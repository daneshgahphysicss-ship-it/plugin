<?php
/**
 * Uninstall routine.
 *
 * Deliberately self contained: no plugin class is loaded and no syntax newer
 * than PHP 5.6 is used, because WordPress runs this file even when the plugin
 * itself could never boot on this server. A fatal error here would leave the
 * site unable to finish removing the plugin.
 *
 * Nothing is deleted unless the shop explicitly turned on
 * "delete data on uninstall". Capabilities and cron entries are always removed,
 * because both are meaningless without the plugin and both are recreated on a
 * future activation.
 *
 * @package FWS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's footprint from the current site.
 *
 * @return void
 */
function fws_uninstall_site() {
	global $wpdb;

	// -------------------------------------------------------------------------
	// Always: scheduled jobs.
	// -------------------------------------------------------------------------
	$hooks = array(
		'fws_rollup_stats',
		'fws_rebuild_affinity',
		'fws_scan_carts',
		'fws_process_queue',
		'fws_build_forecast',
		'fws_cleanup',
	);

	foreach ( $hooks as $hook ) {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( $hook );
		} else {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// Always: capabilities.
	// -------------------------------------------------------------------------
	$caps = array(
		'fws_view_reports',
		'fws_manage_settings',
		'fws_manage_carts',
		'fws_export_data',
	);

	$roles = wp_roles();

	foreach ( array_keys( $roles->roles ) as $role_name ) {
		$role = get_role( $role_name );

		if ( null === $role ) {
			continue;
		}

		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	// -------------------------------------------------------------------------
	// Opt in only: everything else.
	// -------------------------------------------------------------------------
	$settings = get_option( 'fws_settings', array() );
	$opted_in = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	if ( ! $opted_in ) {
		return;
	}

	// Tables are discovered by prefix rather than listed, so a table added in a
	// later version can never be orphaned by an out of date list here.
	$like   = $wpdb->esc_like( $wpdb->prefix . 'fws_' ) . '%';
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if ( is_array( $tables ) ) {
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterised; the list comes from SHOW TABLES.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
		}
	}

	// Options, transients and their timeouts.
	$patterns = array(
		$wpdb->esc_like( 'fws_' ) . '%',
		$wpdb->esc_like( '_transient_fws_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_fws_' ) . '%',
		$wpdb->esc_like( '_site_transient_fws_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_fws_' ) . '%',
	);

	foreach ( $patterns as $pattern ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
	}

	// Per user screen preferences.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'fws_' ) . '%' ) );

	// A persistent object cache still holds the option values we just deleted.
	wp_cache_flush();
}

if ( is_multisite() ) {
	$fws_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( (array) $fws_site_ids as $fws_site_id ) {
		switch_to_blog( (int) $fws_site_id );
		fws_uninstall_site();
		restore_current_blog();
	}

	delete_site_option( 'fws_network_settings' );
} else {
	fws_uninstall_site();
}
