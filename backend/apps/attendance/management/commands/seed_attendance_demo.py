"""داده‌های نمایشی حضور و غیاب: انواع مرخصی، مانده، سیاست، محدوده، درخواست‌ها و تردد."""
import datetime as gregorian_datetime

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.management.base import BaseCommand
from django.db import transaction

from apps.attendance.models import (AttendancePolicy, AttendancePunch,
                                    AttendanceRequest, GeoArea, LeaveBalance,
                                    LeaveType, OvertimeConstant, RewardPenalty)
from apps.attendance.services import approve_request, register_record
from apps.core.models import Holiday

User = get_user_model()


class Command(BaseCommand):
    help = 'داده‌های نمایشی حضور و غیاب (انواع مرخصی، درخواست‌ها، تردد، محدوده)'

    @transaction.atomic
    def handle(self, *args, **options):
        manager = User.objects.filter(username='modir-arshad').first()
        staff = User.objects.filter(username__in=[
            'karmand-1', 'karmand-dabir', 'karmand-anbar',
        ]).exclude(pk=manager.pk if manager else None)
        if not manager:
            self.stdout.write(self.style.WARNING('modir-arshad نیست — seed انجام شد.'))
            return

        # --- دسترسی مدیر حضور و غیاب ---
        manager.user_permissions.add(
            Permission.objects.get(codename='manage_attendance'))

        # --- سیاست ---
        p = AttendancePolicy.get()
        p.hours_per_day = 8
        p.show_days = 38
        p.last_day_for_request = 16
        p.record_fix_daily_limit = 1
        p.record_fix_monthly_limit = 3
        p.save()

        # --- انواع مرخصی ---
        annual, _ = LeaveType.objects.get_or_create(
            code='annual', defaults={'name': 'استحقاقی', 'limit_per_year': 30,
                                     'needs_balance': True})
        mission, _ = LeaveType.objects.get_or_create(
            code='mission', defaults={'name': 'ماموریت', 'needs_balance': False})
        hourly, _ = LeaveType.objects.get_or_create(
            code='hourly', defaults={'name': 'ساعتی', 'needs_balance': False})

        # --- ثابت‌های اضافه‌کاری ---
        OvertimeConstant.objects.get_or_create(
            key='holiday_rate', defaults={
                'title': 'ضریب اضافه‌کاری تعطیلات', 'value': 1.5,
                'description': 'مطابق تنظیمات سامانه مرجع (overtime_holiday_rate)'})
        OvertimeConstant.objects.get_or_create(
            key='night_rate', defaults={
                'title': 'ضریب اضافه‌کاری شب', 'value': 1.1,
                'description': 'مطابق تنظیمات سامانه مرجع (overtime_night_rate)'})

        # --- محدوده مجاز ---
        area, _ = GeoArea.objects.get_or_create(
            name='دفتر مرکزی', defaults={'lat': 35.7000, 'lng': 51.4000,
                                          'radius_m': 150,
                                          'description': 'ساختمان مرکزی'})

        # --- مانده مرخصی ---
        LeaveBalance.objects.get_or_create(
            user=manager, leave_type=annual, jyear=1404,
            defaults={'granted': 30, 'used': 6})

        # --- تردد نمونه (یک هفته کاری) ---
        sample_day = jdatetime.date(1404, 7, 6)
        created_punch = 0
        for i, user in enumerate(list(staff)[:2] or [manager]):
            for offset in (0, 1, 2):
                d = sample_day + jdatetime.timedelta(days=offset)
                if d.weekday() in (4, 5):
                    continue
                try:
                    if not Holiday.objects.filter(date=d.togregorian()).exists():
                        register_record(
                            user=user, date=d,
                            check_in=gregorian_datetime.time(8, 0 + i * 5),
                            check_out=gregorian_datetime.time(17, 15 + i * 5))
                        created_punch += 1
                except ValueError:
                    pass

        # --- درخواست‌های نمونه ---
        today = jdatetime.date.today()
        month_start = jdatetime.date(today.year, today.month, 1)
        requester = list(staff)[:1]
        requester = requester[0] if requester else manager
        req1 = AttendanceRequest.objects.create(
            kind=AttendanceRequest.Kind.LEAVE_DAILY, user=requester,
            leave_type=annual, from_date=month_start + jdatetime.timedelta(days=8),
            to_date=month_start + jdatetime.timedelta(days=9),
            days=2, description='مرخصی استحقاقی')
        try:
            approve_request(req1, manager, note='تأیید شد')
        except ValueError:
            pass

        AttendanceRequest.objects.create(
            kind=AttendanceRequest.Kind.OVERTIME, user=manager,
            for_date=month_start + jdatetime.timedelta(days=5),
            hours=2, description='پشتیبانی پایان ماه')

        AttendanceRequest.objects.create(
            kind=AttendanceRequest.Kind.MISSION_DAILY, user=manager,
            for_date=month_start + jdatetime.timedelta(days=7),
            days=1, description='بازدید از نمایندگی')

        # --- پاداش ---
        RewardPenalty.objects.get_or_create(
            user=manager, kind='reward', date=month_start + jdatetime.timedelta(days=4),
            defaults={'title': 'پشتکار', 'amount': 500000,
                      'description': 'تحویل به‌موقع پروژه'})

        # --- رویداد تردد با موقعیت ---
        AttendancePunch.objects.get_or_create(
            user=manager, date=month_start.togregorian(),
            time=gregorian_datetime.time(8, 3),
            defaults={'direction': 'in', 'source': 'web',
                      'lat': area.lat, 'lng': area.lng, 'area': area})

        self.stdout.write(self.style.SUCCESS(
            f'داده‌های حضور و غیاب: {created_punch} رکورد تردد + درخواست‌ها + محدوده + مانده — آماده است.'))
