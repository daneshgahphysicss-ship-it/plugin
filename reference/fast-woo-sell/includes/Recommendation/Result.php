<?php
/**
 * Recommendation result.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * A finished list, plus enough provenance to report on it honestly.
 *
 * The engine that produced the list is carried alongside it rather than being
 * assumed from the placement, because the fallback chain means the two are
 * routinely different. Without this the reporting screens would credit an engine
 * that never ran.
 */
final class Result {

	/**
	 * Products to show, best first.
	 *
	 * @var int[]
	 */
	private $ids;

	/**
	 * Engine that produced the first, and usually every, product.
	 *
	 * @var string
	 */
	private $engine;

	/**
	 * Engines that contributed anything, in the order they ran.
	 *
	 * @var string[]
	 */
	private $contributors;

	/**
	 * Whether the requested engine was not the one that answered.
	 *
	 * @var bool
	 */
	private $fell_back;

	/**
	 * Whether the list came from cache.
	 *
	 * @var bool
	 */
	private $cached;

	/**
	 * Build a result.
	 *
	 * @param int[]    $ids          Products.
	 * @param string   $engine       Primary engine id.
	 * @param string[] $contributors Every engine that added something.
	 * @param bool     $fell_back    Whether the requested engine was replaced.
	 * @param bool     $cached       Whether this came from cache.
	 */
	public function __construct( array $ids, string $engine = '', array $contributors = array(), bool $fell_back = false, bool $cached = false ) {
		$this->ids          = Sanitize::id_list( $ids, 100 );
		$this->engine       = Sanitize::slug( $engine, 32 );
		$this->contributors = array_values( array_unique( array_map( 'strval', $contributors ) ) );
		$this->fell_back    = $fell_back;
		$this->cached       = $cached;
	}

	/**
	 * An empty result.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Products to show.
	 *
	 * @return int[]
	 */
	public function ids(): array {
		return $this->ids;
	}

	/**
	 * How many products there are.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->ids );
	}

	/**
	 * Whether there is anything to render.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->ids;
	}

	/**
	 * Primary engine id.
	 *
	 * @return string
	 */
	public function engine(): string {
		return $this->engine;
	}

	/**
	 * Every engine that contributed.
	 *
	 * @return string[]
	 */
	public function contributors(): array {
		return $this->contributors;
	}

	/**
	 * Whether more than one engine was needed.
	 *
	 * @return bool
	 */
	public function is_mixed(): bool {
		return count( $this->contributors ) > 1;
	}

	/**
	 * Whether the requested engine was replaced.
	 *
	 * @return bool
	 */
	public function fell_back(): bool {
		return $this->fell_back;
	}

	/**
	 * Whether this came from cache.
	 *
	 * @return bool
	 */
	public function cached(): bool {
		return $this->cached;
	}
}
