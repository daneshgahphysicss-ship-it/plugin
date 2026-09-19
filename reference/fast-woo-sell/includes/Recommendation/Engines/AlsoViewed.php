<?php
/**
 * Co-view engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\AffinityRepository;
use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Products looked at in the same browsing session as the seed products.
 *
 * Weaker than co-purchase but available far sooner, and it captures comparison
 * behaviour that orders never show: the three cameras someone weighed up before
 * buying one of them are related, even though they were never bought together.
 */
final class AlsoViewed implements Engine {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'also_viewed';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'بازدیدکنندگان این محصول، این‌ها را هم دیدند', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'بر پایهٔ رفتار مرور: محصولاتی که در یک نشست همراه این محصول دیده شده‌اند.', 'fast-woo-sell' );
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
	 * Needs at least one seed product.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		return array() !== $context->seeds();
	}

	/**
	 * Rank by co-view similarity.
	 *
	 * @param Context $context Request context.
	 * @param int     $limit   How many to return.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array {
		$seeds = $context->seeds();

		if ( array() === $seeds ) {
			return array();
		}

		$fetch = $limit + count( $context->exclude() );

		$rows = 1 === count( $seeds )
			? AffinityRepository::top( $seeds[0], AffinityRepository::KIND_VIEWED, $fetch )
			: AffinityRepository::top_for_many( $seeds, AffinityRepository::KIND_VIEWED, $fetch );

		return Ranked::ids( $rows, $context->exclude(), $limit );
	}
}
