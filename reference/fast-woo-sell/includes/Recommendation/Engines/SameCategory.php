<?php
/**
 * Same category engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Other products from the same categories, most sold first.
 *
 * The last rung of the ladder. It needs no history of any kind, only a catalogue,
 * which is why it is what a brand new shop sees and why it is never allowed to be
 * unavailable. A recommendation block that renders nothing looks like a bug to a
 * shopper and like a broken plugin to the shop owner.
 */
final class SameCategory implements Engine {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'same_category';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'از همین دسته‌بندی', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'محصولات دیگر همان دسته‌بندی، به ترتیب فروش. برای فروشگاه تازه هم کار می‌کند.', 'fast-woo-sell' );
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
	 * Needs WooCommerce and something to take categories from.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return false;
		}

		return array() !== $context->seeds() || array() !== $context->category_ids();
	}

	/**
	 * Products sharing a category with the seeds.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$slugs = $this->slugs( $context );

		if ( array() === $slugs ) {
			return array();
		}

		$exclude = $context->exclude();

		$found = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => Sanitize::clamp( $limit + count( $exclude ), 1, 100 ),
				'category' => $slugs,
				'exclude'  => $exclude,
				'return'   => 'ids',
				// Ordering by the total_sales meta directly rather than by the
				// `popularity` alias, because the alias is a shop loop concept
				// that WC_Product_Query has not always understood.
				'meta_key' => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'  => 'meta_value_num',
				'order'    => 'DESC',
			)
		);

		return Ranked::clean( is_array( $found ) ? $found : array(), $exclude, $limit );
	}

	/**
	 * Category slugs to search in.
	 *
	 * @param Context $context Request context.
	 *
	 * @return string[]
	 */
	private function slugs( Context $context ): array {
		$slugs = array();

		foreach ( $context->category_ids() as $term_id ) {
			$slug = get_term_field( 'slug', $term_id, 'product_cat' );

			if ( is_string( $slug ) && '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		if ( array() !== $slugs ) {
			return array_values( array_unique( $slugs ) );
		}

		foreach ( $context->seeds() as $seed ) {
			$terms = wp_get_post_terms( $seed, 'product_cat', array( 'fields' => 'slugs' ) );

			if ( is_array( $terms ) ) {
				$slugs = array_merge( $slugs, $terms );
			}

			if ( count( $slugs ) >= 5 ) {
				break;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
	}
}
