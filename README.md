# سامانه اتوماسیون اداری آرینت

[![ساخت و آزمون (CI)](https://github.com/alirezarahimy-code/ariant-office-automation-system/actions/workflows/ci.yml/badge.svg)](https://github.com/alirezarahimy-code/ariant-office-automation-system/actions/workflows/ci.yml)

سامانه جامع اتوماسیون اداری، فارسی و راست‌به‌چپ، بر پایه **ASP.NET Core (.NET 10)** با تقویم شمسی در تمام سامانه.

> **وضعیت ساخت:** تمام پروژه با موفقیت ساخته می‌شود و **۶۶۷ آزمون** (شامل ۲۰۷ بردار تقویم شمسی) سبز است — نتیجه اجرای CI روی گیت‌هاب.

> وضعیت: **فاز ۰ (پایه) انجام شد** — اسکلت معماری، تقویم شمسی آزمون‌شده، هویت کاربران، گزارش ممیزی خودکار
> و نخستین ماژول (ساختار سازمانی) آماده است. نقشه فازهای بعد در [`docs/01-roadmap.md`](docs/01-roadmap.md).

## ویژگی‌های شاخص

- **تقویم شمسی دقیق**: پیاده‌سازی الگوریتم مرجع FarsiWeb (چرخه ۳۳ ساله) که با تقویم رسمی ایران مطابق است.
  الگوریتم نادرست ۲۸۲۰ ساله (بیرشک) که در سال‌های ۱۴۰۳/۱۴۰۴ یک روز خطا دارد، استفاده نشده است.
  صحت با **۲۰۷ بردار مرجع** و ۵۹٬۵۳۶ روز پیاپی سنجیده شده است.
- **گزارش ممیزی خودکار**: هر ایجاد/ویرایش/حذف، بدون دخالت برنامه‌نویس ماژول، با کاربر و زمان و مقادیر پیش/پس ثبت می‌شود.
- **حذف نرم**: هیچ داده‌ای واقعاً نابود نمی‌شود.
- **فارسی‌سازی کامل**: پیام‌های خطای Identity، ارقام فارسی، نیم‌فاصله، فونت وزیرمتن به‌صورت محلی (بدون CDN).
- **چند-پایگاه‌داده‌ای**: SQLite (پیش‌فرض توسعه) و SQL Server (عملیاتی) با یک تنظیم.
- **وب‌سرویس آماده اتصال**: `/api/v1` با نشانه JWT — برای اتصال به سامانه تیکتینگ موجود (PHP).
- **آماده توسعه تیمی**: مدیریت متمرکز پکیج‌ها، آزمون واحد، CI گیت‌هاب، ابزار بررسی نگارشی بدون نیاز به دات‌نت.

## راه‌اندازی سریع

```bash
dotnet restore
dotnet run --project src/Ariant.OfficeAutomation.Web
```

مرورگر: `http://localhost:5100` — ورود با `admin` و گذرواژه `Admin@1404` (سپس تغییر دهید).

راهنمای کامل: [`docs/03-setup.md`](docs/03-setup.md)

## ساختار راه‌حل

| پروژه | نقش |
| --- | --- |
| `Ariant.OfficeAutomation.Common` | تقویم شمسی، ابزارهای متن فارسی |
| `Ariant.OfficeAutomation.Domain` | موجودیت‌ها، قواعد و شمارشی‌های کسب‌وکار |
| `Ariant.OfficeAutomation.Application` | سرویس‌ها، قراردادها، DTO |
| `Ariant.OfficeAutomation.Infrastructure` | EF Core، Identity، JWT، میان‌افزار ممیزی |
| `Ariant.OfficeAutomation.Web` | رابط کاربری MVC/Razor + وب‌سرویس REST |
| `Ariant.OfficeAutomation.Tests` | آزمون‌های واحد (xUnit) |

## مستندات

| سند | موضوع |
| --- | --- |
| [`docs/01-roadmap.md`](docs/01-roadmap.md) | نقشه راه فازها، اولویت‌ها، اصل‌های طراحی |
| [`docs/02-architecture.md`](docs/02-architecture.md) | معماری لایه‌ها، تصمیم‌های کلیدی، قراردادهای کدنویسی |
| [`docs/03-setup.md`](docs/03-setup.md) | راه‌اندازی، SQL Server، تنظیمات امنیتی، رفع اشکال |
| [`docs/04-ticketing-integration.md`](docs/04-ticketing-integration.md) | اتصال به سامانه تیکتینگ PHP |

## فهرست کارهای فوری

1. اجرای پروژه و گرفتن بیلد سبز (`dotnet build` و `dotnet test`).
2. پاسخ به پرسش‌های بخش ۶ سند اتصال تیکتینگ.
3. مهاجرت به SQL Server برای محیط عملیاتی (`dotnet ef migrations add`).
4. انتخاب ماژول دوم (پیشنهاد: دبیرخانه و گردش مکاتبات).

## مجوز فونت

فونت [وزیرمتن](https://github.com/rastikerdar/vazirmatn) با مجوز SIL Open Font License 1.1 و
متن مجوز در `src/Ariant.OfficeAutomation.Web/wwwroot/fonts/vazirmatn/LICENSE.txt` قرار دارد.
