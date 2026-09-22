<?php
/**
 * Manual relationships engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * The up-sells and cross-sells the shop owner set by hand in WooCommerce.
 *
 * Placed above the statistical engines in the fallback order on purpose. A person
 * who took the trouble to name a specific accessory knows something the order
 * history does not, and silently overriding that choice is the fastest way for a
 * plugin like this to lose the shop owner's trust.
 */
final class ManualRelated implements Engine {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'manual';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'انتخاب دستی ووکامرس', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'بیش‌فروش و فروش مکمل که خودتان روی محصول تعیین کرده‌اید.', 'fast-woo-sell' );
	}

	/**
	 * Shared across visitors.
	 *
	 * @return bool
	 */
	public function is_personal(): bool {
		return false;
	}

	/**
	 * Needs a product page and WooCommerce.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		return array() !== $context->seeds() && function_exists( 'wc_get_product' );
	}

	/**
	 * Up-sells first, then cross-sells.
	 *
	 * Up-sells come first because they are chosen as alternatives to the product
	 * being looked at, while cross-sells are chosen as additions to a cart; on a
	 * product page the former is the closer match.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$ids = array();

		foreach ( $context->seeds() as $seed ) {
			$product = wc_get_product( $seed );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$ids = array_merge( $ids, $product->get_upsell_ids(), $product->get_cross_sell_ids() );

			if ( count( $ids ) >= ( $limit + count( $context->exclude() ) ) ) {
				break;
			}
		}

		return Ranked::clean( Sanitize::id_list( $ids, 200 ), $context->exclude(), $limit );
	}
}
