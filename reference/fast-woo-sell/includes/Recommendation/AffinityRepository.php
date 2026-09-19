<?php
/**
 * Affinity table access.
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
 * Every query against the affinity table.
 *
 * The table holds two sorts of row and the distinction is carried in `kind`:
 *
 *   kind = 'bought'        one row per ordered pair, `support` is the number of
 *                          orders containing both, `score` is the similarity
 *   kind = 'bought_total'  one row per product with `related_id` = 0, `support`
 *                          is how many orders contained that product at all
 *
 * The total rows exist so the similarity divisor is an indexed lookup on this
 * table rather than a derived table that has to be materialised again for every
 * batch of a large rebuild. Reads always filter on an exact kind, so a caller
 * asking for 'bought' can never be handed a total row by accident.
 */
final class AffinityRepository {

	/**
	 * Products bought in the same order.
	 */
	const KIND_BOUGHT = 'bought';

	/**
	 * Products viewed in the same session.
	 */
	const KIND_VIEWED = 'viewed';

	/**
	 * Suffix marking a per product total row.
	 */
	const TOTAL_SUFFIX = '_total';

	/**
	 * The kinds a rebuild produces.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( self::KIND_BOUGHT, self::KIND_VIEWED );
	}

	/**
	 * The kind name holding totals for a pair kind.
	 *
	 * @param string $kind Pair kind.
	 *
	 * @return string
	 */
	public static function total_kind( string $kind ): string {
		return $kind . self::TOTAL_SUFFIX;
	}

	/**
	 * The prefixed table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		return Schema::table( Schema::AFFINITY );
	}

	/**
	 * The WooCommerce order line lookup table name.
	 *
	 * @return string
	 */
	public static function lookup_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wc_order_product_lookup';
	}

	/**
	 * The WooCommerce order statistics table name.
	 *
	 * @return string
	 */
	public static function order_stats_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wc_order_stats';
	}

	/**
	 * Whether the WooCommerce analytics tables this class needs are present.
	 *
	 * @return bool
	 */
	public static function has_lookup_tables(): bool {
		return Woo::table_exists( self::lookup_table() ) && Woo::table_exists( self::order_stats_table() );
	}

	/**
	 * How many order lines fall inside the window.
	 *
	 * Used to choose a rebuild strategy before committing to one; the difference
	 * between a shop with ten thousand lines and one with five million is the
	 * difference between a single statement and a job that has to be resumable.
	 *
	 * @param string $since MySQL datetime lower bound.
	 *
	 * @return int
	 */
	public static function count_order_lines( string $since ): int {
		global $wpdb;

		$lookup = self::lookup_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$lookup} WHERE date_created >= %s", $since )
		);
	}

	/**
	 * How many product view events fall inside the window.
	 *
	 * @param string $since MySQL datetime lower bound.
	 *
	 * @return int
	 */
	public static function count_views( string $since ): int {
		global $wpdb;

		$events = EventRepository::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE event_type = %s AND created_at >= %s",
				EventType::PRODUCT_VIEW,
				$since
			)
		);
	}

	/**
	 * The lowest and highest product post id in the catalogue.
	 *
	 * Both affinity sources key on product ids, which are post ids, so the post
	 * table gives one id space that is correct whether or not the analytics
	 * tables exist. Ranges over it are how a large rebuild is split into pieces.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function post_id_bounds(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			"SELECT MIN(ID) AS lo, MAX(ID) AS hi FROM {$wpdb->posts}
			WHERE post_type IN ( 'product', 'product_variation' )"
		);

		if ( ! is_object( $row ) ) {
			return array( 0, 0 );
		}

		return array( Sanitize::id( $row->lo ), Sanitize::id( $row->hi ) );
	}

	/**
	 * Store how many paid orders contained each product.
	 *
	 * @param string $since       MySQL datetime lower bound.
	 * @param int    $window_days Window length recorded on the row.
	 * @param string $run_at      Timestamp stamped on every row this run writes.
	 *
	 * @return int Rows affected.
	 */
	public static function rebuild_bought_totals( string $since, int $window_days, string $run_at ): int {
		global $wpdb;

		$table    = self::table();
		$lookup   = self::lookup_table();
		$orders   = self::order_stats_table();
		$statuses = Woo::paid_statuses();
		$in       = Woo::string_placeholders( count( $statuses ) );

		$sql = "INSERT INTO {$table}
			( product_id, related_id, kind, support, score, window_days, updated_at )
			SELECT l.product_id, 0, %s, COUNT( DISTINCT l.order_id ), 0, %d, %s
			FROM {$lookup} l
			INNER JOIN {$orders} s ON s.order_id = l.order_id
			WHERE l.date_created >= %s
				AND l.product_id > 0
				AND s.status IN ( {$in} )
			GROUP BY l.product_id
			ON DUPLICATE KEY UPDATE
				support = VALUES( support ),
				window_days = VALUES( window_days ),
				updated_at = VALUES( updated_at )";

		$args = array_merge(
			array( self::total_kind( self::KIND_BOUGHT ), $window_days, $run_at, $since ),
			$statuses
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table names; every value is a placeholder.
		$done = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Store how many sessions viewed each product.
	 *
	 * @param string $since       MySQL datetime lower bound.
	 * @param int    $window_days Window length recorded on the row.
	 * @param string $run_at      Timestamp stamped on every row this run writes.
	 *
	 * @return int Rows affected.
	 */
	public static function rebuild_viewed_totals( string $since, int $window_days, string $run_at ): int {
		global $wpdb;

		$table  = self::table();
		$events = EventRepository::table();

		$sql = "INSERT INTO {$table}
			( product_id, related_id, kind, support, score, window_days, updated_at )
			SELECT object_id, 0, %s, COUNT( DISTINCT session_id ), 0, %d, %s
			FROM {$events}
			WHERE event_type = %s
				AND created_at >= %s
				AND object_id > 0
				AND session_id <> ''
			GROUP BY object_id
			ON DUPLICATE KEY UPDATE
				support = VALUES( support ),
				window_days = VALUES( window_days ),
				updated_at = VALUES( updated_at )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table names; every value is a placeholder.
		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
				$sql,
				self::total_kind( self::KIND_VIEWED ),
				$window_days,
				$run_at,
				EventType::PRODUCT_VIEW,
				$since
			)
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Store co-purchase counts for one range of source products.
	 *
	 * Both directions of every pair are written, because reads always filter on
	 * `product_id` and an unordered pair table would only ever answer half the
	 * questions asked of it. The self-join with `<>` rather than `<` produces
	 * both directions in a single pass.
	 *
	 * Score is left at zero here and filled in by `rescore()`. Joining the total
	 * rows inside this statement would mean selecting from the same table the
	 * statement is inserting into, which MySQL permits but does not define the
	 * ordering of; splitting the two removes the question entirely.
	 *
	 * @param string $since       MySQL datetime lower bound.
	 * @param int    $window_days Window length recorded on the row.
	 * @param string $run_at      Timestamp stamped on every row this run writes.
	 * @param int    $min_support Pairs seen fewer times than this are dropped.
	 * @param int    $low         Lowest source product id, inclusive.
	 * @param int    $high        Highest source product id, inclusive.
	 *
	 * @return int Rows affected.
	 */
	public static function rebuild_bought_pairs( string $since, int $window_days, string $run_at, int $min_support, int $low, int $high ): int {
		global $wpdb;

		$table    = self::table();
		$lookup   = self::lookup_table();
		$orders   = self::order_stats_table();
		$statuses = Woo::paid_statuses();
		$in       = Woo::string_placeholders( count( $statuses ) );

		$sql = "INSERT INTO {$table}
			( product_id, related_id, kind, support, score, window_days, updated_at )
			SELECT a.product_id, b.product_id, %s, COUNT( DISTINCT a.order_id ), 0, %d, %s
			FROM {$lookup} a
			INNER JOIN {$orders} s ON s.order_id = a.order_id
			INNER JOIN {$lookup} b ON b.order_id = a.order_id AND b.product_id <> a.product_id
			WHERE a.date_created >= %s
				AND a.product_id >= %d
				AND a.product_id <= %d
				AND b.product_id > 0
				AND s.status IN ( {$in} )
			GROUP BY a.product_id, b.product_id
			HAVING COUNT( DISTINCT a.order_id ) >= %d
			ON DUPLICATE KEY UPDATE
				support = VALUES( support ),
				window_days = VALUES( window_days ),
				updated_at = VALUES( updated_at )";

		$args = array_merge(
			array( self::KIND_BOUGHT, $window_days, $run_at, $since, $low, $high ),
			$statuses,
			array( $min_support )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table names; every value is a placeholder.
		$done = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Store co-view counts for one range of source products.
	 *
	 * @param string $since       MySQL datetime lower bound.
	 * @param int    $window_days Window length recorded on the row.
	 * @param string $run_at      Timestamp stamped on every row this run writes.
	 * @param int    $min_support Pairs seen fewer times than this are dropped.
	 * @param int    $low         Lowest source product id, inclusive.
	 * @param int    $high        Highest source product id, inclusive.
	 *
	 * @return int Rows affected.
	 */
	public static function rebuild_viewed_pairs( string $since, int $window_days, string $run_at, int $min_support, int $low, int $high ): int {
		global $wpdb;

		$table  = self::table();
		$events = EventRepository::table();

		$sql = "INSERT INTO {$table}
			( product_id, related_id, kind, support, score, window_days, updated_at )
			SELECT a.object_id, b.object_id, %s, COUNT( DISTINCT a.session_id ), 0, %d, %s
			FROM {$events} a
			INNER JOIN {$events} b
				ON b.session_id = a.session_id
				AND b.event_type = %s
				AND b.object_id <> a.object_id
			WHERE a.event_type = %s
				AND a.created_at >= %s
				AND a.session_id <> ''
				AND a.object_id >= %d
				AND a.object_id <= %d
				AND b.object_id > 0
			GROUP BY a.object_id, b.object_id
			HAVING COUNT( DISTINCT a.session_id ) >= %d
			ON DUPLICATE KEY UPDATE
				support = VALUES( support ),
				window_days = VALUES( window_days ),
				updated_at = VALUES( updated_at )";

		$args = array(
			self::KIND_VIEWED,
			$window_days,
			$run_at,
			EventType::PRODUCT_VIEW,
			EventType::PRODUCT_VIEW,
			$since,
			$low,
			$high,
			$min_support,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table names; every value is a placeholder.
		$done = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		return false === $done ? 0 : (int) $done;
	}
	/**
	 * Fill in similarity scores for one range of source products.
	 *
	 * The measure is cosine similarity on binary vectors:
	 *
	 *     score = co_occurrences / sqrt( total(a) * total(b) )
	 *
	 * Dividing by the geometric mean of both totals is what stops the shop's
	 * best seller being recommended alongside everything. Raw co-occurrence, or
	 * confidence that only divides by the source product's total, both rank a
	 * product that appears in a third of all orders above a product that is
	 * genuinely bought with this one.
	 *
	 * @param string $kind   Pair kind.
	 * @param string $run_at Only rows stamped with this timestamp are rescored.
	 * @param int    $low    Lowest source product id, inclusive.
	 * @param int    $high   Highest source product id, inclusive.
	 *
	 * @return int Rows affected.
	 */
	public static function rescore( string $kind, string $run_at, int $low, int $high ): int {
		global $wpdb;

		$table = self::table();
		$total = self::total_kind( $kind );

		// A multi-table UPDATE may reference the target table again as a joined
		// table. Only a subquery on the target is disallowed, which is why the
		// totals are joined rather than selected.
		$sql = "UPDATE {$table} p
			INNER JOIN {$table} ta ON ta.product_id = p.product_id AND ta.related_id = 0 AND ta.kind = %s
			INNER JOIN {$table} tb ON tb.product_id = p.related_id AND tb.related_id = 0 AND tb.kind = %s
			SET p.score = p.support / SQRT( ta.support * tb.support )
			WHERE p.kind = %s
				AND p.related_id > 0
				AND p.product_id >= %d
				AND p.product_id <= %d
				AND p.updated_at = %s
				AND ta.support > 0
				AND tb.support > 0";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table name; every value is a placeholder.
		$done = $wpdb->query(
			$wpdb->prepare( $sql, array( $total, $total, $kind, $low, $high, $run_at ) )
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Remove rows an earlier rebuild wrote and this one did not.
	 *
	 * This is how a full rebuild happens without ever truncating: new figures are
	 * written first, then anything still carrying an older stamp is dropped. A
	 * reader during the rebuild sees stale rows or fresh rows, never none.
	 *
	 * @param string $before MySQL datetime; rows older than this go.
	 * @param int    $limit  Largest number of rows to remove per call.
	 *
	 * @return int Rows removed.
	 */
	public static function prune( string $before, int $limit = 5000 ): int {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 100000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
		$done = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s LIMIT %d", $before, $limit )
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * How many rows the table holds.
	 *
	 * @return int
	 */
	public static function total(): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The strongest related products for one product.
	 *
	 * Served by `KEY lookup (product_id, kind, score)`, so the ordering is read
	 * straight off the index without a sort.
	 *
	 * @param int    $product_id Source product.
	 * @param string $kind       Pair kind.
	 * @param int    $limit      How many to return.
	 *
	 * @return array<int, array{product_id:int,score:float,support:int}>
	 */
	public static function top( int $product_id, string $kind, int $limit ): array {
		global $wpdb;

		$product_id = Sanitize::id( $product_id );
		$limit      = Sanitize::clamp( $limit, 1, 100 );

		if ( 0 === $product_id ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT related_id, score, support
				FROM {$table}
				WHERE product_id = %d AND kind = %s AND related_id > 0
				ORDER BY score DESC, support DESC, related_id ASC
				LIMIT %d",
				$product_id,
				$kind,
				$limit
			)
		);

		return self::shape( $rows );
	}

	/**
	 * The strongest related products across several source products.
	 *
	 * Scores are summed rather than averaged, so a product related to three
	 * things in the cart outranks one strongly related to a single item. The
	 * seed products are excluded from the result, since recommending what is
	 * already in the cart is the most common way these blocks embarrass a shop.
	 *
	 * @param int[]  $product_ids Source products.
	 * @param string $kind        Pair kind.
	 * @param int    $limit       How many to return.
	 *
	 * @return array<int, array{product_id:int,score:float,support:int}>
	 */
	public static function top_for_many( array $product_ids, string $kind, int $limit ): array {
		global $wpdb;

		$product_ids = Sanitize::id_list( $product_ids, 50 );
		$limit       = Sanitize::clamp( $limit, 1, 100 );

		if ( array() === $product_ids ) {
			return array();
		}

		$table = self::table();
		$in    = Woo::int_placeholders( count( $product_ids ) );

		$sql = "SELECT related_id, SUM( score ) AS score, MAX( support ) AS support
			FROM {$table}
			WHERE product_id IN ( {$in} )
				AND kind = %s
				AND related_id > 0
				AND related_id NOT IN ( {$in} )
			GROUP BY related_id
			ORDER BY score DESC, support DESC, related_id ASC
			LIMIT %d";

		$args = array_merge( $product_ids, array( $kind ), $product_ids, array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prefixed table name; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		return self::shape( $rows );
	}

	/**
	 * Normalise database rows into the shape engines expect.
	 *
	 * @param mixed $rows Result of get_results().
	 *
	 * @return array<int, array{product_id:int,score:float,support:int}>
	 */
	private static function shape( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$id = Sanitize::id( $row->related_id );

			if ( 0 === $id ) {
				continue;
			}

			$out[] = array(
				'product_id' => $id,
				'score'      => (float) $row->score,
				'support'    => (int) $row->support,
			);
		}

		return $out;
	}
}

