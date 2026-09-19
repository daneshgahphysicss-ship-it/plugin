<?php
/**
 * Recommendation result filtering.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Core\Config;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Removes products that must not be shown, then enforces variety.
 *
 * Every engine returns candidates without checking any of this, deliberately.
 * Ranking and eligibility are different problems: an engine that also had to
 * know about stock, visibility and the current cart would be untestable, and the
 * eight of them would each get the rules subtly wrong.
 */
final class Filters {

	/**
	 * Reduce a candidate list to products that may be shown.
	 *
	 * Input order is preserved throughout. The engines already ranked these, and
	 * a filter that reorders its input silently discards that work.
	 *
	 * @param int[]   $ids     Candidate products, best first.
	 * @param Context $context Request context.
	 * @param int     $limit   How many to keep.
	 *
	 * @return int[]
	 */
	public static function apply( array $ids, Context $context, int $limit ): array {
		$ids   = Sanitize::id_list( $ids, 500 );
		$limit = Sanitize::clamp( $limit, 1, 100 );

		if ( array() === $ids ) {
			return array();
		}

		$ids = array_values( array_diff( $ids, self::exclusions( $context ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$products = self::load( $ids );

		if ( array() === $products ) {
			return array();
		}

		$cap    = Config::int( 'max_per_category', 0, 50 );
		$stock  = Config::bool( 'exclude_out_of_stock' );
		$counts = array();
		$out    = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $products[ $id ] ) ) {
				continue;
			}

			if ( ! self::is_showable( $products[ $id ], $stock ) ) {
				continue;
			}

			if ( $cap > 0 && ! self::within_category_cap( $id, $cap, $counts ) ) {
				continue;
			}

			$out[] = $id;

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Products the settings say to leave out.
	 *
	 * @param Context $context Request context.
	 *
	 * @return int[]
	 */
	private static function exclusions( Context $context ): array {
		$exclude = $context->exclude();

		if ( Config::bool( 'exclude_cart_items' ) ) {
			$exclude = array_merge( $exclude, self::cart_ids() );
		}

		if ( Config::bool( 'exclude_purchased' ) && 0 !== $context->user_id() ) {
			$exclude = array_merge( $exclude, RankingRepository::purchased_by( $context->user_id(), 100 ) );
		}

		return Sanitize::id_list( $exclude, 500 );
	}

	/**
	 * Product ids currently in the cart.
	 *
	 * @return int[]
	 */
	private static function cart_ids(): array {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return array();
		}

		$ids = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['product_id'] ) ) {
				$ids[] = Sanitize::id( $item['product_id'] );
			}

			if ( ! empty( $item['variation_id'] ) ) {
				$ids[] = Sanitize::id( $item['variation_id'] );
			}
		}

		return Sanitize::id_list( $ids, 200 );
	}

	/**
	 * Load every candidate in one query, keyed by id.
	 *
	 * One `wc_get_products()` call primes the post, meta and term caches for the
	 * whole list, where a `wc_get_product()` per candidate is a round trip each and
	 * turns a block of four recommendations into dozens of queries on a cold cache.
	 * The returned order is ignored: the engines already ranked these, so the
	 * caller walks its own list and looks each one up here.
	 *
	 * @param int[] $ids Candidate products.
	 *
	 * @return array<int, \WC_Product>
	 */
	private static function load( array $ids ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$found = wc_get_products(
			array(
				'include' => $ids,
				'limit'   => count( $ids ),
				'status'  => 'publish',
				'return'  => 'objects',
			)
		);

		if ( ! is_array( $found ) ) {
			return array();
		}

		$map = array();

		foreach ( $found as $product ) {
			if ( $product instanceof \WC_Product ) {
				$map[ $product->get_id() ] = $product;
			}
		}

		return $map;
	}

	/**
	 * Whether a product may appear in a recommendation block.
	 *
	 * `is_visible()` already accounts for status, catalogue visibility, password
	 * protection, whether a price is set, and the shop's own hide out of stock
	 * setting. The extra stock test exists because this plugin's setting is
	 * independent of that one: a shop may want to list sold out items on category
	 * pages but not recommend them.
	 *
	 * @param \WC_Product $product       Candidate.
	 * @param bool        $require_stock Whether to insist on stock.
	 *
	 * @return bool
	 */
	private static function is_showable( \WC_Product $product, bool $require_stock ): bool {
		if ( ! $product->is_visible() ) {
			return false;
		}

		if ( $require_stock && ! $product->is_in_stock() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a product fits under the per category cap, counting it if so.
	 *
	 * Four all but identical products from one category is the most common way a
	 * statistically correct recommendation block still looks useless, so variety
	 * is enforced here rather than left to the engines.
	 *
	 * @param int             $product_id Candidate.
	 * @param int             $cap        Largest number allowed per category.
	 * @param array<int, int> $counts     Running tally, updated in place.
	 *
	 * @return bool
	 */
	private static function within_category_cap( int $product_id, int $cap, array &$counts ): bool {
		$terms = function_exists( 'wc_get_product_term_ids' )
			? wc_get_product_term_ids( $product_id, 'product_cat' )
			: array();

		if ( ! is_array( $terms ) || array() === $terms ) {
			return true;
		}

		foreach ( $terms as $term_id ) {
			if ( isset( $counts[ $term_id ] ) && $counts[ $term_id ] >= $cap ) {
				return false;
			}
		}

		foreach ( $terms as $term_id ) {
			$counts[ $term_id ] = ( $counts[ $term_id ] ?? 0 ) + 1;
		}

		return true;
	}
}
