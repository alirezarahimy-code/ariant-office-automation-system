# راه‌اندازی و اجرا

## ۱. پیش‌نیازها

| مورد | نسخه |
| --- | --- |
| .NET SDK | ۱۰ (پیشنهادی) یا ۸ |
| پایگاه‌داده | SQLite (پیش‌فرض، بدون نصب) یا SQL Server 2019+ |
| مرورگر | نسخه‌های جدید کروم/اج/فایرفاکس |
| ویرایشگر | Visual Studio 2026 / VS Code + C# Dev Kit / Rider |

> **تغییر نسخه دات‌نت:** فقط یک خط در `Directory.Build.props` (`TargetFramework`) و نسخه پکیج‌ها در
> `Directory.Packages.props`. اگر ابزار شما از دات‌نت ۱۰ پشتیبانی نمی‌کند، هر شش پکیج را به `8.0.31` تغییر دهید.

## ۲. اجرای سریع (SQLite — بدون نیاز به نصب پایگاه‌داده)

```bash
git clone <آدرس مخزن>
cd ariant-office-automation-system

dotnet restore
dotnet build

# اجرا
dotnet run --project src/Ariant.OfficeAutomation.Web
```

سپس در مرورگر: `http://localhost:5100`

**نخستین ورود:**

| نام کاربری | گذرواژه |
| --- | --- |
| `admin` | `Admin@1404` |

> پس از نخستین ورود، از منوی «تغییر گذرواژه» گذرواژه را عوض کنید.

در نخستین اجرا، خودِ برنامه:
1. فایل پایگاه‌داده SQLite (`ariant.dev.db`) را می‌سازد،
2. نقش‌ها (مدیر سیستم/مدیر/کارمند) را درج می‌کند،
3. کاربر مدیر را می‌سازد،
4. ساختار سازمانی و چند کارمند نمونه را وارد می‌کند.

## ۳. اجرای آزمون‌ها

```bash
dotnet test
```

**آزمون تقویم شمسی نباید قرمز شود**: ۲۰۷ بردار مرجع (از کتابخانه مرجع منطبق با تقویم رسمی ایران)
در `PersianDateTests.cs` قرار دارد و هر تغییر در منطق تقویم را فوراً آشکار می‌کند.

## ۴. اتصال به SQL Server (محیط عملیاتی)

`appsettings.json` را تغییر دهید:

```json
{
  "ConnectionStrings": {
    "Default": "Server=.;Database=AriantOfficeAutomation;Trusted_Connection=True;TrustServerCertificate=True;MultipleActiveResultSets=true"
  },
  "Database": {
    "Provider": "SqlServer",
    "UseMigrations": true
  }
}
```

سپس مهاجرت‌ها را بسازید و اعمال کنید:

```bash
dotnet tool install --global dotnet-ef

dotnet ef migrations add InitialCreate \
  --project src/Ariant.OfficeAutomation.Infrastructure \
  --startup-project src/Ariant.OfficeAutomation.Web \
  --output-dir Persistence/Migrations/SqlServer

dotnet ef database update \
  --project src/Ariant.OfficeAutomation.Infrastructure \
  --startup-project src/Ariant.OfficeAutomation.Web
```

> در حالت `UseMigrations: false` برنامه با `EnsureCreated` جداول را می‌سازد؛ این حالت برای
> توسعه مناسب است ولی در محیط عملیاتی از مهاجرت استفاده کنید.

## ۵. تنظیمات امنیتی پیش از استقرار

| کلید | توضیح |
| --- | --- |
| `Jwt:SigningKey` | **حتماً** از متغیر محیطی خوانده شود: `Jwt__SigningKey` (حداقل ۳۲ نویسه) |
| `Seed:AdminPassword` | گذرواژه مدیر اولیه؛ آن را تغییر دهید یا از متغیر محیطی بدهید |
| `Seed:AdminEmail` | رایانامه مدیر |
| `Database:EnableDetailedErrors` | در محیط عملیاتی `false` |

نمونه اجرای عملیاتی:

```bash
export Jwt__SigningKey="یک-کلید-تصادفی-حداقل-۳۲-نویسه"
export Seed__AdminPassword="یک-گذرواژه-قوی"
export ASPNETCORE_ENVIRONMENT=Production
dotnet Ariant.OfficeAutomation.Web.dll
```

## ۶. ساخت خروجی برای انتشار

```bash
dotnet publish src/Ariant.OfficeAutomation.Web \
  --configuration Release \
  --runtime win-x64 \
  --self-contained false \
  --output ./publish
```

سپس پوشه `publish` را روی سرور IIS (با نصب ASP.NET Core Hosting Bundle) قرار دهید و
Application Pool را روی `No Managed Code` تنظیم کنید.

## ۷. بررسی نگارشی بدون نصب دات‌نت (اختیاری)

برای بازبینی سریع پیش از کامیت (یا در محیط‌هایی که SDK نصب نیست):

```bash
pip install tree-sitter tree-sitter-c-sharp
python3 tools/check-cs/check_cs.py
```

## ۸. رفع اشکال‌های رایج

| نشانه | علت و راه‌حل |
| --- | --- |
| خطای «The type or namespace name ... could not be found» | `dotnet restore` را اجرا کنید؛ یا نسخه پکیج‌ها با `TargetFramework` هم‌تراز نیست |
| قفل شدن حساب مدیر | ۵ تلاش ناموفق باعث قفل ۱۵ دقیقه‌ای می‌شود |
| متن فارسی به‌صورت مربع نمایش داده می‌شود | فایل‌های فونت در `wwwroot/fonts/vazirmatn` باید موجود باشند |
| تاریخ‌ها یک روز جابه‌جا هستند | بررسی کنید الگوریتم تقویم تغییر نکرده باشد (آزمون‌های `PersianDateTests` را اجرا کنید) |
| خطای ICU/فرهنگ در پایگاه‌داده کانتینری | `InvariantGlobalization` باید `false` بماند |
