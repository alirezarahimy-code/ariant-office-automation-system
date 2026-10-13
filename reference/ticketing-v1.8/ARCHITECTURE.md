# نقشه کلی سامانه (ARCHITECTURE)

> این فایل نقشهٔ مرجع سامانه است تا در گفتگوهای بعدی لازم نباشد دوباره کل مخزن گشته شود.
> هر بخش یک **تگ** دارد (مثلاً `[TK-CORE]`). برای پیدا کردن سریع، اسم تگ را جست‌وجو کن.
> وضعیت هر مورد: ✅ بررسی‌شده · 🟡 بخشی بررسی‌شده · ❓ نیاز به بررسی
> تاریخ آخرین بروزرسانی: 2026-10-09 · شاخه: `arena-updates`

---

## ۱. نمای کلی

سامانه از **دو بخش اصلی** تشکیل شده که دیتابیس MySQL مشترک دارن:

```
                ┌──────────────────────────────────────────┐
                │        MySQL (دیتابیس داخلی ticket)      │
                └──────────────▲───────────────▲───────────┘
                               │               │
   ┌───────────────────────────┴──┐      ┌─────┴──────────────────────────┐
   │ [ITSM] سامانه تیکتینگ و دارایی│      │ [FOOD] سامانه غذا / فیش / تردد  │
   │ index.php + bootstrap.php     │      │ food-ticket*.php + food-order  │
   │ + ماژول‌های روی index         │      │ + cron/food_ticket_worker.php  │
   └───────────────────────────────┘      └──────────────▲─────────────────┘
                                                         │ (محرک چاپ)
                                          ┌──────────────┴─────────────────┐
                                          │ فایل Access تردد (TENTER)       │
                                          │ [SRC-TENTER] — هنوز وابستگی اصلی │
                                          └────────────────────────────────┘
```

- **[ITSM]** سامانه اصلی: تیکت، دارایی، تردد، گزارش، ادمین.
- **[FOOD]** ماژول غذا و فیش: سفارش، تطبیق تردد با سفارش، صف چاپ، چاپ روی پرینتر.
- **[SRC-TENTER]** فایل Access دستگاه تردد. الان محرک چاپ فیش است و **داخلی نیست**.

---

## ۲. ورودی‌ها (Entry points)

| تگ | فایل | نقش | وضعیت |
|---|---|---|---|
| `[EP-WEB]` | `index.php` (۴۶۱۹ خط، ۲۳ تابع) | روتر اصلی وب. `$page` از `?page=` خوانده می‌شه و هر صفحه یک `if` / `case` داره | ✅ |
| `[EP-INSTALL]` | `install.php` | نصب اولیه، ساخت `config.php` و `installed.lock` | 🟡 |
| `[EP-CRON-TICKET]` | `cron/ticket_auto_close.php`، `cron/sla_maintenance.php`، `cron/prune_activity_logs.php`، `cron/ldap_sync.php` | کرون‌های ITSM | 🟡 |
| `[EP-CRON-DOMAIN]` | `cron/domain_scan_worker.php` (`--loop`) | Worker اسکن دامنه | 🟡 |
| `[EP-WORKER-FOOD]` | `cron/food_ticket_worker.php` (۱۶۳ خط) | **Worker چاپ فیش** (بدون Session/Panel). از `food-ticket.php` ماژول‌ها رو require می‌کنه | 🟡 |
| `[EP-API-INV]` | `api/inventory.php` | API دارایی | 🟡 |
| `[EP-API-FOOD]` | `food-ticket.php` (`api/*`) | API پنل غذا (config، printers، orders، reports، …) | 🟡 |
| `[EP-PANEL-FOOD]` | `food-ticket-web/index.html` + `food-ticket-web-host.php` | پنل وب غذا (فرانت) که از API بالا استفاده می‌کنه | 🟡 |
| ~~`[EP-CSHARP]`~~ | ~~`food-ticket-web/Program.cs`~~ | **حذف شد** (سرویس .NET قدیمی؛ موتور پایش فقط PHP است) | ✅ حذف |

---

## ۳. بخش ITSM (سامانه تیکتینگ و دارایی)

### 3.1 هسته و احراز هویت
| تگ | فایل | نقش | وضعیت |
|---|---|---|---|
| `[TK-BOOT]` | `bootstrap.php` (۲۲۶۱ خط) | هسته: اتصال DB، هویت نصب، migrationهای ستونی (`ALTER TABLE` در runtime)، کش، لاگ، CSRF، helperها | ✅ |
| `[TK-AUTH]` | داخل `index.php` و `bootstrap.php` | ورود، `login_attempts`، LDAP | 🟡 |
| `[TK-PERM]` | `permissions.php` (۵۵۰ خط، ۳۳ تابع) | نقش‌ها و دسترسی‌ها (`role_permissions`) | 🟡 |
| `[TK-SETTINGS]` | داخل `index.php` (`page=settings`)، جدول `settings` | تنظیمات سامانه | 🟡 |
| `[TK-AUDIT]` | `[TK-LOG]` | `activity_logs`، `system_logs`، صفحات `logs`، `activity-log`، `audit` | 🟡 |

### 3.2 تیکت (Ticketing)
| تگ | فایل/صفحه | جدول‌ها | وضعیت |
|---|---|---|---|
| `[TK-CORE]` | `index.php` (`page=ticket`، `new-ticket`، `queue`، `supervisor`) | `tickets`، `ticket_messages`، `ticket_events`، `ticket_attachments`، `ticket_ratings`، `ticket_number_seq` | 🟡 |
| `[TK-SERVICE]` | `index.php` (`page=services`)، فرم‌های سرویس | `service_catalog`، `service_catalog_fields`، `categories` | 🟡 |
| `[TK-SLA]` | `cron/sla_maintenance.php`، `ticket_sla_alerts` | `ticket_sla_alerts` | 🟡 |
| `[TK-OLA]` | `ola.php` (۱۰۵ خط)، صفحه `ola` | `ola_policies`، `ticket_ola_alerts` | 🟡 |
| `[TK-CHANGE]` | مدل‌های change در schema | `change_records`، `change_approvals`، `change_ticket_links` | ❓ |
| `[TK-PROBLEM]` | مدل‌های problem در schema | `problem_records`، `problem_ticket_links` | ❓ |
| `[TK-KB]` | `index.php` (`page=knowledge`) | `knowledge_articles` | 🟡 |
| `[TK-NOTIF]` | `index.php` (`page=notifications`) | `notifications` | 🟡 |
| `[TK-HOLIDAY]` | `index.php` (`page=holidays`) | `holidays` | 🟡 |
| `[TK-CLOSE]` | `cron/ticket_auto_close.php` | `tickets` | 🟡 |

### 3.3 دارایی (Assets / Inventory)
| تگ | فایل | جدول‌ها | وضعیت |
|---|---|---|---|
| `[AS-CORE]` | `index.php` (`page=assets`، `asset`، `asset-history`) | `assets`، `asset_relations`، `asset_history_events` | 🟡 |
| `[AS-PROFILE]` | `asset-profile.php` (۷۲۳ خط) | `asset_profiles` + ستون‌های پویا روی `asset_profiles` و `assets` (ALTER runtime) | 🟡 |
| `[AS-INV]` | `inventory.php` (۵۵۰ خط)، `asset-inventory-page.php`، `api/inventory.php`، صفحات `inventory` و `inventory-diagnostics` | `asset_inventory_history`، `asset_memory_modules`، `asset_storage_devices`، `asset_graphics_adapters`، `asset_peripherals` | 🟡 |
| `[AS-NAMES]` | `asset-names.php` (۱۳۱ خط) | نام نمایشی کامپیوترها | 🟡 |
| `[AS-DOMAIN]` | `domain-scan.php` (۱۱۰۰ خط)، `cron/domain_scan_worker.php`، صفحه `domain-scan` | `domain_scan_runs`، `domain_scan_queue` | 🟡 |
| `[AS-PRINTER-PROFILE]` | داخل `asset-profile.php` (تشخیص پرینتر مجازی/فیزیکی) | — | 🟡 |

### 3.4 سازمان و کاربران
| تگ | فایل | جدول‌ها | وضعیت |
|---|---|---|---|
| `[ORG-CORE]` | `organization.php` (۳۲۹ خط)، صفحه `organization` | `org_units`، `org_unit_managers`، `departments`، `handling_units` | 🟡 |
| `[ORG-USERS]` | `index.php` (`profile`، `supervisor`) | `users` | 🟡 |
| `[ORG-GOV]` | `governance.php` (۵۱۵ خط)، صفحه `governance` | ۱۲ CSRF؛ جداول governance در bootstrap | 🟡 |

### 3.5 تردد عمومی (Traffic Control) و CD/DVD
| تگ | فایل | جدول‌ها | وضعیت |
|---|---|---|---|
| `[TR-CTRL]` | `traffic-control.php` (۶۶۲ خط)، صفحات `traffic-control` و `traffic-control-print` | `traffic_visits`، `traffic_destinations` | 🟡 |
| `[CD-DVD]` | `cd-dvd.php` (۷۹۶ خط)، صفحات `cd-dvd` و `cd-dvd-history` | `cd_dvd_records`، `cd_dvd_types` | 🟡 |

### 3.6 گزارش، جست‌وجو، پشتیبان
| تگ | فایل | وضعیت |
|---|---|---|
| `[RPT]` | `index.php` (`reports`، `analytics`) | 🟡 |
| `[SEARCH]` | `global-search.php` (۱۲۱ خط) | 🟡 |
| `[BACKUP]` | `backup.php` (۲۳۴ خط)، صفحه `backup` | 🟡 |

---

## ۴. بخش FOOD (غذا، فیش، تردد)

### 4.1 سفارش داخلی (کاربر سفارش می‌ده)
| تگ | فایل | جدول‌ها | وضعیت |
|---|---|---|---|
| `[FD-ORDER]` | `food-order.php` (۱۲۹۴ خط، ۴۰ تابع) | `food_calendar`، `food_calendar_items`، `food_catalog`، `food_orders`، `food_order_logs` | 🟡 |
| `[FD-ORDER-PANEL]` | `food-order` صفحه/`assets/food-order*.js/css` | — | 🟡 |
| `[FD-PROXY]` | داخل `food-order.php` (نیابت، لغو) | `food_orders` | 🟡 |
| `[FD-SRC]` | `food-ticket-order-source.php` (۸۶ خط) | نقشه سفارش روز: `food_order_internal_map()` → از `food_orders` (MySQL داخلی، بدون Access) | ✅ |
| `[FD-ORDER-EXT]` | — | **وابستگی Access سفارش (food_fish / orders_path / orders_table) حذف شد.** | ✅ حذف |

### 4.2 تردد و تطبیق (محرک چاپ)
| تگ | فایل | نقش | وضعیت |
|---|---|---|---|
| `[FD-ENGINE]` | `food-ticket-engine.php` (۹۳۲ خط، ۱۷ تابع) | **موتور پایش**: خواندن ردیف‌های امروز از Access تردد → مرتب‌سازی → تطبیق با سفارش → ثبت رویداد → چاپ فوری → حذف ردیف از Access | ✅ |
| `[FD-GROUP]` | `food-ticket-groups.php` (۱۸۰۶ خط، ۳۹ تابع) | گروه‌های غذایی با L_UID و نماینده؛ جدول‌های `food_ticket_groups`، `food_ticket_group_members`، `food_ticket_group_runs`، `food_ticket_group_uid_history`، `food_ticket_daily_absence` | 🟡 |
| `[FD-CARD]` | جدول `food_ticket_card_map`، `food_ticket_guest_cards` | نگاشت کارت به کاربر، کارت مهمان | ❓ |
| `[FD-IMPORT]` | `food-ticket-import.php` (۴۶۱ خط) | ایمپورت لیست کارکنان از Excel/xlsx | 🟡 |

### 4.3 صف چاپ و قوانین وعده
| تگ | فایل | جدول‌ها | وضعیت |
|---|---|---|---|
| `[FD-RUNTIME]` | `food-ticket-runtime.php` (۱۰۴۹ خط، ۳۳ تابع) | `food_ticket_events` (صف چاپ با `print_status`)، قوانین وعده، `food_ticket_print_log` | 🟡 |
| `[FD-TEMPLATE]` | `food-ticket-templates.php` (۲۰۴۹ خط، ۵۷ تابع) | `food_ticket_templates`؛ Template → Renderer → Windows Queue | 🟡 |
| `[FD-PRINT-NET]` | `food-ticket-netprint.php` (۱۲۹۶ خط) | چاپ TCP 9100 روی پرینتر شبکه، کالیبراسیون، trim/align | 🟡 |
| `[FD-PRINT-WIN]` | داخل `food-ticket-runtime.php` / `food-ticket-templates.php` | چاپ ویندوز (PowerShell) | 🟡 |
| `[FD-CONFIG]` | `food-ticket.php` (۴۴۷۵ خط، ۱۱۴ تابع)، جدول `food_ticket_config` | تنظیمات مرکزی، API، صفحه ادمین، تست، ذخیره | 🟡 |
| `[FD-ENV]` | `food-ticket-env.php` (۲۳۴ خط) | مقادیر سازگاری و **Base64** (شامل رمز پیش‌فرض کارخانه Access؛ ⚠️ بخش امنیت را ببین) | ✅ |
| `[FD-WORKER-STATUS]` | جدول `food_ticket_worker_status` | وضعیت Worker | 🟡 |

### 4.4 منبع داده تردد (وابستگی Access)
| تگ | شرح | وضعیت |
|---|---|---|
| `[SRC-TENTER]` | **تنها منبع خارجی باقی‌مانده.** فایل Access (`attendance_path`)، جدول `C_Date`/`C_Time`/`UID` (نام جدول `TENTER`). هر ردیف پس از پردازش حذف می‌شه | ✅ |
| `[SRC-ACCESS-READ]` | `food-ticket.php`: `food_ticket_odbc()` و خواندن با PowerShell/OLEDB/ODBC | 🟡 |
| `[SRC-ACCESS-TOOLS]` | `tools/diag_*.php`، `tools/set_attendance_password.php`، `tools/install_food_ticket_worker.ps1` | ❓ |

### 4.5 سرویس‌ها و پنل جانبی
| تگ | فایل | نقش | وضعیت |
|---|---|---|---|
| `[FD-PANEL]` | `food-ticket-web/index.html` + `assets/*.js` | پنل مدیریت/گزارش غذا (API: config, printers, orders, reports, reprint-errors, monitoring, …) | 🟡 |
| ~~`[FD-CSHARP]`~~ | — | **حذف شد** | ✅ حذف |

---

## ۵. دیتابیس (جدول‌ها و مالکشون)

| دامنه | جدول‌ها |
|---|---|
| هویت/سازمان | `users`، `departments`، `org_units`، `org_unit_managers`، `handling_units`، `role_permissions`، `login_attempts`، `settings` |
| تیکت | `tickets`، `ticket_messages`، `ticket_events`، `ticket_attachments`، `ticket_ratings`، `ticket_number_seq`، `ticket_sla_alerts`، `ticket_ola_alerts`، `categories`، `service_catalog`، `service_catalog_fields`، `knowledge_articles`، `notifications`، `holidays` |
| تغییر/مشکل | `change_records`، `change_approvals`، `change_ticket_links`، `problem_records`، `problem_ticket_links`، `ola_policies` |
| دارایی | `assets`، `asset_profiles`، `asset_relations`، `asset_history_events`، `asset_inventory_history`، `asset_memory_modules`، `asset_storage_devices`، `asset_graphics_adapters`، `asset_peripherals`، `domain_scan_runs`، `domain_scan_queue` |
| تردد/CD | `traffic_visits`، `traffic_destinations`، `cd_dvd_records`، `cd_dvd_types` |
| لاگ | `activity_logs`، `system_logs`، `api_rate_limits` |
| غذا | `food_ticket_config`، `food_ticket_events` (صف چاپ)، `food_ticket_print_log`، `food_ticket_templates`، `food_ticket_card_map`، `food_ticket_guest_cards`، `food_ticket_groups`، `food_ticket_group_members`، `food_ticket_group_runs`، `food_ticket_group_uid_history`، `food_ticket_daily_absence`، `food_ticket_worker_status`، `food_catalog`، `food_calendar`، `food_calendar_items`، `food_orders`، `food_order_logs` |

> ⚠️ جدول‌های `ALTER` در runtime (داخل `bootstrap.php`، `asset-profile.php`، `food-ticket*.php`) یعنی schema واقعی فقط با اجرای کد ساخته می‌شه؛ `schema.sql` و `upgrade-*.sql` کامل نیستن.

---

## ۶. کدهای مرده یا بی‌استفاده (مهم)

| تگ | مورد | وضعیت |
|---|---|---|
| `[DEAD-MVC]` | پوشه `app/` (Controllers، Models، Services، Core، Views) و `routes/web.php` (`App\Core\Router`) | ✅ **هیچ فایلی این‌ها رو require نمی‌کنه.** `index.php` تک‌فایلی و بدون روتر MVC کار می‌کنه. احتمالاً نسخهٔ قدیمی یا نیمه‌کاره‌ست. |
| `[DEAD-TEST-COPY]` | `tests/test_group_flow_offline.php` و `tools/*` که توابع `food_ticket_*` رو دوباره تعریف می‌کنن | 🟡 فقط برای تست است، نه در اجرای عادی |

---

## ۷. وابستگی‌های بین بخش‌ها

جریان نهایی (بعد از تغییر): `[SRC-TENTER]` → `[FD-ENGINE]` → `[FD-SRC]` (food_orders) → `[FD-RUNTIME]` → `[FD-PRINT-*]`

- `[FD-*]` → `[TK-BOOT]` (اتصال DB، helperها از `bootstrap.php`)
- `[FD-ENGINE]` → `[SRC-TENTER]` (خواندن و حذف ردیف) و `[FD-SRC]` (تطبیق)
- `[FD-ENGINE]` → `[FD-RUNTIME]` (ثبت رویداد و صف)
- `[FD-RUNTIME]` → `[FD-TEMPLATE]` → `[FD-PRINT-NET]` / `[FD-PRINT-WIN]`
- `[FD-PANEL]` → API `[FD-CONFIG]`
- `[AS-PROFILE]` و `[AS-INV]` → `[AS-NAMES]` و `[AS-DOMAIN]`
- ❓ `[ITSM]` و `[FOOD]` فقط دیتابیس مشترک دارن؛ کد مشترک جدی بینشون نیست (غیر از `bootstrap.php`).

---

## ۸. کرون‌ها و سرویس‌ها (وقتی سامانه روی ویندوز/XAMPP اجرا میشه)

| تگ | اجرا | دوره |
|---|---|---|
| `[CRON-FOOD]` | `cron/food_ticket_worker.php` (Worker همیشه‌روشن) | پیوسته |
| `[CRON-DOMAIN]` | `cron/domain_scan_worker.php --loop` | پیوسته |
| `[CRON-TICKET]` | `ticket_auto_close.php`، `sla_maintenance.php`، `ldap_sync.php`، `prune_activity_logs.php` | زمان‌بندی‌شده |

---

## ۹. مشکلات و ریسک‌های شناخته‌شده

| تگ | شرح | شدت |
|---|---|---|
| `[RISK-REPO]` | مخزن **پابلیک** است و داخلش کد داخلی، SQL، CSV نمونه و ساختار سازمانی هست | بالا |
| `[RISK-PWD]` | رمز پیش‌فرض کارخانه Access در `food-ticket-env.php` (Base64) | بالا |
| `[RISK-HIST]` | تاریخچه گیت فقط یک commit دارد؛ نمی‌شه بررسی کرد آیا رمز قبلاً در تاریخچه بوده | متوسط |
| `[RISK-DEAD]` | `app/` و `routes/` بی‌استفاده‌اند و باعث سردرگمی می‌شن | پایین |
| `[RISK-MIGRATE]` | migrationها در runtime (`ALTER` داخل کد) اجرا میشن؛ schema کامل جایی ثبت نشده | متوسط |
| `[RISK-ACCESS]` | محرک چاپ فیش به فایل TENTER وابسته است (`[SRC-TENTER]`). این وابستگی عمداً باقی مانده | مورد انتظار |
| `[RISK-TESTS]` | تست‌های `SecurityTest.php`، `ArchitectureTest.php` و ۴ مورد `test_food_ticket_logic.php` از قبل روی مخزن fail هستن (قبل از این تغییر هم همین بود) | متوسط |

---

## ۱۰. فهرست سریع «کجا باید دنبال X بگردم؟»

| اگر گفتی… | برو به |
|---|---|
| تیکت، ارجاع، پاسخ، SLA | `[TK-CORE]`، `[TK-SLA]`، `index.php` |
| دارایی، شناسنامه، سخت‌افزار | `[AS-CORE]`، `[AS-PROFILE]`، `[AS-INV]` |
| دامنه، اسکن کامپیوتر | `[AS-DOMAIN]` |
| کاربر، سازمان، نقش | `[ORG-USERS]`، `[ORG-CORE]`، `[TK-PERM]` |
| سفارش غذا، نیابت، لغو | `[FD-ORDER]`، `[FD-PROXY]` |
| فیش نشد، صف چاپ، چاپ مجدد | `[FD-RUNTIME]`، `[FD-PRINT-NET]`، `[FD-PRINT-WIN]` |
| قالب فیش، لوگو، طراحی | `[FD-TEMPLATE]` |
| تردد، کارت، TENTER، Access | `[SRC-TENTER]`، `[SRC-ACCESS-READ]`، `[FD-ENGINE]` |
| گروه غذایی، نماینده، غیبت | `[FD-GROUP]` |
| ایمپورت Excel کارکنان | `[FD-IMPORT]` |
| Worker چاپ فیش | `[EP-WORKER-FOOD]`، `[CRON-FOOD]` |
| پنل غذا (فرانت) | `[FD-PANEL]` |
| گزارش، آمار | `[RPT]` |
| لاگ، فعالیت | `[TK-AUDIT]` |
| تنظیمات | `[TK-SETTINGS]`، `[FD-CONFIG]` |
| نصب | `[EP-INSTALL]` |
