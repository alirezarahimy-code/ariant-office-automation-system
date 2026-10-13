"""توکن SSO بین پلتفرم و سرویس‌های متصل.

قرارداد (مأخوذ از قرارداد اثبات‌شده سرویس غذا در سامانه تیکتینگ — docs/14 بند ۷):
- payload شامل user_id، role، service_group، exp (و اختیاری return_url)
- امضای HMAC-SHA256 با کلید مشترک
- توکن کوتاه‌مدت؛ اعتبارنامه هرگز در URL/LocalStorage سرویس مقصد نمی‌ماند
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import time


def _b64e(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode('ascii').rstrip('=')


def _b64d(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + '=' * (-len(text) % 4))


def build_sso_token(user_id: int, role: str, service_group: str, secret: str,
                    ttl: int = 120, return_url: str = '') -> str:
    payload = {
        'user_id': int(user_id),
        'role': role,
        'service_group': service_group,
        'exp': int(time.time()) + int(ttl),
    }
    if return_url:
        payload['return_url'] = return_url
    body = _b64e(json.dumps(payload, separators=(',', ':'), ensure_ascii=False).encode('utf-8'))
    signature = _b64e(hmac.new(secret.encode('utf-8'), body.encode('ascii'), hashlib.sha256).digest())
    return f'{body}.{signature}'


def verify_sso_token(token: str, secret: str, now: int | None = None) -> dict | None:
    """اعتبارسنجی توکن؛ در صورت موفقیت payload وگرنه None."""
    if not token or '.' not in token:
        return None
    body, _, signature = token.partition('.')
    expected = _b64e(hmac.new(secret.encode('utf-8'), body.encode('ascii'), hashlib.sha256).digest())
    if not hmac.compare_digest(signature, expected):
        return None
    try:
        payload = json.loads(_b64d(body).decode('utf-8'))
    except (ValueError, UnicodeDecodeError):
        return None
    if int(payload.get('exp', 0)) < (now if now is not None else int(time.time())):
        return None
    return payload
