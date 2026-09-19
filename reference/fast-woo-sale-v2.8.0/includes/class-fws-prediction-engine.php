<?php
/**
 * Class FWS_Prediction_Engine
 * موتور استخراج پیشنهادات هوشمند، لایه کش دو سطحی (Redis / Transients API) جهت سرعت صفر تأخیر
 *
 * نسخه ۲.۷: استراتژی سه‌حالته موتور (خودکار / ترکیبی / دستی)، قوانین دستی مدیر با اولویت،
 * و فیلتر لیست سیاه در تمام مسیرهای پیشنهاددهی
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Prediction_Engine {

    private static $instance = null;
    private static $memoized_cache = array();
    private static $cache_ver = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_cache_version() {
        if (null === self::$cache_ver) {
            self::$cache_ver = (int) get_option('fws_cache_version', 1);
        }
        return self::$cache_ver;
    }

    /**
     * شناسه‌های لیست سیاه محصولات (هرگز نباید پیشنهاد شوند)
     * @return array
     */
    private function get_blacklist_ids() {
        return array_map('absint', (array) FWS_Settings::get('product_blacklist', array()));
    }

    /**
     * سیستم کش ۳ لایه فوق‌سریع:
     * لایه ۰: حافظه موقت PHP (Static Memoization)
     * لایه ۱: Redis / Memcached Persistent Object Cache
     * لایه ۲: جدول Transients پایگاه‌داده
     */
    private function get_cache($key) {
        // Tier 0: In-Memory Static Cache
        if (isset(self::$memoized_cache[$key])) {
            return self::$memoized_cache[$key];
        }

        $ver = $this->get_cache_version();
        $full_key = "fws_{$key}_v{$ver}";

        // Tier 1: Check Object Cache / Redis
        $cached = wp_cache_get($full_key, FWS_Database_Miner::CACHE_GROUP);
        if (false !== $cached) {
            self::$memoized_cache[$key] = $cached;
            return $cached;
        }

        // Tier 2: Fallback to Transients API for hosts without Redis
        $transient = get_transient($full_key);
        if (false !== $transient) {
            wp_cache_set($full_key, $transient, FWS_Database_Miner::CACHE_GROUP, 24 * HOUR_IN_SECONDS);
            self::$memoized_cache[$key] = $transient;
            return $transient;
        }

        return false;
    }

    private function set_cache($key, $data) {
        self::$memoized_cache[$key] = $data;
        $ver = $this->get_cache_version();
        $full_key = "fws_{$key}_v{$ver}";
        $ttl = 24 * HOUR_IN_SECONDS;

        wp_cache_set($full_key, $data, FWS_Database_Miner::CACHE_GROUP, $ttl);
        set_transient($full_key, $data, $ttl);
    }

    /**
     * استخراج قوانین دستی مدیر (Pin) برای یک محصول خاص — مرتب بر اساس اولویت
     * @param int   $product_id
     * @param array $blacklist
     * @return array
     */
    public function get_manual_rules_for_product($product_id, $blacklist = array()) {
        $product_id = absint($product_id);
        if ($product_id <= 0) return array();

        $rules = (array) FWS_Settings::get('manual_rules', array());
        if (empty($rules)) return array();

        // اولویت نمایش بر اساس درصد اطمینان فرضی تعیین‌شده توسط مدیر
        usort($rules, function ($a, $b) {
            return (int) (isset($b['confidence']) ? $b['confidence'] : 0) <=> (int) (isset($a['confidence']) ? $a['confidence'] : 0);
        });

        $out = array();
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            if ((int) (isset($rule['source']) ? $rule['source'] : 0) !== $product_id) continue;

            $target = absint(isset($rule['target']) ? $rule['target'] : 0);
            if ($target <= 0 || $target === $product_id) continue;
            if (!empty($blacklist) && in_array($target, $blacklist, true)) continue;

            $product = wc_get_product($target);
            if ($product && $product->is_visible() && $product->is_in_stock() && $product->is_purchasable()) {
                $out[] = array(
                    'product_id'    => $product->get_id(),
                    'name'          => $product->get_name(),
                    'price'         => (float) $product->get_price(),
                    'regular_price' => (float) $product->get_regular_price(),
                    'image'         => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                    'confidence'    => (float) max(50, min(100, (int) (isset($rule['confidence']) ? $rule['confidence'] : 95))),
                    'co_orders'     => 0,
                    'lift'          => 0.0,
                    'is_fallback'   => false,
                    'is_manual'     => true,
                );
            }
        }
        return $out;
    }

    /**
     * دریافت محصولات مکمل پیشنهادی — با احترام به استراتژی موتور (خودکار/ترکیبی/دستی)
     */
    public function get_recommendations_for_product($product_id, $limit = 3) {
        $product_id = absint($product_id);
        if ($product_id <= 0) return array();

        $limit = max(1, (int) $limit);
        $mode  = (string) FWS_Settings::get('manual_override_mode', FWS_Settings::MODE_AUTOMATIC);

        $cache_key = "rec_{$product_id}_{$limit}_{$mode}";
        $cached = $this->get_cache($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $blacklist = $this->get_blacklist_ids();
        $seen = array($product_id => true); // جلوگیری از تکرار محصول فعلی و آیتم‌های اضافه‌شده
        $recommendations = array();

        // ─── ۱) قوانین دستی مدیر (در حالت ترکیبی: اولویت‌دار | در حالت دستی: تنها منبع) ───
        if (FWS_Settings::MODE_AUTOMATIC !== $mode) {
            foreach ($this->get_manual_rules_for_product($product_id, $blacklist) as $manual_rec) {
                if (count($recommendations) >= $limit) break;
                $mpid = (int) $manual_rec['product_id'];
                if (isset($seen[$mpid])) continue;
                $seen[$mpid] = true;
                $recommendations[] = $manual_rec;
            }
        }

        // ─── ۲) قوانین کش‌شده دیتابیس (در حالت ۱۰۰٪ دستی به‌کلی کنار گذاشته می‌شود) ───
        if (FWS_Settings::MODE_MANUAL !== $mode && count($recommendations) < $limit) {
            global $wpdb;
            $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;

            // Candidate lookahead buffer: دریافت کاندیداهای بیشتر جهت تضمین پر بودن پیشنهادها
            $candidate_limit = max(10, ($limit + count($recommendations)) * 3);
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    recommended_product_id,
                    confidence_score,
                    co_occurrence,
                    lift_score
                FROM {$affinity_table}
                WHERE source_product_id = %d
                  AND confidence_score >= %f
                ORDER BY confidence_score DESC, co_occurrence DESC
                LIMIT %d
            ", $product_id, floatval(FWS_Settings::get('min_confidence', 60)), $candidate_limit));

            if (!empty($results)) {
                // Prime Core WordPress Post & PostMeta caches in a SINGLE query (رفع N+1)
                $rec_pids = array();
                foreach ($results as $item) {
                    $rec_pids[] = absint($item->recommended_product_id);
                }
                if (!empty($rec_pids) && function_exists('_prime_post_caches')) {
                    _prime_post_caches($rec_pids, true, true);
                }

                foreach ($results as $item) {
                    if (count($recommendations) >= $limit) {
                        break;
                    }
                    $rec_pid = absint($item->recommended_product_id);
                    if (isset($seen[$rec_pid]) || in_array($rec_pid, $blacklist, true)) continue;

                    $product = wc_get_product($rec_pid);
                    if ($product && $product->is_visible() && $product->is_in_stock() && $product->is_purchasable()) {
                        $seen[$rec_pid] = true;
                        $recommendations[] = array(
                            'product_id'   => $product->get_id(),
                            'name'         => $product->get_name(),
                            'price'        => (float) $product->get_price(),
                            'regular_price'=> (float) $product->get_regular_price(),
                            'image'        => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                            'confidence'   => (float) $item->confidence_score,
                            'co_orders'    => (int) $item->co_occurrence,
                            'lift'         => (float) $item->lift_score,
                            'is_fallback'  => false,
                            'is_manual'    => false,
                        );
                    }
                }
            }
        }

        // ─── ۳) فال‌بک هوشمند دسته‌بندی (در حالت ۱۰۰٪ دستی غیرفعال) ───
        if (count($recommendations) < $limit
            && FWS_Settings::MODE_MANUAL !== $mode
            && 'yes' === FWS_Settings::get('enable_fallback', 'yes')
        ) {
            $needed = $limit - count($recommendations);
            $exclude_ids = array_merge(array_keys($seen), $blacklist);
            $fallbacks = $this->get_category_fallbacks($product_id, $needed, $exclude_ids);
            foreach ($fallbacks as $fb) {
                $recommendations[] = $fb;
            }
        }

        $recommendations = apply_filters('fws_product_recommendations', $recommendations, $product_id, $limit);
        $this->set_cache($cache_key, $recommendations);
        return $recommendations;
    }

    /**
     * فال‌بک هوشمند دسته‌بندی برای کالاهای بدون سابقه سبد خرید (حل مسئله Cold-Start)
     */
    public function get_category_fallbacks($product_id, $limit = 2, $exclude_ids = array()) {
        $terms = wc_get_product_term_ids($product_id, 'product_cat');
        if (empty($terms)) return array();

        $args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => max(4, $limit * 2),
            'post__not_in'           => $exclude_ids,
            'tax_query'              => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $terms,
                ),
            ),
            'meta_key'               => 'total_sales',
            'orderby'                => 'meta_value_num',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );

        $query = new WP_Query($args);
        $fallbacks = array();

        if ($query->have_posts()) {
            foreach ($query->posts as $fb_id) {
                if (count($fallbacks) >= $limit) break;
                $prod = wc_get_product($fb_id);
                if ($prod && $prod->is_visible() && $prod->is_in_stock() && $prod->is_purchasable()) {
                    $fallbacks[] = array(
                        'product_id'   => $prod->get_id(),
                        'name'         => $prod->get_name(),
                        'price'        => (float) $prod->get_price(),
                        'regular_price'=> (float) $prod->get_regular_price(),
                        'image'        => wp_get_attachment_image_url($prod->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        // صداقت آماری: برای فال‌بک هیچ درصد همبستگی ساختگی نمایش داده نمی‌شود
                        'confidence'   => 0.0,
                        'co_orders'    => 0,
                        'lift'         => 0.0,
                        'is_fallback'  => true,
                        'is_manual'    => false,
                    );
                }
            }
        }

        return apply_filters('fws_category_fallbacks', $fallbacks, $product_id, $limit);
    }

    /**
     * هوش مالی سبد خرید: پیدا کردن کالای پرکننده بهینه برای رسیدن به سقف ارسال رایگان
     */
    public function get_free_shipping_fillers($cart_total, $threshold) {
        if ($cart_total >= $threshold) {
            return array();
        }

        $gap = $threshold - $cart_total;
        $cart_items = WC()->cart ? WC()->cart->get_cart() : array();
        $cart_product_ids = array();
        foreach ($cart_items as $item) {
            $cart_product_ids[] = absint($item['product_id']);
        }

        $cart_product_ids = array_filter(array_unique(array_map('absint', $cart_product_ids)));
        if (empty($cart_product_ids)) return array();

        $blacklist = $this->get_blacklist_ids();

        global $wpdb;
        $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
        $in_placeholders = implode(',', array_fill(0, count($cart_product_ids), '%d'));

        // Find accessories or complementary items whose price bridges the free-shipping gap
        $candidates = $wpdb->get_results($wpdb->prepare("
            SELECT recommended_product_id, MAX(confidence_score) as max_conf
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
            GROUP BY recommended_product_id
            ORDER BY max_conf DESC
            LIMIT 8
        ", array_merge($cart_product_ids, $cart_product_ids)));

        $fillers = array();
        if (!empty($candidates)) {
            foreach ($candidates as $cand) {
                if (count($fillers) >= 4) break;
                $cand_pid = absint($cand->recommended_product_id);
                if (in_array($cand_pid, $blacklist, true)) continue;

                $prod = wc_get_product($cand_pid);
                if ($prod && $prod->is_visible() && $prod->is_in_stock() && $prod->is_purchasable()) {
                    $price = (float) $prod->get_price();
                    $fillers[] = array(
                        'product_id'   => $prod->get_id(),
                        'name'         => $prod->get_name(),
                        'price'        => $price,
                        'image'        => wp_get_attachment_image_url($prod->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        'confidence'   => (float) $cand->max_conf,
                        'bridges_gap'  => ($price >= $gap),
                    );
                }
            }
        }

        return $fillers;
    }

    /**
     * پیش‌بینی محصول مکمل برتر پس از ثبت سفارش در صفحه تشکر (1-Click Post Purchase Upsell)
     * نسخه ۲.۷: پیمایش ۵ کاندیدای برتر + فیلتر لیست سیاه + بررسی is_visible
     */
    public function get_post_purchase_upsell($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return null;

        $purchased_ids = array();
        foreach ($order->get_items() as $item) {
            $purchased_ids[] = absint($item->get_product_id());
        }
        $purchased_ids = array_filter(array_unique(array_map('absint', $purchased_ids)));
        if (empty($purchased_ids)) return null;

        $blacklist = $this->get_blacklist_ids();

        global $wpdb;
        $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
        $in_placeholders = implode(',', array_fill(0, count($purchased_ids), '%d'));

        $top_matches = $wpdb->get_results($wpdb->prepare("
            SELECT recommended_product_id, MAX(confidence_score) as best_conf, MAX(lift_score) as best_lift
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
            GROUP BY recommended_product_id
            ORDER BY best_conf DESC, best_lift DESC
            LIMIT 5
        ", array_merge($purchased_ids, $purchased_ids)));

        if (empty($top_matches)) return null;

        foreach ($top_matches as $top_match) {
            $candidate_pid = absint($top_match->recommended_product_id);
            if (in_array($candidate_pid, $blacklist, true)) continue;

            $product = wc_get_product($candidate_pid);
            if (!$product || !$product->is_visible() || !$product->is_in_stock() || !$product->is_purchasable()) continue;

            $regular = (float) $product->get_price();
            $discount_pct = max(0, min(90, floatval(FWS_Settings::get('upsell_discount', 20))));
            $discounted = round($regular * ((100 - $discount_pct) / 100), wc_get_price_decimals());

            return array(
                'product_id'        => $product->get_id(),
                'name'              => $product->get_name(),
                'regular_price'     => $regular,
                'discounted_price'  => $discounted,
                'discount_percent'  => $discount_pct,
                'confidence'        => (float) $top_match->best_conf,
                'image'             => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
            );
        }

        return null;
    }

    /**
     * پیش‌بینی خرید بعدی کاربر لاگین‌شده بر اساس سابقه خریدهای قبلی (Personalized RFM)
     * نسخه ۲.۷: فیلتر لیست سیاه + کش نتیجه منفی (حذف کوئری‌های تکراری صفحه حساب کاربری)
     */
    public function get_user_next_purchase_prediction($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) return null;

        $cache_key = "user_rec_{$user_id}";
        $cached = $this->get_cache($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $prediction = $this->build_user_prediction($user_id);

        // کش حتی برای حالت «بدون پیشنهاد» تا صفحه حساب کاربری هر بار کوئری سنگین نزند
        $this->set_cache($cache_key, $prediction);
        return $prediction;
    }

    /**
     * ساخت پیش‌بینی خرید بعدی کاربر (بدون لایه کش)
     */
    private function build_user_prediction($user_id) {
        $blacklist = $this->get_blacklist_ids();

        $customer_orders = wc_get_orders(array(
            'customer' => $user_id,
            'limit'    => 10,
            'status'   => array('wc-completed', 'wc-processing'),
            'orderby'  => 'date',
            'order'    => 'DESC',
        ));

        if (empty($customer_orders)) return null;

        $purchased_product_ids = array();
        foreach ($customer_orders as $order) {
            foreach ($order->get_items() as $item) {
                $purchased_product_ids[] = absint($item->get_product_id());
            }
        }
        $purchased_product_ids = array_filter(array_unique(array_map('absint', $purchased_product_ids)));
        if (empty($purchased_product_ids)) return null;

        global $wpdb;
        $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
        $in_placeholders = implode(',', array_fill(0, count($purchased_product_ids), '%d'));

        $candidates = $wpdb->get_results($wpdb->prepare("
            SELECT recommended_product_id, AVG(confidence_score) as avg_conf, SUM(co_occurrence) as sum_orders
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
            GROUP BY recommended_product_id
            ORDER BY avg_conf DESC
            LIMIT 8
        ", array_merge($purchased_product_ids, $purchased_product_ids)));

        if (!empty($candidates)) {
            foreach ($candidates as $candidate) {
                $candidate_pid = absint($candidate->recommended_product_id);
                if (in_array($candidate_pid, $blacklist, true)) continue;

                $product = wc_get_product($candidate_pid);
                if ($product && $product->is_visible() && $product->is_in_stock() && $product->is_purchasable()) {
                    return array(
                        'product_id' => $product->get_id(),
                        'name'       => $product->get_name(),
                        'price'      => (float) $product->get_price(),
                        'image'      => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        'confidence' => round($candidate->avg_conf, 1),
                    );
                }
            }
        }

        return null;
    }

    /**
     * استخراج فوق‌سریع کل پیشنهادات سبد خرید با یک کوئری تجمیعی ریاضی به جای کوئری‌های تکراری
     */
    public function get_cart_recommendations($cart_product_ids, $limit = 3) {
        $cart_product_ids = array_filter(array_unique(array_map('absint', (array) $cart_product_ids)));
        if (empty($cart_product_ids)) return array();

        $cache_key = 'cart_recs_' . md5(implode('_', $cart_product_ids)) . "_{$limit}";
        $cached = $this->get_cache($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $blacklist = $this->get_blacklist_ids();

        global $wpdb;
        $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
        $in_placeholders = implode(',', array_fill(0, count($cart_product_ids), '%d'));

        $candidate_limit = max(10, $limit * 3);
        $query_params = array_merge($cart_product_ids, $cart_product_ids, array($candidate_limit));
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                recommended_product_id, 
                MAX(confidence_score) as best_conf, 
                SUM(co_occurrence) as total_co
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
            GROUP BY recommended_product_id
            ORDER BY best_conf DESC, total_co DESC
            LIMIT %d
        ", $query_params));

        $recommendations = array();
        if (!empty($results)) {
            // Prime Core WordPress Post & PostMeta caches in a single query
            $rec_pids = array();
            foreach ($results as $row) {
                $rec_pids[] = absint($row->recommended_product_id);
            }
            if (!empty($rec_pids) && function_exists('_prime_post_caches')) {
                _prime_post_caches($rec_pids, true, true);
            }

            foreach ($results as $row) {
                if (count($recommendations) >= $limit) {
                    break;
                }
                $rec_pid = absint($row->recommended_product_id);
                if (in_array($rec_pid, $blacklist, true)) continue;

                $product = wc_get_product($rec_pid);
                if ($product && $product->is_visible() && $product->is_in_stock() && $product->is_purchasable()) {
                    $recommendations[] = array(
                        'product_id' => $product->get_id(),
                        'name'       => $product->get_name(),
                        'price'      => (float) $product->get_price(),
                        'image'      => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        'confidence' => (float) $row->best_conf,
                    );
                }
            }
        }

        $this->set_cache($cache_key, $recommendations);
        return $recommendations;
    }

    /**
     * پیش‌بینی و استخراج کالاهای مکمل با بیشترین نرخ خرید همزمان برای عبارت جستجوشده کاربر
     */
    public function get_search_co_occurrence_recommendations($search_query, $limit = 2) {
        $search_query = sanitize_text_field(trim($search_query));
        if (empty($search_query) || mb_strlen($search_query) < 2) {
            return array();
        }

        $cache_key = 'search_rec_' . md5($search_query) . "_{$limit}";
        $cached = $this->get_cache($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $blacklist = $this->get_blacklist_ids();

        global $wpdb;

        // 1. پیدا کردن آیدی محصولات تطابق‌یافته با کلمه کلیدی جستجو (فقط روی عنوان کالا جهت استفاده ۱۰۰٪ از ایندکس)
        $wildcard = '%' . $wpdb->esc_like($search_query) . '%';
        $matching_product_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
              AND post_status = 'publish'
              AND post_title LIKE %s
            ORDER BY ID DESC
            LIMIT 6
        ", $wildcard));

        if (empty($matching_product_ids)) {
            return array();
        }

        $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
        $in_placeholders = implode(',', array_fill(0, count($matching_product_ids), '%d'));

        // 2. کوئری استخراج مکمل‌ها با استفاده از ایندکس ترکیبی پوششی (Covering Index)
        $query_params = array_merge($matching_product_ids, $matching_product_ids, array($limit * 3));
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                recommended_product_id,
                source_product_id,
                MAX(confidence_score) as max_conf,
                SUM(co_occurrence) as total_co
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
            GROUP BY recommended_product_id
            ORDER BY total_co DESC, max_conf DESC
            LIMIT %d
        ", $query_params));

        $recommendations = array();
        if (!empty($results)) {
            $prime_ids = array();
            foreach ($results as $row) {
                $prime_ids[] = absint($row->recommended_product_id);
                $prime_ids[] = absint($row->source_product_id);
            }
            if (!empty($prime_ids) && function_exists('_prime_post_caches')) {
                _prime_post_caches(array_unique($prime_ids), true, true);
            }

            foreach ($results as $row) {
                if (count($recommendations) >= $limit) break;
                $rec_pid = absint($row->recommended_product_id);
                if (in_array($rec_pid, $blacklist, true)) continue;

                $product = wc_get_product($rec_pid);
                $source = wc_get_product($row->source_product_id);
                if ($product && $product->is_visible() && $product->is_in_stock() && $product->is_purchasable()) {
                    $recommendations[] = array(
                        'product_id'    => $product->get_id(),
                        'name'          => $product->get_name(),
                        'price'         => (float) $product->get_price(),
                        'regular_price' => (float) $product->get_regular_price() ?: (float) $product->get_price(),
                        'image'         => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        'confidence'    => round((float) $row->max_conf, 1),
                        'co_occurrence' => (int) $row->total_co,
                        'source_name'   => $source ? $source->get_name() : $search_query,
                        'reason'        => sprintf('پرفروش‌ترین مکمل خریداری‌شده در کنار «%s»', $source ? $source->get_name() : $search_query),
                    );
                }
            }
        }

        $this->set_cache($cache_key, $recommendations);
        return $recommendations;
    }
}
