"""ساخت گروه‌های نقش پیش‌فرض — دبیرخانه + زیرسامانه‌های فاز ۲.

واژگان مجوز: تطبیق سامانه تیکتینگ (docs/14 بند ۵) و بستر یکپارچه (docs/08).
مجوزها می‌توانند ساده (secretariat) یا نقطه‌دار «app.codename» باشند.
"""
from django.contrib.auth.models import Group, Permission
from django.core.management.base import BaseCommand
from django.db.models import Q

GROUPS = {
    'کاربر': [
        'view_own_letter', 'answer_letter',
        'archive.view_archive',
    ],
    'کارشناس': [
        'view_own_letter', 'refer_letter', 'answer_letter',
        'archive.view_archive',
    ],
    'مدیر واحد': [
        'view_own_letter', 'view_unit_letter', 'refer_letter', 'answer_letter',
        'close_letter', 'view_confidential_letter',
        'archive.view_archive', 'meetings.manage_meetings',
    ],
    'دبیر': [
        'manage_secretariat', 'view_all_letter', 'view_confidential_letter',
        'refer_letter', 'answer_letter',
        'archive.view_archive', 'archive.manage_archive',
    ],
    'بازبین/امضا': [
        'view_all_letter', 'approve_letter', 'answer_letter',
        'archive.view_archive',
    ],
    'مدیر ارشد': [
        'view_all_letter', 'view_confidential_letter', 'refer_letter',
        'answer_letter', 'close_letter', 'approve_letter',
        'archive.view_archive', 'archive.approve_disposal',
        'meetings.manage_meetings', 'tasks.manage_tasks',
    ],
}


def _perm_q(entry: str) -> Q:
    """«codename» → secretariat؛ «app.codename» → همان اپ."""
    if '.' in entry:
        app, code = entry.split('.', 1)
        return Q(codename=code, content_type__app_label=app)
    return Q(codename=entry, content_type__app_label='secretariat')


class Command(BaseCommand):
    help = 'ساخت/به‌روزرسانی گروه‌های نقش پیش‌فرض (دبیرخانه و زیرسامانه‌ها)'

    def handle(self, *args, **options):
        created_count = 0
        for name, entries in GROUPS.items():
            group, created = Group.objects.get_or_create(name=name)
            created_count += int(created)
            query = Q()
            for entry in entries:
                query |= _perm_q(entry)
            perms = Permission.objects.filter(query)
            group.permissions.set(perms)
            self.stdout.write(f'گروه «{name}» — {perms.count()} مجوز')
        self.stdout.write(self.style.SUCCESS(
            f'{len(GROUPS)} گروه آماده ({created_count} تازه ساخته شد).'))
