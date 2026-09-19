<?php
/**
 * Event taxonomy.
 *
 * @package FWS
 */

namespace FWS\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * The closed set of event types the plugin records.
 *
 * The list is closed on purpose. An open taxonomy makes the rollup queries
 * unbounded and lets a typo create a silent second funnel that nothing ever
 * reads. A new type means a new constant here and a new column or case in the
 * rollup, which is exactly the review that should happen.
 */
final class EventType {

	/**
	 * A product page was viewed.
	 */
	const PRODUCT_VIEW = 'product_view';

	/**
	 * A recommendation block became visible to the visitor.
	 */
	const IMPRESSION = 'impression';

	/**
	 * A product inside a recommendation block was clicked.
	 */
	const CLICK = 'click';

	/**
	 * A product was added to the cart.
	 */
	const ADD_TO_CART = 'add_to_cart';

	/**
	 * A line was removed from the cart.
	 */
	const REMOVE_FROM_CART = 'remove_from_cart';

	/**
	 * A line quantity changed.
	 */
	const CART_UPDATED = 'cart_updated';

	/**
	 * The checkout was reached.
	 */
	const CHECKOUT_STARTED = 'checkout_started';

	/**
	 * An order row was created, paid or not.
	 */
	const ORDER_CREATED = 'order_created';

	/**
	 * An order reached a paid status.
	 */
	const ORDER_PAID = 'order_paid';

	/**
	 * A paid order line was credited to a recommendation.
	 */
	const ORDER_ATTRIBUTED = 'order_attributed';

	/**
	 * A tracked cart was marked abandoned.
	 */
	const CART_ABANDONED = 'cart_abandoned';

	/**
	 * A recovery message was dispatched.
	 */
	const RECOVERY_SENT = 'recovery_sent';

	/**
	 * A recovery link was opened.
	 */
	const RECOVERY_CLICKED = 'recovery_clicked';

	/**
	 * Every known type.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::PRODUCT_VIEW,
			self::IMPRESSION,
			self::CLICK,
			self::ADD_TO_CART,
			self::REMOVE_FROM_CART,
			self::CART_UPDATED,
			self::CHECKOUT_STARTED,
			self::ORDER_CREATED,
			self::ORDER_PAID,
			self::ORDER_ATTRIBUTED,
			self::CART_ABANDONED,
			self::RECOVERY_SENT,
			self::RECOVERY_CLICKED,
		);
	}

	/**
	 * Types a browser is allowed to report.
	 *
	 * Everything else is written by a server side WooCommerce hook only. A money
	 * value that a visitor could post is a money value an attacker can forge, so
	 * revenue, orders and attribution never come from the client.
	 *
	 * @return string[]
	 */
	public static function client_allowed(): array {
		return array(
			self::PRODUCT_VIEW,
			self::IMPRESSION,
			self::CLICK,
		);
	}

	/**
	 * Whether a type exists.
	 *
	 * @param string $type Candidate type.
	 *
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::all(), true );
	}

	/**
	 * Whether a browser may report this type.
	 *
	 * @param string $type Candidate type.
	 *
	 * @return bool
	 */
	public static function is_client_allowed( string $type ): bool {
		return in_array( $type, self::client_allowed(), true );
	}

	/**
	 * Whether a type is meaningless without an object id.
	 *
	 * @param string $type Event type.
	 *
	 * @return bool
	 */
	public static function requires_object( string $type ): bool {
		return in_array(
			$type,
			array(
				self::PRODUCT_VIEW,
				self::IMPRESSION,
				self::CLICK,
				self::ADD_TO_CART,
				self::REMOVE_FROM_CART,
				self::CART_UPDATED,
				self::ORDER_ATTRIBUTED,
			),
			true
		);
	}

	/**
	 * Whether a type is meaningless without an order id.
	 *
	 * @param string $type Event type.
	 *
	 * @return bool
	 */
	public static function requires_order( string $type ): bool {
		return in_array(
			$type,
			array(
				self::ORDER_CREATED,
				self::ORDER_PAID,
				self::ORDER_ATTRIBUTED,
			),
			true
		);
	}

	/**
	 * Translated labels, for the admin.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::PRODUCT_VIEW     => __( 'بازدید محصول', 'fast-woo-sell' ),
			self::IMPRESSION       => __( 'نمایش پیشنهاد', 'fast-woo-sell' ),
			self::CLICK            => __( 'کلیک روی پیشنهاد', 'fast-woo-sell' ),
			self::ADD_TO_CART      => __( 'افزودن به سبد', 'fast-woo-sell' ),
			self::REMOVE_FROM_CART => __( 'حذف از سبد', 'fast-woo-sell' ),
			self::CART_UPDATED     => __( 'تغییر سبد', 'fast-woo-sell' ),
			self::CHECKOUT_STARTED => __( 'شروع تسویه', 'fast-woo-sell' ),
			self::ORDER_CREATED    => __( 'ثبت سفارش', 'fast-woo-sell' ),
			self::ORDER_PAID       => __( 'پرداخت سفارش', 'fast-woo-sell' ),
			self::ORDER_ATTRIBUTED => __( 'سفارش منتسب به پیشنهاد', 'fast-woo-sell' ),
			self::CART_ABANDONED   => __( 'سبد رها شده', 'fast-woo-sell' ),
			self::RECOVERY_SENT    => __( 'ارسال پیام بازیابی', 'fast-woo-sell' ),
			self::RECOVERY_CLICKED => __( 'کلیک لینک بازیابی', 'fast-woo-sell' ),
		);
	}

	/**
	 * Label for one type, falling back to the raw slug.
	 *
	 * @param string $type Event type.
	 *
	 * @return string
	 */
	public static function label( string $type ): string {
		$labels = self::labels();

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}
}
