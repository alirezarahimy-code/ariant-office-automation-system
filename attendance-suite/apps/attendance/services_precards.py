"""موتور اعمال پیش‌کارت بر اساس توالی قابل مشاهده در CIL مرجع T5Web."""
from datetime import timedelta

from django.db import transaction
from django.utils import timezone

from apps.attendance.models import PreCard, Punch
from apps.device.models import RawRecord
from apps.org.models import Employee


@transaction.atomic
def apply_pre_cards(date_from, date_to):
    """Mark same-device records in the validity window of each pre-card swipe.

    Static CIL shows: find the first record for each card badge in the selected
    date interval, then query records from one second after that swipe through
    ``ValidSecs``; retain only records from the card's machine, assign the
    selected LeaveType.StateKey to CheckType, and stop after the first target
    record when OnlyFirst is enabled.
    """
    if date_from > date_to:
        raise ValueError('بازهٔ تاریخ نامعتبر است.')

    result = {'cards_examined': 0, 'records_matched': 0, 'records_changed': 0}
    for card in PreCard.objects.select_related(
            'leave_type', 'device').order_by('card_badge_number'):
        result['cards_examined'] += 1
        seed = RawRecord.objects.filter(
            device=card.device,
            pin=str(card.card_badge_number),
            punch_time__date__gte=date_from,
            punch_time__date__lte=date_to,
        ).order_by('punch_time', 'pk').first()
        if seed is None:
            continue

        window_from = seed.punch_time + timedelta(seconds=1)
        window_to = seed.punch_time + timedelta(seconds=card.valid_secs)
        if window_to < window_from:
            continue
        records = RawRecord.objects.select_for_update().filter(
            device=card.device,
            punch_time__gte=window_from,
            punch_time__lte=window_to,
        ).order_by('punch_time', 'pk')
        if card.only_first:
            records = records[:1]

        for record in records:
            result['records_matched'] += 1
            state_key = card.leave_type.state_key
            if record.check_type == state_key:
                continue
            record.check_type = state_key
            record.save(update_fields=['check_type'])
            result['records_changed'] += 1

            employee = Employee.objects.filter(badge_no=record.pin).first()
            if employee is None:
                continue
            local_time = (timezone.localtime(record.punch_time)
                          if timezone.is_aware(record.punch_time)
                          else record.punch_time)
            Punch.objects.filter(
                employee=employee, device=record.device,
                date=local_time.date(), time=local_time.time(),
            ).update(check_type=state_key)
    return result
