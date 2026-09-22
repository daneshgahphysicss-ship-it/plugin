<?php
/**
 * PHPUnit bootstrap for the standalone unit suite.
 *
 * The unit suite deliberately does not load WordPress. It stubs the handful of
 * WordPress functions the units under test touch, which keeps the suite fast
 * and runnable on any machine with PHP and Composer. Anything that genuinely
 * needs WordPress or WooCommerce belongs in tests/Integration instead.
 *
 * @package FWS
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! defined( 'FWS_FILE' ) ) {
	define( 'FWS_FILE', dirname( __DIR__ ) . '/fast-woo-sell.php' );
}

$GLOBALS['wp_version'] = '6.5';

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * -----------------------------------------------------------------------------
 * WordPress stubs
 * -----------------------------------------------------------------------------
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub for the WordPress translation function.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub for esc_html().
	 *
	 * @param string $text Text to escape.
	 *
	 * @return string
	 */
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub for esc_html__().
	 *
	 * @param string $text   Text to translate and escape.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		unset( $domain );

		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub for esc_url().
	 *
	 * @param string $url URL to escape.
	 *
	 * @return string
	 */
	function esc_url( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub for admin_url().
	 *
	 * @param string $path Path relative to wp-admin.
	 *
	 * @return string
	 */
	function admin_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub for add_query_arg(), array signature only.
	 *
	 * @param array  $args Query arguments.
	 * @param string $url  Base URL.
	 *
	 * @return string
	 */
	function add_query_arg( $args, $url = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		$separator = false === strpos( (string) $url, '?' ) ? '?' : '&';

		return $url . $separator . http_build_query( (array) $args );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub for current_user_can(). Controlled by $GLOBALS['fws_test_caps'].
	 *
	 * @param string $capability Capability to check.
	 *
	 * @return bool
	 */
	function current_user_can( $capability ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress stub.
		$caps = isset( $GLOBALS['fws_test_caps'] ) ? (array) $GLOBALS['fws_test_caps'] : array();

		return in_array( $capability, $caps, true );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Plugin classes under test
 * -----------------------------------------------------------------------------
 */

require_once dirname( __DIR__ ) . '/includes/Core/Requirements.php';
require_once dirname( __DIR__ ) . '/includes/Core/Compatibility.php';
