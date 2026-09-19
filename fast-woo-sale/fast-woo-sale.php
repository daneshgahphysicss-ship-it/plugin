<?php
/**
 * Plugin Name: Fast Woo Predictive Purchase | سیستم هوشمند پیش‌بینی و پیشنهاد خرید ووکامرس
 * Plugin URI:  https://github.com/fast-woo-sale/predictive-purchase
 * Description: موتور تحلیل پیشرفته دیتابیس سفارشات ووکامرس، استخراج سبدهای پرتکرار (Market Basket Analysis)، پیش‌بینی خرید بعدی و ارائه پیشنهادات هوشمند کالا.
 * Version:     2.8.0
 * Author:      تیم توسعه هوش تجاری ووکامرس
 * Author URI:  https://example.com
 * Text Domain: fast-woo-sale
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.3
 *
 * License:     GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Safe check for WooCommerce dependency (compatible with alphabetical plugin loading & multisite)
function fws_check_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
    }
    return in_array('woocommerce/woocommerce.php', $active_plugins, true) || class_exists('WooCommerce');
}

add_action('admin_notices', function() {
    if (!fws_check_woocommerce_active()) {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error is-dismissible"><p><strong>سیستم هوشمند پیش‌بینی خرید (Fast Woo Predictive):</strong> برای اجرای این سیستم، نصب و فعال‌سازی افزونه WooCommerce الزامی است.</p></div>';
    }
});

// Define Constants
// FWS_BUNDLE_DISCOUNT و FWS_MIN_CONFIDENCE برای سازگاری قبلی حفظ شده‌اند؛
// مقادیر واقعی از پنل تنظیمات (FWS_Settings) خوانده می‌شوند.
define('FWS_VERSION', '2.8.0');
define('FWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FWS_MIN_CONFIDENCE', 60);
define('FWS_BUNDLE_DISCOUNT', 12);

// Declare HPOS & Blocks Compatibility (High-Performance Order Storage & Cart/Checkout Blocks)
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Apply bundle discount directly into WooCommerce cart calculations
// نسخه ۲.۷ — رفع باگ تخفیف ترکیبی (Compounding Discount):
// مبنای محاسبه باید همیشه «قیمت پایه ثبت‌شده هنگام افزودن به سبد» باشد؛ استفاده از
// min(regular, current) باعث می‌شد با هر بازمحاسبه سبد، تخفیف روی قیمتِ تخفیف‌خوردهٔ قبل
// دوباره اعمال شود (۱۰۰ → ۸۸ → ۷۷.۴۴ → … → نزدیک صفر).
add_action('woocommerce_add_cart_item_data', function($cart_item_data, $product_id, $variation_id) {
    if (!function_exists('wc_get_product')) {
        return $cart_item_data;
    }
    $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
    if (!$product) {
        return $cart_item_data;
    }
    $regular = (float) $product->get_regular_price();
    $current = (float) $product->get_price();
    $base    = ($regular > 0 && $current > 0) ? min($regular, $current) : max($regular, $current);
    if ($base > 0) {
        $cart_item_data['fws_base_price'] = $base;
    }
    return $cart_item_data;
}, 10, 3);

add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!function_exists('WC') || !WC()->session || !is_a($cart, 'WC_Cart')) return;

    $bundle_items = WC()->session->get('fws_bundle_items');
    if (!empty($bundle_items) && is_array($bundle_items)) {
        $discount_percentage = (int) FWS_Settings::get('bundle_discount', defined('FWS_BUNDLE_DISCOUNT') ? FWS_BUNDLE_DISCOUNT : 12);
        $factor = (100 - max(0, min(90, $discount_percentage))) / 100;

        foreach ($cart->get_cart() as $cart_item) {
            $pid = absint($cart_item['product_id']);
            $vid = !empty($cart_item['variation_id']) ? absint($cart_item['variation_id']) : 0;
            $is_bundle_item = in_array($pid, $bundle_items, true) || ($vid > 0 && in_array($vid, $bundle_items, true));

            if ($is_bundle_item && isset($cart_item['data']) && is_object($cart_item['data'])) {
                // مبنای idempotent: قیمت پایهٔ ثبت‌شده هنگام افزودن؛ برای اقلام قدیمی سشن (قبل از ارتقا)
                // قیمت اصلی (regular) ملاک است تا هیچ‌گاه تخفیف به‌صورت تراکمی تکرار نشود.
                $base_price = !empty($cart_item['fws_base_price'])
                    ? (float) $cart_item['fws_base_price']
                    : (float) $cart_item['data']->get_regular_price();

                if ($base_price > 0) {
                    $discounted = round($base_price * $factor, wc_get_price_decimals());
                    $cart_item['data']->set_price($discounted);
                }
            }
        }
    }
}, 20, 1);

// Keep bundle session items synchronized if user removes items or empties cart
add_action('woocommerce_cart_item_removed', function($cart_item_key, $cart) {
    if (WC()->session) {
        $bundle_items = (array) WC()->session->get('fws_bundle_items', array());
        if (!empty($bundle_items)) {
            $current_cart_pids = array();
            foreach ($cart->get_cart() as $item) {
                $current_cart_pids[] = absint($item['product_id']);
                if (!empty($item['variation_id'])) {
                    $current_cart_pids[] = absint($item['variation_id']);
                }
            }
            $updated_bundle = array_values(array_intersect($bundle_items, $current_cart_pids));
            WC()->session->set('fws_bundle_items', $updated_bundle);
        }
    }
}, 10, 2);

add_action('woocommerce_cart_emptied', function() {
    if (WC()->session) {
        WC()->session->__unset('fws_bundle_items');
    }
});

// Load Core Modules (Settings must load first — all modules depend on it)
require_once FWS_PLUGIN_DIR . 'includes/class-fws-settings.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-style-manager.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-database-miner.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-prediction-engine.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-display-hooks.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-ajax-handler.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-admin-analytics.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-admin-page.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-performance-optimizer.php';

// Plugin Activation: Create High-Performance Cache Index Table
register_activation_hook(__FILE__, array('FWS_Database_Miner', 'create_tables_and_schedule'));
register_deactivation_hook(__FILE__, array('FWS_Database_Miner', 'clear_scheduled_events'));

// Initialize Engine
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    FWS_Database_Miner::init();
    FWS_Prediction_Engine::get_instance();
    FWS_Style_Manager::get_instance();
    FWS_Display_Hooks::get_instance();
    FWS_Ajax_Handler::get_instance();
    FWS_Performance_Optimizer::init();
    if (is_admin()) {
        FWS_Admin_Page::get_instance();
    }
});
