<?php
/**
 * Trending engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;
use FWS\Recommendation\RankingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What is selling right now, regardless of what the shopper is looking at.
 *
 * Works everywhere, including on a shop's very first day, which is what makes it
 * the reliable rung near the bottom of the fallback ladder. The window is short
 * on purpose: a fourteen day window follows a season, an all-time list does not.
 */
final class Trending implements Engine {

	/**
	 * Days of history the ranking covers.
	 */
	const WINDOW_DAYS = 14;

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'trending';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'پرفروش‌های این روزها', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'محصولاتی که در دو هفتهٔ گذشته بیشترین فروش و بازدید را داشته‌اند.', 'fast-woo-sell' );
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
	 * Always available.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		unset( $context );

		return true;
	}

	/**
	 * Rank by recent units sold.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		$exclude = $context->exclude();

		return Ranked::clean(
			RankingRepository::trending( self::WINDOW_DAYS, $limit + count( $exclude ), $exclude ),
			$exclude,
			$limit
		);
	}
}
