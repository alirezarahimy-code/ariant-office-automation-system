# پرونده مرجع راهبردی سامانه تیکتینگ — دانش کف دستی

> **هدف:** هر چیزی که لازم است از سامانه تیکتینگ بدانیم، یک‌جا و بدون جست‌وجوی مجدد. منبع منجمد: `reference/ticketing-v1.8/` (کپی کامل کد، فقط‌خواندنی). مکمل‌ها: `docs/11` (دارایی‌ها)، `docs/12` (بررسی عمیق)، `docs/13` (نقص‌ها و اصلاحات).

## ۰. شناسنامه مرجع
- مخزن مبدأ: `github.com/alirezarahimy-code/ticket` — کامیت `0aab446ab14df8ec4048d55e285987ca347aeff3` (۱۴۰۵/۰۷/۲۰)
- محل در ریپوی پلتفرم: `reference/ticketing-v1.8/` — ۱۰ مگابایت، ۱۱۵ فایل PHP، ~۴۰٬۳۲۵ خط کد
- نسخه تحویل: `1.8-final`؛ منطقه زمانی Asia/Tehran؛ DB: MySQL `persian_ticketing`؛ استقرار فعلی: XAMPP
- **قاعده:** این پوشه فقط مرجع است؛ هرگز ویرایش نمی‌شود. تغییرات سامانه زنده در ریپوی خودش.

## ۱. نقشه معماری (جواب «چه چیزی کجاست؟»)
| فایل/مسیر | خط | نقش |
|---|---|---|
| `index.php` | ۴٬۷۳۳ | مسیریاب اصلی ۳۱ صفحه + UI پنل |
| `bootstrap.php` | ۲٬۳۱۱ | هسته: نشست سخت‌شده، CSRF (کوکی itsm_csrf)، قفل حساب، db()، LDAP bind |
| `permissions.php` | ۸۳۷ | موتور RBAC کامل (کاتالوگ/پیش‌فرض/اورراید/کش/اعمال) |
| `organization.php` | ۴۱۴ | درخت سازمان، معاونت‌ها، مدیران واحد، روتینگ |
| `food-ticket.php` + `engine` + `runtime` + `templates` + `groups` + `netprint` | ~۹٬۷۰۰ | موتور چاپ فیش (سنگین‌ترین) |
| `food-order.php` + import ها | ~۲٬۶۰۰ | سفارش غذا، تقویم، ایمپورت |
| `cd-dvd.php` | ۷۹۶ | کنترل رسانه CD/DVD (نقش بازرسی) |
| `traffic-control.php` | ۷۶۸ | تردد مراجعین/میهمان (پورت از کنترل_تردد.xlsm) |
| `governance.php` | ۵۲۰ | تغییر/مسئله ITIL با CAB و Known Error |
| `domain-scan.php` + cron worker | ۱٬۱۰۰+ | کشف کامپیوترهای دامنه → دارایی |
| `inventory.php` + `api/inventory.php` + `client-agent/Collect-Inventory.ps1` | ~۱٬۱۰۰ | موجودی سخت‌افزار با توکن و agent پاورشل |
| `asset-profile.php` | ۷۲۳ | شناسنامه دارایی |
| `install.php` | ۳۷۲ | نصب‌کننده مرحله‌ای |
| `app/` (MVC) | ۱٬۵۴۵ | لایه بازنویسی تمیز (Controllers/Models/Services/Views) — جزئی |
| `cron/` | ۶ کارگر | ldap_sync، sla_maintenance، ticket_auto_close، food_ticket_worker، domain_scan_worker، prune_activity_logs |
| `tests/` + `tools/` | — | ArchitectureTest، SecurityTest، smoke، تست منطق غذا؛ diag_* و backup.ps1 |
| `food-ticket-web/` | — | سرویس ایستای جدا (SQLite) + قرارداد SSO + طراح قالب JS |
| `.github/workflows/ci.yml` | — | CI: PHP 8.2، syntax، تست امن/معماری/غذا |
| ۵۲ فایل `00_*` | ۲٬۷۳۵ خط | changelog فارسی نسخه‌به‌نسخه (تاریخچه واقعی برنامه) |

## ۲. صفحات روتر (۳۱ صفحه index.php)
activity-log، analytics، asset، asset-history، assets، audit، backup، cd-dvd، cd-dvd-history، domain-scan، food-order، food-ticket، governance، help، holidays، inventory، inventory-diagnostics، knowledge، logs، new-ticket، notifications، organization، profile، queue، reports، search، services، settings، supervisor، ticket، traffic-control (+print)

## ۳. پایگاه داده — ۵۵ جدول گروه‌بندی‌شده
- **هسته تیکت:** tickets، ticket_messages، ticket_attachments، ticket_ratings، ticket_events، ticket_sla_alerts، ticket_ola_alerts، ticket_number_seq، categories، service_catalog، service_catalog_fields
- **سازمان و کاربران:** users، departments، org_units، org_unit_managers، handling_units، settings، role_permissions
- **امنیت/لاگ:** activity_logs، system_logs، login_attempts، api_rate_limits، notifications
- **حاکمیت ITIL:** change_records، change_approvals، change_ticket_links، problem_records، knowledge_articles
- **اموال:** assets + asset_memory_modules، asset_storage_devices، asset_graphics_adapters، asset_peripherals، asset_inventory_history، asset_history_events، asset_relations، asset_profiles
- **تردد:** traffic_destinations، traffic_visits
- **رسانه:** cd_dvd_types، cd_dvd_records
- **اسکن دامین:** domain_scan_runs، domain_scan_queue
- **غذا:** food_ticket_card_map، food_ticket_templates، food_catalog، food_calendar، food_calendar_items، food_orders، food_order_logs
- **تقویم:** holidays

## ۴. گردش تیکت (ماشین حالت)
`new → manager_review (پیش‌فرض) → assigned → in_progress → waiting_user | pending → resolved → closed`
- انواع: incident/request/problem/change + parent_ticket_id + custom_fields JSON
- حوزه‌ها: `service_group` = it (اتصال به asset_id) یا support (support_location/equipment)
- SLA: sla_minutes، due_at، **توقف** (sla_paused_at/sla_pause_minutes)؛ OLA: ola_response_due_at، ola_due_at، **دو پله تشدید** (escalation_1/2)؛ تأیید سرپرست (supervisor_id/approved_at/note)
- کرون: بستن خودکار حل‌شده بدون امتیاز پس از `ticket_auto_close_days` روز (پیش‌فرض ۳؛ ۰=خاموش)

## ۵. RBAC — نقش‌ها و ۹۲ کد مجوز (واژگان کامل)
**نقش‌ها:** user کاربر | agent کارشناس | manager مدیر | supervisor سوپروایز | admin | primary_admin ادمین اصلی | support_manager مدیر پشتیبانی | inspector بازرسی
**مکانیزم:** role_permissions(role, permission) + پیش‌فرض نقش + اورراید سفارشی (نشان custom) + کش با flush + `require_permission`/`user_can`
**سطوح داده در خود کدها:** `*.view_own` / `*.view_unit` / `*.view_all`
**کاتالوگ کامل ۹۲ کد:**
- پایه: dash.view، search.global، notif.view، profile.edit، queue.view
- تیکت: ticket.create، ticket.view_own، ticket.view_unit، ticket.view_all، ticket.reply، ticket.edit، ticket.assign، ticket.bulk، ticket.rate، ticket.attach_asset
- سرپرستی: supervisor.panel، supervisor.decide، supervisor.reopen
- خدمات: services.view، services.manage، service_cat.manage، service_field.manage
- گزارش/تحلیل/حاکمیت: reports.view، analytics.view، governance.view، governance.manage، audit.view، logs.view، logs.activity، logs.activity_export
- سازمان: org.view، org.manage، holidays.view، holidays.manage
- تنظیمات: settings.general(_edit)، settings.domain(_edit)، settings.users(_edit)، settings.key_roles(_edit)
- اموال/موجودی: asset.view، asset.edit، asset.history، asset.ping، asset.extract، asset.remote_extract، assets.view، assets.own_unit، inventory.view، inventory.diagnostics، domain.scan، domain.import
- رسانه: cddvd.view، cddvd.view_all، cddvd.edit، cddvd.submit_in، cddvd.submit_out، cddvd.export، cddvd.history
- تردد: traffic.view، traffic.manage، traffic.destinations
- غذا: food.dashboard، food.monitor(_edit)، food.db(_edit)، food.design(_edit)، food.employees(_edit)، food.groups(_edit، _override)، food.guest(_edit)، food.menu(_edit)، food.health(_edit)، food.printer(_edit)، food.reports، food.order_close، foodorder.self، foodorder.proxy
- دانش/پشتیبان: knowledge.view، knowledge.manage، backup.view، backup.manage
- OLA: ola.view، ola.manage

## ۶. درخت سازمان (organization.php)
org_units والد-فرزندی با code؛ توابع کلیدی: `org_unit_children`، `org_unit_subtree_ids`، `org_unit_path_names`، `org_deputy_units` (معاونت‌ها)، `org_ceo_unit_id`، `org_unit_manager_ids`، `user_is_unit_manager`، `user_managed_unit_ids`، `org_unit_routing_department_id`؛ حوزه‌بندی تیکت = حوزه رسیدگی + معاونت

## ۷. احراز هویت
- محلی: password_verify + قفل حساب (attempt_key، پنجره، locked_until)
- LDAP/AD (v3): ورود دامینی؛ معرفی یکجای کاربران (پنل/Job)؛ ساخت خودکار حساب در اولین ورود؛ نگاشت گروه AD→نقش؛ `cron/ldap_sync.php` برای غیرفعال‌سازی حذف‌شده‌ها
- نشست: کوکی سخت‌شده + CSRF توکن در جلسه و کوکی itsm_csrf
- **قرارداد SSO غذا (الگوی پل ما):** توکن HMAC-SHA256 با app.key شامل `role، service_group، user_id، exp`؛ نشست ۸ ساعته در سرویس مقصد؛ بدون اعتبارنامه در URL/LocalStorage؛ نقش‌های مجاز: admin/support

## ۸. موتور چاپ فیش غذا (spec رفتاری برای بازنویسی)
زنجیره: `SOURCE_TABLE` (Access/TENTER دستگاه ویردی) → قوانین → `food_ticket_events` → صف چاپ → چاپگر
- کارگر پس‌زمینه بدون نشست (`cron/food_ticket_worker.php`)؛ منبع: دستگاه حضور و غیاب + پرینتر + شبکه
- توأم‌زدایی: یک کشیدن کارت = گاهی دو ردیف (L_UID + C_Card) → `find_twin_event` + مرتب‌سازی (L_UID مقدم در ثانیه مشترک)
- `food_ticket_card_map(card_key→user_id, personnel_code, hits)` خودیادگیرنده با `card_map_learn`
- وعده‌ها: `meal_windows`، `active_meal`؛ سقف میهمان: متغیر با mode/clock/start/end/cutoff از شمارش‌ها
- گروه‌های غذایی: نماینده با L_UID + غیبت روزانه اعضا
- چاپ: پرینتر حرارتی فارسی نمی‌فهمد → **رندر raster با GD + ESC/POS روی TCP 9100**؛ بازگرداندن کاغذ ESC e (خطی) / ESC K (نقطه‌ای — اصلاح ۱.۳۷.۳)؛ قالب: طراح JS → رندرر → صف ویندوز یا شبکه
- حذف ردیف منبع پس از چاپ با شمارش و ثبت نتیجه روی فیش (del_fail در خلاصه)

## ۹. ماژول‌های دیگر (رفتار کلیدی)
- **CD/DVD:** انواع رسانه با بازه شماره (`media_bounds`)؛ `serial_matches_media`؛ ورود فقط نقش بازرسی؛ خروج نقش‌بررسی‌شده؛ تاریخچه
- **تردد:** ورود/خروج مراجعین و میهمانان، جست‌وجو با کد ملی، چاپ برگ ملاقات؛ پیشوند تابع traffic_؛ پورت موفق از Excel
- **حاکمیت:** تغییر با تأیید CAB، مسئله با Known Error + راه‌حل موقت، برچسب ریسک، شماره‌گذاری gov_change_number/gov_problem_number
- **اسکن دامین:** صف DB → کارگر → رکورد دارایی (رفع strict MySQL برای source='domain')
- **اموال:** ۱۰ جدول جزئیات/تاریخچه/روابط/پروفایل؛ API موجودی با INVENTORY_TOKEN + agent پاورشل

## ۱۰. تاریخچه نسخه‌ها
- git ریپو: ۷۳ کامیت از ۱۴۰۵/۰۷/۱۷ (Fresh start) — تاریخچه قدیمی در git نیست
- تاریخچه واقعی: ۵۲ فایل `00_CHANGELOG_*` (۲٬۷۳۵ خط) + ارتقاهای SQL شماره‌دار `upgrade-1.1 … upgrade-1.8-routing` + شماره‌های ماژولی ۱.۳۷.x/۱.۳۸ (فیش/سفارش غذا)

## ۱۱. نقشه استفاده در پلتفرم (فاز به فاز)
| از تیکتینگ | در پلتفرم | فاز |
|---|---|---|
| ۹۲ کد مجوز + سه سطح داده | IAM جنگو (permissions + QuerySet scoping) | ۱ — انجام شد (واژگان تطبیق) |
| ticket_number_seq | شماره‌گذاری همزمان‌امن دبیرخانه | ۱ |
| manager_review + زنجیره رویداد | ارجاع اولیه + تاریخچه نامه | ۱ |
| SLA توقف‌پذیر + تشدید + auto-close | موتور مهلت پاسخ و هشدار | ۱ |
| org_unit_subtree_ids + معاونت | سطح «واحد و زیرمجموعه» | ۱–۲ |
| LDAP: نگاشت گروه→نقش + اولین ورود | کانکتور احراز هویت دامنه | ۲ |
| قرارداد HMAC غذا | پل «تیکت‌های من» | ۱ (نمایش) — مهاجرت کامل بعد |
| spec موتور غذا (بند ۸) | بازنویسی بومی زیرسامانه غذا/تردد | ۳ |
| cd-dvd + traffic | بایگانی رسانه + زیرسامانه تردد | ۲–۳ |

## ۱۲. راهنمای جست‌وجوی سریع در `reference/ticketing-v1.8/`
| سؤال | فایل |
|---|---|
| مجوز X را چه نقش‌هایی دارند؟ | `permissions.php` (permission_defaults) |
| اسکیما/ایندکس جدول؟ | `schema.sql` |
| گردش وضعیت تیکت؟ | `app/Services/TicketService.php` + `schema.sql` (ENUM status) |
| قفل حساب/CSRF/نشست؟ | `bootstrap.php` |
| منطق چاپ فیش؟ | `food-ticket-engine.php`، `food-ticket-runtime.php` |
| پروتکل پرینتر؟ | `food-ticket-netprint.php` |
| قرارداد SSO؟ | `food-ticket-web/integration-contract-fa.md` |
| توابع درخت سازمان؟ | `organization.php` |
| LDAP و همگام‌سازی؟ | `bootstrap.php` + `cron/ldap_sync.php` |
| رفتار یک نسخه خاص؟ | فایل‌های `00_CHANGELOG_*` |

**پایان پرونده مرجع — نسخه ۱٫۰ — ۱۴۰۵/۰۷/۲۰**
