<?php
/**
 * Class FWS_Performance_Optimizer
 * سیستم جامع نظارت بر عملکرد، بهینه‌سازی جداول دیتابیس، کش چند لایه و ارتقای امتیاز Core Web Vitals
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Performance_Optimizer {

    public static function init() {
        // Admin Benchmark Endpoint (متصل به دکمه بنچمارک در پنل مدیریت)
        // بهینه‌سازی: اندپوینت‌های تکراری/بدون مصرف‌کننده (fws_optimize_database_tables،
        // fws_flush_transient_cache و fws_get_deferred_recommendations بدون احراز/مصرف) حذف شدند
        // تا سطح حمله کاهش یابد؛ منطق آن‌ها در FWS_Ajax_Handler و همین کلاس موجود است.
        add_action('wp_ajax_fws_run_speed_benchmark', array(__CLASS__, 'ajax_run_benchmark'));
    }

    /**
     * بهینه‌سازی دیتابیس، مرتب‌سازی ایندکس‌ها و پاکسازی ترنزینت‌های منقضی
     */
    public static function optimize_database_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;

        // 1. Defragment & Optimize MySQL Table
        $wpdb->query("OPTIMIZE TABLE {$table_name}");
        $wpdb->query("ANALYZE TABLE {$table_name}");

        // 2. Prune expired transients related to Fast Woo
        // نسخه ۲.۷ — رفع باگ «ترنزینت ابدی»: کوئری قبلی فقط ردیف timeout را حذف می‌کرد؛
        // ترنزینتِ بدونِ timeout در وردپرس «دائمی» تلقی می‌شود و هرگز پاک نمی‌شد.
        // اکنون هر دو ردیف (داده + timeout) فقط برای ترنزینت‌های واقعاً منقضی حذف می‌شوند.
        $wpdb->query("
            DELETE data_row, timeout_row
            FROM {$wpdb->options} AS data_row
            INNER JOIN {$wpdb->options} AS timeout_row
                ON timeout_row.option_name = CONCAT('_transient_timeout_', SUBSTRING(data_row.option_name, 12))
            WHERE data_row.option_name LIKE '\\_transient\\_fws\\_%'
              AND timeout_row.option_value < UNIX_TIMESTAMP()
        ");

        // 3. Remove orphan rules pointing to deleted or trashed products
        // نسخه ۲.۷ — هم‌راستا با منطق هفتگی ماینر: فقط رکوردهای واقعاً یتیم یا زباله‌دانی؛
        // نسخه قبلی هر قاعده‌ای که طرفش publish نبود را برای همیشه حذف می‌کرد
        // (اگر ادمین موقتاً محصولات را پیش‌نویس می‌کرد، قوانین کش‌شده می‌سوختند).
        $wpdb->query("
            DELETE a FROM {$table_name} a
            LEFT JOIN {$wpdb->posts} p1 ON a.source_product_id = p1.ID
            LEFT JOIN {$wpdb->posts} p2 ON a.recommended_product_id = p2.ID
            WHERE p1.ID IS NULL OR p2.ID IS NULL OR p1.post_status = 'trash' OR p2.post_status = 'trash'
        ");

        // Flush versioned object cache
        FWS_Database_Miner::purge_cache();

        return true;
    }

    /**
     * بنچمارک زنده سرعت کوئری دیتابیس با و بدون ایندکس
     */
    public static function run_query_benchmark($product_id = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;

        if ($product_id <= 0) {
            $product_id = (int) $wpdb->get_var("SELECT source_product_id FROM {$table_name} LIMIT 1");
        }

        if (!$product_id) {
            return array('status' => 'no_data');
        }

        // 1. Benchmark Indexed Query
        $t1 = microtime(true);
        $indexed_results = $wpdb->get_results($wpdb->prepare("
            SELECT recommended_product_id, confidence_score, lift_score
            FROM {$table_name}
            WHERE source_product_id = %d
            ORDER BY confidence_score DESC
            LIMIT 3
        ", $product_id));
        $indexed_time = round((microtime(true) - $t1) * 1000, 2);

        // 2. Benchmark Full Table Scan Simulation
        $t2 = microtime(true);
        $wpdb->suppress_errors(true);
        $unindexed_query = $wpdb->prepare("
            SELECT recommended_product_id, confidence_score, lift_score
            FROM {$table_name} IGNORE INDEX (idx_affinity_scoring, product_pair)
            WHERE source_product_id = %d
            ORDER BY confidence_score DESC
            LIMIT 3
        ", $product_id);
        $unindexed_results = $wpdb->get_results($unindexed_query);
        $wpdb->suppress_errors(false);
        $unindexed_time = round((microtime(true) - $t2) * 1000, 2);

        // نسخه ۲.۷ — صداقت آماری: اعداد واقعی اندازه‌گیری‌شده گزارش می‌شوند؛
        // نسخه قبل با کف ساختگی (45ms / 0.4ms) نتیجه را دستکاری می‌کرد.
        return array(
            'indexed_ms'    => $indexed_time,
            'unindexed_ms'  => $unindexed_time,
            'indexed_rows'  => count($indexed_results),
            'unindexed_rows'=> count($unindexed_results),
            'has_redis'     => wp_using_ext_object_cache(),
            'rules_count'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
        );
    }

    public static function ajax_run_benchmark() {
        check_ajax_referer('fws_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'دسترسی غیرمجاز'));
        }

        $res = self::run_query_benchmark();
        wp_send_json_success($res);
    }
}
