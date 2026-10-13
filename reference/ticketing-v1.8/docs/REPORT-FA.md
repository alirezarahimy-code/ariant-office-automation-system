# گزارش خروجی: حذف وابستگی سفارش غذا به اکسس

- شاخه: `arena-updates`
- آخرین commit: `8c0b448`
- مبنای مقایسه: `fee7655` (Initial commit)
- فایل‌های این خروجی:
  - `ticket-arena-updates-8c0b448.zip`: کل کد شاخه (بدون `.git`)
  - `CHANGES-from-original.patch`: کل تغییرات نسبت به `fee7655` (قابل اعمال با `git apply`)
  - `REPORT-FA.md`: همین گزارش

## ۱. هدف

- سفارش غذا فقط از جدول داخلی `food_orders` (MySQL) خوانده شود.
- وابستگی به فایل Access سفارش (`food_fish`، `orders_path`، `orders_table`) کامل حذف شود.
- تنها منبع خارجی باقی‌مانده: فایل Access تردد **TENTER** (`SOURCE_TABLE`).

## ۲. جریان نهایی

```
TENTER (Access، تردد) → Worker (food-ticket-engine.php)
  → تطبیق کد ملی با food_orders (MySQL، وضعیت active، همان روز)
  → رویداد در food_ticket_events → صف چاپ
  → پرینتر شبکه (TCP 9100) یا ویندوز
  → حذف ردیف از TENTER بعد از ثبت موفق
```

## ۳. تغییرات (خلاصه)

| commit | شرح |
|---|---|
| `40b58e8` | افزودن `ARCHITECTURE.md` (نقشه سامانه با تگ‌ها) |
| `9f7130f` | حذف سرویس .NET (`Program.cs`، `FoodTicketServer.csproj`، `config.example.json`، `install_prerequisites.bat`، `start_food_ticket.bat`، `README-prerequisites-fa.md`)؛ حذف `food_ticket_orders()`؛ حذف `orders_path`/`orders_table` از ذخیره تنظیمات، API و `schema.sql`/`install-empty-db.sql`؛ به‌روزرسانی تست‌ها و چک‌لیست؛ افزودن `00_CHANGELOG_INTERNAL_ORDERS_ONLY_FA.txt` |
| `67da1e9` | **اصلاح باگ:** در `INSERT` تنظیمات، ۱۴ placeholder برای ۱۳ مقدار بود و ذخیره‌ی تنظیمات fail می‌کرد. اصلاح شد. |
| `ef01cff` | حذف readerهای مرده‌ی اکسس سفارش: `food_ticket_order_map`، `food_ticket_orders_rows_for_keys`، `food_ticket_date_text_variants`، `food_ticket_row_column`؛ حذف route قدیمی `test-orders`؛ ساده‌سازی نمای سفارش‌ها در پنل (حذف انتخاب‌گر «منبع») |
| `8c0b448` | حذف کش pytest که اشتباهی commit شده بود؛ افزودن `.gitignore` |

آمار کلی نسبت به `fee7655`: ۱۷ فایل تغییر کرده، ۲۷۷ خط اضافه و ۱۸۹۱ خط حذف.

## ۴. بررسی‌های انجام‌شده

- **Lint:** `php -l` روی همه‌ی ۱۰۶ فایل PHP مخزن: بدون خطای syntax (PHP 8.4).
- **تست واقعی دیتابیس (MariaDB 11.8):** کوئری ذخیره‌ی تنظیمات:
  - نسخه‌ی `9f7130f`: خطا (`Column count doesn't match value count`)
  - نسخه‌ی `8c0b448`: موفق
- **تست‌های PHP:** خروجی `SecurityTest.php`، `ArchitectureTest.php`، `test_food_ticket_logic.php` با نسخه‌ی قبل از تغییرات **کاملاً یکسان** است. این fail ها از قبل در مخزن بوده‌اند (مثلاً `Rebuild leaves schema tables behind`، `Directory storage not found`، و ۴ مورد `norm_personnel_*` و `code_decimal_preserves_zeroes`).
- **`test_food_ticket_config_paths.php`:** موفق.
- **pytest `tests/test_food_orders_static.py`:** ۲۸ تست موفق.
- **Node:** `test_food_order_calendar_node.js` موفق؛ بخش inline JS پنل (`index.html`) و `food-menu.js` و `food-order-calendar.js` syntax درست دارند.
- **Routeهای پنل:** همه‌ی routeهایی که پنل صدا می‌زند در PHP پیاده‌سازی شده‌اند؛ هیچ route‌ای که فقط سرویس .NET داشته باشد گم نشده.

## ۵. آنچه تأیید نشده (نیاز به تست روی سرور)

- اجرای واقعی Worker روی فایل TENTER (Access) و حذف ردیف‌ها.
- چاپ واقعی روی پرینتر شبکه/ویندوز.
- رفتار پنل در مرورگر (نمای سفارش‌ها، ذخیره‌ی تنظیمات از UI).
- این محیط Access و پرینتر ندارد، پس این موارد با تست دستی روی سرور تست تأیید شوند.

## ۶. باقی‌مانده (عمداً دست نخورده)

- ستون‌های `orders_path`/`orders_table` در نصب‌های قدیمی (و در `upgrade-1.9-food-ticket.sql`) هنوز وجود دارند. کد به آن‌ها دست نمی‌زند و مقدار پیش‌فرض خالی دارند. اگر حذف کامل بخواهی، یک migration جدا لازم است.
- فایل‌های fixture در `tools/` (مثل `food_fish` در `test_group_flow_offline.php`) فقط داده‌ی تستی هستند و روی اجرای عادی اثری ندارند.
- `food_order_mode()` هنوز مقادیر قدیمی `ACCESS`/`TEST` را می‌پذیرد و همه را به `INTERNAL-DB` تبدیل می‌کند؛ هیچ اتصال Access سفارش باز نمی‌شود.
- تست‌های fail شده‌ی بالا (از قبل وجود داشتند) برای رفع جدا لازم‌اند.

## ۷. مشکلات شناخته‌شده‌ای که هنوز بررسی نشده‌اند

- صف چاپ (`food_ticket_process_print_queue`) در این تغییرات دست نخورده، ولی باگ‌های احتمالی آن (مثلاً قفل و تلاش مجدد) هنوز اصلاح نشده‌اند.
- گزارش «شکایت» کاربر درباره‌ی صف چاپ: بعد از اصلاح باگ `67da1e9` هنوز تأیید نشده است. لاگ Worker و `food_ticket_print_log` لازم است تا علت دقیق مشخص شود.

## ۸. به‌روزرسانی: حذف فرم «سفارش‌های غذا» از پنل چاپ فیش

- commit: `818b3b2` (شاخه `arena-updates`)
- حذف شد:
  - آیتم منوی «سفارش‌های غذا» و ورودی آن در لیست نقش‌های مدیر.
  - توابع `orders()`، `ordersForm()`، `ordersView()`، `ordersReadonly()` و اورایدهای `removeOrderPersonnelCodeColumn`.
- باقی ماند (کاربرد دارد): کارت «سفارش امروز» داشبورد، `/api/orders`، و گزارش «سفارش غذا».
- بررسی: `node`/`acorn` syntax اسکریپت داخلی پنل بدون خطا؛ هیچ ارجاع معلق به توابع حذف‌شده نیست؛ `pytest` (۲۸ تست) و تست گاهی `test_food_order_calendar_node.js` موفق.
- هنوز باقی‌مانده (کد مرده، بی‌ضرر): هندلرهای `refresh-orders`/`today-orders`/`#order-filter` داخل listenerهای عمومی که دیگر المانی ندارند. حذف امن آنها نیاز به بازنویسی listenerهای مشترک دارد؛ در صورت نیاز جدا انجام می‌شود.
- تأیید نشده: رندر واقعی پنل در مرورگر (در این محیط مرورگر/jsdom در دسترس نیست).

فایل‌های به‌روز: `ticket-arena-updates-818b3b2.zip` و `CHANGES-from-original.patch`.

## ۹. به‌روزرسانی: پاک‌سازی باقی‌مانده‌ها، ایمپورت سفارش‌های قبلی

کامیت‌های `arena-updates` از `818b3b2` تا `fa8adcb`:

| کامیت | موضوع |
|---|---|
| `8ba9db7` | داشبورد و گزارش «سفارش غذا» از `food_orders` داخلی؛ `/api/orders` پارامتر `to` گرفت |
| `20ec5b8` | رفع گیر کردن پنل روی «در حال آماده‌سازی» بعد از اولین ذخیره (`state.orders` بعد از بارگذاری تعریف نمی‌شد) |
| `c5c24db` | حذف پارامتر بلااستفادهٔ `$ordersConnection`؛ به‌روزرسانی README و راهنمای تست |
| `5cf7dfa` | فایل migration برای حذف ستون‌های `orders_path` / `orders_table` از `food_ticket_config` (اختیاری) |
| `5864d97` | ایمپورت سفارش‌های قبلی (`food-order-import.php`) |
| `fa8adcb` | ایمپورت با همهٔ فیلدها (کد ملی، نام، نام خانوادگی، نهار، تاریخ انتخابی، تاریخ و ساعت رزرو) و ورودی منوی اصلی |

**وضعیت بررسی‌ها:**
- `php -l` روی همهٔ فایل‌های PHP بدون خطا.
- `pytest tests/test_food_orders_static.py`: ۲۸ مورد پاس.
- `tests/test_food_ticket_logic.php`: ۴ مورد شکست که از قبل وجود داشت (norm_personnel، code_decimal) و تغییر نکرده‌اند.
- پنل با jsdom (ورود ساختگی و پاسخ‌های ساختگی API): بوت شد، همهٔ بخش‌های منو بدون خطا باز شدند، و گزارش سفارش داده را نشان داد.
- ایمپورت روی MariaDB آزمایشی: تطبیق، تعارض، تکراری در فایل، بازیابی سفارش لغوشده، ثبت، و اجرای مجدد (idempotent) درست بودند.

**آنچه هنوز تأیید نشده (نیاز به تست روی سرور):**
- صف چاپ فیش روی سرور واقعی با یک تردد و چاپ واقعی.
- ایمپورت از داخل پنل زنده روی داده‌های واقعی (ابتدا با یک فایل کوچک).
- اجرای `upgrade-food-drop-legacy-orders-columns.sql` روی نصب‌های قدیمی (اختیاری).

**باقی‌مانده (عمداً دست نخورده):**
- `ARCHITECTURE.md` و فایل‌های changelog قدیمی به نام `food_fish` ارجاع دارند؛ به‌عنوان تاریخچه نگه داشته شده‌اند.
- فایل‌های `tools/` برای تست‌های آفلاین هنوز نام‌های قدیمی دارند؛ در مسیر اجرای سامانه نیستند.
- ۴ تست قدیمی `test_food_ticket_logic.php` که از قبل شکست می‌خوردند.
