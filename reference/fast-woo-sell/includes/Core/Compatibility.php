<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * Without these declarations WooCommerce lists the plugin as "incompatible" on
 * the HPOS settings screen. They must run on `before_woocommerce_init`, which
 * is why the bootstrap hooks them at file scope.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Declares and probes WooCommerce feature compatibility.
 */
final class Compatibility {

	/**
	 * High-Performance Order Storage feature slug.
	 */
	const FEATURE_HPOS = 'custom_order_tables';

	/**
	 * Cart and Checkout Blocks feature slug.
	 */
	const FEATURE_BLOCKS = 'cart_checkout_blocks';

	/**
	 * Fully qualified name of the WooCommerce features utility.
	 */
	const FEATURES_UTIL = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';

	/**
	 * Fully qualified name of the WooCommerce order utility.
	 */
	const ORDER_UTIL = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';

	/**
	 * Cached result of the checkout block probe.
	 *
	 * @var bool|null
	 */
	private static $checkout_is_block = null;

	/**
	 * Features this plugin is compatible with.
	 *
	 * @return string[]
	 */
	public static function features() {
		return array(
			self::FEATURE_HPOS,
			self::FEATURE_BLOCKS,
		);
	}

	/**
	 * Declare compatibility with every supported feature.
	 *
	 * Hooked on `before_woocommerce_init`.
	 *
	 * @return void
	 */
	public static function declare_all() {
		if ( ! class_exists( self::FEATURES_UTIL ) ) {
			return;
		}

		if ( ! method_exists( self::FEATURES_UTIL, 'declare_compatibility' ) ) {
			return;
		}

		foreach ( self::features() as $feature ) {
			call_user_func(
				array( self::FEATURES_UTIL, 'declare_compatibility' ),
				$feature,
				FWS_FILE,
				true
			);
		}
	}

	/**
	 * Whether the store keeps orders in the custom order tables.
	 *
	 * Every order read in this plugin goes through the WooCommerce CRUD or the
	 * analytics lookup tables, so behaviour must not depend on this. It exists
	 * for diagnostics and for the Site Health report.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		if ( ! class_exists( self::ORDER_UTIL ) ) {
			return false;
		}

		if ( ! method_exists( self::ORDER_UTIL, 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}

		return (bool) call_user_func(
			array( self::ORDER_UTIL, 'custom_orders_table_usage_is_enabled' )
		);
	}

	/**
	 * Whether the checkout page is built with the checkout block.
	 *
	 * Determines which contact-capture path is available: the classic checkout
	 * exposes field hooks, the block checkout needs the Store API path instead.
	 * Result is memoised because it is asked once per request at most.
	 *
	 * @return bool
	 */
	public static function checkout_is_block() {
		if ( null !== self::$checkout_is_block ) {
			return self::$checkout_is_block;
		}

		self::$checkout_is_block = false;

		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return self::$checkout_is_block;
		}

		$page_id = wc_get_page_id( 'checkout' );

		if ( $page_id > 0 ) {
			self::$checkout_is_block = has_block( 'woocommerce/checkout', $page_id );
		}

		return self::$checkout_is_block;
	}

	/**
	 * Reset the memoised probes. Test helper.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$checkout_is_block = null;
	}
}
