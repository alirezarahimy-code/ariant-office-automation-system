"""پایش مهلت پاسخ: هشدار «نزدیک مهلت» و «متأخر» با اعلان درون‌سامانه‌ای.

الگوی مهلت/هشدار از SLA تیکتینگ (docs/14 بند ۱) — با تقویم شمسی و حذف تکرار.
اجرای زمان‌بندی‌شده: cron یا celery beat (هر ۶ ساعت).
"""
from datetime import date, timedelta

from django.conf import settings
from django.core.management.base import BaseCommand

from apps.core.models import Notification
from apps.secretariat.models import Letter, Referral

OPEN_EXCLUDED = ('draft', 'closed', 'archived')


class Command(BaseCommand):
    help = 'بررسی مهلت پاسخ نامه‌ها و صدور هشدار نزدیک‌مهلت/متأخر'

    def handle(self, *args, **options):
        today = date.today()
        warning_days = int(getattr(settings, 'SECRETARIAT_DEADLINE_WARNING_DAYS', 3))
        letters = Letter.objects.exclude(status__in=OPEN_EXCLUDED).filter(
            deadline__isnull=False).select_related('office')

        near = overdue = notified = 0
        for letter in letters:
            deadline = letter.deadline
            gregorian = deadline.togregorian() if hasattr(deadline, 'togregorian') else deadline
            if gregorian is None:
                continue
            if gregorian < today:
                kind = 'overdue'
                overdue += 1
            elif gregorian <= today + timedelta(days=warning_days):
                kind = 'near'
                near += 1
            else:
                continue

            recipients = set()
            if letter.created_by_id:
                recipients.add(letter.created_by_id)
            recipients.update(
                uid for uid in Referral.objects.filter(
                    letter=letter, status=Referral.Status.PENDING,
                ).values_list('to_user_id', flat=True) if uid)

            title = (f'نامه {letter.number_display} '
                     + ('از مهلت گذشت' if kind == 'overdue' else 'به مهلت نزدیک است'))
            url = f'/letters/{letter.pk}/'
            for uid in recipients:
                exists = Notification.objects.filter(
                    user_id=uid, title=title, is_read=False).exists()
                if exists:
                    continue
                Notification.objects.create(
                    user_id=uid, title=title, body=letter.subject[:200], url=url)
                notified += 1

        self.stdout.write(self.style.SUCCESS(
            f'متأخر: {overdue} — نزدیک مهلت: {near} — اعلان تازه: {notified}'))
