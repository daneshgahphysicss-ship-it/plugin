<?php
/**
 * Plugin Name:          Fast Woo Sell
 * Plugin URI:           https://example.com/fast-woo-sell
 * Description:          پیشنهاد هوشمند محصول، پیگیری سبد رها شده و پیش‌بینی موجودی برای ووکامرس. تمام پردازش‌ها محلی است و هیچ داده‌ای به بیرون ارسال نمی‌شود.
 * Version:              0.4.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Author:               Fast Woo Sell
 * Author URI:           https://example.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          fast-woo-sell
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * @package FWS
 */

/*
 * -----------------------------------------------------------------------------
 * COMPATIBILITY CONTRACT FOR THIS FILE
 * -----------------------------------------------------------------------------
 * This file and includes/Core/Requirements.php run *before* the PHP version
 * gate, therefore they must stay parseable by PHP 5.6. Any modern syntax here
 * turns a friendly admin notice into a white screen.
 *
 * Not allowed in these two files:
 *   - scalar type hints and return types
 *   - typed properties
 *   - null coalescing (??) and null coalescing assignment (??=)
 *   - arrow functions (fn), spread in calls, named arguments
 *   - match, enum, readonly, constructor property promotion
 *
 * Every other file in the plugin targets PHP 7.4 and may use 7.4 syntax.
 * -----------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Constants
 * -----------------------------------------------------------------------------
 */

if ( ! defined( 'FWS_VERSION' ) ) {
	define( 'FWS_VERSION', '0.4.0' );
}

if ( ! defined( 'FWS_DB_VERSION' ) ) {
	define( 'FWS_DB_VERSION', '1.0.0' );
}

if ( ! defined( 'FWS_FILE' ) ) {
	define( 'FWS_FILE', __FILE__ );
}

if ( ! defined( 'FWS_BASENAME' ) ) {
	define( 'FWS_BASENAME', plugin_basename( FWS_FILE ) );
}

if ( ! defined( 'FWS_PATH' ) ) {
	define( 'FWS_PATH', plugin_dir_path( FWS_FILE ) );
}

if ( ! defined( 'FWS_URL' ) ) {
	define( 'FWS_URL', plugin_dir_url( FWS_FILE ) );
}

if ( ! defined( 'FWS_MIN_PHP' ) ) {
	define( 'FWS_MIN_PHP', '7.4' );
}

if ( ! defined( 'FWS_MIN_WP' ) ) {
	define( 'FWS_MIN_WP', '6.2' );
}

if ( ! defined( 'FWS_MIN_WC' ) ) {
	define( 'FWS_MIN_WC', '8.0' );
}

if ( ! defined( 'FWS_RECOMMENDED_PHP' ) ) {
	define( 'FWS_RECOMMENDED_PHP', '8.1' );
}

/*
 * -----------------------------------------------------------------------------
 * Pre-gate includes
 * -----------------------------------------------------------------------------
 * Loaded with an explicit require, not through the autoloader, because the
 * requirement gate must work even when the rest of the plugin cannot be safely
 * loaded (unsupported PHP, WooCommerce missing).
 */

require_once FWS_PATH . 'includes/Core/Requirements.php';
require_once FWS_PATH . 'includes/Core/Compatibility.php';

/*
 * -----------------------------------------------------------------------------
 * Bootstrap
 * -----------------------------------------------------------------------------
 */

/**
 * Shared requirement gate instance.
 *
 * @return \FWS\Core\Requirements
 */
function fws_requirements() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new FWS\Core\Requirements(
			array(
				'min_php'         => FWS_MIN_PHP,
				'min_wp'          => FWS_MIN_WP,
				'min_wc'          => FWS_MIN_WC,
				'recommended_php' => FWS_RECOMMENDED_PHP,
			)
		);
	}

	return $instance;
}

/**
 * Load the translation files.
 *
 * Registered on `init` rather than `plugins_loaded` so that no translated
 * string is ever requested before WordPress is ready for it.
 *
 * @return void
 */
function fws_load_textdomain() {
	load_plugin_textdomain( 'fast-woo-sell', false, dirname( FWS_BASENAME ) . '/languages' );
}

/**
 * Register the class loader and the hooks that must exist before boot.
 *
 * Called only after the PHP version gate has passed, because every file it
 * loads targets PHP 7.4 and would be a parse error on an older server.
 *
 * @return void
 */
function fws_load() {
	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	$loaded = true;

	require_once FWS_PATH . 'includes/Core/Autoloader.php';

	FWS\Core\Autoloader::register( FWS_PATH . 'includes' );

	// Registered here rather than in Plugin::boot() because the activation
	// routine schedules events on these intervals before boot ever runs, and
	// wp_schedule_event() rejects a recurrence it does not know about.
	add_filter( 'cron_schedules', array( 'FWS\Core\Cron', 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Intervals are documented in FWS\Core\Cron.

	if ( is_multisite() ) {
		add_action( 'wp_initialize_site', array( 'FWS\Install\Installer', 'on_new_site' ), 20 );
	}
}

/**
 * Boot the plugin once all plugins are loaded.
 *
 * Runs at priority 20 so that WooCommerce has already registered its main
 * class, which makes the `class_exists( 'WooCommerce' )` probe reliable.
 *
 * @return void
 */
function fws_boot() {
	/*
	 * The class loader only needs an adequate PHP version, so it is registered
	 * before the full gate runs. That keeps the custom cron intervals defined
	 * even while WooCommerce is missing: without them WordPress cannot resolve
	 * the recurrence of an already scheduled event and silently drops it.
	 */
	if ( version_compare( PHP_VERSION, FWS_MIN_PHP, '>=' ) ) {
		fws_load();
	}

	if ( ! fws_requirements()->check( FWS\Core\Requirements::CONTEXT_RUNTIME ) ) {
		add_action( 'admin_notices', 'fws_render_requirement_notice' );
		add_action( 'network_admin_notices', 'fws_render_requirement_notice' );

		return;
	}

	FWS\Core\Plugin::instance()->boot();
}

/**
 * Print the blocking requirement notice in the admin.
 *
 * @return void
 */
function fws_render_requirement_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$details = fws_requirements()->get_failure_html();

	if ( '' === $details ) {
		return;
	}

	echo '<div class="notice notice-error">';
	echo '<p><strong>' . esc_html__( 'افزونه «فروش هوشمند» غیرفعال است.', 'fast-woo-sell' ) . '</strong></p>';
	echo wp_kses_post( $details );
	echo '</div>';
}

/**
 * Activation gate and installer.
 *
 * Only PHP and WordPress are enforced here because those cannot change during
 * the activation request. WooCommerce is deliberately *not* enforced: during a
 * bulk activation WooCommerce may not be loaded yet, and blocking on that would
 * produce a false failure. A missing or outdated WooCommerce is reported by the
 * runtime notice instead, and nothing is loaded until it is resolved.
 *
 * @param bool $network_wide Whether the plugin is being network activated.
 *
 * @return void
 */
function fws_on_activation( $network_wide = false ) {
	$requirements = fws_requirements();

	if ( ! $requirements->check( FWS\Core\Requirements::CONTEXT_ACTIVATION ) ) {
		wp_die(
			wp_kses_post( $requirements->get_failure_html() ),
			esc_html__( 'فروش هوشمند — پیش‌نیازها برآورده نشده است', 'fast-woo-sell' ),
			array(
				'back_link' => true,
				'response'  => 200,
			)
		);
	}

	fws_load();

	FWS\Install\Installer::activate( (bool) $network_wide );
}

/**
 * Deactivation handler.
 *
 * Stops scheduled work and nothing else. Data is only ever removed by
 * uninstall.php, and only when the shop opted in to that.
 *
 * @param bool $network_wide Whether the plugin is being network deactivated.
 *
 * @return void
 */
function fws_on_deactivation( $network_wide = false ) {
	// A server below the PHP floor could never have activated, so there is
	// nothing scheduled to clear and nothing safe to load.
	if ( version_compare( PHP_VERSION, FWS_MIN_PHP, '<' ) ) {
		return;
	}

	fws_load();

	FWS\Install\Installer::deactivate( (bool) $network_wide );
}

/*
 * -----------------------------------------------------------------------------
 * Hooks
 * -----------------------------------------------------------------------------
 */

// Declared at file scope so it is always registered before WooCommerce fires
// the action. This is what keeps the plugin listed as HPOS compatible.
add_action( 'before_woocommerce_init', array( 'FWS\Core\Compatibility', 'declare_all' ) );

add_action( 'init', 'fws_load_textdomain', 0 );
add_action( 'plugins_loaded', 'fws_boot', 20 );

register_activation_hook( FWS_FILE, 'fws_on_activation' );
register_deactivation_hook( FWS_FILE, 'fws_on_deactivation' );
