# سامانه اتوماسیون اداری آرینت

[![ساخت و آزمون (CI)](https://github.com/alirezarahimy-code/ariant-office-automation-system/actions/workflows/ci.yml/badge.svg)](https://github.com/alirezarahimy-code/ariant-office-automation-system/actions/workflows/ci.yml)

سامانه جامع اتوماسیون اداری، فارسی و راست‌به‌چپ، با تقویم شمسی در تمام سامانه.

> **پشته فعال: پایتون + جنگو (Django 5.2)** — بر پایه سند تصمیم فنی [`docs/05-stack-decision.md`](docs/05-stack-decision.md)
> (تنها گزینه‌ای که حلقه کامل «نوشتن ← ساخت ← اجرا ← تست» را دارد و پنل مدیریت فارسی/RTL رایگان می‌دهد).

> **وضعیت ساخت:** «پایه» کامل است — ۲۵ آزمون سبز (شامل ۲۲۰ بردار طلایی تقویم شمسی از ICU)،
> پنل مدیریت فارسی، وب‌سرویس `/api/v1` با نشانه JWT، ممیزی تغییرات، حذف نرم، ماژول ساختار سازمانی.

## راه‌اندازی سریع (پایتون/جنگو)

```bash
cd backend
python -m venv .venv && source .venv/bin/activate   # ویندوز: .venv\Scripts\activate
pip install -r requirements/base.txt
python manage.py migrate
python manage.py seed_demo        # داده نمونه + کاربر مدیر
python manage.py runserver
```

- مرورگر: `http://localhost:8000` — پنل مدیریت: `http://localhost:8000/admin/`
- ورود با `admin` و گذرواژه `Admin@1404` (حتماً تغییر دهید)
- راهنمای کامل و تنظیمات تولید: [`backend/README.md`](backend/README.md)

## ویژگی‌های شاخص

- **تقویم شمسی دقیق**: موتور تقویم با **۲۲۰ بردار طلایی مستقل (ICU)** و قاعده کبیسه ۳۳ ساله FarsiWeb آزمون شده است؛
  کتابخانه‌های خطادار تاریخ (مانند jalaliday با ۱۵ خطا در ۵۰۰ تاریخ) کنار گذاشته شدند.
- **گزارش ممیزی خودکار** (django-simple-history): هر ایجاد/ویرایش/حذف با کاربر و مقادیر پیش/پس — بدون دخالت ماژول‌نویس.
- **حذف نرم**: هیچ داده‌ای واقعاً نابود نمی‌شود.
- **فارسی و راست‌به‌چپ به‌صورت بومی**: `LANGUAGE_CODE=fa` و `TIME_ZONE=Asia/Tehran`؛ پنل مدیریت فارسی رایگان؛ تاریخ شمسی در فرم‌ها (django-jalali).
- **چند-پایگاه‌داده‌ای**: SQLite (توسعه)، PostgreSQL و MySQL (تولید) با یک متغیر محیطی `DB_ENGINE`.
- **وب‌سرویس آماده اتصال به تیکتینگ PHP**: `/api/v1/auth/token` (JWT با کلید مشترک)، `/api/v1/auth/me`، `/api/v1/departments`، `/api/v1/employees`.
- **درخت سازمانی** (django-mptt) و **کارهای پس‌زمینه** (Celery) آماده برای گردش کار.

## نمونه وب‌سرویس (پل تیکتینگ)

```bash
# دریافت نشانه
curl -X POST http://localhost:8000/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"Admin@1404"}'

# فهرست واحدها
curl http://localhost:8000/api/v1/departments -H "Authorization: Bearer <token>"
```

قرارداد کامل اتصال به سامانه PHP: [`docs/04-ticketing-integration.md`](docs/04-ticketing-integration.md)

## ساختار مخزن

| مسیر | نقش |
| --- | --- |
| `backend/` | **پشته فعال** — جنگو ۵٫۲، DRF، آزمون‌ها، نیازمندی‌ها |
| `backend/apps/core` | ابزارهای فارسی، تقویم شمسی، مدل‌های پایه (حذف نرم، زمان‌بندی) |
| `backend/apps/organization` | واحدهای سازمانی (درختی) و کارمندان + وب‌سرویس |
| `docs/` | نقشه راه، معماری، راه‌اندازی، پل تیکتینگ، سند تصمیم پشته |
| `src/` و `tests/` | پیاده‌سازی مرجع **.NET** (نگهداری‌شده به‌عنوان مرجع — `[PR #1](https://github.com/alirezarahimy-code/ariant-office-automation-system/pull/1)`) |

## مستندات

| سند | موضوع |
| --- | --- |
| [`docs/01-roadmap.md`](docs/01-roadmap.md) | نقشه راه فازها، اولویت‌ها، اصل‌های طراحی |
| [`docs/02-architecture.md`](docs/02-architecture.md) | معماری لایه‌ها، تصمیم‌های کلیدی، قراردادهای کدنویسی |
| [`docs/03-setup.md`](docs/03-setup.md) | راه‌اندازی نسخه .NET مرجع |
| [`docs/04-ticketing-integration.md`](docs/04-ticketing-integration.md) | اتصال به سامانه تیکتینگ PHP |
| [`docs/05-stack-decision.md`](docs/05-stack-decision.md) | سند تصمیم فنی پشته (آزمون‌های واقعی محیط) |
| [`backend/README.md`](backend/README.md) | راهنمای کامل پشته فعال (پایتون/جنگو) |
