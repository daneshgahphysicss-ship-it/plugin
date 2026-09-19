# راهنمای راه‌اندازی محیط اجرا (گام ۲) — روی سیستم خودت

پیش‌نیاز: **Docker Desktop** و **Node.js 20+**. (PHP لازم نیست؛ داخل کانتینر است.)

## ۱. اولین بار

```bash
git clone <این مخزن> && cd plugin
npm install                 # فقط @wordpress/env
npm run env:start           # دانلود WP + WooCommerce، بالا آمدن MySQL (۲–۵ دقیقه بار اول)
npm run setup               # تنظیمات فروشگاه، فارسی، seed، ساخت affinity
```

بعد از پایان:
- فروشگاه: http://localhost:8888
- پنل: http://localhost:8888/wp-admin — کاربر `admin` رمز `password`

## ۲. راستی‌آزمایی گام ۴ و ۵ (چیزی که باید برایم بفرستی)

```bash
npm run wp -- fws status               # ۱۳ جدول با exists=yes → گام ۴ ✓
npm run wp -- fws affinity check       # جدول با ستون ok همه ✓ → گام ۵ ✓
```

خروجی مورد انتظار `affinity check` (اعداد دقیقاً همین‌ها باید باشند):

| pair | expected | db | score_expected | score_db | ok |
|---|---|---|---|---|---|
| total(0) | 150 | 150 | | | ✓ |
| total(1) | 130 | 130 | | | ✓ |
| total(2) | 50 | 50 | | | ✓ |
| total(3) | 20 | 20 | | | ✓ |
| 0-1 | 130 | 130 | 0.9309 | 0.9309 | ✓ |
| 0-2 | 50 | 50 | 0.5774 | 0.5774 | ✓ |
| 0-3 | 20 | 20 | 0.3651 | 0.3651 | ✓ |
| 1-2 | 30 | 30 | 0.3721 | 0.3721 | ✓ |
| 2-3 | 20 | 20 | 0.6325 | 0.6325 | ✓ |

چرا این اعداد؟ Seeder سه دستهٔ سفارش با ترکیب معلوم می‌سازد (۱۰۰ سفارش {۰،۱}، ۳۰ سفارش {۰،۱،۲}، ۲۰ سفارش {۰،۲،۳}) و ۱۵۰۰ سفارش تصادفی که **هرگز** به این ۴ محصول دست نمی‌زنند. پس `score(0,1) = 130 / √(150×130) = 0.9309` با دست قابل محاسبه است. اگر DB همین را بدهد، موتور کسینوس درست کار می‌کند.

## ۳. کیفیت کد (گام ۳)

```bash
npm run composer -- install
npm run lint                           # PHPCS
npm run composer -- analyse            # PHPStan سطح 6 — بار اول احتمالاً چند ده خطا می‌دهد؛ همان‌ها کار گام ۳ هستند
npm run test:unit
npm run test:integration               # SchemaTest داخل محیط tests
```

## ۴. دستورات روزمره

| کار | دستور |
|---|---|
| خاموش/روشن | `npm run env:stop` / `npm run env:start` |
| seed از نو | `npm run seed:reset && npm run seed` |
| فقط fixture (بدون سفارش تصادفی) | `npm run wp -- fws seed --reset --orders=0` |
| بازسازی affinity | `npm run affinity` |
| لاگ‌ها | `npm run logs` |
| پاک کردن کامل | `npm run env:destroy` |

## ۵. چه چیزی را برایم بفرستی

1. خروجی کامل `fws status` و `fws affinity check`
2. خروجی `composer analyse` (حتی اگر طولانی است — این ورودی گام ۳ است)
3. اگر جایی خطا داد: متن خطا + `npm run logs` (۵۰ خط آخر)

## عیب‌یابی رایج

- **پورت ۸۸۸۸ اشغال است:** `WP_ENV_PORT=8890 npm run env:start`
- **`wp fws` شناخته نمی‌شود:** mu-plugin `tools/wp-env/fws-dev.php` باید در `.wp-env.json` map شده باشد؛ `npm run env:destroy && npm run env:start`
- **`affinity check` می‌گوید total کمتر است:** `affinity_window_days` (پیش‌فرض ۱۸۰) از `--days` (۱۲۰) کمتر شده؛ یا Analytics sync ناقص بوده → `npm run wp -- wc admin import-orders` سپس `npm run affinity`
