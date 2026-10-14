"""محاسبات تقویم کاری: تعطیلات رسمی، روز کاری و افزودن روز کاری.

پایه محاسبه مهلت‌ها و SLA — طبق قاعده نقشه راه (بند ۴ docs/01):
تعطیلات رسمی + روزهای تعطیل هفتگی از محاسبه کنار می‌روند.
"""
from __future__ import annotations

import jdatetime

from .models import Holiday


def is_holiday(jdate) -> bool:
    """آیا این تاریخ در تقویم تعطیلات رسمی ثبت شده است؟"""
    return Holiday.objects.filter(date=jdate).exists()


def is_workday(jdate, work_days=(0, 1, 2, 3, 4)) -> bool:
    """روز کاری = روزهای کاری هفته و نه تعطیل رسمی."""
    return jdate.weekday() in work_days and not is_holiday(jdate)


def next_workday(jdate, work_days=(0, 1, 2, 3, 4)):
    """نخستین روز کاری پس از تاریخ داده‌شده (خود تاریخ اگر کاری باشد)."""
    while not is_workday(jdate, work_days):
        jdate += jdatetime.timedelta(days=1)
    return jdate


def add_workdays(start, days: int, work_days=(0, 1, 2, 3, 4)):
    """افزودن n روز کاری به تاریخ — برای محاسبه مهلت اداری."""
    if days < 0:
        raise ValueError('تعداد روزهای کاری منفی نیست.')
    current = start
    remaining = days
    while remaining > 0:
        current += jdatetime.timedelta(days=1)
        if is_workday(current, work_days):
            remaining -= 1
    return current


def workdays_between(start, end, work_days=(0, 1, 2, 3, 4)) -> int:
    """شمار روزهای کاری در بازه [start, end] (شامل هر دو سر)."""
    if end < start:
        return 0
    count = 0
    current = start
    while current <= end:
        if is_workday(current, work_days):
            count += 1
        current += jdatetime.timedelta(days=1)
    return count
