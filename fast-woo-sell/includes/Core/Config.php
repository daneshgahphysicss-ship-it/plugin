<?php
/**
 * Plugin settings.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single autoloaded settings option.
 *
 * Everything the plugin reads on a hot path lives here, in one autoloaded
 * option, so that reading a setting never costs a query. Anything large or
 * written frequently belongs in State instead, which is never autoloaded.
 */
final class Config {

	/**
	 * Option name holding every setting.
	 */
	const OPTION = 'fws_settings';

	/**
	 * In memory copy of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default value for every setting.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$defaults = array(

			// Master switches.
			'recommendations_enabled'         => true,
			'carts_enabled'                   => true,
			'forecast_enabled'                => true,
			'shadow_mode'                     => false,

			// Privacy and retention.
			'respect_dnt'                     => true,
			'contact_consent_required'        => false,
			'event_retention_days'            => 90,
			'cart_retention_days'             => 90,
			'stats_retention_days'            => 730,
			'delete_data_on_uninstall'        => false,

			// Tracking.
			'track_logged_out'                => true,
			'session_view_cap'                => 20,
			'session_bot_threshold'           => 50,
			'events_per_request_cap'          => 20,
			'tracking_rate_limit_per_minute'  => 60,
			'tracking_require_nonce'          => false,

			// Recommendations.
			'default_limit'                   => 4,
			'min_results'                     => 2,
			'over_fetch'                      => 3,
			'max_per_category'                => 2,
			'exclude_out_of_stock'            => true,
			'exclude_cart_items'              => true,
			'exclude_purchased'               => false,
			'show_social_proof'               => true,
			'lazy_render'                     => false,
			'cache_ttl'                       => 43200,
			'attribution_window_days'         => 7,

			// Affinity rebuild.
			'affinity_window_days'            => 180,
			'affinity_min_support'            => 2,
			'affinity_single_query_max_rows'  => 200000,
			'affinity_batched_max_rows'       => 2000000,
			'affinity_batch_size'             => 500,

			// Abandoned carts.
			'cart_abandon_minutes'            => 60,
			'cart_abandon_minutes_anonymous'  => 1440,
			'cart_recovery_window_hours'      => 72,
			'cart_max_attempts'               => 3,
			'recovery_link_ttl_hours'         => 72,
			'recovery_coupon_enabled'         => false,

			// Stock forecast.
			'forecast_window_days'            => 30,
			'forecast_lead_time_days'         => 7,
			'forecast_critical_days'          => 5,

			// Experiments.
			'experiment_min_samples'          => 200,
			'experiment_min_conversions'      => 25,
			'experiment_min_days'             => 7,
			'experiment_primary_metric'       => 'revenue_per_impression',

			// Diagnostics.
			'debug_logging'                   => false,
		);

		/**
		 * Filter the default value of every setting.
		 *
		 * @since 1.0.0
		 *
		 * @param array $defaults Setting key to default value.
		 */
		return (array) apply_filters( 'fws/config/defaults', $defaults );
	}

	/**
	 * Every setting, stored values merged over the defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Unknown keys from an older version are dropped rather than surfaced.
		$defaults    = self::defaults();
		$stored      = array_intersect_key( $stored, $defaults );
		self::$cache = array_merge( $defaults, $stored );

		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value to return for an unknown key.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$settings = self::all();

		if ( ! array_key_exists( $key, $settings ) ) {
			return $fallback;
		}

		/**
		 * Filter a single resolved setting.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $value Resolved value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'fws/config/value', $settings[ $key ], $key );
	}

	/**
	 * Read a setting as a boolean.
	 *
	 * @param string $key Setting key.
	 *
	 * @return bool
	 */
	public static function bool( string $key ): bool {
		return filter_var( self::get( $key, false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Read a setting as a bounded integer.
	 *
	 * @param string   $key Setting key.
	 * @param int      $min Lowest allowed value.
	 * @param int|null $max Highest allowed value, or null for unbounded.
	 *
	 * @return int
	 */
	public static function int( string $key, int $min = 0, ?int $max = null ): int {
		$value = (int) self::get( $key, $min );

		if ( $value < $min ) {
			$value = $min;
		}

		if ( null !== $max && $value > $max ) {
			$value = $max;
		}

		return $value;
	}

	/**
	 * Read a setting as a trimmed string.
	 *
	 * @param string $key Setting key.
	 *
	 * @return string
	 */
	public static function string( string $key ): string {
		return trim( (string) self::get( $key, '' ) );
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 *
	 * @return bool True when the option was written.
	 */
	public static function set( string $key, $value ): bool {
		return self::update( array( $key => $value ) );
	}

	/**
	 * Write several settings at once.
	 *
	 * Unknown keys are ignored so a malformed request cannot grow the option.
	 *
	 * @param array $values Setting key to new value.
	 *
	 * @return bool True when the option was written.
	 */
	public static function update( array $values ): bool {
		$defaults = self::defaults();
		$values   = array_intersect_key( $values, $defaults );

		if ( empty( $values ) ) {
			return false;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( $stored, $values );
		$merged = array_intersect_key( $merged, $defaults );

		self::$cache = null;

		$written = update_option( self::OPTION, $merged, true );

		if ( $written ) {
			/**
			 * Fires after settings are written.
			 *
			 * @since 1.0.0
			 *
			 * @param array $values Keys that were written.
			 */
			do_action( 'fws/config/updated', $values );
		}

		return $written;
	}

	/**
	 * Seed the option on activation without overwriting existing choices.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
		}

		self::$cache = null;
	}

	/**
	 * Drop the in memory copy.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}
}
