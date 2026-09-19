# برنامهٔ ارتقای `fast-woo-sale` (۲.۸ → ۳.x)

> تصمیم کاربر (۲۰۲۶-۰۹-۱۹): **`fast-woo-sale` محصول است.** پیشنهادهای قبلی روی همین کد سوار می‌شود.
> `fast-woo-sell` در `reference/` می‌ماند و قطعه‌هایش **منتقل** می‌شوند، نه بالعکس.
> این سند جایگزین `proposal-barname-gam-be-gam.md` است؛ اصول آن (زیرساخت، روش ضدباگ، چرخهٔ هر گام) تغییری نکرده‌اند.

## ۰. اصول ثابت
- هر گام: مینی‌طراحی → تأیید → تست → کد → CI سبز → اجرای دستی → کامیت کوچک → **push فوری**.
- **هیچ رفتار موجودی که کار می‌کند شکسته نشود.** هر تغییر داخلی با تست رگرسیون همراه است. نسخهٔ ۲.۸ روی سایت‌ها نصب است؛ ارتقا باید بی‌درد باشد (بدون تغییر تنظیمات کاربر، بدون از دست رفتن قواعد دستی).
- سقف ~۴۰۰ خط در هر گام.
- ابزار کیفیت همان پروپوزال قبل: PHPCS+WPCS، PHPStan L6، PHPUnit، wp-env، CI. **همه از `reference/fast-woo-sell` قابل کپی‌اند** (قبلاً نوشته شده).

## ۱. وضعیت مبنا (۲.۸.۰)
۱۱ فایل PHP (~۴٬۱۰۰ خط) + ۲ JS + ۲ CSS. یک جدول `fws_product_affinity`، یک option `fws_prediction_settings`. WP-Cron روزانه (mining) و هفتگی (cleanup). ۸ سطح نمایش. امنیت AJAX خوب. صفر تست، صفر ردیابی، متن‌های هاردکد فارسی.

## ۲. مراحل و گام‌ها

### مرحلهٔ A — ایمن‌سازی و زیرساخت (گام ۱–۵) · هیچ ویژگی جدید
| # | گام | کارها | پذیرش |
|---|---|---|---|
| ۱ | **بهداشت + ابزار کیفیت** | `.distignore`, `readme.txt`, `composer.json` (phpcs/phpstan/phpunit) و `phpstan.neon.dist` و CI از `reference/` کپی و برای `sale` تنظیم شود؛ header صادقانه: WP ≥ 6.2، WC ≥ 8.0 | CI اجرا می‌شود (اول قرمز؛ همین گزارش ورودی گام ۲ است) |
| ۲ | **رفع یافته‌های PHPStan/PHPCS بدون تغییر رفتار** | فقط تایپ‌ها، escaping، prepare؛ **بدون** refactor | CI سبز روی 7.4/8.1/8.3 |
| ۳ | **محیط اجرا + Seeder** | `.wp-env.json`, Seeder قطعی (منتقل از `reference/fast-woo-sell/tools/cli`) با آداپتور برای جدول `fws_product_affinity`: بررسی دستی `confidence(0→1) = 130/150 = 86.67%`, `lift` | `wp fws affinity check` ✓ |
| ۴ | **تست‌های رگرسیون برای رفتار فعلی** | تست یکپارچه: miner روی seed؛ تخفیف باندل تراکمی نمی‌شود؛ آپسل تشکر IDOR رد می‌شود؛ HMAC باندل جعلی رد می‌شود؛ `the_posts` در غیر جست‌وجو دست نمی‌زند | ~۱۰ تست سبز؛ **قفل رفتار قبل از هر تغییر** |
| ۵ | **رفع ۴ ایراد بحرانی بررسی** | (۱) `wc_get_product` در حلقه → یک کوئری روی `wc_product_meta_lookup` + `_prime_post_caches`؛ (۲) کش per-user برای پیش‌بینی حساب کاربری با stamp `fws_cache_version`؛ (۳) `enable_search_injection` پیش‌فرض → `no` (opt-in) + سازگاری با `is_main_query` و Elementor؛ (۴) `lift DECIMAL(5,2)` → `DECIMAL(8,2)` با migration | تست‌های گام ۴ همچنان سبز + ۴ تست جدید |

### مرحلهٔ B — پایهٔ اندازه‌گیری (گام ۶–۹) · بزرگ‌ترین ارتقای ارزش
بدون این، هیچ‌کدام از «درآمد نسبت‌داده‌شده»، A/B، یا «این ویجت ارزش دارد؟» ممکن نیست.
| # | گام | کارها |
|---|---|---|
| ۶ | **Migrator + State + Capabilities** | منتقل از `reference` (`Install/Migrator`, `Core/State`, `Install/Capabilities`) با پیشوند فعلی؛ `fws_db_version`؛ قابلیت‌های `fws_view_reports`/`fws_manage_settings` به‌جای `manage_woocommerce` همه‌جا |
| ۷ | **ردیابی رویداد (فقط ۳ رویداد کلاینت)** | جدول `fws_events` + `fws_stats_product_daily` (اسکیمای `reference/Install/Schema`)؛ `EventType` با `client_allowed`؛ REST endpoint با nonce + rate limit + visitor از کوکی httpOnly؛ `tracking.js` (منتقل، ≤ 6KB) با IntersectionObserver روی ویجت‌های فعلی (`data-fws-*` روی کارت‌های موجود) |
| ۸ | **رویدادهای سرور + انتساب** | `add_to_cart`/`order_paid`/`order_attributed` از هوک‌های ووکامرس؛ `_fws_attr` در cart item data → order item meta؛ Rollup روزانه؛ حذف ۳ کرون مرده وجود ندارد (sale فقط ۲ دارد) ولی هر دو + rollup → **Action Scheduler** |
| ۹ | **گزارش اثر در پنل** | کارت «۳۰ روز اخیر: نمایش / کلیک / افزودن / سفارش / درآمد نسبت‌داده‌شده» به‌ازای هر ویجت؛ حالت خالی معنادار؛ «؟» فرمول |

### مرحلهٔ C — کیفیت محصول (گام ۱۰–۱۳)
| # | گام | کارها |
|---|---|---|
| ۱۰ | **i18n** | همهٔ رشته‌ها → `__()` انگلیسی + `fa_IR.po/.mo` (ترجمهٔ همان متن‌های فعلی، پس ظاهر برای کاربر ایرانی عوض نمی‌شود) |
| ۱۱ | **شکستن فایل‌های بزرگ** | `display-hooks` (۶۶۶) → یک کلاس به‌ازای هر سطح؛ `admin-page` (۷۵۸) → تب‌ها؛ **بدون تغییر رفتار، پوشش تست گام ۴** |
| ۱۲ | **حریم خصوصی + Site Health** | exporter/eraser برای `fws_events`؛ `respect_dnt`؛ Site Health: کرون/AS، Analytics، آخرین mining |
| ۱۳ | **انتشار 3.0.0** | changelog، zip با `wp dist-archive`، ارتقای درجا از ۲.۸ روی سایت تست (تنظیمات و قواعد دستی حفظ می‌شوند) |

### مرحلهٔ D — ماژول بعدی (گام ۱۴+) · بعد از بازخورد سایت واقعی
A/B چهارمحوره (حالا داده‌اش هست) · سبد رها شده (state machine سند ۰۲) · Rules/Segments · پیش‌بینی موجودی. ترتیب را **داده** تعیین می‌کند.

## ۳. چه چیزهایی از `reference/fast-woo-sell` منتقل می‌شوند (و چه چیزهایی نه)
| منتقل می‌شود | کجا | نمی‌شود |
|---|---|---|
| ابزار کیفیت (composer, phpstan, CI, wp-env, Seeder) | گام ۱، ۳ | موتور کسینوس (`AffinityBuilder`) — miner فعلی با confidence/lift کار می‌کند و کاربر با آن آشناست؛ تعویض متریک بدون دلیل داده‌ای، ریسک بی‌فایده است |
| `Migrator`, `State`, `Capabilities` | گام ۶ | ۱۳ جدول — فقط ۳ تا (`events`, `stats_product_daily`, `stats_variant_daily` بعداً) |
| `EventType`, `RestController`, `Visitor`, `tracking.js`, `Rollup`, `ServerEvents` | گام ۷–۸ | namespace `FWS\` و autoloader — sale با `require_once` کار می‌کند؛ تغییرش فقط churn است |
| `Filters` (فیلتر تک‌کوئری) | گام ۵ | `Config/State` split کامل — فعلاً یک option کافی است، فقط CSS سفارشی autoload=no می‌شود |

## ۴. تصمیم‌های باز که در راه باید بگیریم
- گام ۵: آیا exit-intent مودال پیش‌فرض روشن بماند؟ (پیشنهاد: خاموش، opt-in)
- گام ۸: پنجرهٔ انتساب (پیش‌فرض ۷ روز)
- گام ۱۳: نام نهایی و slug (`fast-woo-sale` می‌ماند؟)

## ۵. شروع
گام ۱ در همین سندباکس بدون PHP قابل انجام است. گام ۲ به بعد خروجی CI (GitHub Actions — روی خود GitHub اجرا می‌شود، PHP لازم ندارد!) را می‌بینم؛ پس **مانع PHP عملاً برداشته شد**: CI روی GitHub PHP دارد.
