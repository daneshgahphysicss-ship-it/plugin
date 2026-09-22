<?php
/**
 * Personal engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\AffinityRepository;
use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;
use FWS\Recommendation\RankingRepository;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Co-purchase recommendations seeded from what this customer has already bought.
 *
 * The seeds are the customer's own order history rather than the page they happen
 * to be on, so this engine is the one that works on a cart page, an account page,
 * or in an email, where there is no current product to reason about.
 *
 * The customer's own purchases are always excluded from the result. Recommending
 * something already owned is the failure mode this engine is most prone to.
 */
final class Personal implements Engine {

	/**
	 * How many past purchases to seed from.
	 *
	 * Capped because the affinity read grows with the seed count and because a
	 * customer's taste ten orders ago says little about the next one.
	 */
	const MAX_SEEDS = 10;

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'personal';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'پیشنهاد ویژهٔ شما', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'بر پایهٔ سفارش‌های پیشین همین مشتری. تنها برای کاربر وارد‌شده کار می‌کند.', 'fast-woo-sell' );
	}

	/**
	 * Depends entirely on who is asking.
	 *
	 * @return bool
	 */
	public function is_personal(): bool {
		return true;
	}

	/**
	 * Needs a logged in customer.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		return 0 !== $context->user_id();
	}

	/**
	 * Rank co-purchases of the customer's own history.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		if ( 0 === $context->user_id() ) {
			return array();
		}

		$purchased = RankingRepository::purchased_by( $context->user_id(), 50 );

		if ( array() === $purchased ) {
			return array();
		}

		$seeds   = array_slice( $purchased, 0, self::MAX_SEEDS );
		$exclude = Sanitize::id_list( array_merge( $context->exclude(), $purchased ), 200 );

		$rows = AffinityRepository::top_for_many(
			$seeds,
			AffinityRepository::KIND_BOUGHT,
			$limit + count( $exclude )
		);

		return Ranked::ids( $rows, $exclude, $limit );
	}
}
