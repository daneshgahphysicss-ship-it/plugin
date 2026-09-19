<?php
/**
 * Daily statistics rollup.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Cron;
use FWS\Core\Hookable;
use FWS\Core\State;
use FWS\Install\Schema;
use FWS\Support\Logger;
use FWS\Support\Sanitize;
use FWS\Support\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw events into the two daily statistics tables.
 *
 * The job recomputes whole days and overwrites the result, rather than adding
 * to a running total. That is the property that makes it safe: a cron run that
 * fires twice, a manual re-run, or an overlapping run can never double count.
 * The cost is rereading a day of events each hour, which the
 * `type_created` and `placement_created` indexes keep to a range scan.
 *
 * Reads then come from the rollup tables only. Nothing on an admin screen ever
 * scans the raw log.
 */
final class Rollup implements Hookable {

	/**
	 * Largest number of past days a catch-up run will rebuild.
	 */
	const MAX_CATCHUP_DAYS = 7;

	/**
	 * Attach to cron.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( Cron::ROLLUP, array( $this, 'run' ) );
	}

	/**
	 * Rebuild every day that may have changed.
	 *
	 * @return void
	 */
	public function run(): void {
		$days = $this->days_to_rebuild();

		foreach ( $days as $day ) {
			$this->rebuild_day( $day );
		}

		State::set( State::ROLLUP_RAN_AT, time() );

		Logger::debug(
			'Statistics rollup finished.',
			array(
				'days' => implode( ',', $days ),
			)
		);
	}

	/**
	 * Rebuild one day, in site time.
	 *
	 * @param string $day Date as Y-m-d.
	 *
	 * @return void
	 */
	public function rebuild_day( string $day ): void {
		$from = $day . ' 00:00:00';
		$to   = gmdate( 'Y-m-d 00:00:00', strtotime( $day . ' +1 day' ) );

		$this->rebuild_product_events( $from, $to );
		$this->rebuild_product_orders( $from, $to );
		$this->rebuild_variants( $from, $to );
	}

	/**
	 * Which days need rebuilding.
	 *
	 * Today and yesterday always, because both can still receive late events, and
	 * further back when a previous run was missed. Bounded so a site whose cron
	 * was dead for a month does not attempt a month long rebuild in one request.
	 *
	 * @return string[]
	 */
	private function days_to_rebuild(): array {
		$now      = Sanitize::site_time();
		$last_run = State::int( State::ROLLUP_RAN_AT );
		$days     = 2;

		if ( $last_run > 0 ) {
			$missed = (int) ceil( ( time() - $last_run ) / DAY_IN_SECONDS ) + 1;
			$days   = max( $days, min( self::MAX_CATCHUP_DAYS, $missed ) );
		}

		$out = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[] = gmdate( 'Y-m-d', $now - ( $i * DAY_IN_SECONDS ) );
		}

		return $out;
	}

	/**
	 * Event derived columns of the product rollup.
	 *
	 * ON DUPLICATE KEY UPDATE touches only these columns, so the order derived
	 * pass below cannot be clobbered by this one, in either order.
	 *
	 * @param string $from Inclusive lower bound, MySQL datetime.
	 * @param string $to   Exclusive upper bound, MySQL datetime.
	 *
	 * @return void
	 */
	private function rebuild_product_events( string $from, string $to ): void {
		global $wpdb;

		$stats  = Schema::table( Schema::STATS_PRODUCT );
		$events = EventRepository::table();

		$sql = "INSERT INTO {$stats}
			( product_id, stat_date, views, impressions, clicks, add_to_carts, attributed_orders, attributed_units, attributed_revenue )
			SELECT
				object_id,
				DATE( created_at ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				COUNT( DISTINCT CASE WHEN event_type = %s THEN order_id END ),
				SUM( CASE WHEN event_type = %s THEN quantity ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN value ELSE 0 END )
			FROM {$events}
			WHERE object_id > 0
				AND created_at >= %s
				AND created_at < %s
			GROUP BY object_id, DATE( created_at )
			ON DUPLICATE KEY UPDATE
				views = VALUES( views ),
				impressions = VALUES( impressions ),
				clicks = VALUES( clicks ),
				add_to_carts = VALUES( add_to_carts ),
				attributed_orders = VALUES( attributed_orders ),
				attributed_units = VALUES( attributed_units ),
				attributed_revenue = VALUES( attributed_revenue )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from Schema; every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
				$sql,
				EventType::PRODUCT_VIEW,
				EventType::IMPRESSION,
				EventType::CLICK,
				EventType::ADD_TO_CART,
				EventType::ORDER_ATTRIBUTED,
				EventType::ORDER_ATTRIBUTED,
				EventType::ORDER_ATTRIBUTED,
				$from,
				$to
			)
		);
	}

	/**
	 * Order derived columns of the product rollup.
	 *
	 * Taken from the WooCommerce analytics lookup tables rather than from the
	 * event log, because an order line is the authoritative record of what was
	 * actually bought and for how much. Skipped when analytics is switched off
	 * and the lookup tables are absent.
	 *
	 * @param string $from Inclusive lower bound, MySQL datetime.
	 * @param string $to   Exclusive upper bound, MySQL datetime.
	 *
	 * @return void
	 */
	private function rebuild_product_orders( string $from, string $to ): void {
		global $wpdb;

		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$orders = $wpdb->prefix . 'wc_order_stats';

		if ( ! Woo::table_exists( $lookup ) || ! Woo::table_exists( $orders ) ) {
			return;
		}

		$stats    = Schema::table( Schema::STATS_PRODUCT );
		$statuses = Woo::paid_statuses();
		$in       = Woo::string_placeholders( count( $statuses ) );

		$sql = "INSERT INTO {$stats}
			( product_id, stat_date, orders, units, revenue )
			SELECT
				l.product_id,
				DATE( l.date_created ),
				COUNT( DISTINCT l.order_id ),
				SUM( l.product_qty ),
				SUM( l.product_net_revenue )
			FROM {$lookup} l
			INNER JOIN {$orders} s ON s.order_id = l.order_id
			WHERE l.date_created >= %s
				AND l.date_created < %s
				AND s.status IN ( {$in} )
			GROUP BY l.product_id, DATE( l.date_created )
			ON DUPLICATE KEY UPDATE
				orders = VALUES( orders ),
				units = VALUES( units ),
				revenue = VALUES( revenue )";

		$args = array_merge( array( $from, $to ), $statuses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table names are prefixed constants; every value is a placeholder.
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * The placement, engine and experiment arm rollup.
	 *
	 * Every column here is derived from events, so the whole row is overwritten.
	 *
	 * `add_to_carts` stays zero until the recommendation renderer starts passing
	 * its placement into the add to cart request; the column exists now so the
	 * table does not need altering when it does.
	 *
	 * @param string $from Inclusive lower bound, MySQL datetime.
	 * @param string $to   Exclusive upper bound, MySQL datetime.
	 *
	 * @return void
	 */
	private function rebuild_variants( string $from, string $to ): void {
		global $wpdb;

		$stats  = Schema::table( Schema::STATS_VARIANT );
		$events = EventRepository::table();

		$sql = "INSERT INTO {$stats}
			( placement_id, engine, variant, stat_date, impressions, clicks, add_to_carts, orders, units, revenue )
			SELECT
				placement_id,
				engine,
				variant,
				DATE( created_at ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
				COUNT( DISTINCT CASE WHEN event_type = %s THEN order_id END ),
				SUM( CASE WHEN event_type = %s THEN quantity ELSE 0 END ),
				SUM( CASE WHEN event_type = %s THEN value ELSE 0 END )
			FROM {$events}
			WHERE placement_id > 0
				AND created_at >= %s
				AND created_at < %s
			GROUP BY placement_id, engine, variant, DATE( created_at )
			ON DUPLICATE KEY UPDATE
				impressions = VALUES( impressions ),
				clicks = VALUES( clicks ),
				add_to_carts = VALUES( add_to_carts ),
				orders = VALUES( orders ),
				units = VALUES( units ),
				revenue = VALUES( revenue )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from Schema; every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
				$sql,
				EventType::IMPRESSION,
				EventType::CLICK,
				EventType::ADD_TO_CART,
				EventType::ORDER_ATTRIBUTED,
				EventType::ORDER_ATTRIBUTED,
				EventType::ORDER_ATTRIBUTED,
				$from,
				$to
			)
		);
	}

}
