# تطبیق «پیشنهادهای قبل از شروع» با کدی که واقعاً نوشته شده

> این سند دو متن پیشنهادی هوش مصنوعی (مشخصات ۴ ستونه + مقایسه با نمونه‌ها) را با کد فعلی
> `fast-woo-sell/` (کامیت `a141aee`) مقایسه می‌کند تا معلوم شود: چه چیزی **پیاده شده**،
> چه چیزی **عمداً جور دیگری** پیاده شده، و چه چیزی **هنوز روی کاغذ** است.

## ۱. چیزهایی که دقیقاً طبق مشخصات پیاده شده‌اند ✅

| مورد در مشخصات | جای آن در کد | وضعیت |
|---|---|---|
| فقط سه رویداد `product_view`/`impression`/`click` از کلاینت | `Tracking/EventType::client_allowed()` | ✅ دقیقاً همان سه |
| `ascii_bin` روی شناسه‌ها، `DECIMAL(18,4)` پول، `current_time('mysql')` | `Install/Schema.php` | ✅ |
| cache epoch به‌جای نوشتن در `wp_options` از فرانت | `Core/State::bump_cache_epoch()` + `Recommendation/Cache` | ✅ |
| نتیجه‌ی شخصی هرگز کش نشود | `Cache::is_cacheable()` = `! is_personal()` | ✅ |
| محاسبه‌ی affinity در MySQL، نه با آبجکت‌های `WC_Order` | `AffinityBuilder` روی `wc_order_product_lookup` | ✅ |
| بازسازی resumable و «جدول هرگز خالی نمی‌شود» | `State::AFFINITY_CURSOR` + `AffinityRepository::prune($run_at)` | ✅ |
| خودتنظیمی پنجره برای فروشگاه بزرگ | `affinity_single_query_max_rows` / `batched_max_rows` + halving | ✅ |
| فیلتر اعتبار در یک کوئری، نه N+1 | `Filters::apply()` یک `wc_get_products()` | ⚠️ یک کوئری ولی هنوز آبجکت محصول هیدرات می‌کند (نه فقط `wc_product_meta_lookup`) |
| ۱۳ جدول با ۴ فاز | `Schema::PHASE_*` | ✅ ساخته می‌شوند |
| تنظیمات: shadow_mode، consent، retention، A/B thresholds | `Core/Config` | ✅ کلیدها هستند، UI ندارند |
| PHP 5.6 pre-gate | `fast-woo-sell.php` + `Requirements.php` | ✅ |

## ۲. جاهایی که کد **عمداً** با مشخصات فرق دارد (و به نظر من درست‌تر است)

1. **`Context` با `private $x` + getter است، نه `public readonly`.** مشخصات `readonly` می‌خواست، ولی حداقل PHP افزونه `7.4` است و `readonly` فقط از 8.1 هست. تصمیم کد درست است.
2. **جدول `fws_affinity` فقط `support` + `score` دارد، نه `confidence`/`lift`/`source_count` جدا.** به‌جای `source_count`، ردیف‌های `kind='bought_total'` ذخیره می‌شوند و `score` = کسینوس. این از مشخصات ساده‌تر است و برای رتبه‌بندی کافی است — ولی یعنی «چرا این پیشنهاد؟» (٪ خریداران A که B را هم خریدند) را باید در زمان نمایش از `support / total(A)` حساب کنیم. قابل انجام است، داده هست.
3. **کلید اصلی `id` مصنوعی + `UNIQUE pair_kind`** به‌جای کلید مرکب. اختلاف کارایی‌اش در مقیاس این پروژه ناچیز است؛ **دست نمی‌زنیم**.
4. **نام رویدادها با مشخصات فرق دارد**: کد `order_paid`/`order_attributed`/`recovery_sent`/`recovery_clicked`/`cart_updated` دارد، مشخصات `purchase`/`cart_recovered`/`recovery_link_opened`/`contact_captured`/`stock_alert_raised`. **مرجع از این به بعد کد است**، نه سند. (`contact_captured` و `stock_alert_raised` در کد نیستند — وقتی ماژول‌شان نوشته شد اضافه می‌شوند.)

## ۳. چیزهایی که در مشخصات وعده داده شده ولی **صفر خط کد** دارند ❌

| وعده | واقعیت |
|---|---|
| `Result` غنی با `Candidate{score, reason_code, evidence}` | `Result` فقط `array $ids` + `engine` + `contributors` + `fell_back` + `cached` است. شواهد دور ریخته می‌شود. |
| خط لولهٔ ۹ مرحله‌ای `Resolver` (ManualOverrides → … → CacheWrite) | `Service` + `Filters` تقریباً مراحل ۲، ۴، ۵، ۶، ۷، ۸، ۹ را دارند ولی به‌صورت کلاس‌های نام‌دار جدا نیستند؛ **ManualOverrides و RuleAdjustments اصلاً نیستند**. |
| `Support/Conditions` (FieldRegistry, Evaluator, SqlCompiler)، RuleEngine، SegmentResolver | هیچ‌کدام. جدول‌های `rules`/`segments` خالی ساخته می‌شوند. |
| State machine سبد رها شده (۶ وضعیت، ۵ مرحله، `recovered` vs `converted_direct`) | هیچ. فقط جدول. |
| Forecast | هیچ. فقط جدول. |
| A/B: تخصیص `crc32(visitor:experiment) % 100`، دروازهٔ معناداری، bootstrap برای RPI | هیچ. |
| زنجیرهٔ انتساب `impression → click → add_to_cart(_fws_attr) → order item meta` | `order_attributed` به‌عنوان نوع رویداد تعریف شده؛ باید بررسی شود `ServerEvents` واقعاً `_fws_attr` را حمل می‌کند یا نه (در گام ۱ چک می‌کنیم). |
| **Action Scheduler** به‌جای WP-Cron | کد از `wp_schedule_event` استفاده می‌کند. Action Scheduler صفر ارجاع. |
| `fws_visitors` نرمال‌سازی‌شده | جدول هست، **هیچ‌کس در آن نمی‌نویسد**. |
| exporter/eraser حریم خصوصی | هیچ. |
| WP-CLI، ویزارد، ابزارها، Seeder، Site Health، گزارش هفتگی، webhook | هیچ. |
| هر نوع UI (فرانت یا ادمین) | هیچ. |

## ۴. نتیجه‌گیری برای برنامه‌ریزی

- دو سند پیشنهادی **خوب و قابل دفاع** هستند، اما به‌عنوان «نقشهٔ کامل محصول» نوشته شده‌اند نه «برنامهٔ کار». اگر بخواهیم همهٔ آن را قبل از اولین نمایش پیشنهاد بسازیم، ماه‌ها هیچ چیزی روی سایت دیده نمی‌شود.
- **پیشنهاد من همان نقشهٔ راه سند بررسی است**: اول یک برش عمودی که کار کند (گام ۰ و ۱)، بعد تنظیمات و داشبورد، و **بعد** از این فهرست، مورد به مورد و با اولویت ارزش/هزینه اضافه کنیم.
- سه موردی از این فهرست که پیشنهاد می‌کنم **همین اول** در گام ۱ لحاظ شوند چون بعداً عوض‌کردنشان درد دارد:
  1. `Result` را از همین حالا به مدل `Candidate` با `score` و `evidence` ارتقا دهیم (تغییر کوچک، ولی همهٔ موتورها را لمس می‌کند — بهتر است قبل از نوشتن لایهٔ نمایش باشد).
  2. زنجیرهٔ انتساب (`_fws_attr` در cart item data → order item meta) — بدون آن هیچ عدد «درآمد نسبت‌داده‌شده» هرگز درست نخواهد بود و بعداً قابل بازسازی نیست.
  3. `fws_visitors` یا استفاده شود یا از Schema حذف شود؛ جدول یتیم نگه نداریم.
- بقیه (Rules/Segments، A/B، سبد رها شده، Forecast، Action Scheduler) به گام ۶ به بعد می‌روند و هر کدام قبل از شروع، یک سند طراحی کوتاه مبتنی بر **کد فعلی** می‌گیرند، نه این دو متن.

## ۵. تطبیق «۱۸ ایراد» سند ۰۴ با کد (بررسی‌شده با grep روی کامیت a141aee)

نتیجهٔ کلیدی: **کد فعلی از روی سند ۰۴ نوشته شده است.** بیشتر اصلاح‌ها اعمال شده‌اند.

| # | ایراد | در کد؟ | شاهد |
|---|---|---|---|
| ۱ | شکستن rollup به دو جدول | ✅ | `fws_stats_product_daily` + `fws_stats_variant_daily` در Schema |
| ۲ | رویداد `order_attributed` | ✅ | `EventType::ORDER_ATTRIBUTED` + ۱۲ ارجاع |
| ۳ | مهار session (۲۰ / ۵۰) | ✅ | `session_view_cap=20`, `session_bot_threshold=50` در Config |
| ۴ | هش سگمنت در کلید کش | ❌ | هیچ ارجاعی به segment در Cache/Context — منطقی است چون Segment هنوز وجود ندارد |
| ۵ | دو کلاس اکشن در Rule Engine | ❌ | Rule Engine نوشته نشده |
| ۶ | `over_fetch` در قرارداد | ✅ | `Service::fetch_limit()` = `limit × over_fetch` با سقف ۱۰۰ |
| ۷ | `visitor_id` فقط از کوکی سرور | ✅ | `Visitor::COOKIE = 'fws_v'`؛ باید در گام ۱ تأیید شود که RestController مقدار بدنه را نادیده می‌گیرد |
| ۸–۱۰ | expires_at، قفل خوش‌بینانه، لینک در order_processed | ❌ | ماژول سبد نوشته نشده |
| ۱۱ | قفل وزن + md5 | ❌ | ماژول آزمایش نوشته نشده |
| ۱۲ | پرچم evaluatable/compilable | ❌ | Conditions نوشته نشده |
| ۱۳ | `items_hash` | ⚠️ | باید در Schema جدول carts چک شود |
| ۱۴ | country خارج از مسیر داغ | ⚠️ | `fws_visitors` اصلاً استفاده نمی‌شود، پس مسئله فعلاً منتفی است |
| ۱۵ | چهار قابلیت | ✅ (با تفاوت) | کد `fws_export_data` دارد به‌جای `fws_view_cart_pii` — **در فاز سبد باید `view_cart_pii` اضافه شود** |
| ۱۶ | Migrator با قفل | ✅ | `Migrator::LOCK`, `admin_init` prio 5 |
| ۱۷ | حذف opt-in و تکه‌ای | ✅ | `uninstall.php` + `delete_data_on_uninstall=false` |
| ۱۸ | اعلام HPOS + Blocks | ✅ | `Compatibility::declare_all` |
| — | Action Scheduler (قاعدهٔ ۷ بلوک دستور) | ❌ | کد از `wp_schedule_event` استفاده می‌کند — **انحراف از قرارداد؛ نیاز به تصمیم** |
| — | Logger روی `WC_Logger` | ✅ | با fallback به `error_log` |

**نتیجه برای برنامه‌ریزی:** طبق ۳۲ بستهٔ سند ۰۴، کد فعلی تقریباً معادل **فاز ۰ کامل، فاز ۱ کامل، فاز ۲ (بدون StrategySelector جدا و بدون Action Scheduler)، و بخش عمدهٔ فاز ۳ (بدون Candidate غنی، بدون Pipeline نام‌دار، بدون ManualOverrides/RuleAdjustments)** است. **فاز ۴ (نمایش فرانت) نقطهٔ شروع واقعی ماست** — که با «گام ۱» نقشهٔ راه سند بررسی یکی است.
