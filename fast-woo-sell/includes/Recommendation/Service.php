<?php
/**
 * Recommendation service.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Core\Config;
use FWS\Support\Logger;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * The one entry point anything outside this namespace should use.
 *
 * It owns three decisions the engines are deliberately kept ignorant of.
 *
 * How many candidates to ask for. Filtering removes products, so asking for
 * exactly four and then discarding two leaves a block half empty. The service
 * over-fetches by a configurable multiple and trims afterwards.
 *
 * When to give up on an engine and try the next. An engine with no data returns
 * an empty list, which is a normal answer, not an error. The chain runs from the
 * most specific engine to the most general and stops as soon as it has enough.
 *
 * Whether a partial answer is worth showing. One lonely product under a heading
 * that promises recommendations looks worse than no block at all, so a result
 * below `min_results` is discarded entirely.
 */
final class Service {

	/**
	 * Largest number of candidates any engine is asked for.
	 */
	const MAX_FETCH = 100;

	/**
	 * Products to recommend for a context.
	 *
	 * @param Context $context   Where the request came from.
	 * @param string  $engine_id Preferred engine, empty to use the default chain.
	 * @param int     $limit     How many to return, zero for the configured default.
	 *
	 * @return Result
	 */
	public static function get( Context $context, string $engine_id = '', int $limit = 0 ): Result {
		if ( ! Config::bool( 'recommendations_enabled' ) ) {
			return Result::none();
		}

		$engine_id = Sanitize::slug( $engine_id, 32 );
		$limit     = self::limit( $limit );
		$fetch     = self::fetch( $limit );

		$picked       = array();
		$contributors = array();
		$primary      = '';
		$cached       = true;

		foreach ( self::chain( $engine_id ) as $id ) {
			$engine = Registry::get( $id );

			if ( null === $engine || ! $engine->supports( $context ) ) {
				continue;
			}

			list( $ids, $from_cache ) = self::candidates( $engine, $context, $fetch );

			if ( ! $from_cache ) {
				$cached = false;
			}

			$fresh = array_values( array_diff( $ids, $picked ) );

			if ( array() === $fresh ) {
				continue;
			}

			$picked         = array_merge( $picked, $fresh );
			$contributors[] = $id;

			if ( '' === $primary ) {
				$primary = $id;
			}

			if ( count( $picked ) >= $limit ) {
				break;
			}
		}

		$picked = array_slice( $picked, 0, $limit );

		/**
		 * Filter the final recommendation list.
		 *
		 * Runs after eligibility filtering, so anything added here bypasses the
		 * stock and visibility checks and is the caller's responsibility.
		 *
		 * @since 1.0.0
		 *
		 * @param int[]   $picked  Product ids, best first.
		 * @param Context $context Request context.
		 * @param string  $primary Engine that answered first.
		 */
		$picked = Sanitize::id_list( apply_filters( 'fws/recommendation/ids', $picked, $context, $primary ), $limit );

		if ( count( $picked ) < Config::int( 'min_results', 0, 24 ) ) {
			return Result::none();
		}

		return new Result(
			$picked,
			$primary,
			$contributors,
			'' !== $engine_id && $primary !== $engine_id,
			// Only claim a cache hit when an engine actually ran and every one of
			// them answered from cache. Reporting a hit for a chain that produced
			// nothing would make the diagnostics screen say the opposite of what
			// happened.
			$cached && array() !== $contributors
		);
	}

	/**
	 * Products for one named engine, with no fallback.
	 *
	 * Used by the admin preview, where seeing that an engine has nothing to say is
	 * the entire point and a silent fallback would hide it.
	 *
	 * @param Context $context   Where the request came from.
	 * @param string  $engine_id Engine to run.
	 * @param int     $limit     How many to return.
	 *
	 * @return Result
	 */
	public static function get_from( Context $context, string $engine_id, int $limit = 0 ): Result {
		$engine = Registry::get( Sanitize::slug( $engine_id, 32 ) );

		if ( null === $engine || ! $engine->supports( $context ) ) {
			return Result::none();
		}

		$limit = self::limit( $limit );

		list( $ids, $from_cache ) = self::candidates( $engine, $context, self::fetch( $limit ) );

		return new Result(
			array_slice( $ids, 0, $limit ),
			$engine->id(),
			array( $engine->id() ),
			false,
			$from_cache
		);
	}

	/**
	 * How many products to return.
	 *
	 * @param int $requested Caller's request, zero for the default.
	 *
	 * @return int
	 */
	private static function limit( int $requested ): int {
		return $requested > 0
			? Sanitize::clamp( $requested, 1, 24 )
			: Config::int( 'default_limit', 1, 24 );
	}

	/**
	 * How many candidates to ask an engine for.
	 *
	 * @param int $limit Final list length.
	 *
	 * @return int
	 */
	private static function fetch( int $limit ): int {
		return min( self::MAX_FETCH, $limit * Config::int( 'over_fetch', 1, 10 ) );
	}

	/**
	 * The order engines are tried in.
	 *
	 * A requested engine goes first but does not replace the chain. A placement
	 * asking for co-purchase recommendations on a shop with no orders yet should
	 * still render something, and the alternative is an empty block on every
	 * product page until the first sale.
	 *
	 * @param string $engine_id Preferred engine, or an empty string.
	 *
	 * @return string[]
	 */
	private static function chain( string $engine_id ): array {
		$order = Registry::order();

		if ( '' === $engine_id || ! Registry::exists( $engine_id ) ) {
			return $order;
		}

		return array_merge(
			array( $engine_id ),
			array_values( array_diff( $order, array( $engine_id ) ) )
		);
	}

	/**
	 * One engine's eligible products, from cache when possible.
	 *
	 * The cached list is the filtered one and is longer than any single request
	 * needs, so topping up from a second engine does not need its own key. Caching
	 * the raw candidates instead would mean re-running the stock and visibility
	 * checks on every page view, which is where the real cost is.
	 *
	 * @param Engine  $engine  Engine to run.
	 * @param Context $context Request context.
	 * @param int     $fetch   How many candidates to keep.
	 *
	 * @return array{0:int[],1:bool} Ids, and whether they came from cache.
	 */
	private static function candidates( Engine $engine, Context $context, int $fetch ): array {
		$key = Cache::is_cacheable( $engine ) ? Cache::key( $engine->id(), $context, $fetch ) : '';

		if ( '' !== $key ) {
			$hit = Cache::get( $key );

			if ( null !== $hit ) {
				return array( $hit, true );
			}
		}

		$ids = Filters::apply( $engine->recommend( $context, $fetch ), $context, $fetch );

		if ( '' !== $key ) {
			Cache::set( $key, $ids );
		}

		if ( array() === $ids ) {
			Logger::debug(
				'Recommendation engine returned nothing.',
				array(
					'engine' => $engine->id(),
					'page'   => $context->page(),
					'seeds'  => $context->seeds(),
				)
			);
		}

		return array( $ids, false );
	}
}
