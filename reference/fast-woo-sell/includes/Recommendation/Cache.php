<?php
/**
 * Recommendation cache.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Core\Config;
use FWS\Core\State;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Caches finished recommendation lists.
 *
 * Two decisions shape this class.
 *
 * Invalidation is by epoch, not by deletion. Every key carries the current cache
 * epoch, and a rebuild bumps the epoch instead of hunting down entries to delete.
 * Old entries become unreachable and expire on their own. There is no way to
 * leave one behind, which matters because the alternative on a large catalogue is
 * a delete loop over tens of thousands of option rows.
 *
 * Personal results are never stored. A cache entry keyed loosely enough to be
 * shared is an entry that can show one shopper another shopper's history, and no
 * cache hit rate is worth that.
 */
final class Cache {

	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'fws_rec_';

	/**
	 * A cached list, if one is available.
	 *
	 * @param string $key Cache key from `key()`.
	 *
	 * @return int[]|null Null on a miss.
	 */
	public static function get( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}

		$found = get_transient( $key );

		if ( ! is_array( $found ) ) {
			return null;
		}

		// An empty array is a real answer worth caching: it stops a shop with no
		// data re-running eight engines on every page view. It is stored as a
		// sentinel because get_transient() cannot distinguish an empty array from
		// a miss.
		if ( isset( $found['empty'] ) ) {
			return array();
		}

		if ( ! isset( $found['ids'] ) || ! is_array( $found['ids'] ) ) {
			return null;
		}

		return Sanitize::id_list( $found['ids'], 100 );
	}

	/**
	 * Store a list.
	 *
	 * @param string $key Cache key from `key()`.
	 * @param int[]  $ids Products to store.
	 *
	 * @return void
	 */
	public static function set( string $key, array $ids ): void {
		if ( '' === $key ) {
			return;
		}

		$ttl = Config::int( 'cache_ttl', 0, WEEK_IN_SECONDS );

		if ( 0 === $ttl ) {
			return;
		}

		$payload = array() === $ids
			? array( 'empty' => 1 )
			: array( 'ids' => Sanitize::id_list( $ids, 100 ) );

		set_transient( $key, $payload, $ttl );
	}

	/**
	 * Build a cache key.
	 *
	 * @param string  $engine  Engine id, or an empty string for a chain result.
	 * @param Context $context Request context.
	 * @param int     $limit   Requested length.
	 *
	 * @return string Empty when the result must not be cached.
	 */
	public static function key( string $engine, Context $context, int $limit ): string {
		if ( 0 === Config::int( 'cache_ttl', 0, WEEK_IN_SECONDS ) ) {
			return '';
		}

		$parts = implode(
			'|',
			array(
				State::cache_epoch(),
				$engine,
				$context->fingerprint(),
				$limit,
				// Settings that change which products survive filtering are part
				// of the key, so toggling one takes effect immediately instead of
				// twelve hours later.
				Config::bool( 'exclude_out_of_stock' ) ? 1 : 0,
				Config::bool( 'exclude_cart_items' ) ? 1 : 0,
				Config::int( 'max_per_category', 0, 50 ),
				// The cart page's own contents, because excluding cart items makes
				// the result depend on them.
				Config::bool( 'exclude_cart_items' ) ? self::cart_signature() : '',
				is_rtl() ? 'rtl' : 'ltr',
			)
		);

		// Transient names are limited to 172 characters and this one is derived
		// from an unbounded fingerprint, so it is hashed rather than truncated.
		return self::PREFIX . md5( $parts );
	}

	/**
	 * Whether a result may be cached at all.
	 *
	 * @param Engine $engine Engine that produced it.
	 *
	 * @return bool
	 */
	public static function is_cacheable( Engine $engine ): bool {
		return ! $engine->is_personal();
	}

	/**
	 * A short string that changes when the cart changes.
	 *
	 * @return string
	 */
	private static function cart_signature(): string {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return '';
		}

		$hash = WC()->cart->get_cart_hash();

		return is_string( $hash ) ? substr( $hash, 0, 12 ) : '';
	}
}
