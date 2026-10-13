# راهنمای استقرار تولید — فاز ۱

> هدف: بالا آوردن سامانه روی سرور کارفرما با PostgreSQL، Gunicorn، Nginx و زمان‌بندی عملیاتی. همه پیکربندی‌ها با متغیر محیطی است — هیچ رمزی در کد نیست.

---

## ۱. پیش‌نیاز سرور
- پایتون ۳٫۱۱+، PostgreSQL 14+، Nginx
- دسترسی فقط‌خواندنی به DB تیکتینگ (برای پل) و آدرس داخلی آن
- SMTP سازمانی (برای اعلان ایمیلی — اختیاری در شروع)

## ۲. نصب
```bash
git clone https://github.com/alirezarahimy-code/ariant-office-automation-system
cd ariant-office-automation-system/backend
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements/base.txt -r requirements/prod.txt
cp ../deploy/env.example /etc/ariant.env   # ویرایش مقادیر
```

## ۳. پیکربندی (`/etc/ariant.env`)
فایل نمونه: `deploy/env.example` — مهم‌ترین‌ها:
- `DJANGO_SECRET_KEY` تازه و تصادفی؛ `DJANGO_DEBUG=0`
- `DJANGO_ALLOWED_HOSTS` و `DJANGO_CSRF_TRUSTED_ORIGINS` = دامنه شما
- `DB_ENGINE=postgresql` (یا `mysql`) + متغیرهای `POSTGRES_*` (نام/کاربر/رمز/میزبان)
- `PLATFORM_ORG_NAME` = نام رسمی سازمان (سربرگ نامه‌ها و PDF)
- پل تیکتینگ: `TICKET_BRIDGE_URL`، `TICKET_BRIDGE_SSO_KEY` **چرخش‌شده**، و `TICKET_DB_*` با کاربر فقط‌خواندنی
- ایمیل: `NOTIFY_EMAIL=1` + `EMAIL_*`

## ۴. راه‌اندازی اولیه
```bash
set -a; source /etc/ariant.env; set +a
python3 manage.py migrate
python3 manage.py collectstatic --noinput
python3 manage.py setup_default_groups
python3 manage.py createsuperuser
# داده واقعی به‌جای دمو: از پنل ادمین واحدها/کاربران/دبیرخانه را بسازید
```

## ۵. سرویس و وب‌سرور
- systemd: نمونه `deploy/ariant.service` → `systemctl enable --now ariant`
- Nginx: نمونه `deploy/nginx.conf.sample` (پروکسی + فایل‌های static/media + HTTPS)
- HTTPS الزامی: گواهی را در Nginx terminating کنید

## ۶. زمان‌بندی عملیاتی (`deploy/cron.example`)
- `check_deadlines` هر ۶ ساعت — هشدار نزدیک‌مهلت/متأخر
- `sync_tickets` هر ۱۵ دقیقه — آینه فقط‌خواندنی تیکت‌ها
- `prune` و پشتیبان‌گیری: `pg_dump` روزانه + بازیابی‌آزمایی ماهانه

## ۷. چک‌لیست امنیتی پیش از پایلوت
- [ ] چرخش SECRET_KEY و SSO_KEY و رمزهای DB
- [ ] کاربر DB تیکتینگ فقط SELECT
- [ ] DEBUG=0 و ALLOWED_HOSTS محدود
- [ ] HTTPS فعال و HSTS
- [ ] LDAP (فاز ۲): پس از اتصال، سیاست رمز محلی سخت‌شده

## ۸. به‌روزرسانی نسخه‌ها
```bash
git pull && pip install -r requirements/base.txt -r requirements/prod.txt
python3 manage.py migrate && python3 manage.py collectstatic --noinput
sudo systemctl restart ariant
```

**پایان — ۱۴۰۵/۰۷/۲۰**
