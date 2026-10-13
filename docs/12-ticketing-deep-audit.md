# بررسی عمیق سامانه تیکتینگ — ماژول‌به‌ماژول (نسخه ۲)

> نسخه ۱ ساختاری بود (اسکیما + نقشه). این سند حاصل خواندن هدفمند کد است: ۴۰٬۳۲۵ خط PHP در اسکریپت‌های ریشه، لایه MVC، کرون‌ها، سرویس غذا، تست‌ها و ابزارها. روش: مطالعه bootstrap، permissions، organization، TicketService، موتور/زمان‌اجرا/قالب/نت‌پرینت غذا، cd-dvd، traffic-control، governance، کرون‌ها و تست‌ها. خواندن خط‌به‌خط ۱۰۰٪ نبود، اما همه ماژول‌های نام‌برده کارفرما تا سطح قواعد کسب‌وکار واکاوی شد.

---

## ۰. معماری واقعی (دو لایه تاریخی)

- **لایه اصلی:** اسکریپت‌های ریشه — `index.php` (۴٬۷۳۳ خط؛ مسیریاب ۳۰ صفحه)، `bootstrap.php` (۲٬۳۱۱ خط: نشست سخت‌شده، CSRF، محدودساز ورود، DB) و ماژول‌های مستقل (food-*، cd-dvd، traffic-control، governance، domain-scan، inventory، asset-profile، organization، permissions، install، help-guide)
- **لایه MVC جدید:** `app/` (۱٬۵۴۵ خط؛ Controllers/Models/Services + Views برای dashboard/ticket) — بازنویسی تمیز ولی جزئی
- کرون‌ها: ۶ کارگر (ldap_sync، sla_maintenance، ticket_auto_close، food_ticket_worker، domain_scan_worker، prune_activity_logs)
- تست‌ها: `ArchitectureTest`، `SecurityTest`، smoke، تست منطق غذا (PHP)، تست تقویم (Node)، تست ایستا (Python)، چک‌لیست موتور؛ ابزارها: diag_* فراوان، backup.ps1، collect_server_inventory.ps1
- فرهنگ مستندسازی: ~۴۰ فایل `00_CHANGELOG_*` فارسی با شماره نسخه (مثل ۱.۳۷.۳) — انضباط تغییرات واقعی

---

## ۱. خودِ تیکت و روند انجام کار

**اسکیمای tickets (ستون‌های کلیدی):**
- نوع ITIL: `incident | request | problem | change` + `parent_ticket_id` (زنجیره) + `custom_fields JSON`
- دو حوزه خدمت: `service_group (it|support)`؛ تیکت IT به دارایی شناسنامه‌دار وصل می‌شود (`asset_id`)، تیکت پشتیبانی محل و تجهیز می‌گیرد (`support_location/equipment`)
- ارجاع: `handling_unit_id`، `assigned_to/assigned_at`، `supervisor_id/approved_at/note` (تأیید سرپرست)

**ماشین وضعیت (۸ حالت):**
`new → manager_review (پیش‌فرض! بررسی مدیر واحد) → assigned → in_progress → waiting_user / pending → resolved → closed`

**SLA/OLA — پیچیده‌تر از انتظار:**
- `sla_minutes` + `due_at` با **توقف/ادامه** (`sla_paused_at`، `sla_pause_minutes`)
- سیاست OLA: مهلت پاسخ، مهلت نهایی و **دو پله تشدید** (`ola_escalation_1_at/2_at`)
- کرون `sla_maintenance` و `ticket_auto_close`: بستن خودکار حل‌شده‌ها پس از N روز بدون امتیاز (تنظیم `ticket_auto_close_days`، پیش‌فرض ۳، صفر=خاموش)

**رویدادها:** `ticket_events` + پیام‌ها + پیوست‌ها + امتیازدهی = ردگیری کامل مسیر
**گردش در لایه سرویس:** createTicket، assignTicket، updateStatus، addMessage، getOverdueTickets، searchTickets، getTicketStats

> **استفاده پلتفرم:** الگوی `manager_review` به‌عنوان پیش‌فرض = همان «ارجاع اولیه دبیرخانه»؛ توقف/تشویع/بستن خودکار SLA = موتور مهلت پاسخ نامه‌ها؛ شماره‌گذاری `ticket_number_seq`.

---

## ۲. سامانه مجوزها و دسترسی‌ها (RBAC کامل)

- نقش‌ها با برچسب فارسی: `primary_admin ادمین اصلی، supervisor سوپروایز، support_manager مدیر پشتیبانی، inspector بازرسی، manager مدیر، agent کارشناس، user کاربر` (+admin در ENUM)
- **کاتالوگ مجوزها گروه‌بندی‌شده بر اساس حوزه** (`permission_catalog`): کدهایی مانند
  `dash.view، search.global، notif.view، profile.edit، ticket.create، ticket.view_own، ticket.view_unit، ticket.view_all، ticket.reply، ticket.assign، ticket.bulk، ticket.rate، queue.view، supervisor.panel، supervisor.decide، supervisor.reopen، services.*، service_field.manage…`
  → **سه سطح داده own/unit/all مستقیماً در کد مجوزهاست** (مطابق ضمیمه ج پلتفرم)
- مکانیزم: پیش‌فرض‌های هر نقش (`permission_defaults`) + **اورراید سفارشی با نشان‌گذاری custom** + مهاجرت یک‌باره + seed/save/reset + باطل‌سازی حافظه پنهان
- اعمال: `user_can، user_can_any، require_permission، require_any_permission` + هلپرهای نقش (`is_staff_role، is_admin_role، is_global_ticket_role، is_department_scoped_role، is_assignable_role`) + دسترسی نقش‌محور چاپ فیش (`print_fish_allowed_roles`)
- امنیت: کوکی نشست با پارامترهای سخت‌شده، CSRF توکن (جلسه + کوکی `itsm_csrf`)، **قفل حساب** با پنجره و `locked_until` (attempt_key)، `api_rate_limits`

> **استفاده پلتفرم:** واژگان کد مجوزها مستقیماً به IAM جنگو نگاشت می‌شود (permission codename + group)؛ سه سطح داده به QuerySet manager تبدیل می‌شود.

---

## ۳. درخت سازمان

- `org_units` والد-فرزندی با کد؛ توابع: `org_unit_children، org_unit_subtree_ids، org_unit_path_names` (مسیر کامل نام‌ها)
- مفاهیم خاص: **واحدهای معاونت** (`org_deputy_units`)، واحد مدیرعامل (`org_ceo_unit_id`)، مدیران واحد (`org_unit_managers` + `user_is_unit_manager، user_managed_units`)، مسیر واحد→دپارتمان برای روتینگ تیکت (`org_unit_routing_department_id`)
- حوزه‌بندی داده: محدودسازی تیکت مدیر/کارشناس بر اساس **حوزه رسیدگی + معاونت سازمانی**

> **استفاده پلتفرم:** هسته OrgUnit فاز ۰ دقیقاً همین است؛ افزودن: مدیر واحد، معاونت، و helper زیردرخت برای سطح «واحد و زیرمجموعه».

---

## ۴. کاربران دامنه و محلی

- ورود محلی (مدیر) + **LDAP/Active Directory** برای کاربران شبکه
- معرفی یکجای همه کاربران دامینی از پنل **یا** Job زمان‌بندی‌شده (بدون نیاز به اولین ورود)
- ساخت خودکار حساب در اولین ورود؛ **نگاشت گروه‌های AD به نقش‌ها** (user/agent/manager/supervisor/admin)
- همگام‌سازی دوره‌ای AD (`cron/ldap_sync.php`) برای غیرفعال‌کردن حذف‌شده‌ها
- تنظیمات کامل LDAP در env (host/port/SSL/base_dn/bind/domain_suffix)

> **استفاده پلتفرم:** تصمیم «وصل کن» LDAP در سند جامع، الگوی اثبات‌شده دارد؛ `IntegrationUserMap` با کلید SamAccountName.

---

## ۵. چاپ فیش غذا — سنگین‌ترین ماژول (~۱۱٬۰۰۰ خط + سرویس جدا)

**زنجیره داده:** `SOURCE_TABLE` (پایگاه **Access** دستگاه حضوروغیاب TENTER) → قوانین → `food_ticket_events` → صف چاپ → چاپگر

- **کارگر پس‌زمینه** (`cron/food_ticket_worker.php`): بدون مرورگر/نشست؛ پیش‌نیاز: سرور + دستگاه + پرینتر + شبکه
- **رفع دوباره‌کاری کارت:** یک کشیدن کارت گاهی دو ردیف می‌سازد (یکی با L_UID و یکی فقط C_Card) → تشخیص «توأم» (`find_twin_event`) و قاعده مرتب‌سازی (L_UID مقدم در ثانیه مشترک)
- **نگاشت کارت خودیادگیرنده:** `food_ticket_card_map (card_key → user_id, personnel_code, hits)` با `card_map_learn`
- قوانین وعده: پنجره‌های غذا، وعده فعال، **سقف میهمان متغیر** (حالت/ساعت/شروع/پایان/cutoff + محاسبه سقف از شمارش‌ها)
- **گروه‌های غذای گروهی:** نماینده گروه با L_UID + غیبت روزانه اعضا (`food-ticket-groups.php`)
- **چاپ فارسی روی پرینتر حرارتی — مهندسی سخت‌افزاری:** چاپگر ESC/POS زبان UTF-8/فارسی نمی‌فهمد → فیش با **GD به تصویر raster** رندر می‌شود (فونت ویندوز)؛ فرمان بازگرداندن کاغذ `ESC e`/`ESC K` با **اصلاح نقطه‌ای** (باگ ۱.۳۷.۳: ESC K بر حسب نقطه است نه خط)
- **قالب‌ها:** طراح JS (`food-designer.js`) → رندرر → صف ویندوز یا پرینتر شبکه **TCP 9100**
- سفارش غذا: تقویم غذایی، کاتالوگ، سفارش + لاگ، ایمپورت از فایل؛ ماژول گروه‌بندی‌شده با جداول food_*
- **سرویس `food-ticket-web`:** ایستای مستقل با SQLite + قرارداد **SSO امضاشده HMAC** (توکن role/service_group/user_id/exp؛ نشست ۸ ساعته؛ بدون اعتبارنامه در URL/LocalStorage)

> **استفاده پلتفرم:** زیرسامانه بومی سطر ۵؛ قواعد موتور (توأم‌زدایی، سقف میهمان، card_map) به‌عنوان **spec رفتاری** برای بازنویسی جنگو؛ رندر raster/ESC-POS را می‌توان با کتابخانه پایتون معادل کرد؛ الگوی worker → management command / celery beat.

---

## ۶. کنترل CD/DVD

- انواع رسانه با **بازه شماره‌گذاری** (`cd_dvd_media_bounds`)؛ شماره داخلی + اعتبارسنجی سریال منطبق بر نوع رسانه (`serial_matches_media`)
- **ثبت ورود رسانه فقط نقش بازرسی** (خطای فارسی صریح)؛ خروج با بررسی نقش؛ رسانه ورودی از بیرون سازمان شماره داخلی ندارد (دستی مقدم است)
- مجوزهای دیدن همه/ویرایش/خروجی جدا؛ تاریخچه (`cd-dvd-history`)

> **استفاده پلتفرم:** مفهوم «رسانه با شماره‌گذاری نوع‌محور + نقش محصور» → ماژول پیوست/رسانه دبیرخانه و بایگانی فاز ۲.

---

## ۷. کنترل تردد مراجعین و میهمانان

- **پورت‌شده از ماکروی Excel** (`کنترل_تردد.xlsm`) — سند زنده موفقیت مهاجرت فرآیند Excel به وب
- ثبت ورود/خروج، جست‌وجوی سریع با **کد ملی**، **چاپ برگ ملاقات**؛ توابع با پیشوند `traffic_` (جداسازی نام)
- جداول: `traffic_destinations`، `traffic_visits`

> **استفاده پلتفرم:** زیرسامانه بومی سطر ۴؛ تجربه پورت از Excel مستقیماً قابل تکرار است.

---

## ۸. سایر یافته‌های مهم

- **حاکمیت ITSM** (`governance.php`): مدیریت تغییر با **تأیید CAB**، مسئله ریشه‌ای با **Known Error و راه‌حل موقت**، برچسب ریسک، شماره‌گذاری تغییر/مسئله، حوزه‌بندی دید
- **اسکن دامین:** کارگر صف‌محور که کامپیوترهای آنلاین دامنه را کشف و رکورد دارایی می‌سازد (با رفع سازگاری MySQL strict)
- **اموال/انبار:** ۱۰ جدول (جزء حافظه/ذخیره‌سازی/گرافیک/جانبی، تاریخچه، روابط، پروفایل)؛ `api/inventory.php` با توکن + **client-agent پاورشل** برای جمع‌آوری موجودی سرور
- پایگاه دانش، اعلانات، تنظیمات key/value، تعطیلات، analytics، پشتیبان‌گیری (backup.ps1)، راهنمای کمک تصویری فارسی
- **نصب‌کننده مرحله‌ای** بدون ویرایش دستی تنظیمات (`install.php`)

## ۹. بدهی فنی (برنامه مهاجرت)

1. دو لایه تاریخی (اسکریپت ریشه vs MVC) — مسیریاب ۴٬۷۳۳ خطی تک‌فایل
2. تعریف نقش در دو جا (ENUM + role_permissions VARCHAR)
3. وابستگی به Access (TENTER) و SQLite جدا برای سرویس غذا
4. بدون سامانه migration (schema.sql + اسکریپت‌های rebuild/rollback)
5. گردش کلیدها (SSO_KEY، INVENTORY_TOKEN، .env) پیش از اتصال لازم است

## ۱۰. تأثیر مستقیم بر فاز ۱ پلتفرم

| از تیکتینگ | در فاز ۱ |
|---|---|
| کدهای مجوز + سه سطح own/unit/all | IAM پلتفرم از روز اول |
| manager_review پیش‌فرض + زنجیره رویداد | ارجاع اولیه دبیرخانه + تاریخچه نامه |
| SLA توقف‌پذیر + دو پله تشدید + بستن خودکار | موتور مهلت پاسخ و هشدار |
| org_unit_subtree_ids + معاونت + مدیر واحد | سطح داده «واحد و زیرمجموعه» |
| LDAP: نگاشت گروه→نقش + اولین ورود + همگام‌سازی | کانکتور احراز هویت |
| قرارداد HMAC غذا | پل نمایش «تیکت‌های من» |
| ticket_number_seq | شماره‌گذاری همزمان‌امن |

**پایان بررسی عمیق — نسخه ۲٫۰ — ۱۴۰۵/۰۷/۲۰**
