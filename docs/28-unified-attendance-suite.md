# سند مادر — سامانه یکپارچه حضور و غیاب (Unified Attendance Suite)

> وضعیت: فاز طراحی و بازنویسی — جدا از اتوماسیون اداری. تصمیم ادغام در پایان کار، مشترک.
> منبع مهندسی معکوس: بسته استقرار IO (T5Web 3.5.7 + TWF 4.28 + T5V3.9.2 + Personnel.dll + Link5 4.0.4 + Comm5Serv + GCPModel) — روش REA (تحلیل ایستای دارای شواهد).

---

## ۱. اصل بنیادین: یک سامانه، صفر داپلیکیت

سه برنامه مرجع (وب T5، دسکتاپ T5V3/Personnel، سرور دستگاه Link5) همگی در **یک سامانه واحد** ادغام می‌شوند. هر فرم/مفهوم تکراری، **یک نسخه واحد و کامل‌ترین حالت** را می‌گیرد:

| مفهوم | وب T5 | دسکتاپ Personnel | Link5 | ✅ حالت واحد (ادغام‌شده) |
|---|---|---|---|---|
| کاربر/پرسنل | UserInfo, UserAddProperty | frmUserInfo, frmEditPersonnel | frmUser | **Employee + حساب کاربری** (یک رکورد هویت + پروفایل + فیلدهای سفارشی) |
| واحد سازمانی | Department, DeptsChart | frmDepartment, frmDeptsChart | frmSelectDepts | **Department** (درختی) |
| سمت سازمانی | Title, Manager | frmTitleChart, frmSelectTitle/Multi | — | **Position** (چارت با سمت بالادست) |
| نوع استخدام | EmpType | frmEmpType | — | **EmpType** |
| دستگاه تردد | Device, DeviceOperations(5 صفحه) | frmDevice/Finder/Properties/Logs/SMS | frmDeviceSettings, frmServiceSetup | **Device** (یک صفحه + زیربخش‌ها) |
| گیت/در | Gate | frmGate | Map/MapDoor | **AccessPoint** |
| کارت | Card | — | frmPishCards | **Card** |
| تردد | Records, InsertRecord | — | frmRecordsLog | **RawRecord (خام دستگاه) → Punch → DailyRecord** |
| مرخصی | InsertDailyLeave(3), LeaveType, LeaveDescription, TransferRemainedLeave | — | — | **LeaveType + LeaveBalance + Request** |
| شیفت | Shift, Schedule, AssignShift, AssignRule, Rule, TempSchedules | — | IntervalSchedule | **Shift + Assignment + Rule (+ رمضان)** |
| درخواست/گردش | Workflow, CustomWF, ShowUsersRequest | — | — | **Request + Workflow + Deputy** |
| خروج | ExitType, ExitReport | — | — | **ExitType** (زیرمجموعه درخواست) |
| گزارش‌ها | ۱۳ گزارش + طراح گزارش (7 صفحه) | ExcelWriterPers | — | **Reports + ReportDesigner + خروجی اکسل/چاپ** |
| تنظیمات | Parameters, ChangeLock, Certificate | frmCertificate, frmChangeLockType | frmOptions, frmEmailSettings | **Settings** (یک مرکز تنظیمات) |
| لاگ‌ها | ViewSystemLogs | frmDeviceLogs | frmRecordsLog, frmViewDownloadLog | **AuditLog یکپارچه** |
| ورود/حساب | Login, Register, Manage, ChangePass | — | frmLogin | **Auth یکپارچه + RBAC** |
| رمضان | Ramezan | — | — | **RamadanRule** در ماژول شیفت |
| ورود اکسل | ImportFromExcel(3) | frmExcel | — | **Excel Import** |
| USB | USBManagement | frmUSB | — | **USB Import** |
| بازدیدکننده | — | — | Visitor/VisitReason | **Visitor** |
| کنترل دسترسی فیزیکی | — | — | Access/AccessGroup/UserAccess | **AccessGroup/AccessRule** |
| بلک‌لیست | — | — | BlackList | **BlackList** |
| اعلان/پیامک | — | frmDeviceSMS | frmEmailSettings | **Notification** (قالب‌محور) |

---

## ۲. چینش دیتابیس (اولویت ۲) — ER خلاصه

### ۲.۱ هویت و سازمان (org, core)
- `Department(id, name, code, parent, is_active)`
- `Position(id, title, parent ← سمت بالادست, department, is_manager)`
- `EmpType(id, name, code)`
- `Employee(id, badge_no یکتا, first/last_name, national_id, emp_type, department, position, manager ← Employee, hire_date, is_active)`
- `EmployeeFieldDef(id, title, type: text/number/date/dropdown, options)` + `EmployeeFieldVal(employee, field_def, value)` — فیلدهای سفارشی (UserAddProperty/UserInfoProperty)
- `User(id, employee 1:1 nullable, username, is_active)` + نقش‌ها

### ۲.۲ دستگاه و ورودی (device)
- `Device(id, name, serial یکتا, model, ip, conn_type: tcp/udp/push, state, last_read_at, last_online_at, records_count)`
- `AccessPoint(id, name, device nullable, description)` (Gate/MapDoor)
- `Card(id, card_no یکتا, employee, is_active)` + `BlackList(card_no, reason)`
- `RawRecord(id, device, employee_code/pin, punch_time, verify_mode, work_code, state: new/transfered/error, payload)` ← RecordsLog با `UpdateTransferedState`
- `DeviceCommand(id, device, cmd_type, payload, state, created_by)` ← PushCommand
- `BioTemplate(id, employee, bio_type: fp/palm/face_vl/face_ir, version: v9/v10, device, size)` (فقط فهرست/مدیریت — نه خود قالب)
- `DeviceUserSync(id, device, employee, direction: up/down, state)` ← UserDeviceUpload/Valid
- `Visitor(id, name, card_no, reason ← VisitReason, enter/leave_time, employee_host)`

### ۲.۳ زمان و حضور و غیاب (attendance)
- `Holiday(id, date, title, kind: national/religious/org)`
- `Shift(id, name, start, end, work_days, grace_minutes, overnight)` + `RamadanRule(shift, start/end_jalali, reduced_minutes)`
- `ShiftAssignment(employee, shift, from_date, to_date)` ← AssignShift
- `ScheduleRule(id, name, priority, conditions_json, actions_json, is_active)` ← Rule/AssignRule (موتور قانون شیفت)
- `AttendancePolicy(singleton: hours_per_day, show_days, last_day_for_request, max_request_age_days, record_fix limits, valid_late_early, allow_hourly_free, overtime rates, night window)`
- `Punch(id, employee, date, time, direction: in/out, source: device/web/import/api, device, lat/lng, area, created_by)` ← Record/InsertRecord
- `DailyRecord(id, employee, date یکتا(user,date), check_in/out, late/overtime/early/worked minutes, state)` ← خلاصه روزانه
- `LeaveType(id, name, code, limit_per_year, needs_balance, is_active)` + `LeaveBalance(employee, leave_type, jyear unique, granted, used)`
- `Request(id, kind: leave_hourly/daily, mission_hourly/daily, overtime, record_fix, exit, employee, dates/times/hours/days, leave_type, status, decided_by/at/note, workflow_state)` ← ShowUsersRequest/Workflow
- `Deputy(employee, for_employee, from, to, is_active)`
- `RewardPenalty(id, employee, kind, date, title, amount)`
- `OvertimeConstant(key, title, value)`
- `GeoArea(id, name, lat, lng, radius_m, is_active)`

### ۲.۴ دسترسی، گزارش، سیستم (core/reports)
- `Role(id, name, code, is_system)` + `Permission(module, action)` + `RolePermission(role, permission)`
- `UserRole(user, role)` + `RoleDataScope(role, module, scope: self/dept/dept_tree/all)` ← UserDeptAccess/UserDeviceAccess
- `AuditLog(user, action, model, object_id, detail, ip, at)` ← SystemLog/EventLog/DeviceOperationLog
- `ReportDef(id, title, module, query/definition_json, columns_json, is_system)` ← ReportDesign/Tablix/Filter/Datas
- `AppSetting(key, value)` (Parameters) + `NotificationTemplate(kind, body)`

**قواعد صحت:** یکتایی‌ها (badge_no، card_no، serial، (employee,date) در DailyRecord)، حذف نرم (`is_active`)، مرجع تاریخ = جلالی (jdatetime) با ذخیره میلادی، همه FKها با `on_delete` صریح، ایندکس روی تاریخ/کارمند/وضعیت.

---

## ۳. رابط کاربری (اولویت ۳) — «آرام و شفاف» v3
- RTL کامل، تقویم جلالی در همه تاریخ‌ها، اعداد فارسی، رنگ‌های جامد + حاشیه + فضای سفید؛ بدون گرادیان/سایه سنگین/شیشه‌ای.
- طراحی سیستمی: کارت، جدول (تراکم مناسب)، chip وضعیت‌ها، فرم‌های گریدی، نوار ابزار ثابت، مودال تأیید.
- دسترس‌پذیری: کنتراست بالا، فوکوس مشخص، پیام‌های خطای فارسی کنار فیلد.
- داشبورد: KPI کارکرد ماه، نیازمند تأیید، وضعیت دستگاه‌ها، نمودارهای تخت (flat).

## ۴. سطح دسترسی حرفه‌ای (اولویت ۴) — RBAC + دامنه داده
- نقش‌های پیش‌فرض: `admin`، `hr_manager` (منابع انسانی)، `supervisor` (مدیر میانی/تأییدکننده)، `operator` (اپراتور تردد)، `employee`، `guard` (نگهبانی/بازدیدکننده).
- ماتریس: هر نقش × هر ماژول × عمل‌ها (`view, add, change, delete, approve, report, export, manage`).
- **دامنه داده** per role/module: `self` فقط خودش | `dept` واحد خودش | `dept_tree` زیرمجموعه‌ها | `all` همه.
- تفویض: Deputy برای تأیید در بازه؛ تأیید چندمرحله‌ای (Workflow/CustomWF).
- لاگ کامل دسترسی‌ها (AuditLog) + ورود موفق/ناموفق.

## ۵. گزارش‌ها (اولویت ۵)
پیش‌فرض‌های سیستم (از هر ۳ برنامه، ادغام‌شده):
1. کارکرد ماهانه (SumReport) 2. روزانه (DailyReport) 3. کارنامه فرد (Cardex/UserReport) 4. تأخیر و تعجیل (LateEarlyReport) 5. خروج‌ها (ExitReport) 6. اضافه‌کاری (OverTimeReport) 7. مانده مرخصی (RemainedLeavesReport) 8. درخواست‌ها (RequestsReport) 9. وضعیت پرسنل (PersonnelStatusReport) 10. تردد خام دستگاه‌ها 11. وضعیت دستگاه‌ها 12. بازدیدکنندگان 13. مرخصی‌های جمعی/جمع‌کل + **طراح گزارش** (ReportDef: فیلتر/ستون/مجموع) + خروجی اکسل/چاپ.

## ۶. فرم‌ها و صحت (اولویت ۶)
- هر فرم: اعتبارسنجی سمت کلاینت+سرور، پیام فارسی، تأیید حذف، وضعیت‌های خالی مناسب، idempotency در عملیات (مثل ثبت تردد).
- تست خودکار برای هر ماژول (منطق + ویو) — معیار تحویل: سبز بودن کل مجموعه.
- تاریخ‌ها: جلالی در ورودی/خروجی، میلادی در ذخیره؛ سال کبیسه (رفع باگ مرجع) پوشش تست دارد.

## ۷. راهنمای سامانه (اولویت ۱)
در `attendance-suite/docs/guide/` — نصب، راه‌اندازی، نقش‌ها، گردش کار روزانه، گزارش‌ها، پل دستگاه، پرسش‌های متداول. (در ادامه تکمیل می‌شود.)

## ۸. برنامه اجرا
| گام | محتوا | وضعیت |
|---|---|---|
| ۱ | استخراج فهرست کامل فرم‌ها/ماژول‌ها (۳ برنامه) | ✅ |
| ۲ | سند ادغام + ER + RBAC (این سند) | ✅ |
| ۳ | اسکلت پروژه `attendance-suite` + مدل‌ها + مهاجرت + RBAC | ✅ (PR #12) |
| ۴ | فرم‌های هسته + داشبورد + ورود | ✅ (PR #13) |
| ۵ | گزارش‌ها + طراح گزارش | ✅ (PR #13/#14 + مجموع/فیلتر) |
| ۶ | پل دستگاه (سازگار RecordTransfer) + اکسل/USB | ✅ (PR #14 + ورود CSV) |
| ۷ | راهنمای کامل + تست نهایی + پیش‌نمایش | ✅ (۴۸ آزمون سبز) |


## افزوده: دروازه چندبرند دستگاه (بدون سرویس ویندوزی)
- **ZK/ZKTeco**: نظرسنجی مستقیم با pyzk (`run_device_bridge` / `pull_devices`) — حذف کامل نیاز به Link5/T5Server.
- **Virdi**: از طریق **پل یونیس (UNIS Manager — Union Community)**: خواندن لاگ از بانک یونیس
  (MS SQL/MySQL/Oracle/Access — متغیر محیطی `UNIS_DB_*`) یا پوشه خروجی CSV؛ مدل `UnisBridge`.
- هر دو مسیر به خط لوله واحد (idempotent ingest → تردد → روزانه) می‌ریزند؛ برندها همزمان.
- امنیت: نام جدول/ستون با الگوی شناسه‌ای (ضد تزریق)، رمز بانک فقط از env، ممیزی همگام‌سازی.
