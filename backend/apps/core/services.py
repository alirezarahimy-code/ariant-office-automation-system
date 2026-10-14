"""سرویس‌های مرکزی اعلان — نقطه ورود یکتای همه زیرسامانه‌ها.

هر زیرسامانه (دبیرخانه، گردش کار، بایگانی، جلسات، وظایف، تیکت‌ها)
فقط send_notification را صدا می‌زند؛ کانال‌ها (درون‌سامانه‌ای/ایمیل/پیامک)
اینجا و بر اساس تنظیمات تصمیم گرفته می‌شوند. هیچ خطای کانالی نباید
جریان کاری را بشکند.
"""
from __future__ import annotations

import logging

from django.conf import settings
from django.core.mail import EmailMultiAlternatives
from django.template.loader import render_to_string

from .models import Notification
from .sms import send_sms

logger = logging.getLogger(__name__)


def _send_email(user, title: str, body: str, url: str) -> bool:
    if not getattr(settings, 'NOTIFICATIONS_EMAIL_ENABLED', False):
        return False
    email = getattr(user, 'email', '') or ''
    if not email:
        return False
    ctx = {
        'title': title,
        'body': body,
        'url': url,
        'user': user,
        'site_name': getattr(settings, 'PLATFORM_NAME', 'سامانه اتوماسیون اداری'),
    }
    try:
        html = render_to_string('core/emails/notification.html', ctx)
        text = f'{title}\n\n{body}\n' + (f'\n{url}' if url else '')
        msg = EmailMultiAlternatives(
            subject=title, body=text,
            from_email=getattr(settings, 'DEFAULT_FROM_EMAIL', 'noreply@ariant.local'),
            to=[email])
        msg.attach_alternative(html, 'text/html')
        msg.send(fail_silently=False)
        return True
    except Exception:  # noqa: BLE001
        logger.exception('خطای ارسال ایمیل اعلان به %s', email)
        return False


def _send_sms(user, title: str, body: str) -> bool:
    if not getattr(settings, 'SMS_ENABLED', False):
        return False
    employee = getattr(user, 'employee_profile', None)
    phone = getattr(employee, 'phone', '') if employee else ''
    if not phone:
        return False
    text = f'{title}\n{body}'.strip()[:300]
    return send_sms(phone, text)


def _send_chat(user, title: str, body: str, url: str = '') -> None:
    """کانال‌های پیام‌رسان سازمانی (بله/ایتا) — هر خطا بی‌صدا لاگ می‌شود."""
    from apps.core.notify_channels import send_chat
    employee = getattr(user, 'employee_profile', None)
    profile = getattr(user, 'profile', None)
    chat_id = ''
    if employee is not None and getattr(employee, 'phone', ''):
        chat_id = employee.phone
    elif profile is not None and profile.phone:
        chat_id = profile.phone
    if not chat_id:
        return
    text = f'{title}\n{body}\n{url}'.strip()[:1000]
    if getattr(settings, 'NOTIFICATIONS_BALE_ENABLED', False):
        send_chat('CHAT_BALE_BACKEND', chat_id, text)
    if getattr(settings, 'NOTIFICATIONS_ITA_ENABLED', False):
        send_chat('CHAT_ITA_BACKEND', chat_id, text)


def send_notification(user, title: str, body: str = '', url: str = '') -> Notification:
    """اعلان چندکاناله: درون‌سامانه‌ای (همیشه) + ایمیل/پیامک (بر اساس تنظیمات)."""
    notification = Notification.objects.create(
        user=user, title=title[:200], body=body[:1000], url=url[:300])
    _send_email(user, title, body, url)
    try:
        _send_sms(user, title, body)
    except Exception:  # noqa: BLE001 — خطای پیامک هرگز نباید اعلان را بشکند
        logger.exception('خطای ارسال پیامک اعلان برای کاربر %s', user.pk)
    try:
        _send_chat(user, title, body, url)
    except Exception:  # noqa: BLE001 — خطای پیام‌رسان هرگز نباید اعلان را بشکند
        logger.exception('خطای ارسال پیام‌رسان اعلان برای کاربر %s', user.pk)
    return notification
