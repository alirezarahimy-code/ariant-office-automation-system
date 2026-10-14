"""کانال‌های پیام‌رسان سازمانی (بله/ایتا) — لایه قابل تعویض مانند SMS.

پیش‌فرض: ConsoleChatBackend (فقط لاگ؛ توسعه و آزمون). برای اتصال واقعی،
کلاسی با همان امضا بنویسید و مسیرش را در CHAT_BALE_BACKEND/CHAT_ITA_BACKEND
بگذارید. هیچ خطای کانالی نباید جریان اعلان را بشکند.
"""
from __future__ import annotations

import json
import logging
import urllib.request

from django.conf import settings
from django.utils.module_loading import import_string

logger = logging.getLogger(__name__)


class ConsoleChatBackend:
    """پیام‌های پیام‌رسان را لاگ می‌کند؛ هیچ شبکه‌ای لازم ندارد."""

    def send(self, to: str, text: str) -> bool:
        logger.info('CHAT → %s: %s', to, text)
        return True


class RestChatBackend:
    """قالب اتصال به درگاه REST پیام‌رسان (بله/ایتا) — POST/JSON.

    تنظیمات: CHAT_API_URL, CHAT_API_TOKEN. این پیاده‌سازی ساده و بدون
    وابستگی است؛ در استقرار واقعی کتابخانه سرویس‌دهنده جایگزین می‌شود.
    """

    def __init__(self, api_url: str = '', token: str = ''):
        self.api_url = api_url or getattr(settings, 'CHAT_API_URL', '')
        self.token = token or getattr(settings, 'CHAT_API_TOKEN', '')

    def send(self, to: str, text: str) -> bool:
        if not self.api_url:
            logger.warning('CHAT_API_URL تنظیم نشده — پیام ارسال نشد.')
            return False
        payload = json.dumps({'to': to, 'text': text}).encode('utf-8')
        request = urllib.request.Request(
            self.api_url, data=payload, method='POST',
            headers={'Content-Type': 'application/json',
                     'Authorization': f'Bearer {self.token}'})
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                return 200 <= response.status < 300
        except Exception:  # noqa: BLE001 — شبکه/سرویس نباید اعلان را بشکند
            logger.exception('خطای ارسال پیام به %s', to)
            return False


def send_chat(backend_setting: str, to: str, text: str, **kwargs) -> bool:
    """ارسال پیام با بک‌اند مسیریابی‌شده از تنظیمات (الگوی send_sms)."""
    if not to:
        return False
    backend_path = getattr(settings, backend_setting, '')
    if not backend_path:
        return False
    try:
        backend_cls = import_string(backend_path)
        backend = backend_cls(**kwargs) if kwargs else backend_cls()
        return bool(backend.send(to, text))
    except Exception:  # noqa: BLE001
        logger.exception('خطای کانال گفت‌وگو (%s)', backend_setting)
        return False
