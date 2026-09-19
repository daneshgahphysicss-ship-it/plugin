# گزارش نخستین اجرای CI روی fast-woo-sale v2.8.0 (پایان گام ۱)

تاریخ: ۲۰۲۶‑۰۹‑۱۹ — کامیت مرجع: `8d668de` — اجرا روی PHP 7.4 / 8.1 / 8.3 در GitHub Actions

> این سند «ورودی گام ۲» است: فهرست دقیق چیزهایی که ابزارها روی کد فعلی پیدا کردند، به‌همراه قضاوت من دربارهٔ اینکه هر کدام واقعاً مشکل است یا هشدار کاذب، و اینکه در کدام گام برطرف می‌شود.

## ۱. خلاصهٔ وضعیت

| بررسی | نتیجه | معنی |
|---|---|---|
| PHP syntax (7.4 و 8.3) | ✅ سبز | کد روی هر دو نسل PHP پارس می‌شود |
| PHPUnit (تست سلامت) | ✅ سبز | زیرساخت تست کار می‌کند؛ هنوز تست واقعی نداریم (گام ۴) |
| PHPCS – خطاهای امنیتی | ❌ ۸۴ خطا در ۷ فایل | جزئیات در بخش ۲ |
| PHPCS – سبک کد | ⚠️ صدها هشدار | فقط فاصله/تب؛ با `phpcbf` خودکار درست می‌شود |
| PHPStan سطح ۶ | ⚠️ ۱۹۷ مورد (فعلاً فقط گزارش) | اکثریت «نبود type در docblock»؛ چند مورد واقعی در بخش ۳ |

نکتهٔ مهم: **هیچ‌کدام از این یافته‌ها رفتار فعلی افزونه را خراب نمی‌کند**. افزونه همین الان کار می‌کند؛ این‌ها بدهی فنی و چند ریسک امنیتیِ سطح پایین هستند که باید پیش از افزودن قابلیت جدید تمیز شوند.

## ۲. خطاهای PHPCS (۸۴ مورد) — دسته‌بندی و قضاوت

### ۲.۱ `WordPress.DB.PreparedSQL` — ۳۸ مورد (میانگین ریسک: کم تا متوسط)

فایل‌ها: `class-fws-performance-optimizer.php` (۸)، `class-fws-database-miner.php` (۱۰)، `class-fws-prediction-engine.php` (۱۶)، `class-fws-admin-page.php` (۱)، و ۳ مورد `$query`/`$bulk_sql` ساخته‌شده به‌صورت دستی.

بررسی دستی: تقریباً همهٔ این‌ها الگوی `"... FROM {$wpdb->prefix}fws_product_affinity ..."` هستند — یعنی **نام جدول** درون‌یابی شده، نه ورودی کاربر. این الگو در وردپرس رایج و بی‌خطر است و sniff نمی‌تواند تشخیص دهد.
اما ۳ مورد باید دقیق‌تر دیده شوند:
- `database-miner.php:328` (`$query`) و `:441` (`$bulk_sql`) — کوئری‌های ساخته‌شده با `implode` روی مقادیر؛ باید مطمئن شویم همهٔ مقادیر قبلاً `absint`/`floatval` شده‌اند (در بازبینی اولیه چنین بود، اما در گام ۲ به‌صورت خط‌به‌خط تأیید و کامنت `phpcs:ignore` با دلیل می‌گذاریم).
- `performance-optimizer.php:98` (`$unindexed_query`).

**اقدام گام ۲:** برای نام جدول از الگوی استاندارد (`$table = $wpdb->prefix . 'fws_product_affinity';` + `phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name`) استفاده می‌کنیم؛ برای ۳ مورد پویا یا `prepare()` واقعی می‌نویسیم یا با توضیح مستند نادیده می‌گیریم. **بدون تغییر رفتار.**

### ۲.۲ `EscapeOutput.OutputNotEscaped` — ۲۸ مورد (ریسک: کم؛ ۲ مورد نیازمند دقت)

فایل‌ها: `class-fws-display-hooks.php` (۲۳)، `class-fws-admin-page.php` (۵).

بررسی دستی نمونه‌ها:
- `echo FWS_Style_Manager::dir_attr()` (چندین بار) — خروجی ثابت `' dir="rtl"'` است؛ بی‌خطر، فقط باید تابع خودش escape کند یا `phpcs:ignore` با دلیل بگیرد.
- `echo wc_price(...)` — تابع ووکامرس خروجی HTML امن می‌دهد؛ الگوی رایج، با `// phpcs:ignore` مستند می‌شود.
- `echo apply_filters('fws_widget_html', ob_get_clean(), ...)` (`display-hooks:69`) — خروجی از بافر خودمان می‌آید ولی چون از فیلتر عبور می‌کند، sniff درست می‌گوید. رفتار را حفظ می‌کنیم و با `wp_kses_post` یا ignore مستند تصمیم می‌گیریم.
- ۵ مورد صفحهٔ ادمین (`243, 255, 294, 370`) — باید تک‌تک دیده شوند؛ محتمل‌ترین حالت `echo $var` روی داده‌ای از دیتابیس/تنظیمات است که باید `esc_html`/`esc_attr` بگیرد.

**اقدام گام ۲:** هر `echo` یا escape واقعی می‌گیرد یا ignore با دلیل. **بدون تغییر رفتار ظاهری.**

### ۲.۳ `NonceVerification.Missing` — ۱۶ مورد (هشدار کاذب — تأیید شده)

همهٔ ۱۶ مورد در `class-fws-admin-page.php` (خطوط ۱۰۷، ۱۲۷–۱۲۹، ۱۶۶–۱۶۷، ۱۸۹، ۲۰۵) هستند. بررسی کردم: هر متد ابتدا `$this->verify_admin_ajax()` را صدا می‌زند که داخل آن `check_ajax_referer('fws_admin_nonce', 'nonce')` و `current_user_can('manage_woocommerce')` انجام می‌شود. sniff نمی‌تواند فراخوانی غیرمستقیم را ببیند.

**اقدام گام ۲:** یک خط `// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify_admin_ajax()` در ابتدای هر متد. صفر تغییر رفتار.

### ۲.۴ ورودی sanitize نشده — ۲ مورد در `class-fws-ajax-handler.php:181`

`$raw_ids = (array) $_POST['product_ids']` بدون `wp_unslash`. در خط بعد `absint` روی همه اعمال می‌شود، پس **از نظر عملی امن است**؛ فقط الگو ناقص است.

**اقدام گام ۲:** `array_map('absint', wp_unslash((array) $_POST['product_ids']))`. رفتار یکسان.

## ۳. PHPStan سطح ۶ — مواردی که واقعاً مهم‌اند

از ۱۹۷ مورد، حدود ۱۶۰ مورد «نوع پارامتر/بازگشت مشخص نشده» است (بدهی مستندسازی، بی‌خطر؛ در گام ۱۱ همراه با تقسیم فایل‌ها حل می‌شود). موارد باقی‌مانده:

| فایل:خط | یافته | قضاوت |
|---|---|---|
| `ajax-handler.php:141,142,209,210,268,269` | `WC_Session::has_session()` / `set_customer_session_cookie()` | هشدار کاذب: در زمان اجرا `WC()->session` از نوع `WC_Session_Handler` است که این متدها را دارد. با `@var WC_Session_Handler` یا `method_exists` حل می‌شود. |
| `ajax-handler.php:337`, `prediction-engine.php:363,451` | `WC_Order_Item::get_product_id()` | نیمه‌واقعی: `get_items()` می‌تواند اقلام غیرمحصولی هم برگرداند. افزودن `instanceof WC_Order_Item_Product` هم هشدار را رفع می‌کند هم یک Fatal بالقوه را می‌بندد. |
| `prediction-engine.php:119,207,278,...` (۹ مورد) | `wp_get_attachment_image_url()` با `string` به‌جای `int` | ورودی از `meta_value` می‌آید؛ یک `(int)` کافی است. |
| `database-miner.php:25–51` | کلاس `WP_CLI` ناشناخته | فقط نبود stub؛ در گام ۲ `php-stubs/wp-cli-stubs` اضافه می‌شود. |
| `database-miner.php:458` | شرط همیشه‌درست | کد مرده؛ در گام ۲ بررسی و حذف/توضیح. |
| `admin-page.php:313`, `display-hooks.php:210,256,263,377,380` | عدد به `esc_attr/esc_html` | بی‌خطر (تبدیل خودکار)؛ `(string)` یا `esc_attr((string) ...)`. |

## ۴. سبک کد

فایل‌ها با **فاصله** تورفتگی شده‌اند و از سبک `if(!x)` استفاده می‌کنند؛ WordPress Coding Standards تب و `if ( ! $x )` می‌خواهد. این‌ها صدها هشدار می‌سازد ولی همه با `composer lint:fix` (phpcbf) خودکار درست می‌شوند.

**تصمیم:** اجرای phpcbf در یک کامیت جداگانه و «فقط سبک» انجام می‌شود تا diff مربوط به منطق از diff مربوط به فاصله جدا بماند و بازبینی ساده باشد. این کار در ابتدای گام ۲ انجام می‌شود، **پس از** آنکه در گام ۴ تست‌های رگرسیون قفل شده باشند؟ — نه؛ چون phpcbf فقط whitespace را عوض می‌کند و syntax-check CI آن را تأیید می‌کند، همان ابتدای گام ۲ انجام می‌شود.

## ۵. زیرساخت CI — چه چیزی ساخته شد

- `.github/workflows/ci.yml`: سه job روی PHP 7.4/8.1/8.3؛ PHPCS (امنیت = خطا، سبک = هشدار)، PHPStan (فعلاً فقط گزارش)، PHPUnit.
- چون از داخل محیط این جلسه دانلود لاگ/آرتیفکت GitHub ممکن نبود، CI گزارش کامل PHPCS و PHPStan را به‌صورت **کامنت روی هر کامیت** می‌نویسد (job با PHP 8.1). این گزارش هم برای من از طریق API خوانا است و هم شما می‌توانید در GitHub زیر هر کامیت ببینید.

## ۶. ترتیب اجرای گام ۲ (پیشنهادی، بدون تغییر رفتار)

1. کامیت «فقط سبک»: `phpcbf` روی `includes/` و فایل اصلی.
2. کامیت «SQL»: الگوی نام جدول + بازبینی ۳ کوئری پویا.
3. کامیت «خروجی»: escape/ignore مستند برای ۲۸ مورد.
4. کامیت «nonce + input»: ignore مستند + `wp_unslash`.
5. کامیت «PHPStan واقعی»: `instanceof WC_Order_Item_Product`، cast به int، stub های WP-CLI.
6. تغییر CI: PHPCS باید سبز باشد (دیگر `|| true` نداریم)؛ PHPStan همچنان گزارش تا گام ۱۱.

معیار پذیرش گام ۲: CI سبز روی هر سه نسخهٔ PHP، و `git diff --stat` نشان دهد که هیچ تغییری خارج از الگوهای بالا رخ نداده است.
