<?php
/**
 * WooCommerce and database helpers shared across modules.
 *
 * @package FWS
 */

namespace FWS\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Small facts about the store that more than one module needs.
 *
 * These live here rather than being repeated privately because getting the paid
 * status prefix wrong silently produces zero revenue everywhere, and a bug like
 * that should only ever have one place to hide.
 */
final class Woo {

	/**
	 * Order statuses that count as a sale, prefixed as the analytics tables store them.
	 *
	 * `wc_get_is_paid_statuses()` returns bare slugs such as `completed`, while
	 * `wc_order_stats.status` stores `wc-completed`. Comparing the two without
	 * normalising matches nothing at all.
	 *
	 * @return string[]
	 */
	public static function paid_statuses(): array {
		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? wc_get_is_paid_statuses()
			: array( 'processing', 'completed' );

		if ( ! is_array( $statuses ) || array() === $statuses ) {
			$statuses = array( 'processing', 'completed' );
		}

		$prefixed = array_map(
			static function ( $status ) {
				$status = (string) $status;

				return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
			},
			$statuses
		);

		return array_values( array_unique( $prefixed ) );
	}

	/**
	 * Whether a table is present, memoised for the rest of the request.
	 *
	 * @param string $table Fully prefixed table name.
	 *
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		static $known = array();

		if ( isset( $known[ $table ] ) ) {
			return $known[ $table ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		$known[ $table ] = ( (string) $found === $table );

		return $known[ $table ];
	}

	/**
	 * A comma separated run of `%s` placeholders.
	 *
	 * @param int $count How many.
	 *
	 * @return string
	 */
	public static function string_placeholders( int $count ): string {
		return implode( ', ', array_fill( 0, max( 1, $count ), '%s' ) );
	}

	/**
	 * A comma separated run of `%d` placeholders.
	 *
	 * @param int $count How many.
	 *
	 * @return string
	 */
	public static function int_placeholders( int $count ): string {
		return implode( ', ', array_fill( 0, max( 1, $count ), '%d' ) );
	}
}
