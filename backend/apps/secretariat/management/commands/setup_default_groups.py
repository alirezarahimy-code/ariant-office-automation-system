"""ساخت گروه‌های نقش پیش‌فرض — تطبیق واژگان مجوز سامانه تیکتینگ (docs/14 بند ۵)."""
from django.contrib.auth.models import Group, Permission
from django.core.management.base import BaseCommand

GROUPS = {
    'کاربر': [
        'view_own_letter', 'answer_letter',
    ],
    'کارشناس': [
        'view_own_letter', 'refer_letter', 'answer_letter',
    ],
    'مدیر واحد': [
        'view_own_letter', 'view_unit_letter', 'refer_letter', 'answer_letter',
        'close_letter', 'view_confidential_letter',
    ],
    'دبیر': [
        'manage_secretariat', 'view_all_letter', 'view_confidential_letter',
        'refer_letter', 'answer_letter',
    ],
    'بازبین/امضا': [
        'view_all_letter', 'approve_letter', 'answer_letter',
    ],
    'مدیر ارشد': [
        'view_all_letter', 'view_confidential_letter', 'refer_letter',
        'answer_letter', 'close_letter', 'approve_letter',
    ],
}


class Command(BaseCommand):
    help = 'ساخت/به‌روزرسانی گروه‌های نقش پیش‌فرض دبیرخانه'

    def handle(self, *args, **options):
        created_count = 0
        for name, codenames in GROUPS.items():
            group, created = Group.objects.get_or_create(name=name)
            created_count += int(created)
            perms = Permission.objects.filter(
                codename__in=codenames, content_type__app_label='secretariat')
            group.permissions.set(perms)
            self.stdout.write(f'گروه «{name}» — {perms.count()} مجوز')
        self.stdout.write(self.style.SUCCESS(
            f'{len(GROUPS)} گروه آماده ({created_count} تازه ساخته شد).'))
