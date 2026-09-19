<?php
/**
 * Scheduled jobs.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every cron hook and interval the plugin uses.
 *
 * Keeping the list in one place is what makes deactivation and uninstall able
 * to clean up completely; a hook scheduled from inside a module and forgotten
 * here would keep firing after the plugin is gone.
 */
final class Cron {

	/**
	 * Roll raw events up into the daily statistics tables.
	 */
	const ROLLUP = 'fws_rollup_stats';

	/**
	 * Rebuild the product affinity table.
	 */
	const AFFINITY = 'fws_rebuild_affinity';

	/**
	 * Move idle carts to abandoned and schedule their first message.
	 */
	const SCAN_CARTS = 'fws_scan_carts';

	/**
	 * Drain the job queue, including outbound recovery messages.
	 */
	const QUEUE = 'fws_process_queue';

	/**
	 * Recalculate stock forecasts.
	 */
	const FORECAST = 'fws_build_forecast';

	/**
	 * Apply the retention settings.
	 */
	const CLEANUP = 'fws_cleanup';

	/**
	 * Five minute interval slug.
	 */
	const EVERY_FIVE_MINUTES = 'fws_five_minutes';

	/**
	 * Fifteen minute interval slug.
	 */
	const EVERY_FIFTEEN_MINUTES = 'fws_fifteen_minutes';

	/**
	 * Hook name to recurrence.
	 *
	 * @return array<string, string>
	 */
	public static function hooks(): array {
		return array(
			self::QUEUE      => self::EVERY_FIVE_MINUTES,
			self::SCAN_CARTS => self::EVERY_FIFTEEN_MINUTES,
			self::ROLLUP     => 'hourly',
			self::AFFINITY   => 'daily',
			self::FORECAST   => 'daily',
			self::CLEANUP    => 'daily',
		);
	}

	/**
	 * Register the custom intervals.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public static function add_schedules( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::EVERY_FIVE_MINUTES ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'هر ۵ دقیقه (فروش هوشمند)', 'fast-woo-sell' ),
		);

		$schedules[ self::EVERY_FIFTEEN_MINUTES ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'هر ۱۵ دقیقه (فروش هوشمند)', 'fast-woo-sell' ),
		);

		return $schedules;
	}

	/**
	 * Schedule anything that is not scheduled yet.
	 *
	 * Start times are staggered so a fresh install does not fire six jobs in the
	 * same cron run.
	 *
	 * @return void
	 */
	public static function schedule_all(): void {
		$offset = 0;

		foreach ( self::hooks() as $hook => $recurrence ) {
			$offset += 2 * MINUTE_IN_SECONDS;

			if ( false !== wp_next_scheduled( $hook ) ) {
				continue;
			}

			wp_schedule_event( time() + $offset, $recurrence, $hook );
		}
	}

	/**
	 * Remove every scheduled occurrence of every hook.
	 *
	 * @return void
	 */
	public static function unschedule_all(): void {
		foreach ( array_keys( self::hooks() ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Queue a hook to run as soon as cron next fires.
	 *
	 * @param string $hook One of the class constants.
	 * @param array  $args Arguments passed to the callback.
	 *
	 * @return void
	 */
	public static function run_soon( string $hook, array $args = array() ): void {
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 10, $hook, $args );
		}
	}
}
