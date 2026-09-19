<?php
/**
 * Recommendation context factory.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a Context from the current request.
 *
 * Context itself never touches a global, `$post`, the query or the cart, which is
 * what makes the engines testable. Something has to read those things, and this
 * is the one place allowed to. Every global read in the recommendation namespace
 * lives in this file, so the boundary is checkable by eye.
 */
final class ContextFactory {

	/**
	 * Largest number of seed products taken from a cart or order.
	 */
	const MAX_SEEDS = 20;

	/**
	 * A context for whatever page is being rendered now.
	 *
	 * @param int    $placement_id Placement being rendered.
	 * @param string $variant      Experiment arm.
	 *
	 * @return Context
	 */
	public static function current( int $placement_id = 0, string $variant = '' ): Context {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return self::for_product( get_queried_object_id(), $placement_id, $variant );
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return self::for_order( self::order_id_from_query(), $placement_id, $variant );
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return self::for_cart( Context::PAGE_CHECKOUT, $placement_id, $variant );
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return self::for_cart( Context::PAGE_CART, $placement_id, $variant );
		}

		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			return self::for_archive( $placement_id, $variant );
		}

		return new Context(
			array(
				'page'         => Context::PAGE_OTHER,
				'user_id'      => get_current_user_id(),
				'placement_id' => $placement_id,
				'variant'      => $variant,
			)
		);
	}

	/**
	 * A context for one product.
	 *
	 * @param int    $product_id   Product being viewed.
	 * @param int    $placement_id Placement being rendered.
	 * @param string $variant      Experiment arm.
	 *
	 * @return Context
	 */
	public static function for_product( int $product_id, int $placement_id = 0, string $variant = '' ): Context {
		$product_id = Sanitize::id( $product_id );

		return new Context(
			array(
				'page'         => Context::PAGE_PRODUCT,
				'product_id'   => $product_id,
				'category_ids' => self::categories( array( $product_id ) ),
				'user_id'      => get_current_user_id(),
				'placement_id' => $placement_id,
				'variant'      => $variant,
			)
		);
	}

	/**
	 * A context seeded from the cart.
	 *
	 * Cart items are the seeds and are not excluded here. Whether to recommend
	 * something already in the cart is a setting, applied in Filters, so that the
	 * cart page and the product page behave the same way.
	 *
	 * @param string $page         Cart or checkout.
	 * @param int    $placement_id Placement being rendered.
	 * @param string $variant      Experiment arm.
	 *
	 * @return Context
	 */
	public static function for_cart( string $page = Context::PAGE_CART, int $placement_id = 0, string $variant = '' ): Context {
		$seeds = array();

		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( isset( $item['product_id'] ) ) {
					$seeds[] = Sanitize::id( $item['product_id'] );
				}

				if ( count( $seeds ) >= self::MAX_SEEDS ) {
					break;
				}
			}
		}

		$seeds = Sanitize::id_list( $seeds, self::MAX_SEEDS );

		return new Context(
			array(
				'page'         => $page,
				'seeds'        => $seeds,
				'category_ids' => self::categories( $seeds ),
				'user_id'      => get_current_user_id(),
				'placement_id' => $placement_id,
				'variant'      => $variant,
			)
		);
	}

	/**
	 * A context seeded from a completed order.
	 *
	 * The purchased products are excluded as well as seeded. Recommending what
	 * somebody bought thirty seconds ago is the one case where an in-cart style
	 * setting should not apply: they are looking at a receipt.
	 *
	 * @param int    $order_id     Order just placed.
	 * @param int    $placement_id Placement being rendered.
	 * @param string $variant      Experiment arm.
	 *
	 * @return Context
	 */
	public static function for_order( int $order_id, int $placement_id = 0, string $variant = '' ): Context {
		$seeds = array();
		$order = ( 0 !== Sanitize::id( $order_id ) && function_exists( 'wc_get_order' ) )
			? wc_get_order( $order_id )
			: null;

		if ( $order instanceof \WC_Order ) {
			foreach ( $order->get_items() as $item ) {
				if ( is_callable( array( $item, 'get_product_id' ) ) ) {
					$seeds[] = Sanitize::id( $item->get_product_id() );
				}

				if ( count( $seeds ) >= self::MAX_SEEDS ) {
					break;
				}
			}
		}

		$seeds = Sanitize::id_list( $seeds, self::MAX_SEEDS );

		return new Context(
			array(
				'page'         => Context::PAGE_ORDER,
				'seeds'        => $seeds,
				'category_ids' => self::categories( $seeds ),
				'user_id'      => get_current_user_id(),
				'exclude'      => $seeds,
				'placement_id' => $placement_id,
				'variant'      => $variant,
			)
		);
	}

	/**
	 * A context for the shop, a category archive or a search page.
	 *
	 * @param int    $placement_id Placement being rendered.
	 * @param string $variant      Experiment arm.
	 *
	 * @return Context
	 */
	public static function for_archive( int $placement_id = 0, string $variant = '' ): Context {
		$term = get_queried_object();

		return new Context(
			array(
				'page'         => Context::PAGE_ARCHIVE,
				'category_ids' => ( $term instanceof \WP_Term && 'product_cat' === $term->taxonomy )
					? array( $term->term_id )
					: array(),
				'user_id'      => get_current_user_id(),
				'placement_id' => $placement_id,
				'variant'      => $variant,
			)
		);
	}

	/**
	 * Category term ids for a set of products.
	 *
	 * @param int[] $product_ids Products to look at.
	 *
	 * @return int[]
	 */
	private static function categories( array $product_ids ): array {
		if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
			return array();
		}

		$ids = array();

		foreach ( Sanitize::id_list( $product_ids, self::MAX_SEEDS ) as $product_id ) {
			$terms = wc_get_product_term_ids( $product_id, 'product_cat' );

			if ( is_array( $terms ) ) {
				$ids = array_merge( $ids, $terms );
			}
		}

		return Sanitize::id_list( $ids, 30 );
	}

	/**
	 * The order id on an order received page.
	 *
	 * The order key is checked when the id comes from the query string, exactly as
	 * WooCommerce does on that endpoint. Without it, changing a number in the URL
	 * would seed the block from a stranger's order, and the recommendations then
	 * hint at what that person bought.
	 *
	 * @return int
	 */
	private static function order_id_from_query(): int {
		global $wp;

		if ( isset( $wp->query_vars['order-received'] ) ) {
			$id = Sanitize::id( $wp->query_vars['order-received'] );

			if ( 0 !== $id ) {
				return self::verified( $id );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, and the order key is verified below.
		$id = isset( $_GET['order'] ) ? Sanitize::id( wp_unslash( $_GET['order'] ) ) : 0;

		return 0 !== $id ? self::verified( $id ) : 0;
	}

	/**
	 * An order id, but only when the request carries its key.
	 *
	 * @param int $order_id Candidate order.
	 *
	 * @return int Zero when the key is missing or wrong.
	 */
	private static function verified( int $order_id ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compared against the stored key, which is the check.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( '' === $key || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		return hash_equals( (string) $order->get_order_key(), $key ) ? $order_id : 0;
	}
}
