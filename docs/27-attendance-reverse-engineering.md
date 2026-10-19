# ۲۷ — مهندسی معکوس سامانه حضور و غیاب (لایه‌به‌لایه) + ایرادات امنیتی

> روش: **شیوه‌ی REA (Reverse Engineer Anything)** — از رفتار تا باینری، لایه‌به‌لایه با شواهد.
> مراجع: ① کد وب `alirezarahimy-code/IO` (MyTime3) ② دو نرم‌افزار EXE (در انتظار تحویل؛ لایه‌ی باینری در نسخه‌های بعدی این سند تکمیل می‌شود).

## لایه ۱ — رفتار (ماژول‌ها و فرم‌ها)

| ماژول (Views) | رفتار |
|---|---|
| Login / Home | ورود (Captcha اختیاری)، کارت کاربر (نام، سمت، **سمت بالادست**)، شمارنده درخواست‌ها (همه/در انتظار/پردازش‌شده/لغوشده)، فهرست درخواست‌های قابل تأیید، وضعیت روزانه |
| Record | ثبت/ویرایش **رویداد تردد** (تاریخ شمسی + ساعت + شرح) — درخواست اصلاح رکورد با سهمیه |
| Late / Early | تاخیر / خروج زودهنگام (با قابلیت اعتبارسنجی `ValidLateEarly`) |
| HourlyLeave / DailyLeave | مرخصی ساعتی (از ساعت تا ساعت) / روزانه (از تاریخ تا تاریخ) با نوع مرخصی + شرح |
| RemainLeave / LeavesReport | مانده مرخصی + گزارش مرخصی‌ها |
| HourlyDuty / DailyDuty | ماموریت ساعتی / روزانه |
| OverTime / ConstOT / OverTimeReport | اضافه‌کاری + **ثابت‌های محاسباتی** + گزارش |
| Request / ShowRequest / ShowUsersRequest | ثبت درخواست یکپارچه (مدل `GeneralRequest`: نوع + نوع مرخصی + تاریخ/ساعت‌ها + شرح) |
| AcceptRequest / EditRequest / Undo | تأیید/رد، ویرایش، **ابطال (Undo)** |
| Deputy | **جانشین تأیید** (بازه‌ی فعال) |
| Reward | پاداش/تنبیه |
| SelectArea / LocationReport | **محدوده مجاز جغرافیایی (geo-fence)** + گزارش موقعیت |
| PersonnelStatus / Settings / Report / ViewR | وضعیت پرسنل، تنظیمات سیاست‌ها، گزارش‌ها |

## لایه ۲ — منطق کسب‌وکار (شواهد: web.config + ساختار فرم‌ها)

- ساعت کاری روزانه **۸ ساعت** (`HoursOfDays`)؛ نمایش بازه‌ی **۳۸ روز** (`ShowDays`)
- **سهمیه‌ی درخواست اصلاح رکورد:** روزانه ۱ / ماهانه ۳ (`RequestRecordsDailyLimit/MonthlyLimit`)
- **آخرین مهلت ثبت درخواست در ماه:** روز ۱۶ (`LastDayForRequest`)؛ حداکثر قدمت درخواست (`MaxRequestAgeDays`)
- انواع مرخصی با محدودیت/پنهان‌سازی (`LeaveID`, `Limit1LeaveID`, `HideLeaveTypes`)
- تایید سلسله‌مراتبی بر اساس **سمت بالادست** (TitleChart) + **جانشین** (Deputy)
- اضافه‌کاری با ثابت‌های قابل تنظیم (ConstOT)؛ پاداش/تنبیه (`ViewRewPen`)
- geo-fence: محدوده (مرکز + شعاع) و گزارش ترددهای داخل/خارج
- سیاست رمز عبور (اجبار/دوره‌ای/محدودیت)، کوکی، Captcha — همه با کلید روشن/خاموش

## لایه ۳ — داده

- دیتابیس `T5V3` (سامانه دستگاه تردد تی‌۵) + `Personnel` (پرسنلی) با EF6 (دو مدل EDMX)
- رشته‌های فارسی از کلاس منبع `MyTime3.Content.PersianString`
- مدل درخواست یکتا `GeneralRequest` با فیلدهای From/To/For + ساعت‌ها + نوع مرخصی

## لایه ۴ — باینری و امنیت

### ساختار باینری وب (bin/)
`MyTime3.dll` + `Personnel.dll` (+ PDB) = منطق کامپایل‌شده؛ EF6، Dapper، ReportViewer، CaptchaMvc. **لایه‌ی EXEها با تحویل فایل‌ها تکمیل می‌شود** (ابزار: REA CLI + pefile/dnfile).

### 🔴 ایرادات امنیتی یافت‌شده (مهم)
1. **نشت رمز پایگاه‌داده در مخزن عمومی `IO`** — `web.config` حاوی connection string با نام کاربری/رمز متن ساده و آی‌پی داخلی سرور است. اقدام فوری: خصوصی‌سازی مخزن + **بازنشانی رمزها** + حذف از تاریخچه.
2. `CookiesSecure=false` — کوکی بدون HTTPS.
3. `Captcha=false` — ورود بدون کپچا (در برابر حمله‌ی حدس رمز).
4. احراز هویت `mode="Windows"` با اجازه‌ی کاربر ناشناس (`allow users="?"`) — کنترل دسترسی تماماً لایه‌ی اپلیکیشن است؛ باید بازبینی شود.
5. `LockIP` ثابت + `LogoPath` مسیر فایل محلی — نشت اطلاعات زیرساخت در config عمومی.

### اقدامات اصلاحی در سامانه‌ی جدید
- اتصال دیتابیس فقط از متغیر محیطی (نه در مخزن)؛ HTTPS/کوکی امن؛ کپچا و محدودیت نرخ ورود؛ اصل کمترین امتیاز؛ لاگ حسابرسی.

## نگاشت به سامانه‌ی بومی (نتیجه‌ی مهندسی معکوس)

ماژول `attendance` بومی همه‌ی رفتارهای بالا را با کلیدهای `attendance.*` و یکپارچه با هسته (اعلان، کاربران، تقویم) می‌سازد: رویداد تردد، خلاصه‌ی روزانه (تاخیر/تعجیل/اضافه‌کاری)، درخواست‌های یکپارچه با تأیید سلسله‌مراتبی و جانشین، انواع مرخصی/مانده، ماموریت، اضافه‌کاری + ثابت‌ها، پاداش/تنبیه، geo-fence، گزارش‌ها و تنظیمات سیاست.

---

## پیوست الف — نگاشت رفتار مرجع ← پیاده‌سازی بومی

| رفتار مرجع (IO) | پیاده‌سازی آرینت |
|---|---|
| ثبت ورود/خروج (Record/Add) | `attendance.register_record` + فرم «ماه» و `AttendancePunch` |
| HourlyLeave / DailyLeave | `AttendanceRequest` (leave_hourly/daily) + `LeaveBalance` |
| HourlyDuty / DailyDuty | `AttendanceRequest` (mission_hourly/daily) |
| OverTime + ConstOT | `AttendanceRequest` (overtime) + `OvertimeConstant` |
| RequestRecordsDailyLimit/MonthlyLimit | سهمیه اصلاح رکورد در `AttendancePolicy` |
| LastDayForRequest / MaxRequestAgeDays / ShowDays / HoursOfDays | `AttendancePolicy` (همان پیش‌فرض‌ها) |
| ShowRequest / AcceptRequest / RejectRequest / Undo | `request_list` / `request_decide` (approve/reject/cancel) |
| Deputy | مدل `Deputy` + قاعده `can_approve` (جانشینِ مدیر) |
| Reward | `RewardPenalty` |
| RemainLeave / LeavesReport | صفحه «مانده مرخصی» + `leaves_report` |
| OverTimeReport | `overtime_report` |
| LocationReport + SelectArea | `location_report` + `GeoArea` (haversine) |
| Home: کارت کاربر + شمارنده درخواست‌ها + پنل جانشین | داشبورد حضور و غیاب (KPI + نیازمند تأیید) |
| Settings (web.config) | صفحه «سیاست‌ها» + Feature Toggles |

## پیوست ب — رفع ایرادات امنیتی (در پیاده‌سازی بومی)

- رمز عبور پایگاه‌داده در `web.config` متن ساده ← در آرینت تنظیمات از متغیر محیطی/فایل خارج از مخزن است.
- `CookiesSecure=false` ← کوکی‌های امن + SameSite.
- `Captcha=false` ← قابلیت کپچا + محدودسازی نرخ (rate-limit) در گذر ورود.
- Windows Auth با `allow users=?` و `LockIP` شکننده ← احراز هویت نقش‌محور + کنترل دسترسی (`attendance.manage_attendance`) + گزارش دسترسی.
- گزارش‌ها/درخواست‌ها: بازرسی‌پذیری (audit log) + محدوده‌های جغرافیایی برای ثبت تردد میدانی.

> **اقدام فوری مالک سامانه IO:** مخزن را خصوصی کنید و رمزهای افشا‌شده را بچرخانید (تاریخچه مخزن همچنان رمزها را نگه می‌دارد).

## پیوست ج — وضعیت تحویل

- کد وب IO: دریافت و به‌صورت لایه‌ای مهندسی معکوس شد (این سند).
- دو فایل اجرایی (EXE): **هنوز دریافت نشده** — پس از ارسال (فشرده‌سازی در چت یا بارگذاری در GitHub) با `rea` + `pefile/dnfile` تحلیل ایستا می‌شوند و یافته‌ها در همین سند اضافه می‌شود.

---

## پیوست د — لایه دستگاه (تحلیل ایستای EXE/DLLها — REA/dnfile)

بسته استقرار کامل سامانه (کلون‌شده از `alirezarahimy-code/IO`) شامل ۶ جزء است:

| جزء | ماهیت | یافته کلیدی |
|---|---|---|
| `T5V3.9.2/T5V3.exe` | نرم‌افزار دسکتاپ حضور و غیاب | دو رشته اتصال `T5V3ConnectionString` / `PersonnelConnectionString`؛ **قفل سخت‌افزاری لایسنس** (`قفل شبکه متصل نیست`، `DeviceCountLimit`، `PersonnelCountLimit`، `LockIP`) |
| `Link5 v4.0.4/Comm5Serv v3.exe` + `service/Comm5Service v4.exe` | سرویس ارتباط دستگاه | پروتکل ZKTeco (جدول‌های `ATTLOG`/`OPERLOG`/`OPLOG`/`FACE`/`ERRORLOG`)؛ انواع انتقال: `AttLog, OpLog, AttPhoto, EnrollUser, ChgUser, EnrollFP, ChgFP, FPImag, FACE, UserPic, WORKCODE, BioPhoto`؛ `SaveAllRecords`، `AUTH=TIMEOUT` |
| `Link5 v4.0.4/Link5.exe` + `Link5.Configurator.exe` | مدیریت دستگاه‌ها | `ConnectToDevice`، `DownloadAllRecords/DownloadDeviceUsers/DownloadTimeRecords`، `ClearLogs`، `DATA UPDATE USERINFO PIN= Card=`، `DeviceLimit/DeviceKeys` |
| `Device.dll` | پروتکل سطح پایین | `ConnectAndReadAllRecords` (TCP+UDP)، `ExtractRecordFromBlock`، `SetDeviceTime`، `GenerateDataAndCheckSum`، `ToZKHex` (پروتکل ZK) |
| `Link5.Save.dll` | **پل انتقال رکورد چندهدفه** | `SaveRecords` با مقصد: **`SaveT5`، `SaveChargoon`، `SaveJahan`، `SaveNarm`، `SavePW`، `SaveElmo`** + `SendToWebServer` + `InsertToDB/InsertToFile` + `ConvertKeyCodes` |
| `Link5.RecordTransfer.dll` | زمان‌بند انتقال | `TransferNewRecords`، `StartTimer/tmrTransfer_Elapsed`، `GetNewRecords`، `UpdateTransferedState` |
| `Link5.Model.dll` / `Link5.DB.dll` | مدل و داده سرور | دامنه **کنترل دسترسی**: Access/AccessGroup/AccessControl، Map/MapDoor (نقشه در)، Visitor/VisitReason، BlackList، Card، Schedule/TimeTable/ScheduleDevice (شیفت)، DeviceStatus (`GetLastReadRecordTime`)، RecordsLog، PushCommand، SystemLog؛ `UserDA.Login/IsAnyMainManager` |
| `Personnel.dll` | ماژول پرسنلی دسکتاپ | `UserInfo` (BadgeNumber، `CheckUserPass/EditPassword`)، `TitlesChart` (چارت سمت‌ها = «سمت بالادست»)، Gate، EmpType، `Template/UserInfoBioData` (قالب بیومتریک)، `UserDeviceUpload/UserDeviceValid` (همگام‌سازی کاربر با دستگاه)، `zkemkeeper.CZKEM` (SDK رسمی ZKTeco)، `SaveTemplateAsPalm/IrFace/VLFace` (کف دست + چهره مادون‌قرمز/مرئی)، `ExcelWriterPers` (خروجی اکسل فارسی) |
| `GCPModel.dll` | پروتکل نسل جدید | Command/CommandGroup/CommandState، Device/DeviceInfo/DeviceProperties، BioData/BioType، VerifyMode، Request/Response |
| `NewPack Dlls` / `SDK 6.3.1.45` | درایورها | `zkemkeeper.dll` (ZKTeco)، `96bitServiceInstaller` (سرویس بیومتریک 96bit) |
| `T5Web [3.5.7]` / `TWF 4.28` | وب (همان مرجع قبلی) | تغییرنامه: «حل مشکل نمایش تاریخ‌های سال کبیسه» — همان مسئله‌ای که آزمون‌های جلالی ما پوشش می‌دهند |

**جمع‌بندی معماری:** دستگاه‌های بیومتریک ZKTeco → سرور Link5/Comm5Serv (دریافت ATTLOG/USERINFO/قالب‌ها) → ثبت در `RecordsLog` → `RecordTransfer` (تایمردار، `UpdateTransferedState`) → `Saving.SaveRecords` به مقصد دلخواه (از جمله **چارگون** و سامانه خودشان T5) یا `SendToWebServer`. این دقیقاً همان الگوی «پل» ماست (docs/04) و در ماژول بومی با `AttendancePunch.source='device'` + API ورود رکورد پشتیبانی می‌شود.

## پیوست هـ — یافته‌های امنیتی تازه (بسته استقرار)

1. 🔴 **رمز `sa` پایگاه‌داده SQL Server به‌صورت متن ساده** در `T5Web [3.5.7]/App/Web.config` و `TWF 4.28/App/Web.config` + **آی‌پی داخلی `192.168.21.213`** + نام بانک‌های `PersonnelDemo2`/`ReportT5V3` → **فوراً رمز `sa` را بچرخانید** (ما مقدار رمز را هیچ‌جا بازنشر نمی‌دهیم). تنظیمات ما از متغیر محیطی است و این مشکل را ندارد.
2. 🔴 `Link5 v4.0.4/Log.txt` حاوی **لاگ واقعی رکوردهای تردد** (پیام‌های رمزنگاری‌شده تاریخ‌دار ۲۰۲۳) و `Errorlog.txt` → در انتشار عمومی نباید باشند؛ حریم خصوصی پرسنل.
3. 🟡 `Link5.exe.config`: اتصال با `integrated security` به بانک‌های محلی `Personnel`/`Link5` + `LockIP=127.0.0.1` (قفل لایسنس) — قابل قبول ولی نشان‌دهنده وابستگی به قفل سخت‌افزاری.
4. 🟡 T5V3 دارای **شمارنده مجوز** (`DeviceCountLimit`/`PersonnelCountLimit`) — مرز اخلاقی: تحلیل ما ایستا و برای مهاجرت مجاز است؛ **هیچ دور زدن لایسنسی انجام نمی‌شود**.
5. ✅ پیاده‌سازی بومی: تنظیمات از env، کوکی امن، کپچا/محدودسازی نرخ، نقش‌محوری، audit log، ثبت تردد با محدوده جغرافیایی.

> **اقدام فوری مالک:** ① مخزن را دوباره **Private** کنید (کلون مرجع نزد ماست) ② رمز `sa` را **بچرخانید** ③ لاگ‌های `Log.txt/Errorlog.txt` را از هر انتشار عمومی حذف کنید.
