"""بایگانی دسته‌ای نامه‌های بسته‌شده/بایگانی‌شده فاقد سابقه — اسپرینت ۲."""
from django.core.management.base import BaseCommand

from apps.archive.services import archive_object, folder_for_letter
from apps.secretariat.models import Letter

CLOSED_STATUSES = (Letter.Status.CLOSED, Letter.Status.ARCHIVED)


class Command(BaseCommand):
    help = 'بایگانی خودکار همه نامه‌های بسته‌شده (idempotent)'

    def add_arguments(self, parser):
        parser.add_argument('--dry-run', action='store_true', help='فقط گزارش، بدون نوشتن')

    def handle(self, *args, **options):
        letters = Letter.objects.filter(status__in=CLOSED_STATUSES).select_related('office')
        new = skipped = 0
        for letter in letters:
            folder = folder_for_letter(letter)
            if options['dry_run']:
                new += 1
                continue
            _, created = archive_object(letter, folder)
            new += int(created)
            skipped += int(not created)
        mode = ' (dry-run)' if options['dry_run'] else ''
        self.stdout.write(self.style.SUCCESS(
            f'بایگانی{mode}: {letters.count()} نامه بسته — تازه: {new}، تکراری/موجود: {skipped}'))
