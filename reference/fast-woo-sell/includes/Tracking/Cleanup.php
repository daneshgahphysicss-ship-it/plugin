<?php
/**
 * Retention sweep.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Config;
use FWS\Core\Cron;
use FWS\Core\Hookable;
use FWS\Core\State;
use FWS\Install\Schema;
use FWS\Support\Logger;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the retention settings to the tables this plugin owns.
 *
 * Deletion is batched and time boxed. One unbounded DELETE against a raw event
 * table with tens of millions of rows will hold locks long enough to time out
 * the checkout of whoever is unlucky enough to be shopping at the time, so the
 * sweep removes a slice, checks the clock, and leaves the rest for the next run.
 */
final class Cleanup implements Hookable {

	/**
	 * Rows removed per statement.
	 */
	const BATCH = 1000;

	/**
	 * Seconds the whole sweep may take.
	 */
	const BUDGET = 20;

	/**
	 * Attach to cron.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( Cron::CLEANUP, array( $this, 'run' ) );
	}

	/**
	 * Remove everything past its retention window.
	 *
	 * @return void
	 */
	public function run(): void {
		$started = microtime( true );

		$events = $this->sweep_events( $started );
		$stats  = $this->sweep_stats();

		State::set( State::CLEANUP_RAN_AT, time() );

		Logger::debug(
			'Retention sweep finished.',
			array(
				'events'  => $events,
				'stats'   => $stats,
				'seconds' => round( microtime( true ) - $started, 2 ),
			)
		);
	}

	/**
	 * Delete expired raw events.
	 *
	 * @param float $started Sweep start time from microtime().
	 *
	 * @return int Rows removed.
	 */
	private function sweep_events( float $started ): int {
		$days = Config::int( 'event_retention_days', 0, 3650 );

		if ( $days <= 0 ) {
			// Zero means keep forever, which some shops want for their own
			// analysis. Never guess a default retention on the owner's behalf.
			return 0;
		}

		$before  = Sanitize::datetime( time() - ( $days * DAY_IN_SECONDS ) );
		$removed = 0;

		do {
			$batch    = EventRepository::delete_before( $before, self::BATCH );
			$removed += $batch;

			if ( ( microtime( true ) - $started ) > self::BUDGET ) {
				// The remainder is picked up by the next scheduled run.
				break;
			}
		} while ( $batch >= self::BATCH );

		return $removed;
	}

	/**
	 * Delete expired rollup rows.
	 *
	 * @return int Rows removed.
	 */
	private function sweep_stats(): int {
		global $wpdb;

		$days = Config::int( 'stats_retention_days', 0, 3650 );

		if ( $days <= 0 ) {
			return 0;
		}

		$before  = gmdate( 'Y-m-d', Sanitize::site_time() - ( $days * DAY_IN_SECONDS ) );
		$removed = 0;

		foreach ( array( Schema::STATS_PRODUCT, Schema::STATS_VARIANT ) as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE stat_date < %s LIMIT %d",
					$before,
					self::BATCH
				)
			);

			$removed += false === $deleted ? 0 : (int) $deleted;
		}

		return $removed;
	}
}
