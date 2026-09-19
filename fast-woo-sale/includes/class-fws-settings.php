<?php
/**
 * Class FWS_Settings
 * مرکز مدیریت تنظیمات افزونه — حذف کامل مقادیر هاردکد از سراسر سیستم
 * تمام آستانه‌ها (تخفیف‌ها، حداقل اطمینان، سقف ارسال رایگان و...) از این کلاس خوانده می‌شوند.
 *
 * نسخه ۲.۷: افزودن استراتژی موتور پیشنهاددهنده (خودکار/ترکیبی/دستی)،
 * قوانین دستی مدیر (Pin) و لیست سیاه محصولات (Blacklist)
 *
 * نسخه ۲.۸: سیستم کامل شخصی‌سازی ظاهر — کلید خاموش/روشن برای همه استایل‌ها و
 * ویجت‌ها، سه پیش‌تنظیم نمایشی (پیش‌فرض/مینیمال/هماهنگ با قالب)، پالت رنگ،
 * تایپوگرافی و CSS سفارشی. هدف: صفر کردن تضاد ظاهری افزونه با قالب سایت.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Settings {

    const OPTION_KEY = 'fws_prediction_settings';

    const MODE_AUTOMATIC = 'automatic_only';
    const MODE_HYBRID    = 'hybrid';
    const MODE_MANUAL    = 'manual_only';

    const PRESET_DEFAULT = 'default';
    const PRESET_MINIMAL = 'minimal';
    const PRESET_THEME   = 'theme';

    /**
     * کلیدهای ویجت‌های تزریق‌شونده در فرانت‌اند (هرکدام یک کلید خاموش/روشن مستقل دارند)
     */
    public static function widget_keys() {
        return array(
            'enable_widget_product'  => 'باکس پکیج هوشمند در صفحه محصول',
            'enable_widget_cart'     => 'پیشنهادات مکمل در صفحه سبد خرید',
            'enable_widget_thankyou' => 'آپسل یک‌کلیکی صفحه تشکر',
            'enable_widget_shipping' => 'نوار پیشرفت ارسال رایگان',
            'enable_widget_account'  => 'ویجت پیش‌بینی خرید بعدی (حساب کاربری)',
            'enable_search_banner'   => 'بنر پیشنهاد هوشمند در نتایج جستجو',
        );
    }

    /**
     * مقادیر پیش‌فرض سازگار با نسخه ۲.۵.۰ (رفتار قبلی افزونه)
     */
    public static function defaults() {
        return array(
            'bundle_discount'         => 12,               // درصد تخفیف پکیج هوشمند صفحه محصول
            'upsell_discount'         => 20,               // درصد تخفیف آپسل یک‌کلیکی صفحه تشکر
            'min_confidence'          => 60,               // حداقل ضریب اطمینان (Confidence) قوانین همبستگی
            'min_support'             => 3,                // حداقل تعداد خرید مشترک یک جفت‌کالا
            'free_shipping_threshold' => 2000000,          // سقف مبلغ ارسال رایگان (تومان)
            'lookback_days'           => 90,               // بازه تحلیل سفارشات تاریخی (روز)
            'recs_limit'              => 3,                // تعداد پیشنهادات هر ویجت
            'enable_fallback'         => 'yes',            // فعال بودن فال‌بک دسته‌بندی (Cold-Start)
            'enable_exit_intent'      => 'yes',            // فعال بودن مودال خروج
            'enable_search_injection' => 'yes',            // فعال بودن تزریق مکمل در نتایج جستجو
            'exit_intent_coupon'      => '',               // کد تخفیف واقعی مودال خروج (خالی = بدون وعده تخفیف)
            // ——— فیلدهای تخصصی (نسخه ۲.۷) ———
            'manual_override_mode'    => self::MODE_AUTOMATIC, // استراتژی موتور پیشنهاددهنده
            'manual_rules'            => array(),          // قوانین دستی مدیر: [ ['source'=>id,'target'=>id,'confidence'=>95], ... ]
            'product_blacklist'       => array(),          // شناسه محصولات ممنوعه در پیشنهادها
            // ——— ظاهر و شخصی‌سازی (نسخه ۲.۸) ———
            'style_master_enable'     => 'yes',            // کلید اصلی: بارگذاری CSS افزونه در فرانت‌اند
            'style_preset'            => self::PRESET_DEFAULT, // پیش‌تنظیم نمایشی: default / minimal / theme
            'enable_widget_product'   => 'yes',            // باکس پکیج صفحه محصول
            'enable_widget_cart'      => 'yes',            // پیشنهادات سبد خرید
            'enable_widget_thankyou'  => 'yes',            // آپسل صفحه تشکر
            'enable_widget_shipping'  => 'yes',            // نوار ارسال رایگان
            'enable_widget_account'   => 'yes',            // ویجت حساب کاربری
            'enable_search_banner'    => 'yes',            // بنر بالای نتایج جستجو
            'accent_color'            => '#f97316',        // رنگ اصلی دکمه‌ها و تاکیدها
            'accent_text_color'       => '#ffffff',        // رنگ متن روی رنگ اصلی
            'badge_bg_color'          => '#ffedd5',        // پس‌زمینه بج‌ها
            'badge_text_color'        => '#f97316',        // متن بج‌ها
            'box_bg_color'            => '#ffffff',        // پس‌زمینه کارت‌ها
            'box_border_color'        => '#e2e8f0',        // حاشیه کارت‌ها
            'inherit_theme_font'      => 'yes',            // استفاده از فونت قالب (هیچ فونتی از افزونه تحمیل نمی‌شود)
            'force_rtl'               => 'yes',            // تحمیل جهت RTL به ویجت‌ها (قالب‌های چپ‌چین خاموش کنند)
            'border_radius'           => 16,               // گردی گوشه‌های کارت‌ها (px)
            'base_font_size'          => 14,               // اندازه پایه متن ویجت‌ها (px)
            'hide_confidence_tags'    => 'no',             // مخفی‌سازی برچسب‌های درصد اطمینان
            'show_emojis'             => 'yes',            // نمایش ایموجی‌ها در متن ویجت‌ها
            'custom_css'              => '',               // CSS سفارشی مدیر فروشگاه
        );
    }

    /**
     * دریافت کامل تنظیمات ذخیره‌شده merged با پیش‌فرض‌ها
     */
    /**
     * In-request memo of the merged settings (BUG-16 fix v2.8.1).
     * get() is called dozens of times per render; previously each call re-read the option and
     * re-ran wp_parse_args(). Reset whenever the option is written (persist_key() or the
     * Settings API), see flush_memo().
     *
     * @var array|null
     */
    private static $memo = null;

    public static function all() {
        if (null === self::$memo) {
            self::$memo = wp_parse_args(self::get_saved_raw(), self::defaults());
        }
        return self::$memo;
    }

    /**
     * Drop the in-request memo. Hooked to update_option_/add_option_/delete_option_{OPTION_KEY}.
     */
    public static function flush_memo() {
        self::$memo = null;
    }

    /**
     * مقادیر خام ذخیره‌شده (بدون merge با پیش‌فرض‌ها)
     */
    public static function get_saved_raw() {
        $saved = get_option(self::OPTION_KEY, array());
        return is_array($saved) ? $saved : array();
    }

    /**
     * خواندن یک مقدار مشخص از تنظیمات
     */
    public static function get($key, $fallback = null) {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            return $fallback;
        }
        return $all[$key];
    }

    /**
     * ذخیره‌سازی بخشی از تنظیمات بدون دست‌زدن به بقیه کلیدها (برای مسیر AJAX پنل مدیریت)
     */
    public static function persist_key($key, $value) {
        $all          = self::get_saved_raw();
        $all[$key]    = $value;
        $result       = update_option(self::OPTION_KEY, $all, false);
        self::flush_memo();
        return $result;
    }

    /**
     * پاکسازی و اعتبارسنجی خروجی فرم تنظیمات قبل از ذخیره در دیتابیس
     */
    public static function sanitize($input) {
        $input    = is_array($input) ? $input : array();
        $defaults = self::defaults();
        $saved    = self::get_saved_raw();
        $clean    = array();

        // مقادیر عددی صحیح
        $int_keys = array(
            'bundle_discount',
            'upsell_discount',
            'min_confidence',
            'min_support',
            'free_shipping_threshold',
            'lookback_days',
            'recs_limit',
        );
        foreach ($int_keys as $key) {
            $clean[$key] = isset($input[$key]) ? absint($input[$key]) : (int) $defaults[$key];
        }

        // محدودسازی بازه‌های مجاز جهت جلوگیری از پیکربندی غلط
        $clean['bundle_discount']         = min(90, max(0, $clean['bundle_discount']));
        $clean['upsell_discount']         = min(90, max(0, $clean['upsell_discount']));
        $clean['min_confidence']          = min(100, max(1, $clean['min_confidence']));
        $clean['min_support']             = min(1000, max(1, $clean['min_support']));
        $clean['free_shipping_threshold'] = max(0, $clean['free_shipping_threshold']);
        $clean['lookback_days']           = min(365, max(7, $clean['lookback_days']));
        $clean['recs_limit']              = min(6, max(1, $clean['recs_limit']));

        // چک‌باکس‌ها (yes/no)
        $checkbox_keys = array(
            'enable_fallback',
            'enable_exit_intent',
            'enable_search_injection',
            // ——— ظاهر و شخصی‌سازی (نسخه ۲.۸) ———
            'style_master_enable',
            'enable_widget_product',
            'enable_widget_cart',
            'enable_widget_thankyou',
            'enable_widget_shipping',
            'enable_widget_account',
            'enable_search_banner',
            'inherit_theme_font',
            'force_rtl',
            'hide_confidence_tags',
            'show_emojis',
        );
        foreach ($checkbox_keys as $key) {
            $clean[$key] = (isset($input[$key]) && 'yes' === $input[$key]) ? 'yes' : 'no';
        }

        // ——— پالت رنگ (نسخه ۲.۸): فقط کد رنگ HEX معتبر ———
        $color_keys = array(
            'accent_color'      => '#f97316',
            'accent_text_color' => '#ffffff',
            'badge_bg_color'    => '#ffedd5',
            'badge_text_color'  => '#f97316',
            'box_bg_color'      => '#ffffff',
            'box_border_color'  => '#e2e8f0',
        );
        foreach ($color_keys as $key => $default_hex) {
            $raw = isset($input[$key]) ? trim((string) $input[$key]) : $default_hex;
            if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $raw)) {
                $clean[$key] = strtolower($raw);
            } else {
                $clean[$key] = $default_hex;
            }
        }

        // پیش‌تنظیم نمایشی (۳ حالت مجاز)
        $preset = isset($input['style_preset']) ? $input['style_preset'] : self::PRESET_DEFAULT;
        $clean['style_preset'] = in_array($preset, array(self::PRESET_DEFAULT, self::PRESET_MINIMAL, self::PRESET_THEME), true)
            ? $preset
            : self::PRESET_DEFAULT;

        // گردی گوشه‌ها و اندازه فونت با بازه محدود
        $clean['border_radius']  = isset($input['border_radius']) ? absint($input['border_radius']) : (int) $defaults['border_radius'];
        $clean['border_radius']  = min(30, max(0, $clean['border_radius']));
        $clean['base_font_size'] = isset($input['base_font_size']) ? absint($input['base_font_size']) : (int) $defaults['base_font_size'];
        $clean['base_font_size'] = min(18, max(11, $clean['base_font_size']));

        // CSS سفارشی مدیر فروشگاه — حذف کامل تگ‌های HTML و کاراکترهای خطرناک
        // (فقط مدیر با دسترسی manage_woocommerce می‌تواند آن را ذخیره کند)
        if (isset($input['custom_css'])) {
            $css = wp_strip_all_tags((string) wp_unslash($input['custom_css']));
            $css = str_replace(array('<', '>'), '', $css);
            $clean['custom_css'] = substr($css, 0, 20000);
        } else {
            $clean['custom_css'] = '';
        }

        // کد تخفیف مودال خروج — فقط متن ساده و ایمن
        $clean['exit_intent_coupon'] = isset($input['exit_intent_coupon'])
            ? sanitize_text_field(wp_unslash($input['exit_intent_coupon']))
            : '';

        // استراتژی موتور پیشنهاددهنده (۳ حالت مجاز)
        $mode = isset($input['manual_override_mode']) ? $input['manual_override_mode'] : self::MODE_AUTOMATIC;
        $clean['manual_override_mode'] = in_array($mode, array(self::MODE_AUTOMATIC, self::MODE_HYBRID, self::MODE_MANUAL), true)
            ? $mode
            : self::MODE_AUTOMATIC;

        // نکته حیاتی: قوانین دستی و بلک‌لیست از طریق AJAX جداگانه مدیریت می‌شوند؛
        // هنگام ذخیره فرم باید مقادیر موجود عیناً حفظ شوند و پاک نشوند.
        $clean['manual_rules']      = isset($saved['manual_rules']) && is_array($saved['manual_rules'])
            ? self::sanitize_rules_list($saved['manual_rules'])
            : array();
        $clean['product_blacklist'] = isset($saved['product_blacklist']) && is_array($saved['product_blacklist'])
            ? self::sanitize_blacklist_ids($saved['product_blacklist'])
            : array();

        return $clean;
    }

    /**
     * پاکسازی و نرمال‌سازی لیست قوانین دستی مدیر
     * @param array $rules
     * @return array
     */
    public static function sanitize_rules_list($rules) {
        $clean = array();
        if (!is_array($rules)) {
            return $clean;
        }
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $source      = absint(isset($rule['source']) ? $rule['source'] : 0);
            $target      = absint(isset($rule['target']) ? $rule['target'] : 0);
            $confidence  = absint(isset($rule['confidence']) ? $rule['confidence'] : 95);

            if ($source <= 0 || $target <= 0 || $source === $target) {
                continue;
            }
            $clean[] = array(
                'source'     => $source,
                'target'     => $target,
                'confidence' => min(100, max(50, $confidence)),
            );
            // سقف ایمن: حداکثر ۱۰۰ قانون دستی
            if (count($clean) >= 100) {
                break;
            }
        }
        return $clean;
    }

    /**
     * پاکسازی لیست سیاه محصولات (فقط شناسه‌های مثبت یکتا)
     * @param array $ids
     * @return array
     */
    public static function sanitize_blacklist_ids($ids) {
        $clean = array();
        if (!is_array($ids)) {
            return $clean;
        }
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        return array_values(array_unique(array_slice($clean, 0, 200)));
    }
}
