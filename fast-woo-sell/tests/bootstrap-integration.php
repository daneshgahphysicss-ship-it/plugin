<?php
/**
 * Integration bootstrap: loads the real WordPress test framework shipped in
 * wp-env, activates WooCommerce and this plugin, and installs WooCommerce's
 * test helpers (WC_Helper_Product, WC_Helper_Order, …).
 *
 * @package FWS
 */

$fws_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $fws_tests_dir ) {
	$fws_tests_dir = '/wordpress-phpunit'; // wp-env default.
}
if ( ! file_exists( $fws_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found at {$fws_tests_dir}. Run inside wp-env: npm run test:integration\n" );
	exit( 1 );
}

require_once $fws_tests_dir . '/includes/functions.php';
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require dirname( __DIR__ ) . '/fast-woo-sell.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		// WooCommerce installs its tables lazily; force it before any test runs.
		define( 'WP_UNINSTALL_PLUGIN', true );
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_roles();
		// Our own installer (tables, caps, state).
		\FWS\Install\Installer::install_site();
	}
);

require $fws_tests_dir . '/includes/bootstrap.php';

// WooCommerce test helpers.
$fws_wc_tests = WP_PLUGIN_DIR . '/woocommerce/tests/legacy/framework';
if ( is_dir( $fws_wc_tests ) ) {
	foreach ( array( 'class-wc-unit-test-case.php', 'helpers/class-wc-helper-product.php', 'helpers/class-wc-helper-order.php', 'helpers/class-wc-helper-customer.php' ) as $fws_file ) {
		if ( file_exists( "{$fws_wc_tests}/{$fws_file}" ) ) {
			require_once "{$fws_wc_tests}/{$fws_file}";
		}
	}
}
