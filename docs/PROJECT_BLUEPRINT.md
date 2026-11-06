# نقشهٔ راه جامع پروژه — PHP & SQL Web Platform

> وضعیت: سند زنده (Living Document) — پس از دریافت شرح پروژه، نسخهٔ ۱٫۰ می‌شود.
> تاریخ ایجاد: ۲۰۲۶-۱۱-۰۶

این سند چارچوب اجرای یک پروژهٔ بزرگ و حساس PHP + SQL را مشخص می‌کند: آنچه تأیید نهایی را می‌خواهد، معماری پیشنهادی، اصول امنیت، فازبندی و اقلام نیاز از کارفرما.

---

## ۱) وضعیت واقعی محیط اجرا (مهم)

| موضوع | وضعیت |
|---|---|
| مخزن | تقریباً خالی است؛ فقط `README.md` |
| PHP در این sandbox | **نصب نیست** (و نصب پکیج سیستمی به دلیل سطح دسترسی ممکن نیست) |
| شبکهٔ خروجی | فقط `github.com`, `codeload.github.com`, `api.github.com`, `registry.npmjs.org`, `pypi.org` مجاز است |
| نتیجه | کد PHP و SQL را می‌نویسم و می‌توانم با ابزارهای npm (مثل `php-parser`) بررسی نحوی (syntax) کنم؛ **اجرای واقعی PHP/MySQL باید در محیط خودتان (یا سرور/Docker) انجام شود** |

## ۲) تصمیمات فنی پیشنهادی (در انتظار تأیید شما)

| لایه | پیشنهاد پیش‌فرض | دلیل |
|---|---|---|
| زبان/نسخه | PHP 8.3 (حداقل 8.2) | JIT، typed properties و readonly classes |
| فریم‌ورک | **Laravel 11** (در صورت هماهنگی: Symfony یا PHP ساخت‌یافتهٔ بدون فریم‌ورک) | استاندارد صنعت برای پروژه‌های بزرگ، اکوسیستم بالغ، امنیت پیش‌فرض |
| دیتابیس | MySQL 8 یا MariaDB 10.11+ با موتور InnoDB | درخواست شما (SQL)، پایداری و ابزارهای عالی |
| کش/صف | Redis (اختیاری اما توصیه‌شده) | صف کارهای سنگین، session، cache |
| وب‌سرور | Nginx + PHP-FPM 8.3 | استاندارد production |
| دیپلوی | Docker Compose (یا سرور واقعی) | راه‌اندازی تکرارپذیر و ایزوله |
| فونت/رابط | فارسی، RTL، فونت Vazirmatn | در صورت فارسی بودن مخاطب |

جزئیات و وضعیت این تصمیمات در `docs/adr/0001-stack.md` ثبت شده است.

## ۳) معماری پیشنهادی (لایه‌ای، Domain-Centric)

```
Presentation  →  Controllers / API Resources / Blade views (فقط I/O و اعتبارسنجی ورودی)
Application   →  Use-cases / Services (Orchestration, Transaction boundaries)
Domain        →  Entities, Value Objects, Domain Rules (بدون وابستگی به فریم‌ورک)
Infrastructure→  Eloquent/PDO Repositories, Mail, Storage, external APIs
```

اصول غیرقابل‌مذاکره:
- هیچ Query مستقیم در Controller؛ دسترسی داده فقط از Repository/Model.
- تمام عملیات چندمرحله‌ای داخل DB Transaction با سطح جدایی مناسب.
- State-changing endpointها: CSRF token (وب) یا Bearer token (API) + rate limit.
- اعتبارسنجی دوطرفه: سمت فرم (UX) و سمت سرور (اجباری).
- `.env` هرگز commit نمی‌شود؛ رازها فقط از Environment Variables خوانده می‌شوند.

## ۴) چک‌لیست امنیت برای «پروژهٔ حساس»

- [ ] PDO با named parameters / Eloquent — صفر string-concatenation در SQL
- [ ] `password_hash(..., PASSWORD_ARGON2ID)` و `password_verify` — هرگز الگوریتم دستی
- [ ] Session: `httponly`, `secure`, `samesite=strict|lax`، regenerate پس از login
- [ ] CSRF protection روی همهٔ POST/PUT/DELETE
- [ ] RBAC (نقش/دسترسی) + Audit Log (چه کسی، چه زمانی، چه تغییری)
- [ ] رمزنگاری ستون‌های بسیار حساس با AES-256-GCM (کلید از KMS/env)
- [ ] Rate limiting روی login، OTP و API؛ قفل حساب پس از تلاش ناموفق
- [ ] آپلود فایل: whitelist پسوند + MIME واقعی، نام تصادفی، خارج از public root
- [ ] Security headers: CSP, X-Frame-Options, HSTS, X-Content-Type-Options
- [ ] وابستگی‌ها: بررسی آسیب‌پذیری (`composer audit`) در CI
- [ ] بکاپ خودکار دیتابیس + تست بازیابی (restore drill)
- [ ] لاگ‌برداری بدون دادهٔ حساس (PII) و با retention مشخص

## ۵) قراردادهای دیتابیس

- Charset: `utf8mb4` / Collation: `utf8mb4_unicode_ci`
- PK: `BIGINT UNSIGNED AUTO_INCREMENT` (یا UUID برای جداسازی سرویس‌ها)
- همهٔ جداول: `created_at`, `updated_at`؛ Soft Delete با `deleted_at` در جداول مهم
- FKها با `ON DELETE RESTRICT` پیش‌فرض؛ ایندکس روی ستون‌های جستجو/join
- Migration به‌جای SQL خام دستی؛ Seed برای دادهٔ اولیه؛ هیچ تغییر مستقیم روی production

## ۶) ساختار مخزن (پس از تأیید استک)

```
20142014/
├─ app/            # هسته (یا src/ در صورت PHP خالص)
├─ database/       # migrations, seeders, factories
├─ public/         # index.php, assets (تنها مسیر قابل‌دستیابی وب)
├─ resources/      # views, lang (fa/en), assets
├─ routes/         # web.php, api.php
├─ tests/          # unit, feature, security
├─ docker/         # nginx, php-fpm, mysql init
├─ docs/           # همین سند، SRS، ADRها، ERD
└─ .github/        # CI (lint + tests)
```

## ۷) فازبندی اجرا

| فاز | محتوا | خروجی |
|---|---|---|
| **۰ — تحلیل** | دریافت شرح پروژه، مخاطبان، ماژول‌ها، قوانین | `docs/SRS.md` + ERD + وایرفریم |
| **۱ — زیرساخت** | اسکافولد، Docker، CI، استانداردها (PSR-12) | اسکلت قابل‌اجرا |
| **۲ — هسته** | Auth، RBAC، Audit Log، مدیریت کاربران | هستهٔ امن و تست‌شده |
| **۳ — ماژول‌ها** | پیاده‌سازی دامنه‌ای ماژول‌های شما | قابلیت‌های بیزینسی |
| **۴ — سخت‌سازی** | Security review، تست بار، بهینه‌سازی کوئری | گزارش آمادگی production |
| **۵ — استقرار** | محیط production، مانیتورینگ، بکاپ | سیستم زنده |

## ۸) آنچه برای شروع واقعی لازم دارم

1. **شرح پروژه**: هدف، مخاطبان، و فهرست امکانات (یا مسئله‌ای که حل می‌کند)
2. **ماژول‌ها و نقش‌ها** (مثلاً مدیر/کاربر/فروشنده) و گردش‌کار اصلی
3. هرگونه **اسکیمای دیتابیس، طرح گرافیکی، دامنهٔ نیم و هاستینگ** موجود
4. **حساسیت داده‌ها**: چه چیزی PII/مالی/محرمانه است تا سطح رمزنگاری مشخص شود
5. **اولویت فاز اول**: MVP چیست؟

## ۹) ریسک‌ها

- اجرای محلی PHP در این محیط ممکن نیست → اعتبارسنجی runtime سمت شما انجام می‌شود
- ابهام در نیازمندی = بزرگ‌ترین ریسک پروژهٔ بزرگ؛ فاز ۰ به همین دلیل اجباری است
- پروژهٔ «حساس» بدون بازبینی امنیتی نهایی نباید به production برود
