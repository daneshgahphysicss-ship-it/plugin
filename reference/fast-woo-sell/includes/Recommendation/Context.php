<?php
/**
 * Recommendation request context.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an engine is allowed to know about where it is being asked from.
 *
 * Engines receive this instead of reading globals, `$post`, or the cart. That is
 * what makes them testable without a request, and what stops an engine quietly
 * depending on the page it happens to have been written for.
 */
final class Context {

	/**
	 * A single product page.
	 */
	const PAGE_PRODUCT = 'product';

	/**
	 * The cart page.
	 */
	const PAGE_CART = 'cart';

	/**
	 * The checkout page.
	 */
	const PAGE_CHECKOUT = 'checkout';

	/**
	 * The order received page.
	 */
	const PAGE_ORDER = 'order';

	/**
	 * A shop, archive or search page.
	 */
	const PAGE_ARCHIVE = 'archive';

	/**
	 * Anywhere else: a widget, a shortcode, an account page.
	 */
	const PAGE_OTHER = 'other';

	/**
	 * Where the request came from.
	 *
	 * @var string
	 */
	private $page;

	/**
	 * The product being looked at, zero when there is not one.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Seed products: cart contents, order lines, or just the current product.
	 *
	 * @var int[]
	 */
	private $seeds;

	/**
	 * Product category term ids in play.
	 *
	 * @var int[]
	 */
	private $category_ids;

	/**
	 * Logged in user, zero for a guest.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Products that must not be returned.
	 *
	 * @var int[]
	 */
	private $exclude;

	/**
	 * Placement being rendered, zero for a direct call.
	 *
	 * @var int
	 */
	private $placement_id;

	/**
	 * Experiment arm, empty when not in an experiment.
	 *
	 * @var string
	 */
	private $variant;

	/**
	 * Build a context.
	 *
	 * @param array<string, mixed> $args Overrides.
	 */
	public function __construct( array $args = array() ) {
		$this->page         = isset( $args['page'] ) ? Sanitize::slug( $args['page'], 20 ) : self::PAGE_OTHER;
		$this->product_id   = isset( $args['product_id'] ) ? Sanitize::id( $args['product_id'] ) : 0;
		$this->seeds        = isset( $args['seeds'] ) ? Sanitize::id_list( $args['seeds'], 50 ) : array();
		$this->category_ids = isset( $args['category_ids'] ) ? Sanitize::id_list( $args['category_ids'], 30 ) : array();
		$this->user_id      = isset( $args['user_id'] ) ? Sanitize::id( $args['user_id'] ) : 0;
		$this->exclude      = isset( $args['exclude'] ) ? Sanitize::id_list( $args['exclude'], 100 ) : array();
		$this->placement_id = isset( $args['placement_id'] ) ? Sanitize::id( $args['placement_id'] ) : 0;
		$this->variant      = isset( $args['variant'] ) ? Sanitize::slug( $args['variant'], 20 ) : '';

		if ( 0 !== $this->product_id && ! in_array( $this->product_id, $this->seeds, true ) ) {
			$this->seeds[] = $this->product_id;
		}

		// A product being recommended alongside itself is the single most common
		// way one of these blocks looks broken, so the current product is always
		// excluded regardless of what the caller passed.
		if ( 0 !== $this->product_id && ! in_array( $this->product_id, $this->exclude, true ) ) {
			$this->exclude[] = $this->product_id;
		}
	}

	/**
	 * Where the request came from.
	 *
	 * @return string
	 */
	public function page(): string {
		return $this->page;
	}

	/**
	 * The product being looked at.
	 *
	 * @return int
	 */
	public function product_id(): int {
		return $this->product_id;
	}

	/**
	 * Seed products.
	 *
	 * @return int[]
	 */
	public function seeds(): array {
		return $this->seeds;
	}

	/**
	 * Product category term ids in play.
	 *
	 * @return int[]
	 */
	public function category_ids(): array {
		return $this->category_ids;
	}

	/**
	 * Logged in user id.
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Products that must not be returned.
	 *
	 * @return int[]
	 */
	public function exclude(): array {
		return $this->exclude;
	}

	/**
	 * Placement being rendered.
	 *
	 * @return int
	 */
	public function placement_id(): int {
		return $this->placement_id;
	}

	/**
	 * Experiment arm.
	 *
	 * @return string
	 */
	public function variant(): string {
		return $this->variant;
	}

	/**
	 * A copy with more exclusions.
	 *
	 * @param int[] $ids Products to add.
	 *
	 * @return self
	 */
	public function excluding( array $ids ): self {
		return new self(
			array(
				'page'         => $this->page,
				'product_id'   => $this->product_id,
				'seeds'        => $this->seeds,
				'category_ids' => $this->category_ids,
				'user_id'      => $this->user_id,
				'exclude'      => array_merge( $this->exclude, $ids ),
				'placement_id' => $this->placement_id,
				'variant'      => $this->variant,
			)
		);
	}

	/**
	 * A short stable string identifying this context for cache keys.
	 *
	 * The user id is deliberately absent: engines that depend on who is asking
	 * declare it themselves, so a shared context never produces a cache entry
	 * that leaks one shopper's history to the next.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		$seeds = $this->seeds;
		sort( $seeds );

		$exclude = $this->exclude;
		sort( $exclude );

		return implode(
			'|',
			array(
				$this->page,
				$this->product_id,
				implode( ',', $seeds ),
				implode( ',', $exclude ),
				$this->placement_id,
				$this->variant,
			)
		);
	}
}
