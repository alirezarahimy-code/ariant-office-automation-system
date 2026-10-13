# backend — سامانه اتوماسیون اداری آرینت (پایتون/جنگو)

پشته فعال سامانه: **Django 5.2 + Django REST Framework** با پنل مدیریت فارسی/راست‌به‌چپ.

## راه‌اندازی سریع

```bash
python -m venv .venv
source .venv/bin/activate          # ویندوز: .venv\Scripts\activate
pip install -r requirements/base.txt
python manage.py migrate
python manage.py seed_demo         # داده نمونه + کاربر admin/Admin@1404
python manage.py runserver
```

## اجرای آزمون‌ها

```bash
python manage.py test              # همه آزمون‌ها
python manage.py test apps.core    # فقط آزمون‌های تقویم و متن فارسی
```

## دستورهای مهم

| دستور | کار |
| --- | --- |
| `python manage.py migrate` | اعمال مهاجرت‌های پایگاه‌داده |
| `python manage.py makemigrations` | ساخت مهاجرت پس از تغییر مدل‌ها |
| `python manage.py seed_demo` | داده‌های نمونه + کاربر مدیر |
| `python manage.py createsuperuser` | ساخت کاربر مدیر جدید |
| `python manage.py collectstatic` | جمع‌آوری فایل‌های ایستا (تولید) |

## تنظیمات تولید (متغیرهای محیطی)

| متغیر | مقدار پیش‌فرض | توضیح |
| --- | --- | --- |
| `DJANGO_SECRET_KEY` | (توسعه) | **در تولید حتماً تنظیم شود** |
| `DJANGO_DEBUG` | `1` | در تولید: `0` |
| `DJANGO_ALLOWED_HOSTS` | `*` | نام دامنه‌ها با ویرگول |
| `DB_ENGINE` | `sqlite` | `postgresql` یا `mysql` |
| `POSTGRES_DB/USER/PASSWORD/HOST/PORT` | — | اتصال PostgreSQL |
| `MYSQL_DB/USER/PASSWORD/HOST/PORT` | — | اتصال MySQL |
| `JWT_SIGNING_KEY` | (توسعه) | کلید مشترک نشانه JWT با سامانه تیکتینگ PHP |

مثال اجرا در تولید:

```bash
gunicorn config.wsgi:application --bind 0.0.0.0:8000 --workers 4
```

## ساختار برنامه‌ها

```
backend/
├── config/            # تنظیمات، نشانی‌ها، WSGI/ASGI
├── apps/
│   ├── core/          # تقویم شمسی، متن فارسی، حذف نرم، مدل‌های پایه
│   │   └── testdata/  # ۲۲۰ بردار طلایی تقویم (تولیدشده از ICU — مستقل)
│   └── organization/  # واحدهای درختی، کارمندان، وب‌سرویس v1
└── requirements/      # base / dev / prod (نسخه‌ها قفل‌شده)
```

## قرارداد وب‌سرویس نسخه ۱

| مسیر | روش | مجوز | توضیح |
| --- | --- | --- | --- |
| `/api/v1/health` | GET | عمومی | سلامت سامانه |
| `/api/v1/auth/token` | POST | عمومی | دریافت نشانه JWT |
| `/api/v1/auth/token/refresh` | POST | عمومی | تمدید نشانه |
| `/api/v1/auth/me` | GET | کاربر | هویت کاربر جاری |
| `/api/v1/departments` | GET/POST/… | کاربر | واحدهای سازمانی |
| `/api/v1/employees` | GET/POST/… | کاربر | کارمندان |

پشتیبانی جست‌وجو (`?search=`)، فیلتر (`?is_active=true`) و صفحه‌بندی روی همه فهرست‌ها فعال است.

## نکته‌های نگهداری

- پس از هر تغییر مدل: `makemigrations` را اجرا و فایل مهاجرت را **کامیت** کنید (CI آن را بررسی می‌کند).
- ستون‌های درخت MPTT به‌عمد از ممیزی حذف شده‌اند؛ ممیزی فقط داده کسب‌وکار را ثبت می‌کند.
- حذف‌ها نرم هستند؛ رکوردهای حذف‌شده با `Model.all_objects` قابل بازیابی‌اند.
