<?php
/**
 * Recently viewed engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;
use FWS\Recommendation\RankingRepository;
use FWS\Tracking\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * What this visitor looked at earlier, most recent first.
 *
 * Not a recommendation in the usual sense; it is a memory aid, and on shops with
 * a long consideration cycle it converts better than anything derived from other
 * people's behaviour. It reads the visitor's own rows, so it produces nothing at
 * all for someone who has opted out of tracking, which is the correct outcome.
 */
final class RecentlyViewed implements Engine {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'recently_viewed';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'بازدیدهای اخیر شما', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'محصولاتی که همین بازدیدکننده پیش‌تر دیده است. برای بازدیدکنندهٔ بدون ردیابی خالی می‌ماند.', 'fast-woo-sell' );
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
	 * Needs a trackable visitor with a cookie already set.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		unset( $context );

		return Visitor::is_trackable() && Visitor::has_cookie();
	}

	/**
	 * The visitor's own view history.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		if ( ! $this->supports( $context ) ) {
			return array();
		}

		$exclude = $context->exclude();

		return Ranked::clean(
			RankingRepository::recently_viewed( Visitor::id(), $limit + count( $exclude ), $exclude ),
			$exclude,
			$limit
		);
	}
}
