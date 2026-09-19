<?php
/**
 * Plugin Name: FWS Dev Helpers (mu-plugin, wp-env only)
 * Description: Loads the Fast Woo Sell WP-CLI dev commands. Never shipped.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$fws_cli = WP_PLUGIN_DIR . '/fast-woo-sell/tools/cli/class-fws-dev-command.php';
	if ( is_readable( $fws_cli ) ) {
		require_once $fws_cli;
	}
}
