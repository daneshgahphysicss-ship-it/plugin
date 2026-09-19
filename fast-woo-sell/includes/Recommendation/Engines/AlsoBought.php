<?php
/**
 * Co-purchase engine.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Recommendation\AffinityRepository;
use FWS\Recommendation\Context;
use FWS\Recommendation\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Products bought in the same order as the seed products.
 *
 * The strongest signal a shop has, because it is a record of decisions people
 * actually paid for rather than of pages they happened to open. It is also the
 * slowest to become useful: a new shop has no co-purchase history at all, which
 * is why this engine sits at the top of the fallback order rather than alone.
 */
final class AlsoBought implements Engine {

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'also_bought';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'خریداران این محصول، این‌ها را هم خریدند', 'fast-woo-sell' );
	}

	/**
	 * Admin description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'بر پایهٔ سفارش‌های پرداخت‌شده: محصولاتی که با این محصول در یک سفارش خریده شده‌اند.', 'fast-woo-sell' );
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
	 * Needs at least one seed product and a built affinity table.
	 *
	 * @param Context $context Request context.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool {
		return array() !== $context->seeds();
	}

	/**
	 * Rank by co-purchase similarity.
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

		$rows = 1 === count( $seeds )
			? AffinityRepository::top( $seeds[0], AffinityRepository::KIND_BOUGHT, $limit + count( $context->exclude() ) )
			: AffinityRepository::top_for_many( $seeds, AffinityRepository::KIND_BOUGHT, $limit + count( $context->exclude() ) );

		return Ranked::ids( $rows, $context->exclude(), $limit );
	}
}
