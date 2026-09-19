<?php
/**
 * Class FWS_Display_Hooks
 * تزریق ویجت‌های فرانت‌اند ووکامرس برای نمایش پیشنهادات دیتابیس
 * نسخه ۲.۸: همه تزریق‌های خودکار از گیت شخصی‌سازی ظاهر (FWS_Style_Manager) عبور می‌کنند؛
 * شورت‌کدها به‌عنوان انتخاب آگاهانه مدیر همیشه رندر می‌شوند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Display_Hooks {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Enqueue Assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Single Product Page Recommendation Box (از گیت شخصی‌سازی ظاهر عبور می‌کند)
        add_action('woocommerce_after_single_product_summary', array($this, 'maybe_render_product_recommendations'), 25);

        // Cart Page Upsell Box
        add_action('woocommerce_after_cart_table', array($this, 'maybe_render_cart_recommendations'), 15);

        // Sales Booster 1: Post-Purchase 1-Click Upsell in Thank-You Page
        add_action('woocommerce_thankyou', array($this, 'maybe_render_thank_you_upsell'), 10);

        // Sales Booster 2: Dynamic Free Shipping Progress Bar with Smart Fillers
        add_action('woocommerce_before_cart_table', array($this, 'maybe_render_free_shipping_progress_bar'), 5);

        // Sales Booster 3: Exit-Intent Cart Abandonment Rescue Modal
        add_action('wp_footer', array($this, 'maybe_render_exit_intent_rescue_modal'));

        // Customer Account Next Purchase Prediction
        add_action('woocommerce_account_dashboard', array($this, 'maybe_render_my_account_prediction'), 10);

        // Growth Tool 9: Predictive Search Booster (ارتقای نتایج جستجو با پرفروش‌ترین کالای مکمل)
        add_action('woocommerce_before_shop_loop', array($this, 'maybe_render_search_complementary_banner'), 12);
        add_filter('the_posts', array($this, 'inject_complementary_into_search_results'), 10, 2);

        // Shortcode support for Gutenberg, Elementor, and custom themes
        add_shortcode('fws_predicted_products', array($this, 'shortcode_handler'));
        add_shortcode('fws_bundle', array($this, 'render_bundle_shortcode'));
        add_shortcode('fws_free_shipping_bar', array($this, 'render_free_shipping_shortcode'));
        add_shortcode('fws_cart_recommendations', array($this, 'render_cart_recommendations_shortcode'));
    }

    /* ───── نسخه ۲.۸: گیت شخصی‌سازی ظاهر برای تزریق‌های خودکار ─────
     * هر ویجت فقط در صورتی رندر می‌شود که در پنل «ظاهر و شخصی‌سازی» فعال باشد؛
     * شورت‌کدها مستقل از این گیت‌ها هستند (قرار دادن شورت‌کد در صفحه = انتخاب صریح مدیر).
     * خروجی نهایی همه ویجت‌ها از فیلتر fws_widget_html عبور می‌کند تا قالب‌ها و
     * توسعه‌دهندگان بتوانند HTML را کاملاً بازنویسی یا استایل‌دهی کنند.
     */
    private function gated_output($widget_key, $callback) {
        if (!FWS_Style_Manager::component_enabled($widget_key)) {
            return;
        }
        ob_start();
        call_user_func($callback);
        echo apply_filters('fws_widget_html', ob_get_clean(), $widget_key);
    }

    public function maybe_render_product_recommendations() {
        $this->gated_output('enable_widget_product', function () {
            $this->render_product_recommendations_box();
        });
    }

    public function maybe_render_cart_recommendations() {
        $this->gated_output('enable_widget_cart', function () {
            $this->render_cart_recommendations_box();
        });
    }

    public function maybe_render_thank_you_upsell($order_id) {
        $this->gated_output('enable_widget_thankyou', function () use ($order_id) {
            $this->render_thank_you_upsell_box($order_id);
        });
    }

    public function maybe_render_free_shipping_progress_bar() {
        $this->gated_output('enable_widget_shipping', function () {
            $this->render_free_shipping_progress_bar();
        });
    }

    public function maybe_render_exit_intent_rescue_modal() {
        $this->gated_output('enable_exit_intent', function () {
            $this->render_exit_intent_rescue_modal();
        });
    }

    public function maybe_render_my_account_prediction() {
        $this->gated_output('enable_widget_account', function () {
            $this->render_my_account_prediction_widget();
        });
    }

    public function maybe_render_search_complementary_banner() {
        $this->gated_output('enable_search_banner', function () {
            $this->render_search_complementary_banner();
        });
    }

    public function enqueue_scripts() {
        if (is_admin()) return;

        // Conditional asset loading: only load scripts & styles where needed to protect PageSpeed & Core Web Vitals
        $should_load = is_product() || is_cart() || is_checkout() || is_account_page() || is_search();
        global $post;
        if (!$should_load && is_a($post, 'WP_Post')) {
            if (
                has_shortcode($post->post_content, 'fws_predicted_products') ||
                has_shortcode($post->post_content, 'fws_bundle') ||
                has_shortcode($post->post_content, 'fws_free_shipping_bar') ||
                has_shortcode($post->post_content, 'fws_cart_recommendations')
            ) {
                $should_load = true;
            } else {
                // صفحه‌سازها (مثل المنتور) شورت‌کدها را در متا ذخیره می‌کنند نه post_content
                $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
                if (is_string($elementor_data) && false !== strpos($elementor_data, 'fws_')) {
                    $should_load = true;
                }
            }
        }

        if (!$should_load) {
            return;
        }

        // نسخه ۲.۸: کلید اصلی استایل — اگر خاموش باشد هیچ CSS از افزونه لود نمی‌شود
        // (ویجت‌ها با استایل خود قالب رندر می‌شوند؛ JS عملکردی سر جایش می‌ماند)
        if (FWS_Style_Manager::styles_enabled()) {
            wp_enqueue_style('fws-recommendations', FWS_PLUGIN_URL . 'assets/css/fws-recommendations.css', array(), FWS_VERSION);
        }
        wp_enqueue_script('fws-recommendations', FWS_PLUGIN_URL . 'assets/js/fws-recommendations.js', array('jquery'), FWS_VERSION, true);

        // Defer script loading for optimal TTFB and First Contentful Paint
        add_filter('script_loader_tag', function($tag, $handle) {
            if ('fws-recommendations' === $handle && false === strpos($tag, 'defer')) {
                return str_replace(' src', ' defer="defer" src', $tag);
            }
            return $tag;
        }, 10, 2);

        wp_localize_script('fws-recommendations', 'fws_params', array(
            'ajax_url'            => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('fws_prediction_nonce'),
            'bundle_discount'     => (int) FWS_Settings::get('bundle_discount', 12),
            'free_shipping_limit' => (float) FWS_Settings::get('free_shipping_threshold', 2000000),
            'currency_symbol'     => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'تومان',
            'added_text'          => __('پکیج با موفقیت به سبد خرید اضافه شد', 'fast-woo-sale'),
        ));
    }

    /**
     * رندر باکس هوشمند محصولات خریداری‌شده با این کالا در صفحه محصول
     */
    public function render_product_recommendations_box($custom_product_id = 0) {
        $product_id = $custom_product_id > 0 ? absint($custom_product_id) : 0;
        if ($product_id <= 0) {
            global $product;
            $product_id = ($product && is_a($product, 'WC_Product')) ? $product->get_id() : 0;
        }

        $product = wc_get_product($product_id);
        if (!$product) return;

        $engine = FWS_Prediction_Engine::get_instance();
        $recommendations = $engine->get_recommendations_for_product($product->get_id(), max(1, (int) FWS_Settings::get('recs_limit', 3)));

        if (empty($recommendations)) return;

        $main_price = (float) $product->get_price();
        $total_bundle_original = $main_price;
        foreach ($recommendations as $rec) {
            $total_bundle_original += (float) $rec['price'];
        }
        $bundle_discount = max(0, min(90, (int) FWS_Settings::get('bundle_discount', 12)));
        $discount_factor = (100 - $bundle_discount) / 100;
        $total_bundle_discounted = $total_bundle_original * $discount_factor;

        // امضای HMAC سمت سرور: فقط شناسه‌های واقعاً رندرشده در این باکس مجاز به دریافت تخفیف پکیج هستند
        $official_rec_ids = array();
        foreach ($recommendations as $rec) {
            $official_rec_ids[] = (int) $rec['product_id'];
        }
        $bundle_signature = hash_hmac('sha256', $product->get_id() . '|' . implode(',', $official_rec_ids), wp_salt('auth'));
        ?>
        <div class="fws-bundle-wrapper"<?php echo FWS_Style_Manager::dir_attr(); // جهت ویجت — قابل خاموش‌کردن از تنظیمات ?>>
            <div class="fws-bundle-header">
                <span class="fws-bundle-badge">تحلیل دیتابیس سفارشات</span>
                <h3 class="fws-bundle-title">پیشنهادهای هوشمند دیتابیس (خریداری‌شده با این کالا)</h3>
                <p class="fws-bundle-subtitle">بر اساس تحلیل سوابق خرید و رفتار مشتریان این فروشگاه</p>
            </div>

            <div class="fws-bundle-items">
                <!-- Main Product -->
                <div class="fws-bundle-item is-primary">
                    <input type="checkbox" checked disabled class="fws-check-item" data-price="<?php echo esc_attr($main_price); ?>" value="<?php echo esc_attr($product->get_id()); ?>">
                    <span class="fws-item-thumb">
                        <?php echo $product->get_image('thumbnail', array('loading' => 'lazy', 'decoding' => 'async')); ?>
                    </span>
                    <div class="fws-item-info">
                        <span class="fws-item-tag">محصول فعلی</span>
                        <strong class="fws-item-name"><?php echo esc_html($product->get_name()); ?></strong>
                        <span class="fws-item-price"><?php echo wc_price($main_price); ?></span>
                    </div>
                </div>

                <div class="fws-plus-sign">+</div>

                <!-- Recommended Co-Occurrence Products -->
                <?php foreach ($recommendations as $idx => $rec): ?>
                    <div class="fws-bundle-item is-recommended" data-product-id="<?php echo esc_attr($rec['product_id']); ?>">
                        <input type="checkbox" checked class="fws-check-item" data-price="<?php echo esc_attr($rec['price']); ?>" value="<?php echo esc_attr($rec['product_id']); ?>">
                        <span class="fws-item-thumb">
                            <img src="<?php echo esc_url($rec['image']); ?>" alt="<?php echo esc_attr($rec['name']); ?>" loading="lazy" decoding="async" width="56" height="56">
                        </span>
                        <div class="fws-item-info">
                            <?php if (FWS_Style_Manager::show_confidence_tags()): ?>
                            <?php if (!empty($rec['is_fallback'])): ?>
                                <!-- صداقت آماری: فال‌بک دسته‌بندی هیچ درصد همبستگی ساختگی نمایش نمی‌دهد -->
                                <span class="fws-confidence-tag is-fallback"><?php echo esc_html(FWS_Style_Manager::style_text('پیشنهاد فروشگاه برای شما')); ?></span>
                            <?php elseif (!empty($rec['is_manual'])): ?>
                                <span class="fws-confidence-tag is-manual"><?php echo esc_html(FWS_Style_Manager::style_text('پیشنهاد مدیر فروشگاه (' . $rec['confidence'] . '٪ اولویت)')); ?></span>
                            <?php else: ?>
                                <span class="fws-confidence-tag">
                                    <?php echo esc_html($rec['confidence']); ?>٪ سفارشات مشترک
                                </span>
                            <?php endif; ?>
                            <?php endif; ?>
                            <strong class="fws-item-name"><?php echo esc_html($rec['name']); ?></strong>
                            <span class="fws-item-price"><?php echo wc_price($rec['price']); ?></span>
                        </div>
                    </div>
                    <?php if ($idx < count($recommendations) - 1): ?>
                        <div class="fws-plus-sign">+</div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Bundle Action Bar -->
            <div class="fws-bundle-action-bar">
                <div class="fws-pricing-breakdown">
                    <span class="fws-label">قیمت کل پکیج با تخفیف هوشمند (<?php echo esc_html($bundle_discount); ?>٪):</span>
                    <div class="fws-prices">
                        <del class="fws-original-price"><?php echo wc_price($total_bundle_original); ?></del>
                        <strong class="fws-discounted-price"><?php echo wc_price($total_bundle_discounted); ?></strong>
                    </div>
                </div>
                <button type="button" class="fws-add-bundle-btn"
                        data-main-id="<?php echo esc_attr($product->get_id()); ?>"
                        data-bundle-ids="<?php echo esc_attr(implode(',', $official_rec_ids)); ?>"
                        data-bundle-sig="<?php echo esc_attr($bundle_signature); ?>">
                    <?php echo esc_html(FWS_Style_Manager::style_text('⚡ افزودن پکیج هوشمند به سبد خرید')); ?>
                </button>
            </div>
        </div>
        <?php
    }

    public function render_cart_recommendations_box() {
        if (!WC()->cart || WC()->cart->is_empty()) return;

        $cart_product_ids = array();
        foreach (WC()->cart->get_cart() as $item) {
            $cart_product_ids[] = absint($item['product_id']);
        }

        $engine = FWS_Prediction_Engine::get_instance();
        $recommendations = $engine->get_cart_recommendations($cart_product_ids, 3);

        if (empty($recommendations)) return;
        ?>
        <div class="fws-cart-recommendations-wrapper"<?php echo FWS_Style_Manager::dir_attr(); ?>>
            <h4 class="fws-cart-title"><?php echo esc_html(FWS_Style_Manager::style_text('🛍️ مشتریانی که این سبد را خریدند، این کالاها را نیز تهیه کردند:')); ?></h4>
            <div class="fws-cart-grid">
                <?php foreach ($recommendations as $rec): ?>
                    <div class="fws-cart-item">
                        <img src="<?php echo esc_url($rec['image']); ?>" alt="<?php echo esc_attr($rec['name']); ?>" loading="lazy" decoding="async" width="56" height="56">
                        <div class="fws-cart-item-details">
                            <strong><?php echo esc_html($rec['name']); ?></strong>
                            <span class="fws-cart-item-price"><?php echo wc_price($rec['price']); ?></span>
                            <?php if (FWS_Style_Manager::show_confidence_tags()): ?>
                                <span class="fws-cart-confidence"><?php echo esc_html($rec['confidence']); ?>٪ همبستگی خرید</span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr($rec['product_id']); ?>">+ افزودن به سبد</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Sales Booster 1: Post-Purchase 1-Click Upsell Box on Thank You Page
     */
    public function render_thank_you_upsell_box($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Security check: Only allow active/processing orders
        if (!in_array($order->get_status(), array('pending', 'on-hold', 'processing'))) {
            return;
        }

        $engine = FWS_Prediction_Engine::get_instance();
        $upsell = $engine->get_post_purchase_upsell($order_id);
        if (!$upsell) return;

        // Check if already claimed to prevent duplicate clicks
        if ($order->get_meta('_fws_upsell_added_' . $upsell['product_id'])) {
            return;
        }
        ?>
        <div class="fws-thankyou-upsell-box"<?php echo FWS_Style_Manager::dir_attr(); ?>>
            <div class="fws-thankyou-badge"><?php echo esc_html(FWS_Style_Manager::style_text('⚡ پیشنهاد اختصاصی و آنی (بدون هزینه ارسال مجدد)')); ?></div>
            <h3 class="fws-thankyou-title">پیشنهاد مکمل بر اساس اقلام سفارش داده شده شما</h3>
            <p class="fws-thankyou-desc">
                به عنوان تشکر، می‌توانید محصول <strong>«<?php echo esc_html($upsell['name']); ?>»</strong> را با <strong><?php echo esc_html($upsell['discount_percent']); ?>٪ تخفیف اختصاصی</strong> تنها با یک کلیک به همین سفارش اضافه نمایید!
            </p>
            <div class="fws-thankyou-item-row">
                <img src="<?php echo esc_url($upsell['image']); ?>" alt="<?php echo esc_attr($upsell['name']); ?>" loading="lazy" decoding="async" width="64" height="64">
                <div class="fws-thankyou-pricing">
                    <del><?php echo wc_price($upsell['regular_price']); ?></del>
                    <strong><?php echo wc_price($upsell['discounted_price']); ?></strong>
                    <?php if (FWS_Style_Manager::show_confidence_tags()): ?>
                        <span class="fws-thankyou-tag"><?php echo esc_html($upsell['confidence']); ?>٪ همبستگی با سبد شما</span>
                    <?php endif; ?>
                </div>
                <button type="button" class="fws-thankyou-claim-btn" 
                        data-order-id="<?php echo esc_attr($order_id); ?>" 
                        data-order-key="<?php echo esc_attr($order->get_order_key()); ?>" 
                        data-product-id="<?php echo esc_attr($upsell['product_id']); ?>">
                    <?php echo esc_html(FWS_Style_Manager::style_text('✅ افزودن آنی به سفارش جاری')); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Sales Booster 2: Dynamic Free Shipping Progress Bar with Smart Fillers
     */
    public function render_free_shipping_progress_bar() {
        if (!WC()->cart) return;

        $threshold = (float) FWS_Settings::get('free_shipping_threshold', 2000000);
        if ($threshold <= 0) return;

        $cart_total = (float) (WC()->cart ? WC()->cart->get_subtotal() : 0);
        $percentage = min(100, round(($cart_total / $threshold) * 100));
        $remaining = max(0, $threshold - $cart_total);

        $engine = FWS_Prediction_Engine::get_instance();
        $fillers = ($remaining > 0) ? $engine->get_free_shipping_fillers($cart_total, $threshold) : array();
        ?>
        <div class="fws-shipping-bar-wrapper"<?php echo FWS_Style_Manager::dir_attr(); ?>>
            <div class="fws-shipping-status">
                <?php if ($remaining <= 0): ?>
                    <span class="fws-shipping-success"><?php echo esc_html(FWS_Style_Manager::style_text('🎉 تبریک! سفارش شما مشمول ارسال کاملاً رایگان شد!')); ?></span>
                <?php else: ?>
                    <span>تنها <strong><?php echo wc_price($remaining); ?></strong> تا <strong>ارسال کاملاً رایگان</strong> سفارش شما مانده!</span>
                <?php endif; ?>
                <span class="fws-shipping-percent"><?php echo esc_html($percentage); ?>٪</span>
            </div>
            <div class="fws-progress-track">
                <div class="fws-progress-fill" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
            </div>

            <?php if (!empty($fillers)): ?>
                <div class="fws-fillers-row">
                    <span class="fws-fillers-title">کالاهای پیشنهادی متناسب با سبد برای رسیدن به سقف ارسال رایگان:</span>
                    <div class="fws-fillers-list">
                        <?php foreach ($fillers as $fil): ?>
                            <div class="fws-filler-card">
                                <img src="<?php echo esc_url($fil['image']); ?>" alt="<?php echo esc_attr($fil['name']); ?>" loading="lazy" decoding="async" width="48" height="48">
                                <div class="fws-filler-info">
                                    <span class="fws-filler-name"><?php echo esc_html($fil['name']); ?></span>
                                    <span class="fws-filler-price"><?php echo wc_price($fil['price']); ?></span>
                                </div>
                                <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr($fil['product_id']); ?>">+ افزودن</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Sales Booster 3: Exit-Intent Rescue Modal
     * نسخه صادقانه: تنها در صورت پیکربندی کد تخفیف واقعی از پنل، وعده تخفیف نمایش داده می‌شود؛
     * در غیر این صورت متن بدون وعده تخفیف نمایش داده می‌شود (رفع وعده تخفیف بی‌پشتوانه).
     */
    public function render_exit_intent_rescue_modal() {
        if ('yes' !== FWS_Settings::get('enable_exit_intent', 'yes')) return;
        if (!is_cart() && !is_checkout()) return;
        if (!WC()->cart || WC()->cart->is_empty()) return;

        $coupon_code = trim((string) FWS_Settings::get('exit_intent_coupon', ''));
        $has_coupon  = ('' !== $coupon_code);
        ?>
        <div id="fws-exit-intent-modal" class="fws-modal" style="display:none;"<?php echo FWS_Style_Manager::dir_attr(); ?> role="dialog" aria-modal="true" aria-labelledby="fws-modal-title">
            <div class="fws-modal-content">
                <button type="button" class="fws-modal-close" aria-label="بستن">&times;</button>
                <div class="fws-modal-header">
                    <?php if ($has_coupon): ?>
                        <span class="fws-modal-badge"><?php echo esc_html(FWS_Style_Manager::style_text('🎁 هدیه پایانی')); ?></span>
                        <h3 id="fws-modal-title">آیا قبل از تکمیل سفارش قصد خروج دارید؟</h3>
                        <p>همین حالا سفارش خود را تکمیل کنید؛ کد تخفیف <strong>«<?php echo esc_html($coupon_code); ?>»</strong> به‌صورت خودکار روی سبد شما اعمال می‌شود.</p>
                    <?php else: ?>
                        <span class="fws-modal-badge"><?php echo esc_html(FWS_Style_Manager::style_text('🛒 سبد خرید شما آماده است')); ?></span>
                        <h3 id="fws-modal-title">آیا قبل از تکمیل سفارش قصد خروج دارید؟</h3>
                        <p>اقلام انتخابی شما در سبد خرید محفوظ می‌ماند؛ هر زمان که آماده بودید می‌توانید خرید خود را تکمیل کنید.</p>
                    <?php endif; ?>
                </div>
                <div class="fws-modal-actions">
                    <?php if ($has_coupon): ?>
                        <button type="button" class="fws-modal-confirm-btn fws-modal-coupon-btn">اعمال تخفیف و تکمیل خرید</button>
                    <?php else: ?>
                        <a href="<?php echo wc_get_checkout_url(); ?>" class="fws-modal-confirm-btn">ادامه فرآیند خرید</a>
                    <?php endif; ?>
                    <button type="button" class="fws-modal-dismiss-btn">خیر، انصراف</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_my_account_prediction_widget() {
        if (!is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $engine = FWS_Prediction_Engine::get_instance();
        $prediction = $engine->get_user_next_purchase_prediction($user_id);
        if ($prediction) {
            ?>
            <div class="fws-account-prediction-box"<?php echo FWS_Style_Manager::dir_attr(); ?>>
                <div class="fws-account-prediction-badge"><?php echo esc_html(FWS_Style_Manager::style_text('✨ پیش‌بینی هوشمند سفارش بعدی شما')); ?></div>
                <h4 class="fws-account-title">کالای متناسب با سلیقه و سوابق خرید شما:</h4>
                <div class="fws-account-item">
                    <img src="<?php echo esc_url($prediction['image']); ?>" alt="<?php echo esc_attr($prediction['name']); ?>" loading="lazy" decoding="async" width="56" height="56">
                    <div class="fws-account-item-info">
                        <strong><?php echo esc_html($prediction['name']); ?></strong>
                        <span class="fws-account-item-price"><?php echo wc_price($prediction['price']); ?></span>
                        <?php if (FWS_Style_Manager::show_confidence_tags()): ?>
                            <span class="fws-account-item-conf"><?php echo esc_html($prediction['confidence']); ?>٪ احتمال علاقه‌مندی شما</span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr($prediction['product_id']); ?>">+ افزودن سریع به سبد</button>
                </div>
            </div>
            <?php
        }
    }

    /**
     * شورت‌کد اختصاصی جهت نمایش پیشنهادهای کالا در هر بخش دلخواه قالب یا صفحه‌ساز
     * نحوه استفاده: [fws_predicted_products id="123" limit="3" title="کالاهای پیشنهادی"]
     */
    public function shortcode_handler($atts) {
        $atts = shortcode_atts(array(
            'id'    => 0,
            'limit' => 3,
            'title' => 'پیشنهادهای هوشمند دیتابیس (خریداری‌شده با این کالا)',
        ), $atts, 'fws_predicted_products');

        $product_id = absint($atts['id']);
        if ($product_id <= 0) {
            global $product;
            if ($product && is_a($product, 'WC_Product')) {
                $product_id = $product->get_id();
            }
        }

        if ($product_id <= 0) return '';

        $engine = FWS_Prediction_Engine::get_instance();
        $recommendations = $engine->get_recommendations_for_product($product_id, absint($atts['limit']));
        if (empty($recommendations)) return '';

        ob_start();
        ?>
        <div class="fws-cart-recommendations-wrapper fws-shortcode-wrapper"<?php echo FWS_Style_Manager::dir_attr(); ?>>
            <h4 class="fws-cart-title"><?php echo esc_html(FWS_Style_Manager::style_text($atts['title'])); ?></h4>
            <div class="fws-cart-grid">
                <?php foreach ($recommendations as $rec): ?>
                    <div class="fws-cart-item">
                        <img src="<?php echo esc_url($rec['image']); ?>" alt="<?php echo esc_attr($rec['name']); ?>" loading="lazy" decoding="async" width="56" height="56">
                        <div class="fws-cart-item-details">
                            <strong><?php echo esc_html($rec['name']); ?></strong>
                            <span class="fws-cart-item-price"><?php echo wc_price($rec['price']); ?></span>
                            <?php if (FWS_Style_Manager::show_confidence_tags()): ?>
                            <?php if (!empty($rec['is_fallback'])): ?>
                                <span class="fws-cart-confidence is-fallback"><?php echo esc_html(FWS_Style_Manager::style_text('پیشنهاد فروشگاه برای شما')); ?></span>
                            <?php elseif (!empty($rec['is_manual'])): ?>
                                <span class="fws-cart-confidence is-manual"><?php echo esc_html(FWS_Style_Manager::style_text('پیشنهاد مدیر فروشگاه (' . $rec['confidence'] . '٪ اولویت)')); ?></span>
                            <?php else: ?>
                                <span class="fws-cart-confidence"><?php echo esc_html($rec['confidence']); ?>٪ تطابق سفارشات</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr($rec['product_id']); ?>">+ افزودن به سبد</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return apply_filters('fws_widget_html', ob_get_clean(), 'shortcode_predicted_products');
    }

    /**
     * شورت‌کد نمایش پکیج هوشمند کالا در هر صفحه یا تب دلخواه: [fws_bundle id="123"]
     */
    public function render_bundle_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'fws_bundle');

        $product_id = absint($atts['id']);
        if ($product_id <= 0 && function_exists('is_product') && is_product()) {
            global $product;
            $product_id = ($product && is_a($product, 'WC_Product')) ? $product->get_id() : 0;
        }

        if ($product_id <= 0) return '';

        ob_start();
        $this->render_product_recommendations_box($product_id);
        return apply_filters('fws_widget_html', ob_get_clean(), 'shortcode_bundle');
    }

    /**
     * شورت‌کد نوار پیشرفت ارسال رایگان: [fws_free_shipping_bar]
     */
    public function render_free_shipping_shortcode($atts) {
        ob_start();
        $this->render_free_shipping_progress_bar();
        return apply_filters('fws_widget_html', ob_get_clean(), 'shortcode_shipping_bar');
    }

    /**
     * شورت‌کد پیشنهادات متناسب با سبد خرید: [fws_cart_recommendations]
     */
    public function render_cart_recommendations_shortcode($atts) {
        ob_start();
        $this->render_cart_recommendations_box();
        return apply_filters('fws_widget_html', ob_get_clean(), 'shortcode_cart_recommendations');
    }

    /**
     * ارتقای هوشمند نتایج جستجو: تزریق پرفروش‌ترین کالای مکمل خریداری‌شده در کنار محصول جستجوشده به حلقه نتایج
     * مثال کاربر: خریدار لپ‌تاپ هنگام سرچ لپ‌تاپ، پرفروش‌ترین ماوس مکمل را بالاتر در نتایج مشاهده می‌کند
     */
    public function inject_complementary_into_search_results($posts, $query) {
        if ('yes' !== FWS_Settings::get('enable_search_injection', 'yes')) {
            return $posts;
        }
        if (!is_search() || is_admin() || !$query->is_main_query()) {
            return $posts;
        }

        // فقط جستجوهای مرتبط با محصولات؛ نتایج وبلاگ/صفحات دست‌نخورده می‌مانند
        $q_post_type = $query->get('post_type');
        if (!empty($q_post_type) && 'product' !== $q_post_type && !(is_array($q_post_type) && in_array('product', $q_post_type, true))) {
            return $posts;
        }

        // تزریق فقط در صفحه اول نتایج: صفحات بعدی pagination نتیجه تکراری می‌بینند و شمارش صفحه به‌هم می‌ریخت
        $paged = (int) $query->get('paged');
        if ($paged > 1) {
            return $posts;
        }

        $search_query = get_search_query();
        if (empty($search_query) || empty($posts)) {
            return $posts;
        }

        $engine = FWS_Prediction_Engine::get_instance();
        // همان کلید کش بنر (limit=2) بازاستفاده می‌شود تا یک کوئری اضافی حذف شود
        $complements = $engine->get_search_co_occurrence_recommendations($search_query, 2);
        if (empty($complements)) {
            return $posts;
        }

        $complement_id = absint($complements[0]['product_id']);
        $existing_ids = wp_list_pluck($posts, 'ID');

        // اگر محصول مکمل در نتایج اولیه نیست، آن را در رتبه دوم نتایج تزریق کن
        // (نکته: تخصیص پراپرتی داینامیک به WP_Post در PHP 8.2+ منسوخ است؛ بنر دلیل پیشنهاد را جداگانه نمایش می‌دهد)
        if (!in_array($complement_id, $existing_ids, true)) {
            $complement_post = get_post($complement_id);
            if ($complement_post) {
                array_splice($posts, 1, 0, array($complement_post));
            }
        }

        return $posts;
    }

    /**
     * رندر بنر هوشمند پیشنهاد کالای مکمل پرفروش در بالای لیست نتایج جستجو
     */
    public function render_search_complementary_banner() {
        if (!is_search()) return;
        // نسخه ۲.۸: گیت مستقل بنر جستجو (جدای تزریق نتایج)
        if (!FWS_Style_Manager::component_enabled('enable_search_banner')) return;

        $search_query = get_search_query();
        if (empty($search_query)) return;

        $engine = FWS_Prediction_Engine::get_instance();
        $complements = $engine->get_search_co_occurrence_recommendations($search_query, 2);
        if (empty($complements)) return;

        ?>
        <div class="fws-search-booster-banner"<?php echo FWS_Style_Manager::dir_attr(); ?>>
            <div class="fws-search-booster-header">
                <span class="fws-search-badge"><?php echo esc_html(FWS_Style_Manager::style_text('🎯 پیشنهاد هوشمند خریداران قبلی')); ?></span>
                <h3 class="fws-search-title">خریداران «<?php echo esc_html($search_query); ?>» معمولاً این کالای مکمل را نیز خریده‌اند:</h3>
                <p class="fws-search-subtitle">بر اساس تحلیل داده‌کاوی سبدهای خرید، این محصول بیشترین نرخ خرید همزمان را داشته است.</p>
            </div>
            <div class="fws-search-items-row">
                <?php foreach ($complements as $item): ?>
                    <div class="fws-search-item-card">
                        <div class="fws-search-item-body">
                            <a href="<?php echo esc_url(get_permalink($item['product_id'])); ?>" class="fws-search-item-thumb">
                                <img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['name']); ?>" loading="lazy" decoding="async" width="60" height="60">
                            </a>
                            <div class="fws-search-item-info">
                                <?php if (FWS_Style_Manager::show_confidence_tags()): ?>
                                    <span class="fws-search-co-tag"><?php echo esc_html(FWS_Style_Manager::style_text('🔥 ' . $item['confidence'] . '٪ سفارش همزمان')); ?></span>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(get_permalink($item['product_id'])); ?>" class="fws-search-name">
                                    <strong><?php echo esc_html($item['name']); ?></strong>
                                </a>
                                <span class="fws-search-reason"><?php echo esc_html($item['reason']); ?></span>
                                <div class="fws-search-action-bar">
                                    <span class="fws-search-price"><?php echo wc_price($item['price']); ?></span>
                                    <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr($item['product_id']); ?>">
                                        + افزودن فوری به سبد
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
