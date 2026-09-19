<?php
/**
 * Event table access.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Every query against the event table lives here.
 *
 * Keeping the SQL in one class is what makes the write path auditable: there is
 * exactly one place that can insert a row, and it accepts only the columns in
 * the whitelist below. A caller cannot smuggle an unexpected column in through
 * an array key.
 */
final class EventRepository {

	/**
	 * Column name to printf format.
	 *
	 * @return array<string, string>
	 */
	public static function columns(): array {
		return array(
			'event_type'   => '%s',
			'visitor_id'   => '%s',
			'session_id'   => '%s',
			'user_id'      => '%d',
			'object_id'    => '%d',
			'variation_id' => '%d',
			'order_id'     => '%d',
			'placement_id' => '%d',
			'engine'       => '%s',
			'variant'      => '%s',
			'surface'      => '%s',
			'device'       => '%s',
			'quantity'     => '%d',
			'value'        => '%s',
			'currency'     => '%s',
			'meta'         => '%s',
			'created_at'   => '%s',
		);
	}

	/**
	 * The prefixed table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		return Schema::table( Schema::EVENTS );
	}

	/**
	 * Insert one row.
	 *
	 * @param array $row Column name to value.
	 *
	 * @return int Inserted id, or zero on failure.
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		$columns = self::columns();
		$row     = array_intersect_key( $row, $columns );

		if ( empty( $row ) ) {
			return 0;
		}

		$formats = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = $columns[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no core API.
		$ok = $wpdb->insert( self::table(), $row, $formats );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Insert several rows in a single statement.
	 *
	 * A batched insert matters here because the tracking endpoint can carry a
	 * page worth of impressions; twenty separate INSERTs would mean twenty round
	 * trips and twenty index updates inside one request.
	 *
	 * @param array<int, array> $rows List of column name to value maps.
	 *
	 * @return int Number of rows written.
	 */
	public static function insert_many( array $rows ): int {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		if ( 1 === count( $rows ) ) {
			return self::insert( reset( $rows ) ) > 0 ? 1 : 0;
		}

		$columns = self::columns();
		$names   = array_keys( $columns );
		$chunks  = array();
		$values  = array();

		foreach ( $rows as $row ) {
			$placeholders = array();

			foreach ( $names as $column ) {
				$value = array_key_exists( $column, $row ) ? $row[ $column ] : null;

				if ( null === $value ) {
					// A bare NULL keyword, never interpolated data.
					$placeholders[] = 'NULL';
					continue;
				}

				$placeholders[] = $columns[ $column ];
				$values[]       = $value;
			}

			$chunks[] = '(' . implode( ', ', $placeholders ) . ')';
		}

		$sql = 'INSERT INTO ' . self::table()
			. ' (`' . implode( '`, `', $names ) . '`) VALUES '
			. implode( ', ', $chunks );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column names come from the whitelist above; every value is a placeholder.
		$prepared = $wpdb->prepare( $sql, $values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$written = $wpdb->query( $prepared );

		return false === $written ? 0 : (int) $written;
	}

	/**
	 * How many events of one type a session has already produced.
	 *
	 * Used to cap a single session's contribution so one visitor reloading a
	 * page a thousand times cannot dominate the popularity signal.
	 *
	 * @param string $session_id Session identifier.
	 * @param string $type       Event type.
	 *
	 * @return int
	 */
	public static function count_in_session( string $session_id, string $type ): int {
		global $wpdb;

		if ( '' === $session_id ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND event_type = %s",
				$session_id,
				$type
			)
		);
	}

	/**
	 * A visitor's recent clicks on recommendations, newest first.
	 *
	 * @param string $visitor_id Visitor identifier.
	 * @param string $since      MySQL datetime lower bound.
	 * @param int    $limit      Largest number of rows to return.
	 *
	 * @return array<int, object>
	 */
	public static function recent_clicks( string $visitor_id, string $since, int $limit = 100 ): array {
		global $wpdb;

		if ( '' === $visitor_id ) {
			return array();
		}

		$table = self::table();
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, variation_id, placement_id, engine, variant, created_at
				FROM {$table}
				WHERE visitor_id = %s AND event_type = %s AND created_at >= %s
				ORDER BY created_at DESC
				LIMIT %d",
				$visitor_id,
				EventType::CLICK,
				$since,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a type was already recorded for an order.
	 *
	 * Order paid can be reached from several WooCommerce transitions and from a
	 * gateway callback that retries, so the write path has to be idempotent.
	 *
	 * @param int    $order_id Order id.
	 * @param string $type     Event type.
	 *
	 * @return bool
	 */
	public static function order_has( int $order_id, string $type ): bool {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return false;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE order_id = %d AND event_type = %s LIMIT 1",
				$order_id,
				$type
			)
		);

		return null !== $found;
	}

	/**
	 * Delete a bounded number of rows older than a cut off.
	 *
	 * Bounded so the retention sweep can run inside a cron time budget instead
	 * of locking the table with one enormous DELETE.
	 *
	 * @param string $before MySQL datetime cut off.
	 * @param int    $limit  Largest number of rows to remove.
	 *
	 * @return int Rows removed.
	 */
	public static function delete_before( string $before, int $limit = 1000 ): int {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 10000, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
				$before,
				$limit
			)
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Total row count, for the diagnostics screen.
	 *
	 * @return int
	 */
	public static function total(): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Erase every row belonging to one visitor, for a privacy request.
	 *
	 * @param string $visitor_id Visitor identifier.
	 *
	 * @return int Rows removed.
	 */
	public static function delete_visitor( string $visitor_id ): int {
		global $wpdb;

		if ( '' === $visitor_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no core API.
		$removed = $wpdb->delete( self::table(), array( 'visitor_id' => $visitor_id ), array( '%s' ) );

		return false === $removed ? 0 : (int) $removed;
	}
}
