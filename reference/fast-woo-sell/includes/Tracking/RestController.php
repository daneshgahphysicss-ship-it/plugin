<?php
/**
 * Tracking endpoint.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Config;
use FWS\Core\Hookable;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * The single public route the plugin exposes.
 *
 * This endpoint is deliberately unauthenticated, because the visitors it serves
 * are anonymous by definition. That choice is contained by keeping the blast
 * radius as small as possible:
 *
 *   - only product_view, impression and click are accepted
 *   - identity always comes from the server issued cookie, never the body
 *   - money and order fields are forced to zero for this caller
 *   - object ids must resolve to a published product
 *   - requests are rate limited per identity and address
 *   - the response is always 204 with no body, so it leaks nothing
 *
 * The worst a determined abuser achieves is inflating view counts on their own
 * products, which is bounded by the rate limit and visible in the raw data.
 */
final class RestController implements Hookable {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'fws/v1';

	/**
	 * Collection route.
	 */
	const ROUTE = '/events';

	/**
	 * Nonce action, used when the setting requires one.
	 */
	const NONCE_ACTION = 'fws_track';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declare the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => array( $this, 'may_collect' ),
				'args'                => array(
					'events' => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'رویدادهایی که مرورگر گزارش می‌دهد.', 'fast-woo-sell' ),
					),
					'nonce'  => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Whether the request may be processed.
	 *
	 * Returns false rather than an error object for every rejection, so an
	 * abusive client learns nothing about which check it failed.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public function may_collect( $request ): bool {
		if ( ! EventRecorder::is_enabled() || ! Visitor::is_trackable() ) {
			return false;
		}

		if ( ! $this->same_origin() ) {
			return false;
		}

		if ( Config::bool( 'tracking_require_nonce' ) && ! $this->valid_nonce( $request ) ) {
			return false;
		}

		return ! $this->rate_limited();
	}

	/**
	 * Store the reported events.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function collect( $request ) {
		$events = $request->get_param( 'events' );

		if ( ! is_array( $events ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$cap    = Config::int( 'events_per_request_cap', 1, 100 );
		$events = array_slice( $events, 0, $cap );
		$clean  = array();
		$ids    = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type = isset( $event['type'] ) ? Sanitize::slug( $event['type'] ) : '';

			if ( ! EventType::is_client_allowed( $type ) ) {
				continue;
			}

			$object_id    = Sanitize::id( isset( $event['object_id'] ) ? $event['object_id'] : 0 );
			$variation_id = Sanitize::id( isset( $event['variation_id'] ) ? $event['variation_id'] : 0 );

			if ( $object_id <= 0 ) {
				continue;
			}

			$ids[ $object_id ] = $object_id;

			if ( $variation_id > 0 ) {
				$ids[ $variation_id ] = $variation_id;
			}

			$clean[] = array(
				'type'         => $type,
				'object_id'    => $object_id,
				'variation_id' => $variation_id,
				'placement_id' => Sanitize::id( isset( $event['placement_id'] ) ? $event['placement_id'] : 0 ),
				'engine'       => Sanitize::slug( isset( $event['engine'] ) ? $event['engine'] : '', 32 ),
				'variant'      => Sanitize::slug( isset( $event['variant'] ) ? $event['variant'] : '', 1 ),
				'surface'      => Sanitize::slug( isset( $event['surface'] ) ? $event['surface'] : '', 20 ),
			);
		}

		if ( empty( $clean ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$known = $this->existing_products( array_values( $ids ) );

		$clean = array_values(
			array_filter(
				$clean,
				static function ( $event ) use ( $known ) {
					return in_array( $event['object_id'], $known, true );
				}
			)
		);

		if ( ! empty( $clean ) ) {
			EventRecorder::record_client( $clean );
		}

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Which of the given ids are published products or variations.
	 *
	 * One query for the whole batch: twenty calls to wc_get_product() would mean
	 * twenty post lookups plus product object hydration for data we throw away.
	 *
	 * @param int[] $ids Candidate ids.
	 *
	 * @return int[]
	 */
	private function existing_products( array $ids ): array {
		global $wpdb;

		$ids = Sanitize::id_list( $ids, 100 );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated from a counted integer list.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID IN ( {$placeholders} )
				AND post_type IN ( 'product', 'product_variation' )
				AND post_status = 'publish'",
				$ids
			)
		);

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Whether the request came from this site.
	 *
	 * A cheap barrier against another origin using the endpoint as a free write
	 * target. It is not a security boundary on its own, which is why the payload
	 * rules above do the real work.
	 *
	 * @return bool
	 */
	private function same_origin(): bool {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( '' === $origin ) {
			$origin = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		}

		if ( '' === $origin ) {
			// sendBeacon on some browsers omits both headers; a missing header is
			// not treated as hostile, only a mismatching one is.
			return true;
		}

		$sent = wp_parse_url( $origin, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $sent ) || ! is_string( $home ) ) {
			return true;
		}

		return strtolower( $sent ) === strtolower( $home );
	}

	/**
	 * Verify the optional nonce.
	 *
	 * Off by default: a nonce baked into a full page cache expires while the
	 * page is still being served, which would silently discard real traffic.
	 * Sites that do not cache anonymous pages can switch it on.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	private function valid_nonce( $request ): bool {
		$nonce = (string) $request->get_param( 'nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_header( 'x_fws_nonce' );
		}

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Whether this client has exceeded its allowance.
	 *
	 * The counter key is a salted hash of identity and address; the address
	 * itself is never stored, which keeps the promise that the plugin collects
	 * no personal data it does not need.
	 *
	 * @return bool
	 */
	private function rate_limited(): bool {
		$limit = Config::int( 'tracking_rate_limit_per_minute', 0, 6000 );

		if ( $limit <= 0 ) {
			return false;
		}

		$key   = 'fws_rl_' . md5( wp_salt( 'nonce' ) . Visitor::id() . '|' . $this->client_address() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * The client address, used only as rate limit key material.
	 *
	 * @return string
	 */
	private function client_address(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
}
