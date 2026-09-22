<?php
/**
 * Popularity reads for the recommendation engines.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Install\Schema;
use FWS\Support\Sanitize;
use FWS\Support\Woo;
use FWS\Tracking\EventRepository;
use FWS\Tracking\EventType;

defined( 'ABSPATH' ) || exit;

/**
 * Ranked product lists that do not depend on a seed product.
 *
 * Everything here reads the daily rollup rather than the raw event log or the
 * order tables directly. A shop page must never wait on a scan of millions of
 * events, and the rollup already holds one small row per product per day.
 */
final class RankingRepository {

	/**
	 * Products selling best over a recent window.
	 *
	 * Ordered by units first and views second, so a product with real sales
	 * outranks one that is merely being looked at, and a shop with no order data
	 * yet still gets a sensible list instead of an empty one.
	 *
	 * @param int   $days    How far back to look.
	 * @param int   $limit   How many to return.
	 * @param int[] $exclude Products to leave out.
	 *
	 * @return int[]
	 */
	public static function trending( int $days, int $limit, array $exclude = array() ): array {
		return self::ranked(
			Sanitize::clamp( $days, 1, 365 ),
			$limit,
			$exclude,
			'',
			'SUM( units ) DESC, SUM( views ) DESC, product_id ASC'
		);
	}

	/**
	 * Products earning the most revenue over a recent window.
	 *
	 * @param int   $days    How far back to look.
	 * @param int   $limit   How many to return.
	 * @param int[] $exclude Products to leave out.
	 *
	 * @return int[]
	 */
	public static function best_sellers( int $days, int $limit, array $exclude = array() ): array {
		return self::ranked(
			Sanitize::clamp( $days, 1, 3650 ),
			$limit,
			$exclude,
			'HAVING SUM( orders ) > 0',
			'SUM( revenue ) DESC, SUM( orders ) DESC, product_id ASC'
		);
	}

	/**
	 * One shape of aggregate read against the daily product rollup.
	 *
	 * @param int    $days    How far back to look.
	 * @param int    $limit   How many to return.
	 * @param int[]  $exclude Products to leave out.
	 * @param string $having  Optional HAVING clause, no placeholders.
	 * @param string $order   ORDER BY expression, no placeholders.
	 *
	 * @return int[]
	 */
	private static function ranked( int $days, int $limit, array $exclude, string $having, string $order ): array {
		global $wpdb;

		$limit   = Sanitize::clamp( $limit, 1, 100 );
		$exclude = Sanitize::id_list( $exclude, 100 );
		$table   = Schema::table( Schema::STATS_PRODUCT );
		$since   = Sanitize::date( $days );

		$not_in = '';
		$args   = array( $since );

		if ( array() !== $exclude ) {
			$not_in = ' AND product_id NOT IN ( ' . Woo::int_placeholders( count( $exclude ) ) . ' )';
			$args   = array_merge( $args, $exclude );
		}

		$args[] = $limit;

		$sql = "SELECT product_id
			FROM {$table}
			WHERE stat_date >= %s{$not_in}
			GROUP BY product_id
			{$having}
			ORDER BY {$order}
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table name; clauses are class constants; every value is a placeholder.
		return self::ids( $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Products this visitor looked at, most recent first.
	 *
	 * @param string $visitor_id Visitor identifier.
	 * @param int    $limit      How many to return.
	 * @param int[]  $exclude    Products to leave out.
	 *
	 * @return int[]
	 */
	public static function recently_viewed( string $visitor_id, int $limit, array $exclude = array() ): array {
		global $wpdb;

		$visitor_id = Sanitize::hex( $visitor_id, 32 );
		$limit      = Sanitize::clamp( $limit, 1, 100 );
		$exclude    = Sanitize::id_list( $exclude, 100 );

		if ( '' === $visitor_id ) {
			return array();
		}

		$table  = EventRepository::table();
		$not_in = '';
		$args   = array( $visitor_id, EventType::PRODUCT_VIEW );

		if ( array() !== $exclude ) {
			$not_in = ' AND object_id NOT IN ( ' . Woo::int_placeholders( count( $exclude ) ) . ' )';
			$args   = array_merge( $args, $exclude );
		}

		$args[] = $limit;

		$sql = "SELECT object_id
			FROM {$table}
			WHERE visitor_id = %s
				AND event_type = %s
				AND object_id > 0{$not_in}
			GROUP BY object_id
			ORDER BY MAX( created_at ) DESC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table name; every value is a placeholder.
		return self::ids( $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Products a customer has already bought, most recent first.
	 *
	 * Used both as a seed for personal recommendations and as an exclusion list
	 * for shops that do not want to suggest a repeat purchase. Resolved through
	 * the customer lookup table so it works on both order storage backends.
	 *
	 * @param int $user_id Customer.
	 * @param int $limit   How many to return.
	 *
	 * @return int[]
	 */
	public static function purchased_by( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$user_id = Sanitize::id( $user_id );
		$limit   = Sanitize::clamp( $limit, 1, 200 );
		$people  = $wpdb->prefix . 'wc_customer_lookup';

		if ( 0 === $user_id || ! AffinityRepository::has_lookup_tables() || ! Woo::table_exists( $people ) ) {
			return array();
		}

		$lookup   = AffinityRepository::lookup_table();
		$orders   = AffinityRepository::order_stats_table();
		$statuses = Woo::paid_statuses();
		$in       = Woo::string_placeholders( count( $statuses ) );

		$sql = "SELECT l.product_id
			FROM {$lookup} l
			INNER JOIN {$orders} s ON s.order_id = l.order_id
			INNER JOIN {$people} c ON c.customer_id = s.customer_id
			WHERE c.user_id = %d
				AND s.status IN ( {$in} )
				AND l.product_id > 0
			GROUP BY l.product_id
			ORDER BY MAX( l.date_created ) DESC
			LIMIT %d";

		$args = array_merge( array( $user_id ), $statuses, array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table names; every value is a placeholder.
		return self::ids( $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Cast a column of ids to a clean integer list.
	 *
	 * @param mixed $values Result of get_col().
	 *
	 * @return int[]
	 */
	private static function ids( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		return Sanitize::id_list( $values, 200 );
	}
}
