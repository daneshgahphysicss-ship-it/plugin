# ۰۲ — چهار مشخصات (اسکیمای DB، رویدادها، state machine سبد، قرارداد موتور)

> متن عیناً از گفتگوی کاربر با هوش مصنوعی، قبل از شروع پروژه. ویرایش نشده.
> تطبیق آن با کد فعلی: `docs/fa/tatbigh-pishnahadat-ba-kod.md`

---

قبل از چهار مشخصات: اصلاحاتت را قبول دارم و در یکی از آن‌ها **اصل خودت را به پیشنهاد قبلی خودم هم تعمیم می‌دهم**.

وقتی BINARY(16) را به‌خاطر هزینه‌ی debugging رد کردی، همان استدلال دقیقاً به پیشنهاد `TINYINT UNSIGNED` من برای `event_type`/`device`/`source` هم وارد است — `event_type = 7` در SQL خام همان‌قدر آزاردهنده است. پس **v1 هیچ enum عددی و هیچ UUID فشرده‌ای ندارد**؛ فقط `CHARACTER SET ascii` که ۷۵٪ صرفه‌جویی بایت را بدون هیچ هزینه‌ی خوانایی می‌دهد. عدد ۶۰–۷۰٪ هم از مستندات حذف شد و تبدیل شد به یک تسک benchmark با روش مشخص (پایین آمده).

یک بهینه‌سازی ساختاری هم که ارزشش از هر دوی آن‌ها بیشتر است و در نمونه‌ها نبود: `device`/`source`/`country` صفت **بازدیدکننده** هستند نه صفت **رویداد**، ولی `upsellsmart` آن‌ها را در هر ردیف رویداد تکرار می‌کند. با نرمال‌سازی به یک جدول `visitors`، سه ستون از جدول داغ حذف می‌شود — بدون هیچ ابهامی در دیباگ.

---

# ۱. Database Schema

قرارداد سراسری: `CHARACTER SET ascii COLLATE ascii_bin` روی هر ستونی که شناسه یا واژگان بسته است. `utf8mb4` فقط برای متن انسانی (نام، یادداشت). همه‌ی `DATETIME`ها به وقت سایت با `current_time('mysql')`، سازگار با کل کدبیس ووکامرس.

## ۱.۱ `fws_visitors` — هویت و صفات بازدیدکننده

```sql
visitor_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
user_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
device         VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT '',
source         VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
country        CHAR(2)     CHARACTER SET ascii NOT NULL DEFAULT '',
events_count   INT UNSIGNED NOT NULL DEFAULT 0,
first_seen_at  DATETIME NOT NULL,
last_seen_at   DATETIME NOT NULL,
PRIMARY KEY (visitor_id),
KEY user_idx      (user_id),
KEY last_seen_idx (last_seen_at)
```

`country` اینجا **یک‌بار** پر می‌شود، نه در هر رویداد — همان مشکل `WC_Geolocation` روی مسیر بحرانی افزودن به سبد که پیدا کردیم.

## ۱.۲ `fws_events` — جدول داغ، فقط-افزودنی

```sql
id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
event_type       VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
object_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
object_type      VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'product',
visitor_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
session_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
surface          VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
placement_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
variant_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
source_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
quantity         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
value            DECIMAL(18,4) NOT NULL DEFAULT 0,
order_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
created_at       DATETIME NOT NULL,
PRIMARY KEY (id),
KEY type_time    (event_type, created_at),
KEY object_type_time (object_id, event_type, created_at),
KEY session_type (session_id, event_type),
KEY variant_type (variant_id, event_type),
KEY visitor_time (visitor_id, created_at)
```

پنج ایندکس ثانویه — هر کدام به یک کوئری شناخته‌شده گره خورده و بیشتر از این نمی‌گذاریم چون هزینه‌ی نوشتن دارد:

| ایندکس | کوئری |
|---|---|
| `type_time` | شمارش قیف ۳۰ روزه |
| `object_type_time` | آمار هر محصول |
| `session_type` | گروه‌بندی `product_view` بر اساس session برای `also_viewed` |
| `variant_type` | قیف A/B |
| `visitor_time` | پیشنهاد شخصی‌سازی‌شده |

**`meta LONGTEXT` عمداً وجود ندارد.** `upsellsmart` آن را در هر ردیف حمل می‌کند. اگر بعداً لازم شد، یک جدول اسپارس `fws_event_meta` اضافه می‌شود، نه ستون در جدول داغ.

**پاک‌سازی از روی `id` انجام می‌شود، نه `created_at`** — چون `id` یکنواخت با زمان است، از کلید اصلی استفاده می‌کنیم و یک ایندکس اضافه لازم نداریم: `DELETE FROM fws_events WHERE id < :cutoff_id LIMIT 5000` در حلقه.

## ۱.۳ `fws_events_daily` — rollup و منبع تمام گزارش‌ها

```sql
stat_date   DATE NOT NULL,
event_type  VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
surface     VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
placement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
variant_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
value_sum   DECIMAL(18,4) NOT NULL DEFAULT 0,
PRIMARY KEY (stat_date, event_type, object_id, surface, placement_id, variant_id),
KEY variant_date (variant_id, stat_date),
KEY object_date  (object_id, stat_date),
KEY date_idx     (stat_date)
```

کلید اصلی مرکب یعنی `INSERT ... ON DUPLICATE KEY UPDATE event_count = event_count + VALUES(event_count)` — همان راه‌حل write-amplification که در `storzen` مشکل بود. این جدول **هم** داشبورد را تغذیه می‌کند **هم** قیف A/B را؛ جدول `stats` جداگانه‌ای لازم نیست.

## ۱.۴ `fws_affinity`

```sql
source_id    BIGINT UNSIGNED NOT NULL,
type         VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
related_id   BIGINT UNSIGNED NOT NULL,
support      INT UNSIGNED NOT NULL DEFAULT 0,
source_count INT UNSIGNED NOT NULL DEFAULT 0,
confidence   FLOAT NOT NULL DEFAULT 0,
lift         FLOAT NOT NULL DEFAULT 0,
score        FLOAT NOT NULL DEFAULT 0,
window_start DATE NOT NULL,
computed_at  DATETIME NOT NULL,
PRIMARY KEY (source_id, type, related_id),
KEY rank_idx    (source_id, type, score),
KEY related_idx (related_id)
```

سه تصمیم عمدی:

**کلید اصلی `(source_id, type, related_id)` است، نه یک `id` مصنوعی.** در InnoDB کلید اصلی همان clustered index است، پس ردیف‌های یک محصول فیزیکاً کنار هم ذخیره می‌شوند و مسیر داغ یک range scan خالص روی clustered index می‌شود. هر دو نمونه یک `id` مصنوعی + یک UNIQUE جدا دارند که یک لایه‌ی indirection اضافه می‌کند.

**`rank_idx` به‌طور ضمنی پوشا است.** InnoDB ستون‌های کلید اصلی را به انتهای هر ایندکس ثانویه اضافه می‌کند، پس `related_id` داخل `rank_idx` هست و کوئری «Top-N مرتبط برای محصول A» هرگز به ردیف اصلی مراجعه نمی‌کند.

**`source_count` ذخیره می‌شود** تا `confidence` قابل بازبینی و بازمحاسبه باشد. بدون آن، اگر عددی مشکوک بود راهی برای صحت‌سنجی نداری.

## ۱.۵ `fws_carts`

```sql
id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
token_hash          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
session_key         VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '',
visitor_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
user_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
status              VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
stage               VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cart_created',
first_name          VARCHAR(100) NOT NULL DEFAULT '',
last_name           VARCHAR(100) NOT NULL DEFAULT '',
phone_raw           VARCHAR(24) CHARACTER SET ascii NOT NULL DEFAULT '',
phone_e164          VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
email               VARCHAR(191) NOT NULL DEFAULT '',
contact_consent     TINYINT(1) NOT NULL DEFAULT 0,
contact_source      VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
items_json          LONGTEXT NULL,
items_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
subtotal            DECIMAL(18,4) NOT NULL DEFAULT 0,
total               DECIMAL(18,4) NOT NULL DEFAULT 0,
currency            CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT '',
priority_score      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
priority_band       VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT '',
score_factors_json  TEXT NULL,
recommended_action  VARCHAR(24) CHARACTER SET ascii NOT NULL DEFAULT '',
assigned_user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
last_outcome        VARCHAR(24) CHARACTER SET ascii NOT NULL DEFAULT '',
supersedes_cart_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
order_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
recovered_total     DECIMAL(18,4) NOT NULL DEFAULT 0,
created_at          DATETIME NOT NULL,
updated_at          DATETIME NOT NULL,
last_activity_at    DATETIME NOT NULL,
abandoned_at        DATETIME NULL,
closed_at           DATETIME NULL,
expires_at          DATETIME NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY token_uq       (token_hash),
KEY status_activity_idx   (status, last_activity_at),
KEY status_priority_idx   (status, priority_score),
KEY session_idx           (session_key),
KEY phone_idx             (phone_e164),
KEY user_idx              (user_id),
KEY order_idx             (order_id),
KEY expires_idx           (expires_at)
```

نکات طراحی:

**`token_hash` ذخیره می‌شود، نه توکن خام.** توکن ۳۲ بایتی تصادفی فقط داخل لینک بازیابی زندگی می‌کند؛ دیتابیس فقط sha256 آن را دارد. مقایسه با `hash_equals`. اگر دیتابیس لو برود، کسی نمی‌تواند سبد کسی را بازیابی کند.

**`phone_e164` جدا از `phone_raw`.** خام را برای نمایش نگه می‌داریم، نرمال‌شده (`+98912...`) را برای dedupe و جست‌وجو و لینک `tel:`. `0912`, `912`, `+98912`, `0098912` همه یک نفرند.

**`supersedes_cart_id`** — اگر همان شخص (بر پایه‌ی `phone_e164` یا `user_id`) سبد جدیدی بسازد در حالی که یک ردیف `abandoned` دارد، ردیف جدید به قدیمی لینک می‌شود و قدیمی از صف بیرون می‌رود. بدون این، کارشناس دو بار به یک نفر زنگ می‌زند.

**`status_activity_idx (status, last_activity_at)`** دقیقاً کوئری detector است: `WHERE status='active' AND last_activity_at < ?`. یک range scan خالص.

**یک ردیف به‌ازای هر سبد زنده، در جا آپدیت می‌شود** — نه یک ردیف به‌ازای هر رویداد. تاریخچه در جدول بعدی است.

## ۱.۶ `fws_cart_log` — مسیر حسابرسی

```sql
id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
cart_id     BIGINT UNSIGNED NOT NULL,
entry_type  VARCHAR(24) CHARACTER SET ascii NOT NULL,  -- transition|note|call|message|link_open
from_status VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
to_status   VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT '',
actor_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,        -- 0 = سیستم/کرون
outcome     VARCHAR(24) CHARACTER SET ascii NOT NULL DEFAULT '',
note        TEXT NULL,
created_at  DATETIME NOT NULL,
PRIMARY KEY (id),
KEY cart_idx (cart_id, created_at)
```

هر گذار state machine اینجا لاگ می‌شود. یعنی وقتی چیزی عجیب شد، می‌توانی دقیقاً ببینی سبد از کجا به کجا رفت و چه کسی یا چه چیزی آن را حرکت داد.

## ۱.۷ `fws_forecast`

```sql
product_id       BIGINT UNSIGNED NOT NULL,
variation_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
window_days      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
qty_current      INT NOT NULL DEFAULT 0,
qty_previous     INT NOT NULL DEFAULT 0,
growth_pct       SMALLINT NOT NULL DEFAULT 0,
velocity_daily   DECIMAL(10,4) NOT NULL DEFAULT 0,
manages_stock    TINYINT(1) NOT NULL DEFAULT 0,
stock_qty        INT NULL,
days_to_stockout DECIMAL(8,2) NULL,
lead_time_days   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
reorder_qty      INT NOT NULL DEFAULT 0,
urgency          VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'none',
trend            VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'flat',
lost_demand_qty  INT UNSIGNED NOT NULL DEFAULT 0,
computed_at      DATETIME NOT NULL,
PRIMARY KEY (product_id, variation_id),
KEY urgency_idx (urgency, days_to_stockout),
KEY trend_idx   (trend, growth_pct)
```

`variation_id` در کلید اصلی چون موجودی معمولاً در سطح تنوع مدیریت می‌شود (`0` برای محصول ساده). `lost_demand_qty` همان تلاقی ماژول ۲ و ۳ است: تعدادی که در سبدهای رها شده بود در حالی که محصول ناموجود بود.

## ۱.۸ جداول سرویس مشترک

```sql
-- fws_rules
id BIGINT UNSIGNED AUTO_INCREMENT, name VARCHAR(191),
scope VARCHAR(24) CHARACTER SET ascii NOT NULL,      -- recommendation|abandoned_cart|forecast|global
conditions_json LONGTEXT, action VARCHAR(24) CHARACTER SET ascii,
action_args_json TEXT, priority INT NOT NULL DEFAULT 10,
status VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'active',
created_at DATETIME, updated_at DATETIME,
PRIMARY KEY (id), KEY scope_idx (scope, status, priority)

-- fws_segments
id BIGINT UNSIGNED AUTO_INCREMENT, name VARCHAR(191),
slug VARCHAR(64) CHARACTER SET ascii NOT NULL,
conditions_json LONGTEXT, is_system TINYINT(1) NOT NULL DEFAULT 0,
customer_count INT UNSIGNED NOT NULL DEFAULT 0, counted_at DATETIME NULL,
status VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'active',
created_at DATETIME, updated_at DATETIME,
PRIMARY KEY (id), UNIQUE KEY slug_uq (slug), KEY status_idx (status)

-- fws_placements  (نمونه‌ی ویجت: موتور + محل + تنظیمات)
id BIGINT UNSIGNED AUTO_INCREMENT, name VARCHAR(191),
engine VARCHAR(32) CHARACTER SET ascii NOT NULL,
surface VARCHAR(32) CHARACTER SET ascii NOT NULL,
hook VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '',
hook_priority SMALLINT NOT NULL DEFAULT 10,
result_limit TINYINT UNSIGNED NOT NULL DEFAULT 4,
layout VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT 'grid',
title VARCHAR(191) NOT NULL DEFAULT '', settings_json LONGTEXT,
rule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
status VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'active',
created_at DATETIME, updated_at DATETIME,
PRIMARY KEY (id), KEY surface_status_idx (surface, status)
```

## ۱.۹ آزمایش A/B تعمیم‌یافته

```sql
-- fws_experiments
id BIGINT UNSIGNED AUTO_INCREMENT, name VARCHAR(191),
dimension VARCHAR(20) CHARACTER SET ascii NOT NULL,   -- engine|placement|rule|position
surface VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '',
primary_metric VARCHAR(28) CHARACTER SET ascii NOT NULL DEFAULT 'revenue_per_impression',
min_samples_per_variant INT UNSIGNED NOT NULL DEFAULT 200,
min_conversions_total   INT UNSIGNED NOT NULL DEFAULT 25,
min_runtime_days        TINYINT UNSIGNED NOT NULL DEFAULT 7,
status VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'draft',
winner_variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
started_at DATETIME NULL, ended_at DATETIME NULL,
created_at DATETIME, updated_at DATETIME,
PRIMARY KEY (id), KEY status_idx (status)

-- fws_experiment_variants
id BIGINT UNSIGNED AUTO_INCREMENT, experiment_id BIGINT UNSIGNED NOT NULL,
label CHAR(1) CHARACTER SET ascii NOT NULL,           -- A|B|C
weight TINYINT UNSIGNED NOT NULL DEFAULT 50,
engine VARCHAR(32) CHARACTER SET ascii NULL,
placement_id BIGINT UNSIGNED NULL,
rule_id BIGINT UNSIGNED NULL,
hook VARCHAR(64) CHARACTER SET ascii NULL,
hook_priority SMALLINT NULL,
PRIMARY KEY (id), KEY exp_idx (experiment_id)
```

`dimension` تعیین می‌کند کدام ستون‌های variant معنادار هستند — همان چهار محوری که خواستی. نتایج جدول جدا ندارند؛ همه از `fws_events_daily` گروه‌بندی‌شده بر `variant_id` می‌آیند.

**تخصیص variant بدون هیچ ذخیره‌سازی و بدون کوکی:**
```
bucket = crc32( visitor_id . ':' . experiment_id ) % 100
```
چسبنده، قطعی، سازگار با کش صفحه، صفر کوئری. `variant_id` انتخاب‌شده در همه‌ی رویدادهای آن درخواست حمل می‌شود.

## ۱.۱۰ فازبندی جداول و تسک benchmark

| فاز | جداول |
|---|---|
| ۰–۱ | `visitors`, `events`, `events_daily` |
| ۲ | `affinity`, `placements` |
| ۳ | `carts`, `cart_log` |
| ۴ | `forecast` |
| ۵ | `rules`, `segments` |
| ۶ | `experiments`, `experiment_variants` |

`rules`/`segments` در فاز ۵ **ولی اینترفیس‌هایشان در فاز ۲ نوشته می‌شوند** (بخش ۴ را ببین) تا ماژول‌ها از روز اول با قرارداد نهایی حرف بزنند.

**تسک benchmark (به‌جای ادعای ۶۰–۷۰٪):** با Seeder یک میلیون رویداد تولید کن، دو نسخه‌ی جدول بساز (utf8mb4 پیش‌فرض در برابر ascii)، و `data_length` + `index_length` را از `information_schema.TABLES` بخوان. عدد واقعی اندازه‌گیری‌شده در README مهندسی ثبت شود، نه در مستندات محصول.

---

# ۲. Event Taxonomy

واژگان بسته در `Support\EventTypes` با متادیتای هر رویداد. جدول مرجع:

| `event_type` | محرک | مبدأ | مجاز از کلاینت؟ | تغذیه می‌کند |
|---|---|---|---|---|
| `product_view` | رندر صفحه‌ی محصول | beacon | ✅ | `also_viewed`، قیف |
| `impression` | ورود کارت پیشنهاد به viewport | beacon (IntersectionObserver، دسته‌ای) | ✅ | قیف A/B، آمار ویجت |
| `click` | کلیک روی کارت پیشنهاد | `sendBeacon` | ✅ | قیف A/B |
| `add_to_cart` | `woocommerce_add_to_cart` | **سرور** | ❌ | قیف، A/B |
| `remove_from_cart` | `woocommerce_cart_item_removed` | سرور | ❌ | تشخیص اصطکاک |
| `checkout_start` | صفحه‌ی چک‌اوت یا اولین تماس Store API | سرور | ❌ | قیف، state machine |
| `contact_captured` | ثبت شماره/ایمیل | سرور | ❌ | state machine |
| `order_created` | `woocommerce_checkout_order_processed` | سرور | ❌ | قیف |
| `purchase` | سفارش به `processing`/`completed` رسید | سرور | ❌ | `bought_together`، انتساب درآمد، A/B |
| `cart_abandoned` | کرون detector | سرور | ❌ | تحلیل سبد |
| `recovery_link_opened` | بازدید URL توکن | سرور | ❌ | قیف بازیابی |
| `cart_recovered` | سفارش پرداخت‌شده در پنجره‌ی پیگیری | سرور | ❌ | گزارش ROI |
| `stock_alert_raised` | forecast محصول را critical کرد | سرور | ❌ | لاگ پیش‌بینی |

## مرز امنیتی

**کلاینت فقط سه رویداد را می‌تواند بفرستد: `product_view`, `impression`, `click`.** هر چیزی که به پول ربط دارد فقط از هوک سمت سرور می‌آید. این دقیقاً پاسخ به آسیب‌پذیری‌ای است که در `TrackingController` نمونه پیدا کردیم — آنجا هر رویدادی از جمله `purchase` از بیرون قابل تزریق بود و ماتریس پیشنهاد را مسموم می‌کرد.

روی همان سه رویداد مجاز هم: nonce، rate limit با transient بر پایه‌ی `visitor_id`+IP، سقف ۲۰ رویداد در هر درخواست، اعتبارسنجی اینکه `object_id` یک محصول منتشرشده است، و رد رویداد بدون `session_id` معتبر.

## idempotency

- `purchase` → با order meta `_fws_purchase_tracked`
- `cart_recovered` → با order meta `_fws_recovery_tracked`
- `impression` → deduplicate سمت کلاینت به‌ازای هر (placement, product) در هر بارگذاری صفحه

## زنجیره‌ی انتساب

این حلقه‌ی حیاتی است که بدون آن معیار Attributed Revenue ساخته نمی‌شود:

```
impression  ──┐  source_object_id + placement_id + variant_id
click       ──┤  (سمت کلاینت، در payload)
              ↓
add_to_cart ──┤  ذخیره در cart_item_data['_fws_attr']
              ↓
purchase    ──┘  کپی به order item meta در woocommerce_checkout_create_order_line_item
```

## قیف به‌ازای هر variant

```
impressions    = Σ event_count WHERE event_type='impression'    AND variant_id=V
clicks         = Σ event_count WHERE event_type='click'         AND variant_id=V
add_to_carts   = Σ event_count WHERE event_type='add_to_cart'   AND variant_id=V
orders         = Σ event_count WHERE event_type='purchase'      AND variant_id=V  (DISTINCT order_id از جدول خام)
attr_revenue   = Σ value_sum   WHERE event_type='purchase'      AND variant_id=V

CTR   = clicks/impressions      ATC = add_to_carts/clicks
CR    = orders/add_to_carts     RPI = attr_revenue/impressions
```

**پیشنهاد می‌کنم `revenue_per_impression` معیار اصلی پیش‌فرض باشد، نه CTR.** بهینه‌سازی روی CTR به clickbait پاداش می‌دهد؛ RPI تنها معیاری است که قابل بازی‌کردن نیست.

## دروازه‌ی معناداری

```
gate_passed =  min(impressions_A, impressions_B) >= min_samples_per_variant
            && (conversions_A + conversions_B)  >= min_conversions_total
            && runtime_days                     >= min_runtime_days
```

تا قبل از عبور از دروازه، UI فقط می‌گوید «در حال جمع‌آوری داده — ۱۲۴ از ۲۰۰ نمایش لازم برای هر گروه» با نوار پیشرفت، و **هیچ برنده‌ای اعلام نمی‌شود**.

یک محدودیت آماری که باید صریح بگویم: z-test دو-نسبتی فقط برای معیارهای **نسبتی** (CTR، ATC، CR) معتبر است. برای `revenue_per_impression` که یک میانگین با دُم سنگین است، z-test نتیجه‌ی گمراه‌کننده می‌دهد. برای آن معیار **bootstrap با ۱۰۰۰۰ نمونه‌ی مجدد و بازه‌ی اطمینان ۹۵٪** گزارش می‌کنیم، و اگر بازه شامل صفر بود می‌گوییم «تفاوت معنادار نیست». این تمایز در `upsellsmart` وجود ندارد.

---

# ۳. State Machine سبد رها شده

## وضعیت‌ها

| وضعیت | معنا | ترمینال؟ |
|---|---|---|
| `active` | سبد زنده، در حال به‌روزرسانی | خیر |
| `abandoned` | آستانه‌ی بی‌فعالیتی گذشت، سفارشی نیست | خیر |
| `in_progress` | کارشناس آن را برداشته و تماس گرفته | خیر |
| `recovered` | با پیگیری ما به سفارش پرداخت‌شده رسید | ✅ |
| `converted_direct` | مشتری خودش بدون پیگیری خرید را تمام کرد | ✅ |
| `lost` | بسته شد بدون خرید | ✅ |

**تمایز `recovered` از `converted_direct` مهم‌ترین تصمیم این ماشین است.** اگر هر سفارشی که بعد از یک سبد رها شده اتفاق افتاد را «بازیابی‌شده» بشماری، افزونه اعتبار فروشی را می‌گیرد که هیچ نقشی در آن نداشته، و عدد ROI بی‌معنی می‌شود. هیچ‌کدام از نمونه‌ها چنین تمایزی ندارند.

## مراحل — یکنواخت و مستقل از وضعیت

```
cart_created → cart_viewed → checkout_started → contact_captured → payment_started
```

`stage` **هرگز عقب نمی‌رود**. اگر کسی به چک‌اوت رسید و برگشت به سبد، `stage` روی `checkout_started` می‌ماند. این از flapping و از نوسان امتیاز اولویت جلوگیری می‌کند.

## جدول گذارها

| از | رویداد | به | اثرات جانبی |
|---|---|---|---|
| — | `woocommerce_add_to_cart` | `active` / `cart_created` | ساخت ردیف، تولید توکن، ذخیره‌ی hash، snapshot اقلام، `expires_at = now + retention` |
| `active` | فعالیت سبد یا چک‌اوت | `active` | آپدیت `last_activity_at`، snapshot، پیشروی `stage` |
| `active` | شماره/ایمیل ثبت شد | `active` / `contact_captured` | ذخیره‌ی PII + `contact_consent` + `contact_source`، لاگ |
| `active` | `checkout_order_processed` | `active` / `payment_started` | لینک `order_id` |
| `active` | سفارش پرداخت شد | **`converted_direct`** | `closed_at`، بدون ادعای اعتبار |
| `active` | `last_activity_at < now − cutoff` (کرون ۱۵ دقیقه‌ای) | **`abandoned`** | `abandoned_at`، محاسبه‌ی `priority_score` + `score_factors_json` + `priority_band`، رویداد `cart_abandoned`، اجرای قواعد scope=`abandoned_cart` |
| `abandoned` | تخصیص یا اولین تماس | `in_progress` | `attempts++`، لاگ |
| `abandoned` \| `in_progress` | سفارش پرداخت‌شده‌ی منطبق در پنجره‌ی پیگیری | **`recovered`** | `recovered_total`، انتساب، رویداد `cart_recovered` |
| `abandoned` \| `in_progress` | نتیجه = `not_interested` \| `wrong_number` | `lost` | `closed_at`، `last_outcome` |
| `in_progress` | `attempts ≥ max_attempts` بدون نتیجه | `lost` | |
| هر وضعیت | سبد جدید از همان شخص | وضعیت فعلی + `supersedes_cart_id` روی ردیف جدید | ردیف قدیمی از صف بیرون |
| ترمینال | `now > expires_at` (کرون روزانه) | حذف ردیف | فقط ردیف بی‌نام در `events_daily` می‌ماند |

## نگهبان‌ها و ثابت‌ها

- حداکثر **یک** ردیف `active` به‌ازای هر `session_key` — با upsert روی `session_idx` تضمین می‌شود.
- وضعیت‌های ترمینال هرگز باز نمی‌شوند. سبد جدید یعنی ردیف جدید.
- **قاعده‌ی انتساب بازیابی:** سفارش فقط زمانی `recovered` است که (الف) ردیف در `abandoned` یا `in_progress` بوده، (ب) سفارش داخل `recovery_window_hours` (پیش‌فرض ۷۲) از آخرین اقدام پیگیری ثبت شده، و (ج) تطبیق با `user_id` یا `phone_e164` یا `email` یا توکن بازیابی برقرار است. در غیر این صورت `converted_direct`.
- دو آستانه‌ی رها شدن: **۶۰ دقیقه** برای سبدهایی که اطلاعات تماس دارند، **۲۴ ساعت** برای بدون اطلاعات تماس (چون قابل پیگیری نیستند و شلوغ کردن صف بی‌فایده است).
- هر گذار در `fws_cart_log` ثبت می‌شود.

## ثبت شماره — طبق اصلاح تو

چهار مسیر، هیچ‌کدام مزاحم:

1. **فیلدهای طبیعی چک‌اوت** — فقط روی `blur` (نه در حین تایپ)، فقط فیلدهایی که مشتری خودش پر کرده. صفر تغییر در UI. برای چک‌اوت بلوکی معادل آن هوک Store API روی به‌روزرسانی مشتری است — نام دقیق هوک را روی نسخه‌ی ووکامرس نصب‌شده تأیید می‌کنم، چون بین نسخه‌ها عوض شده.
2. **گزینه‌ی سبک و اختیاری، پیش‌فرض خاموش** — یک چک‌باکس و یک فیلد تلفن **داخل بخش صورت‌حساب** با `woocommerce_after_checkout_billing_form`: «اگر پرداخت ناتمام ماند، برای پیگیری با من تماس بگیرید». بدون مودال، بدون overlay، بدون بلاک کردن. نتیجه: `contact_consent = 1`, `contact_source = 'opt_in'`.
3. **کاربر لاگین‌شده** — از پروفایل خوانده می‌شود، `contact_source = 'account'`. هیچ ثبتی لازم نیست.
4. **تنظیم «فقط با رضایت صریح»** — وقتی روشن است، مسیر ۱ هیچ چیز ذخیره نمی‌کند و فقط ۲ و ۳ شماره را پر می‌کنند.

---

# ۴. قرارداد Recommendation Engine

## اینترفیس

```php
interface EngineInterface {
    public function key(): string;                 // شناسه‌ی پایدار: 'bought_together'
    public function label(): string;               // برچسب ترجمه‌شده برای UI
    public function requirements(): array;         // ['affinity'] | ['events'] | ['wc_analytics']
    public function supports( Context $context ): bool;
    public function recommend( Context $context ): Result;
}
```

## `Context` — شیء مقداری تغییرناپذیر

یک‌بار در هر درخواست توسط `ContextFactory` ساخته می‌شود:

```php
final class Context {
    public readonly string $surface;        // product|cart|checkout|thankyou|minicart|shortcode|block|rest
    public readonly int    $product_id;
    public readonly int    $variation_id;
    public readonly array  $cart_item_ids;
    public readonly float  $cart_total;
    public readonly int    $order_id;
    public readonly array  $category_ids;
    public readonly string $visitor_id;
    public readonly string $session_id;
    public readonly int    $user_id;
    public readonly array  $segment_slugs;  // ← از Segment Engine مشترک
    public readonly int    $limit;
    public readonly int    $min_results;
    public readonly array  $exclude_ids;
    public readonly int    $placement_id;
    public readonly int    $variant_id;
    public readonly bool   $is_cacheable;   // false وقتی شخصی‌سازی‌شده است
    public function cache_key(): string;    // قطعی
}
```

## `Result` — نه یک آرایه‌ی خالی از عدد

```php
final class Candidate {
    public int    $product_id;
    public float  $score;
    public string $reason_code;   // affinity|category|popularity|manual|personal
    public array  $evidence;      // ['support'=>24,'confidence'=>0.68,'lift'=>3.1]
}

final class Result {
    /** @var Candidate[] */
    public array  $candidates;
    public string $engine;
    public bool   $is_fallback;
    public string $reason_code;
}
```

هر دو نمونه‌ی مرجع فقط `array<int,int>` برمی‌گردانند. با این کار **شواهد به‌طور برگشت‌ناپذیر دور ریخته می‌شود** — و بعد نمی‌توانی به مدیر فروشگاه بگویی «چرا این پیشنهاد؟»، نمی‌توانی fallback را از پیشنهاد واقعی در آمار جدا کنی، و نمی‌توانی اشکال‌زدایی کنی.

## خط لوله‌ی `Resolver`

هر مرحله یک کلاس نام‌دار و قابل تست:

```
۱. ManualOverrides     ← pin/block هر محصول. بالاترین اقتدار، همیشه برنده
۲. EngineResolution    ← موتور انتخابی (یا موتور variant آزمایش) → Result
۳. RuleAdjustments     ← Rule Engine مشترک، scope=recommendation، اکشن boost/suppress
۴. ValidityFilter      ← منتشرشده/قابل‌خرید/مرئی/سیاست موجودی — یک کوئری روی
                          wc_product_meta_lookup، نه N+1 روی wc_get_product()
۵. Exclusions          ← محصول جاری، محتوای سبد، خریدهای قبلی (اختیاری)، لیست بلاک
۶. Diversity           ← حداکثر N از هر دسته (تا چهار تیشرت یکسان پیشنهاد نشود)
۷. FallbackChain       ← اگر < min_results: هم‌دسته‌ی محبوب → محبوب فروشگاه
                          و is_fallback = true علامت می‌خورد
۸. Trim                ← به limit
۹. CacheWrite          ← اگر is_cacheable
```

## تضمین‌های قرارداد — قابل تست

- `recommend()` هرگز exception پرت نمی‌کند؛ در خطا `Result` خالی برمی‌گرداند و لاگ می‌کند.
- `recommend()` **هیچ نوشتنی انجام نمی‌دهد** و روی مسیر داغ حداکثر **۱ کوئری** می‌زند؛ fallback حداکثر ۱ کوئری دیگر.
- برای ورودی یکسان، ترتیب خروجی **قطعی** است — تساوی امتیاز با `product_id ASC` شکسته می‌شود. این برای اعتبار A/B و درستی کش الزامی است.
- هیچ موتوری `wc_get_product()` را داخل حلقه صدا نمی‌زند.

## نقاط توسعه

```php
apply_filters( 'fws/engines', $registry );
apply_filters( 'fws/context', $context );
apply_filters( "fws/candidates/{$engine}", $candidates, $context );
apply_filters( 'fws/result', $result, $context );
apply_filters( 'fws/resolver/pipeline', $steps );
```

## معماری مشترک Rule و Segment — طبق تصمیم استراتژیک تو

این دو **بیرون از `Recommendations`** و در `Support/Conditions` می‌نشینند:

```
Support/Conditions/
├─ FieldRegistry    ← کاتالوگ یگانه‌ی فیلدهای شرط. هر فیلد: key, label, type,
│                     operators[], scopes[], resolver
├─ ConditionTree    ← {match: all|any, conditions: [...], groups: [...]}
├─ ContextBag       ← کیف کلید→مقدار با پرکردن تنبل (lazy)
├─ Evaluator        ← ارزیابی درخت در برابر ContextBag (در حافظه، هر درخواست)
└─ SqlCompiler      ← کامپایل درخت به SQL پارامتری (برای شمارش سگمنت)

Rules/RuleEngine     → decide( string $scope, ContextBag $bag ): Decision
Segments/SegmentResolver → slugs_for_user( int $user_id ): string[]
                         → count( ConditionTree $tree ): int
```

`ContextBag` همان انتزاع مشترکی است که سه ماژول را به هم می‌بندد. هر ماژول فیلدهای خودش را ثبت می‌کند:

| مالک | فیلدها |
|---|---|
| مشترک | `user_id`, `segment_slugs`, `order_count`, `lifetime_value`, `is_returning`, `country`, `device`, `hour_of_day` |
| Recommendation | `product_id`, `product_cat`, `product_stock_status`, `cart_total`, `surface` |
| AbandonedCart | `cart_total`, `items_count`, `has_phone`, `stage`, `hours_since_abandon`, `contains_low_stock` |
| Forecast | `product_id`, `days_to_stockout`, `growth_pct`, `urgency`, `trend` |

هر فیلد `scopes[]` خودش را اعلام می‌کند، پس سازنده‌ی قاعده در UI فقط فیلدهای معتبر آن scope را نشان می‌دهد، و `Evaluator` برای فیلد ناشناخته شرط را `false` می‌کند نه crash.

نتیجه: مثال خودت بدون یک خط منطق جدید ساخته می‌شود —

```
scope: abandoned_cart          |  scope: recommendation
match: all                     |  match: all
  segment           in [vip]   |    product_stock_status = outofstock
  cart_total        > 5000000  |  actions:
  contains_low_stock = true    |    boost: engine=substitute_products
actions:                       |
  set_priority: +25            |
  notify: telegram_webhook     |
```

## انتخاب دو سطحی استراتژی بازسازی — طبق اصلاح تو

`Affinity\StrategySelector` قبل از هر بازسازی اندازه می‌گیرد:

```php
$has_lookup = تشخیص وجود wc_order_product_lookup و wc_order_stats
$orders     = COUNT سفارش‌های پرداخت‌شده در پنجره
$rows       = COUNT ردیف‌های lookup در پنجره

! $has_lookup            → 'orders_api_batched'      (پیمایش wc_get_orders دسته‌ای)
$rows <=   200_000       → 'sql_single'              (یک کوئری تجمعی)
$rows <= 2_000_000       → 'sql_batched'             (دسته بر بازه‌ی product_id)
بیشتر                    → 'sql_batched_sampled'     (پنجره کوچک‌تر + min_support بالاتر)
```

آستانه‌ها به‌صورت تنظیم و فیلتر، و نتیجه‌ی هر اجرا در یک option ثبت می‌شود: `{strategy, started, finished, rows_scanned, pairs_written, duration_ms}` که در صفحه‌ی سلامت دیتابیس نمایش داده شود.

نکته‌ی صادقانه: آستانه‌ی ۲۰۰ هزار و ۲ میلیون فعلاً **حدس مهندسی** است. قبل از قفل کردنشان باید روی یک دیتاست واقعی `EXPLAIN` بگیریم و زمان اجرای self-join را اندازه بگیریم. این را به‌عنوان یک تسک benchmark در فاز ۲ می‌گذارم، نه به‌عنوان عدد قطعی.

---

# جمع‌بندی

چهار ستون مشخص شدند: **۱۲ جدول** با فازبندی، **۱۳ نوع رویداد** با مرز امنیتی صریح روی سه رویداد مجاز از کلاینت، **۶ وضعیت و ۵ مرحله‌ی** سبد با تمایز حیاتی `recovered` از `converted_direct`، و **قرارداد موتور** با `Result` غنی به‌جای آرایه‌ی عدد، به‌علاوه‌ی `Support/Conditions` به‌عنوان سرویس مشترک هر سه ماژول.

سه تسک benchmark هم ثبت شد که نتیجه‌شان قبل از قطعی‌سازی لازم است: اندازه‌ی واقعی جدول در ascii در برابر utf8mb4، پلن اجرای self-join روی `wc_order_product_lookup`، و آستانه‌های انتخاب استراتژی.

می‌خواهی این چهار مشخصات را به‌عنوان یک سند در `docs/` مخزن ثبت کنم تا در فازهای بعد مرجع باشد، و بعد فاز ۰ (اسکلت + `Core` + `Schema`) را شروع کنم؟
