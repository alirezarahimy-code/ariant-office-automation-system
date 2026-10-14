# فاز ۳ — تحویل: تکمیل زیرسامانه‌های گام-۰ تا نهایی‌سازی

> وضعیت: **تحویل‌شده** — شاخه `arena/ace87255-…` → PR → `main`
> آزمون‌ها: **۲۶۷ سبز** (از ۱۸۳ پایان فاز ۲)
> تاریخ: ۱۴۰۴/۰۷/۲۳

## ۱. خلاصه

پس از ادغام فاز ۲ (PR #2)، با دستور «همه فازهای باقی‌مانده تا نهایی شدن»،
هشت بلوک پشت‌سرهم ساخته و ادغام شد. این فاز ردیف‌های گام-۰ شناسنامه بستر
(docs/08 بند ۳) را از «برنامه‌ریزی‌شده» به «بومی» برد و باقی‌مانده فاز ۱
نقشه راه (docs/01) را تکمیل کرد. زیرسامانه «چاپ فیش حقوقی» طبق تصمیم
مدیریت از دامنه این فاز کنار گذاشته شد و در manifest باقی ماند.

## ۲. بلوک‌های تحویلی

| # | بلوک | مسیرها | آزمون |
|---|------|--------|-------|
| ۱ | کنترل تردد | `backend/apps/attendance/` — `/attendance/` | ۲۶ |
| ۲ | انبار | `backend/apps/inventory/` — `/inventory/` | ۲۰ |
| ۳ | بازرگانی | `backend/apps/commerce/` — `/commerce/` | ۱۹ |
| ۴ | تکمیل تیکتینگ | `migrate_php_tickets`, `ticket_cutover_check` | ۲۲ |
| ۵ | باقی‌مانده فاز ۱ | `core/{calendar_services,models,views}` — `/accounts/{profile,settings,users}/` | ۳۴+ |
| ۶ | یکپارچه‌سازی | `core/{notify_channels,ldap_backend}.py` | — |
| ۷ | گزارش‌ساز | `reports/{datasets,export,models}.py` — `/reports/builder/` | ۲۰ |
| ۸ | نهایی‌سازی | docs/20، deploy، کاشت نمایشی | — |

## ۳. کنترل تردد (docs/01 فاز ۴ — بخش حضور و غیاب)

- `WorkSchedule`: ساعت شروع/پایان، روزهای کاری هفته (شنبه=۰…جمعه=۶)، مهلت تأخیر
- `AttendanceRecord`: یک رکورد در روز برای هر کاربر (unique)؛ منبع: دستی/دستگاه/درون‌ریزی
- سرویس‌ها: `day_metrics` (به‌موقع/با تأخیر/خروج ثبت نشده/غایب + دقیقه تأخیر پس از مهلت و اضافه‌کاری)، `monthly_summary` (خلاصه ماه + ردیف روزانه)، `register_record` (idempotent — مسیر واحد دستی/دستگاه)
- نماها: فهرست ماهانه با فیلتر کاربر (مدیر) و ماه شمسی، ثبت دستی، گزارش ماهانه کارکرد
- `seed_attendance_demo`: ۳۰ روز تردد قطعی-تصادفی برای ۶ کاربر نمایشی

## ۴. انبار

- `Warehouse`, `Item` (کد/نام/واحد/حداقل موجودی)، `StockMove` (رسید + / حواله − / تعدیل امضا دار)
- اصل طراحی: **موجودی ذخیره نمی‌شود**؛ همیشه از جمع اسناد (`signed_quantity`) محاسبه می‌شود
- سرویس‌ها: `receive`/`issue` (با کنترل موجودی)، `current_stock`، `item_card` (کارت کالا با موجودی پیوسته)، `low_stock_items`
- انبارگردانی: `StockCount` + ردیف‌ها → `apply_count` اختلاف شمارش با موجودی را سند تعدیل می‌زند
- نماها: فهرست کالا + هشدار زیر حداقل، کارت کالا، ثبت سند، صفحه موجودی/انبارگردانی

## ۵. بازرگانی

- زنجیره: `PurchaseRequest` (پیش‌نویس → ارسال → تأیید/رد → انجام) → `Inquiry` (استعلام‌ها + انتخاب انحصاری) → `Contract`
- اعلان به مدیران هنگام ارسال و به درخواست‌کننده هنگام تصمیم
- نماها: فهرست با تب‌ها، جزئیات با دکمه‌های تصمیم/استعلام/انتخاب، قراردادها

## ۶. تکمیل تیکتینگ (گذار docs/04)

- `migrate_php_tickets file.json`: درون‌ریزی خروجی JSON سامانه PHP → آینه (`ExternalTicket`) → بومی (idempotent با کلید `سامانه:شناسه`)
- `ticket_cutover_check`: گزارش فقط‌خواندنی آمادگی سوییچ `TICKET_MODE=native` — شمارش آینه/بومی، وضعیت‌های بدون نگاشت، کاربران بدون نگاشت، شمار مانع‌ها
- قالب فایل JSON در مستندات همان فرمان آمده است

## ۷. باقی‌مانده فاز ۱ نقشه راه

- **تعطیلات رسمی**: `Holiday` + `core/calendar_services.py`:
  `is_holiday`, `is_workday`, `next_workday`, `add_workdays`, `workdays_between`
  (پایه محاسبه مهلت و SLA بر روزهای کاری). فرمان `seed_holidays` برای
  تعطیلات ثابت شمسی ۱۴۰۴–۱۴۰۶؛ تعطیلات قمری با `--file` یا پنل.
- **تنظیمات سامانه**: `OrgSettings` تک‌رکوردی (نام سازمان/شعار/سال مالی) در
  `/accounts/settings/` — نام در همه صفحه‌ها از دیتابیس خوانده می‌شود.
- **پروفایل و امضا**: `UserProfile` (تماس + امضای الکترونیک) در `/accounts/profile/`.
- **مدیریت کاربران UI**: `/accounts/users/` — فهرست، جست‌وجو، ویرایش هویت و فعال/غیرفعال.

## ۸. یکپارچه‌سازی

- **بله/ایتا**: `core/notify_channels.py` با الگوی قابل تعویض SMS
  (`CHAT_BALE_BACKEND`/`CHAT_ITA_BACKEND`) — `ConsoleChatBackend` برای
  توسعه، `RestChatBackend` برای درگاه REST. همه‌چیز روی هاب `send_notification`
  و پشت پرچم‌های env؛ هیچ خطای کانالی اعلان را نمی‌شکند.
- **LDAP/AD**: `core/ldap_backend.py` — بک‌اند `LDAPAuthBackend` پشت پرچم
  `AUTH_LDAP_ENABLED`؛ بدون python-ldap یا با پرچم خاموش، بی‌صدا کنار می‌رود
  و ورود محلی دست‌نخورده است.

## ۹. گزارش‌ساز پیشرفته (docs/01 فاز ۷)

- مجموعه‌داده‌ها (`reports/datasets.py`): نامه‌ها، وظایف، تردد، تیکت‌ها — همه با بازه تاریخ شمسی
- خروجی‌ها (`reports/export.py`): **CSV** (با BOM برای اکسل فارسی)، **XLSX** (openpyxl)، **PDF فارسی** (reportlab + arabic-reshaper با فونت `REPORTS_PDF_FONT`؛ بدون فونت پاسخ راهنما) و **نسخه چاپی** (print CSS مرورگر)
- `ScheduledReport` + فرمان `dispatch_scheduled_reports`: ارسال دوره‌ای (روزانه/هفتگی/ماهانه) با پیوست CSV به فهرست رایانامه‌ها؛ cron نمونه در `deploy/cron.example`
- نمای `/reports/builder/` در منوی «میز کار»

## ۱۰. وضعیت نهایی manifest بستر

| کلید | عنوان | وضعیت | گام |
|------|-------|-------|-----|
| secretariat | دبیرخانه و مکاتبات | native | ۳ |
| workflow | گردش کار | native | ۳ |
| archive | بایگانی و امحا | native | ۳ |
| meetings | جلسات و مصوبات | native | ۳ |
| tasks | وظایف و تقویم | native | ۳ |
| reports | گزارش‌ها و داشبورد | native | ۳ |
| ticketing | تیکتینگ | migrating | ۳ |
| access_control | کنترل تردد | **native** | ۳ |
| warehouse | انبار | **native** | ۳ |
| commerce | امور بازرگانی | **native** | ۳ |
| fiscal_print | چاپ فیش حقوقی | planned | ۰ |

## ۱۱. دامنه‌های عامدانه کنار گذاشته‌شده

- **چاپ فیش حقوقی** — به تصمیم مدیریت از این فاز حذف شد (در manifest ماند؛
  بسترهای لازمش یعنی تردد و کارکرد ساخته شده‌اند).
- **بازنویسی/مهندسی معکوس سامانه حضور و غیاب فعلی سازمان** — سناریوی جدا
  که بعداً با دریافت فایل‌های سامانه مبدأ آغاز می‌شود؛ درگاه دستگاه‌ها
  (`AttendanceRecord.Source.DEVICE`) برایش آماده است.
- **نگاشت SQL Server** (`DB_ENGINE=mssql` با mssql-django) — نیازمند نشست
  توسعه اختصاصی + دور آزمون روی خود SQL Server است.

## ۱۲. دستورهای عملیاتی تازه

```bash
python manage.py seed_attendance_demo     # داده نمایشی تردد
python manage.py seed_inventory_demo      # داده نمایشی انبار
python manage.py seed_holidays            # تعطیلات رسمی ثابت (۱۴۰۴–۱۴۰۶)
python manage.py migrate_php_tickets export.json   # مهاجرت تیکت‌های PHP
python manage.py ticket_cutover_check     # آمادگی سوییچ native
python manage.py dispatch_scheduled_reports [--force]  # گزارش‌های دوره‌ای
```

## ۱۳. معیارهای پذیرش

- [x] ۲۶۷ آزمون سبز (دور کامل `manage.py test`)
- [x] هر بلوک با مهاجرت + آزمون + کاشت نمایشی ادغام شد
- [x] UI هم‌سبک «آرام و شفاف» — RTL، شمسی، ارقام فارسی
- [x] manifest به‌روز شد (۹ بومی، ۱ در گذار، ۱ برنامه‌ریزی‌شده)
- [x] `deploy/env.example` و `deploy/cron.example` به‌روز شدند
- [x] هیچ وابستگی ابری جدیدی در مسیر بحرانی اضافه نشد
