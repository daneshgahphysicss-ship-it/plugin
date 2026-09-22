<?php
/**
 * Affinity rebuild job.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Core\Config;
use FWS\Core\Cron;
use FWS\Core\Hookable;
use FWS\Core\State;
use FWS\Support\Logger;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds the product to product affinity table.
 *
 * Three properties matter more than speed here.
 *
 * It is resumable. A shop with two million order lines cannot finish inside one
 * cron request, so progress is kept in a cursor and the next run continues from
 * where the last one stopped rather than starting over.
 *
 * It never leaves the table empty. Rows are written with this run's timestamp
 * and only rows still carrying an older one are removed, at the end. A shopper
 * browsing during the rebuild sees yesterday's recommendations or today's, never
 * a blank block.
 *
 * It refuses work it cannot do. Past a configurable ceiling the window is
 * narrowed instead of the job being attempted and timing out, and the decision
 * is written to the log so the shop owner can see why the window shrank.
 */
final class AffinityBuilder implements Hookable {

	/**
	 * Seconds one cron run may spend.
	 *
	 * Deliberately below the sixty second mark most shared hosts use for the
	 * PHP time limit, leaving room for the prune and the state write.
	 */
	const BUDGET = 40;

	/**
	 * Smallest window the ceiling logic will narrow to.
	 */
	const MIN_WINDOW_DAYS = 30;

	/**
	 * Views are noisier and far more numerous than orders, so the co-view pass
	 * looks at a shorter window than the co-purchase pass.
	 */
	const VIEW_WINDOW_CAP = 45;

	/**
	 * Attach to cron.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( Cron::AFFINITY, array( $this, 'run' ) );
	}

	/**
	 * Continue or start a rebuild.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! Config::bool( 'recommendations_enabled' ) ) {
			return;
		}

		$started = microtime( true );
		$cursor  = $this->cursor();

		if ( array() === $cursor ) {
			Logger::debug( 'Affinity rebuild skipped: nothing to build from.' );

			return;
		}

		$kinds = AffinityRepository::kinds();

		while ( $cursor['kind_index'] < count( $kinds ) ) {
			$kind = $kinds[ $cursor['kind_index'] ];

			if ( 0 === $cursor['next_id'] ) {
				$this->build_totals( $kind, $cursor );
			}

			$done = $this->build_range( $kind, $cursor );

			if ( $done ) {
				++$cursor['kind_index'];
				$cursor['next_id'] = 0;
			}

			if ( ( microtime( true ) - $started ) > self::BUDGET ) {
				State::set( State::AFFINITY_CURSOR, $cursor );

				Logger::debug(
					'Affinity rebuild paused, will resume on the next run.',
					array(
						'kind'    => $kind,
						'next_id' => $cursor['next_id'],
					)
				);

				return;
			}
		}

		$this->finish( $cursor, $started );
	}

	/**
	 * Discard any partial rebuild and start fresh on the next run.
	 *
	 * @return void
	 */
	public function reset(): void {
		State::delete( State::AFFINITY_CURSOR );
	}

	/**
	 * Whether a rebuild is part way through.
	 *
	 * @return bool
	 */
	public static function in_progress(): bool {
		return is_array( State::get( State::AFFINITY_CURSOR ) );
	}

	/**
	 * The saved cursor, or a new one describing a fresh pass.
	 *
	 * @return array<string, mixed> Empty when there is nothing to build.
	 */
	private function cursor(): array {
		$saved = State::get( State::AFFINITY_CURSOR );

		if ( is_array( $saved ) && isset( $saved['started'], $saved['kind_index'], $saved['next_id'], $saved['high'], $saved['batch'], $saved['window'] ) ) {
			return array(
				'started'    => (string) $saved['started'],
				'kind_index' => Sanitize::clamp( $saved['kind_index'], 0, 10 ),
				'next_id'    => Sanitize::id( $saved['next_id'] ),
				'low'        => Sanitize::id( $saved['low'] ?? 0 ),
				'high'       => Sanitize::id( $saved['high'] ),
				'batch'      => Sanitize::clamp( $saved['batch'], 1, 10000000 ),
				'window'     => Sanitize::clamp( $saved['window'], 1, 3650 ),
			);
		}

		return $this->start_pass();
	}

	/**
	 * Decide the shape of a new pass.
	 *
	 * @return array<string, mixed> Empty when there is nothing to build.
	 */
	private function start_pass(): array {
		list( $low, $high ) = AffinityRepository::post_id_bounds();

		if ( 0 === $high ) {
			return array();
		}

		$window = $this->window_days();
		$span   = ( $high - $low ) + 1;
		$batch  = $span;
		$since  = Sanitize::datetime( time() - ( $window * DAY_IN_SECONDS ) );
		$rows   = AffinityRepository::has_lookup_tables()
			? AffinityRepository::count_order_lines( $since )
			: 0;

		$rows += AffinityRepository::count_views( $since );

		if ( $rows > Config::int( 'affinity_single_query_max_rows', 1000, 100000000 ) ) {
			// Past this size a single grouped self-join is the wrong shape: it
			// holds a temporary table for minutes and is killed by the host
			// before it commits anything. Split it instead.
			$batch = Config::int( 'affinity_batch_size', 50, 100000 );
		}

		return array(
			'started'    => Sanitize::datetime(),
			'kind_index' => 0,
			'next_id'    => 0,
			'low'        => $low,
			'high'       => $high,
			'batch'      => max( 1, $batch ),
			'window'     => $window,
		);
	}

	/**
	 * The window to build over, narrowed if the shop is too large for it.
	 *
	 * @return int
	 */
	private function window_days(): int {
		$window = Config::int( 'affinity_window_days', 7, 3650 );

		if ( ! AffinityRepository::has_lookup_tables() ) {
			return $window;
		}

		$ceiling = Config::int( 'affinity_batched_max_rows', 10000, 100000000 );

		while ( $window > self::MIN_WINDOW_DAYS ) {
			$since = Sanitize::datetime( time() - ( $window * DAY_IN_SECONDS ) );

			if ( AffinityRepository::count_order_lines( $since ) <= $ceiling ) {
				return $window;
			}

			$window = max( self::MIN_WINDOW_DAYS, (int) floor( $window / 2 ) );

			Logger::warning(
				'Affinity window narrowed: the configured window holds more order lines than the ceiling allows.',
				array(
					'window_days' => $window,
					'ceiling'     => $ceiling,
				)
			);
		}

		return $window;
	}

	/**
	 * The window's lower bound for a pass.
	 *
	 * Derived from the pass start rather than from now, so a rebuild that spans
	 * several cron runs measures every batch against the same boundary. Using
	 * `now` per batch would mean early products were built over a slightly wider
	 * window than late ones, and the scores would not be comparable.
	 *
	 * @param array<string, mixed> $cursor Pass cursor.
	 * @param int                  $window Window length in days.
	 *
	 * @return string MySQL datetime.
	 */
	private function since( array $cursor, int $window ): string {
		$started = strtotime( (string) $cursor['started'] );

		if ( false === $started ) {
			$started = time();
		}

		return gmdate( 'Y-m-d H:i:s', $started - ( $window * DAY_IN_SECONDS ) );
	}

	/**
	 * The window a kind is built over.
	 *
	 * @param string               $kind   Pair kind.
	 * @param array<string, mixed> $cursor Pass cursor.
	 *
	 * @return int
	 */
	private function window_for( string $kind, array $cursor ): int {
		$window = (int) $cursor['window'];

		if ( AffinityRepository::KIND_VIEWED === $kind ) {
			return min( $window, self::VIEW_WINDOW_CAP );
		}

		return $window;
	}

	/**
	 * Write the per product total rows for one kind.
	 *
	 * @param string               $kind   Pair kind.
	 * @param array<string, mixed> $cursor Pass cursor.
	 *
	 * @return void
	 */
	private function build_totals( string $kind, array $cursor ): void {
		$window = $this->window_for( $kind, $cursor );
		$since  = $this->since( $cursor, $window );
		$run_at = (string) $cursor['started'];

		if ( AffinityRepository::KIND_BOUGHT === $kind ) {
			if ( ! AffinityRepository::has_lookup_tables() ) {
				return;
			}

			AffinityRepository::rebuild_bought_totals( $since, $window, $run_at );

			return;
		}

		AffinityRepository::rebuild_viewed_totals( $since, $window, $run_at );
	}

	/**
	 * Build one range of source products for one kind.
	 *
	 * @param string               $kind   Pair kind.
	 * @param array<string, mixed> $cursor Pass cursor, advanced in place.
	 *
	 * @return bool True when the kind is finished.
	 */
	private function build_range( string $kind, array &$cursor ): bool {
		if ( AffinityRepository::KIND_BOUGHT === $kind && ! AffinityRepository::has_lookup_tables() ) {
			// Analytics is switched off, so there is no authoritative record of
			// what was bought together. Co-view still works, so skip rather than
			// abandon the whole rebuild.
			return true;
		}

		$window      = $this->window_for( $kind, $cursor );
		$since       = $this->since( $cursor, $window );
		$run_at      = (string) $cursor['started'];
		$min_support = Config::int( 'affinity_min_support', 1, 1000 );

		$low  = max( (int) $cursor['low'], (int) $cursor['next_id'] );
		$high = min( (int) $cursor['high'], ( $low + (int) $cursor['batch'] ) - 1 );

		if ( AffinityRepository::KIND_BOUGHT === $kind ) {
			AffinityRepository::rebuild_bought_pairs( $since, $window, $run_at, $min_support, $low, $high );
		} else {
			AffinityRepository::rebuild_viewed_pairs( $since, $window, $run_at, $min_support, $low, $high );
		}

		AffinityRepository::rescore( $kind, $run_at, $low, $high );

		$cursor['next_id'] = $high + 1;

		return $cursor['next_id'] > (int) $cursor['high'];
	}

	/**
	 * Drop last pass's rows, publish the result and clear the cursor.
	 *
	 * @param array<string, mixed> $cursor  Completed pass cursor.
	 * @param float                $started Run start from microtime().
	 *
	 * @return void
	 */
	private function finish( array $cursor, float $started ): void {
		$run_at  = (string) $cursor['started'];
		$pruned  = 0;
		$partial = false;

		do {
			$batch   = AffinityRepository::prune( $run_at );
			$pruned += $batch;

			if ( $batch > 0 && ( microtime( true ) - $started ) > ( self::BUDGET + 10 ) ) {
				// Leftover rows are last pass's figures, not corrupt ones, so
				// serving them for another hour is the safe way to run out of
				// time here.
				$partial = true;
				break;
			}
		} while ( $batch > 0 );

		State::set( State::AFFINITY_BUILT_AT, time() );
		State::delete( State::AFFINITY_CURSOR );
		State::bump_cache_epoch();

		Logger::info(
			'Affinity rebuild finished.',
			array(
				'window_days'   => (int) $cursor['window'],
				'pruned'        => $pruned,
				'prune_partial' => $partial,
				'rows'          => AffinityRepository::total(),
				'seconds'       => round( microtime( true ) - $started, 2 ),
			)
		);
	}
}
