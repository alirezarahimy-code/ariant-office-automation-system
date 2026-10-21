# T5Web 3.5.7 — تقسیم‌بندی کامل بازسازی و وضعیت واقعی

**مبنای موجودی:** پک فقط‌خواندنی `IO/T5Web [3.5.7]/App`؛ ۱۱۲ فایل `.cshtml` (شامل partial و layout)، اسمبلی‌های وب/مدل/DB/Attendance/Report و منابع فارسی. ۱۱۲ فایل به معنی ۱۱۲ صفحهٔ مستقل نیست. فهرست مبسوط مرجع در `docs/29-reference-systems-deep-inventory.md` و کنترلرها/ممیزی‌های انتخابی در `docs/31-rea-controller-and-form-inventory.md` است.

## جمع‌بندی بدون ابهام

- **بازنویسی کامل، خط‌به‌خط و پذیرش‌شدهٔ T5Web: هیچ ماژولی تا این لحظه.**
- در checkout فعلی بعضی صفحات/CRUDها و قابلیت‌های محلی وجود دارند؛ این‌ها «پیاده‌سازی جزئی» هستند، نه اثبات بازسازی T5Web.
- ممیزی استاتیک/خط‌به‌خط فقط برای بخش‌های منتخب انجام شده است: `ImportFromExcel`، `InsertRecord`، `EditLateEarly`، `InsertDailyLeave`، `USBManagement` و گزارش‌های `Daily`/`Sum`. ممیزی سندی به معنی بازنویسی آن بخش نیست.
- اسمبلی‌های کامپایل‌شده با REA/CIL بررسی می‌شوند؛ CIL سورس C# اصلی یا رفتار قطعی runtime نیست. تطبیق نهایی نیازمند آزمون مرجع/داده/نقش و مقایسهٔ رابط و نتیجه است.
- قابلیت USBManagement مرجع **خود T5** است. پل UNIS/Virdi قابلیت دیگری است و جایگزین یا زیرمنوی آن نمی‌شود.

### کدهای وضعیت

- **نیمه‌پیاده‌سازی محلی:** صفحه یا عملیات مشابه در checkout دیده می‌شود؛ برابری با مرجع بررسی/تأیید نشده.
- **ممیزی‌شده، بازنویسی‌نشده:** بخشی از View/CIL مستند شده، اما هنوز معادل اجرایی کامل ندارد.
- **مانده:** معادل T5 کامل در برنامهٔ محلی شناسایی نشده یا فقط نام/صفحهٔ مشابه وجود دارد.
- **قبولی خط‌به‌خط:** فقط وقتی تمام اجزای View، رفتار، مجوز، متن، داده و سناریو با مرجع سنجیده و تست شده باشد. **فعلاً هیچ موردی این وضعیت را ندارد.**

## فهرست کامل بخش‌های مرجع و وضعیت بازسازی

| بستهٔ کاری | تمام بخش‌ها/فرم‌های مرجع T5Web | وضعیت فعلی در برنامهٔ محلی |
|---|---|---|
| **T5-01 حساب، ورود و دسترسی** | `Account`: Login/Logout و helperهای `checkPassLogin`, `FirstLogin`, `PassChanged`; وجود viewهای Account برای Manage/Register/External Login بررسی و با metadata کنترلر تطبیق شد. `Home`: Index/About/Contact/ChangePass؛ `ChangeLock/Index` و endpoint `Check` | Login/Logout امن با نام‌های ورودی T5، redirect محلی، محدودسازی تلاش ناموفق، CAPTCHA، ورود اول با اجبار تغییر رمز و فرم ChangePass با فیلدهای T5 پیاده شده‌اند. صفحهٔ مدیران/نقش‌ها/ProgramAccess CRUD و دامنه دارد و لغو ProgramUser نشست‌های باز را می‌بندد. REA inventory کلاس `AccountController` در artifact 3.5.7، actionهای Register/Manage/ExternalLogin را نشان نمی‌دهد؛ فایل‌های view مربوط به‌تنهایی مسیر فعال را اثبات نمی‌کنند. CIL متد `ChangeLock.Check(int,string)` فقط `Persian.ErrorOperation` را می‌خواند، یک ثانیه مکث می‌کند و همان متن را برمی‌گرداند؛ ورودی کد/نوع را مصرف یا license state را تغییر نمی‌دهد. endpoint محلی همین پاسخ را بدون provider بازمی‌گرداند و هیچ فعال‌سازی جعلی انجام نمی‌دهد؛ اتصال به checker مجاز فروشنده فقط در صورت وجود provider معتبر ممکن است. **باقی‌ماندهٔ پذیرش: تطبیق runtime/دادهٔ واقعی؛ behavior ایستای همین DLL مبنای کد محلی است.** جزئیات در سند 31. |
| **T5-02 اطلاعات پایه و سازمان** | `Department/Index`, `DeptsChart/Index`, `Title/Index`, `EmpType/Index`, `Certificate/Index`, `UserInfo/Index`, `UserAddProperty/Index`, `Manager/Index`, `Deputy/Index` | بخش‌هایی مانند واحد/چارت، سمت، نوع استخدام، مدرک، اطلاعات پرسنل، فیلد سفارشی، مدیر/جانشین و ورود Excel در برنامهٔ محلی صفحه/CRUD دارند. **نیمه‌پیاده‌سازی؛ همهٔ فیلدها، ترتیب/متن، عملیات و roleهای مرجع تطبیق نشده‌اند.** |
| **T5-03 تقویم، شیفت، برنامه و قواعد** | `Calendar/Index`, `Calendar/Holidays`; `Shift/Index`; `AssignShift/Index`, `AssignShift/Add`; `Schedule/Index`; `TempSchedules/Index`; `Rule/Index`; `AssignRule/Index`; `Ramezan/Index`; `Parameters/Index`; `CustomWF/Index`, `Workflow/Index` | تقویم/تعطیلات، شیفت و تخصیص، شیفت موقت، قوانین، رمضان، پارامتر و workflow محلی وجود دارند. **نیمه‌پیاده‌سازی؛ الگوریتم زمان‌بندی/قواعد و تفاوت نسخه‌ها هنوز سرتاسری با مرجع آزموده نشده‌اند.** |
| **T5-04 داشبورد و ابزار مشترک** | `Dashboard/Index`, `DashboardItem/Index`; `Shared`: `_Layout`, `_Menu`, `_MainToolbar`, `_Toolbar`, `_ToolbarSettings`, `_Grid`, `_Workflow`, pickerهای user/date/device، Material inputها، dialog، ساعت، نام کاربر، خطا و اسکریپت‌های تأیید/فونت | shell و `base.html` و منوی نقش‌محور محلی وجود دارد؛ dashboard محلی نیز هست. **ماندهٔ تطبیق** برای ترتیب/نمایش همهٔ منوها، toolbarها، grid/pickerها، پیام‌ها، حالت‌های نقش‌محور و DashboardItem. |
| **T5-05 دستگاه، گیت و عملیات دستگاهی T5** | `Device/Index`; `DeviceOperations/Index`, `DeviceProperties`, `ShowUsers`, `DownloadUsers`, `UploadUsers`; `Gate/Index` | صفحه‌های device/operations/gates و کاربران دستگاه در برنامه هستند، اما پیاده‌سازی و کنترل آن‌ها هنوز **جایگزین اثبات‌شدهٔ عملیات T5Web نیست**. مالکیت UI/دادهٔ Link5 نیز باید جدا نگه داشته شود؛ منوها با TWF یا Link5 ادغام نشوند. |
| **T5-06 رکورد و ورود/خروج داده** | `Records/Index`; `InsertRecord/Index`; `USBManagement/Index`; `ImportFromExcel/Index`, `Preview`, `Import`; `Export/Index` | `InsertRecord` اکنون گام جزئی دیگری هم دارد: انتخاب‌گر JSON صفحه‌بندی‌شده و محدود به scope دسترسی، با جستجوی همهٔ پرسنل (به‌جای سقف اولیهٔ ۴۰۰ نفر)؛ قرارداد نام فیلد، تاریخ جلالی و CheckTypeهای ۰/۱ نیز به `Punch` محلی می‌روند. درخت دپارتمان/فیلترهای پیشرفتهٔ T5، LeaveTypeها و بعضی ستون‌های Record هنوز معادل ندارند. Excel محلی preview/validation امن‌تری دارد ولی با ریزرفتار مرجع برابر نیست. **USBManagement مرجع T5 و Export بازسازی نشده‌اند.** ممیزی‌های InsertRecord/Excel/USB در سند 31 استاتیک‌اند. |
| **T5-07 کنترل و اصلاح کارکرد روزانه** | `TotalControl/Index`; `EditLateEarly/Index`; `InsertDailyLeave/Index`, `AddDailyLeave`, `AddLeavesAdded`; `PersonnelStatus/Index`; `Permit/Index`; `Card/Index`, `Card/Apply`; `ExitType/Index`; `LeaveType/MyView`; `LeaveDescription/Index`; `TransferRemainedLeave/Index` | کنترل جامع/وضعیت، Permit/ValidLeave، انواع خروج/مرخصی، شرح، پیش‌کارت و انتقال مانده در checkout قابلیت‌هایی دارند؛ ولی مدل یا گردش‌کارشان لزوماً مرجع نیست. `EditLateEarly` محلی `DailyRecord` را تغییر می‌دهد، مرجع `EditedValue` ثبت می‌کند. `InsertDailyLeave` محلی `Request` گروهی است، مرجع دو tab `DailyLeave` و `LeavesAdded` دارد. **همه نیمه‌پیاده‌سازی/ناهمسان تا آزمون مرجع.** ممیزی سه مورد InsertRecord/EditLateEarly/InsertDailyLeave به معنی قبولی نیست. |
| **T5-08 گزارش‌های خروجی** | `DailyReport`, `CardexReport`, `ExitReport`, `LateEarlyReport`, `OverTimeReport`, `PersonnelStatusReport`, `RemainedLeavesReport`, `RequestsReport`, `SumReport`, `UserReport`; `ReportDatas/Index`, `ShowReport/Index`, `GridReport/Index` | Daily/Sum محلی بخشی از ستون‌ها و خروجی را دارند؛ ممیزی View/CIL منتخب انجام شده است، اما ReportViewer، چاپ، schema و تمام محاسبات/ستون‌های مرجع کامل نشده‌اند. چند گزارش محلی هم‌نام/عمومی هست. **Daily/Sum نیمه‌پیاده‌سازی؛ سایر گزارش‌ها یا معادل کامل ندارند یا تأیید نشده‌اند.** |
| **T5-09 طراح گزارش** | `ReportDesign/Index`, `ReportFilter/Index`, `ReportTablix/Index`, `ReportTablixColumn/Index`, `ReportTextbox/Index`, `ReportTitles/Index`, `ReportTitles/Show` | گزارش‌ساز محلی وجود دارد، اما **طراح کامل و سلسله‌مراتب داده/فیلتر/tablix/column/textbox/title مرجع خط‌به‌خط بازسازی و تأیید نشده است.** |
| **T5-10 درخواست‌ها، گردش‌کار و ممیزی** | `ShowUsersRequest/Index`; `ViewSystemLogs/Index`; `CustomWF/Index`, `Workflow/Index` و اجزای Shared | system log و custom workflow محلی وجود دارند. صفحات درخواست در سامانهٔ محلی معادل خودکار `T5 ShowUsersRequest` نیستند و نباید با برنامهٔ جداگانهٔ TWF یا منوی آن یکی شوند. **ماندهٔ تطبیق role، داده، گردش و UI.** |

### موارد ممیزی‌شده تا چه عمقی؟

| بخش | View/منبع متنی | اسمبلی/CIL | بازنویسی اجرایی مرجع |
|---|---|---|---|
| `ImportFromExcel` | Index/Preview/Import و منابع مرتبط بررسی شده | Controller و `WorkbookDataProcessor` تحلیل شده | خیر؛ importer محلی عمداً سخت‌گیرتر است |
| `InsertRecord` | Index و `_SelectUser` بررسی شده | `Index` و `Insert` تحلیل شده | جزئی؛ فقط دو CheckType ۰/۱ در مدل محلی |
| `EditLateEarly` | View بررسی شده | `Index`/`Edit`/`UpdateValues` تحلیل شده | خیر؛ مدل محلی متفاوت است |
| `InsertDailyLeave` | Index و دو tab/JS بررسی شده | actionهای ثبت/فهرست/حذف بررسی شده | خیر؛ مدل و محاسبهٔ مانده متفاوت است |
| `USBManagement` | View بررسی شده | Controller/parser و `CopyToModelRecord` بررسی شده | خیر؛ قابلیت T5 هنوز پیاده نشده؛ UNIS bridge مستقل است |
| `DailyReport` و `SumReport` | View/ASPX/partialهای منتخب بررسی شده | schema و بخشی از `GetData` تحلیل شده | جزئی؛ ReportViewer و تمام ستون‌ها/فرمول‌ها موجود نیست |
| سایر بخش‌های T5 | فهرست کلی از artifactهای مرجع موجود است | بررسی کامل همهٔ actionها انجام نشده | خیر؛ باید به ترتیب بسته‌های کاری پایین انجام شود |

## برنامهٔ اجرا به بسته‌های قابل تحویل

1. **T5-00 — تثبیت traceability:** فهرست ۱۱۲ cshtml را به ماتریس `فایل/View → route/action → role → فیلد → رفتار → پیام → داده → تست` تبدیل می‌کنم؛ partialها و assetها جدا علامت می‌خورند. هیچ بخش با «وجود صفحه» Done نمی‌شود.
2. **T5-01 تا T5-04 — shell و دادهٔ پایه:** auth/roles، سازمان/پرسنل، تقویم/شیفت/قواعد و داشبورد/shared components؛ هر فرم جداگانه از مرجع به local map و تست می‌شود.
3. **T5-05 و T5-06 — دستگاه، رکورد و import:** مرزبندی T5-native با Link5 تثبیت؛ InsertRecord تکمیل؛ USBManagement از T5 خودش؛ Excel و Export. هیچ UNIS/Virdi behavior به T5 نسبت داده نمی‌شود.
4. **T5-07 — کنترل/اصلاح:** `TotalControl`, `EditLateEarly`, `InsertDailyLeave`, Permit, Card, PersonnelStatus و انواع leave؛ اول مدل و قواعد، بعد فرم و آزمون نتیجه. نگاشت حدسی به `Punch` یا `Request` ممنوع.
5. **T5-08 و T5-09 — گزارش و designer:** هر گزارش/فیلد/فیلتر/صفحه‌بندی/چاپ جدا؛ تا وقتی همهٔ schemaها و خروجی‌های مرجع تطبیق نشده‌اند کامل اعلام نمی‌شود.
6. **T5-10 و پذیرش — workflow/ممیزی و رگرسیون:** دسترسی نقش‌محور، audit، لغو/خطا/تکرار، دادهٔ بزرگ، RTL/Jalali، مقایسهٔ screenshot و سناریوی شبکه. اختلاف نسخه/runtime آشکار ثبت می‌شود.

## Definition of Done برای هر فرم

- تمام عناصر View مرجع (ترتیب، متن فارسی، فیلد، default، disabled/hidden، دکمه، tab، modal، خطا، بازگشت، AJAX و partial) به نمونهٔ محلی نگاشت شده باشند.
- هر action و branch قابل استخراج از CIL با تست سناریوهای موفق، ناموفق، دسترسی و دادهٔ مرزی پوشش داده شود؛ CIL به‌عنوان runtime قطعی معرفی نشود.
- roleها و scope داده جداگانه آزمون شوند؛ منوی قابل مشاهده جایگزین authorization سمت سرور نیست.
- دادهٔ خروجی و logها با مرجع/نمونهٔ تأییدشده مقایسه شوند؛ تراکنش/اتمیک‌بودن تا وقتی اثبات نشده ادعا نشود.
- اختلاف امنیتی (CSRF/XSS/open return URL و غیره) به روش امن حل شود؛ ناامنی مرجع عیناً کپی نمی‌شود و هر اختلاف ثبت می‌شود.
- پس از اجرای تست و بررسی UI در نسخهٔ فعال، status همان فرم از «نیمه‌پیاده‌سازی» به «قبولی خط‌به‌خط» تغییر کند.

**تأکید:** این جدول یک تقسیم‌بندی کامل برای اجرای کار است، نه ادعای تحویل کامل. در حال حاضر پاسخ دقیق به «کدام بخش‌ها دقیقاً بازنویسی شده‌اند؟» این است: **هیچ بخش کامل و تأییدشده‌ای؛ چند پیاده‌سازی جزئی و چند ممیزی استاتیک داریم که وضعیتشان در جدول مشخص شده است.**
