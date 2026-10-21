# پیوست فنی فاز صفر — موجودی کنترلرها و فرم‌های اسمبلی‌کامپایل‌شده

**تاریخ:** ۲۰۲۶-۱۰-۲۰  |  **روش:** REA managed static analysis؛ خواندن metadata و CIL بدون اجرای اسمبلی‌ها.

> این پیوست، موجودی فنی قابل مشاهده در اسمبلی‌هاست؛ نام متد به‌تنهایی اثبات نمی‌کند یک endpoint عمومی، منوی فعال یا مجاز در نصب جاری باشد. فهرست منو/صفحه باید با Viewها و رفتار نسخهٔ فعال تطبیق شود.

## ۱) نسخه و موجودی اسمبلی‌های هسته

| اسمبلی | نسخه | چارچوب هدف | TypeDef | MethodDef | نتیجه metadata |
|---|---:|---|---:|---:|---|
| `T5V3Web.dll` | 3.5.7.0 | .NETFramework,Version=v4.7.2 | 402 | 3186 | complete |
| `T5V3.Model.dll` | 1.0.0.0 | .NETFramework,Version=v4.0 | 85 | 1141 | complete |
| `T5V3.DB.dll` | 1.0.0.0 | .NETFramework,Version=v4.0 | 336 | 1731 | complete |
| `T5V3.Attendance.dll` | 1.0.0.0 | .NETFramework,Version=v4.0 | 56 | 397 | complete |
| `T5V3.Report.dll` | 1.0.0.0 | .NETFramework,Version=v4.0 | 46 | 1522 | complete |
| `MyTime3.dll` | 4.27.0.0 | .NETFramework,Version=v4.7.2 | 268 | 1623 | complete |
| `TD5.dll` | 1.0.0.0 | .NETFramework,Version=v4.7.2 | 90 | 589 | complete |
| `Link5.exe` | 4.0.4.0 | .NETFramework,Version=v4.8 | 132 | 1499 | complete |
| `Link5.Model.dll` | 1.0.0.0 | .NETFramework,Version=v4.8 | 38 | 439 | complete |
| `Link5.DB.dll` | 1.0.0.0 | .NETFramework,Version=v4.8 | 174 | 893 | complete |

تعدادها شامل کلاس‌های خودکار کامپایلر و helperها هم می‌شوند؛ از آن‌ها تعداد فرم یا قابلیت نتیجه‌گیری نمی‌شود.

## ۲) T5Web — کنترلرها و عملیات قابل ردیابی

تعداد کلاس‌های کنترلر در اسمبلی وب: **67**. عملیات زیر نام متدهای قابل مشاهده در هر کنترلر است؛ متدهای accessors/getters/setters و سازنده حذف شده‌اند.

- **Account** — Login, Login, Logout, RedirectToLocal, checkPassLogin, FirstLogin, PassChanged
- **AssignRule** — Index, GetDeptRules, GetUserRules, Edit, EditPersonnelRule, Delete, DeleteUserRule
- **AssignShift** — Index, AutoSch, Add, Edit, Delete, AddShift, UserShiftEdit
- **Calendar** — Index, GetList, GetByID, Edit, Delete, DeleteAll, Holidays, AddHoliday, DeleteHoliday, DeleteAllHolidays, CreateGridModel, CreateGridModelHoliday
- **Card** — Index, Apply, ApplyInterval, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **CardexReport** — Index, Show, CXReport, GetSchema, GetData, footerText, LoadLeaveTypesToViewBag
- **Certificate** — Index, GetList, GetByID, EditCert, DeleteCert, DeleteAll, CreateGridModel
- **ChangeLock** — Index, Check, EnableLockDevices, EnableLockActiveDays, EnableLockUserCount, EnableLockManagersCount, EnableLock, DecryptTimeApp, DecryptManagers, DecryptDevsCount, DecryptActiveDays, Decrypt
- **CustomWF** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **DailyReport** — Index, Show, DReport, GetSchema, TimeSpanToString, GetData
- **Dashboard** — Index, Edit, Delete, DeleteAll, CreateGridModel
- **DashboardItem** — Index, Edit, Delete, DeleteAll, CreateGridModel
- **Department** — Index, Edit, Delete, DeleteAll, GetByID, GetTree, LoadTree, GetUserInfoByDeptId, GetTreeHTML, AddNode, AddToolbars
- **DeptsChart** — Index, Employ, Fire, GetDeptsTree, LoadTree
- **Deputy** — Index, Edit, GetTree, LoadTree
- **Device** — Index, Edit, Delete, DeleteAll, CreateGridModel, LoadGatesToViewBag, GetAllDevicesTree, LoadTreeDevices, GetTreeHTML, AddNode, AddToolbars
- **DeviceOperations** — Index, DeviceStates, DownloadUsers, UploadUsers, UploadUsers, ShowUsers, ModifyUsers, DeviceProperties, GetCapacity, Restart, DeleteAllData, DeleteAdmins, DeleteAllRecords, TimeSync, SyncTimeData, SetDLST, RemoveTimeRecords, GetAdvanced, Set1_1, Set1_N, SetRepeatTime, SetSleepTime, SetCardReg, GetAdvanced2, SetIP, SetAlgorithm, SetSector, RemoteEnroll, ChangeKey, ReadKey, Connect, Disconnect, GetRecords, GetTimeRecords, SaveRecords, CopyToModelRecord, GetServiceURL, CallDeviceServicePost, CallDeviceService
- **EditLateEarly** — Index, Edit, UpdateValues
- **EmpType** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **ExitReport** — Index, Show, EXReport, GetSchema, GetData, AddRowToDataset
- **ExitType** — Index, Edit, Delete, DeleteAll
- **Export** — Index, GetFile, SaveHolo
- **Gate** — Index, Edit, Delete, GetTree, LoadTree, GetTreeHTML, AddNode, AddNodeLink, AddToolbars
- **GridReport** — Index, Show
- **GroupAssignShift** — Index, AutoSch, Add, Edit, Delete, AddShiftGroup
- **Home** — Index, ChangeFont, MyBanner, About, ChangePass, ChangePass, GetMenu, GetSubMenu, GetToolbars, SetToolbar, SideMenu, ToolbarShow, GetLoggedInBadgeNumber, GetByID, EditPassword, IsAdmin, AdminUsersList
- **ImportFromExcel** — Index, Import, PreviewExcel, Preview, GetTextForHTML, GetFromHTMLText, AddRow
- **InsertDailyLeave** — Index, Reload, InsertDailyLeave, InsertLeaveAdded, GetDailyLeaves, GetLeavesAdded, Delete, DeleteAdded
- **InsertRecord** — Index, Insert
- **LateEarlyReport** — Index, Show, LateEarlyReport, GetSchema, AddValuesToDataset, GetData
- **LeaveDescription** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **LeaveType** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel, LoadLeaveTypesToViewBag, LoadUserParametersToViewBag
- **Manager** — Index, Edit, Delete, DeleteAll, CreateGridModel
- **OverTimeReport** — Index, Show, OTReport, GetSchema, GetData, AddRowToDataset
- **Parameters** — Index, Edit, Delete, DeleteAll, CreateGridModel
- **Permit** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **PersonnelStatus** — Index, Calc, ToExcel, CalculateData
- **PersonnelStatusReport** — Index, Show, PSReport, GetSchema, GetData, AddToDatset
- **Ramezan** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **Records** — Index, Show, query
- **RemainedLeavesReport** — Index, Calc, ToExcel
- **ReportDatas** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **ReportDesign** — Index, GetList, GetByID, Edit, Delete, DeleteAll, EditAccess, GetTitlesTree, LoadTree, CreateGridModel
- **ReportFilter** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **ReportTablixColumn** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **ReportTablix** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **ReportTextbox** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **ReportTitles** — Index, Show, GetTitleHistoryByTitleId, GetByID, GetTree, GetDepartmentsTree, EditAccess, LoadTree, LoadTree, GetFullTree, GetTreeHTML, AddNode, AddToolbars
- **RequestsReport** — Index, Show, ReqsReport, GetSchema, TimeSpanToString, GetData, WFName, TypeName, From, To
- **Rule** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel, LoadLeaveTypesToViewBag
- **Schedule** — Index, GetList, GetByID, Edit, Delete, DeleteAll, GetItemsList, CheckDateTime, LoadRules, LoadCalendars
- **Shift** — Index, Edit, Delete, DeleteAll, GetScheduleHTML, GetItemsList
- **ShowReport** — Index, Show, DReport, GetSchema, TimeSpanToString, GetData
- **ShowUsersRequest** — Index, Index, getStatus
- **SumReport** — Index, Show, SumReport, GetSchema, GetData
- **TempSchedules** — Index, Show, LoadTempSchs2, LoadTempSchs1, EditSch
- **Title** — Index, Edit, Delete, DeleteAll, GetByID, GetReportTree, GetTree, GetDepartmentsTree, EditAccess, LoadTree, LoadTree
- **TotalControl** — Index, Show, ChangeRecordValid, ChangeRecordState, DeleteRecord, GetStateName, GetRowColor, GetRecordTypeName
- **TransferRemainedLeave** — Index, Calc, Transfer
- **USBManagement** — Index, ReadRecords, CopyToModelRecord
- **UserAddProperty** — Index, GetList, GetByID, Edit, Delete, DeleteAll, CreateGridModel
- **UserInfo** — Index, GetPersonnel, GetPersonnelByID, SearchPersonnel, EndWork, Tranfer, Edit, Delete, GetDeptsTree, GetDeptsTreeFiltered, CheckAndAdd, LoadTree, GetUploadDevicesTree, GetValidDevicesTree, LoadTreeDevices, CreateGridModel, UserTitleForHTML
- **UserReport** — Index, GetReportByID
- **UserSelection** — GetUsers, GetUsersByShift, GetSubDepts, SearchUsers, FilterUsersByShift, FilterUsers, GetTree, LoadTree, CreateModel, GetUsers, FilterUsers
- **ViewSystemLogs** — Index
- **Workflow** — Index, Add, Delete, MoveUp, MoveDown, GetTree, LoadTree, LoadAccepterNames
- **iclock** — cdata, cdata, getrequest

## ۳) TWF — کنترلرها و عملیات قابل ردیابی

### MyTime3 — 30 کنترلر
- **AcceptRequest** — Index, Accept, AcceptRequest, CancelRequest, AcceptByID2, AcceptByID1, AcceptByID, Accept, AcceptAll, Cancel, CancelByID, AcceptAllRequests, CancelAllRequests, InsertData
- **ChangePass** — ChangePassCheck, CheckPassMonthly, CheckPassForce, PassChanged
- **ConstOT** — Index, Edit, Edit, EditAll, GetAllDailyConstOT, GetOwnedTitles, GetOverTimeSubUsers
- **DailyDuty** — Add, Add, Edit, Edit, EditRequestDaily, RequestDaily, AddRequest
- **DailyLeave** — Add, Add, Edit, Edit, EditRequestDaily, RequestDaily, AddRequest, GetRemainD
- **Deputy** — Index, GetState, Delete, Add
- **Early** — Add, Add, Edit, Edit, EditRequestEarly, RequestEarly
- **EditRequest** — Index, GetRequests, CancelRequest, EditByID, Delete, CheckRequestTime
- **Home** — CheckLock, Index, IsAdmin, MakeCount, RenderRazorViewToString, GetTableData, GetDailyDetailsReport, GetPositionsList, GetStatusList, GetValidLeaveTypes, CalculateDailyAbsence, CalculateDailyDetailsReport, GetSuspendRequests, GetRequestsFor, About, MyBanner, ForNDayReport, RequestLockValuee, SetRequestsLock, CanDownloadNoti5, GetNoti5, SetToolbar, GetToolbars, SideMenu, ToolbarShow
- **HourlyDuty** — Add, Add, Edit, Edit, EditRequestHourly, CheckRemainDays, RequestHourly, AddRequest
- **HourlyLeave** — Add, Add, Edit, Edit, EditRequestHourly, CheckDailyLeave, RequestDailyTest, AddRequestTest, RequestHourly, AddRequest
- **Late** — Add, Add, Edit, Edit, EditRequestLate, RequestLate
- **LeavesReport** — Index, isObs
- **LocationReport** — Index, GetImage, GetReport, Report, ShowPhoto, AddPersonnelToViewBag
- **Login** — Login, CheckLock, RetryCheck, TestUserPass, Login, ChangePassword, ChangePasswordForce, ChangePasswordForce, Change, ChangePassword, Logout
- **Notify** — Index, SetViewed
- **OverTime** — Add, Add, Edit, RequestOverTime
- **OverTimeReport** — Index, HasAnyUsers, Show, ChangeBefore, ChangeAfter, AcceptBefore, CancelBefore, AcceptAfter, CancelAfter
- **PersonnelStatus** — Index
- **Record** — Add, Add, Edit, Edit, EditRequestRecord, GetRecordRequestsCount, MonthStart, MonthEnd, RequestForRecord
- **RemainLeave** — GetRemain, RemainLeaveJ, RemainLeaveJj, RemainLeave, GetForUser, RenderRazorViewToString, Index, ForPersonnel, AddPersonnelToViewBag, GetLeaveSubUsers
- **Report** — Index, Report, GetPersonnel, TotalCalcsReport, DailyReport, AddPersonnelToViewBag, AddReportsToViewBag, GetSchema, GetData
- **Request** — New, FromUser, SendPosition, Edit, GeneralRequestWeb, GeneralRequest, AddLocationRequest, AddRequest, ShowPhoto, AddPersonnelToViewBag
- **Reward** — Add, Add, RequestReward
- **SelectArea** — Index, GetPersonnel, GetAreas, GetArea, DeleteArea, InsertNew, GetUserAreas
- **Settings** — Index, Change, LoadSettings, LoadValue
- **ShowRequest** — Index, GetRequests
- **ShowUsersRequest** — Index, AddPersonnelToViewBag, GetForUser, getStatus
- **Undo** — Index, Undo, DeleteData
- **ViewR** — Index
### TD5 — 2 کنترلر
- **Request** — GetRequest, Where, Id, BadgeNumber, WorkflowType, DateTime, ForDate, LeaveId, LeaveDescriptionId, FromTime, ToTime, RecordTime, OverTimeType, OverTimeAllowValue, IsSuspend, Descriptions, IsDaily, Tolist, First, SelectMany, Select, GetT5Model
- **UserInfo** — GetUserInfo, InsertUserInfo, UpdateUserInfo, DeleteUserInfo, Where, BadgeNumber, WorkflowType, EmpTypeId, CertificateId, Janbaz, Gender, EmployeeNumber, FirstName, LastName, FatherName, NationalNo, Address, Phone, Email, Birthday, HiredDate, FiredDate, ValidFrom, ValidTo, Photo, Descriptions, UserName, Password, ProgramUser, WebUser, DeviceName, DeviceEnable, DevicePass, DevicePriv, DeviceCardNo, DviceGroup, Tolist, First, SelectMany, Select, Execute, Any

## ۴) Link5 — فرم‌های WinForms و handlerهای قابل مشاهده

در اسمبلی اصلی Link5، **26 نوع UI/فرم کلیدی** شناسایی شد. handlerها و متدهای موجود در metadata:

- **PhotoFace** — CheckPushDevice, DoubleToString, StringToDouble, AddCommandHistory, UpdateCommandHistory, GetUserFromApi, getImageB64, ImgToBase64String, GetDateInFormat, GetAttMode, GetRecorsList, GetUserRecord, SendRecords, UpdateLastReadTimeToTime, IsUserLimited, UserAccessChk, IsMainManager, GetInControlUser, GetInControlDevs, GetInControlDepts, GetLastPushTry
- **frmAbout** — linkLabel1_LinkClicked, ok_Button_Click, frmAbout_Load, ToPersianEncode
- **frmAddSelectedColumn** — btnOK_Click, btnCancel_Click
- **frmBackupSchedule** — cmbScheduleType_SelectedIndexChanged, frmBackupSchedule_Load, LoadFormByItem, CreateItemByForm, LoadAllData, grdData_CurrentCellChanged, ucToolbarDataEntry1_ButtonAddClicked, ucToolbarDataEntry1_ButtonCancelClicked, ucToolbarDataEntry1_ButtonEditClicked, ucToolbarDataEntry1_ButtonRemoveClicked, ucToolbarDataEntry1_ButtonSaveClicked, grdData_CellFormatting
- **frmChangeLockType** — btnOK_Click
- **frmCode** — FrmCode_Load, Button2_Click, Button1_Click
- **frmDeviceKeyCodes** — DeviceKeyCodes_Load, chkDefaultKeys_CheckedChanged, lbDevices_SelectedIndexChanged, LoadKeys, btnSave_Click, GetGridData, btnCancel_Click, grdKeys_CellValueChanged
- **frmDeviceSettings** — btnOK_Click, btnCancel_Click, chkGeneralSettings_CheckedChanged, frmDeviceSettings_Load, btnDeleteSchedule_Click, btnTimeSyncSchedule_Click
- **frmEmailSettings** — frmEmailSettings_Load, btnOK_Click
- **frmGetTimeInterval** — btnCancel_Click, btnOK_Click, frmGetTimeInterval_Load, hjTo_DateTimeChanged
- **frmIntervalSchedule** — rdoDaily_CheckedChanged, rdoWeekly_CheckedChanged, rdoMonthly_CheckedChanged, btnOK_Click, btnCancel_Click
- **frmLogin** — btnOK_Click, btnCancel_Click, frmLogin_Load
- **frmMain** — OnPersonnelDBEvent, DownloadAllRecords, ConfigSaving, ConfigPushServer, InvisibleMenus, QueryAccess, IsAnyDeviceConnected, CHKCode, RefreshDevices, OnPushTransaction, OnTransaction, ConnectToDevice, Disconnect, RefreshGrdDevices, InserALog, frmMain_Load, frmMain_Activated, frmMain_FormClosing, grdDevices_CellFormatting, tmrRefreshList_Tick, grdDevices_CurrentCellChanged, tmrAutoDownload_tick, mnuCertificates_Click, mnuEmpTypes_Click, mnuTitles_Click, mnuDepartments_Click, mnuExtraPersonnelData_Click, mnuImportExcel_Click, mnuPersonnel_Click, mnuDeptsChart_Click, mnuZones_Click, mnuDevices_Click, mnuDeviceDownload_Click, mnuUpload_Click, mnuDownloadRecords_Click, mnuDownloadTimeRecords_Click, mnuDeviceProperties_Click, mnuDeviceLogs_Click, mnuBackupDevice_Click, mnuRestore_Click, mnuDeviceSettings_Click, mnuConnect_Click, mnuDisconnect_Click, mnuDeviceLog_Click, mnuRecordsLog_Click, mnuUSB_Click, mnuCards_Click, mnuPushCommands_Click, mnuKeyCodes_Click, OnUSBRecords, mnuOutput_Click, mnuOptions_Click, mnuServiceSettings_Click, mnuPushSettings_Click, mnuScheduleBackups_Click, mnuUserManagement_Click, mnuChangeLockType_Click, mnuAbout_Click, btnPersonnel_Click, btnConnect_Click, btnDisconnect_Click, btnDevices_Click, btnDownloadLogs_Click, btnLogOff_Click, btnExit_Click, grdDevices_ColumnWidthChanged, saveColumnWidths, loadColumnWidths, splitContainer1_SplitterMoved, checkBoxIntervalDownload_CheckedChanged, checkBoxOnlineDownload_CheckedChanged, SaveDeviceSettings, mnuEmailSettings_Click, mnuCheckMTU_Click, mnuExportList_Click, txtSearchDevice_TextChanged, toolStripMenuItemEdit_Click, toolStripMenuItemSettings_Click, grdDevices_CellDoubleClick, tsMain_ItemClicked, txtPushLastRecordTime_TextChanged, ToPersianEncode
- **frmOptions** — frmOptions_Load, btnOK_Click, btnCancel_Click, btnDeleteSchedule_Click, btnTimeSyncSchedule_Click, btnSelectPhotosPath_Click, rdoNoneTimeDownload_CheckedChanged, chkDeleteBefore_CheckedChanged, chkDeleteOver_CheckedChanged, chkDeleteSchedule_CheckedChanged, chkTimeSync_CheckedChanged, chkReadNewRecordsOnly_CheckedChanged, radioButtonAll_CheckedChanged
- **frmOutput** — frmOutput_Load, btnAddColumn_Click, btnRemoveColumn_Click, grdColumns_CellFormatting, btnOK_Click, btnCancel_Click, btnCreateTable_Click, btnUp_Click, btnDown_Click
- **frmPickUserAccess** — frmPickUserAccess_Load, btnOK_Click, btnCancel_Click, grdUserAccess_CellClick
- **frmPishCards** — LoadFormByItem, CreateItemByForm, LoadAllData, frmCard_Load, grdData_CurrentCellChanged, ucToolbarDataEntry1_ButtonAddClicked, ucToolbarDataEntry1_ButtonCancelClicked, ucToolbarDataEntry1_ButtonEditClicked, ucToolbarDataEntry1_ButtonRemoveClicked, ucToolbarDataEntry1_ButtonSaveClicked
- **frmPushCommander** — ServiceConnected, T_Ticks, frmPushCommander_Load, DeviceListBox_SelectedIndexChanged, DnlSearchBtn_Click, UpdateState, AddCommandRow, RebootBtn_Click, CheckActivationCode, OpenDoor_Click, ActivateDLST_Click, DeactivateDLST_Click, UnAlarm_Click, ClearLog_Click, SetIp_Click, SetPort_Click, GetInfoBtn_Click, ReadInfo, RefreshInfos, CallUserBtn_Click, GetCallResult, DownloadUsers_Click, UploadUserBtn_Click, EnrollFP_Click, EnrollFace_Click, EnrollPalm_Click, EnrollCard_Click, ChoosePhotoBtn_Click, UploadImageForEnrollBtn_Click, AttSearchBtn_Click, ClearLogInTime_Click, GetAttResult, WriteReceivedRecords, ClearAttBtn_Click, SaveRecordsOnDb, SaveAllRecord, CountSaveAllRecord, ShowSaveAllRecord, MeetingAddUserBtn_Click, ClearMeetingListBtn_Click, SendToPushService, SubmitMeetingBtn_Click, TabControl_SelectedIndexChanged, TabControl_Selecting, AttReqPinBtn_Click, button2_Click, AttreqBtn_Click, GetMachineName, GetMachineName, ListyCallingBtn_Click, TakkiCallingBtn_Click, ListyDeleteBtn_Click, TakkiDeleteBtn_Click, toolTip1_Popup, chkDeleteRecords_CheckedChanged, SearchDevice_Click, AddADRBtn_Click, DeleteAdrBtn_Click, rdioAllImageDelete_CheckedChanged, rdioAllVideoDelete_CheckedChanged, ClearCmdBtn_Click, StartBroker, ChangeCommandList, frmPushCommander_FormClosed, tabControl1_SelectedIndexChanged, CreateCmd, GenerateCommandNumber, GetValueByNameInPushHeader2, cmbFilter_SelectedIndexChanged, button1_Click, button2_Click_1, comboBox1_SelectedIndexChanged
- **frmPushSettings** — frmPushSettings_Load, btnOK_Click, btnCancel_Click
- **frmRecordsLog** — btnShowReport_Click, frmRecordsLog_Load, grdLogs_CellFormatting, nmbDeviceNumber_ValueChanged, btnExcel_Click, chkSelectPersonnel_CheckedChanged
- **frmSelectDepts** — frmSelectDepts_Load, btnOK_Click, btnCancel_Click
- **frmSelectDevices** — frmSelectDepts_Load, btnOK_Click, btnCancel_Click
- **frmServiceSetup** — btnSelectInstallUtil_Click, btnInstallOcx_Click, btnInstallService_Click, btnRestartService_Click, tmrServiceState_Tick, btnSaveSettings_Click, frmServiceSetup_Load
- **frmServiceWorker** — Start, Stop, timer_Tick, groupBox1_Enter
- **frmUser** — LoadFormByItem, CreateItemByForm, LoadAllData, frmUser_Load, grdData_CurrentCellChanged, btnSelectAccessType_Click, btnSelectDeptsAccess_Click, ucToolbarDataEntry1_ButtonAddClicked, ucToolbarDataEntry1_ButtonCancelClicked, ucToolbarDataEntry1_ButtonEditClicked, ucToolbarDataEntry1_ButtonRemoveClicked, ucToolbarDataEntry1_ButtonSaveClicked, chkMainManager_CheckedChanged, btnDevicesAccess_Click
- **frmViewDownloadLog** — chkSelectDevice_CheckedChanged, btnShowReport_Click, frmViewDownloadLog_Load, grdLog_CellFormatting, nmbDeviceNumber_ValueChanged

## ۵) بررسی اولیهٔ ورود و اتصال هویت

- در `T5V3Web.Controllers.AccountController.Login` فراخوانی `Personnel.DB.UserInfoDA.CheckUserPass` دیده شد؛ رشته‌های آن همچنین به خواندن/به‌روزرسانی `FirstLogin` در `UserInfo` و ثبت `PassCheckProps` اشاره می‌کنند.
- در `MyTime3.Controllers.LoginController.TestUserPass` و مسیر ورود `Login` نیز فراخوانی `Personnel.DB.UserInfoDA.CheckUserPass` مشاهده شد. مسیر ورود TWF همچنین `T5V3.DB.EntityManagers.UserPermissionDA.IsAnyMainManager` را فراخوانی می‌کند و از `MyTime3.Authorization.LoginManager` برای ایجاد session استفاده می‌کند.
- نام cookieهای شناسایی‌شده در دو اسمبلی متفاوت است (`t5v3webauth` برای T5Web و `mytimeauth` برای TWF). پس شواهد استاتیک فعلی از **اعتبارسنجی بر مبنای همان Personnel/UserInfo و permissionهای T5** پشتیبانی می‌کند، اما لزوماً از یک cookie/SSO مشترک بین دو وب‌اپ نه. این با تعریف یک‌بارهٔ کاربر سازگار است؛ تجربهٔ ورود خودکار/ورود دوباره در نسخهٔ فعال باید جداگانه با مشاهدهٔ runtime ثبت شود.
- مسیر ورود Link5 در `frmLogin.btnOK_Click` از `Link5.DB.UserDA.Login` استفاده می‌کند؛ در Link5 SQL هم `User`, `UserAccess`, `UserDeptAccess`, `UserDeviceAccess` وجود دارد. رابطهٔ دقیق حساب/مجوز داخلی Link5 با مجوز انتخاب برنامه در T5 در artifactهای static هنوز قطعی نشده است. طبق تأیید کاربر، رفتار فعال شبکه مرجع است و این رابطه باید از QueryAccess/InvisibleMenus و مسیرهای sync و سپس در شبکهٔ فعال تطبیق شود؛ در محصول جدید، کاربر تکراری نخواهیم ساخت.

## ۶) برداشت‌های فنی موقت، نیازمند تطبیق با اجرای واقعی

- T5Web به‌صورت MVC وب، controllerهای جدا برای بیشتر View moduleها دارد؛ متدهایی مانند `HomeController.GetMenu/GetSubMenu/SideMenu` و `MenusAccessModel` نشان می‌دهند منو و دسترسی‌ها پویا/وابسته به مجوزند. بنابراین منوی واقعی باید از داده و role runtime تأیید شود، نه فقط از فولدر Views.
- TWF شامل کنترلرهای صریح ثبت/ویرایش/لغو درخواست و پذیرش/لغو دسته‌ای است؛ کلاس‌های مدل درخواست نیز چند نوع درخواست (مرخصی روزانه/ساعتی، مأموریت، تأخیر/تعجیل، رکورد و اضافه‌کار) را از هم جدا می‌کنند.
- Link5 فرم `frmMain` دارای handlerهای اصلی دستگاه، دانلود/آپلود، رکورد، کاربر، USB، گزارش و تنظیمات است؛ `frmPushCommander` شامل عملیات آنلاین/فرمان و ثبت رکورد است. فرم‌ها و handlerها نشان‌دهندهٔ UI واقعی‌اند، اما فعال‌بودن هر گزینه وابسته به مجوز، دستگاه، لایسنس و تنظیم محیط است.
- رشته‌های تعبیه‌شده در `frmMain` نشانهٔ گروه‌های منوی Link5 هستند: پرسنل و داده‌های پایه (مدارک، انواع استخدام، عناوین، واحدها، فیلدهای اضافه، ورود Excel، پرسنل، چارت واحدها)، دستگاه و عملیات (مناطق، دستگاه‌ها، دانلود/آپلود کاربران، دانلود رکورد/زمان، مشخصات دستگاه، کلیدها، لاگ‌ها، تنظیم دستگاه، backup/restore، فرمان‌های Push، اتصال/قطع)، گزارش/لاگ و ابزارها (لاگ دستگاه/رکورد، خروجی لیست دستگاه، USB، کارت‌ها)، تنظیمات (خروجی، گزینه‌ها، تنظیم سرویس/ایمیل/Push، زمان‌بندی Backup) و مدیریت کاربران/راهنما. عنوان و ترتیب دقیق فارسی باید از منابع تعبیه‌شده و نسخهٔ در حال اجرای شبکه نهایی شود.
- T5Web assembly، منابع فارسی و ۱۰ قالب RDLC قابل شناسایی دارد: AbsentPresent، Cardex، Earlies، Lates، Leaves، Lessworks، Monthly، OverTimes، Requests و Total. این‌ها علاوه بر صفحات View باید در ممیزی خروجی‌های چاپی/گزارشی لحاظ شوند.
- **منوی وب T5Web**: `_Menu.cshtml` دو لینک «صفحه اصلی» و «درباره ما» دارد؛ بقیه از `HomeController.GetMenu(Context)` ساخته می‌شود. شش سرگروه و آیتم‌های آن‌ها از ترتیب resource getterها و HTML تولیدشده استخراج شدند:
  - **اطلاعات پایه:** مدرک تحصیلی، سمت، انتخاب جانشین، نوع استخدام، نوع خروج، شرح خروج، تقویم، ماه رمضان، قانون، قسمت، مشخصات اضافه پرسنل، ورود اطلاعات کاربران از Excel، اطلاعات پرسنلی، گروهبندی پرسنل.
  - **اطلاعات حضور و غیاب:** جدول زمانی، شیفت، اختصاص شیفت به پرسنل، شیفت‌های موقت، اختصاص قوانین.
  - **کنترل حضور و غیاب:** درج تردد، درج مرخصی و مأموریت روزانه، مدیریت اطلاعات USB، ویرایش تأخیر و تعجیل، صدور مجوز کسر کار، پیش‌کارت/اعمال پیش‌کارت، رکورد تردد، کنترل یکپارچه، گزارش جدولی، انتقال مانده مرخصی، وضعیت پرسنل.
  - **دستگاه:** گیت، دستگاه، عملیات روی دستگاه‌ها.
  - **گزارشات:** گزارش روزانه فردی، گزارش مجموع، گزارش تأخیر و تعجیل، گزارش سمت‌های سازمانی، گزارش وضعیت پرسنل، گزارش خروج‌ها، گزارش اضافه‌کارها، گزارش سالانه مرخصی، گزارش مانده مرخصی، گزارش درخواست‌ها، پارامترها، طراحی/نمایش گزارش، خروجی اطلاعات.
  - **مدیریت:** Log، گردش کار، مدیر، تغییر مجوز نرم‌افزار، مدیریت داشبورد، آیتم‌های داشبورد.
  منو پویاست: `GetMenu` نقش‌های گرفته‌شده از `LoginManager.GetRole(Context)` را split می‌کند و آیتم‌ها را با permission-codeهایی از جنس `5-1`, `5-2`, `6-1`, ... فیلتر می‌کند؛ `UserPermissionDA` آن‌ها را از `UserPermissions` می‌خواند و مدل Manager هم `ProgramAccess` دارد. پس سطح دسترسی بر خود منو اثر می‌گذارد. `ToolbarItems.xml` جداگانه ۹ shortcut نقش‌دار دارد؛ ترتیب/visibility هر آیتم را با آزمون نقش تأیید می‌کنیم، نه با نمایش همهٔ منوها برای همه.
- **منوی وب TWF** در `_Menu.cshtml` صریحاً «صفحه اصلی»، «درباره ما»، دو گروه **عملیات** و **گزارشات** دارد. عملیات: درخواست جدید، درخواست از طرف پرسنل، مشاهده وضعیت کاربران، ویرایش درخواست، تایید درخواست، وضعیت درخواست‌ها، جانشین، بازگشت عملیات، اضافه‌کار ثابت، درج پاداش و جریمه، انتخاب محدوده مجاز و دریافت Noti5. گزارشات: نمایش گزارشات، مانده مرخصی، گزارش وضعیت پرسنل و نمایش پرسنل با مجوز خروج. برخی گزینه‌ها بر اساس مدیر/تنظیمات نمایش داده می‌شوند.
- **منوی Link5** از handlerهای `frmMain` و منابع فارسی استخراج شد: **پرسنلی** (مدارک تحصیلی، انواع استخدام، سمت‌ها، قسمت‌ها، مشخصات اضافه، ورود Excel، پرسنل، چارت پرسنل)؛ **دستگاه/عملیات** (منطقه‌ها، دستگاه‌ها، فراخوانی/بارگذاری پرسنل، فراخوانی ترددها/بازهٔ زمانی، مشخصات دستگاه، کدهای دستگاه، لاگ دستگاه، تنظیمات دستگاه، پشتیبان‌گیری/بازیابی، Push، اتصال/قطع)؛ **سوابق و ابزارها** (لاگ ارتباط، لاگ تردد، خروجی لیست دستگاه، USB، کارت‌ها)؛ **تنظیمات** (تنظیم خروجی/عملکرد، سرویس، ایمیل، Push، زمان‌بندی پشتیبان‌گیری)؛ **مدیریت کاربران، راهنما و درباره**. ترتیب menu از `InitializeComponent` موجود است؛ captionهای فارسی از resourceهای embedded خوانده شده‌اند. گزینه‌های سرویس فقط به‌عنوان قابلیت نسخهٔ مرجع ثبت می‌شوند؛ محصول جدید همچنان قید عدم وابستگی به Windows Service را رعایت می‌کند.
- هیچ‌یک از این findings به‌تنهایی مجوز دور زدن قفل، اجرای برنامه یا استفاده از API دستگاه را ایجاد نمی‌کند. رفتارهای نیازمند runtime باید با دسترسی مجاز و مشاهدهٔ نسخهٔ شبکه تأیید شوند.

## ۷) مقایسهٔ نسخه‌های DLL مشترک بین دو وب‌برنامه

REA comparison روی فایل‌های هم‌نام داخل T5Web و TWF انجام شد. نتیجهٔ مهم: پوشه‌ها از یک bundle یکسان کپی نشده‌اند.

| فایل | SHA در دو بسته | نتیجهٔ اختلاف REA | تفسیر مجاز فعلی |
|---|---|---|---|
| `T5V3.Attendance.dll` | یکسان | — | بایت‌به‌بایت یکسان در دو بسته |
| `T5V3.LeaveCalcs.dll` | یکسان | — | بایت‌به‌بایت یکسان در دو بسته |
| `T5V3.ReportGenerator.dll` | یکسان | — | بایت‌به‌بایت یکسان در دو بسته |
| `Personnel.dll` | متفاوت | ۳۵ متدِ هم‌امضا دارای CIL متفاوت در مقایسهٔ T5Web↔TWF؛ type/fieldها برابرِ match شده‌اند | باید متدهای تغییرکرده، مخصوصاً ورود/پرونده، جداگانه بررسی شوند |
| `T5V3.Model.dll` | متفاوت | ۷ مورد اضافه، ۴ حذف‌شده، ۱۱۱۱ مورد changed و ۲ مورد unknown در گزارش مقایسه | counts compiler/property code هم دارند؛ نوع/مدل‌های واقعی باید جدا diff شوند |
| `T5V3.DB.dll` | متفاوت | ۱۱۱ متدِ هم‌امضا دارای تفاوت CIL؛ هیچ عضو اضافه/حذف‌شده در summary مقایسه گزارش نشد | لایهٔ DB/منطق بین bundleها یکسان فرض نشود |
| `T5V3.Misc.dll` | متفاوت | ۱۸ اضافه، ۲۹ حذف، ۲۱۸ changed و ۲ unknown | helperها/تاریخ/گرید و APIهای جانبی بررسی جدا می‌خواهند |
| `T5V3.Report.dll` | متفاوت | ۱۴۳۱ changed در هم‌امضاها، ۳۸۱ unchanged | تغییر بزرگ در codegen/report surface؛ خروجی گزارش نسخه‌ها نباید ادغام حدسی شود |
| `Personnel.dll` در Link5 | با هر دو وب‌اپ متفاوت | مقایسهٔ عضو به عضو هنوز انجام نشده | نسخه/کپی Link5 جدا ثبت و مقایسهٔ بعدی لازم است |

> REA وضعیت `changed` را از تفاوت CIL/شکل method نشان می‌دهد؛ این به‌تنهایی ثابت نمی‌کند رفتار کاربری تغییر کرده، به‌خصوص برای کلاس‌های auto-generated. از طرف دیگر، hash متفاوت را هم نباید «بی‌اهمیت» فرض کرد. مرحلهٔ بعد باید موارد تغییریافته را برحسب Controller/Model/DB/Report اولویت‌بندی و با View و رفتار عملی تطبیق دهد.

## ۸) نگاشت اولیهٔ صفحه‌های تأییدشده به بازسازی

برای کاهش خطای نگاشت از روی نام کنترلر، کد بازسازی این موارد را جداگانه پیاده می‌کند:

- `LeaveDescription/Index.cshtml` ↔ `attendance:leave_descriptions`؛ شرح و enum مربوط به Duty/Leave، با آزمون create/edit/delete.
- `LeaveType/MyView.cshtml` ↔ `attendance:leave_types`؛ نام/نماد، Duty/Leave، ساعتی/روزانه، StateKey، رنگ، سقف‌ها، روش/پارامتر استحقاق، انتقال سال، پرداخت و تعطیلات. enumهای numeric مرجع در مدل جدید نرمال شده‌اند؛ رفتار محاسباتی همهٔ این سیاست‌ها هنوز پذیرش تطبیقی می‌خواهد.
- `Card/Index.cshtml` + `Card/Apply.cshtml` ↔ `attendance:pre_cards` + `attendance:pre_cards_apply`؛ نگاشت Card و الگوریتم ApplyInterval در CIL از طریق badge آغازگر، پنجرهٔ ValidSecs، MachineNumber، StateKey/CheckType و OnlyFirst اجرا می‌شود؛ آزمون‌های محلی وجود دارد و تطبیق دادهٔ واقعی هنوز لازم است.
- `Schedule/Index.cshtml` ↔ `attendance:schedule_definitions`؛ فیلدهای ساعت، پنجره‌های ورود/خروج، رمضان، استراحت، قانون و تقویم.
- `TempSchedules/Index.cshtml` ↔ `attendance:temp_schedules`؛ نمایش بازه و رکوردها، و تغییر گروهی/فردی با دامنهٔ پرسنلی مجاز.
- `Permit/Index.cshtml` ↔ `attendance:valid_leaves`؛ `ValidLeave` با انتخاب پرسنل، بازه، مدت و شرح.
- `ImportFromExcel/Index.cshtml` ↔ `org:import_excel`؛ واردکردن **پروندهٔ پرسنلی** با انتخاب ستون، پیش‌نمایش و تأیید ثبت. واردکنندهٔ CSV تردد به‌عنوان جایگزین Excel پرسنل استفاده نمی‌شود.

این نگاشت‌ها از Viewها و metadata مدل/کنترلر استخراج شده‌اند؛ آزمون‌های Django رفتار محلی را می‌سنجند، اما تطابق UI/runtime نصب فعال شبکه جداگانه نیازمند پذیرش است.

**ممیزی جزئی T5Web ImportFromExcel (نسخهٔ پک 3.5.7):** سه View موجود است: `Index.cshtml`, `Preview.cshtml`, `Import.cshtml`. در `Index`, فرم multipart فیلد `filePath` را به POST `/ImportFromExcel/PreviewExcel` می‌فرستد؛ دکمهٔ «نمایش» فایل را برای نمایش جدول خام می‌خواند و دکمهٔ `operation=ShowPreview` مسیر پیش‌نمایشِ ستون‌بندی‌شده را انتخاب می‌کند. جدول خام سلول‌های Excel را نشان می‌دهد؛ کلیک سلول، شمارهٔ ستون (۱مبنا) را در ورودی فیلد انتخاب‌شده می‌گذارد، و کلیک ردیف شمارهٔ ردیف را برای `FromRow`/`ToRow` می‌گذارد. ۹ فیلد به‌ترتیب badge، نام، نام خانوادگی، نام دستگاه، ایمیل، تلفن، آدرس، شماره و جنسیت هستند؛ مقدار checkboxهای `selectedColumns` برابر ۱ تا ۹ است. Resourceهای اصلی فارسی: عنوان «ورود اطلاعات کاربران از Excel»، `Show=نمایش`, `ShowPreview=پیش نمایش`, `SelectColumns=انتخاب ستون`, `Importable=قابل انتقال`, `Import=انتقال`, `Male=مرد`, `Female=زن`.

در metadata، `Index`، `Import`، `PreviewExcel` و `Preview` به `AuthorizeFilter(Roles="14-1,14-2")` متصل‌اند؛ سه action آخرِ POST صفت `[HttpPost]` دارند و `Index` صفت HTTP صریح ندارد. فرم‌های Index و Preview در فایل View خودشان `AntiForgeryToken` نشان نمی‌دهند؛ این مشاهده وجود یا نبود global filter را تعیین نمی‌کند. `PreviewExcel` هر ۹ `ExcelColumn` را با ترتیب ثابت می‌سازد و `WorkbookDataProcessor.Process` را فراخوانی می‌کند؛ مسیر دیگر، `DataSet.Tables[0]` را به `ExcelPreviewModel` دوبعدی تبدیل و View `Index` را برمی‌گرداند. Action جداگانهٔ `Preview` نیز جریان پردازش/نمایش Preview را دارد.

در `Personnel.dll`, REA متد `T5V3.ExcelDataReader.WorkbookDataProcessor.Process` (۴۴۳ دستور CIL) و مدل‌های `ExcelPersonnel`/`ExcelColumn` را شناسایی کرد. پردازش از ردیف آغازین تا ردیف پایانی به‌شکل inclusive و با شمارهٔ سطر/ستون ۱مبنا انجام می‌شود؛ badge با `Int64.TryParse` اعتبارسنجی می‌شود (شکست: enum status 1 / `BadgeNumberError`)، نام و نام خانوادگی پس از `Trim` باید غیرخالی باشند (شکست: enum status 2 / `FirstNameOrLastNameError`)، و خطاهای دیگر در خواندن/تبدیل فیلدها enum status 3 / `BadData` می‌گیرند. `Importable` برابر status صفر است. `StatusStr` این enumها را به resourceهای UI تبدیل می‌کند: `BadgeNumberError=شناسه مشکل دارد`, `NameError=نام مشکل دارد`, `DataError=اطلاعات مشکل دارد`, `OK=تایید`. جنسیت با `Boolean.TryParse` خوانده می‌شود اما مقدار موفقیت parse در CIL دور ریخته می‌شود؛ رشتهٔ نامعتبر می‌تواند مقدار `false` (و در View «مرد») بگیرد. وقتی ردیفِ درخواست‌شده از `DataTable.Rows.Count` بیرون است، branch در CIL به status مقدار 1 (`BadgeNumberError`) می‌رود، نه به مسیر status-zero/Importable؛ runtime همچنان با فایل نمونه آزموده نشده است.

`Preview.cshtml` فقط برای ردیف‌های `Importable` مقدار hidden به نام `items` می‌سازد؛ `GetTextForHTML` ۹ مقدار را با `;` سریال می‌کند، `GetFromHTMLText` آن‌ها را دوباره parse می‌کند و در exception مقدار null برمی‌گرداند. CILِ `Import` روی هر مقدار parseشده دوباره `Importable=true` می‌گذارد، بعد badge موجود را با `UserInfoDA.Exists` می‌سنجد، سقف `GlobalHelper.MaxPersonnelCount` را بررسی و `UserInfoDA.AddNew` را صدا می‌زند؛ ایجاد UserInfo یک‌به‌یک است و audit log per-user می‌سازد. defaults قابل‌مشاهده شامل DeptID=-1، تاریخ تولد 2000-01-01، اولین CertificateID و EmpTypeID، DeviceCardNumber="0"، DeviceEnable=true، DeviceGroupNumber=1، DevicePrivilege=0، HiredDay=Now و خالی/صفر بودن چند مقدار هویتی/حساب است. badge تکراری در مرحلهٔ Import خطا می‌شود؛ پیش‌نمایش پردازش‌شدهٔ `WorkbookDataProcessor` duplicate در DB را خودش بررسی نمی‌کند.

ریسک‌های static: دادهٔ hidden فرم را می‌توان POST کرد و منطق Import آن را پس از parse معتبرِ ساختاری، بی‌اعتنا به وضعیت قبلی Preview، قابل ورود علامت می‌زند؛ قالب `;` نیز escape ندارد. در CIL action محدودیت صریح اندازهٔ فایل/پسوند/سطر یا token ضد-CSRF دیده نشد؛ تنظیمات IIS/سایت یا محدودیت داخلی کتابخانه جدا هستند و از این نبودن در بدنه نتیجه‌گیری نمی‌شوند. واردکنندهٔ محلی عمدی سخت‌گیرتر است (CSRF، preview وابسته به session کاربر، محدودیت فایل/ردیف، اعتبارسنجی duplicate و payload) و بنابراین برابری ریزرفتار با نسخهٔ مرجع ادعا نمی‌شود؛ هر تفاوتی باید با اولویت امنیت/دقت آشکار بماند.

**ممیزی جزئی T5Web USBManagement (نسخهٔ پک 3.5.7):** `USBManagement/Index.cshtml` یک فرم multipart با فیلد `attlog` و POST مستقیم به `/USBManagement/ReadRecords` دارد؛ در خود View نشانهٔ `AntiForgeryToken` دیده نمی‌شود. صفحه `Logs` و `OutDBBadgeNumbers` را به‌صورت `<li>` می‌نمایاند. در metadata، `Index` دارای `[HttpGet]` و `ReadRecords` دارای `[HttpPost]` هستند؛ هر دو به `T5V3Web.AuthorizeFilter` با role رشته‌ای `52-1,52-2` متصل‌اند. این یافته به‌تنهایی global filter یا مجوز runtime در نصب فعال را اثبات نمی‌کند.

REA روی `T5V3Web.dll` (SHA-256 `96c71335ca080f4fa568e65f81957590ffdb953a9fe133ed0000b416afc7e755`, MVID `a2ffb71c-6140-4327-b321-e268e0b58ec5`) متدهای `Index` (0x06000a79)، `ReadRecords` (0x06000a7a) و `CopyToModelRecord` (0x06000a7b) را شناسایی کرد. CILِ `ReadRecords` فقط در صورت وجود فایل آپلودشده وارد مسیر پردازش می‌شود: stream به `MemoryStream` کپی، با `Encoding.ASCII` decode و با CR و LF به‌عنوان جداکننده و `StringSplitOptions.RemoveEmptyEntries` به خطوط تبدیل می‌شود. سپس همهٔ badge numberهای Personnel گرفته و به `Personnel.USBFiles.RecordReader.ReadFromData(string[] fileData, List<long> dbBadgeNumbers, bool addNewBadgeNumbers)` با آرگومان سوم `false` داده می‌شوند. برای هر رکورد parseشده، `CopyToModelRecord` اجرا و یک `OprationLogModel` با نام عملیات، کاربر، IP و دادهٔ رکورد ساخته می‌شود؛ سپس `RecordDA.BulkInsert` فراخوانی و `Logs` و `NewBadgeNumbers` روی مدل View قرار می‌گیرند. ترتیب CIL ایجاد audit log پیش از فراخوانی BulkInsert را نشان می‌دهد؛ پیامد تراکنشی/اتمی آن بدون آزمون runtime یا بررسی پیاده‌سازی DB قطعی نیست.

**Parser در `Personnel.dll`:** `ReadFromData` آرایهٔ خطوط را پیمایش می‌کند و هر خط را با یک TAB (`String.Split(char[])`, بدون `RemoveEmptyEntries`) می‌شکند. فیلدهای 0 تا 4 به‌ترتیب badge number، timestamp، machine number، check type و verify mode هستند؛ فیلدهای اضافه در این متد خوانده نمی‌شوند. badge با `Int64.TryParse` خوانده می‌شود. badge ناشناختهٔ معتبر، یکتا به `NewBadgeNumbers` افزوده می‌شود؛ چون Controller آرگومان سوم را `false` می‌دهد، برای آن رکورد ساخته نمی‌شود و این شاخه log خطای badge هم تولید نمی‌کند (شماره در خروجی `OutDBBadgeNumbers` نمایش داده می‌شود). آرگومان `true` در خود parser امکان ساخت رکورد برای badge ناشناخته را می‌دهد، اما مسیر مرجع `ReadRecords` آن را فعال نمی‌کند. تنها شکست `Int64.TryParse` مسیر log `ErrorBadge` را می‌گیرد.

Timestamp با چهار جداکنندهٔ ذخیره‌شده در FieldRVA (به‌ترتیب space، `-`، `_`، `:`) به شش جزء year/month/day/hour/minute/second شکسته می‌شود؛ هر جزء با `Convert.ToInt32` تبدیل می‌شود. برای year کمتر از 1500، `PersianCalendar.ToDateTime(..., 0)` و برای year برابر یا بیشتر از 1500، `new DateTime(..., 0)` استفاده می‌شود. رکورد parser مقدارهای `BadgeNumber`, `CheckTime`, `MachineNumber` (line[2]), `CheckType` (line[3]) و `VerifyMode` (line[4]) را می‌گیرد؛ `InsertTime=DateTime.Now`, `SourceType=1` و `IsValid=true` نیز تنظیم می‌شوند. در بدنهٔ parser، `WorkCode`, `OriginalSCheckType` و `InsertUser` مقداردهی صریح نشده‌اند. خطای هر خط در قالب `{0}:{1} {2}-{3}` به log می‌رود: resourceهای مرجع `ErrorInReadingRecord=خطا در خواندن رکورد` و `LineNumber=Line Number`، شمارهٔ خط (مبنای 1) و `Exception.Message`. badge نامعتبر/ناشناخته با قالب `{0} {1} {2}` و resourceهای `BadgeNumber=شناسه`, `ErrorBadge=درست درج نشده است` ثبت می‌شود. خطای سطح عملیات از `ErrorInOperation=خطا در انجام عملیات` و `Exception.Message` ساخته می‌شود؛ در پایان تعداد رکوردهای parseشده به‌همراه `RecordsRead=رکورد خوانده شد` log می‌شود. این resource valueها از manifest `Personnel.USBFiles.USBLogResource.resources` خوانده شده‌اند. این‌ها شواهد CIL ایستا هستند، نه آزمون رفتار runtime یا بازیابی سورس C#.

در `USBManagementController.CopyToModelRecord`، `BadgeNumber`, `CheckTime`, `CheckType`, `InsertTime`, `IsValid`, `MachineNumber`, `OriginalSCheckType`, `SourceType`, `VerifyMode` و `WorkCode` از مدل Personnel خوانده و به مدل T5 نوشته می‌شوند؛ استثنا `InsertUser` است که CIL آن را با مقدار ثابت `1` تنظیم می‌کند، نه مقدار parser یا هویت کاربر جاری. در نتیجه مقادیر پیش‌فرض parser برای `WorkCode` و `OriginalSCheckType` نیز کپی می‌شوند. پک T5Web فایل نمونهٔ `.attlog`/`.dat`/`.txt`/`.csv` نداشت؛ تطبیق با نمونهٔ واقعی و رفتار نصب هنوز باز است. جستجوی متن‌های پک و اسکن رشته‌های ASCII/UTF-16 اسمبلی‌ها برای `UNIS`, `Union Community` و `unis.mdb` اشاره‌ای به نرم‌افزار یونیس پیدا نکرد؛ تنها تطبیق `Uniscribe` در `Microsoft.ReportViewer.Common.dll` نام Windows text-shaping API و نامرتبط است. bridge موجود در `apps.device` نباید معادل این قابلیت T5 شمرده شود. `Card/ApplyInterval` با شواهد CIL و آزمون‌های محدود پیاده شده است؛ گزارش‌های ویژه هنوز نیازمند نگاشت و آزمون اختصاصی هستند.

## ۹) مرز این استخراج

REA اسمبلی .NET را بارگذاری/اجرا نکرد؛ خروجی metadata و CIL است. نام و بدنهٔ CIL برای ردیابی منطق کمک می‌کند، اما به‌تنهایی متن اصلی C#، ترتیب منو در زمان اجرا، اتصال واقعی database، تنظیمات مشتری یا رفتار دستگاه را اثبات نمی‌کند. آن موارد جداگانه از Viewها، resourceها، DDL، تنظیمات غیرمحرمانه و آزمون‌های مرجع تکمیل می‌شوند.
