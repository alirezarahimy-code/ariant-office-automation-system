# راهنمای نصب و راه‌اندازی سامانه یکپارچه حضور و غیاب

## پیش‌نیاز
- Python 3.11+ و pip

## نصب
```bash
pip install -r requirements.txt
python manage.py migrate
python manage.py seed_demo      # داده‌های نمایشی + نقش‌ها
python manage.py runserver 0.0.0.0:8000
```

## حساب‌های نمایشی
| کاربر | گذرواژه | نقش |
|---|---|---|
| admin | admin | مدیر سامانه |
| manager | manager | مدیر منابع انسانی |
| employee | employee | کارمند |

> در استقرار واقعی حتماً گذرواژه‌ها را تغییر دهید و `SUITE_SECRET_KEY` را از متغیر محیطی بگذارید.

## تنظیمات محیطی
| متغیر | پیش‌فرض | معنی |
|---|---|---|
| SUITE_SECRET_KEY | dev-only | کلید امنیتی (الزامی در production) |
| SUITE_DEBUG | 1 | حالت عیب‌یابی (در production = 0) |
| SUITE_HTTPS | 0 | فعال‌سازی کوکی امن + HSTS |
