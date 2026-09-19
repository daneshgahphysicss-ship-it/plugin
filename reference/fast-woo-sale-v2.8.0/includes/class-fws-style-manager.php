<?php
/**
 * Class FWS_Style_Manager
 * مرکز کنترل ظاهر افزونه — نسخه ۲.۸ «شخصی‌سازی کامل»
 *
 * مأموریت: صفر کردن تضاد ظاهری افزونه با قالب سایت
 *  ۱) کلید اصلی خاموش/روشن CSS افزونه (بدون استایل = ویجت‌ها با استایل خود قالب رندر می‌شوند)
 *  ۲) سه پیش‌تنظیم نمایشی: default (طراحی اختصاصی) / minimal (تخت و بی‌سایه) / theme (هماهنگ کامل با قالب)
 *  ۳) تزریق متغیرهای CSS از تنظیمات پالت رنگ + تایپوگرافی + گردی گوشه‌ها
 *  ۴) کلید خاموش/روشن مستقل برای هر ویجت تزریق‌شونده (صفحه محصول، سبد، تشکر، ارسال رایگان، حساب، بنر جستجو)
 *  ۵) CSS سفارشی مدیر فروشگاه + فیلتر توسعه‌دهندگان fws_widget_html / fws_styles_enabled
 *
 * نکته معماری: استایل‌شیت ثابت (fws-recommendations.css) همیشه از var(--fws-*, fallback) می‌خواند؛
 * این کلاس فقط مقدار متغیرها را بر اساس تنظیمات تولید می‌کند. هیچ استایلی اجباری نیست.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Style_Manager {

    private static $instance  = null;
    private static $cache     = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // کلاس پیش‌تنظیم نمایشی روی body (پس از تعیین وضعیت CSS اصلی)
        add_filter('body_class', array($this, 'add_body_classes'));
        // چسباندن متغیرهای CSS + CSS سفارشی به استایل بارگذاری‌شده (یا هندل مستقل)
        add_action('wp_enqueue_scripts', array($this, 'attach_dynamic_css'), 20);
    }

    /* ───────────────────────── گیت‌های فعال/غیرفعال ───────────────────────── */

    /**
     * کلید اصلی بارگذاری استایل‌های افزونه
     * خاموش = هیچ CSS از افزونه به سایت تزریق نمی‌شود (فقط HTML معنایی + JS عملکردی)
     */
    public static function styles_enabled() {
        if (!isset(self::$cache['styles_enabled'])) {
            $enabled = ('yes' === FWS_Settings::get('style_master_enable', 'yes'));
            self::$cache['styles_enabled'] = apply_filters('fws_styles_enabled', $enabled);
        }
        return self::$cache['styles_enabled'];
    }

    /**
     * وضعیت فعال بودن هر ویجت/کامپوننت فرانت‌اند
     * @param string $key enable_widget_product | enable_widget_cart | enable_widget_thankyou |
     *                    enable_widget_shipping | enable_widget_account | enable_search_banner
     * @return bool
     */
    public static function component_enabled($key) {
        if (!isset(self::$cache['comp_' . $key])) {
            $defaults = FWS_Settings::defaults();
            $default  = isset($defaults[$key]) ? $defaults[$key] : 'yes';
            $enabled  = ('yes' === FWS_Settings::get($key, $default));
            // فیلتر توسعه‌دهندگان: کنترل کامل هر کامپوننت از قالب/افزونه‌های دیگر
            self::$cache['comp_' . $key] = apply_filters('fws_component_enabled', $enabled, $key);
        }
        return self::$cache['comp_' . $key];
    }

    /**
     * نمایش یا عدم نمایش برچسب‌های درصد اطمینان (Confidence Tags)
     */
    public static function show_confidence_tags() {
        if (!isset(self::$cache['show_tags'])) {
            self::$cache['show_tags'] = ('no' !== FWS_Settings::get('hide_confidence_tags', 'no'));
        }
        return self::$cache['show_tags'];
    }

    /**
     * نمایش یا عدم نمایش ایموجی‌ها در متن ویجت‌ها
     */
    public static function show_emojis() {
        if (!isset(self::$cache['show_emojis'])) {
            self::$cache['show_emojis'] = ('yes' === FWS_Settings::get('show_emojis', 'yes'));
        }
        return self::$cache['show_emojis'];
    }

    /**
     * حذف ایموجی و علائم نمادین از متن‌های ویجت وقتی مدیر آن را خاموش کرده است
     * @param string $text
     * @return string
     */
    public static function style_text($text) {
        if (self::show_emojis()) {
            return $text;
        }
        // بازه‌های یونیکد ایموجی/دینگ‌بت/گونه‌گزین/اتصال‌دهنده صفر-عرض
        $stripped = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{2190}-\x{21FF}]/u',
            '',
            $text
        );
        return trim(preg_replace('/\s{2,}/u', ' ', (string) $stripped));
    }

    /**
     * صفت جهت ویجت‌ها — قالب‌های چپ‌چین می‌توانند آن را از تنظیمات خاموش کنند
     * @return string مثل « dir="rtl"» یا رشته خالی
     */
    public static function dir_attr() {
        if (!isset(self::$cache['dir_attr'])) {
            self::$cache['dir_attr'] = ('yes' === FWS_Settings::get('force_rtl', 'yes')) ? ' dir="rtl"' : '';
        }
        return self::$cache['dir_attr'];
    }

    /* ───────────────────────── بارگذاری دارایی‌های پویا ───────────────────────── */

    /**
     * افزودن کلاس‌های وضعیت ظاهر به body فرانت‌اند
     * @param array $classes
     * @return array
     */
    public function add_body_classes($classes) {
        if (!is_admin() && self::styles_enabled()) {
            $classes[] = 'fws-has-styles';
            $classes[] = 'fws-preset-' . self::current_preset();
        }
        return $classes;
    }

    /**
     * پیش‌تنظیم نمایشی فعلی
     * @return string
     */
    public static function current_preset() {
        if (!isset(self::$cache['preset'])) {
            $preset = FWS_Settings::get('style_preset', FWS_Settings::PRESET_DEFAULT);
            if (!in_array($preset, array(FWS_Settings::PRESET_DEFAULT, FWS_Settings::PRESET_MINIMAL, FWS_Settings::PRESET_THEME), true)) {
                $preset = FWS_Settings::PRESET_DEFAULT;
            }
            self::$cache['preset'] = $preset;
        }
        return self::$cache['preset'];
    }

    /**
     * چسباندن CSS پویا (متغیرها + پیش‌تنظیم + CSS سفارشی) به استایل افزونه؛
     * اگر کلید اصلی استایل خاموش باشد ولی CSS سفارشی وجود داشته باشد،
     * فقط همان CSS سفارشی از طریق هندل سبک بدون فایل منتشر می‌شود.
     */
    public function attach_dynamic_css() {
        if (is_admin()) {
            return;
        }

        $custom_css = $this->get_custom_css();

        if (self::styles_enabled() && wp_style_is('fws-recommendations', 'enqueued')) {
            $dynamic = $this->build_variables_css() . "\n" . $this->build_preset_css();
            if ('' !== trim($dynamic)) {
                wp_add_inline_style('fws-recommendations', $dynamic);
            }
        }

        if ('' !== $custom_css) {
            // CSS سفارشی حتی در حالت «بدون استایل افزونه» هم منتشر می‌شود تا مدیر
            // بتواند ویجت‌های بدون استایل را دستی به قالب خودش وصل کند.
            wp_register_style('fws-custom-css', false, array(), FWS_VERSION);
            wp_enqueue_style('fws-custom-css');
            wp_add_inline_style('fws-custom-css', $custom_css);
        }
    }

    /**
     * CSS سفارشی مدیر (با فیلتر توسعه‌دهندگان)
     * @return string
     */
    public function get_custom_css() {
        if (!isset(self::$cache['custom_css'])) {
            $css = (string) FWS_Settings::get('custom_css', '');
            $css = str_replace(array('<', '>'), '', $css);
            self::$cache['custom_css'] = trim(apply_filters('fws_custom_css', $css));
        }
        return self::$cache['custom_css'];
    }

    /* ───────────────────────── تولید متغیرهای CSS ───────────────────────── */

    /**
     * بلوک متغیرهای CSS بر اساس پالت رنگ و تایپوگرافی ذخیره‌شده
     * مقادیر fallback داخل استایل‌شیت دقیقاً برابر همین پیش‌فرض‌هاست، پس
     * اگر این بلوک منتشر نشود ظاهر همان حالت پیش‌فرض پایدار می‌ماند.
     * @return string
     */
    public function build_variables_css() {
        $accent   = self::get_hex('accent_color', '#f97316');
        $acc_text = self::get_hex('accent_text_color', '#ffffff');
        $badge_bg = self::get_hex('badge_bg_color', '#ffedd5');
        $badge_tx = self::get_hex('badge_text_color', '#f97316');
        $box_bg   = self::get_hex('box_bg_color', '#ffffff');
        $box_bd   = self::get_hex('box_border_color', '#e2e8f0');

        $radius   = max(0, min(30, (int) FWS_Settings::get('border_radius', 16)));
        $radius_s = max(0, $radius - 6);
        $font     = max(11, min(18, (int) FWS_Settings::get('base_font_size', 14)));

        $vars = array(
            '--fws-accent'          => $accent,
            '--fws-accent-text'     => $acc_text,
            '--fws-accent-hover'    => self::darken_hex($accent, 12),
            '--fws-badge-bg'        => $badge_bg,
            '--fws-badge-text'      => $badge_tx,
            '--fws-box-bg'          => $box_bg,
            '--fws-box-border'      => $box_bd,
            '--fws-radius'          => $radius . 'px',
            '--fws-radius-sm'       => $radius_s . 'px',
            '--fws-font-size'       => $font . 'px',
            '--fws-text-main'       => '#0f172a',
            '--fws-text-muted'      => '#64748b',
            '--fws-price-color'     => '#059669',
            '--fws-shadow'          => '0 1px 3px rgba(0,0,0,0.05)',
            '--fws-btn-success-bg'  => '#16a34a',
            '--fws-tag-bg'          => '#dcfce7',
            '--fws-tag-text'        => '#15803d',
            '--fws-search-badge-bg' => '#2563eb',
            '--fws-progress-fill'   => 'linear-gradient(90deg, #3b82f6, #10b981)',
            '--fws-grad-thankyou'   => 'linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%)',
            '--fws-grad-search'     => 'linear-gradient(135deg, #eff6ff 0%, #ffffff 100%)',
        );

        $vars = apply_filters('fws_style_variables', $vars);

        $out = ':root{';
        foreach ($vars as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }
        $out .= '}';
        return $out;
    }

    /**
     * بازنویسی متغیرها برای پیش‌تنظیم‌های «مینیمال» و «هماهنگ با قالب»
     * + چند قاعده ساختاری که با متغیر قابل بیان نیستند
     *
     * نکته: مقادیر به‌جای :root به کلاس .fws-preset-* اسکوپ می‌شوند تا هم روی body
     * فرانت‌اند و هم روی ریشه پیش‌نمایش زنده پنل مدیریت کار کند.
     *
     * @param bool $all_presets در پنل مدیریت true بدهید تا هر دو بلوک منتشر شود
     *                          (برای سوییچ زنده پیش‌نمایش قبل از ذخیره)
     * @return string
     */
    public function build_preset_css($all_presets = false) {
        $preset = self::current_preset();
        $blocks = array();

        if (FWS_Settings::PRESET_MINIMAL === $preset || $all_presets) {
            // مینیمال: تخت، بی‌سایه، بی‌گرادیان — رنگ‌ها از پالت مدیر
            $blocks[] = '.fws-preset-minimal{'
                . '--fws-shadow:none;'
                . '--fws-grad-thankyou:none;'
                . '--fws-grad-search:none;'
                . '--fws-progress-fill:var(--fws-accent);'
                . '}';
        }

        if (FWS_Settings::PRESET_THEME === $preset || $all_presets) {
            // هماهنگ با قالب: پس‌زمینه/متن/سایه را از قالب ارث‌بری کن؛
            // تنها رنگ تاکیدی، رنگ انتخابی مدیر (accent) باقی می‌ماند.
            $blocks[] = '.fws-preset-theme{'
                . '--fws-box-bg:transparent;'
                . '--fws-text-main:inherit;'
                . '--fws-text-muted:inherit;'
                . '--fws-price-color:inherit;'
                . '--fws-shadow:none;'
                . '--fws-btn-success-bg:var(--fws-accent);'
                . '--fws-tag-bg:transparent;'
                . '--fws-tag-text:inherit;'
                . '--fws-search-badge-bg:var(--fws-accent);'
                . '--fws-progress-fill:var(--fws-accent);'
                . '--fws-grad-thankyou:none;'
                . '--fws-grad-search:none;'
                . '}'
                // کارت‌های داخلی هم شفاف شوند تا استایل قالب دیده شود
                . '.fws-preset-theme .fws-bundle-items,'
                . '.fws-preset-theme .fws-bundle-item,'
                . '.fws-preset-theme .fws-filler-card,'
                . '.fws-preset-theme .fws-search-item-card,'
                . '.fws-preset-theme .fws-account-item,'
                . '.fws-preset-theme .fws-thankyou-item-row,'
                . '.fws-preset-theme .fws-cart-item{background:transparent;}'
                . '.fws-preset-theme .fws-thankyou-upsell-box{border-style:solid;}'
                // متن دکمه افزودن سریع از قالب ارث‌بری کند
                . '.fws-preset-theme .fws-quick-add-btn{background:transparent;color:inherit;}';
        }

        return implode("\n", $blocks);
    }

    /* ───────────────────────── ابزارهای کمکی رنگ ───────────────────────── */

    /**
     * خواندن یک کد رنگ HEX معتبر از تنظیمات
     * @param string $key
     * @param string $fallback
     * @return string
     */
    public static function get_hex($key, $fallback) {
        $raw = (string) FWS_Settings::get($key, $fallback);
        if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $raw)) {
            // نرمال‌سازی ۳ رقمی به ۶ رقمی برای محاسبات تیره‌سازی
            if (4 === strlen($raw)) {
                $raw = '#' . $raw[1] . $raw[1] . $raw[2] . $raw[2] . $raw[3] . $raw[3];
            }
            return strtolower($raw);
        }
        return $fallback;
    }

    /**
     * تیره‌کردن رنگ HEX به درصد مشخص (برای حالت hover دکمه‌ها)
     * @param string $hex
     * @param int    $percent ۰ تا ۱۰۰
     * @return string
     */
    public static function darken_hex($hex, $percent) {
        $hex = self::get_hex($hex, '#000000');
        $hex = ltrim($hex, '#');
        if (6 !== strlen($hex)) {
            return '#' . $hex;
        }
        $percent = max(0, min(100, (int) $percent)) / 100;
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $channel = hexdec(substr($hex, $i * 2, 2));
            $channel = (int) round($channel * (1 - $percent));
            $out .= str_pad(dechex(max(0, min(255, $channel))), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}
