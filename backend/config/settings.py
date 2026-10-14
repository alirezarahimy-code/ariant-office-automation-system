"""تنظیمات پروژه سامانه اتوماسیون اداری آرینت.

نکته امنیتی: تمام تنظیمات حساس از متغیرهای محیطی خوانده می‌شوند.
در محیط تولید حتماً DJANGO_SECRET_KEY و JWT_SIGNING_KEY را تنظیم کنید.
"""
import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent

# ---------------------------------------------------------------------------
# تنظیمات پایه
# ---------------------------------------------------------------------------
SECRET_KEY = os.environ.get(
    'DJANGO_SECRET_KEY', 'dev-only-insecure-key-change-me-in-production')
DEBUG = os.environ.get('DJANGO_DEBUG', '1') == '1'
ALLOWED_HOSTS = [
    h.strip() for h in os.environ.get('DJANGO_ALLOWED_HOSTS', '*').split(',')
    if h.strip()
]

INSTALLED_APPS = [
    'django.contrib.admin',
    'django.contrib.auth',
    'django.contrib.contenttypes',
    'django.contrib.sessions',
    'django.contrib.messages',
    'django.contrib.staticfiles',
    # بسته‌های شخص ثالث
    'rest_framework',
    'rest_framework_simplejwt',
    'django_filters',
    'simple_history',
    'mptt',
    'django_jalali',
    # برنامه‌های سامانه
    'apps.core',
    'apps.organization',
    'apps.secretariat',
    'apps.ticket_bridge',
    # فاز ۲ — زیرسامانه‌های بستر
    'apps.workflow',
    'apps.archive',
    'apps.meetings',
    'apps.tasks',
    'apps.reports',
    'apps.tickets',
    'apps.registry',
    # فاز ۳ — زیرسامانه‌های گام-۰
    'apps.attendance',
    'apps.inventory',
]

MIDDLEWARE = [
    'django.middleware.security.SecurityMiddleware',
    'whitenoise.middleware.WhiteNoiseMiddleware',
    'django.contrib.sessions.middleware.SessionMiddleware',
    'django.middleware.common.CommonMiddleware',
    'django.middleware.csrf.CsrfViewMiddleware',
    'django.contrib.auth.middleware.AuthenticationMiddleware',
    'django.contrib.messages.middleware.MessageMiddleware',
    'django.middleware.clickjacking.XFrameOptionsMiddleware',
    # ثبت کاربرِ ایجادکننده تغییر در تاریخچه ممیزی
    'simple_history.middleware.HistoryRequestMiddleware',
]

ROOT_URLCONF = 'config.urls'

TEMPLATES = [
    {
        'BACKEND': 'django.template.backends.django.DjangoTemplates',
        'DIRS': [BASE_DIR / 'templates'],
        'APP_DIRS': True,
        'OPTIONS': {
            'context_processors': [
                'django.template.context_processors.request',
                'django.contrib.auth.context_processors.auth',
                'apps.core.context_processors.platform_context',
                'django.contrib.messages.context_processors.messages',
            ],
        },
    },
]

WSGI_APPLICATION = 'config.wsgi.application'

# ---------------------------------------------------------------------------
# پایگاه داده — پیش‌فرض SQLite برای توسعه؛ تولید با متغیر محیطی
# ---------------------------------------------------------------------------
# DB_ENGINE: sqlite | postgresql | mysql
DB_ENGINE = os.environ.get('DB_ENGINE', 'sqlite').lower()

if DB_ENGINE == 'postgresql':
    DATABASES = {
        'default': {
            'ENGINE': 'django.db.backends.postgresql',
            'NAME': os.environ.get('POSTGRES_DB', 'ariant'),
            'USER': os.environ.get('POSTGRES_USER', 'ariant'),
            'PASSWORD': os.environ.get('POSTGRES_PASSWORD', ''),
            'HOST': os.environ.get('POSTGRES_HOST', '127.0.0.1'),
            'PORT': os.environ.get('POSTGRES_PORT', '5432'),
            'CONN_MAX_AGE': 60,
        }
    }
elif DB_ENGINE == 'mysql':
    DATABASES = {
        'default': {
            'ENGINE': 'django.db.backends.mysql',
            'NAME': os.environ.get('MYSQL_DB', 'ariant'),
            'USER': os.environ.get('MYSQL_USER', 'ariant'),
            'PASSWORD': os.environ.get('MYSQL_PASSWORD', ''),
            'HOST': os.environ.get('MYSQL_HOST', '127.0.0.1'),
            'PORT': os.environ.get('MYSQL_PORT', '3306'),
            'CONN_MAX_AGE': 60,
            'OPTIONS': {'charset': 'utf8mb4'},
        }
    }
else:
    DATABASES = {
        'default': {
            'ENGINE': 'django.db.backends.sqlite3',
            'NAME': BASE_DIR / 'db.sqlite3',
        }
    }

# ---------------------------------------------------------------------------
# هویت و احراز هویت
# ---------------------------------------------------------------------------
AUTH_PASSWORD_VALIDATORS = [
    {'NAME': 'django.contrib.auth.password_validation.UserAttributeSimilarityValidator'},
    {'NAME': 'django.contrib.auth.password_validation.MinimumLengthValidator'},
    {'NAME': 'django.contrib.auth.password_validation.CommonPasswordValidator'},
    {'NAME': 'django.contrib.auth.password_validation.NumericPasswordValidator'},
]

# ---------------------------------------------------------------------------
# بین‌المللی‌سازی — فارسی، ایران، تقویم شمسی در نمایش
# ---------------------------------------------------------------------------
LANGUAGE_CODE = 'fa'
TIME_ZONE = 'Asia/Tehran'
USE_I18N = True
USE_L10N = True
USE_TZ = True

LANGUAGES = [
    ('fa', 'فارسی'),
    ('en', 'English'),
]

# ---------------------------------------------------------------------------
# فایل‌های ایستا
# ---------------------------------------------------------------------------
STATIC_URL = 'static/'
STATIC_ROOT = BASE_DIR / 'staticfiles'
STATICFILES_DIRS = [BASE_DIR / 'static']
STORAGES = {
    'default': {'BACKEND': 'django.core.files.storage.FileSystemStorage'},
    'staticfiles': {'BACKEND': 'whitenoise.storage.CompressedManifestStaticFilesStorage'},
}

DEFAULT_AUTO_FIELD = 'django.db.models.BigAutoField'

# ---------------------------------------------------------------------------
# وب‌سرویس (DRF) — قرارداد سازگار با پل تیکتینگ (docs/04)
# ---------------------------------------------------------------------------
REST_FRAMEWORK = {
    'DEFAULT_AUTHENTICATION_CLASSES': [
        'rest_framework_simplejwt.authentication.JWTAuthentication',
        'rest_framework.authentication.SessionAuthentication',
    ],
    'DEFAULT_PERMISSION_CLASSES': [
        'rest_framework.permissions.IsAuthenticated',
    ],
    'DEFAULT_FILTER_BACKENDS': [
        'django_filters.rest_framework.DjangoFilterBackend',
        'rest_framework.filters.SearchFilter',
        'rest_framework.filters.OrderingFilter',
    ],
    'DEFAULT_PAGINATION_CLASS': 'rest_framework.pagination.PageNumberPagination',
    'PAGE_SIZE': 25,
}

from datetime import timedelta  # noqa: E402

SIMPLE_JWT = {
    # کلید مشترک با سامانه تیکتینگ PHP (docs/04 — سناریو SSO)
    'SIGNING_KEY': os.environ.get(
        'JWT_SIGNING_KEY', 'dev-only-jwt-signing-key-change-me-32chars!'),
    'ALGORITHM': 'HS256',
    'ACCESS_TOKEN_LIFETIME': timedelta(minutes=10),
    'REFRESH_TOKEN_LIFETIME': timedelta(days=1),
    'AUTH_HEADER_TYPES': ('Bearer',),
}

# ---------------------------------------------------------------------------
# ممیزی تغییرات (django-simple-history)
# ---------------------------------------------------------------------------
SIMPLE_HISTORY_HISTORY_CHANGE_REASON_USE_TEXT_FIELD = True

# ---------------------------------------------------------------------------
# ورودی‌های تاریخ — پذیرش هم شمسی و هم میلادی در فرم‌ها
# ---------------------------------------------------------------------------
DATE_INPUT_FORMATS = [
    '%Y-%m-%d', '%d/%m/%Y', '%Y/%m/%d', '%d-%m-%Y',
]

# --- رسانه و پیوست‌ها ---
MEDIA_URL = '/media/'
MEDIA_ROOT = BASE_DIR / 'media'

# --- سیاست پیوست دبیرخانه (docs/10 بند ۱٫۵) ---
SECRETARIAT_ATTACHMENT_MAX_MB = 25
SECRETARIAT_ATTACHMENT_ALLOWED_EXTENSIONS = [
    'pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff',
    'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
]

# --- آزمون: بدون manifest استاتیک (صفحه‌ها در تست رندر شوند) ---
import sys
TESTING = 'test' in sys.argv
if TESTING and 'STORAGES' in globals():
    STORAGES['staticfiles'] = {'BACKEND': 'django.contrib.staticfiles.storage.StaticFilesStorage'}

# --- احراز هویت وب ---
LOGIN_URL = 'accounts:login'
LOGIN_REDIRECT_URL = 'secretariat:kartable'
LOGOUT_REDIRECT_URL = 'accounts:login'

# --- هویت سازمان (سربرگ نامه‌ها) ---
PLATFORM_ORG_NAME = os.environ.get('PLATFORM_ORG_NAME', 'سازمان آرینت')
PLATFORM_ORG_MOTTO = os.environ.get('PLATFORM_ORG_MOTTO', 'اتوماسیون اداری یکپارچه')

# --- دبیرخانه ---
SECRETARIAT_DEADLINE_WARNING_DAYS = 3

# --- پل تیکتینگ (docs/04 + قرارداد SSO در docs/14 بند ۷) ---
TICKET_BRIDGE = {
    'system': 'php-ticketing',
    'base_url': os.environ.get('TICKET_BRIDGE_URL', ''),
    'sso_key': os.environ.get('TICKET_BRIDGE_SSO_KEY', 'dev-secret-change-me'),
    'fixture': str(BASE_DIR / 'apps' / 'ticket_bridge' / 'fixtures' / 'sample_tickets.json'),
    'db': {
        'host': os.environ.get('TICKET_DB_HOST', ''),
        'port': int(os.environ.get('TICKET_DB_PORT', '3306')),
        'name': os.environ.get('TICKET_DB_NAME', 'persian_ticketing'),
        'user': os.environ.get('TICKET_DB_USER', ''),
        'password': os.environ.get('TICKET_DB_PASSWORD', ''),
    },
}

# --- اعلان ایمیلی (زیرساخت فاز ۲ — اسپرینت ۵ زودتر تحویل شد) ---
NOTIFICATIONS_EMAIL_ENABLED = os.environ.get('NOTIFY_EMAIL', '0') == '1'
EMAIL_HOST = os.environ.get('EMAIL_HOST', 'localhost')
EMAIL_PORT = int(os.environ.get('EMAIL_PORT', '25'))
EMAIL_HOST_USER = os.environ.get('EMAIL_HOST_USER', '')
EMAIL_HOST_PASSWORD = os.environ.get('EMAIL_HOST_PASSWORD', '')
EMAIL_USE_TLS = os.environ.get('EMAIL_USE_TLS', '0') == '1'
DEFAULT_FROM_EMAIL = os.environ.get('EMAIL_FROM', 'automation@localhost')

# ===== اعلان چندکاناله (فاز ۲) =====
PLATFORM_NAME = os.environ.get('PLATFORM_NAME', 'سامانه اتوماسیون اداری آرینت')
SMS_ENABLED = os.environ.get('SMS_ENABLED', '0') == '1'
SMS_BACKEND = os.environ.get('SMS_BACKEND', 'apps.core.sms.ConsoleSMSBackend')
SMS_FROM = os.environ.get('SMS_FROM', '')
SMS_API_URL = os.environ.get('SMS_API_URL', '')
SMS_API_KEY = os.environ.get('SMS_API_KEY', '')

# ===== پل تیکتینگ (فاز ۲) =====
# bridge = فقط پل به سامانه PHP (پیش‌فرض) | native = زیرسامانه بومی فعال
TICKET_MODE = os.environ.get('TICKET_MODE', 'bridge')
