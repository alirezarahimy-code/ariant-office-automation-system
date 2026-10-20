# پیوست فاز صفر — واژه‌نامهٔ مدل‌ها و schemaهای قابل مشاهده

**تاریخ:** ۲۰۲۶-۱۰-۲۰  |  **استخراج اسمبلی:** REA  |  **استخراج DDL:** اسکریپت‌های UTF-16 SQL در IO

> تعریف «ویژگی» در اسمبلی با «ستون فیزیکی» یکسان نیست: propertyهای navigation و propertyهای نمایشی هم ممکن است دیده شوند. بخش T5/Personnel در نبود DDL کامل، فهرست مدل است، نه ادعای schema نهایی. بخش Link5/Personnel.sql ستون‌های DDL تحویلی را عیناً نشان می‌دهد؛ وضعیت مشتری ممکن است تفاوت داشته باشد.

## T5 مدل

**کلاس‌های دارای property استخراج‌شده:** 62. هر کلاس با propertyهای نام‌گذاری‌شده در اسمبلی:

- `T5V3.Model.ADayShift` — ActiveSchedule, AssignedShift, AssignedShiftWOTemp, Date, IsAuto, IsRamezan, IsTemp, Ramezan19LateAdvance
- `T5V3.Model.AllowedValue` — AllowedDate, AllowedType, BadgeNumber, Value
- `T5V3.Model.AppRuleModel` — App, Location, Photo, RuleID, ShowLocation, ShowPhoto, Web, WorkFlow
- `T5V3.Model.AreaModel` — AreaID, Center, ManagerID, Points, UserID, UserName, ValidType
- `T5V3.Model.AreaPointModel` — AreaID, Index, Latitude, Longitude
- `T5V3.Model.AutoSchedule` — AssignedSchedule, AssignedScheduleID, BadgeNumber
- `T5V3.Model.Calendar` — CalendarID, CalendarName, Holidays
- `T5V3.Model.CalendarHoliday` — CalendarID, Descriptions, HolidayDate
- `T5V3.Model.Card` — CardBadgeNumber, LeaveTypeID, LeaveTypeName, MachineName, MachineNumber, OnlyFirst, ValidSecs
- `T5V3.Model.Certificate` — CertificateID, CertificateName
- `T5V3.Model.CustomWF` — CustomWorkflowID, DescriptionsContains, DescriptionsStartWith, Label, LeaveDescriptionID, LeaveTypeID, MaxRequestLen, MinRequestLen
- `T5V3.Model.DailyLeave` — BadgeNumber, DailyLeaveFullLenDays, DailyLeaveToDate, DailyLeaveType, Descriptions, DetailDescriptionID, DetailDescriptions, DocumentNumber, InsertDate, IsFirstDayLeave, LeaveDate, LeaveDateHijri, LeaveTypeID
- `T5V3.Model.Department` — DepartmentID, Name, RuleID, UpperDeptID
- `T5V3.Model.DepartmentRule` — DepartmentID, DeptRuleID, FromDate, FromDateHijri, RuleID, RuleName, ToDate, ToDateHijri
- `T5V3.Model.Device` — BaudRate, Code, CommPassword, ConnectionType, IP, LastStamp, MachineName, MachineNumber, Port, Push, SerialNumber, SerialPort
- `T5V3.Model.DownloadRecordLog` — ComputerName, Descriptions, DownloadType, EventTime, MachineIP, MachineNumber, RecordsCount, Success, UserID
- `T5V3.Model.DownloadTime` — DownloadTime1, ID, MachineNumbers
- `T5V3.Model.LeaveDescription` — Description, ForLeaveType, ID
- `T5V3.Model.EditedValue` — BadgeNumber, ForDate, ValidDuration, ValueType
- `T5V3.Model.EmpType` — EmpTypeID, EmpTypeName, LeaveTypeIDs
- `T5V3.Model.GlobalVariables` — LockSerial, MaxDevicesCount, MaxPersonnelCount, ShowNameInReports, SupportExtended, SupportPW, UserName
- `T5V3.Model.HourlyLeave` — BadgeNumber, Descriptions, DetailDescriptions, FromTime, ID, LeaveTypeID, ToTime
- `T5V3.Model.LeavesAdded` — AddedDate, AddedDateHijri, BadgeNumber, Descriptions, DocumentNumber, ID, IsResell, LeaveTypeID, LeaveTypeName, ValueAdded
- `T5V3.Model.LeaveType` — AutoInHolidays, Color, DecreaseFromLeaveID, EachXHoursADay, ForNextYear, ForNextYearSum, GetInHolidays, GetInType, IsHourly, LeaveName, LeaveType1, LeaveTypeID, MaxDailyInMonth, MaxDailyInYear, MaxHoursInADay, MaxHoursInAMonth, MaxMinus, OnlyOne, ParameterID, Payment, StateKey, Symbol, ValidFreeOT
- `T5V3.Model.Log` — BadgeNumber, ComputerName, Descriptions, EventID, EventTime, EventType, EventTypeStr, HijriDate, OperationType, OperationTypeStr, TimeStr, UserName
- `T5V3.Model.Manager` — AllowDevices, AllowUsers, BadgeNumber, IsMainManager, Name, ProgramAccess
- `T5V3.Model.Notification` — Descriptions, ForBadgeNumber, ID, IsView, Title
- `T5V3.Model.Paramtr` — Formula, IsSum, ParameterID, ParameterName, SumFunction, UniqueSymbol
- `T5V3.Model.Personnel` — BadgeNumber
- `T5V3.Model.PersonnelTitle` — BadgeNumber, FromDate, FromDateHijri, ID, TitleID, TitleName, ToDate, ToDateHijri
- `T5V3.Model.Position` — PositionID, PositionName, Steps
- `T5V3.Model.PositionStep` — Descriptions, Location, OperationName, OrderIndex, Photo, PositionID, Required
- `T5V3.Model.Ramz` — FromDate, FromDateHijri, ID, Late192123, Late192123Value, ToDate, ToDateHijri, Year
- `T5V3.Model.Record` — BadgeNumber, CheckTime, CheckType, HijriDate, HijriInsertTime, InsertTime, InsertUser, IsValid, MachineNumber, Name, OriginalSCheckType, SourceType, TimeString, VerifyMode, WorkCode
- `T5V3.Model.ReportChart` — CategoryName, CategoryValue, ChartID, ChartTitle, ChartType, Height, Left, LocationType, ReportID, SeriName, SeriValue, Top, Width
- `T5V3.Model.ReportData` — ParameterID, ParameterName, ParameterType, ReportDataID, ReportID
- `T5V3.Model.ReportFilter` — FilterID, Operator, ParameterName, PassIfTrue, ReportDataID, Value
- `T5V3.Model.Reportmdl` — Footer, FooterHeight, Header, HeaderHeight, Height, IsSumReport, PageBreakColumnNumber, PageBreakWithBadgeNumber, ReportCharts, ReportDatas, ReportFilters, ReportID, ReportName, ReportTablixes, ReportTextboxes, RowColor, SortColumnID, SumRow, ToHTMLColor, Width
- `T5V3.Model.ReportTablix` — Columns, HeaderHeight, Height, Left, LocationType, ReportID, RowHeight, TablixID, Top, Width
- `T5V3.Model.ReportTablixColumn` — ColumnID, HeaderHeight, HeaderText, Height, TablixID, ValueText, Width
- `T5V3.Model.ReportTextbox` — BorderStyle, Height, Left, LocationType, ReportID, Text, TextboxID, Top, Width
- `T5V3.Model.Request` — AllowValue, BadgeNumber, CreateTime, Descriptions, ForDate, FromTime, ImageData, IsDaily, IsSuspend, Latitude, LeaveDescriptionID, LeaveID, Longitude, OverTimeAllowType, RecordTime, RequestID, RequesterName, ToTime, WorkflowType
- `T5V3.Model.RequestAcceptMdl` — AcceptDate, AccepterBadgeNumber, AccepterTitleID, IsCancel, IsDeputy, RequestID
- `T5V3.Model.Rule` — AppRules, ConstOTMaxDaily, ConstOTMaxMonthly, EarlyMore, EarlyMoreLeaveID, EarlyMoreTime, FreeOverTime, FreeOverTimeMax, LateMore, LateMoreLeaveID, LateMoreTime, LimitRecReqInDay, LimitRecReqInMonth, MaxBalanceDay, MinOverTimeInterval, OverTime, OverTimeAfter, OverTimeAfterMax, OverTimeBefore, OverTimeBeforeMax, OverTimeMax, ParamName, RuleID, StrConstOTMaxDaily, StrConstOTMaxMonthly, StrEarlyMoreTime, StrFreeOverTimeMax, StrLateMoreTime, StrMaxBalanceDay, StrMinOverTimeInterval, StrOverTimeAfterMax, StrOverTimeBeforeMax, StrOverTimeMax, StrWebLeaveTelorance, StrWoInLate, StrWoOutEarly, WebLeaveTelorance, WoIn, WoInLate, WoInMode, WoOut, WoOutEarly, WoOutMode
- `T5V3.Model.Schedule` — AfterI, BeforeI, CalendarID, Color, Early, EarlyToStr, EndFrom, EndFromToStr, EndTo, EndToToStr, FromTime, FromTimeStr, FromTimeToStr, HTMLColor, ID, IntervalB, IntervalBToStr, Late, LateToStr, Name, RamzFromTime, RamzFromTimeToStr, RamzToTime, RamzToTimeToStr, RestTime, RestTimes, RestTimesStr, RuleID, StartFrom, StartFromToStr, StartTo, StartToToStr, SuspendTime, SuspendTimeToStr, Symbol, ToTime, ToTimeStr, ToTimeToStr, WorkDays, WorkMins
- `T5V3.Model.Shift` — Period, ShiftDetails, ShiftID, ShiftName, Unit, WeekEnds
- `T5V3.Model.ShiftDetail` — Index, ScheduleID, ShiftID
- `T5V3.Model.Template` — BadgeNumber, FingerIndex, Flags, IsFace, Len, Template1
- `T5V3.Model.TempSchedule` — AssignDate, AssignedSchedule, BadgeNumber
- `T5V3.Model.TitleChart` — Address, Certs, DisplayName, Duties, EmailAddress, TitleID, TitleName, UpperTitleID
- `T5V3.Model.USBFileData` — Logs, NewBadgeNumbers, Users
- `T5V3.Model.USBFileRecords` — Logs, NewBadgeNumbers, Records
- `T5V3.Model.UserAccess` — AccessLevel, AccessType, BadgeNumber
- `T5V3.Model.UserInfo` — Address, BadgeNumber, BirthDay, CertificateID, DeptID, DeptName, DeviceCardNumber, DeviceEnable, DeviceGroupNumber, DeviceMachineNumber, DeviceName, DevicePassword, DevicePrivilage, Email, EmpTypeID, EmployeeNumber, FatherName, FiredDay, FirstName, HiredDay, InValidDevicesList, IsFemale, JanbazCode, LastName, NationalNumber, NotUploadDevicesList, Password, PersonnelTitles, Photo, ProgramUser, RuleID, RuleType, ShiftName, Tel, Templates, UserName, ValidFrom, ValidTo, WebUser
- `T5V3.Model.UserManager` — Access, ID, ManagerBadgeNumber, UserBadgeNumber
- `T5V3.Model.UserPosition` — BadgeNumber, CreateDateHijri, CreateTime, CreateTimeStr, ID, PositionID, PositionName, Steps, UserName
- `T5V3.Model.UserPositionStep` — BadgeNumber, CreateDate, CreateDateHijri, CreateTimeStr, Descriptions, Image, ImageData, Latitude, Longitude, PositionID, StepIndex, StepName
- `T5V3.Model.UserRule` — BadgeNumber, FromDate, FromDateHijri, RuleID, RuleName, RuleType, RuleTypeName, ToDate, ToDateHijri, UserName, UserRuleID
- `T5V3.Model.UserShift` — BadgeNumber, FromDate, FromDateHijri, ShiftID, ShiftName, ToDate, ToDateHijri
- `T5V3.Model.ValidLeave` — BadgeNumber, Descriptions, FromDate, FromDateHijri, ID, Name, ToDate, ToDateHijri, ValidHours, ValidStr
- `T5V3.Model.Workflow` — AccepterName, AccepterTitleID, LeaveTypeID, Operation, OrderIndex, TitleID
- `T5V3.Model.YearLeaveRemain` — BadgeNumber, LeaveTypeID, Value, Year

## T5 DB/EntityClasses

**کلاس‌های دارای property استخراج‌شده:** 55. هر کلاس با propertyهای نام‌گذاری‌شده در اسمبلی:

- `T5V3.DB.T5V3DBManager` — AllowValueDA, AreaDA, AutoScheduleDA, CalendarDA, CardDA, CustomWFDA, DailyLeaveDA, DepartmentRuleDA, DeputyDA, DownloadRecordsLogDA, DownloadTimeDA, EditedValueDA, EmpLeavesDA, HourlyLeaveDA, LeaveDescriptionDA, LeaveTypeDA, LeavesAddedDA, NotificationDA, ParameterDA, PersonnelDeputyDA, PositionDA, RamezanDA, RecordDA, ReportAccessDA, ReportChartDA, ReportDA, ReportDataDA, ReportFilterDA, ReportTablixDA, ReportTextboxDA, RequestAcceptDA, RequestDA, RuleDA, ScheduleDA, ShiftDA, ShiftDetailDA, SystemLogDA, TablixColumnDA, TempScheduleDA, UserInfoManagerDA, UserPermissionDA, UserPositionDataDA, UserRuleDA, UserShiftDA, ValidLeaveDA, WorkflowDA, YearRemainDA
- `T5V3.DB.EntityClasses.AllowValue` — AllowValue1, AllowValueType, BadgeNumber, ForDate
- `T5V3.DB.EntityClasses.AppRule` — App, Location, Photo, Rule, RuleID, ShowLocation, ShowPhoto, Web, WorkflowType
- `T5V3.DB.EntityClasses.Area` — AreaID, AreaPoints, ManagerID, UserID, ValidType
- `T5V3.DB.EntityClasses.AreaPoint` — Area, AreaID, Latitude, Longitude, PointIndex
- `T5V3.DB.EntityClasses.AutoSchedule` — BadgeNumber, Schedule, ScheduleID
- `T5V3.DB.EntityClasses.Calendar` — CalendarHolidays, CalendarID, CalendarName, Schedules
- `T5V3.DB.EntityClasses.CalendarHoliday` — Calendar, CalendarID, Descriptions, HolidayDate
- `T5V3.DB.EntityClasses.Card` — CardBadgeNumber, LeaveType, LeaveTypeID, MachineNumber, OnlyFirst, ValidSecs
- `T5V3.DB.EntityClasses.CustomWorkflow` — CustomWorkflowID, DescriptionsContains, DescriptionsStartWith, Label, LeaveDescriptionID, LeaveTypeID, MaxRequestLen, MinRequestLen
- `T5V3.DB.EntityClasses.DailyLeaf` — BadgeNumber, Date, DescriptionID, Descriptions, DocumentNumber, InsertDate, LeaveDescription, LeaveID, LeaveType
- `T5V3.DB.EntityClasses.DepartmentRule` — DepartmentID, DeptRuleID, FromDate, Rule, RuleID, ToDate
- `T5V3.DB.EntityClasses.Deputy` — DeputyTitleID, TitleID
- `T5V3.DB.EntityClasses.DownloadRecordsLog` — ComputerName, Descriptions, DeviceDownloadEventType, EventID, EventTime, MachineIP, MachineNumber, RecordsCount, Success, UserID
- `T5V3.DB.EntityClasses.DownloadTime` — DownloadTime1, DownloadTimeDevices, ID
- `T5V3.DB.EntityClasses.DownloadTimeDevice` — DownloadTime, DownloadTimeID, MachineNumber
- `T5V3.DB.EntityClasses.EditedValue` — BadgeNumber, EditValue, EditValueType, ForDate
- `T5V3.DB.EntityClasses.EmpTypeLeave` — EmpTypeID, LeaveType, LeaveTypeID
- `T5V3.DB.EntityClasses.HourlyLeave` — BadgeNumber, DescriptionID, Descriptions, FromTime, ID, LeaveDescription, LeaveID, LeaveType, ToTime
- `T5V3.DB.EntityClasses.LeaveDescription` — DailyLeaves, DutyOrLeave, HourlyLeaves, ID, LeaveDescriptions
- `T5V3.DB.EntityClasses.LeavesAdded` — AssignDate, BadgeNumber, DailyValue, Descriptions, DocumentNumber, ID, IsResell, LeaveType, LeaveTypeID
- `T5V3.DB.EntityClasses.LeaveType` — AutoInHolidays, Cards, Color, DailyLeaves, DecreaseFromLeaveID, DutyOrLeave, EachXHoursADay, EmpTypeLeaves, ForNextYear, ForNextYearSum, GetInHolidays, GetInType, Hourly, HourlyLeaves, LeaveID, LeaveName, LeavesAddeds, MaxDailyInMonth, MaxDailyInYear, MaxHoursInDay, MaxHoursInMonth, MaxMinus, OnlyOne, Parameter, ParameterID, Payment, StateKey, Symbol, ValidFreeOT, YearRemains
- `T5V3.DB.EntityClasses.Entities` — AllowValues, AppRules, AreaPoints, Areas, AutoSchedules, CalendarHolidays, Calendars, Cards, CustomWorkflows, DailyLeaves, DepartmentRules, Deputies, DownloadRecordsLogs, DownloadTimeDevices, DownloadTimes, EditedValues, EmpTypeLeaves, HourlyLeaves, LeaveDescriptions, LeaveTypes, LeavesAddeds, Parameters, PersonnelDeputies, PositionStepDatas, PositionSteps, Positions, Ramezans, Records, ReportAccesses, ReportCharts, ReportDatas, ReportFilters, ReportTablixColumns, ReportTablixes, ReportTextboxes, Reports, RequestAccepts, Requests, RestTimes, Rules, Schedules, ShiftDetails, Shifts, SystemLogs, TempSchedules, UserInfoManagers, UserPermissions, UserPositionDatas, UserRules, UserShifts, ValidLeaves, Workflows, YearRemains
- `T5V3.DB.EntityClasses.Parameter` — Formula, IsSum, LeaveTypes, ParameterID, ParameterName, SumFormula, UniqueSymbol
- `T5V3.DB.EntityClasses.PersonnelDeputy` — BadgeNumber, DeputyBadgeNumber
- `T5V3.DB.EntityClasses.Position` — PositionID, PositionName, PositionSteps, UserPositionDatas
- `T5V3.DB.EntityClasses.PositionStep` — Descriptions, Location, OperationIndex, OperationName, Photo, Position, PositionID, Required
- `T5V3.DB.EntityClasses.PositionStepData` — CreateDate, Descriptions, ImageData, Latitude, Longitude, StepIndex, UserPositionData, UserPositionID
- `T5V3.DB.EntityClasses.Ramezan` — FromDate, ID, Late192123, Late192123Value, ToDate, Year
- `T5V3.DB.EntityClasses.Record` — BadgeNumber, CheckTime, CheckType, InsertTime, InsertUser, IsValid, MachineNumber, OriginalSCheckType, SourceType, VerifyMethod, WorkCode
- `T5V3.DB.EntityClasses.Report` — Footer, FooterHeight, Header, HeaderHeight, Height, IsSumReport, PageBreakColumn, ReportAccesses, ReportCharts, ReportDatas, ReportID, ReportName, ReportTablixes, ReportTextboxes, RowColor, SortColumnID, SumRow, Width
- `T5V3.DB.EntityClasses.ReportAccess` — Report, ReportID, TitleID
- `T5V3.DB.EntityClasses.ReportChart` — CategoryName, CategoryValue, ChartID, ChartTitle, ChartType, Height, Left, LocationType, Report, ReportID, SeriName, SeriValue, Top, Width
- `T5V3.DB.EntityClasses.ReportData` — ParameterID, ParameterType, Report, ReportDataID, ReportFilters, ReportID
- `T5V3.DB.EntityClasses.ReportFilter` — FilterID, Operator, PassIfTrue, ReportData, ReportDataID, Value
- `T5V3.DB.EntityClasses.ReportTablix` — Height, Left, LocationType, Report, ReportID, ReportTablixColumns, TablixID, Top, Width
- `T5V3.DB.EntityClasses.ReportTablixColumn` — ColumnID, HeaderHeight, HeaderText, Height, ReportTablix, TablixID, ValueText, Width
- `T5V3.DB.EntityClasses.ReportTextbox` — BorderStyle, Height, Left, LocationType, Report, ReportID, Text, TextboxID, Top, Width
- `T5V3.DB.EntityClasses.Request` — BadgeNumber, CreateDate, Descriptions, ForDate, FromTime, ImageData, IsDaily, IsSuspend, Latitude, LeaveDescriptionID, LeaveID, Longitude, OverTimeAllowValue, OverTimeType, RecordTime, RequestAccepts, RequestID, ToTime, WofkflowType
- `T5V3.DB.EntityClasses.RequestAccept` — AcceptDate, AccepterBadgeNumber, AccepterTitleID, IsCancel, IsDeputy, Request, RequestID
- `T5V3.DB.EntityClasses.RestTime` — FromTime, RestIndex, SchID, Schedule, ToTime
- `T5V3.DB.EntityClasses.Rule` — AppRules, ConstOTMaxDaily, ConstOTMaxMonhtly, DepartmentRules, EarlyMore, EarlyMoreMins, EarlyMoreMode, FreeOverTime, FreeOverTimeMax, LateMore, LateMoreMins, LateMoreMode, MaxBalanceDay, MinOverTimeInterval, OverTime, OverTimeAfter, OverTimeAfterMax, OverTimeBefore, OverTimeBeforeMax, OverTimeMax, ParamName, RecReqDailyLimit, RecReqMonthlyLimit, RuleID, Schedules, UserRules, WebTelorance, woIn, woInLateMins, woInMode, woOut, woOutEarlyMins, woOutMode
- `T5V3.DB.EntityClasses.Schedule` — AfterI, AutoSchedules, BeforeI, Calendar, CalendarID, CheckIn, CheckInTime1, CheckInTime2, CheckOut, CheckOutTime1, CheckOutTime2, Color, EarlyMinutes, EndTime, FoodTimeFrom, FoodTimeTo, IntervalB, IntervalIn, LateMinutes, RamezanIn, RamezanOut, RestTime, RestTimes, Rule, RuleID, SchClassID, SchName, ShiftDetails, StartTime, Symbol, TempSchedules, WorkDay, WorkMins
- `T5V3.DB.EntityClasses.Shift` — Period, ShiftDetails, ShiftID, ShiftName, Unit, UserShifts, WeekEnds
- `T5V3.DB.EntityClasses.ShiftDetail` — DayIndex, Schedule, ScheduleID, Shift, ShiftID
- `T5V3.DB.EntityClasses.SystemLog` — ComputerName, Descriptions, EventID, EventTime, EventType, OperationType, UserID
- `T5V3.DB.EntityClasses.TempSchedule` — AssignDate, BadgeNumber, Schedule, ScheduleID
- `T5V3.DB.EntityClasses.UserInfoManager` — Access, ID, ManagerBadgeNumber, PersonnelBadgeNumber
- `T5V3.DB.EntityClasses.UserPermission` — AccessLevel, AccessType, BadgeNumber
- `T5V3.DB.EntityClasses.UserPositionData` — BadgeNumber, CreateTime, ID, Position, PositionID, PositionStepDatas
- `T5V3.DB.EntityClasses.UserRule` — BadgeNumber, FromDate, Rule, RuleID, RuleType, ToDate, UserRuleID
- `T5V3.DB.EntityClasses.UserShift` — BadgeNumber, EndDate, FromDate, Shift, ShiftID
- `T5V3.DB.EntityClasses.ValidLeave` — BadgeNumber, Descriptions, FromDate, ID, ToDate, ValidHours
- `T5V3.DB.EntityClasses.Workflow` — AccepterTitleID, LeaveTypeID, OrderIndex, TitleID, WorkflowType
- `T5V3.DB.EntityClasses.YearRemain` — BadgeNumber, DailyValue, LeaveType, LeaveTypeID, Year

## مدل‌های افزوده‌شده در بازسازی T5Web

- `attendance.PreCard` — نگاشت فیلدهای `T5V3.Model.Card`/`T5V3.DB.EntityClasses.Card`: `card_badge_number` ↔ CardBadgeNumber, `valid_secs` ↔ ValidSecs, `leave_type` ↔ LeaveTypeID, `device` ↔ MachineNumber, `only_first` ↔ OnlyFirst. `device` در نسخهٔ جدید FK به ردیف محلی Device است؛ برابری شناسهٔ محلی با شمارهٔ ماشین مرجع فرض نشده است. CRUD و ApplyInterval بازسازی شده‌اند: شمارهٔ کارتِ آغازگر در بازه پیدا می‌شود؛ رکوردهای همان دستگاه از یک ثانیه پس از آن تا `ValidSecs` انتخاب و `check_type` آنها به `LeaveType.state_key` تغییر می‌کند؛ `OnlyFirst` انتخاب را به اولین رکورد محدود می‌کند. `LeaveType.state_key` مقدار پیش‌فرض ۴ دارد، مطابق مقدار اولیهٔ فیلد StateKey در فرم مرجع.
- `device.RawRecord.check_type` و `attendance.Punch.check_type` نگه‌دارندهٔ CheckType مرجع‌اند؛ ApplyInterval هر دو لایه را به‌روزرسانی می‌کند تا اعمال پیش/پس از تبدیل رکورد خام حفظ شود. متدهای DAL در CIL مرتب‌سازی صریح ندارند؛ پیاده‌سازی جدید برای نتیجهٔ تکرارپذیر، ترتیب زمانی و سپس شناسهٔ محلی را انتخاب کرده است. این نگاشت آزمون‌شدهٔ الگوریتم CIL است، نه استخراج سورس C# یا تأیید کامل محاسبات نهایی T5؛ اتصال CheckType به محاسبات گزارش و پذیرش مقایسه‌ای با دادهٔ واقعی مرجع همچنان لازم است.

## Personnel DB

**کلاس‌های دارای property استخراج‌شده:** 116. هر کلاس با propertyهای نام‌گذاری‌شده در اسمبلی:

- `ExcelWriterPers.Cell` — Alignment, BackColor, Column, Document, Font, ForeColor, Format, Row, Value
- `ExcelWriterPers.CellInfo` — Alignment, BackColor, Column, Document, FXIndex, Font, ForeColor, Format, Row, Value
- `ExcelWriterPers.ColumnInfo` — Index, Width
- `ExcelWriterPers.ExcelColor` — Automatic, Black, Blue, Color, Cyan, DarkBlue, DarkGreen, DarkRed, Gray, Green, Index, Magenta, Olive, Purple, Red, Silver, Teal, White, WindowBackground, WindowText, Yellow
- `ExcelWriterPers.ExcelDocument` — CodePage, DefaultFont, FX, Fonts, Formats, Item, UserName
- `ExcelWriterPers.FontInfo` — Color, Font
- `ExcelWriterPers.FormatInfo` — BackColor, Font, FontIndex, ForeColor, Format, FormatIndex, HorizontalAlignment
- `ExcelDataReader2.ExcelDataReader` — WorkbookData
- `ExcelDataReader.Core.XlsHeader` — ByteOrder, ClassId, DifFirstSector, DifSectorCount, DllVersion, FAT, FatSectorCount, FileStream, IsSignatureValid, MiniFatFirstSector, MiniFatSectorCount, MiniSectorSize, MiniStreamCutoff, RootDirectoryEntryStart, SectorSize, Signature, TransactionSignature, Version
- `ExcelDataReader.Core.XlsFat` — Header, SectorsCount, SectorsForFat
- `ExcelDataReader.Core.XlsStream` — BaseOffset, BaseSector
- `ExcelDataReader.Core.XlsRootDirectory` — Entries, RootEntry
- `ExcelDataReader.Core.XlsDirectoryEntry` — Child, ChildSid, ClassId, CreationTime, EntryColor, EntryLength, EntryName, EntryType, LastWriteTime, LeftSibling, LeftSiblingSid, PropType, RightSibling, RightSiblingSid, StreamFirstSector, StreamSize, UserFlags
- `ExcelDataReader.Core.XlsBiffStream` — Position, Size
- `ExcelDataReader.Core.XlsBiffRecord` — Bytes, ID, Offset, RecordSize, Size
- `ExcelDataReader.Core.XlsBiffBOF` — CreationID, CreationYear, HistoryFlag, MinVersionToOpen, Type, Version
- `ExcelDataReader.Core.XlsBiffSimpleValueRecord` — Value
- `ExcelDataReader.Core.XlsBiffInterfaceHdr` — CodePage
- `ExcelDataReader.Core.XlsBiffDimensions` — FirstColumn, FirstRow, IsV8, LastColumn, LastRow
- `ExcelDataReader.Core.XlsBiffIndex` — DbCellAddresses, FirstExistingRow, IsV8, LastExistingRow
- `ExcelDataReader.Core.XlsBiffSST` — Count, UniqueCount
- `ExcelDataReader.Core.XlsFormattedUnicodeString` — ByteCount, CharacterCount, ExtendedStringSize, Flags, FormatCount, HasExtString, HasFormatting, HeadSize, IsMultiByte, Size, TailSize, Value
- `ExcelDataReader.Core.XlsBiffRow` — FirstDefinedColumn, Flags, LastDefinedColumn, RowHeight, RowIndex, XFormat
- `ExcelDataReader.Core.XlsBiffDbCell` — CellAddresses, RowAddress
- `ExcelDataReader.Core.XlsBiffBlankCell` — ColumnIndex, RowIndex, XFormat
- `ExcelDataReader.Core.XlsBiffIntegerCell` — Value
- `ExcelDataReader.Core.XlsBiffMulBlankCell` — LastColumnIndex
- `ExcelDataReader.Core.XlsBiffNumberCell` — Value
- `ExcelDataReader.Core.XlsBiffLabelCell` — Length, UseEncoding, Value
- `ExcelDataReader.Core.XlsBiffRKCell` — Value
- `ExcelDataReader.Core.XlsBiffMulRKCell` — LastColumnIndex
- `ExcelDataReader.Core.XlsBiffLabelSSTCell` — SSTIndex
- `ExcelDataReader.Core.XlsBiffBoolErrCell` — ErrorCode, IsError, Value
- `ExcelDataReader.Core.XlsBiffFormulaString` — Length, UseEncoding, Value
- `ExcelDataReader.Core.XlsBiffFormulaCell` — Flags, Formula, FormulaLength, UseEncoding, Value
- `ExcelDataReader.Core.XlsBiffWindow1` — ActiveTab, FirstVisibleTab, Flags, Height, Left, SelectedTabCount, TabRatio, Top, Width
- `ExcelDataReader.Core.XlsBiffBoundSheet` — IsV8, SheetName, StartOffset, Type, UseEncoding, VisibleState
- `ExcelDataReader.Core.XlsWorkbookGlobals` — Backup, CodePage, Country, DSF, ExtSST, ExtendedFormats, Fonts, Formats, InterfaceHdr, MMS, SST, Sheets, Styles, WriteAccess
- `ExcelDataReader.Core.XlsWorksheet` — CalcCount, CalcMode, Data, DataOffset, Delta, Dimensions, Index, Iteration, Name, RefMode, Window2
- `T5V3.ExcelDataReader.ExcelColumn` — ColumnIndex, DisplayName, FromRow, Index, Selected, Sheet, ToRow
- `T5V3.ExcelDataReader.ExcelPersonnel` — Address, BadgeNumber, DeviceName, Email, FirstName, Gender, Importable, LastName, Number, Status, StatusStr, Tel
- `Personnel.PersonnelSystem` — DB, SupportPW, ZKDevices
- `Personnel.PhotoFace` — BadgNumber, FacePhoto
- `Personnel.Properties.Resources` — Culture, ResourceManager
- `Personnel.Properties.Settings` — Default
- `Personnel.ZK.DataCollector` — Result
- `Personnel.ZK.PhotoFolder` — Folderpath
- `Personnel.ZK.ZKDevice` — ActivationCode, AllZK, CheckPing, Connected, Deivce, HardwareName, IP, IsZMMFirmware, MachineNumber, Name, Ping, Port, SerialNumber
- `Personnel.ZK.ZKDeviceBackupEventArgs` — EventType
- `Personnel.ZK.ZKDeviceDownloadEventArgs` — BadgeNumber, EventType, IsProgress, Progress
- `Personnel.ZK.ZKDeviceEventArgs` — Descriptions, EventType
- `Personnel.ZK.ZKDeviceTransactionEventargs` — BadgeNumber, CheckTime, CheckType, VerifyMethod
- `Personnel.ZK.ZKDeviceUploadEventArgs` — BadgeNumber, EventType, IsProgress, Progress
- `Personnel.USBFiles.USBLogResource` — BadgeNumber, CreateTemp10Success, CreateTemp9Success, CreateUserDatSuccess, Culture, ErrorBadge, ErrorInOperation, ErrorInReadingBlock, ErrorInReadingRecord, Index, LineNumber, NotExists, RecordsRead, ResourceManager
- `Personnel.UI.frmDevice` — SupportPW
- `Personnel.UI.ucSelectMultiDepts` — SelectedDevices
- `Personnel.UI.ucSelectDevice` — SelectedDevices
- `Personnel.UI.ucSelectOneUser` — SelectedUser, ShowPhoto, TextBoxWidth
- `Personnel.UI.ucSelectTitle` — SelectedTitleID, SelectedTitleName
- `Personnel.UI.ucSelectUser` — SelectOneOnly, SelectedBadgeNumbers
- `Personnel.UI.UIColorSettings` — ColorPage1, ColorPage2, ColorPage3, ColorPage4, ColorPage5, Default
- `Personnel.UI.UIResource` — Aban, AboutUs, Absent, Access, ActivationCode, AddDailyLeave, AddInterval, AddLeavesAdded, AddNew, AddTempSchedules, AddValidLeave, Address, AdminsCount, AdvancedOperations, Algorithm, All, AllRecords, Allow, AllowOverTime, ApplicationUser, Apply, AreYouSure, AttState, AttendaceSoftware, AttendanceControl, AttendanceData, Auto, AutoDownloadTag, AutoDownloadTimes, AutoInHolidays, AutoSchedules, AutomaticDownload, Azar, Backup, BackupScheduleSample, BadPhotos, BadgeNumber, BadgeNumberError, Bahman, BaseDate, BasicOperations, BaudRate, Biometrics, BirthDay, Boolean, BorderStyle, Calculate, Calendar, CalendarName, Cancel, CantEnableDevice, CantReadUserIDs, CantReadUserTemplates, CardMustRegister, CardNumber, CardexReport, CategoryAxis, CategoryAxisTitle, Certificate, CertificateName, Certificates, Certs, ChangeKey, ChangeState, ChartTitle, ChartType, Charts, CheckType, ClearAllTempSchedules, ClearTexts, Color, Column, ColumnHeaderText, Columns, CommKey, ComputerName, Connect, ConnectionType, ConstOverTime, Count, CreatePhotoFolder, CreateUserDataFile, Culture, Custom, DLST, Daily, DailyDuty, DailyLeave, DailyLeaves, DailyReport, DataBaseName, DataError, DataInUsed, DataType, Database, Date, Day, DayOfWeek, DecreaseFrom, DefectRecords, Delete, DeleteAdmins, DeleteAll, DeleteAllData, DeleteAllIntervals, DeleteAllRecords, DeleteInterval, DeleteRecords, DeleteSuccess, DeleteTemplate, DeleteUsers, Deny, Department, DepartmentName, Departments, DepartmentsChart, Descriptions, Details, Device, DeviceCountLimit, DeviceFinder, DeviceInformation, DeviceMessage, DeviceMessages, DeviceName, DeviceNotConnected, DevicePassword, DevicePrivilage, DeviceProperties, Devices, DevicesEdited, Dey, Disconnect, Disconnet, DisplayName, Down, Download, DownloadRecords, DownloadRecordsLog, DownloadTime, DownloadTimeRecords, DownloadUsers, DownloadUsersFromDevice, Dropdown, Duration, Duties, Duties2, Duty, EachXHoursADat, Early, EarlyMore, Edit, EditEarly, EditLate, EditLateEarly, EditOverTime, EditOverTimes, EditPersonnel, EditYearRemain, Email, EmailAddress, EmpDate, EmpType, EmpTypeName, EmpTypes, Employ, EmployAll, Enable, EnableAutoDownload, EndAccess, EndWork, EndWorkAll, EnterToSearch, Error, ErrorConnection, ErrorDelaySecs, ErrorDeletingFinger, ErrorDeletingUser, ErrorInIntervals, ErrorOperation, Errors, Esfand, EventType, ExecuteQuery, ExistsBadgeNumber, Exit, Export, ExportData, ExportRecords, FaceCapacity, FaceCount, FaceTemplate, FacesCount, Farvardin, FastUpload, FastUploadOverwriteFingers, FatherName, Female, FilePath, Filter, FilterDevice, FingerCapacity, FingerCount, FingerFlags, FingerIndex, FingerOnly, FingerTemplate, FingersCount, FiredDate, FirstDayOfMonth, FirstName, Flash, FolderAddress, Footer, FooterHeight, ForNextYear, Format, Formula, FormulaEditor, FreeOT, Friday, From, FromDate, FromRow, FromTime, Functions, Gate, GateName, Gates, Gender, GetInHolidays, GetInType, GetTime, Graph, GroupNumber, HaveAccess, Header, HeaderHeight, HeaderHeightSize, Height, Help, HelpContent, Holiday, Hourly, HourlyDuty, HourlyLeave, IP, Import, ImportExcel, Importable, In, InAndOutRecords, InDBUsers, InDeptPersonnel, InDuty, InFreeOT, InFrom, InLeave, InLesswork, InTo, IncludeSubDepts, InputStaredItems, InsertRecord, InsertTime, InsertUser, Invalid, InvalidName, InvalidateDevice, InvalidateRecords, IsExists, IsFace, IsSum, IsSumReport, Item, ItemUsed, J25_30, J35_40, J45_50, J55_60, J65_70, JMore70, Janbaz, KeyCode, KeyIndex, KeyName, KeyOperation, Khordad, LargeImage, LastName, LastStamp, Late, Late1921, Late1921Value, LateEarly, LateEarlyReport, LateMore, LaunchFrom, LaunchTime, LaunchTo, Leave, LeaveDescription, LeaveDescriptions, LeaveName, LeaveOrDuty, LeaveRemain, LeaveType, LeaveTypes, Leaves, LeavesAdded, LeavesReport, Left, Less25, LessWork, List, Location, LockServer, Log, Login, Logout, MAC, MachineName, MachineNumber, MachineNumberExits, MainManager, Male, ManageDatabase, ManageTemplates, ManageUSB, Manager, Manual, Manual2, ManualDownload, Max, MaxBalanceDaily, MaxDailyInMonth, MaxDailyInYear, MaxHourlyInDay, MaxHourlyInMonth, MaxMinus, Mehr, Min, Model, Monday, Month, MonthFirst, MonthLast, Mordad, NON, Name, NameError, NationalNo, Network, NewFormat, Next, NextYearSum, No, NoAccess, NoUploadDevices, NormalUser, Number, OK, OTAfter, OTBefore, Offline, Online, OnlyManualRecordsCanDelete, OnlyOne, Operation, Operation2, OperationLog, OperationLogStamp, OperationReport, OperationTime, OperationType, Options, Ordibehesht, OriginalCheckType, Out, OutDBUsers, OutDeptPersonnel, OutDuty, OutFreeOT, OutFrom, OutLeave, OutLesswork, OutOfDBBadgenumbers, OutTo, OverTime, OverTimeType, OverTimes, OverTimesReport, PageBody, Parameter, ParameterName, ParameterType, Parameters, Password, Period, Personnal, Personnel, PersonnelLimit, PersonnelManagerTag, PersonnelStatusReport, Photo, PhotoInDB, PhotoInFolder, PhotoStamp, PhotosReport, Ping, PleaseSelectShift, Port, Present, Preview, Previous, Program, ProgramUsers, Properties, PropertyName, Public, Push, RS485Number, Ramezan, RamzFrom, RamzTo, Read, ReadFromDevice, ReadOnly, ReadRecords, ReadUserDataFile, ReadUsersSuccess, ReadingTemplatesSuccess, RealLen, Record, RecordCapacity, RecordInsertFailed, RecordInsertSuccess, RecordSource, RecordsCount, RecordsFileAddress, RecordsReadedFromDevice, RecordsReport, Reg2, Reg3, RegisterOp, Relog, RemainTransferHint, RemoteEnroll, Repeat, RepeatTime, Report, ReportAccess, ReportData, ReportDesign, ReportDesigner, ReportName, ReportParametres, ReportTablixColumns, ReportTextboxes, ReportViewer, Reports, RequestDelaySecs, ResourceManager, Restart, RestoreBackup, Reverse, RowHeightSize, RowIndex, RowNumberExists, Rule, RuleName, RuleType, Rules, SMSType, SQLPassword, SQLServer, SampleFormula, Saturday, Save, SaveChanges, SaveUserFirst, SavedRecords, Schedule, ScheduleName, Schedules, SchedulesString, Search, SearchDepts, SearchFilter, SearchPersonnel, SearchUsers, Searchicon, Sector, Select, SelectColumns, SelectDepartment, SelectDepartment3Dot, SelectDevices, SelectFolder, SelectHolidays, SelectManager, SelectManagerName, SelectPersonnel, SelectPhoto, SelectShift, SelectTimeInterval, SelectTitle, Selected, Selected2, SelectedColumns, Seprator, SeriAxis, SeriAxisTitle, SerialNumber, SerialPort, ServerName, SetSchedule, SetTime, Sharivar, Sheet, Shift, ShiftName, Shifts, Show, ShowImportList, ShowPreview, ShowReport, ShowTempSchedules, ShowUsers, SleepTime, StartAccess, StartDeleting, StartReading, StartReadingTemplates, StartUpload, State, StateKey, String, Success, SuccessAdd, SuccessConnection, SuccessDelete, SuccessEdit, SuccessOperation, Successes, SumRow, Sunday, SuspendTime, Symbol, T5Parameters, Tablixes, Tel, TempSchedules, TempSchedules1, TemplateStamp, Test, TestParameter, Text, Thirsday, Threshold1_1, Threshold1_N, Time, TimeSyncWithSystem, Tir, Title, TitleChart, TitleName, Titles, TitlesChart, To, ToDate, ToRow, ToTime, Top, Total, TotalCalcsReport, TotalControl, TotalControlItems, Transfer, TransferLeaveRemain, TransferValue, Tuesday, USB, USBManagement, UniqueSymbol, Unit, Up, Upload, UploadDevices, UploadFaceError, UploadFingerError, UploadPhotoError, UploadUsers, UploadUsersError, UploadUsersSuccess, User, UserCapacity, UserID, UserInfo, UserInfoStamp, UserManagement, UserName, UserNameOrPasswordError, UserParameters, UserProperties, UserReports, UserShiftManagement, UserShifts, UserSuccessAdded, UserinfoManagers, UsersCount, Valid, ValidDate, ValidDateEmpty, ValidDateError, ValidDevices, ValidFrom, ValidLeaves, ValidLen, ValidTransfer, ValidateDevice, ValidateRecords, Value, Vendor, VerifyStyle, ViewAll, WOPayment, WebUser, Wednesday, Week, WeekEnds, Width, WindowsNT, WorkCode, WorkDays, WorkMins, Workflow, WorkflowAlarm, WorkflowHint, Year, YearFirst, YearLast, Yes, home, imgBtnAdd, imgBtnCancel, imgBtnDelete, imgBtnEndWork, imgBtnSave, imgBtnTransfer, woIn, woOut
- `Personnel.Model.AllDeviceData` — AlgorithmVersion, FunctionKeys, Gateway, IP, MifareSector, OneToN, OneToOne, Records, RepeatTime, Subnet, Users
- `Personnel.Model.Certificate` — CertificateID, CertificateName
- `Personnel.Model.DashboardItem` — GroupID, ID, ItemID
- `Personnel.Model.DashboardModel` — BadgeNumber, GroupName, ID, OrderIndex
- `Personnel.Model.DBEventEventArgs` — Description, Operation, TableName
- `Personnel.Model.Department` — DepartmentID, Name, UpperDeptID
- `Personnel.Model.Device` — BaudRate, Code, CommPassword, ConnectionType, ErrorDelaySecs, GateID, GateName, IP, LastStamp, MachineName, MachineNumber, OperationLogStamp, PWType, PhotoStamp, Port, Push, RS485Number, RequestDelaySecs, SerialNumber, SerialPort, TemplateStamp, UserInfoStamp
- `Personnel.Model.DeviceAdvancedOptions` — OneToN, OneToOne, RegCard, RepeatTime, SleepTime
- `Personnel.Model.DeviceCapacity` — AdminCount, FaceCapacity, FaceCount, FingerCapacity, FingerCount, MAC, ProductCode, RecordsCapacity, RecordsCount, SerialNumber, UserCapacity, UserCount, Vendor
- `Personnel.Model.DeviceMessage` — Content, Duration, SMSID, SMSType, Users, ValidFrom
- `Personnel.Model.DeviceOperationLog` — BadgeNumber, MachineNumber, OperationTime, OperationType, Param1, Param2, Param3, Param4
- `Personnel.Model.EmpType` — EmpTypeID, EmpTypeName
- `Personnel.Model.FormEventEventArgs` — FormEventType, FormType
- `Personnel.Model.Gate` — GateID, GateName, UpperGateID
- `Personnel.Model.Record` — BadgeNumber, CheckTime, CheckType, InsertTime, InsertUser, IsValid, MachineNumber, Name, OriginalSCheckType, SourceType, TimeString, VerifyMode, WorkCode
- `Personnel.Model.USBFileData` — Logs, NewBadgeNumbers, Users
- `Personnel.Model.USBFileRecords` — Logs, NewBadgeNumbers, Records
- `Personnel.Model.USBRecordsEventArgs` — USBFileRecords
- `Personnel.Model.UserDeviceUpload` — BadgeNumber, IsUpload, MachineNumber
- `Personnel.Model.UserDeviceValid` — BadgeNumber, IsValid, MachineNumber
- `Personnel.Model.UserInfoProperty` — BadgeNumber, DropDownValue, PropertyID, StringValue
- `Personnel.Model.UserInfoTitle` — BadgeNumber, FromDate, FromDateHijri, ID, TitleID, TitleName, ToDate, ToDateHijri
- `Personnel.Model.Property` — DropdownValues, IsSearchFilter, PropertyID, PropertyName, PropertyType
- `Personnel.Model.PropertyDropdownValue` — DropdownValue, DropdownValueID, PropertyID
- `Personnel.Model.Template` — BadgeNumber, FingerIndex, Flags, IsFace, Len, Template1
- `Personnel.Model.TitleChart` — Address, Certs, DeputyTitleIDs, DisplayName, Duties, EmailAddress, Observer, TitleID, TitleName, UpperTitleID
- `Personnel.Model.UserInfo` — Address, BadgeAndName, BadgeNumber, Birthday, CertificateID, DeptID, DeptName, DeviceCardNumber, DeviceEnable, DeviceGroupNumber, DeviceMachineNumber, DeviceName, DevicePassword, DevicePrivilage, Email, EmpTypeID, EmpTypeName, EmployeeNumber, FatherName, FiredDay, FirstName, HijriBirthDay, HijriFiredDay, HijriHireDay, HiredDay, IsFemale, JanbazCode, LastName, NationalNumber, Password, Photo, ProgramUser, ShiftName, Tel, Templates, UserName, ValidFrom, ValidFromHasValue, ValidFromHijriDateOnly, ValidFromTimeOnly, ValidTo, ValidToHasValue, ValidToHijriDateOnly, ValidToTimeOnly, WebUser
- `Personnel.Model.FunctionKey` — Code, Index, Name, State
- `Personnel.DB.Certificate` — CertID, CertName, UserInfoes
- `Personnel.DB.Dashboard` — BadgeNumber, DashboardItems, GroupName, ID, OrderIndex, UserInfo
- `Personnel.DB.DashboardItem` — Dashboard, GroupID, ID, ItemID
- `Personnel.DB.Department` — DepartmentID, DepartmentName, TitlesCharts, UpperDeptID, UserInfoes
- `Personnel.DB.Device` — BaudRate, Code, CommPassword, ConnectionType, DevTpe, DeviceOperationLogs, ErrorDelaySecs, Gate, GateID, IP, LastStamp, MachineName, MachineNumber, OperationLogStamp, PhotoStamp, Port, Push, RS485Number, RequestDelaySecs, SerialNo, SerialPort, TemplateStamp, UserDeviceUploads, UserDeviceValids, UserInfoStamp, UserInfoes
- `Personnel.DB.DeviceMessage` — Content, DurationMins, SMSID, SMSType, UserInfoes, ValidFromTime
- `Personnel.DB.DeviceOperationLog` — BadgeNumber, Device, MachineNumber, OperationTime, OperationType, Param1, Param2, Param3, Param4
- `Personnel.DB.EmpType` — EmpTypeID, EmpTypeName, UserInfoes
- `Personnel.DB.FontSize` — BadgeNumber, FontSize1, UserInfo
- `Personnel.DB.Gate` — Devices, GateID, GateName, UpperGateID
- `Personnel.DB.MyTimeToolbarItem` — BadgeNumber, ItemID
- `Personnel.DB.Entities` — Certificates, DashboardItems, Dashboards, Departments, DeviceMessages, DeviceOperationLogs, Devices, EmpTypes, FontSizes, Gates, MyTimeToolbarItems, Properties, PropertyDropdownValues, Templates, TitlesCharts, UserDeviceUploads, UserDeviceValids, UserInfoProperties, UserInfoTitles, UserInfoes
- `Personnel.DB.Property` — IsSearchFilter, PropertyDropdownValues, PropertyID, PropertyName, PropertyType, UserInfoProperties
- `Personnel.DB.PropertyDropdownValue` — DropdownValue, DropdownValueID, Property, PropertyID, UserInfoProperties
- `Personnel.DB.Template` — BadgeNumber, FingerIndex, Flags, IsFace, Len, Template1, UserInfo
- `Personnel.DB.TitlesChart` — Address, Certs, DisplayName, Duties, EmailAddress, GuardAccess, Observer, TitleID, TitleName, UpperTitleID, UserInfoTitles
- `Personnel.DB.UserDeviceUpload` — BadgeNumber, Device, IsUpload, MachineNumber, UserInfo
- `Personnel.DB.UserDeviceValid` — BadgeNumber, Device, IsValid, MachineNumber, UserInfo
- `Personnel.DB.UserInfo` — Address, BadgeNumber, Birthday, Certificate, CertificateID, Dashboards, Department, DeptID, Descriptions, DeviceCardNo, DeviceEnable, DeviceMessages, DeviceName, DevicePass, DevicePriv, Devices, DviceGroup, Email, EmpType, EmpTypeID, EmployeeNumber, FatherName, FiredDate, FirstName, FontSize, Gender, HiredDate, Janbaz, LastName, NationalNo, Password, Phone, Photo, ProgramUser, Templates, UserDeviceUploads, UserDeviceValids, UserInfoProperties, UserInfoTitles, UserName, ValidFrom, ValidTo, WebUser
- `Personnel.DB.DBManager` — Certificates, ConnectionString, DashboardDA, DashboardItemDA, Departments, DeviceMessageDA, DeviceOperationLogDA, Devices, EmpTypes, FontSizeDA, Gates, Properties, PropertyDropdownValues, Templates, TitlesCharts, ToolbarItems, UserDeviceUploads, UserDeviceValids, UserInfoProperties, UserInfoTitles, UserInfos
- `Personnel.DB.UserInfoProperty` — BadgeNumber, DropDownValue, Property, PropertyDropdownValue, PropertyID, StringValue, UserInfo
- `Personnel.DB.UserInfoTitle` — BadgeNumber, FromDate, ID, TitleChartID, TitlesChart, ToDate, UserInfo
- `zkemkeeper.IZKEM` — AccGroup, BASE64, MachineNumber
- `ItemForEdit` — itemForEditType
- `FilterItem` — FilterType, ItemID, ViewString
- `ComboFilter` — ID, Name

## Link5 مدل

**کلاس‌های دارای property استخراج‌شده:** 27. هر کلاس با propertyهای نام‌گذاری‌شده در اسمبلی:

- `Link5.Model.AccessCombo` — ComboID, Descriptions, FullName, Group1, Group2, Group3, Group4, Group5
- `Link5.Model.AccessControl` — Badgenumber, GroupNumber, IsPersonnal, MachineNumber, TimeTable1, TimeTable2, TimeTable3, VerifyStyle, sBadgeNumber
- `Link5.Model.AccessGroup` — FullName, GroupID, Name, TimeTable1, TimeTable2, TimeTable3, UseHolidays, VerifyStyle
- `Link5.Model.BackupSchedule` — Name, Schedule, ScheduleDevices
- `Link5.Model.Card` — CardBadgeNumber, OnlyFirst, StateKey, ValidSecs
- `Link5.Model.Command` — ID, InsertTime, MachineNumber, OperationType, Results
- `Link5.Model.DeviceAlarm` — AlarmID, Color, CreateTime, CreateTimeStr, HijriDate, HtmlColor, IsView, MachineName, MachineNumber, Message, ViewUserID
- `Link5.Model.IntervalSchedule` — DayOfMonth, DayOfWeeks, DeleteOrTimeSync, FromTime, ID, MachineNumber, ScheduleDateType, ToTime
- `Link5.Model.DeviceSettings` — AutoConnectInStartUp, DeleteAfterReadAll, DeleteBeforeTime, DeleteBeforeTimeHours, DeleteMoreThan, DeleteMoreThanCount, DeleteSchedule, DownloadPhotos, EnableOffline, EnableOnline, IsGlobalSettings, MachineNumber, OnlyNewRecords, TimeSyncSchedule
- `Link5.Model.EventLog` — Count, EventTime, EventType, FromDate, ID, IP, MachineName, MachineNumber, Success, ToDate
- `Link5.Model.KeyStateConvert` — MachineNumber, NewKeyCode, OldKeyCode
- `Link5.Model.ListDevice` — Connected, GateName, HardwareName, IP, LastDeleteBeforeTime, LastDeleteWasSuccess, LastOnlineComputerTime, LastOnlineRecordTime, LastReadRecordsCount, LastReadedRecordTime, LastSuccessDeleteTime, LastSuccessPush, LastSuccessReadFromTime, LastSuccessReadTime, LastSuccessReadToTime, LastTryDeleteTime, LastTryTime, LastTryWasSuccess, MachineNumber, Name, Ping, Push, PushLastRecordTime, PushLastStamp, PushLastTransactionTime, RecordsCount, SerialNumber, ZK
- `Link5.Model.Map` — ImageData, MapID, Name, UserID
- `Link5.Model.MapDoor` — LeftPos, MachineName, MachineNumber, MapID, TopPos
- `Link5.Model.PushCommand` — CommandDescriptions, CommandID, CommandStr, CommandType, CreateTime, ID, MachineNumber, ResponseTime, Status
- `Link5.Model.RecordLog` — BadgeNumber, CheckTime, CheckType, InsertTime, LastError, MachineName, MachineNumber, PersonnelName, ReadType, RecordSource, RetryTime, Transfered, VerifyMethod
- `Link5.Model.Schedule` — ScheduleID, ScheduleTime, ScheduleTimeOfDay, ScheduleTimeType
- `Link5.Model.ScheduleDevice` — MachineNumber, ScheduleID, ScheduleType
- `Link5.Model.SelectedColumn` — DeviceOutputType, FormatString, ID, TableColumnName
- `Link5.Model.SortableBindingList`1` — IsSortedCore, SortDirectionCore, SortPropertyCore, SupportsSortingCore
- `Link5.Model.TimeTable` — FriFrom, FriTo, FullName, MonFrom, MonTo, Name, SatFrom, SatTo, SunFrom, SunTo, ThFrom, ThTo, TimeStrs, TimeTableID, TuFrom, TuTo, WedFrom, WedTo
- `Link5.Model.User` — IsMainManager, LoginName, Password, UserID
- `Link5.Model.UserAccess` — Access, AccessType, UserID
- `Link5.Model.UserAlarm` — Alarm, AlarmID, AlarmType, AlarmTypeClass, Dismiss, EventTime, EventType, RefLink, UserID
- `Link5.Model.Visitor` — AccessLevel, BadgeNumber, CarryingGoods, Company, FirstName, FromTime, HostDepartment, HostPersonnel, ID, IsExit, LastName, LastRecordLocation, LastRecordTime, Phone, Photo, ToTime, VisitReason, VisitorsQuantity
- `Link5.Model.VisitorAccessLevel` — VisitorAccessLevelDescriptions, VisitorAccessLevelID
- `Link5.Model.VisitReason` — VisitReasonDescriptions, VisitReasonID

## Link5 DB/Entity

**کلاس‌های دارای property استخراج‌شده:** 32. هر کلاس با propertyهای نام‌گذاری‌شده در اسمبلی:

- `Link5.DB.AccessCombo` — ComboID, Descriptions, Group1, Group2, Group3, Group4, Group5
- `Link5.DB.AccessControl` — Badgenumber, GroupNumber, IsPersonnal, IsUploaded, MachineNumber, TimeTable1, TimeTable2, TimeTable3, VerifyStyle
- `Link5.DB.AccessGroup` — GroupID, Name, TimeTable1, TimeTable2, TimeTable3, UseHolidays, VerifyStyle
- `Link5.DB.BlackList` — BadgeNumber, UserID
- `Link5.DB.Card` — CardBadgeNumber, OnlyFirst, StateKey, ValidSecs
- `Link5.DB.Command` — ID, InsertTime, MachineNumber, OperationID, Results
- `Link5.DB.DeviceAlarm` — AlarmID, Color, CreateTime, MachineNumber, Message, ViewUserID
- `Link5.DB.DeviceSetting` — AutoConnectInStartUp, DeleteAfterReadAll, DeleteBeforeHours, DeleteBeforeHoursEnable, DeleteMoreCount, DeleteMoreEnable, DeleteSchedule, DownloadInterval, DownloadOnline, DownloadPhoto, DownloadPush, MachineNumber, OnlyNewRecords, TimeSyncSchedule, UseGeneralSettings
- `Link5.DB.Entities` — AccessComboes, AccessControls, AccessGroups, BlackLists, Cards, Commands, DeviceAlarms, DeviceSettings, DeviceStatus, EvenetsLogs, IntervalSchedules, KeyCodeConverts, MapDoors, Maps, Options, PushCommands, RecordsLogs, ScheduleDevices, Schedules, SelectedColumns, SystemLogs, TimeTables, UserAccesses, UserAlarms, UserDeptAccesses, UserDeviceAccesses, Users, VisitReasons, VisitorAccessLevelDoors, VisitorAccessLevels, Visitors
- `Link5.DB.IntervalSchedule` — DayOfMonth, DayOfWeeks, DeleteOrTimeSync, FromTime, ID, MachineNumber, ScheduleDateType, ToTime
- `Link5.DB.KeyCodeConvert` — KeyCode, MachineNumber, NewKeyCode
- `Link5.DB.DeviceStatu` — LastDeleteBeforeTime, LastDeleteWasSuccess, LastOnlineRead, LastOnlineRecordTime, LastReadRecordsCount, LastReadedRecordTime, LastSuccessDeleteTime, LastSuccessReadFromTime, LastSuccessReadTime, LastSuccessReadToTime, LastTryDeleteTime, LastTryTime, LastTryWasSuccess, MachineNumber, PushLastRecordTime, PushLastStamp, PushLastTransactionTime, RecordsCount
- `Link5.DB.EvenetsLog` — Count, EventTime, EventType, FromDate, ID, IP, MachineNumber, Success, ToDate
- `Link5.DB.Map` — ImageData, MapDoors, MapID, Name, User, UserID
- `Link5.DB.MapDoor` — LeftPos, MachineNumber, Map, MapID, TopPos
- `Link5.DB.Option` — SettingsID, Value
- `Link5.DB.PushCommand` — CommandDescriptions, CommandID, CommandStr, CommandType, CreateTime, ID, MachineNumber, ResponseTime, Status
- `Link5.DB.RecordsLog` — BadgeNumber, CheckTime, CheckType, InsertTime, LastError, MachineNumber, ReadType, RetryTime, Transfered, VerifyMethod
- `Link5.DB.Schedule` — ScheduleDevices, ScheduleID, ScheduleTime, ScheduleTimeOfDay, ScheduleTimeType
- `Link5.DB.ScheduleDevice` — MachineNumber, Schedule, ScheduleID, ScheduleType
- `Link5.DB.SelectedColumn` — DeviceDataType, FormatString, ID, TableColumnName
- `Link5.DB.SystemLog` — Descriptions, EventTime, EventType, ID
- `Link5.DB.TimeTable` — FriFrom, FriTo, MonFrom, MonTo, Name, SatFrom, SatTo, SunFrom, SunTo, ThFrom, ThTo, TimeTableID, TuFrom, TuTo, WedFrom, WedTo
- `Link5.DB.User` — IsMainManager, LoginName, Maps, Password, UserAccesses, UserAlarms, UserDeptAccesses, UserDeviceAccesses, UserID
- `Link5.DB.UserAccess` — Access, AccessType, User, UserID
- `Link5.DB.UserAlarm` — Alarm, AlarmID, AlarmType, Dismiss, EventTime, EventType, RefLink, User, UserID
- `Link5.DB.UserDeptAccess` — DeptID, User, UserID
- `Link5.DB.UserDeviceAccess` — Access, DeviceID, User, UserID
- `Link5.DB.Visitor` — AccessLevel, BadgeNumber, CarryingGoods, Company, FirstName, FromTime, HostDepartment, HostPersonnel, ID, IsExit, LastName, Phone, Photo, ToTime, VisitReason, VisitorsQuantity
- `Link5.DB.VisitorAccessLevel` — AccessLevelID, Descriptions, VisitorAccessLevelDoors
- `Link5.DB.VisitorAccessLevelDoor` — AccessLevelID, DoorID, VisitorAccessLevel
- `Link5.DB.VisitReason` — VisistReasonID, VisitReasonDescription

## TWF مدل

**کلاس‌های دارای property استخراج‌شده:** 45. هر کلاس با propertyهای نام‌گذاری‌شده در اسمبلی:

- `MyTime3.GlobalHelper` — FulLogoSize, LockIsOK, PersonnelCount, TimeApp
- `MyTime3.Properties.Resources` — Culture, ResourceManager, logo
- `MyTime3.Models.AppExtraDataForRequest` — ImageData, latitude, longitude
- `MyTime3.Models.ChangePasswordModel` — ConfirmPassword, NewPassword, OldPassword
- `MyTime3.Models.DailyExitRequest` — Descriptions, ForDateHijri, LeaveDescriptionID, LeaveID, RequestID, ToDateHijri
- `MyTime3.Models.DailyLeaveRequest` — Descriptions, ForDate, HijriDate, LeaveTypeID
- `MyTime3.Models.Deputy` — CurrentDeputy, IsAnyDeputy, ValidList
- `MyTime3.Models.GeneralRequest` — Descriptions, ForDateHijri, ForTime, FromDateHijri, FromTime, FromUser, ImageData, IsNew, Latitude, LeaveDescriptionsID, LeaveTypeID, Longitude, Message, OvertimeValue, Password, RequestID, RequestType, RequestTypeStr, ToDateHijri, ToTime, UserName
- `MyTime3.Models.HomeModel` — Deputy, Reports, Requests
- `MyTime3.Models.ADayReport` — AbsentRequestedDescriptions, DailyLeaveName, DayOfWeekName, Early, EarlyRequestedDescriptions, HaveEarly, HaveLate, HijriDate, InShiftExits, IsAbsent, IsAbsentRequested, IsDailyLeave, IsEarlyRequested, IsHoliday, IsLateRequested, IsOdd, IsWOInRequested, IsWOOutRequested, Late, LateRequestedDescriptions, MainInTime, MainOutTime, MainOutTimeDate, SchInTime, SchOutTime, WOShift, WoIn, WoOut
- `MyTime3.Models.InShiftExit` — FromTime, IsRequested, RequestedDescriptions, ToTime
- `MyTime3.Models.HourlyExitRequest` — Descriptions, ForDateHijri, FromTimeStr, LeaveDescriptionID, LeaveID, RequestID, ToTimeStr
- `MyTime3.Models.HourlyLeaveRequest` — Descriptions, ForDate, FromTime, HijriDate, LeaveTypeID, ToTime
- `MyTime3.Models.LateEarlyRequest` — Descriptions, ForDateStr, RequestID
- `MyTime3.Models.LatLng` — lat, lng
- `MyTime3.Models.LocationReportModel` — data
- `MyTime3.Models.LoginModel` — Password, UserName
- `MyTime3.Models.OTConstDaily` — BadgeNumber, DayOfWeek, ForDate, ValueDaily
- `MyTime3.Models.OverTimeForDay` — AfterValueLast, AfterValueReal, BadgeNumber, BeforeValueLast, BeforeValueReal, ForDate, Name
- `MyTime3.Models.OverTimeRequest` — Descriptions, ForDateHijri, OverTimeType, ValidValue
- `MyTime3.Models.PerosnnelStatusModel` — ForDate, RType, Rows
- `MyTime3.Models.PersonnelModel` — BadgeNumber, FirstName, LastName
- `MyTime3.Models.RecordRequest` — Descriptions, ForDateStr, RecordTimeStr, RequestID
- `MyTime3.Models.RemainLeave` — BadgeNumber, DailyTaken, HourlyTaken, HourlyTakenStr, LeaveTypeID, LeaveTypeName, MaxMinus, Name, Remain, Requested, ToDate, TotalTaken, YearRemain
- `MyTime3.Models.ReportModel` — ErrorMessage, FromDateHijri, IsSelf, Page, ReportID, SelectedBadgeNumbers, ToDateHijri
- `MyTime3.Models.Request` — AcceptedTitles, BadgeNumber, CanceledTitle, DeputyTitleName, Descriptions, ForDeputy, From, ImageData, IsCanceled, Latitude, Longitude, Name, RequestID, RequestT5, RequestTime, To, TypeName, WaitForTitle, WorkflowName
- `MyTime3.Models.RewardPenaltyRequest` — Descriptions, ForBadgeNumber, ForDateHijri, RewardPenaltyType, ValidValue
- `MyTime3.Models.SettingsModel` — AllowValidHourlyFree, AllowValidLate, ChangePassForce, ChangePassLimit, ChangePassMonthly, ChangePassRestriction, ExportReport, HoursOfDays, LastDayForRequest, MaxRequestAgeDays, OverAllMessage, RequestForUser, RequestRecord, RequestRecordsDailyLimit, RequestRecordsMonthlyLimit, ViewConstOT, ViewDailyDuty, ViewDailyLeave, ViewHourlyDuty, ViewHourlyLeave, ViewOverTimeRequest, ViewRewardRequest
- `MyTime3.Models.ShowRequestModel` — Requests, fromDate, toDate
- `MyTime3.Models.SumConstOT` — BadgeNumber, FirstDayValue, Name, SumValue
- `MyTime3.Models.TotalCalcsModel` — AbsentDays, BadgeNumber, DutyDaily, DutyHourly, Early, FirstName, FreeOverTime, FromDateHijri, FullName, LastName, Late, LeaveDaily, LeaveHourly, OverTimeConst, OverTimeManager, OverTimeReal, PresentDays, ShiftDays, ToDateHijri, TotalDays, TotalLess, TotalOverTime, WorkDays
- `MyTime3.Models.UserInfoModel` — AccessId, Address, BadgeNumber, Birthday, CertificateID, Creator, DeptID, Descriptions, DeviceCardNo, DeviceEnable, DeviceMachineNumber, DeviceName, DevicePass, DevicePriv, DeviceSerialNumber, DviceGroup, Email, EmpTypeID, EmployeeNumber, FatherName, FiredDate, FirstName, Free, Gender, HiredDate, Janbaz, LastName, NationalNo, PartsId, Password, Phone, Photo, PositionDateFrom, PositionDateTo, PositionDeviceId, PositionType, ProgramUser, TitleChartName, UpperTitleChartName, UserName, VaccineBarcode, ValidFrom, ValidTo, WebUser, select
- `MyTime3.Models.TitleChartModel` — DisplayName, TitleID, TitleName, UpperTitleID
- `MyTime3.Models.UserInfoTitlesModel` — BadgeNumber, FromDate, ID, TitleChartID, ToDate
- `MyTime3.Lock.CheckLockResult` — IsLogoFree, LockResult, PersonnelCount, TimeApp, UserName
- `MyTime3.Controllers.DescriptionClass` — BadgeNumber, Descriptions, FromTime
- `MyTime3.Controllers.ShowRequestController` — name
- `MyTime3.Controllers.HomeController` — AcceptRequestCount, AccseptRequestCount, AllRequestCount, BadgNumber, PendingRequestCount, RejectRequestCount, SuspendRequestCount, WaitingRequestCount
- `MyTime3.Content.ImagesResource` — Arm, Arm2, Culture, ResourceManager, baner5, baner93_11, baner93_12, baner93_3, logo, logo1
- `MyTime3.Content.Noti5` — Culture, Noti5Setup, ResourceManager
- `MyTime3.Content.PersianString` — Aban, About, Absent, AbsentHour, AbsentWORecord, Accept, AcceptAll, AcceptAllForUSer, AcceptRequest, AcceptedTitle, AccessDenied, AddArea, All, Azar, BadDateFormat, BadDescriptions, BadExitType, BadTimeFormat, BadgeNumber, Bahman, BefrmaDarkhasteBaghiye, Cancel, CancelAll, CancelAllForUser, CanceledTitle, CaptchaError, ChangePassword, ConfirmPassword, ConfirmPasswordError, ConstOT, Culture, CurrentDeputy, DailyDuty, DailyLeave, DailyLeaveExists, DailyLeaveLimit, DailyOTConstLimit, DailyTaken, Date, DayOfWeek, DefineUserRule, Delete, DeleteDeputy, DeleteRequest, Deputy, Descriptions, DescriptionsRequired, Dey, DownloadExportFile, DownloadNoti5, Duty, DutyType, Early, Edit, EditAll, EditConstOT, EditDailyDuty, EditDailyLeave, EditDetails, EditEarly, EditHourlyDuty, EditHourlyLeave, EditLate, EditOverTime, EditRecordRequest, EditRequest, EditValue, ErrorInInsertingData, ErrorOperation, Esfand, EvenRecords, ExitEvenRecords, Exited, Export, Farvardin, FileType, For, ForDate, FreeOverTime, Friday, From, FromDate, FromTime, FromUser, HaveToLocation, HaveToPhoto, Holiday, HomePage, Hour, HourlyDuty, HourlyLeave, HourlyLeavePerMonthLimit, HourlyTaken, In, InsertRecord, InsertRewardAndPenalty, InsertSuccess, InvalidUserNameOrPassword, Khordad, LastDayForRequest, LastValidDayOfMonth, Late, LateEarly, Leave, LeaveDescriptions, LeaveRequest, LeaveType, LocationReport, LocationRequired, LockError, LockRequests, Login, Logout, MaxHourlyInDayLimit, MaxRequestAgeDays, MaxRequestTime, Mehr, Minute, Monday, MonthlyOTConstLimit, MonthlyValue, Mordad, Mr, Mrs, Myself, Name, NewImage, NewPassword, NewRequest, NewValue, NoReportSelected, OK, OTAfterLast, OTAfterReal, OTBeforeLast, OTBeforeReal, OddRecords, OldPassword, Operations, Ordibehesht, Out, OverTimeAccess, OverTimeAfter, OverTimeBefore, OverTimeReport, Password, Penalty, Personnel, PersonnelCountLimit, PersonnelStatus, PersonnelStatusReport, PhotoRequired, PreMonth, PreYear, PresentOddRecord, Remain, RemainLeave, RemainLeaveError, Report, ReportFrom, ReportName, Reports, Request, RequestDailyDuty, RequestDailyForDateExists, RequestDailyLeave, RequestEarly, RequestExists, RequestFromUser, RequestHourlyDuty, RequestHourlyLeave, RequestLate, RequestOverTime, RequestRecord, RequestRecordsDailyLimit, RequestRecordsMonthlyLimit, RequestTime, Requested, RequestsLockInfo, RequestsStatus, ResourceManager, RetryCheck, ReturnToConstOT, ReturnToEditRequest, ReturnToHome, ReturnToPrePage, Reverse, Reward, RewardAndPenalty, RulesNotPermitApp, RulesNotPermitWeb, Saturday, SaveChanges, Search, Select, SelectAll, SelectDate, SelectDeputy, SelectPersonnel, SelectTime, SelectValidArea, Self, Set, Settings, Shahrivar, Show, ShowAllDays, ShowAttachmentPhoto, ShowLeaves, ShowOnMap, ShowReport, ShowRequestsFor, SimpleReport, SoftwareName, Status, SuccessOperation, Sum, SumOTConstForCurrentMonth, Sunday, Taken, TextInImage, Thirsday, Time, Tir, To, ToDate, ToTime, ToolbarItems, Tuesday, Type, Undo, User, UserName, ValidHourlyFree, Validate, Value, ViewDailyDuty, ViewDailyLeave, ViewHourlyDuty, ViewHourlyLeave, ViewInsertConstOT, ViewOverTimeRequest, ViewReport, ViewReports, ViewReward, ViewValidLateEarly, WOIn, WOOut, WORecord, WOShift, WaitForTitle, Wednesday, Welcome, WorkflowType, YouCurrentlyDoNotHaveDeputy, bode5, bode5Copy, bode5Copy1, bode5Descriptions1, bode5Descriptions2, bode5Fac, bode5Tel
- `TINYLib._DTiny` — Counter, DataPartition, Initialize, NetWorkINIT, ServerIP, ShowTinyInfo, SpecialID, TinyErrCode, UserPassWord
- `Row` — BadgeNumber, Name, Records, State
- `LV` — BadgeNumber, Descriptions, FromTo, Name, Type
- `PassCheckProps` — Badge, LastChangeDate

## ستون‌های DDL دقیق — `Link5.sql`

**تعداد جدول‌های CREATE TABLE استخراج‌شده:** 46. انواع فیزیکی ستون‌ها در اسکریپت حفظ می‌شوند:

- **`RecordsLog`** — BadgeNumber bigint NOT NULL; CheckTime datetime NOT NULL; MachineNumber int NOT NULL; CheckType int NOT NULL; InsertTime datetime NOT NULL; ReadType int NOT NULL; Transfered bit NULL; RetryTime int NULL; LastError nvarchar(250) NULL; VerifyMethod int NULL
- **`AccessCombo`** — ComboID int NOT NULL; Descriptions nvarchar(50) NOT NULL; Group1 int NULL; Group2 int NULL; Group3 int NULL; Group4 int NULL; Group5 int NULL
- **`AccessControl`** — Badgenumber bigint NOT NULL; MachineNumber int NOT NULL; GroupNumber int NOT NULL; IsPersonnal bit NOT NULL; TimeTable1 int NULL; TimeTable2 int NULL; TimeTable3 int NULL; VerifyStyle int NOT NULL; IsUploaded bit NOT NULL
- **`AccessGroup`** — GroupID int NOT NULL; Name nvarchar(50) NOT NULL; TimeTable1 int NULL; TimeTable2 int NULL; TimeTable3 int NULL; VerifyStyle int NOT NULL; UseHolidays bit NOT NULL
- **`BlackList`** — UserID int NOT NULL; BadgeNumber bigint NOT NULL
- **`Card`** — CardBadgeNumber bigint NOT NULL; ValidSecs int NOT NULL; StateKey int NOT NULL; OnlyFirst bit NOT NULL
- **`Command`** — ID int NOT NULL; MachineNumber int NOT NULL; OperationID int NOT NULL; InsertTime datetime NOT NULL; Results int NULL
- **`CommandLog`** — Id int IDENTITY(1,1) NOT NULL; CommandType nvarchar(50) NOT NULL; RequestedTime datetime NOT NULL; MachineSerialNumber nvarchar(50) NOT NULL; MachineNumber int NOT NULL; IsAnswered bit NOT NULL; AnswerTime datetime NULL; State nvarchar(50) NULL; CommandId nvarchar(50) NOT NULL; IP nvarchar(50) NULL; CommandSource nvarchar(50) NULL
- **`DeviceAlarm`** — AlarmID int NOT NULL; MachineNumber int NOT NULL; Color int NOT NULL; CreateTime datetime NOT NULL; Message nvarchar(max) NOT NULL; ViewUserID int NULL
- **`DeviceErrorLog`** — machineSN nvarchar(max) NULL; ErrCode nvarchar(max) NULL; ErrMsg nvarchar(max) NULL; DataOrigin nvarchar(max) NULL; CmdId int NULL; Additional nvarchar(max) NULL
- **`DeviceSetting`** — MachineNumber int NOT NULL; UseGeneralSettings bit NOT NULL; DeleteAfterReadAll bit NOT NULL; DeleteBeforeHoursEnable bit NOT NULL; DeleteBeforeHours int NOT NULL; DeleteMoreEnable bit NOT NULL; DeleteMoreCount int NOT NULL; OnlyNewRecords bit NOT NULL; AutoConnectInStartUp bit NOT NULL; DeleteSchedule bit NOT NULL; TimeSyncSchedule bit NOT NULL; DownloadPush bit NOT NULL; DownloadOnline bit NOT NULL; DownloadInterval bit NOT NULL; DownloadPhoto bit NULL
- **`DeviceStatus`** — MachineNumber int NOT NULL; LastTryTime datetime NULL; LastTryWasSuccess bit NULL; LastSuccessReadFromTime datetime NULL; LastSuccessReadToTime datetime NULL; LastSuccessReadTime datetime NULL; LastReadedRecordTime datetime NULL; LastReadRecordsCount int NULL; RecordsCount int NULL; LastTryDeleteTime datetime NULL; LastDeleteBeforeTime datetime NULL; LastDeleteWasSuccess bit NULL; LastSuccessDeleteTime datetime NULL; LastOnlineRead datetime NULL; LastOnlineRecordTime datetime NULL; PushLastStamp bigint NULL; PushLastRecordTime datetime NULL; PushLastTransactionTime datetime NULL; ReadRecordsCount int NULL; SaveRecordsCount int NULL; DuplicateRecordsCount int NULL
- **`ErrorLog`** — Id int IDENTITY(1,1) NOT NULL; Code nvarchar(50) NULL; Message nvarchar(max) NULL
- **`EvenetsLog`** — ID bigint NOT NULL; MachineNumber int NOT NULL; IP nvarchar(50) NOT NULL; EventType int NOT NULL; EventTime datetime NOT NULL; Success bit NOT NULL; Count int NOT NULL; FromDate datetime NULL; ToDate datetime NULL
- **`IntervalSchedule`** — ID int NOT NULL; MachineNumber int NOT NULL; DeleteOrTimeSync int NOT NULL; ScheduleDateType int NOT NULL; DayOfWeeks nvarchar(50) NOT NULL; DayOfMonth nvarchar(50) NOT NULL; FromTime datetime NOT NULL; ToTime datetime NOT NULL
- **`KeyCodeConvert`** — MachineNumber int NOT NULL; KeyCode int NOT NULL; NewKeyCode int NOT NULL
- **`Map`** — MapID int NOT NULL; UserID int NOT NULL; ImageData image NOT NULL; Name nvarchar(150) NULL
- **`MapDoor`** — MapID int NOT NULL; MachineNumber int NOT NULL; TopPos int NOT NULL; LeftPos int NOT NULL
- **`Option`** — SettingsID int NOT NULL; Value nvarchar(500) NOT NULL
- **`PushCommand`** — ID bigint NOT NULL; MachineNumber int NOT NULL; CommandID int NOT NULL; CommandType int NOT NULL; CommandDescriptions nvarchar(1000) NOT NULL; CommandStr nvarchar(50) NOT NULL; Status int NOT NULL; CreateTime datetime NOT NULL; ResponseTime datetime NULL
- **`PushCommands`** — Id int IDENTITY(1,1) NOT NULL; MachineSerialNumber nvarchar(50) NULL; CommandId int NULL; SendTime datetime NULL; ResponseTime datetime NULL; Status nvarchar(50) NULL; CommandType nvarchar(50) NULL; CommandDescriptions nvarchar(1000) NULL
- **`RecordsLogData`** — BadgeNumber bigint NOT NULL; CheckTime datetime NOT NULL; MachineNumber int NOT NULL; CheckType int NOT NULL; InsertTime datetime NOT NULL; ReadType int NOT NULL; Transfered bit NULL; RetryTime int NULL; LastError nvarchar(250) NULL; VerifyMethod int NULL; Read bit NULL
- **`RecordsLogInvalid`** — BadgeNumber bigint NOT NULL; CheckTime nvarchar(100) NOT NULL; MachineNumber int NOT NULL; CheckType int NOT NULL; InsertTime nvarchar(100) NOT NULL; ReadType int NOT NULL; Transfered bit NULL; RetryTime int NULL; LastError nvarchar(250) NULL; VerifyMethod int NULL
- **`RecordsLogTemp`** — BadgeNumber bigint NOT NULL; CheckTime datetime NOT NULL; MachineNumber int NOT NULL; CheckType int NOT NULL; InsertTime datetime NOT NULL; ReadType int NOT NULL; Transfered bit NULL; RetryTime int NULL; LastError nvarchar(250) NULL; VerifyMethod int NULL
- **`Schedule`** — ScheduleID int IDENTITY(1,1) NOT NULL; ScheduleTimeType int NOT NULL; ScheduleTime int NOT NULL; ScheduleTimeOfDay datetime NOT NULL
- **`ScheduleDevice`** — ScheduleID int NOT NULL; MachineNumber int NOT NULL; ScheduleType int NOT NULL
- **`SchedulerOptions`** — MachineNumber nvarchar(50) NOT NULL; ReadSchedule bit NOT NULL; ReadSchedulePeriod int NOT NULL; OnlineAttBroker bit NOT NULL; DeleteRecordsAfterRead bit NOT NULL; DeleteEarlierRecords bit NOT NULL; DeleteRecordsEarlierThan int NOT NULL; DeleteRecordsMore bit NOT NULL; DeleteRecordsMoreThan int NOT NULL; DeleteSchedule bit NOT NULL; DeleteSchedulePeriod nvarchar(50) NOT NULL; TimeSync bit NOT NULL; TimeSyncPeriod nvarchar(50) NOT NULL; RecordsGapTime int NOT NULL
- **`SchedulerSettings`** — MachineSerialNumber nvarchar(50) NOT NULL; ReadInTime bit NOT NULL; TimeOfRead int NOT NULL; ReadOnline bit NOT NULL; DeleteAfterRead bit NOT NULL; DeleteEarlier bit NOT NULL; DeleteEarlierThan int NOT NULL; DeleteMore bit NOT NULL; DeleteMoreThan int NOT NULL; DeleteSch bit NOT NULL; DeleteSchModel nvarchar(50) NOT NULL; TimeSync bit NOT NULL; TimeSyncModel nvarchar(50) NOT NULL; OnlyNewRecords bit NOT NULL; RecordsGapTime int NOT NULL
- **`Schedules`** — Name nvarchar(50) NOT NULL; Span datetime NULL; Id int IDENTITY(1,1) NOT NULL
- **`SelectedColumn`** — ID int NOT NULL; DeviceDataType int NOT NULL; TableColumnName nvarchar(50) NOT NULL; FormatString nvarchar(50) NOT NULL
- **`SystemLog`** — ID bigint IDENTITY(1,1) NOT NULL; EventTime datetime NOT NULL; EventType int NOT NULL; Descriptions nvarchar(50) NOT NULL
- **`test`** — BadgeNumber varchar(50) NOT NULL; CheckTime datetime NOT NULL; CheckType varchar(50) NULL; SensorID varchar(50) NULL
- **`TimeTable`** — TimeTableID int NOT NULL; Name nvarchar(50) NOT NULL; SatFrom datetime NOT NULL; SatTo datetime NOT NULL; SunFrom datetime NOT NULL; SunTo datetime NOT NULL; MonFrom datetime NOT NULL; MonTo datetime NOT NULL; TuFrom datetime NOT NULL; TuTo datetime NOT NULL; WedFrom datetime NOT NULL; WedTo datetime NOT NULL; ThFrom datetime NOT NULL; ThTo datetime NOT NULL; FriFrom datetime NOT NULL; FriTo datetime NOT NULL
- **`User`** — UserID int NOT NULL; LoginName nvarchar(250) NOT NULL; Password nvarchar(250) NOT NULL; IsMainManager bit NOT NULL
- **`UserAccess`** — UserID int NOT NULL; AccessType int NOT NULL; Access int NOT NULL
- **`UserAlarm`** — AlarmID int NOT NULL; UserID int NOT NULL; Alarm nvarchar(250) NOT NULL; EventTime datetime NOT NULL; RefLink nvarchar(250) NOT NULL; Dismiss bit NOT NULL; EventType int NOT NULL; AlarmType int NOT NULL
- **`UserDeptAccess`** — UserID int NOT NULL; DeptID int NOT NULL
- **`UserDeviceAccess`** — UserID int NOT NULL; DeviceID int NOT NULL; Access int NOT NULL
- **`webpages_Membership`** — UserId int NOT NULL; CreateDate datetime NULL; ConfirmationToken nvarchar(128) NULL; IsConfirmed bit NULL; LastPasswordFailureDate datetime NULL; PasswordFailuresSinceLastSuccess int NOT NULL; Password nvarchar(128) NOT NULL; PasswordChangedDate datetime NULL; PasswordSalt nvarchar(128) NOT NULL; PasswordVerificationToken nvarchar(128) NULL; PasswordVerificationTokenExpirationDate datetime NULL
- **`webpages_OAuthMembership`** — Provider nvarchar(30) NOT NULL; ProviderUserId nvarchar(100) NOT NULL; UserId int NOT NULL
- **`webpages_Roles`** — RoleId int IDENTITY(1,1) NOT NULL; RoleName nvarchar(256) NOT NULL
- **`webpages_UsersInRoles`** — UserId int NOT NULL; RoleId int NOT NULL
- **`WebService.Options`** — MinutesToShowQueue int NOT NULL
- **`WebService.Queue`** — Id bigint NOT NULL; SendTime datetime NOT NULL; RequestType int NOT NULL; DeviceSerialNumber nvarchar(50) NOT NULL; State int NOT NULL; Sender nvarchar(50) NOT NULL
- **`WebService.TermsOfApproval`** — Id int IDENTITY(1,1) NOT NULL; ApproachType int NOT NULL; MinimumOfValue nvarchar(max) NOT NULL; MaximumOfValue nvarchar(max) NOT NULL; ActionNo int NOT NULL; OpenDoor bit NOT NULL; SaveRecord bit NOT NULL; PromptMessage nvarchar(max) NOT NULL; CreateDate datetime NOT NULL; Active bit NOT NULL; Value nvarchar(max) NOT NULL
- **`WebUser`** — Id int IDENTITY(1,1) NOT NULL; Username nvarchar(56) NOT NULL

**Viewهای DDL:** `AllData`.

## ستون‌های DDL دقیق — `Personnel.sql`

**تعداد جدول‌های CREATE TABLE استخراج‌شده:** 30. انواع فیزیکی ستون‌ها در اسکریپت حفظ می‌شوند:

- **`VaccineLog`** — Id int IDENTITY(1,1) NOT NULL; FirstName nvarchar(50) NOT NULL; LastName nvarchar(50) NOT NULL; NationalId nvarchar(50) NOT NULL; VaccineId bigint NOT NULL; VaccineTitle nvarchar(50) NOT NULL; VaccineDate datetime NOT NULL; BatchNumber nvarchar(50) NOT NULL; Country nvarchar(50) NOT NULL
- **`UserInfoVaccinationDetails`** — Id int IDENTITY(1,1) NOT NULL; Pin nvarchar(100) NOT NULL; VaccinationAPICheckString nvarchar(100) NOT NULL; NationalId nvarchar(50) NOT NULL
- **`UserInfo`** — BadgeNumber bigint NOT NULL; DeptID int NOT NULL; EmpTypeID int NOT NULL; CertificateID int NOT NULL; Janbaz int NOT NULL; Gender bit NOT NULL; EmployeeNumber nvarchar(20) NOT NULL; FirstName nvarchar(50) NOT NULL; LastName nvarchar(50) NOT NULL; FatherName nvarchar(50) NOT NULL; NationalNo nvarchar(50) NOT NULL; Address nvarchar(250) NOT NULL; Phone nvarchar(20) NOT NULL; Email nvarchar(50) NOT NULL; Birthday datetime NOT NULL; HiredDate datetime NULL; FiredDate datetime NULL; ValidFrom datetime NULL; ValidTo datetime NULL; Photo image NULL; Descriptions nvarchar(250) NOT NULL; UserName nvarchar(100) NULL; Password nvarchar(250) NULL; ProgramUser bit NOT NULL; WebUser bit NOT NULL; DeviceName nvarchar(50) NOT NULL; DeviceEnable bit NOT NULL; DevicePass nvarchar(250) NOT NULL; DevicePriv int NOT NULL; DeviceCardNo nvarchar(50) NOT NULL; DviceGroup int NOT NULL; DeviceSerialNumber nvarchar(50) NULL; VaccineBarcode nvarchar(max) NULL; FirstLogin bit NULL
- **`TitlesChart`** — TitleID int IDENTITY(1,1) NOT NULL; TitleName nvarchar(50) NOT NULL; DisplayName nvarchar(50) NOT NULL; UpperTitleID int NOT NULL; EmailAddress nvarchar(250) NOT NULL; Address nvarchar(250) NOT NULL; Certs nvarchar(500) NOT NULL; Duties nvarchar(500) NOT NULL; Observer bit NULL
- **`UserInfoTitles`** — ID int NOT NULL; BadgeNumber bigint NOT NULL; TitleChartID int NOT NULL; FromDate datetime NOT NULL; ToDate datetime NULL
- **`Certificate`** — CertID int IDENTITY(1,1) NOT NULL; CertName nvarchar(50) NOT NULL
- **`Dashboard`** — ID int NOT NULL; BadgeNumber bigint NOT NULL; GroupName nvarchar(50) NOT NULL; OrderIndex int NOT NULL
- **`DashboardItem`** — ID int NOT NULL; GroupID int NOT NULL; ItemID int NULL
- **`Department`** — DepartmentID int IDENTITY(1,1) NOT NULL; DepartmentName nvarchar(250) NOT NULL; UpperDeptID int NOT NULL
- **`Device`** — MachineNumber int NOT NULL; GateID int NOT NULL; MachineName nvarchar(50) NOT NULL; ConnectionType int NOT NULL; IP nvarchar(50) NOT NULL; SerialPort int NOT NULL; Port int NOT NULL; BaudRate int NOT NULL; CommPassword int NOT NULL; SerialNo nvarchar(50) NOT NULL; Code nvarchar(50) NOT NULL; Push bit NOT NULL; LastStamp bigint NULL; PhotoStamp bigint NULL; UserInfoStamp bigint NULL; TemplateStamp bigint NULL; OperationLogStamp bigint NULL; RequestDelaySecs int NULL; ErrorDelaySecs int NULL; RS485Number int NULL; DevTpe int NULL; TCPPush bit NOT NULL; TimeZone nvarchar(50) NULL; ATTLOGStamp nvarchar(50) NULL; OPERLOGStamp nvarchar(50) NULL; ATTPHOTOStamp nvarchar(50) NULL; ErrorDelay nvarchar(50) NULL; Delay nvarchar(50) NULL; TransFlag nvarchar(max) NULL; Realtime nvarchar(50) NULL; TransInterval nvarchar(50) NULL; TransTimes nvarchar(60) NULL; Encrypt nvarchar(50) NULL; LastRequestTime datetime NULL; DevMac nvarchar(50) NULL; DevFPVersion nvarchar(50) NULL; DevFirmwareVersion nvarchar(50) NULL; UserCount int NULL; AttCount int NULL; FpCount int NULL; Timeout int NULL; SyncTime int NULL; VendorName nvarchar(50) NULL; IRTempDetectionFunOn nvarchar(50) NULL; MaskDetectionFunOn nvarchar(50) NULL; UserPicURLFunOn nvarchar(50) NULL; MultiBioDataSupport nvarchar(50) NULL; MultiBioPhotoSupport nvarchar(50) NULL; MultiBioVersion nvarchar(max) NULL; MultiBioCount nvarchar(max) NULL; MaxMultiBioDataCount nvarchar(max) NULL; MaxMultiBioPhotoCount nvarchar(max) NULL; IsFirst bit NULL; FaceCount int NULL; Ping bit NULL
- **`DeviceBioTypes`** — Id int IDENTITY(1,1) NOT NULL; MachineSerialNumber nvarchar(50) NULL; BioType nvarchar(50) NOT NULL; Version nvarchar(50) NOT NULL
- **`DeviceConnectionState`** — Id int IDENTITY(1,1) NOT NULL; DeviceSerialNumber nvarchar(50) NULL; HasPush bit NULL; Push bit NULL; ConnectDLL bit NULL; Ping bit NULL
- **`DeviceMessage`** — SMSID int NOT NULL; ValidFromTime datetime NOT NULL; DurationMins int NOT NULL; SMSType int NOT NULL; Content nvarchar(350) NOT NULL
- **`DeviceMessagePersonnel`** — SMSID int NOT NULL; BadgeNumber bigint NOT NULL
- **`DeviceOperationLog`** — MachineNumber int NOT NULL; BadgeNumber bigint NOT NULL; OperationType int NOT NULL; OperationTime datetime NOT NULL; DeviceSerialnumber nvarchar(50) NULL; Param1 int NOT NULL; Param2 int NOT NULL; Param3 int NOT NULL; Param4 int NOT NULL
- **`DeviceProps`** — Id int IDENTITY(1,1) NOT NULL; SerialNumber nvarchar(50) NOT NULL; IsFingerTemp bit NOT NULL; FingerTempVersion nvarchar(50) NULL; IsFaceTemp bit NOT NULL; FaceTempVersion nvarchar(50) NULL; IsFinger bit NOT NULL; FingerVersion nvarchar(50) NULL; IsFace bit NOT NULL; FaceVersion nvarchar(50) NULL; IsPalm bit NOT NULL; PalmVersion nvarchar(50) NULL; IsBioPhoto bit NOT NULL; BioPhotoVersion nvarchar(50) NULL
- **`EmpType`** — EmpTypeID int IDENTITY(1,1) NOT NULL; EmpTypeName nvarchar(50) NOT NULL
- **`FontSize`** — BadgeNumber bigint NOT NULL; FontSize float NOT NULL
- **`Gate`** — GateID int NOT NULL; GateName nvarchar(50) NOT NULL; UpperGateID int NOT NULL
- **`Ghadir1`** — Clock_BarCode varchar(8) NULL; Clock_BDate varchar(10) NULL; Clock_BTime smallint NULL; Clock_BRdrCode int NULL; Clock_BRecState smallint NULL; Clock_Date varchar(10) NULL; Clock_Time smallint NULL; Clock_RdrCode int NULL; Clock_Chg smallint NULL; Clock_RecState smallint NULL; Clock_User varchar(15) NULL
- **`ManagerDevice`** — ManagerID bigint NOT NULL; DeviceNumber int NOT NULL
- **`MyTimeToolbarItem`** — BadgeNumber bigint NOT NULL; ItemID int NOT NULL
- **`OperationLog`** — MachineNumber int NOT NULL; BadgeNumber bigint NOT NULL; OperationType nvarchar(max) NOT NULL; OperationTime datetime NOT NULL; DeviceSerialnumber nvarchar(50) NULL; Param1 nvarchar(50) NOT NULL; Param2 nvarchar(50) NOT NULL; Param3 nvarchar(50) NOT NULL; Param4 nvarchar(50) NOT NULL
- **`Property`** — PropertyID int NOT NULL; PropertyName nvarchar(50) NOT NULL; IsSearchFilter bit NOT NULL; PropertyType int NOT NULL
- **`PropertyDropdownValue`** — DropdownValueID int NOT NULL; PropertyID int NOT NULL; DropdownValue nvarchar(50) NOT NULL
- **`Template`** — BadgeNumber bigint NOT NULL; FingerIndex int NOT NULL; IsFace bit NOT NULL; Template image NOT NULL; Len int NOT NULL; Flags int NOT NULL; PhotoFace image NULL
- **`UserBioData`** — Id int IDENTITY(1,1) NOT NULL; Pin nvarchar(20) NOT NULL; Type nvarchar(50) NOT NULL; No nvarchar(50) NOT NULL; Index nvarchar(50) NOT NULL; Version nvarchar(50) NOT NULL; Content nvarchar(max) NOT NULL; BioType nvarchar(50) NULL
- **`UserDeviceUpload`** — BadgeNumber bigint NOT NULL; MachineNumber int NOT NULL; IsUpload bit NOT NULL
- **`UserDeviceValid`** — BadgeNumber bigint NOT NULL; MachineNumber int NOT NULL; IsValid bit NOT NULL
- **`UserInfoProperty`** — BadgeNumber bigint NOT NULL; PropertyID int NOT NULL; StringValue nvarchar(250) NULL; DropDownValue int NULL

**Viewهای DDL:** `PersonnelVaccineLog`, `UserChartView`.

## نتیجه و محدودیت

- Link5 دو DDL دارد و برای آن می‌توان نام جدول و ستون فیزیکی را دقیقاً از فایل‌ها استخراج کرد؛ این به‌تنهایی اثبات نمی‌کند نصب شبکه همین نسخهٔ DDL را دارد.
- T5Web/TWF از مدل‌ها و queryهای EF/Dapper استفاده می‌کنند، اما اسکریپت کامل DDLِ T5/Personnel در پوشه‌های آن‌ها در IO تحویل نشده است. بنابراین واژه‌نامهٔ propertyها باید با metadata/backup مجاز پایگاه واقعی سنجیده شود.
- این واژه‌نامه شواهد نام‌گذاری است؛ semantics، nullability، index/FK و قواعد محاسبه را باید با CIL، SQLهای embedded و رفتار runtime جداگانه بررسی کرد.
