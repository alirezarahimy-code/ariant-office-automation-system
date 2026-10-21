"""تنظیمات سامانه یکپارچه حضور و غیاب — مستقل از اتوماسیون اداری."""
import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent

SECRET_KEY = os.environ.get(
    'SUITE_SECRET_KEY', 'dev-only-change-me-in-production')
DEBUG = os.environ.get('SUITE_DEBUG', '1') == '1'
ALLOWED_HOSTS = ['*']

INSTALLED_APPS = [
    'django.contrib.admin',
    'django.contrib.auth',
    'django.contrib.contenttypes',
    'django.contrib.sessions',
    'django.contrib.messages',
    'django.contrib.staticfiles',
    'apps.core',
    'apps.org',
    'apps.device',
    'apps.attendance',
    'apps.reports',
    'apps.t5web.apps.T5WebConfig',
]

MIDDLEWARE = [
    'django.middleware.security.SecurityMiddleware',
    'django.contrib.sessions.middleware.SessionMiddleware',
    'django.middleware.common.CommonMiddleware',
    'django.middleware.csrf.CsrfViewMiddleware',
    'django.contrib.auth.middleware.AuthenticationMiddleware',
    'apps.core.middleware.T5ProgramAccessMiddleware',
    'django.contrib.messages.middleware.MessageMiddleware',
    'django.middleware.clickjacking.XFrameOptionsMiddleware',
]

ROOT_URLCONF = 'config.urls'

TEMPLATES = [{
    'BACKEND': 'django.template.backends.django.DjangoTemplates',
    'DIRS': [BASE_DIR / 'templates'],
    'APP_DIRS': True,
    'OPTIONS': {
        'context_processors': [
            'django.template.context_processors.request',
            'django.contrib.auth.context_processors.auth',
            'django.contrib.messages.context_processors.messages',
            'apps.t5web.menu.t5web_navigation',
        ],
    },
}]

WSGI_APPLICATION = 'config.wsgi.application'

DATABASES = {
    'default': {
        'ENGINE': 'django.db.backends.sqlite3',
        'NAME': BASE_DIR / 'db.sqlite3',
    }
}

AUTH_USER_MODEL = 'core.User'

LANGUAGE_CODE = 'fa-ir'
TIME_ZONE = 'Asia/Tehran'
USE_I18N = True
USE_TZ = True

STATIC_URL = 'static/'
STATICFILES_DIRS = [BASE_DIR / 'static']
LOGIN_URL = 'core:login'
LOGIN_REDIRECT_URL = 'core:home_index'
LOGOUT_REDIRECT_URL = 'core:login'
# Optional dotted class path implementing the trusted vendor license contract.
T5_LICENSE_CHECKER = os.environ.get('T5_LICENSE_CHECKER', '').strip()
# The T5 3.5.7 web.config enables its login CAPTCHA by default.
LOGIN_CAPTCHA_ENABLED = os.environ.get('SUITE_LOGIN_CAPTCHA', '1') == '1'

DEFAULT_AUTO_FIELD = 'django.db.models.BigAutoField'

# --- امنیت نشست و کوکی ---
SESSION_COOKIE_HTTPONLY = True
CSRF_COOKIE_HTTPONLY = True
SESSION_COOKIE_SAMESITE = 'Lax'
CSRF_COOKIE_SAMESITE = 'Lax'
if os.environ.get('SUITE_HTTPS', '0') == '1':
    SESSION_COOKIE_SECURE = True
    CSRF_COOKIE_SECURE = True
    SECURE_SSL_REDIRECT = True
    SECURE_HSTS_SECONDS = 86400

# محدودسازی نرخ ورود (در services)
LOGIN_RATE_LIMIT = 5


# --- پل یونیس (بانک نرم‌افزار UNIS ویردی) ---
# اتصال بانک یونیس با متغیرهای محیطی (راهنمای 05) — رمز فقط از env، هرگز در کد/لاگ:
#   UNIS_DB_ENGINE=mssql|mysql|oracle|sqlite   UNIS_DB_NAME=UNIS   UNIS_DB_HOST=...
#   UNIS_DB_USER=...   UNIS_DB_PASSWORD=...
# بدون env: پیش‌فرض SQLite بی‌اثر (فقط برای سلامت ساختار) — تا پیکربندی نشود خوانده نمی‌شود.
_unis_engine_name = os.environ.get('UNIS_DB_ENGINE', 'sqlite').lower()
if _unis_engine_name == 'access':
    # بانک Access نرم‌افزار یونیس (unis.mdb) — بدون درایور جنگو؛ خواندن با pyodbc.
    # رمز بانک فقط از env (UNIS_DB_PASSWORD) — هرگز در کد/لاگ/UI.
    UNIS_ACCESS = {
        'name': os.environ.get('UNIS_DB_NAME', ''),
        'password': os.environ.get('UNIS_DB_PASSWORD', ''),
    }
    _unis_engine_name = 'sqlite'  # alias رسمی جنگو فقط برای سلامت ساختار/تست

_unis_engine = {
    'mssql': 'mssql_django', 'sqlserver': 'mssql_django',
    'mysql': 'django.db.backends.mysql',
    'oracle': 'django.db.backends.oracle',
    'sqlite': 'django.db.backends.sqlite3',
}[_unis_engine_name]
DATABASES['unis'] = {
    'ENGINE': _unis_engine,
    'NAME': os.environ.get('UNIS_DB_NAME', str(BASE_DIR / 'db_unis.sqlite3')),
    'HOST': os.environ.get('UNIS_DB_HOST', ''),
    'PORT': os.environ.get('UNIS_DB_PORT', ''),
    'USER': os.environ.get('UNIS_DB_USER', ''),
    'PASSWORD': os.environ.get('UNIS_DB_PASSWORD', ''),
}
if _unis_engine == 'mssql_django':
    DATABASES['unis']['OPTIONS'] = {
        'driver': os.environ.get(
            'UNIS_DB_DRIVER', 'ODBC Driver 17 for SQL Server')}
