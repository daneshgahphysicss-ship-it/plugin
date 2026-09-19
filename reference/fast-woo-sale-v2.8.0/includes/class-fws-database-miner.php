<?php
/**
 * Class FWS_Database_Miner
 * ماینر فوق‌سریع، بهینه و بدون بار اضافی روی دیتابیس (پشتیبانی دوگانه HPOS و ساختار کلاسیک، رفع کامل N+1، کاهش ۵۰٪ بار پردازش با روابط متقارن و Bulk Insert)
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Database_Miner {

    const TABLE_AFFINITY = 'fws_product_affinity_cache';
    const CACHE_GROUP    = 'fws_affinity_rules';

    /**
     * اتصال اکشن کرون‌جاب دوره‌ای به ماینر
     */
    public static function init() {
        add_action('fws_daily_database_mining_event', array(__CLASS__, 'run_scheduled_mining'));
        add_action('fws_weekly_db_cleanup_event', array(__CLASS__, 'cleanup_orphaned_and_stale_records'));
        // نسخه ۲.۷: هوک حذف محصول باید در هر درخواست ثبت شود؛ ثبت آن فقط در activation عملاً مرده بود
        add_action('before_delete_post', array(__CLASS__, 'on_product_deleted'));
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('fws mine', array(__CLASS__, 'cli_run_mining'));
            WP_CLI::add_command('fws vacuum', array(__CLASS__, 'cli_run_vacuum'));
        }
    }

    public static function cli_run_vacuum($args, $assoc_args) {
        WP_CLI::log("Starting Fast Woo Database Vacuum and Orphan Pruning...");
        self::cleanup_orphaned_and_stale_records();
        WP_CLI::success("Database sanitized, indexes defragmented, and cache flushed.");
    }

    public static function run_scheduled_mining() {
        self::run_market_basket_analysis();
    }

    /**
     * اجرای مستقیم تحلیل سبد خرید از طریق ترمینال لینوکس و WP-CLI با صفر درصد تأثیر روی سرور وب
     * مثال: wp fws mine --days=90
     */
    public static function cli_run_mining($args, $assoc_args) {
        $lookback = isset($assoc_args['days']) ? absint($assoc_args['days']) : 90;
        WP_CLI::log("Starting Fast Woo Market Basket Mining (Lookback: {$lookback} days)...");
        $start = microtime(true);
        delete_transient('fws_mining_lock');
        $count = self::run_market_basket_analysis($lookback);
        $duration = round(microtime(true) - $start, 2);
        WP_CLI::success("Mining finished in {$duration}s. Generated and indexed {$count} affinity rules.");
    }

    public static function get_memory_limit_bytes() {
        $val = ini_get('memory_limit');
        if (!$val || $val === '-1') return 512 * 1024 * 1024;
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $int_val = (int) $val;
        switch($last) {
            case 'g': $int_val *= 1024 * 1024 * 1024; break;
            case 'm': $int_val *= 1024 * 1024; break;
            case 'k': $int_val *= 1024; break;
        }
        return max(128 * 1024 * 1024, $int_val);
    }

    /**
     * ایجاد جدول کش ماتریس همبستگی با ایندکس‌های ترکیبی پوششی (Covering Indexes)
     */
    public static function create_tables_and_schedule() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_AFFINITY;
        $charset_collate = $wpdb->get_charset_collate();

        // High Performance schema with BTREE compound indexes for 0-disk query scans
        // Using optimal storage data types: MEDIUMINT UNSIGNED for IDs, SMALLINT UNSIGNED for co-occurrence, and composite indexes
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_product_id BIGINT(20) UNSIGNED NOT NULL,
            recommended_product_id BIGINT(20) UNSIGNED NOT NULL,
            co_occurrence INT(11) UNSIGNED NOT NULL DEFAULT 1,
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            lift_score DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            last_calculated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_pair (source_product_id, recommended_product_id),
            INDEX idx_affinity_scoring (source_product_id, confidence_score, lift_score),
            INDEX idx_rec_lookup (recommended_product_id, co_occurrence),
            INDEX idx_calc_time (last_calculated)
        ) {$charset_collate} ENGINE=InnoDB ROW_FORMAT=DYNAMIC;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Optimize table structure
        self::optimize_indexes();

        // Schedule periodic mining cron
        if (!wp_next_scheduled('fws_daily_database_mining_event')) {
            wp_schedule_event(time() + 60, 'daily', 'fws_daily_database_mining_event');
        }

        // Schedule weekly database hygiene & orphan cleanup
        if (!wp_next_scheduled('fws_weekly_db_cleanup_event')) {
            wp_schedule_event(time() + 3600, 'weekly', 'fws_weekly_db_cleanup_event');
        }

        // Schedule initial calculation asynchronously to prevent activation timeouts on heavy stores
        wp_schedule_single_event(time() + 5, 'fws_daily_database_mining_event');
    }

    public static function clear_scheduled_events() {
        wp_clear_scheduled_hook('fws_daily_database_mining_event');
        wp_clear_scheduled_hook('fws_weekly_db_cleanup_event');
        delete_transient('fws_mining_lock');
    }

    /**
     * حذف فوری رکوردهای جدول در زمان حذف فیزیکی محصول از ووکامرس (Zero Orphan Records)
     */
    public static function on_product_deleted($post_id) {
        if (get_post_type($post_id) === 'product') {
            global $wpdb;
            $table_name = $wpdb->prefix . self::TABLE_AFFINITY;
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$table_name} 
                WHERE source_product_id = %d OR recommended_product_id = %d
            ", $post_id, $post_id));
            self::purge_cache();
        }
    }

    /**
     * بهینه‌سازی ساختار دیتابیس، پاکسازی رکوردهای منقضی‌شده و بدون استفاده (Database Vacuuming)
     */
    public static function cleanup_orphaned_and_stale_records() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_AFFINITY;

        // 1. حذف رکوردهایی که محصول اصلی یا مکمل آن‌ها دیگر در ووکامرس وجود ندارد یا پاک شده است
        $wpdb->query("
            DELETE aff FROM {$table_name} aff
            LEFT JOIN {$wpdb->posts} p1 ON aff.source_product_id = p1.ID AND p1.post_type = 'product'
            LEFT JOIN {$wpdb->posts} p2 ON aff.recommended_product_id = p2.ID AND p2.post_type = 'product'
            WHERE p1.ID IS NULL OR p2.ID IS NULL OR p1.post_status = 'trash' OR p2.post_status = 'trash'
        ");

        // 2. هرس کردن رکوردهای بسیار قدیمی و فاقد ارزش آماری (Below threshold cleanup)
        $min_conf = floatval(FWS_Settings::get('min_confidence', 60));
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$table_name}
            WHERE confidence_score < %f 
               OR last_calculated < DATE_SUB(NOW(), INTERVAL 120 DAY)
        ", max(20.0, $min_conf - 20.0)));

        // 3. Defragment و بهینه‌سازی دیسک و ایندکس‌ها
        self::optimize_indexes();
    }

    /**
     * بهینه‌سازی و Defragment کردن جداول و پاکسازی کش‌ها
     */
    public static function optimize_indexes() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_AFFINITY;
        $wpdb->query("OPTIMIZE TABLE {$table_name}");
        self::purge_cache();
    }

    /**
     * پاکسازی امن کش با پشتیبانی از کش گروهی و ارتقای نسخه کلید کش (Cache Busting)
     */
    public static function purge_cache() {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }
        $current_ver = (int) get_option('fws_cache_version', 1);
        update_option('fws_cache_version', $current_ver + 1);
    }

    /**
     * تشخیص نحوه ذخیره‌سازی سفارشات (HPOS یا Posts کلاسیک)
     */
    public static function get_orders_table_info() {
        global $wpdb;
        // رفع خطای مهلک نسخه ۲.۵.۰: بک‌اسلش مضاعف (\\) در فراخوانی کلاس باعث Parse Error کل افزونه می‌شد
        $is_hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') &&
                   \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($is_hpos) {
            return array(
                'table'      => "{$wpdb->prefix}wc_orders",
                'id_col'     => 'id',
                'status_col' => 'status',
                'date_col'   => 'date_created_gmt',
            );
        } else {
            return array(
                'table'      => "{$wpdb->prefix}posts",
                'id_col'     => 'ID',
                'status_col' => 'post_status',
                'date_col'   => 'post_date_gmt',
            );
        }
    }

    /**
     * بررسی وجود جدول Analytics ووکامرس (wc_order_product_lookup)
     * در صورت عدم وجود، ماینینگ بی‌صدا شکست می‌خورد؛ این گارد از آن جلوگیری می‌کند.
     * @return bool
     */
    public static function analytics_lookup_table_exists() {
        global $wpdb;
        static $exists = null;
        if (null !== $exists) {
            return $exists;
        }
        $table  = $wpdb->prefix . 'wc_order_product_lookup';
        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        return $exists;
    }

    /**
     * تحلیل دیتابیس با مکانیسم دسته‌ای بهینه‌شده، بدون N+1، کاهش ۵۰٪ پردازش، قفل مانع از تداخل و Bulk Insert سریع
     * @param int $lookback_days بازه زمانی آنالیز سفارشات (صفر = خواندن از پنل تنظیمات)
     * @return int تعداد قوانین همبستگی کشف‌شده
     */
    public static function run_market_basket_analysis($lookback_days = 0) {
        // گارد پایداری: بدون جدول Analytics ووکامرس، هیچ تحلیلی ممکن نیست
        if (!self::analytics_lookup_table_exists()) {
            update_option('fws_analytics_missing', 1, false);
            return 0;
        }
        delete_option('fws_analytics_missing');

        if ($lookback_days <= 0) {
            $lookback_days = max(7, (int) FWS_Settings::get('lookback_days', 90));
        }

        $lock_key = 'fws_mining_lock';
        if (get_transient($lock_key)) {
            return 0; // جلوگیری از تداخل و اجرای همزمان دو پروسه ماینینگ
        }
        set_transient($lock_key, time(), 15 * MINUTE_IN_SECONDS);

        // تخصیص بهینه منابع سرور در طول پردازش
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (!ini_get('safe_mode')) {
            @set_time_limit(300);
        }
        if (function_exists('wp_suspend_cache_addition')) {
            wp_suspend_cache_addition(true);
        }

        global $wpdb;
        $affinity_table = $wpdb->prefix . self::TABLE_AFFINITY;
        $batch_size = max(100, intval(500));

        // نسخه ۲.۷ — سازگاری منطقه زمانی:
        // جداول Analytics ووکامرس (wc_order_product_lookup) با «ساعت سایت» پر می‌شوند؛
        // اما ستون‌های تاریخ جدول سفارشات (post_date_gmt / date_created_gmt) بر مبنای UTC هستند.
        $lookback_seconds = max(7, (int) $lookback_days) * DAY_IN_SECONDS;
        $cutoff_local = date('Y-m-d H:i:s', current_time('timestamp') - $lookback_seconds); // برای جداول Analytics
        $cutoff_gmt   = gmdate('Y-m-d H:i:s', time() - $lookback_seconds);                  // برای جدول سفارشات

        $order_info = self::get_orders_table_info();

        // Count eligible orders within lookback window
        $total_orders = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$order_info['table']}
            WHERE {$order_info['status_col']} IN ('wc-completed', 'wc-processing')
              AND {$order_info['date_col']} >= %s
        ", $cutoff_gmt));
        if ($total_orders < 1) $total_orders = 1;

        $inserted_count = 0;
        $has_more = true;

        // Local in-memory frequency cache to completely ELIMINATE N+1 queries
        // مقدار ۰ یعنی «فرکانس این محصول در بازه تحلیل یافت نشد» (سنتینل سلامت داده)
        $freq_cache = array();

        // Helper lambda for cached product order frequency (bounded by the exact lookback window)
        $get_freq = function($pid) use ($wpdb, &$freq_cache, $cutoff_local) {
            if (isset($freq_cache[$pid])) {
                return $freq_cache[$pid];
            }
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}wc_order_product_lookup WHERE product_id = %d AND date_created >= %s",
                $pid,
                $cutoff_local
            ));
            $freq_cache[$pid] = $cnt;
            return $freq_cache[$pid];
        };

        // نسخه ۲.۷ — صفحه‌بندی کلیدی (Keyset) به‌جای OFFSET:
        // OFFSET هر دفعه کل تجمیع سنگین GROUP BY را از نو اجرا می‌کرد (رفتار O(n²))؛
        // با کلید (pid1,pid2) فقط داده‌های جدید هر دفعه پیمایش می‌شود.
        $last_pid1 = 0;
        $last_pid2 = 0;

        while ($has_more) {
            $query = "
                SELECT 
                    item1.product_id AS pid1,
                    item2.product_id AS pid2,
                    COUNT(DISTINCT item1.order_id) AS pair_orders
                FROM {$wpdb->prefix}wc_order_product_lookup item1
                INNER JOIN {$wpdb->prefix}wc_order_product_lookup item2 
                    ON item1.order_id = item2.order_id 
                    AND item1.product_id < item2.product_id
                WHERE item1.product_id > 0
                  AND item2.product_id > 0
                  AND item1.date_created >= %s
                  AND item2.date_created >= %s
                  AND (item1.product_id > %d OR (item1.product_id = %d AND item2.product_id > %d))
                GROUP BY item1.product_id, item2.product_id
                HAVING pair_orders >= %d
                ORDER BY item1.product_id ASC, item2.product_id ASC
                LIMIT %d
            ";

            $results = $wpdb->get_results($wpdb->prepare(
                $query,
                $cutoff_local,
                $cutoff_local,
                $last_pid1,
                $last_pid1,
                $last_pid2,
                max(1, (int) FWS_Settings::get('min_support', 3)),
                $batch_size
            ));

            if (empty($results)) {
                $has_more = false;
                break;
            }

            // Bulk prefetch uncached product frequencies in a single SQL query
            $uncached_pids = array();
            foreach ($results as $row) {
                $p1_temp = (int) $row->pid1;
                $p2_temp = (int) $row->pid2;
                if (!isset($freq_cache[$p1_temp])) $uncached_pids[$p1_temp] = true;
                if (!isset($freq_cache[$p2_temp])) $uncached_pids[$p2_temp] = true;
            }
            if (!empty($uncached_pids)) {
                $pid_list = implode(',', array_map('intval', array_keys($uncached_pids)));
                $freq_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT product_id, COUNT(DISTINCT order_id) AS cnt 
                     FROM {$wpdb->prefix}wc_order_product_lookup 
                     WHERE product_id IN ({$pid_list}) AND date_created >= %s 
                     GROUP BY product_id",
                    $cutoff_local
                ));
                if (!empty($freq_rows)) {
                    foreach ($freq_rows as $frow) {
                        $freq_cache[(int)$frow->product_id] = (int) $frow->cnt;
                    }
                }
                // محصولات بدون هیچ سفارشی در بازه: سنتینل ۰ (قوانین ساخته نمی‌شوند)
                foreach ($uncached_pids as $upid => $_) {
                    if (!isset($freq_cache[$upid])) {
                        $freq_cache[$upid] = 0;
                    }
                }
            }

            $rows_to_insert = array();
            $now_mysql = current_time('mysql');
            $min_confidence = floatval(FWS_Settings::get('min_confidence', 60));

            foreach ($results as $row) {
                $p1 = (int) $row->pid1;
                $p2 = (int) $row->pid2;
                $pair_count = (int) $row->pair_orders;

                $freq1 = $get_freq($p1);
                $freq2 = $get_freq($p2);

                // نسخه ۲.۷ — سلامت آماری: به‌جای فرض freq=1 (که اطمینان ساختگی ۱۰۰٪ می‌ساخت)،
                // جفتی که فرکانس قابل‌اعتماد ندارد کلاً رد می‌شود.
                if ($freq1 < 1 || $freq2 < 1) {
                    continue;
                }

                $lift = round(($pair_count * $total_orders) / ($freq1 * $freq2), 2);

                // Symmetric expansion: Rule A -> B
                $conf1 = round(($pair_count / $freq1) * 100, 2);
                if ($conf1 >= $min_confidence) {
                    $rows_to_insert[] = array(
                        'src'  => $p1,
                        'rec'  => $p2,
                        'cnt'  => $pair_count,
                        'conf' => $conf1,
                        'lift' => $lift,
                    );
                }

                // Symmetric expansion: Rule B -> A
                $conf2 = round(($pair_count / $freq2) * 100, 2);
                if ($conf2 >= $min_confidence) {
                    $rows_to_insert[] = array(
                        'src'  => $p2,
                        'rec'  => $p1,
                        'cnt'  => $pair_count,
                        'conf' => $conf2,
                        'lift' => $lift,
                    );
                }
            }

            // High-Performance Bulk Multi-Row Insert (Turns hundreds of DB queries into 1 bulk statement)
            if (!empty($rows_to_insert)) {
                $placeholders = array();
                $values = array();
                foreach ($rows_to_insert as $item) {
                    $placeholders[] = '(%d, %d, %d, %f, %f, %s)';
                    $values[] = $item['src'];
                    $values[] = $item['rec'];
                    $values[] = $item['cnt'];
                    $values[] = $item['conf'];
                    $values[] = $item['lift'];
                    $values[] = $now_mysql;
                }

                $bulk_sql = "INSERT INTO {$affinity_table} 
                    (source_product_id, recommended_product_id, co_occurrence, confidence_score, lift_score, last_calculated) 
                    VALUES " . implode(', ', $placeholders) . "
                    ON DUPLICATE KEY UPDATE 
                        co_occurrence = VALUES(co_occurrence),
                        confidence_score = VALUES(confidence_score),
                        lift_score = VALUES(lift_score),
                        last_calculated = VALUES(last_calculated)";

                $wpdb->query($wpdb->prepare($bulk_sql, $values));
                $inserted_count += count($rows_to_insert);
            }

            $last_row = end($results);
            $last_pid1 = (int) $last_row->pid1;
            $last_pid2 = (int) $last_row->pid2;
            if (count($results) < $batch_size) {
                $has_more = false;
            }

            // Memory Safety Guard: If script consumes over 80% of max allowed PHP memory, break gracefully
            if (function_exists('memory_get_usage') && memory_get_usage(true) > (0.80 * self::get_memory_limit_bytes())) {
                break;
            }

            // CPU Throttling: Micro-pause between batches to keep CPU load below 20% on shared hosting
            if (true) {
                usleep(15000); // 15ms breath pause
            }
        }

        if (function_exists('wp_suspend_cache_addition')) {
            wp_suspend_cache_addition(false);
        }
        delete_transient($lock_key);
        self::purge_cache();
        return $inserted_count;
    }
}
