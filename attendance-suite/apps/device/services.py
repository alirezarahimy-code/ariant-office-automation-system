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
            parsed = gregorian.datetime.strptime(text, fmt)
            return timezone.make_aware(parsed, timezone.get_current_timezone())
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


def _direction_for_record(raw: RawRecord, local_dt: gregorian.datetime) -> str:
    """Prefer explicit direction from UNIS; retain time-based fallback otherwise."""
    payload = raw.payload or ''
    direction_text = ''
    for part in payload.split('|'):
        if part.casefold().startswith('dir='):
            direction_text = part.split('=', 1)[1].strip().casefold()
            break
    if direction_text in {'i', 'in', 'entry', 'enter', 'checkin', 'ورود', 'داخل'}:
        return Punch.Direction.IN
    if direction_text in {'o', 'out', 'exit', 'leave', 'checkout', 'خروج', 'بیرون'}:
        return Punch.Direction.OUT
    # A number of vendor exports use numeric event codes whose meaning differs
    # by configuration. Do not guess those; preserve the existing fallback.
    return Punch.Direction.OUT if local_dt.hour >= 12 else Punch.Direction.IN


def convert_raw_to_punches(device: Device | None = None, *,
                           allowed_pins: set[str] | None = None) -> dict:
    """تبدیل رکوردهای خام تازه به تردد؛ در صورت نیاز، محدود به کدهای مجاز."""
    from apps.org.models import Employee
    qs = RawRecord.objects.filter(state=RawRecord.State.NEW)
    if device:
        qs = qs.filter(device=device)
    if allowed_pins is not None:
        qs = qs.filter(pin__in=allowed_pins)
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
        direction = _direction_for_record(raw, dt)
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
