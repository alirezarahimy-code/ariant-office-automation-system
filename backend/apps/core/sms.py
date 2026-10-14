"""لایه ارسال پیامک — جدا از کسب‌وکار، قابل تعویض با تنظیمات.

پیش‌فرض: ConsoleSMSBackend (فقط لاگ می‌زند؛ مناسب توسعه و آزمون).
برای اتصال به سرویس واقعی (کاوه‌نگار/ملی‌پیامک/…) یک کلاس با همان
امضا بنویسید و مسیرش را در SMS_BACKEND بگذارید.
"""
from __future__ import annotations

import logging

from django.conf import settings
from django.utils.module_loading import import_string

logger = logging.getLogger(__name__)


class ConsoleSMSBackend:
    """پیامک‌ها را لاگ می‌کند؛ هیچ هزینه/شبکه‌ای ندارد."""

    def send(self, to: str, text: str) -> bool:
        logger.info('SMS → %s: %s', to, text)
        return True


class RestSMSBackend:
    """قالب اتصال به سرویس پیامک REST — با کلید/گیرنده در تنظیمات.

    تنظیمات مورد نیاز: SMS_API_URL, SMS_API_KEY, SMS_FROM.
    این پیاده‌سازی عامدانه ساده است تا در محیط بدون اینترنت هم تست شود؛
    در استقرار واقعی کتابخانه سرویس‌دهنده جایگزین شود.
    """

    def send(self, to: str, text: str) -> bool:
        url = getattr(settings, 'SMS_API_URL', '')
        if not url:
            logger.warning('SMS_API_URL تنظیم نشده — پیامک به %s ارسال نشد', to)
            return False
        try:
            import requests
            resp = requests.post(url, json={
                'key': getattr(settings, 'SMS_API_KEY', ''),
                'sender': getattr(settings, 'SMS_FROM', ''),
                'receptor': to,
                'message': text,
            }, timeout=10)
            return bool(resp.ok)
        except Exception:  # noqa: BLE001 — ارسال پیامک هرگز نباید جریان کاری را بشکند
            logger.exception('خطای ارسال پیامک به %s', to)
            return False


_backend = None


def get_backend():
    """نمونه یکتای بک‌اند پیامک بر اساس SMS_BACKEND (مسیر نقطه‌ای)."""
    global _backend
    if _backend is None:
        path = getattr(settings, 'SMS_BACKEND', 'apps.core.sms.ConsoleSMSBackend')
        _backend = import_string(path)()
    return _backend


def reset_backend() -> None:
    """برای آزمون‌ها — بازنشانی بک‌اند کش‌شده."""
    global _backend
    _backend = None


def send_sms(to: str, text: str) -> bool:
    """ارسال یک پیامک؛ اگر SMS_ENABLED خاموش یا شماره خالی باشد، بی‌اثر."""
    if not getattr(settings, 'SMS_ENABLED', False):
        return False
    if not to or not str(to).strip():
        return False
    return bool(get_backend().send(str(to).strip(), text))
