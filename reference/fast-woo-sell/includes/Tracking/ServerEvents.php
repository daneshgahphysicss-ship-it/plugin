<?php
/**
 * WooCommerce to event bridge.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Config;
use FWS\Core\Hookable;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Records the events that must never be trusted to a browser.
 *
 * Cart changes, orders, money and attribution are all written from WooCommerce
 * hooks. Every callback here is defensive: a store must not break because a
 * tracking row failed, so failures are logged and swallowed rather than raised.
 */
final class ServerEvents implements Hookable {

	/**
	 * Attach to WooCommerce.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 20, 4 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'on_remove_cart_item' ), 20, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'on_quantity_update' ), 20, 3 );

		// Covers the classic shortcode checkout and the block checkout alike,
		// because both are ordinary pages that pass through template_redirect.
		add_action( 'template_redirect', array( $this, 'on_checkout_view' ) );

		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * A product was added to the cart.
	 *
	 * @param string $cart_item_key Cart line key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity added.
	 * @param int    $variation_id  Variation id, or zero.
	 *
	 * @return void
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ): void {
		unset( $cart_item_key );

		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return;
		}

		EventRecorder::record_safely(
			EventType::ADD_TO_CART,
			array(
				'object_id'    => $product_id,
				'variation_id' => (int) $variation_id,
				'quantity'     => (int) $quantity,
				'value'        => $this->line_value( $variation_id > 0 ? (int) $variation_id : $product_id, (int) $quantity ),
				'currency'     => get_woocommerce_currency(),
				'surface'      => $this->surface(),
			)
		);

		Visitor::touch();
	}

	/**
	 * A cart line is about to be removed.
	 *
	 * The before hook is used rather than the after hook because the line data
	 * is still addressable here without reaching into removed_cart_contents.
	 *
	 * @param string    $cart_item_key Cart line key.
	 * @param \WC_Cart  $cart          The cart.
	 *
	 * @return void
	 */
	public function on_remove_cart_item( $cart_item_key, $cart ): void {
		if ( ! is_object( $cart ) || ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$item = $cart->cart_contents[ $cart_item_key ];

		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

		if ( $product_id <= 0 ) {
			return;
		}

		EventRecorder::record_safely(
			EventType::REMOVE_FROM_CART,
			array(
				'object_id'    => $product_id,
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'quantity'     => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
				'currency'     => get_woocommerce_currency(),
				'surface'      => $this->surface(),
			)
		);
	}

	/**
	 * A cart line quantity changed.
	 *
	 * @param string $cart_item_key Cart line key.
	 * @param int    $quantity      New quantity.
	 * @param int    $old_quantity  Previous quantity.
	 *
	 * @return void
	 */
	public function on_quantity_update( $cart_item_key, $quantity, $old_quantity ): void {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;

		if ( null === $cart || ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$item       = $cart->cart_contents[ $cart_item_key ];
		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

		if ( $product_id <= 0 ) {
			return;
		}

		EventRecorder::record_safely(
			EventType::CART_UPDATED,
			array(
				'object_id'    => $product_id,
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'quantity'     => (int) $quantity,
				'currency'     => get_woocommerce_currency(),
				'surface'      => $this->surface(),
				'meta'         => array( 'from' => (int) $old_quantity ),
			)
		);
	}

	/**
	 * The checkout page was reached.
	 *
	 * Recorded once per session; a customer who reloads checkout four times is
	 * one funnel entry, not four.
	 *
	 * @return void
	 */
	public function on_checkout_view(): void {
		if ( is_admin() || ! function_exists( 'is_checkout' ) ) {
			return;
		}

		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		if ( ! Visitor::is_trackable() ) {
			return;
		}

		if ( EventRepository::count_in_session( Visitor::session_id(), EventType::CHECKOUT_STARTED ) > 0 ) {
			return;
		}

		$cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		$total = null === $cart ? 0 : $cart->get_total( 'edit' );
		$items = null === $cart ? 0 : $cart->get_cart_contents_count();

		EventRecorder::record_safely(
			EventType::CHECKOUT_STARTED,
			array(
				'quantity' => (int) $items,
				'value'    => $total,
				'currency' => get_woocommerce_currency(),
				'surface'  => 'checkout',
			)
		);

		Visitor::touch();
	}

	/**
	 * An order row was created.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return void
	 */
	public function on_new_order( $order_id ): void {
		$order = $this->order( $order_id );

		if ( null === $order ) {
			return;
		}

		// Only stamp identity when a real browser is present, so an order typed
		// in by a shop manager is not attributed to the shop manager.
		if ( Visitor::has_cookie() && Visitor::is_trackable() ) {
			Visitor::attach_to_order( $order );
		}

		if ( EventRepository::order_has( $order->get_id(), EventType::ORDER_CREATED ) ) {
			return;
		}

		EventRecorder::record_safely(
			EventType::ORDER_CREATED,
			array(
				'order_id'   => $order->get_id(),
				'user_id'    => $order->get_customer_id(),
				'quantity'   => $order->get_item_count(),
				'value'      => $order->get_total(),
				'currency'   => $order->get_currency(),
				'surface'    => 'order',
				'visitor_id' => Visitor::from_order( $order ),
			)
		);
	}

	/**
	 * A gateway confirmed payment.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return void
	 */
	public function on_payment_complete( $order_id ): void {
		$this->record_paid( $this->order( $order_id ) );
	}

	/**
	 * An order changed status.
	 *
	 * Needed alongside payment_complete because bank transfer, cash on delivery
	 * and manual admin changes reach a paid status without a gateway callback.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    The order.
	 *
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		unset( $from );

		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );

		if ( ! in_array( (string) $to, $paid, true ) ) {
			return;
		}

		$this->record_paid( $order instanceof \WC_Order ? $order : $this->order( $order_id ) );
	}

	/**
	 * Write the paid event and everything derived from it, once.
	 *
	 * @param \WC_Order|null $order The order.
	 *
	 * @return void
	 */
	private function record_paid( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order_id = $order->get_id();

		// Both a gateway callback and a status transition can arrive for the same
		// payment, so the guard is on stored state rather than on a request flag.
		if ( EventRepository::order_has( $order_id, EventType::ORDER_PAID ) ) {
			return;
		}

		$visitor_id = Visitor::from_order( $order );

		EventRecorder::record_safely(
			EventType::ORDER_PAID,
			array(
				'order_id'   => $order_id,
				'user_id'    => $order->get_customer_id(),
				'quantity'   => $order->get_item_count(),
				'value'      => $order->get_total(),
				'currency'   => $order->get_currency(),
				'surface'    => 'order',
				'visitor_id' => $visitor_id,
			)
		);

		$this->attribute( $order, $visitor_id );
	}

	/**
	 * Credit paid order lines to the recommendation that was clicked.
	 *
	 * One row per matched line, carrying the line total, so revenue per
	 * placement is a plain SUM with no join back to WooCommerce at read time.
	 *
	 * @param \WC_Order $order      The order.
	 * @param string    $visitor_id Visitor identifier recorded on the order.
	 *
	 * @return void
	 */
	private function attribute( \WC_Order $order, string $visitor_id ): void {
		if ( '' === $visitor_id ) {
			return;
		}

		$order_id = $order->get_id();

		if ( EventRepository::order_has( $order_id, EventType::ORDER_ATTRIBUTED ) ) {
			return;
		}

		$days   = Config::int( 'attribution_window_days', 1, 365 );
		$since  = Sanitize::datetime( time() - ( $days * DAY_IN_SECONDS ) );
		$clicks = EventRepository::recent_clicks( $visitor_id, $since, 200 );

		if ( empty( $clicks ) ) {
			return;
		}

		// Last click wins: rows arrive newest first, so the first entry for an
		// id is the one that is kept.
		$by_id = array();

		foreach ( $clicks as $click ) {
			$product = (int) $click->object_id;
			$variant = (int) $click->variation_id;

			if ( $product > 0 && ! isset( $by_id[ $product ] ) ) {
				$by_id[ $product ] = $click;
			}

			if ( $variant > 0 && ! isset( $by_id[ $variant ] ) ) {
				$by_id[ $variant ] = $click;
			}
		}

		$currency = $order->get_currency();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();

			$click = null;

			if ( $variation_id > 0 && isset( $by_id[ $variation_id ] ) ) {
				$click = $by_id[ $variation_id ];
			} elseif ( isset( $by_id[ $product_id ] ) ) {
				$click = $by_id[ $product_id ];
			}

			if ( null === $click ) {
				continue;
			}

			EventRecorder::record_safely(
				EventType::ORDER_ATTRIBUTED,
				array(
					'object_id'    => $product_id,
					'variation_id' => $variation_id,
					'order_id'     => $order_id,
					'user_id'      => $order->get_customer_id(),
					'placement_id' => (int) $click->placement_id,
					'engine'       => (string) $click->engine,
					'variant'      => (string) $click->variant,
					'quantity'     => (int) $item->get_quantity(),
					'value'        => $item->get_total(),
					'currency'     => $currency,
					'surface'      => 'order',
					'visitor_id'   => $visitor_id,
					'meta'         => array( 'clicked_at' => (string) $click->created_at ),
				)
			);
		}
	}

	/**
	 * Resolve an order id or object into an order.
	 *
	 * @param mixed $order_id Order id or object.
	 *
	 * @return \WC_Order|null
	 */
	private function order( $order_id ): ?\WC_Order {
		if ( $order_id instanceof \WC_Order ) {
			return $order_id;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( (int) $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Value of a cart line, for the add to cart event.
	 *
	 * @param int $product_id Product or variation id.
	 * @param int $quantity   Quantity.
	 *
	 * @return string
	 */
	private function line_value( int $product_id, int $quantity ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '0';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return '0';
		}

		return Sanitize::money( (float) $product->get_price( 'edit' ) * max( 1, $quantity ) );
	}

	/**
	 * Which page the current request is on.
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
}
