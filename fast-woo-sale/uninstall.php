<?php
/**
 * Uninstall Fast Woo Predictive Purchase
 * پاکسازی امن داده‌های موقت، ترنزینت‌ها و کرون‌های زمان‌بندی‌شده هنگام حذف افزونه
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear scheduled cron tasks (BUG-12 fix v2.8.1: the weekly cleanup event was left orphaned)
wp_clear_scheduled_hook( 'fws_daily_database_mining_event' );
wp_clear_scheduled_hook( 'fws_weekly_db_cleanup_event' );
delete_transient( 'fws_mining_lock' );

// Clean up option configurations
delete_option( 'fws_prediction_settings' );
delete_option( 'fws_cache_version' );
delete_option( 'fws_analytics_missing' );

// Clear transient caches from options table
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_fws_%' OR option_name LIKE '%_transient_timeout_fws_%'" );

// Note: The affinity table {$wpdb->prefix}fws_product_affinity_cache is intentionally preserved
// to prevent catastrophic data loss if the plugin is temporarily deactivated or re-installed.
