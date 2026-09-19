<?php
/**
 * Unit bootstrap: no WordPress. Only pure-PHP logic is unit tested here;
 * anything touching $wpdb or WC runs in the integration suite (step 3).
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
