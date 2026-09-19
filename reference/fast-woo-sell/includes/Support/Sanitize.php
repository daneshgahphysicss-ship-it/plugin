<?php
/**
 * Input sanitisation helpers.
 *
 * @package FWS
 */

namespace FWS\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow, reusable coercions for values crossing a trust boundary.
 *
 * Every one of these returns a safe default rather than throwing, because the
 * write paths that use them must never turn a malformed value into a fatal on
 * a live storefront.
 */
final class Sanitize {

	/**
	 * A non negative integer id.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int
	 */
	public static function id( $value ): int {
		$value = is_scalar( $value ) ? (int) $value : 0;

		return $value > 0 ? $value : 0;
	}

	/**
	 * A list of unique positive ids.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Largest number of ids to keep.
	 *
	 * @return int[]
	 */
	public static function id_list( $value, int $max = 100 ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			$id = self::id( $item );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}

			if ( count( $ids ) >= $max ) {
				break;
			}
		}

		return array_values( $ids );
	}

	/**
	 * A lowercase slug of `[a-z0-9_-]`, truncated to a maximum length.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Maximum length.
	 *
	 * @return string
	 */
	public static function slug( $value, int $length = 32 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9_\-]/', '', $value );

		return substr( (string) $value, 0, $length );
	}

	/**
	 * A lowercase hexadecimal token of an exact length, or an empty string.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Required length.
	 *
	 * @return string
	 */
	public static function hex( $value, int $length = 32 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( (string) $value );

		return preg_match( '/^[a-f0-9]{' . $length . '}$/', $value ) ? $value : '';
	}

	/**
	 * A bounded integer.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Lowest allowed value.
	 * @param int   $max   Highest allowed value.
	 *
	 * @return int
	 */
	public static function clamp( $value, int $min, int $max ): int {
		$value = is_scalar( $value ) ? (int) $value : $min;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * A monetary amount as a fixed point string.
	 *
	 * Returned as a string, not a float, so the value handed to the database is
	 * never subject to binary rounding on the way in.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $decimals Decimal places to keep.
	 *
	 * @return string
	 */
	public static function money( $value, int $decimals = 8 ): string {
		if ( ! is_scalar( $value ) ) {
			return '0';
		}

		$value = (float) str_replace( ',', '.', (string) $value );

		if ( ! is_finite( $value ) ) {
			return '0';
		}

		return number_format( $value, $decimals, '.', '' );
	}

	/**
	 * A three letter uppercase currency code.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public static function currency( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );

		return 3 === strlen( $value ) ? $value : '';
	}

	/**
	 * A MySQL datetime string in site time.
	 *
	 * @param int|null $timestamp Unix timestamp, or null for now.
	 *
	 * @return string
	 */
	public static function datetime( ?int $timestamp = null ): string {
		if ( null === $timestamp ) {
			return current_time( 'mysql' );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp + (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
	}

	/**
	 * Now, shifted into the site's timezone.
	 *
	 * Returned as a Unix timestamp so it can be used for date arithmetic and
	 * then formatted with gmdate(), which is the pairing that keeps every stored
	 * date in this plugin in site time and none of them double shifted.
	 *
	 * @return int
	 */
	public static function site_time(): int {
		return time() + (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}

	/**
	 * A site time date as Y-m-d, offset by a number of days.
	 *
	 * @param int $days_ago Days to subtract.
	 *
	 * @return string
	 */
	public static function date( int $days_ago = 0 ): string {
		return gmdate( 'Y-m-d', self::site_time() - ( $days_ago * DAY_IN_SECONDS ) );
	}

	/**
	 * A JSON string of bounded length, or null when there is nothing to store.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum encoded length.
	 *
	 * @return string|null
	 */
	public static function json( $value, int $limit = 2000 ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$encoded = wp_json_encode( $value );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return null;
		}

		// Truncating JSON would produce an unparseable value, so an oversized
		// payload is dropped entirely rather than corrupted.
		return strlen( $encoded ) > $limit ? null : $encoded;
	}
}
