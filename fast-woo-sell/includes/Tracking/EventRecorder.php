<?php
/**
 * Event write path.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Config;
use FWS\Support\Logger;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * The only way an event row is created.
 *
 * There are two entry points on purpose. `record()` is the trusted internal API
 * used by WooCommerce hooks and cron, and may set money, order ids and an
 * explicit visitor. `record_client()` is the untrusted API used by the tracking
 * endpoint: it accepts three event types, forces the server issued identity and
 * zeroes every monetary field, so a forged request can add noise but can never
 * invent revenue or steal another visitor's history.
 */
final class EventRecorder {

	/**
	 * Places an event can originate from.
	 *
	 * @return string[]
	 */
	public static function surfaces(): array {
		return array(
			'product',
			'cart',
			'checkout',
			'order',
			'shop',
			'category',
			'tag',
			'search',
			'home',
			'account',
			'widget',
			'email',
			'other',
		);
	}

	/**
	 * Types recorded even when the visitor opted out, with identity stripped.
	 *
	 * A shop still needs its own revenue and recovery figures; what the privacy
	 * signal removes is the link back to a person, not the accounting.
	 *
	 * @return string[]
	 */
	public static function unconditional(): array {
		return array(
			EventType::ORDER_CREATED,
			EventType::ORDER_PAID,
			EventType::CART_ABANDONED,
			EventType::RECOVERY_SENT,
			EventType::RECOVERY_CLICKED,
		);
	}

	/**
	 * Whether the tracking subsystem is on at all.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filter the master tracking switch.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether events are recorded.
		 */
		return (bool) apply_filters( 'fws/tracking/enabled', true );
	}

	/**
	 * Record one event from a trusted caller.
	 *
	 * @param string $type Event type.
	 * @param array  $data Optional column overrides.
	 *
	 * @return int Inserted id, or zero when nothing was written.
	 */
	public static function record( string $type, array $data = array() ): int {
		$row = self::build_row( $type, $data, true );

		if ( null === $row ) {
			return 0;
		}

		$id = EventRepository::insert( $row );

		if ( $id > 0 ) {
			/**
			 * Fires after an event row is written.
			 *
			 * @since 1.0.0
			 *
			 * @param int   $id  Inserted row id.
			 * @param array $row Row as written.
			 */
			do_action( 'fws/tracking/recorded', $id, $row );
		}

		return $id;
	}

	/**
	 * Record a batch of events reported by a browser.
	 *
	 * @param array<int, array> $events List of maps, each with a `type` key.
	 *
	 * @return int Number of rows written.
	 */
	public static function record_client( array $events ): int {
		$cap  = Config::int( 'events_per_request_cap', 1, 100 );
		$rows = array();

		foreach ( array_slice( $events, 0, $cap ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type = isset( $event['type'] ) ? Sanitize::slug( $event['type'] ) : '';

			if ( ! EventType::is_client_allowed( $type ) ) {
				continue;
			}

			$row = self::build_row( $type, $event, false );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$written = EventRepository::insert_many( $rows );

		if ( $written > 0 ) {
			Visitor::touch();

			/**
			 * Fires after a browser reported batch is written.
			 *
			 * @since 1.0.0
			 *
			 * @param int   $written Rows written.
			 * @param array $rows    Rows as written.
			 */
			do_action( 'fws/tracking/recorded_batch', $written, $rows );
		}

		return $written;
	}

	/**
	 * Normalise one event into a database row.
	 *
	 * @param string $type    Event type.
	 * @param array  $data    Raw values.
	 * @param bool   $trusted Whether the caller may set money and identity.
	 *
	 * @return array|null Row, or null when the event must be discarded.
	 */
	private static function build_row( string $type, array $data, bool $trusted ): ?array {
		if ( ! self::is_enabled() || ! EventType::is_valid( $type ) ) {
			return null;
		}

		// A server side caller may pass an identity it already resolved, for
		// instance from order meta during a gateway callback. That request has no
		// browser and would fail the bot heuristic, so the explicit value has to
		// win: consent was checked when the identity was first stored.
		$explicit_visitor = $trusted && isset( $data['visitor_id'] )
			? Sanitize::hex( $data['visitor_id'], 32 )
			: '';

		$anonymous = false;

		if ( '' !== $explicit_visitor ) {
			$visitor_id = $explicit_visitor;
			$session_id = isset( $data['session_id'] ) ? Sanitize::hex( $data['session_id'], 32 ) : '';
		} else {
			$trackable = Visitor::is_trackable();

			if ( ! $trackable && ! in_array( $type, self::unconditional(), true ) ) {
				return null;
			}

			// An opted out visitor still produces accounting rows, anonymously.
			$anonymous  = ! $trackable;
			$visitor_id = $anonymous ? '' : Visitor::id();
			$session_id = $anonymous ? '' : Visitor::session_id();
		}

		$object_id = Sanitize::id( isset( $data['object_id'] ) ? $data['object_id'] : 0 );
		$order_id  = $trusted ? Sanitize::id( isset( $data['order_id'] ) ? $data['order_id'] : 0 ) : 0;

		if ( EventType::requires_object( $type ) && $object_id <= 0 ) {
			return null;
		}

		if ( EventType::requires_order( $type ) && $order_id <= 0 ) {
			return null;
		}

		// The order's own customer id wins over the current request, so a paid
		// event fired from a cron run or a webhook is still credited correctly.
		$user_id = $trusted && isset( $data['user_id'] )
			? Sanitize::id( $data['user_id'] )
			: Visitor::user_id();

		$row = array(
			'event_type'   => $type,
			'visitor_id'   => $visitor_id,
			'session_id'   => $session_id,
			'user_id'      => $anonymous ? 0 : $user_id,
			'object_id'    => $object_id,
			'variation_id' => Sanitize::id( isset( $data['variation_id'] ) ? $data['variation_id'] : 0 ),
			'order_id'     => $order_id,
			'placement_id' => Sanitize::id( isset( $data['placement_id'] ) ? $data['placement_id'] : 0 ),
			'engine'       => Sanitize::slug( isset( $data['engine'] ) ? $data['engine'] : '', 32 ),
			'variant'      => self::variant( isset( $data['variant'] ) ? $data['variant'] : '' ),
			'surface'      => self::surface( isset( $data['surface'] ) ? $data['surface'] : '' ),
			'device'       => Visitor::device(),
			'quantity'     => Sanitize::clamp( isset( $data['quantity'] ) ? $data['quantity'] : 0, 0, 65535 ),
			'value'        => $trusted ? Sanitize::money( isset( $data['value'] ) ? $data['value'] : 0 ) : '0',
			'currency'     => $trusted ? Sanitize::currency( isset( $data['currency'] ) ? $data['currency'] : '' ) : '',
			'meta'         => Sanitize::json( isset( $data['meta'] ) ? $data['meta'] : null ),
			'created_at'   => Sanitize::datetime(),
		);

		/**
		 * Filter a row just before it is written, or return null to drop it.
		 *
		 * @since 1.0.0
		 *
		 * @param array|null $row     Normalised row.
		 * @param string     $type    Event type.
		 * @param bool       $trusted Whether the caller is server side.
		 */
		$row = apply_filters( 'fws/tracking/before_record', $row, $type, $trusted );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Coerce an experiment variant to a single lowercase letter.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private static function variant( $value ): string {
		$value = Sanitize::slug( $value, 1 );

		return preg_match( '/^[a-z]$/', $value ) ? $value : '';
	}

	/**
	 * Coerce a surface to one of the known values.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private static function surface( $value ): string {
		$value = Sanitize::slug( $value, 20 );

		return in_array( $value, self::surfaces(), true ) ? $value : '';
	}

	/**
	 * Record an event and swallow any storage failure.
	 *
	 * Used by WooCommerce hooks, where a tracking problem must never surface as
	 * a fatal in the middle of a customer's checkout.
	 *
	 * @param string $type Event type.
	 * @param array  $data Optional column overrides.
	 *
	 * @return void
	 */
	public static function record_safely( string $type, array $data = array() ): void {
		try {
			self::record( $type, $data );
		} catch ( \Throwable $e ) {
			Logger::error(
				'Failed to record event.',
				array(
					'type'  => $type,
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
