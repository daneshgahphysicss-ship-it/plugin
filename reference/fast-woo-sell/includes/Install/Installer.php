<?php
/**
 * Activation and deactivation.
 *
 * @package FWS
 */

namespace FWS\Install;

use FWS\Core\Config;
use FWS\Core\Cron;
use FWS\Core\State;
use FWS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that happens when the plugin is switched on or off.
 *
 * Deactivation stops work but never deletes anything: a shop that deactivates
 * to debug a theme conflict must not lose ninety days of statistics. Deletion
 * only ever happens from uninstall.php, and only on request.
 */
final class Installer {

	/**
	 * Largest network we will walk synchronously during a network activation.
	 */
	const MAX_NETWORK_SITES = 500;

	/**
	 * Activation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 *
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			self::activate_network();

			return;
		}

		self::install_site();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network deactivated.
	 *
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$site_ids = self::network_site_ids();

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::uninstall_site_schedules();
				restore_current_blog();
			}

			return;
		}

		self::uninstall_site_schedules();
	}

	/**
	 * Install into a site created after the plugin was network activated.
	 *
	 * @param \WP_Site $site The new site.
	 *
	 * @return void
	 */
	public static function on_new_site( $site ): void {
		if ( ! is_multisite() || ! $site instanceof \WP_Site ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( FWS_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Create everything one site needs.
	 *
	 * @return void
	 */
	public static function install_site(): void {
		$errors = Migrator::install_tables();

		if ( ! empty( $errors ) ) {
			Logger::error( 'Activation could not create every table.', array( 'errors' => $errors ) );
		}

		Capabilities::install();
		Config::install();
		Cron::schedule_all();

		State::set( State::DB_VERSION, defined( 'FWS_DB_VERSION' ) ? FWS_DB_VERSION : '1.0.0' );
		State::cache_epoch();

		if ( false === State::get( State::ONBOARDED, false ) ) {
			// Recorded explicitly rather than left absent so the wizard can tell
			// a brand new install apart from an upgrade of an older version.
			State::set( State::ONBOARDED, 0 );
		}

		/**
		 * Fires at the end of a successful activation.
		 *
		 * @since 1.0.0
		 */
		do_action( 'fws/activated' );
	}

	/**
	 * Walk a network, installing into each site.
	 *
	 * @return void
	 */
	private static function activate_network(): void {
		$site_ids = self::network_site_ids();

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			self::install_site();
			restore_current_blog();
		}
	}

	/**
	 * Site ids on the network, capped so activation cannot time out.
	 *
	 * @return int[]
	 */
	private static function network_site_ids(): array {
		$site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => self::MAX_NETWORK_SITES,
				'update_site_meta_cache' => false,
			)
		);

		$site_ids = array_map( 'intval', (array) $site_ids );

		if ( count( $site_ids ) >= self::MAX_NETWORK_SITES ) {
			Logger::warning(
				'Network is larger than the synchronous activation cap; remaining sites install on their first admin request.',
				array( 'cap' => self::MAX_NETWORK_SITES )
			);
		}

		return $site_ids;
	}

	/**
	 * Stop scheduled work for one site, leaving all data in place.
	 *
	 * @return void
	 */
	private static function uninstall_site_schedules(): void {
		Cron::unschedule_all();

		/**
		 * Fires at the end of a deactivation, after cron is cleared.
		 *
		 * @since 1.0.0
		 */
		do_action( 'fws/deactivated' );
	}
}
