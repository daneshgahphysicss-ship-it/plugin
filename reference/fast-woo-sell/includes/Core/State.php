<?php
/**
 * Internal runtime state.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores values the plugin writes about itself rather than values a shop
 * manager chooses.
 *
 * Every key is a separate option with autoload disabled, because these are
 * written often and read rarely. Cron timestamps, rebuild cursors, the schema
 * version and the cache epoch all belong here, never in Config.
 */
final class State {

	/**
	 * Prefix applied to every option name.
	 */
	const PREFIX = 'fws_state_';

	/**
	 * Schema version the installed tables are at.
	 */
	const DB_VERSION = 'db_version';

	/**
	 * Counter bumped to invalidate every recommendation cache entry at once.
	 */
	const CACHE_EPOCH = 'cache_epoch';

	/**
	 * Timestamp of the last completed affinity rebuild.
	 */
	const AFFINITY_BUILT_AT = 'affinity_built_at';

	/**
	 * Cursor for a partially completed affinity rebuild.
	 */
	const AFFINITY_CURSOR = 'affinity_cursor';

	/**
	 * Timestamp of the last completed statistics rollup.
	 */
	const ROLLUP_RAN_AT = 'rollup_ran_at';

	/**
	 * Timestamp of the last completed retention sweep.
	 */
	const CLEANUP_RAN_AT = 'cleanup_ran_at';

	/**
	 * Whether the setup wizard has been completed or dismissed.
	 */
	const ONBOARDED = 'onboarded';

	/**
	 * Read a value.
	 *
	 * @param string $key      State key, without the prefix.
	 * @param mixed  $fallback Value to return when unset.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		return get_option( self::PREFIX . $key, $fallback );
	}

	/**
	 * Read a value as an integer.
	 *
	 * @param string $key      State key, without the prefix.
	 * @param int    $fallback Value to return when unset.
	 *
	 * @return int
	 */
	public static function int( string $key, int $fallback = 0 ): int {
		return (int) self::get( $key, $fallback );
	}

	/**
	 * Write a value with autoload disabled.
	 *
	 * update_option() forwards the autoload flag to add_option() when the option
	 * does not exist yet, so one call covers both create and update without an
	 * extra read.
	 *
	 * @param string $key   State key, without the prefix.
	 * @param mixed  $value Value to store.
	 *
	 * @return bool
	 */
	public static function set( string $key, $value ): bool {
		return update_option( self::PREFIX . $key, $value, false );
	}

	/**
	 * Delete a value.
	 *
	 * @param string $key State key, without the prefix.
	 *
	 * @return bool
	 */
	public static function delete( string $key ): bool {
		return delete_option( self::PREFIX . $key );
	}

	/**
	 * Current cache epoch, seeded on first read.
	 *
	 * @return int
	 */
	public static function cache_epoch(): int {
		$epoch = self::int( self::CACHE_EPOCH );

		if ( $epoch < 1 ) {
			$epoch = 1;
			self::set( self::CACHE_EPOCH, $epoch );
		}

		return $epoch;
	}

	/**
	 * Invalidate every cached recommendation set.
	 *
	 * Bumping a counter is used instead of deleting keys because transients may
	 * live in an external object cache with no way to enumerate them.
	 *
	 * @return int The new epoch.
	 */
	public static function bump_cache_epoch(): int {
		$epoch = self::cache_epoch() + 1;

		self::set( self::CACHE_EPOCH, $epoch );

		/**
		 * Fires after every cached recommendation set is invalidated.
		 *
		 * @since 1.0.0
		 *
		 * @param int $epoch The new epoch.
		 */
		do_action( 'fws/cache/flushed', $epoch );

		return $epoch;
	}

	/**
	 * Every option name this class owns, for uninstall.
	 *
	 * @return string[]
	 */
	public static function option_names(): array {
		$keys = array(
			self::DB_VERSION,
			self::CACHE_EPOCH,
			self::AFFINITY_BUILT_AT,
			self::AFFINITY_CURSOR,
			self::ROLLUP_RAN_AT,
			self::CLEANUP_RAN_AT,
			self::ONBOARDED,
		);

		return array_map(
			static function ( $key ) {
				return self::PREFIX . $key;
			},
			$keys
		);
	}
}
