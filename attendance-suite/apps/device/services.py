"""خدمات دستگاه: دریافت رکورد (پل سازگار RecordTransfer) و تبدیل به تردد."""
from __future__ import annotations

import datetime as gregorian

from django.db import transaction
from django.utils import timezone

from apps.attendance.models import Punch
from apps.attendance.services import refresh_daily

from .models import Device, RawRecord


def _parse_dt(value: str) -> gregorian.datetime:
    """ورودی '2026-10-19 08:00:00' یا ISO."""
    text = (value or '').strip().replace('T', ' ')
    for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%d %H:%M', '%Y/%m/%d %H:%M:%S',
                '%Y/%m/%d %H:%M'):
        try:
            return gregorian.datetime.strptime(text, fmt)
        except ValueError:
            continue
    raise ValueError(f'قالب زمان نامعتبر: {value}')


@transaction.atomic
def ingest_records(device: Device, records: list[dict]) -> dict:
    """ثبت رکوردهای خام دستگاه (idempotent). هر رکورد: pin, time, verify_mode?

    قرارداد سازگار با Link5.RecordTransfer: انتقال دسته‌ای با وضعیت انتقال.
    """
    created = skipped = 0
    for item in records:
        pin = str(item.get('pin') or '').strip()
        punch_time = _parse_dt(item.get('time') or '')
        if not pin:
            raise ValueError('pin (کد پرسنلی دستگاه) الزامی است.')
        obj, was_created = RawRecord.objects.get_or_create(
            device=device, pin=pin, punch_time=punch_time,
            defaults={'verify_mode': int(item.get('verify_mode') or 0),
                      'work_code': str(item.get('work_code') or ''),
                      'payload': str(item.get('payload') or '')})
        if was_created:
            created += 1
        else:
            skipped += 1
    device.last_read_at = timezone.now()
    device.records_count += created
    device.save(update_fields=['last_read_at', 'records_count'])
    return {'created': created, 'skipped': skipped}


def convert_raw_to_punches(device: Device | None = None) -> dict:
    """تبدیل رکوردهای خام «تازه» به رویداد تردد (با تطبیق pin ← شماره کارگری)."""
    from apps.org.models import Employee
    qs = RawRecord.objects.filter(state=RawRecord.State.NEW)
    if device:
        qs = qs.filter(device=device)
    converted = errors = 0
    for raw in qs.select_related('device').order_by('punch_time'):
        employee = Employee.objects.filter(badge_no=raw.pin).first()
        if employee is None:
            raw.state = RawRecord.State.ERROR
            raw.save(update_fields=['state'])
            errors += 1
            continue
        dt = raw.punch_time
        if timezone.is_aware(dt):
            dt = timezone.localtime(dt)  # به وقت محلی (تهران)
        direction = Punch.Direction.OUT if dt.hour >= 12 else Punch.Direction.IN
        Punch.objects.get_or_create(
            employee=employee, date=dt.date(), time=dt.time(),
            defaults={'direction': direction, 'source': Punch.Source.DEVICE,
                      'device': raw.device,
                      'description': f'از رکورد خام {raw.pk}'})
        raw.state = RawRecord.State.TRANSFERED
        raw.save(update_fields=['state'])
        refresh_daily(employee, dt.date())
        converted += 1
    return {'converted': converted, 'errors': errors}
