"""دستور ساخت داده‌های نمونه برای شروع سریع: seed_demo"""
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand
from django.utils import timezone
from jdatetime import date as jdate

from apps.organization.models import Department, Employee


class Command(BaseCommand):
    help = 'ساخت داده‌های نمونه سازمانی و کاربر مدیر (توسعه)'

    def handle(self, *args, **options):
        User = get_user_model()
        if not User.objects.filter(username='admin').exists():
            User.objects.create_superuser(
                username='admin',
                email='admin@ariant.local',
                password='Admin@1404',
            )
            self.stdout.write(self.style.SUCCESS(
                'کاربر مدیر ساخته شد: admin / Admin@1404'))

        hq, _ = Department.objects.get_or_create(
            code='100', defaults={'title': 'دبیرخانه مرکزی'})
        finance, _ = Department.objects.get_or_create(
            code='110', defaults={'title': 'معاونت مالی و اداری', 'parent': hq})
        it, _ = Department.objects.get_or_create(
            code='120', defaults={'title': 'فناوری اطلاعات', 'parent': hq})

        samples = [
            ('مریم', 'رضایی', '1001', hq, 'کارشناس دبیرخانه'),
            ('علی', 'محمدی', '1002', finance, 'کارشناس مالی'),
            ('حسین', 'کریمی', '1003', it, 'کارشناس فناوری اطلاعات'),
        ]
        for first, last, code, dept, job in samples:
            Employee.objects.get_or_create(
                personnel_code=code,
                defaults={
                    'first_name': first,
                    'last_name': last,
                    'department': dept,
                    'job_title': job,
                    'hire_date': jdate.fromgregorian(date=timezone.now().date()),
                },
            )

        self.stdout.write(self.style.SUCCESS(
            'داده‌های نمونه آماده شد: '
            f'{Department.objects.count()} واحد، '
            f'{Employee.objects.count()} کارمند'))
