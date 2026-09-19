<?php
/**
 * Recommendation engine contract.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

defined( 'ABSPATH' ) || exit;

/**
 * One way of choosing products to suggest.
 *
 * An engine's only job is ranking. It does not check stock, visibility, price or
 * whether the product still exists, and it does not decide how many to show. The
 * service around it does all of that, which is why an engine may over-return and
 * why every engine can be reasoned about on its own.
 */
interface Engine {

	/**
	 * Stable machine name, stored in placements and in the event log.
	 *
	 * Changing one of these breaks reporting continuity, so they are treated as
	 * part of the data format rather than as an implementation detail.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Translated name for the admin screens.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Translated one line explanation of what this engine does.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Whether this engine can say anything useful in this context.
	 *
	 * Returning false is not a failure. A co-purchase engine on a page with no
	 * product simply has nothing to work from, and the service moves on to the
	 * next candidate rather than rendering an empty block.
	 *
	 * @param Context $context Where the request came from.
	 *
	 * @return bool
	 */
	public function supports( Context $context ): bool;

	/**
	 * Whether the result depends on who is asking.
	 *
	 * A personal result is never cached and never shared, because a cache entry
	 * loose enough to be reused is one that can show a shopper someone else's
	 * history.
	 *
	 * @return bool
	 */
	public function is_personal(): bool;

	/**
	 * Ranked product ids, best first.
	 *
	 * May return more than asked for and may return products that will later be
	 * filtered out. Must not return the context's excluded products.
	 *
	 * @param Context $context Where the request came from.
	 * @param int     $limit   How many the caller would like.
	 *
	 * @return int[]
	 */
	public function recommend( Context $context, int $limit ): array;
}
