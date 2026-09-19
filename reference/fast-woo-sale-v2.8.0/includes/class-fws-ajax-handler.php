<?php
/**
 * Class FWS_Ajax_Handler
 * پردازشگر امن و فوق‌سریع AJAX (حفاظت صددرصدی در برابر IDOR، اعتبارسنجی نانس، محافظت از انبار و ثبت تخفیف‌های هوشمند)
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Ajax_Handler {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_fws_add_bundle', array($this, 'add_bundle_to_cart'));
        add_action('wp_ajax_nopriv_fws_add_bundle', array($this, 'add_bundle_to_cart'));

        add_action('wp_ajax_fws_add_single', array($this, 'add_single_to_cart'));
        add_action('wp_ajax_nopriv_fws_add_single', array($this, 'add_single_to_cart'));

        add_action('wp_ajax_fws_thankyou_upsell', array($this, 'process_thankyou_upsell'));
        add_action('wp_ajax_nopriv_fws_thankyou_upsell', array($this, 'process_thankyou_upsell'));

        // اعمال کد تخفیف مودال خروج — تنها کد پیکربندی‌شده در پنل ادمین اعمال می‌شود
        add_action('wp_ajax_fws_apply_exit_coupon', array($this, 'apply_exit_coupon'));
        add_action('wp_ajax_nopriv_fws_apply_exit_coupon', array($this, 'apply_exit_coupon'));

        add_action('wp_ajax_fws_recalculate_rules', array($this, 'recalculate_rules'));
        add_action('wp_ajax_fws_optimize_database', array($this, 'optimize_database'));
    }

    /**
     * شناسایی IP واقعی کاربر — نسخه ۲.۷: مقاوم در برابر جعل هدر
     *
     * نکته امنیتی: به هدرهای پروکسی (X-Forwarded-For و...) فقط زمانی اعتماد می‌شود که
     * درخواست مستقیماً از یک پروکسی معتبر (لیست `fws_trusted_proxies`) رسیده باشد؛
     * در غیر این صورت REMOTE_ADDR ملاک است چون هدرها قابل جعل‌اند و Rate-Limit را بی‌اثر می‌کردند.
     * فروشگاه‌های پشت کلودفلر/CDN می‌توانند با فیلتر `fws_trusted_proxies` بازه‌های CIDR را ثبت کنند.
     * @return string
     */
    private function get_client_ip() {
        $remote = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ('' === $remote) {
            return '0.0.0.0';
        }

        $trusted = apply_filters('fws_trusted_proxies', array());
        if (!empty($trusted) && $this->ip_matches_ranges($remote, (array) $trusted) && class_exists('WC_Geolocation')) {
            $real = \WC_Geolocation::get_ip_address();
            if (!empty($real)) {
                return sanitize_text_field($real);
            }
        }
        return $remote;
    }

    /**
     * تطبیق IP با لیست IP/CIDR (پروکسی‌های معتبر)
     */
    private function ip_matches_ranges($ip, $ranges) {
        foreach ($ranges as $range) {
            $range = trim((string) $range);
            if ('' === $range) continue;
            if (false !== strpos($range, '/')) {
                if ($this->ip_in_cidr($ip, $range)) return true;
            } elseif ($ip === $range) {
                return true;
            }
        }
        return false;
    }

    /**
     * بررسی عضویت IP در یک بازه CIDR (پشتیبانی IPv4 و IPv6)
     */
    private function ip_in_cidr($ip, $cidr) {
        list($subnet, $bits) = array_pad(explode('/', $cidr, 2), 2, null);
        $ip_bin     = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if (false === $ip_bin || false === $subnet_bin || strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }
        $max  = strlen($ip_bin) * 8;
        $bits = (null === $bits) ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) return false;

        $bytes = (int) floor($bits / 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
            return false;
        }
        if ($rem > 0) {
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($ip_bin[$bytes]) & $mask) !== (ord($subnet_bin[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * بررسی سقف نرخ مجاز درخواست‌ها (Rate Limiting) جهت حفاظت در برابر ربات‌ها، حملات DoS و Cart Stuffing
     */
    private function check_rate_limit($action = 'cart_action', $limit = 30, $window = 60) {
        $ip = $this->get_client_ip();
        $key = 'fws_rate_' . md5($action . '_' . $ip);
        $attempts = (int) get_transient($key);
        if ($attempts >= $limit) {
            wp_send_json_error(array('message' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی صبر کرده و سپس تلاش نمایید.'));
        }
        set_transient($key, $attempts + 1, $window);
    }

    /**
     * اعمال کد تخفیف مودال خروج به سبد خرید
     * امنیت: تنها کد تخفیفی که ادمین در پنل تنظیمات ثبت کرده اعمال می‌شود؛
     * ورودی کاربر هرگز به‌عنوان کد تخفیف مورد اعتماد قرار نمی‌گیرد.
     */
    public function apply_exit_coupon() {
        check_ajax_referer('fws_prediction_nonce', 'nonce');
        $this->check_rate_limit('apply_coupon', 5, 60);

        $configured_coupon = trim((string) FWS_Settings::get('exit_intent_coupon', ''));
        if ('' === $configured_coupon) {
            wp_send_json_error(array('message' => 'در حال حاضر کد تخفیف ویژه‌ای تعریف نشده است.'));
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('message' => 'سبد خرید در دسترس نیست. لطفاً صفحه را تازه‌سازی کنید.'));
        }

        // Ensure guest session exists so the coupon can be persisted on the cart
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        $applied = WC()->cart->apply_coupon($configured_coupon);

        if (!$applied) {
            $notices = wc_get_notices('error');
            $notice_msg = !empty($notices) ? wp_strip_all_tags(reset($notices)['notice']) : 'امکان اعمال این کد تخفیف وجود ندارد.';
            wc_clear_notices();
            wp_send_json_error(array('message' => $notice_msg));
        }

        wc_clear_notices(); // جلوگیری از نمایش دوباره پیام موفقیت در بارگذاری بعدی صفحه

        wp_send_json_success(array(
            'message'      => 'کد تخفیف با موفقیت روی سبد خرید شما اعمال شد.',
            'checkout_url' => wc_get_checkout_url(),
        ));
    }

    /**
     * افزودن دسته‌ای اقلام پکیج و تنظیم سشن تخفیف
     *
     * امنیت نسخه ۲.۷: امضای HMAC سمت سرور — فقط شناسه‌هایی که در رندر باکس پکیج
     * (صفحه محصول) امضا شده‌اند مجاز به دریافت تخفیف پکیج هستند؛ در نتیجه ارسال
     * دستی product_ids دلخواه (Cart Stuffing) عملاً غیرممکن می‌شود.
     * تخفیف پکیج فقط با حداقل ۲ کالای واقعی از پکیج اعمال می‌شود.
     */
    public function add_bundle_to_cart() {
        check_ajax_referer('fws_prediction_nonce', 'nonce');
        $this->check_rate_limit('add_bundle', 25, 60);

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('message' => 'سبد خرید در دسترس نیست. لطفاً صفحه را تازه‌سازی کنید.'));
        }

        $main_id   = isset($_POST['main_id']) ? absint($_POST['main_id']) : 0;
        $official  = isset($_POST['bundle_ids']) ? array_filter(array_map('absint', preg_split('/[,\\s]+/', sanitize_text_field(wp_unslash($_POST['bundle_ids']))))) : array();
        $signature = isset($_POST['bundle_sig']) ? sanitize_text_field(wp_unslash($_POST['bundle_sig'])) : '';
        $raw_ids   = isset($_POST['product_ids']) ? (array) $_POST['product_ids'] : array();
        $submitted = array_filter(array_unique(array_map('absint', $raw_ids)));

        // اعتبارسنجی امضای پکیج: امضا فقط برای جفت «محصول مبدأ + لیست رسمی پیشنهادها» معتبر است
        $expected_sig = hash_hmac('sha256', $main_id . '|' . implode(',', $official), wp_salt('auth'));
        if ($main_id <= 0 || empty($official) || !hash_equals($expected_sig, $signature)) {
            wp_send_json_error(array('message' => 'امضای پکیج نامعتبر است؛ لطفاً صفحه محصول را تازه‌سازی کرده و دوباره تلاش کنید.'));
        }

        // محصول اصلی همیشه بخشی از پکیج است
        if (!in_array($main_id, $submitted, true)) {
            $submitted[] = $main_id;
        }

        // فقط شناسه‌های داخل امضا پذیرفته می‌شوند؛ بقیه بی‌صدا حذف می‌شوند
        $allowed   = array_merge(array($main_id), $official);
        $valid_ids = array_values(array_intersect($submitted, $allowed));
        if (empty($valid_ids)) {
            wp_send_json_error(array('message' => 'هیچ محصول معتبری از پکیج انتخاب نشده است.'));
        }
        if (count($valid_ids) > 10) {
            $valid_ids = array_slice($valid_ids, 0, 10);
        }

        // تخفیف پکیج فقط وقتی معنا دارد که حداقل ۲ کالای پکیج انتخاب شده باشد
        $apply_discount = (count($valid_ids) >= 2);

        // Ensure customer session is created and persisted for guest users
        if (WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        $added = 0;
        foreach ($valid_ids as $pid) {
            $product = wc_get_product($pid);
            if ($product && $product->is_purchasable() && $product->is_in_stock()) {
                $cart_item_key = WC()->cart->add_to_cart($pid, 1);
                if ($cart_item_key) {
                    $added++;
                }
            }
        }

        if ($added === 0) {
            $notices = wc_get_notices('error');
            $notice_msg = !empty($notices) ? wp_strip_all_tags(reset($notices)['notice']) : 'محصولات انتخابی در انبار موجود یا قابل سفارش نیستند.';
            wc_clear_notices();
            wp_send_json_error(array('message' => $notice_msg));
        }

        // سشن تخفیف فقط پس از افزودن موفق و فقط برای لیست اعتبارسنجی‌شده ثبت می‌شود (رفع آلودگی سشن در مسیر خطا)
        if ($apply_discount && WC()->session) {
            $existing = (array) WC()->session->get('fws_bundle_items', array());
            $merged   = array_unique(array_merge($existing, $valid_ids));
            WC()->session->set('fws_bundle_items', $merged);
        }

        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $message = $apply_discount
            ? sprintf('%d محصول با تخفیف پکیج به سبد خرید افزوده شد.', $added)
            : sprintf('%d محصول به سبد افزوده شد؛ تخفیف پکیج فقط با انتخاب حداقل ۲ کالا اعمال می‌شود.', $added);

        wp_send_json_success(array(
            'message'    => $message,
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_url'   => wc_get_cart_url(),
            'cart_hash'  => WC()->cart->get_cart_hash(),
            'fragments'  => apply_filters('woocommerce_add_to_cart_fragments', array(
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            )),
        ));
    }

    /**
     * افزودن سریع کالای پرکننده یا پیشنهادی
     */
    public function add_single_to_cart() {
        check_ajax_referer('fws_prediction_nonce', 'nonce');
        $this->check_rate_limit('add_single', 30, 60);

        $pid = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if ($pid > 0) {
            $product = wc_get_product($pid);
            if ($product && $product->is_purchasable() && $product->is_in_stock()) {
                if (WC()->session && !WC()->session->has_session()) {
                    WC()->session->set_customer_session_cookie(true);
                }
                $cart_item_key = WC()->cart->add_to_cart($pid, 1);
                if ($cart_item_key) {
                    ob_start();
                    woocommerce_mini_cart();
                    $mini_cart = ob_get_clean();

                    wp_send_json_success(array(
                        'message'    => 'محصول با موفقیت به سبد افزوده شد.',
                        'cart_count' => WC()->cart->get_cart_contents_count(),
                        'cart_url'   => wc_get_cart_url(),
                        'cart_hash'  => WC()->cart->get_cart_hash(),
                        'fragments'  => apply_filters('woocommerce_add_to_cart_fragments', array(
                            'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
                        )),
                    ));
                } else {
                    $notices = wc_get_notices('error');
                    $notice_msg = !empty($notices) ? wp_strip_all_tags(reset($notices)['notice']) : 'امکان افزودن این کالا به سبد خرید وجود ندارد.';
                    wc_clear_notices();
                    wp_send_json_error(array('message' => $notice_msg));
                }
            } else {
                wp_send_json_error(array('message' => 'این کالا در حال حاضر موجود یا قابل سفارش نیست.'));
            }
        }
        wp_send_json_error(array('message' => 'شناسه محصول نامعتبر است.'));
    }

    /**
     * افزودن ۱ کلیکی آپسل به سفارش جاری (محافظت کامل از IDOR و جلوگیری از ثبت تکراری)
     */
    public function process_thankyou_upsell() {
        check_ajax_referer('fws_prediction_nonce', 'nonce');
        $this->check_rate_limit('thankyou_upsell', 10, 60);

        $order_id   = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key  = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        $order   = wc_get_order($order_id);
        $product = wc_get_product($product_id);

        if (!$order || !$product) {
            wp_send_json_error(array('message' => 'اطلاعات سفارش یا کالا نامعتبر است.'));
        }

        // Strict Anti-IDOR Authorization Check: Verify either authenticated customer ID or cryptographic Order Key
        $current_user_id = get_current_user_id();
        $is_owner  = ($current_user_id > 0 && $order->get_user_id() === $current_user_id);
        $valid_key = (!empty($order_key) && hash_equals($order->get_order_key(), $order_key));

        if (!$is_owner && !$valid_key && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'خطای امنیتی: دسترسی غیرمجاز به این سفارش.'));
        }

        // Only allow modification for orders in modifiable status
        if (!in_array($order->get_status(), array('pending', 'on-hold', 'processing'))) {
            wp_send_json_error(array('message' => 'امکان تغییر سفارش با وضعیت فعلی آن وجود ندارد.'));
        }

        // Idempotency: Prevent duplicate addition via order meta and existing order items
        $meta_flag = '_fws_upsell_added_' . $product_id;
        if ($order->get_meta($meta_flag)) {
            wp_send_json_error(array('message' => 'این محصول قبلاً به سفارش شما افزوده شده است.'));
        }
        foreach ($order->get_items() as $existing_item) {
            if ($existing_item->get_product_id() === $product_id || $existing_item->get_variation_id() === $product_id) {
                wp_send_json_error(array('message' => 'این محصول قبلاً در این سفارش ثبت شده است.'));
            }
        }

        // Stock & purchasable verification
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            wp_send_json_error(array('message' => 'متأسفانه موجودی این کالا در انبار به اتمام رسیده است.'));
        }

        // نسخه ۲.۷ — ضد دستکاری تخفیف: فقط کالایی که موتور پیش‌بینی واقعاً برای این سفارش
        // پیشنهاد داده است با تخفیف آپسل قابل افزودن است؛ ارسال product_id دلخواه رد می‌شود.
        $engine         = FWS_Prediction_Engine::get_instance();
        $expected_upsell = $engine->get_post_purchase_upsell($order_id);
        if (!$expected_upsell || (int) $expected_upsell['product_id'] !== $product_id) {
            wp_send_json_error(array('message' => 'این کالا جزو پیشنهادهای اختصاصی سیستم برای سفارش شما نیست.'));
        }

        // Calculate discounted price
        $discount_pct = max(0, min(90, floatval(FWS_Settings::get('upsell_discount', 20))));
        $regular_price = (float) $product->get_price();
        $discounted_price = round($regular_price * ((100 - $discount_pct) / 100), wc_get_price_decimals());

        $item_id = $order->add_product($product, 1, array(
            'subtotal' => $discounted_price,
            'total'    => $discounted_price,
        ));

        if (!$item_id) {
            wp_send_json_error(array('message' => 'خطا در الحاق محصول به سفارش. لطفاً مجدداً تلاش کنید.'));
        }

        // Mark as added and record customer note
        $order->update_meta_data($meta_flag, current_time('mysql'));
        $order->calculate_totals();
        $order->add_order_note(sprintf(
            'محصول مکمل «%s» با %s٪ تخفیف اختصاصی (%s) از طریق سیستم پیشنهاد هوشمند به سفارش افزوده شد.',
            $product->get_name(),
            $discount_pct,
            wc_price($discounted_price)
        ));
        $order->save();

        wp_send_json_success(array(
            'message'   => 'کالای مکمل با موفقیت و با تخفیف ویژه به سفارش شما اضافه شد!',
            'new_total' => $order->get_formatted_order_total(),
        ));
    }

    /**
     * بازسازی الگوها با احراز هویت ادمین
     */
    public function recalculate_rules() {
        check_ajax_referer('fws_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'عدم دسترسی مجاز.'));
        }

        if (get_transient('fws_mining_lock')) {
            wp_send_json_error(array('message' => 'عملیات تحلیل داده‌ها هم‌اکنون در پس‌زمینه در حال اجرا است. لطفاً شکیبا باشید.'));
        }

        $count = FWS_Database_Miner::run_market_basket_analysis();
        wp_send_json_success(array('rules_count' => $count));
    }

    /**
     * بهینه‌سازی دیتابیس با احراز هویت ادمین
     */
    public function optimize_database() {
        check_ajax_referer('fws_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'عدم دسترسی مجاز.'));
        }

        FWS_Performance_Optimizer::optimize_database_tables();
        wp_send_json_success(array('message' => 'جداول با موفقیت Defragment، ایندکس‌ها بهینه‌سازی و ترنزینت‌های منقضی پاکسازی شدند.'));
    }
}
