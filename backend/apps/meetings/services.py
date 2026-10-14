"""سرویس‌های جلسات: صورت‌جلسه، امضا، نهایی‌سازی و تبدیل مصوبه به نامه/وظیفه."""
from __future__ import annotations

from django.utils import timezone

from .models import Meeting, Minutes, MinutesSignature, Resolution


def notify(user, title: str, body: str = '', url: str = '') -> None:
    """اعلان یکپارچه — واگذاری به سرویس مرکزی (اسپرینت ۵)."""
    from apps.core.services import send_notification
    send_notification(user, title, body, url)


def notify_attendees(meeting: Meeting, title: str, body: str = '') -> int:
    url = f'/meetings/{meeting.pk}/'
    count = 0
    for attendee in meeting.attendees.select_related('user'):
        notify(attendee.user, title, body, url)
        count += 1
    return count


def get_or_create_minutes(meeting: Meeting) -> Minutes:
    minutes, _ = Minutes.objects.get_or_create(meeting=meeting)
    return minutes


def sign_minutes(minutes: Minutes, user, position: str = '') -> MinutesSignature:
    signature, _ = MinutesSignature.objects.get_or_create(
        minutes=minutes, user=user, defaults={'position': position})
    return signature


def finalize_minutes(minutes: Minutes, user) -> Minutes | None:
    if minutes.is_final or not minutes.body.strip():
        return None
    minutes.is_final = True
    minutes.finalized_by = user
    minutes.finalized_at = timezone.now()
    minutes.save(update_fields=['is_final', 'finalized_by', 'finalized_at', 'updated_at'])
    notify_attendees(
        minutes.meeting, f'صورت‌جلسه «{minutes.meeting.title}» نهایی شد',
        minutes.body[:200])
    return minutes


def convert_resolution_to_letter(resolution: Resolution, user):
    """مصوبه → پیش‌نویس نامه صادره (گردش تأیید صادره فاز ۱ روی آن جاری است)."""
    from apps.secretariat.models import Office
    from apps.secretariat.services import register_letter
    if resolution.converted_letter_id:
        return None
    office = Office.objects.filter(is_active=True).order_by('pk').first()
    if office is None:
        return None
    body = (f'مصوبه شماره {resolution.number} جلسه «{resolution.meeting.title}»\n\n'
            f'{resolution.description}')
    letter = register_letter(
        office, 'outgoing', resolution.title, created_by=user,
        body=body, summary=f'برگرفته از مصوبه {resolution.number}')
    resolution.converted_letter = letter
    resolution.status = Resolution.Status.CONVERTED
    resolution.save(update_fields=['converted_letter', 'status', 'updated_at'])
    notify(user, 'نامه صادره از مصوبه ساخته شد',
           f'پیش‌نویس «{letter.subject}» ایجاد شد.', f'/letters/{letter.pk}/')
    return letter


def convert_resolution_to_task(resolution: Resolution, user):
    """مصوبه → وظیفه (اسپرینت ۴)."""
    from apps.tasks.services import create_task
    if resolution.converted_task_id:
        return None
    task = create_task(
        title=resolution.title, description=(
            f'مصوبه {resolution.number} جلسه «{resolution.meeting.title}»\n\n'
            f'{resolution.description}'),
        responsible=resolution.responsible or user,
        due_jalali=resolution.due_date,
        created_by=user,
        source_resolution_id=resolution.pk,
    )
    resolution.converted_task_id = task.pk
    if resolution.status == Resolution.Status.OPEN:
        resolution.status = Resolution.Status.CONVERTED
    resolution.save(update_fields=['converted_task_id', 'status', 'updated_at'])
    return task
