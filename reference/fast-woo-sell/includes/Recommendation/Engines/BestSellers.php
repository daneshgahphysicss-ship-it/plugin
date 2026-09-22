<?php
/**
 * Best sellers engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;
use FWS\Recommendation\RankingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The shop's steady earners over a long window.
 *
 * Ranked by revenue rather than by units, because a list of the cheapest thing in
 * the catalogue is what unit ranking produces on most shops and it is not a list
 * anyone wants to be shown.
 */
final class BestSellers implements Engine {

	/**
	 * Days of history the ranking covers.
	 */
	const WINDOW_DAYS = 365;

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'best_sellers';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'پرفروش‌ترین‌ها', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'محصولاتی که در یک سال گذشته بیشترین درآمد را داشته‌اند.', 'fast-woo-sell' );
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
	 * Rank by revenue.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		$exclude = $context->exclude();

		return Ranked::clean(
			RankingRepository::best_sellers( self::WINDOW_DAYS, $limit + count( $exclude ), $exclude ),
			$exclude,
			$limit
		);
	}
}
