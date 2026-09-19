<?php
/**
 * Front end tracking assets.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Config;
use FWS\Core\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the browser side of the tracker.
 *
 * The script is only enqueued when there is something for it to report, so a
 * shop with tracking off, or a visitor who opted out, downloads nothing at all.
 */
final class Assets implements Hookable {

	/**
	 * Script handle.
	 */
	const HANDLE = 'fws-tracking';

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue and configure the tracker.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( is_admin() || ! EventRecorder::is_enabled() || ! Visitor::is_trackable() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			FWS_URL . 'assets/js/tracking.js',
			array(),
			FWS_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'fwsTrackingData', $this->data() );
	}

	/**
	 * Everything the script needs, and nothing it does not.
	 *
	 * No visitor identifier is exposed: identity lives in an httpOnly cookie the
	 * browser cannot read, which is what stops a third party script on the page
	 * from lifting it.
	 *
	 * @return array
	 */
	private function data(): array {
		$data = array(
			'endpoint' => rest_url( RestController::REST_NAMESPACE . RestController::ROUTE ),
			'nonce'    => Config::bool( 'tracking_require_nonce' ) ? wp_create_nonce( RestController::NONCE_ACTION ) : '',
			'batch'    => Config::int( 'events_per_request_cap', 1, 100 ),
			'delay'    => 2000,
			'ratio'    => 0.5,
			'dwell'    => 700,
			'surface'  => $this->surface(),
			'product'  => $this->product_id(),
		);

		/**
		 * Filter the data handed to the tracking script.
		 *
		 * @since 1.0.0
		 *
		 * @param array $data Script configuration.
		 */
		return (array) apply_filters( 'fws/tracking/script_data', $data );
	}

	/**
	 * Which page the visitor is on.
	 *
	 * @return string
	 */
	private function surface(): string {
		if ( ! function_exists( 'is_product' ) ) {
			return 'other';
		}

		if ( is_product() ) {
			return 'product';
		}

		if ( is_cart() ) {
			return 'cart';
		}

		if ( is_checkout() ) {
			return 'checkout';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_product_category() ) {
			return 'category';
		}

		if ( is_product_tag() ) {
			return 'tag';
		}

		if ( is_shop() ) {
			return 'shop';
		}

		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		return 'other';
	}

	/**
	 * The product being viewed, or zero.
	 *
	 * @return int
	 */
	private function product_id(): int {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return 0;
		}

		return (int) get_queried_object_id();
	}
}
