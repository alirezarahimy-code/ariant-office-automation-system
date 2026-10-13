"""برچسب‌های قالب: ارقام فارسی، تاریخ شمسی و نگاشت رنگ وضعیت‌ها."""
from __future__ import annotations

import jdatetime
from django import template
from django.utils import timezone

from apps.core.persian import to_persian_digits

register = template.Library()


def _to_jdate(value):
    """هر مقدار تاریخ (میلادی/شمسی/رشته) → jdatetime.date"""
    if value is None or value == '':
        return None
    if isinstance(value, jdatetime.datetime):
        return value.date()
    if isinstance(value, jdatetime.date):
        return value
    if hasattr(value, 'year') and hasattr(value, 'month'):  # datetime/date میلادی
        if hasattr(value, 'date') and not hasattr(value, 'j_year'):
            value = value.date() if hasattr(value, 'hour') else value
        try:
            return jdatetime.date.fromgregorian(date=value)
        except (TypeError, ValueError):
            return None
    return None


@register.filter
def fa(value):
    """ارقام فارسی برای هر عدد/رشته."""
    if value is None:
        return ''
    return to_persian_digits(str(value))


@register.filter
def jdate(value):
    """نمایش تاریخ شمسی: ۱۴۰۵/۰۷/۲۰"""
    j = _to_jdate(value)
    return to_persian_digits(j.strftime('%Y/%m/%d')) if j else ''


@register.filter
def jalali_datetime(value):
    """نمایش تاریخ‌زمان شمسی: ۱۴۰۵/۰۷/۲۰ — ۱۴:۳۲"""
    if not value:
        return ''
    if timezone.is_aware(value):
        value = timezone.localtime(value)
    j = jdatetime.datetime.fromgregorian(datetime=value)
    return to_persian_digits(j.strftime('%Y/%m/%d — %H:%M'))


@register.filter
def status_pill(value):
    """کلاس رنگ قرص برای وضعیت نامه/ارجاع."""
    return {
        'draft': 'gray', 'registered': 'info', 'in_review': 'violet',
        'referred': 'info', 'in_progress': 'warn', 'waiting_user': 'warn',
        'answered': 'ok', 'sent': 'teal', 'closed': 'gray', 'archived': 'gray',
        'pending': 'warn', 'acted': 'ok', 'returned': 'bad',
    }.get(value, 'gray')


@register.filter
def priority_pill(value):
    return {'normal': 'gray', 'urgent': 'warn', 'immediate': 'bad'}.get(value, 'gray')


@register.filter
def conf_pill(value):
    return {'normal': 'gray', 'internal': 'info',
            'confidential': 'bad', 'secret': 'bad'}.get(value, 'gray')


@register.filter
def deadline_kind(value):
    """رنگ مهلت: bad=گذشته، warn=نزدیک (≤۳ روز)، ok=آینده."""
    from datetime import date as _date, timedelta
    j = _to_jdate(value)
    if not j:
        return ''
    try:
        g = j.togregorian()
    except (AttributeError, ValueError):
        return ''
    today = _date.today()
    if g < today:
        return 'bad'
    if g <= today + timedelta(days=3):
        return 'warn'
    return 'ok'


@register.filter
def filesize_mb(value):
    try:
        mb = int(value) / (1024 * 1024)
    except (TypeError, ValueError):
        return ''
    return to_persian_digits(f'{mb:.1f}') + ' مگابایت'
