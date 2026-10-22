# پروندهٔ ممیزی سه سامانه مرجع: T5Web، TWF، Link5

**تاریخ بررسی:** ۲۰ اکتبر ۲۰۲۶  
**دامنه:** فقط محتویات ریپازیتوری `IO` که کاربر در اختیار گذاشته: `T5Web [3.5.7]`، `TWF 4.28`، `Link5 v4.0.4`، به‌علاوهٔ `T5V3.9.2`، `SDK 6.3.1.45` و `NewPack Dlls 10.06.1398`.  
**هدف:** تفکیک مسئولیت هر برنامه و تهیهٔ فهرست فرم‌ها/ماژول‌ها/اجزای نرم‌افزار و شواهد پایگاه داده، پیش از تصمیم دربارهٔ ادامهٔ پیاده‌سازی.  
**اصل امنیتی:** در فایل‌های تنظیمات مرجع اطلاعات اتصال و مقادیر حساس وجود دارد؛ عمداً هیچ رمز، کلید، رشتهٔ اتصال کامل، IP خصوصی یا نشانی عملیاتی حساس در این گزارش بازنشر نشده است. چون مقادیر حساس در فایل‌های تحویلی به‌صورت متنی/قابل‌خواندن بوده‌اند، قبل از استفاده عملیاتی باید آن‌ها را محرمانه تلقی و در محیط واقعی تعویض کرد.

> **اصلاح قطعی طبق تأیید کاربر دربارهٔ شبکهٔ فعال:** سه برنامه در شبکهٔ واقعی کاملاً به هم متصل‌اند؛ کاربر و موقعیت سازمانی در T5 تعریف می‌شود و دسترسی به T5Web/TWF/Link5 از سطح دسترسی مدیریت می‌شود. با تعریف/مجوز کاربر در T5، کاربر مجاز وارد TWF شده و کارکرد خود را می‌بیند؛ مدیران طبق موقعیت سازمانی درخواست‌ها را تأیید/رد می‌کنند. این رفتار عملیاتی از سوی کاربر تأیید شده و بر تنظیمات نمونهٔ تحویلی یا استنباط صرف از اسمبلی اولویت دارد. اتصال واقعی و schema مشترک در ادامه باید از شواهد/نسخهٔ شبکه ردیابی شود.

---

## ۰. حدود قطعیت این ممیزی

این بسته‌ها عمدتاً **برنامه‌های .NET Framework کامپایل‌شده** هستند؛ سورس کنترلرها و کد اصلی C# در این IO ارائه نشده است. بنابراین:

- در **T5Web و TWF**، فهرست فرم‌ها از فایل‌های واقعی `.cshtml` استخراج شده؛ فهرست کلاس‌ها و مدل‌ها از assemblyهای همراه استخراج شده است.
- در **Link5**، منبع فرم‌های WinForms در دسترس نیست؛ فهرست قابلیت‌ها بر اساس نام مدل‌ها، ساختار دیتابیس SQL، نام کنترل‌ها/منابع رشته‌ای و تنظیمات استخراج شده است. تعداد دقیق پنجره‌های WinForms را نمی‌توان از نام DLLها با قطعیت اعلام کرد.
- اسکریپت‌های `Link5.sql` و `Personnel.sql` قابل تحلیل‌اند؛ **اسکریپت کامل ساخت پایگاه‌های T5Web/TWF در پوشه‌هایشان دیده نشد**. فایل MDF همراه T5Web نام و کاربرد فایل را نشان می‌دهد، اما این فایل، دیتابیس عملیاتی اصلی آن نیست؛ اتصال برنامه به دیتابیس‌های SQL مجزا در تنظیمات مشخص شده.
- هیچ پایگاه داده‌ای در این بررسی به سرور متصل یا تغییر داده نشده است؛ نام/ستون‌های مدل‌های T5 از assemblyها **مدل دامنه** است، نه ادعای مشاهدهٔ schema واقعی مشتری.
- ادغام کارکردها و جریان داده در بخش معماری، **تحلیل بر مبنای شواهد بسته** است و هرجا از روی شواهد مستقیم قطعی نیست، با «استنباط» مشخص شده است.

---

## ۱. خلاصهٔ مدیریتی: سه برنامه سه وظیفهٔ جدا دارند

| برنامه | نقش اصلی | کاربران اصلی | داده/مسئولیت محوری | نتیجهٔ کاربردی |
|---|---|---|---|---|
| **T5Web 3.5.7** | سامانهٔ وب مدیریتی جامع حضور و غیاب | مدیر سیستم، منابع انسانی، مدیر واحد، اپراتور | پرسنل، سازمان، شیفت/قانون، محاسبهٔ کارکرد، رکوردها، مرخصی، گزارش و طراحی گزارش | عملیات روزانه و پیکربندی/کنترل کامل حضور و غیاب از وب |
| **TWF 4.28** | گردش‌کار و درگاه درخواست‌های کارکنان/مدیران | کارمند، مدیر تأییدکننده، منابع انسانی | درخواست، تأیید/رد، مرخصی، مأموریت روزانه/ساعتی، تأخیر/تعجیل، اضافه‌کار، مانده و جانشین | خودخدمتی پرسنل و گردش تصویب؛ نه جایگزین کل پنل عملیاتی T5Web |
| **Link5 4.0.4** | برنامه/سرویس ارتباط با دستگاه‌ها + کنترل دسترسی و تبادل داده | اپراتور دستگاه/امنیت، مدیر سامانه | دستگاه، فرمان، وضعیت، رکوردهای خام، کاربران/کارت/بیومتریک، گیت/دسترسی، ارسال/دریافت و صف | خواندن و مدیریت دستگاه و همگام‌سازی رویدادها/کاربران؛ مکمل موتور حضور و غیاب |

**تقسیم مسئولیت مهم:** Link5 صاحب لایهٔ دستگاه و رویداد/کنترل دسترسی است؛ T5V3 موتور/کتابخانهٔ مدل و محاسبهٔ حضور/مرخصی است؛ T5Web پنل مدیریتی وب روی آن قابلیت‌هاست؛ TWF سطح گردش‌کار و درخواست است. این مرزبندی با تفکیک مدل‌ها و assemblyها پشتیبانی می‌شود، اما برای تعیین ترتیب دقیق تبادل در همهٔ استقرارها باید رفتار runtime و تنظیمات همان مشتری بررسی شود.

---

# ۲. T5Web 3.5.7 — پنل وب مدیریتی حضور و غیاب

## ۲.۱ شواهد بسته

- مسیر: `IO/T5Web [3.5.7]/App`
- **۶۷ پوشهٔ View**، شامل `Shared`، `Account` و `_ViewStart`.
- **۱۱۲ فایل cshtml** به‌صورت شمارش خام (شامل partialها، layoutها، صفحات حساب و خطا؛ بنابراین برابر با ۱۱۲ صفحهٔ مستقل نیست).
- اسمبلی‌های مرتبط: `T5V3Web.dll`, `T5V3.Model.dll`, `T5V3.DB.dll`, `T5V3.Attendance.dll`, `T5V3.LeaveCalcs.dll`, `T5V3.Report.dll`, `T5V3.ReportGenerator.dll`, `Personnel.dll`, `TC5.dll`, `TMisc5.dll` و وابستگی‌های MVC/EF/ReportViewer.
- `App/Misc/ToolbarItems.xml` و فایل‌های CSS/JS/تصویر: منو، ابزارها، کنترل‌های ورودی، تقویم فارسی، tree، جدول و گزارش.

## ۲.۲ فرم‌ها و صفحات: فهرست کامل فایل‌های View

### حساب و دسترسی
- `Account`: `Login`, `Manage`, `Register`, `ExternalLoginConfirmation`, `ExternalLoginFailure`؛ partialهای تغییر رمز، تنظیم رمز، حذف/فهرست ورود خارجی.
- `Home`: `Index`, `About`, `Contact`, `ChangePass`.
- `ChangeLock`: `Index`.

### ساختار سازمان و پرونده پرسنلی
- `Department/Index` — واحدها/دپارتمان.
- `DeptsChart/Index` — نمودار/چارت واحدها.
- `Title/Index` — عناوین/سمت‌ها.
- `EmpType/Index` — نوع استخدام/پرسنل.
- `Certificate/Index` — مدارک/گواهی‌ها.
- `UserInfo/Index` — پرونده اطلاعات پرسنلی.
- `UserAddProperty/Index` — مشخصه/فیلد اضافی پرسنل.
- `Manager/Index`, `Deputy/Index` — مدیر و جانشین/نمایندگی.

### تنظیمات تقویم، شیفت و قواعد
- `Calendar/Index`, `Calendar/Holidays` — تقویم و تعطیلات.
- `Shift/Index`, `AssignShift/Index`, `AssignShift/Add` — تعریف و تخصیص شیفت.
- `Schedule/Index`, `TempSchedules/Index` — برنامه/زمان‌بندی و برنامهٔ موقت.
- `Rule/Index`, `AssignRule/Index` — قواعد و تخصیص قواعد.
- `Ramezan/Index` — تنظیم/قاعدهٔ رمضان.
- `Parameters/Index` — پارامترهای محاسبه.
- `CustomWF/Index`, `Workflow/Index` — گردش‌کار سفارشی و گردش‌کار.
- `Dashboard/Index`, `DashboardItem/Index` — داشبورد و اقلام داشبورد.

### دستگاه، رکورد و ورود اطلاعات
- `Device/Index` — فهرست دستگاه.
- `DeviceOperations/Index`, `DeviceProperties`, `ShowUsers`, `DownloadUsers`, `UploadUsers` — عملیات/مشخصات دستگاه، مشاهده و دانلود/آپلود کاربران.
- `Records/Index` — رکوردهای تردد.
- `InsertRecord/Index` — ثبت رکورد.
- `USBManagement/Index` — مدیریت رسانه/فایل USB.
- `ImportFromExcel/Index`, `Import`, `Preview` — ویزارد ورود اکسل: ورود به بخش، بارگذاری/تنظیم، پیش‌نمایش.
- `Export/Index` — خروجی.
- `Gate/Index` — گیت/دسترسی.

### اصلاح و کنترل روزانه/جمعی
- `TotalControl/Index` — کنترل جامع روزانه/جدولی.
- `EditLateEarly/Index` — اصلاح دیرکرد و تعجیل.
- `InsertDailyLeave/Index`, `AddDailyLeave`, `AddLeavesAdded` — ثبت مرخصی روزانه/گروهی و نتیجه/موارد ثبت‌شده.
- `PersonnelStatus/Index` — وضعیت پرسنل.
- `Permit/Index` — مجوز.
- `Card/Index`, `Card/Apply` — کارت و تخصیص/درخواست کارت.
- `ExitType/Index`, `LeaveType/MyView`, `LeaveDescription/Index` — انواع خروج/مرخصی و شرح مرخصی.
- `TransferRemainedLeave/Index` — انتقال مانده مرخصی.

### گزارش‌ها و طراحی گزارش
- گزارش‌های اجرایی: `DailyReport`, `CardexReport`, `ExitReport`, `LateEarlyReport`, `OverTimeReport`, `PersonnelStatusReport`, `RemainedLeavesReport`, `RequestsReport`, `SumReport`, `UserReport` (هرکدام `Index`).
- `ReportDatas/Index`, `ShowReport/Index` — داده/اجرای گزارش.
- اجزای طراح گزارش: `ReportDesign/Index`, `ReportFilter/Index`, `ReportTablix/Index`, `ReportTablixColumn/Index`, `ReportTextbox/Index`, `ReportTitles/Index`, `ReportTitles/Show`.
- `GridReport/Index` — گزارش جدولی.

### درخواست‌ها، ممیزی و ابزار عمومی
- `ShowUsersRequest/Index` — درخواست‌های کاربران.
- `ViewSystemLogs/Index` — مشاهدهٔ گزارش‌های سیستم.
- `Shared`: `_Layout`, `_Menu`, `_MainToolbar`, `_Toolbar`, `_ToolbarSettings`, `_Grid`, `_Workflow`, انتخاب کاربر/تاریخ/دستگاه، ورودی‌های Material، dialog، زمان جاری، نام کاربر، خطا و اسکریپت‌های تأیید حذف/تنظیم فونت. این‌ها **partial/اجزای مشترک‌اند، نه صفحات مستقل**.

## ۲.۳ دامنهٔ مدل و منطق از اسمبلی‌های T5V3

### کلاس‌های دامنهٔ استخراج‌شده از `T5V3.Model.dll`
`AccessLevel`, `ADayShift`, `AllowedValue`, `AllowType`, `AppRuleModel`, `AreaModel`, `AreaPointModel`, `AutoSchedule`, `Calendar`, `CalendarHoliday`, `Card`, `Certificate`, `CustomWF`, `DailyLeave`, `Department`, `DepartmentRule`, `Device`, `DownloadRecordLog`, `DownloadTime`, `LeaveDescription`, `EditedValue`, `EmpType`, `HourlyLeave`, `LeavesAdded`, `LeaveType`, `Log`, `Manager`, `Notification`, `ParameterType`, `Paramtr`, `Personnel`, `PersonnelTitle`, `Position`, `PositionStep`, `ProgramAccess`, `Ramz`, `Record`, `ReportChart`, `ReportData`, `ReportFilter`, `Reportmdl`, `ReportTablix`, `ReportTablixColumn`, `ReportTextbox`, `Request`, `RequestAcceptMdl`, `Rule`, `Schedule`, `Shift`, `ShiftDetail`, `ShiftUnit`, `Template`, `TempSchedule`, `TitleChart`, `USBFileData`, `USBFileRecords`, `UserAccess`, `UserInfo`, `UserManager`, `UserPosition`, `UserPositionStep`, `UserRule`, `UserShift`, `ValidLeave`, `Workflow`, `WorkflowOperations`, `YearLeaveRemain` و enumهای مرتبط (منبع/نوع رکورد، نوع مرخصی، نوع قاعده، عملگر فیلتر، سطح دسترسی و حالت ورود/خروج).

### لایهٔ دادهٔ استخراج‌شده از `T5V3.DB.dll`
کلاس‌های دسترسی/مدیریت داده شامل مدیران `RecordDA`, `ScheduleDA`, `ShiftDA`, `ShiftDetailDA`, `UserInfoManagerDA`, `UserPermissionDA`, `UserRuleDA`, `UserShiftDA`, `RequestDA`, `RequestAcceptDA`, `EmpLeavesDA`, `DailyLeaveDA`, `HourlyLeaveDA`, `LeavesAddedDA`, `LeaveTypeDA`, `LeaveDescriptionDA`, `YearRemainDA`, `ValidLeaveDA`, `DeputyDA`, `DepartmentRuleDA`, `ParameterDA`, `RamezanDA`, `CalendarDA`, `AutoScheduleDA`, `TempScheduleDA`, `CustomWFDA`, `WorkflowDA`, `SystemLogDA`, مدیران گزارش، ناحیه و دستگاه/دانلود.

### حوزه‌های داده‌ای/جدولی قابل شناسایی از EntityClasses
مدل EF شامل موجودیت‌هایی مانند `Record`, `Schedule`, `Shift`, `ShiftDetail`, `RestTime`, `TempSchedule`, `UserInfoManager`, `UserPositionData`, `UserRule`, `UserShift`, `DepartmentRule`, `Calendar`, `CalendarHoliday`, `AutoSchedule`, `DailyLeaf`, `HourlyLeave`, `LeavesAdded`, `LeaveType`, `LeaveDescription`, `YearRemain`, `ValidLeave`, `Deputy`, `PersonnelDeputy`, `Request`, `RequestAccept`, `Workflow`, `CustomWorkflow`, `SystemLog`, `Parameter`, `Report`, `ReportAccess`, `ReportData`, `ReportChart`, `ReportFilter`, `ReportTablix`, `ReportTablixColumn`, `ReportTextbox`, `Area`, `AreaPoint`, `Card`, `Device`, `DownloadTime`, `DownloadTimeDevice`, `DownloadRecordsLog`, `EditedValue`, `EmpTypeLeave`, `Position`, `PositionStep`, `PositionStepData`, `AppRule`, `AllowValue` است.

> این‌ها نام entityهای assembly هستند؛ در نبود DDL کامل، نام فیزیکی/تعداد دقیق جدول‌ها را مساوی با فهرست entityها فرض نکنید.

## ۲.۴ پایگاه داده و اتصال
تنظیمات وب نشان می‌دهد برنامه دست‌کم به این قلمروهای داده‌ای وصل می‌شود:
1. **Personnel** — پایگاه سازمان/پرسنل؛ مدل‌های `Personnel.dll` و `DB.PersonnelMdl`.
2. **T5V3/ReportT5V3** — داده/موتور حضور و غیاب، قواعد، کارکرد، درخواست، گردش‌کار و گزارش.
3. **ASP.NET membership محلی** — MDF پیوست‌شده در `App_Data` با نام ASP.NET؛ این MDF به‌تنهایی نمایندهٔ دیتابیس اصلی Attendance نیست.
4. **Link5 Service URL** — یک سرویس محلی/شبکه برای ارتباطات دستگاه از وب به Link5.

وجود connection string در Web.config قطعی است؛ مقصد و schema باید برای تولید با مشورت کاربر و با نگهداری امن مقادیر انتخاب شوند.

## ۲.۵ برداشت عملی
T5Web وسیع‌ترین کنسول مدیریت است: اطلاعات پایه، محاسبه و اصلاح، گزارش‌گیری و designer. برخی فرم‌های آن فقط با دیدن View نام‌گذاری می‌شوند؛ منطق اجراییِ دقیقشان در DLL کامپایل‌شده پنهان است. «فرم موجود» به‌تنهایی تضمین نمی‌کند یک قابلیت مستقل، کامل یا فعال در پیکربندی باشد.

## ۲.۶ وضعیت بازسازی محلی گزارش‌های Daily و Sum (۲۰۲۶-۱۰-۲۱)

منبع مقایسهٔ رابط، `DailyReport/Index.cshtml`، `SumReport/Index.cshtml`، `DailyReport/DReport.aspx`، `SumReport/SumReport.aspx` و partialهای `Shared/_SelectUser.cshtml` و `Shared/_SelectDateInterval.cshtml` از نسخهٔ 3.5.7 است. صفحات مرجع با POST نمایش/اعتبارسنجی گزارش را آغاز می‌کنند، انتخاب پرسنل را از tree واحدها، فهرست کارمندان، جستجو و فیلتر پیشرفته می‌سازند و بازهٔ ماهانه یا دو تاریخ جلالی می‌پذیرند. خروجی هر دو در iframe و ReportViewer نمایش داده می‌شود؛ گزارش روزانه برای هر کارمند یک صفحه دارد (طبق `DReport.aspx`، تعداد صفحه برابر تعداد badgeهای انتخاب‌شده است) و صفحه‌بندی عمومی جدول ۵۰ رکوردی نیست.

CIL متد `DailyReportController.GetData` شناسه‌/نام/واحد، تاریخ و روز هفته، تا سه ورود و سه خروج، زمان کل/کار/ماموریت/مرخصی، تأخیر، تعجیل، کسرکار، زمان ناهار، اضافه‌کاری، وضعیت و شمار روزهای حضور/غیبت/تعطیلی را در schema گزارش می‌سازد. schema متد `SumReportController.GetData` فیلدهای `TotalDays`, `SchDays`, `PresentDays`, `AbsentDays`, `OTReal`, `OTFree`, `OTTotal`, `Late`, `Early`, `LessTotal`, `DutyHours`, `LeaveHours`, `DutyDays`, `LeaveDays`, `OTConst1`, `OTConst2` و `WorkDays` را دارد. این فهرست از نام ستون‌های `DataTable` در CIL به‌دست آمده؛ معانی/فرمول‌های هر ستون باید با داده و runtime نصب مرجع تطبیق داده شوند.

تحلیل ایستای REA از `UserSelectionController.CreateModel` وجود فیلترهای مدرک تحصیلی (`Certificate`)، نوع استخدام (`EmpType`) و propertyهای سفارشی با پرچم `IsSearchFilter` را تأیید می‌کند؛ partial بر اساس نوع property ورودی checkbox، متن یا فهرست گزینه‌ها می‌سازد. `FilterUsers`، علاوه بر این دو شناسهٔ پایه، مقادیر `StringValue` یا `DropDownValue` را تطبیق می‌دهد. این CIL وجود/ساختار فیلترها را نشان می‌دهد، نه مقادیر runtime propertyهای یک نصب مشخص.

در نسخهٔ محلی فعلی، `daily` و `sum` انتخاب پرسنل را به کارمندان مجاز در report data-scope محدود می‌کنند، فیلتر ماه/بازهٔ جلالی دارند، تاریخ‌های Jalali را نمایش می‌دهند، برای هر کارمند یک صفحه می‌سازند و CSV را به مجوز export محدود می‌کنند؛ خروجی Daily بیش از ۱۰٬۰۰۰ ردیف را به‌جای قطع بی‌اعلان رد می‌کند؛ متن سلول‌های CSV نیز در برابر formula injection خنثی می‌شود. Sum از aggregation پایگاه داده استفاده می‌کند. Daily ستون‌های شماره کارگری/نام/واحد را جدا می‌کند و تا سه ورود و سه خروج را از رکوردهای محلی نمایش می‌دهد؛ Sum نیز شماره کارگری و نام را جداگانه دارد. Sum محلی اکنون شمار روزهای دارای رکورد/حضور/غیبت و مجموع دقیقه‌های تأخیر، تعجیل، کارکرد و اضافه‌کاریِ ذخیره‌شده را تفکیک می‌کند. انتخاب‌گر محلی همان خانواده‌های Certificate، EmpType و فیلدهای سفارشیِ صریحاً علامت‌خورده برای جستجو را در یک endpoint محدود به data-scope اعمال می‌کند. بازهٔ سفارشیِ گزارش‌های استاندارد دیگر نیز پیاده شده است. تست‌های report با `apps.reports.tests`, `AccuracyTests` و `AdvancedBuilderTests` پوشش داده شده‌اند؛ آخرین اجرای کامل ۱۴۹ تست موفق بوده است. این آزمون‌ها برابری runtime با نسخهٔ نصب‌شده را اثبات نمی‌کنند.

**فاصلهٔ رفتاری/رابط باز:** گزینه‌ها و propertyهای فعال در شبکهٔ کاربر باید با runtime نصب‌شده تطبیق داده شوند؛ داده‌های نمونهٔ مخزن جایگزین آن نیستند. جستجوی محلی با نام/نام‌خانوادگی/شمارهٔ کارگری انجام می‌شود و اثبات نشده تمام فیلدهای جستجوی نسخهٔ مرجع را پوشش دهد. ReportViewer، چاپ و schema کامل گزارش هنوز پیاده نشده‌اند؛ Daily ستون‌های محاسباتی Duty/Leave/summary را ندارد و Sum فقط بخشی از آمار را دارد، نه همهٔ فیلدهای CIL. نتایج endpoint حداکثر ۵۰۰ کارمند دارد؛ queryهای Daily/Sum برای export و aggregation با دسته‌های محدود اجرا می‌شوند تا انتخاب‌های بزرگ از سقف پارامترهای DB عبور نکنند. گردش دقیق افزودن/حذف asynchronous مرجع هنوز کامل بازسازی نشده است. همچنین CIL مشاهده‌شده برای `RecordDA.GetAll` ترتیب صریحی را اثبات نکرد؛ ترتیب محلی گزارش Daily قطعی است ولی برابر بودن آن با ترتیب T5 نصب‌شده باید با runtime تأیید شود. بنابراین این پیاده‌سازی بازسازی کامل یا تأییدشدهٔ Daily/Sum نیست.

---

# ۳. TWF 4.28 — گردش‌کار/پرتال درخواست کارکنان

## ۳.۱ شواهد بسته

- مسیر: `IO/TWF 4.28/App`
- **۲۸ پوشهٔ ماژول View** (با `Shared` و `Login`)، **۵۹ فایل cshtml خام**؛ شامل partialها و صفحات Add/Edit، پس شمارش فایل معادل صفحهٔ مستقل نیست.
- اسمبلی‌های اختصاصی کلیدی: `MyTime3.dll`, `TD5.dll`; به‌علاوهٔ همان خانوادهٔ `T5V3.Model/DB/Attendance/LeaveCalcs/Report` و `Personnel.dll`.
- صفحهٔ Description تغییرات فقط به رفع نمایش تاریخ سال کبیسه اشاره دارد؛ بنابراین نسخه/تاریخچهٔ changelog در این پوشه محدود است.

## ۳.۲ فرم‌ها و زیر‌فرم‌ها: فهرست کامل فایل‌ها

- **Login**: `Login`, `ChangePassword`, `ChangePasswordForce`, `CheckLock`.
- **Home**: `Index`, `About`.
- **درخواست و تصویب**: `Request/New`, `Request/FromUser`, `Request/ShowPhoto`; `AcceptRequest/Index` و partial `_RequestsList`; `EditRequest/Index`; `ShowRequest/Index`; `ShowUsersRequest/Index`; `Undo/Index`.
- **مرخصی**: `DailyLeave/Add`, `DailyLeave/Edit`; `HourlyLeave/Add`, `HourlyLeave/Edit`; `RemainLeave/Index`, `RemainLeave/ForPersonnel`, `RemainLeave/GetForUser`; `LeavesReport/Index`.
- **مأموریت روزانه/ساعتی**: `DailyDuty/Add`, `DailyDuty/Edit`; `HourlyDuty/Add`, `HourlyDuty/Edit`.
- **تاخیر/تعجیل و رکورد اصلاحی**: `Late/Add`, `Late/Edit`; `Early/Add`, `Early/Edit`; `Record/Add`, `Record/Edit`.
- **اضافه‌کار و ثابت‌کارکرد**: `OverTime/Add`; `ConstOT/Index`, `ConstOT/Edit`; `OverTimeReport/Index`, `OverTimeReport/Show`.
- **پاداش/تنبیه و جانشین**: `Reward/Add`; `Deputy/Index` و `_CurrentDeputy`.
- **اطلاعات/گزارش‌های دیگر**: `PersonnelStatus/Index`; `LocationReport/Index`; `Report/Index` و `Report.aspx`; `SelectArea/Index`; `ViewR/Index`; `Settings/Index`.
- **Shared**: `_Layout`, `_Menu`, `_Toolbar`, `_ToolbarSettings`, `_UserName`, `_CurrentTime`, `_HomeStatus`, `_RemainLeave`, `_RequestForm`, `_SelectMultiUser`, `Error`.

## ۳.۳ مسئولیت و فرآیندها

شواهد کلاس‌های `MyTime3.Controllers.*` و `TD5.Controllers.*` نشان‌دهندهٔ کنترلرهای مشخص درخواست، تأیید درخواست، مرخصی، مأموریت، دیرکرد، تعجیل، رکورد، اضافه‌کار، مانده مرخصی، وضعیت پرسنل، گزارش، ناحیه، پاداش، جانشین، تنظیمات، ورود و تغییر رمز است.

**مدل وظیفه:** کاربر درخواست را ثبت می‌کند؛ مدیر/مسئول آن را بررسی و تصمیم‌گیری می‌کند؛ قابلیت‌های undo/edit و نمایش درخواست‌ها پشتیبانی پیرامونی‌اند. سامانه همچنین می‌تواند مانده مرخصی، مأموریت، اضافه‌کار، تأخیر/تعجیل و وضعیت را ارائه کند. بنابراین TWF سطح workflow/self-service است، نه سیستم اصلی دریافت رکورد از دستگاه یا مدیریت همهٔ پارامترهای محاسبه.

## ۳.۴ داده، اتصال و تنظیمات قابل مشاهده

- به **Personnel** و **T5V3** متصل است؛ دو connection string/مدل EF در تنظیمات دیده می‌شود.
- تنظیمات معنایی قابل مشاهده شامل انواع مرخصی منتخب/مخفی، تعداد روزهای قابل نمایش، محدودیت زمانی درخواست، حداکثر تعداد رکورد روزانه/ماهانه، فعال/غیرفعال بودن درخواست رکورد، مرخصی/مأموریت روزانه و ساعتی، پاداش، اضافه‌کار ثابت/درخواستی، تغییر رمز، CAPTCHA و خروجی گزارش است.
- کلاس‌های `TD5.Model.Request`, `TD5.Controllers.RequestController`, `UserInfoController`, `UserRuleControllers`, `CalendarControllers`, `DepartmentRuleControllers`, `LeaveTypeControllers` نشانهٔ لایهٔ سرویس/دادهٔ میانی هستند.
- فایل DDL مستقل برای دیتابیس TWF در پوشه موجود نیست. با توجه به assemblyها و اتصال‌ها، TWF روی داده‌های T5/Personnel کار می‌کند؛ ایجاد پایگاه سوم مستقل از این evidence قابل اثبات نیست.

## ۳.۵ جایگاه نسبت به T5Web

TWF احتمالاً دسترسی ساده‌تر و نقش‌محور برای کارکنان/مدیران به همان حوزهٔ منابع انسانی و Attendance است. فرم‌های مشابه مانند وضعیت پرسنل/گزارش، بدان معنا نیست که TWF کل قابلیت‌های T5Web را تکرار می‌کند. صفحه‌های اصلی TWF پیرامون درخواست، تأیید و موجودی مرخصی سازمان یافته‌اند.

---

# ۴. Link5 4.0.4 — مدیریت ارتباط دستگاه و کنترل دسترسی

## ۴.۱ شواهد بسته و اجزای قابل اجرا

- مسیر: `IO/Link5 v4.0.4`
- برنامهٔ اصلی: `Link5.exe`; پیکربندی: `Link5.exe.config`, `Link5.settings.xml`.
- ابزارهای مجزا: `Link5.Configurator.exe`, `Comm5Serv v3.exe`; پوشهٔ `service` نسخهٔ همراه سرویس‌ها/فایل‌های runtime را دارد.
- لایه‌های برنامه: `Link5.Model.dll`, `Link5.DB.dll`, `Link5.Options.dll`, `Link5.Save.dll`, `Link5.RecordTransfer.dll`, `Link5.CommandListener.dll`, `Device.dll`, `Personnel.dll`, `Sensor.dll`, `Push.dll`, `ServiceManager.dll`, `SettingsManager.dll`, `GCPModel.dll`, `GCPOption.dll`, `Bio5Securityv2.dll`, `Bode5Crypto.dll`, `xLink.dll`.
- پشتیبانی ارتباطی/دستگاهی بر اساس SDK و وابستگی‌ها شامل ZK SDK/ActiveX، ارتباط TCP/IP/USB/Serial، سرویس و push است؛ مدل دقیق وابسته به سخت‌افزار و مجوز است.
- کتابخانه‌های تصویری/بیومتریک موجودند (`Emgu.CV`, haar cascades، `QrReader`, `Bio5Security`); وجود DLL به‌تنهایی اثبات نمی‌کند همهٔ قابلیت‌های بینایی ماشین در هر نصب فعال است.
- `Log.txt`, `Errorlog.txt`, `test.txt` لاگ/فایل نمونه‌اند، نه مستند طراحی؛ در پروژهٔ واقعی ممکن است اطلاعات محیط عملیاتی داشته باشند.

## ۴.۲ نواحی UI/عملیاتی استنباط‌شده از کد کامپایل‌شده

با توجه به مدل‌ها و رشته‌های واقعی UI، Link5 شامل این نواحی عملیاتی است:

1. **اتصال و وضعیت دستگاه:** فهرست دستگاه‌ها، اتصال/قطع، ping، وضعیت آنلاین، اطلاعات/تنظیمات دستگاه، شماره سریال/شناسه، وضعیت آخرین خواندن.
2. **دریافت تردد:** دریافت رکوردها/زمان‌ها، download همه یا رکوردهای جدید، دانلود دوره‌ای/آنلاین، آخرین زمان/تعداد رکورد، تاریخچه و وضعیت انتقال.
3. **مدیریت کاربران دستگاه:** انتخاب پرسنل، دانلود کاربران/عکس/قالب، آپلود کاربران، مدیریت PIN/کارت/کلمه عبور/گروه/اعتبار، templateهای زیستی و روش verification.
4. **فرمان و صف:** commands، command logs، push commandها، فرمان‌های تغییر وضعیت/عملیات دستگاه، صف ارسال و پاسخ‌ها.
5. **رکورد و گزارش:** Record/RecordsLog، فیلتر تاریخ/دستگاه، خروجی Excel، گزارش، انتخاب ستون، نمایش/حذف/فیلتر رکوردهای خام/نامعتبر.
6. **کنترل دسترسی فیزیکی:** AccessGroup, AccessControl, AccessCombo, UserAccess, UserDeptAccess, UserDeviceAccess, Gate/door map, MapDoor, Access levels, card, blacklist, user alarm، visitor و VisitReason/VisitorAccessLevel.
7. **زمان‌بندی و نگهداری:** Schedule, ScheduleDevice, TimeTable, IntervalSchedule, BackupSchedule, SchedulerSettings/Options؛ همگام‌سازی ساعت، پاک‌سازی/حذف رکوردهای قدیمی، auto-connect/auto-download/backup.
8. **سرویس و انتقال:** نصب/مدیریت سرویس، push, web server/API، پورت TCP/HTTPS، listener، مسیر انتقال/ثبت.
9. **پیکربندی و پشتیبانی:** دیتابیس Personnel و Link5 جداگانه، تنظیمات عمومی، format mapping، کلیدهای دستگاه، لاگ، backup/restore، Excel/USB و اطلاعات نسخه/قفل.

**محدودیت:** این‌ها نواحی کارکردی استخراج‌شده‌اند؛ چون فرم‌های اصلی Link5 در exe هستند و فایل‌های سورس UI در IO نیست، نام و تعداد قطعی تمام پنجره‌ها/زیرپنجره‌ها قابل فهرست‌کردن نیست.

## ۴.۳ پایگاه داده Link5: دو فایل SQL و موجودیت‌ها

فایل‌های `Link5.sql` و `Personnel.sql` UTF-16LE هستند و DDL واقعی دارند. در مجموع **۷۶ جدول** و **۳ view** در دو فایل شناسایی شد. این شمارش مربوط به اسکریپت‌های تحویلی است، نه تضمین schema جاری نصب مشتری.

### `Link5.sql` — ۴۶ جدول + view `AllData`

**رکورد و صف انتقال:** `RecordsLog`, `RecordsLogData`, `RecordsLogInvalid`, `RecordsLogTemp`, `Command`, `CommandLog`, `PushCommand`, `PushCommands`, `ErrorLog`, `DeviceErrorLog`, `SystemLog`, `EvenetsLog` (همین املای موجود در اسکریپت).

**دستگاه/وضعیت/تنظیمات:** `DeviceAlarm`, `DeviceSetting`, `DeviceStatus`, `IntervalSchedule`, `Schedule`, `Schedules`, `ScheduleDevice`, `SchedulerOptions`, `SchedulerSettings`, `TimeTable`.

**مدل بدون جدول هم‌نام در این DDL:** `BackupSchedule` در `Link5.Model.dll` هست، ولی جدول هم‌نامی در دو اسکریپت SQL شمارش‌شده دیده نشد.

**دسترسی و کارت:** `AccessCombo`, `AccessControl`, `AccessGroup`, `BlackList`, `Card`, `UserAccess`, `UserDeptAccess`, `UserDeviceAccess`, `UserAlarm`, `Map`, `MapDoor`, `KeyCodeConvert`.

**کاربر و تنظیمات نرم‌افزار:** `User`, `Option`, `SelectedColumn`, `WebUser`, `webpages_Membership`, `webpages_OAuthMembership`, `webpages_Roles`, `webpages_UsersInRoles`.

**سرویس/API:** `WebService.Options`, `WebService.Queue`, `WebService.TermsOfApproval`.

**سایر/آزمون:** `test`.

> تفاوت املای `EvenetsLog` و تعدد جداول مشابه رکورد/فرمان حفظ شده چون نام واقعی در DDL همین است؛ در مهاجرت باید نام و وابستگی واقعی رعایت شود، نه پاک‌سازی حدسی.

### `Personnel.sql` — ۳۰ جدول + دو view

**پرسنل/سازمان:** `UserInfo`, `Department`, `EmpType`, `TitlesChart`, `UserInfoTitles`, `Certificate`, `UserInfoProperty`, `Property`, `PropertyDropdownValue`.

**دستگاه و ارتباط پرسنل:** `Device`, `DeviceBioTypes`, `DeviceConnectionState`, `DeviceMessage`, `DeviceMessagePersonnel`, `DeviceOperationLog`, `DeviceProps`, `ManagerDevice`, `UserBioData`, `UserDeviceUpload`, `UserDeviceValid`.

**گیت/دسترسی:** `Gate`.

**پنل و ابزارها:** `Dashboard`, `DashboardItem`, `FontSize`, `MyTimeToolbarItem`, `Template`.

**پزشکی/واکسیناسیون:** `VaccineLog`, `UserInfoVaccinationDetails` و viewهای `PersonnelVaccineLog` و `UserChartView`.

**سایر/نسخه‌ای:** `Ghadir1`, `OperationLog`.

### مدل‌های Link5.Model
`Access`, `AccessCombo`, `AccessControl`, `AccessGroup`, `BackupSchedule`, `Card`, `Command`, `DeviceAlarm`, `IntervalSchedule`, `DeviceSettings`, `EventLog`, `ListDevice`, `Map`, `MapDoor`, `PushCommand`, `RecordLog`, `Schedule`, `ScheduleDevice`, `SelectedColumn`, `TimeTable`, `User`, `UserAccess`, `UserAlarm`, `Visitor`, `VisitorAccessLevel`, `VisitReason` و enumهای مرتبط.

### دیتابیس‌های مشخص‌شده در تنظیمات
برنامه دو اتصال مجزا دارد: **Link5 DB** برای وضعیت دستگاه/دسترسی/صف/تنظیمات و **Personnel DB** برای اطلاعات کارکنان و ساختار سازمان. تنظیمات سرویس نیز پارامترهای دیگری برای دیتابیس/API دارد. مقادیر اتصال و رمز در این گزارش حذف شده‌اند.

---

# ۵. اقلام دیگر IO و ارتباطشان با سه برنامه

## ۵.۱ `T5V3.9.2`
این یک پوشهٔ پروژهٔ مستقلِ قابل‌خواندن نیست؛ مجموعه‌ای از assemblyهای موتور T5 است: `T5V3.exe`, `T5V3.Model`, `T5V3.DB`, `T5V3.Attendance`, `T5V3.LeaveCalcs`, `T5V3.Misc`, `T5V3.Report`, `T5V3.ReportGenerator`, `T5V3.UI`, `T5V3.ZK`, `Personnel.DLL`, `GeneralControls.dll`, `Push.dll`, `Sensor.dll`, `SettingsManager.dll`, ReportViewer و `Settings.xml`. این اجزا به‌عنوان موتور/کتابخانه/رابط دستگاه، از سوی T5Web و TWF استفاده می‌شوند؛ قابل‌تمایز از خود UIهای وب.

## ۵.۲ `SDK 6.3.1.45`
مجموعهٔ SDK/درایور و ابزارهای ثبت/حذف SDK: ارتباط COM/USB/TCP و دستگاه‌های ZK (مانند `zkemkeeper`, `zkemsdk`, `commpro`, `plcommpro`, `tcpcomm`, `usbcomm` و فایل‌های register/unregister). این بسته زیرساختی است و خودش سامانهٔ کسب‌وکار یا دیتابیس حضور و غیاب نیست.

## ۵.۳ `NewPack Dlls 10.06.1398`
بستهٔ پیش‌نیاز/نصب قدیمی‌تر: نسخه‌های SDK، Tiny OCX 32/64 بیتی، installer سرویس و DLLهای ReportViewer/Entity Framework/MySQL/interop. نسخهٔ OCX و فایل‌های register نشان می‌دهد بخشی از محیط legacy ممکن است نصب COM/سرویس Windows بخواهد؛ اما این به‌معنای الزامِ همان معماری برای سامانهٔ جدید نیست.

## ۵.۴ ساختار عمومی و خطرهای نصب
- برنامه‌های وب: ASP.NET MVC/.NET Framework، IIS/ASP.NET، SQL Server و ReportViewer در بسته‌ها دیده می‌شود.
- Link5: WinForms/.NET Framework، SQL Server، DLL/SDK دستگاه، و در بعضی استقرارها سرویس ارتباطی/Push.
- این‌ها مشخصات مرجع فعلی‌اند، نه تصمیم معماری سامانهٔ جدید. محدودیت کاربر دربارهٔ عدم استفاده از سرویس ویندوزی برای محصول جدید همچنان برقرار است.
- `README.md` در IO تقریباً خالی است و Descriptionهای دو وب‌اپ نیز مستند کامل نصب/معماری نیستند.

---

# ۶. مقایسهٔ قابلیت/مرز داده

| قابلیت | T5Web | TWF | Link5 |
|---|---:|---:|---:|
| ساختار سازمان/اطلاعات پرسنل | مدیریت کامل وب | مصرف/نمایش برای درخواست‌ها و نقش‌ها | مصرف برای کاربران دستگاه/Personnel DB |
| دریافت رکورد از دستگاه | مدیریت/نمایش و عملیات متصل | خیر؛ به‌صورت صفحهٔ اصلی دیده نشد | **مسئولیت محوری** |
| محاسبهٔ حضور/شیفت/کارکرد | **مدیریت و کنترل**؛ موتور در T5V3 | نمایش/درخواست مرتبط | خیر؛ لایهٔ دستگاه/رویداد |
| اصلاح/ثبت دستی رکورد | بله: TotalControl/InsertRecord/EditLateEarly/Records | رکورد/درخواست محدود | رکورد خام/انتقال و مدیریت device-side |
| مرخصی و مانده | پیکربندی، گزارش و عملیات | **خودخدمتی/درخواست/تأیید و مانده** | خیر |
| گردش تأیید | Workflow/CustomWF در پنل | **هستهٔ گردش درخواست کارکنان** | خیر؛ صف فرمان دستگاه با workflow کسب‌وکار فرق دارد |
| گزارش‌ساز | طراحی چندجزئی Tablix/filter/textbox/title | گزارش‌های محدودتر | log/export/گزارش عملیاتی و انتخاب ستون |
| کنترل دسترسی فیزیکی | Gate و برخی قابلیت‌های مدیریتی | خیر | **AccessGroup/AccessControl/Card/Gate/Map/Visitor** |
| دانلود/آپلود کاربران دستگاه | UI مدیریتی فراخوانی عملیات Link5 | خیر | **دانلود/آپلود واقعی و صف فرمان** |

---

# ۷. نمای پیشنهادی از جریان کار (با درجهٔ قطعیت)

1. دستگاه اثرانگشت/کارت، رویداد را ثبت می‌کند.
2. **Link5** از دستگاه خوانده، وضعیت/فرمان/رکورد خام را مدیریت و داده را به DB/سرویس انتقال می‌دهد. (پشتیبانی مستقیم توسط مدل RecordLog، queue/command، device status و قابلیت‌های download.)
3. **T5V3** مدل و منطق محاسبهٔ Attendance/Leave/Schedule را در اختیار می‌گذارد. (پشتیبانی مستقیم توسط اسمبلی Attendance/LeaveCalcs و کلاس‌های Schedule/Shift/Record/Leave.)
4. **T5Web** برای منابع انسانی/مدیران کنترل تنظیمات و عملیات روزمره، اصلاح و گزارش را فراهم می‌کند.
5. **TWF** درخواست‌های کارکنان و جریان تصویب را روی دادهٔ T5/Personnel فراهم می‌کند.

**قطعی نیست:** ترتیب دقیق فراخوانی‌ها، کدام دیتابیس در لحظهٔ هر عملیات نوشته می‌شود، رفتار Sync در هر نصب، و اینکه آیا همهٔ سرویس‌ها همیشه روشن‌اند. برای این موارد باید تنظیمات واقعی همان مشتری و trace runtime بررسی شود.

---

# ۸. نتیجهٔ ممیزی و اصولی که باید پیش از توسعه تثبیت شوند

1. این سه محصول **یک محصول با سه منوی هم‌ارز نیستند**؛ سه مسئولیت جدا دارند: موتور مدیریتی Attendance، workflow پرسنلی، و Link5 دستگاه/دسترسی.
2. پوشه‌های `T5Web` و `TWF` قابل شمارش صفحه‌اند؛ `Link5` از روی سورس UI در دسترس، قابل شمارش قطعی فرم نیست. نباید برای Link5 عدد ساختگی از فرم‌ها اعلام کرد.
3. برای فرم‌های T5Web، ۱۱۲ cshtml شامل partialهاست؛ برای TWF، ۵۹ cshtml نیز شامل partialها و Add/Edit است. شمارش باید با «صفحه واقعی» تفکیک شود و هر فرم با عملیاتش اعتبارسنجی شود.
4. دیتابیس یک schema واحد نیست: شواهد از Personnel، T5V3/Attendance و Link5 DB جداگانه حکایت دارد؛ TWF عمدتاً روی Personnel/T5V3 است. ادغام آینده باید با map جدول به جدول انجام شود، نه با حدس و تکرار مدل‌ها.
5. اسکریپت‌های SQL کامل فقط برای Link5/Personnel ارائه شده‌اند. schema T5 را تا تهیهٔ DDL یا backup قابل‌خواندن نباید «کامل استخراج‌شده» اعلام کرد.
6. تصمیم نوع دیتابیس سامانهٔ جدید طبق دستور کاربر **باز می‌ماند**؛ این گزارش توصیه به انتخاب SQL Server/MySQL/SQLite نمی‌کند.
7. برای ادامه، ابتدا ماتریس «قابلیت ← برنامهٔ مالک ← منبع داده ← عملیات/فرم ← مرجع تست» تهیه شود؛ سپس تعیین شود کدام قابلیت‌ها در محصول یکپارچه اجرا شوند و کدام صرفاً adapter/bridge باشند. قبل از این تصمیم، صفحات مشابه را نباید در چند ماژول دوباره ساخت.

---

## پیوست A — شمارش خام فرم‌ها

| پوشه | پوشه‌های View سطح اول | فایل‌های `.cshtml` خام | نکته |
|---|---:|---:|---|
| T5Web 3.5.7 | ۶۷ | ۱۱۲ | شامل Account/Shared/layout/partialها |
| TWF 4.28 | ۲۸ | ۵۹ | شامل Shared/partialها و Add/Edit |
| Link5 4.0.4 | UI کامپایل‌شده؛ سورس View ارائه نشده | **قابل تعیین نیست** | مدل‌ها، SQL، منابع و نام کنترل‌ها بررسی شده |

## پیوست B — فایل‌های مبنای مستقیم

- `T5Web [3.5.7]/App/Views/**`, `App/Web.config`, `App/Misc/ToolbarItems.xml`, `App/bin/T5V3*.dll`, `Personnel.dll`.
- `TWF 4.28/App/Views/**`, `App/Web.config`, `App/bin/MyTime3.dll`, `TD5.dll`, `T5V3*.dll`, `Personnel.dll`.
- `Link5 v4.0.4/Link5.sql`, `Personnel.sql`, `Link5.exe.config`, `Link5.settings.xml`, `Link5.Model.dll`, `Link5.DB.dll`, `Link5.exe`, `service/**`.
- ریشهٔ IO: `T5V3.9.2/**`, `SDK 6.3.1.45/**`, `NewPack Dlls 10.06.1398/**`.
