"""داده نمایشی فاز ۱ — سامانه را با طراحی مصوب و داده واقعی‌نما پر می‌کند.

اجرا: python3 manage.py setup_demo_data [--force]
کاربران نمونه (رمز یکسان با نام کاربری + 1234):
  dabir (دبیر)، modir (مدیر واحد)، karshenas (کارشناس)، emza (بازبین/امضا)، modir-arshad، karbar
"""
from datetime import date, timedelta

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Group
from django.core.files.uploadedfile import SimpleUploadedFile
from django.core.management import call_command
from django.core.management.base import BaseCommand
from django.utils import timezone

from apps.core.models import Notification
from apps.organization.models import Department, Employee
from apps.secretariat.models import (Attachment, Copy, Letter, LetterApproval, LetterEvent,
                                     Office, Referral)
from apps.secretariat.services import register_letter
from apps.ticket_bridge.models import IntegrationUserMap

User = get_user_model()


def jday(offset: int) -> jdatetime.date:
    return jdatetime.date.fromgregorian(date=date.today() + timedelta(days=offset))


class Command(BaseCommand):
    help = 'ساخت داده نمایشی فاز ۱ (کاربران، نامه‌ها، ارجاع‌ها، تیکت‌ها)'

    def add_arguments(self, parser):
        parser.add_argument('--force', action='store_true', help='بازسازی حتی اگر داده هست')

    def handle(self, *args, **options):
        if Letter.objects.count() > 0 and not options['force']:
            self.stdout.write(self.style.WARNING(
                'داده موجود است — برای بازسازی: setup_demo_data --force'))
            return
        call_command('setup_default_groups')

        # --- ساختار سازمانی ---
        deputy, _ = Department.objects.get_or_create(code='DEP-1', defaults={'title': 'معاونت اداری و مالی'})
        office_dep, _ = Department.objects.get_or_create(
            code='DEP-1-1', defaults={'title': 'امور دفتر و دبیرخانه', 'parent': deputy})
        it_dep, _ = Department.objects.get_or_create(
            code='DEP-2', defaults={'title': 'فناوری اطلاعات'})

        def make_user(username, first, last, group_names, dept=None, code=None):
            user = User.objects.filter(username=username).first()
            if user is None:
                user = User.objects.create_user(username, password=f'{username}1234',
                                                first_name=first, last_name=last)
            for name in group_names:
                group = Group.objects.filter(name=name).first()
                if group:
                    user.groups.add(group)
            if dept and code and not Employee.objects.filter(personnel_code=code).exists():
                Employee.objects.create(user=user, first_name=first, last_name=last,
                                        personnel_code=code, department=dept, job_title='')
            return user

        dabir = make_user('dabir', 'زهرا', 'کرمی', ['دبیر'], office_dep, 'E-101')
        modir = make_user('modir', 'حسین', 'رادمنش', ['مدیر واحد'], office_dep, 'E-102')
        karshenas = make_user('karshenas', 'مریم', 'رضایی', ['کارشناس'], it_dep, 'E-201')
        emza = make_user('emza', 'رضا', 'امینی', ['بازبین/امضا'], deputy, 'E-103')
        arshad = make_user('modir-arshad', 'علی', 'فرهمند', ['مدیر ارشد'], deputy, 'E-100')
        karbar = make_user('karbar', 'سارا', 'کریمی', ['کاربر'], it_dep, 'E-202')

        office, _ = Office.objects.get_or_create(
            code='HQ', defaults={'name': 'دبیرخانه مرکزی', 'department': office_dep})

        incoming_specs = [
            ('درخواست خرید تجهیزات رایانه‌ای', 'شرکت پارس رایان', 'normal', 'normal', 6, -2, dabir),
            ('ابلاغ بخشنامه بودجه سال ۱۴۰۶', 'سازمان برنامه و بودجه', 'urgent', 'internal', 3, -4, dabir),
            ('دعوت به جلسه کمیته فنی پروژه اتوماسیون', 'دانشگاه صنعتی', 'normal', 'normal', 1, -1, dabir),
            ('گزارش خرابی سرور پایگاه‌داده', 'واحد فناوری اطلاعات', 'immediate', 'normal', -1, -6, dabir),
            ('نتایج بازرسی دوره‌ای از انبار', 'بازرسی کل', 'normal', 'confidential', None, -8, dabir),
            ('درخواست همکاری کارآموزی', 'دانشکده فنی', 'normal', 'normal', 12, -3, dabir),
            ('اعلام فهرست کالاهای رسوبی', 'شرکت بازرگانی آریا', 'normal', 'normal', None, -10, dabir),
            ('درخواست تمدید قرارداد پشتیبانی', 'شرکت رایان‌پشتیبان', 'urgent', 'normal', 4, -5, dabir),
        ]
        letters = []
        for subject, sender_org, priority, conf, deadline_off, date_off, by in incoming_specs:
            letter = register_letter(
                office, Letter.LetterType.INCOMING, subject, created_by=by,
                sender_org=sender_org, sender_name='', summary=f'خلاصه {subject}',
                priority=priority, confidentiality=conf,
                letter_date=jday(date_off), received_at=timezone.now() - timedelta(days=-date_off),
                deadline=jday(deadline_off) if deadline_off is not None else None)
            letters.append(letter)

        # زنجیره ارجاع نمونه: دبیر → مدیر (اقدام‌شده) → کارشناس (در انتظار، نزدیک مهلت)
        first = letters[0]
        r1 = Referral.objects.create(
            letter=first, from_user=dabir, to_user=modir, note='بررسی و تعیین تکلیف فرمایید.',
            status=Referral.Status.ACTED, acted_at=timezone.now() - timedelta(days=1))
        Referral.objects.create(
            letter=first, parent=r1, from_user=modir, to_user=karshenas,
            note='استعلام قیمت از سه تأمین‌کننده گرفته شود.', deadline=jday(2))
        first.status = Letter.Status.REFERRED
        first.save(update_fields=['status'])
        LetterEvent.objects.create(letter=first, actor=dabir, action=LetterEvent.Action.REFERRED,
                                   to_status=first.status, note='ارجاع اولیه')

        # نامه متأخر با ارجاع باز
        overdue = letters[3]
        Referral.objects.create(letter=overdue, from_user=dabir, to_user=karshenas,
                                note='گزارش ریشه‌ای تهیه شود.', deadline=jday(-1))
        overdue.status = Letter.Status.IN_PROGRESS
        overdue.save(update_fields=['status'])

        # پاسخ‌شده
        answered = letters[1]
        Referral.objects.create(letter=answered, from_user=dabir, to_user=modir,
                                status=Referral.Status.ACTED, acted_at=timezone.now())
        answered.status = Letter.Status.ANSWERED
        answered.save(update_fields=['status'])
        LetterEvent.objects.create(letter=answered, actor=modir, action=LetterEvent.Action.ANSWERED,
                                   note='بخشنامه در جلسه شورا قرائت و ابلاغ داخلی شد.')

        # پیوست نمونه
        pdf_file = SimpleUploadedFile(
            'bakhshname-1406.pdf', b'%PDF-1.4 demo attachment', content_type='application/pdf')
        attachment = Attachment(letter=answered, file=pdf_file, uploaded_by=dabir)
        attachment.full_clean()
        attachment.save()

        # رونوشت
        Copy.objects.create(letter=letters[2], to_user=karbar, note='جهت استحضار')
        Copy.objects.create(letter=letters[2], to_department=it_dep)

        # صادره: پیش‌نویس، در بازبینی، امضا+ارسال‌شده
        draft = register_letter(office, Letter.LetterType.OUTGOING,
                                'پاسخ به استعلام تجهیزات شبکه', created_by=karshenas,
                                body='احتراماً، در پاسخ به نامه شماره ۱۴۰۵/و/۰۰۰۱ …',
                                letter_date=jday(0))
        in_review = register_letter(office, Letter.LetterType.OUTGOING,
                                    'ابلاغ دستورالعمل امنیت اطلاعات', created_by=dabir,
                                    body='بدین‌وسیله دستورالعمل پیوست ابلاغ می‌گردد…',
                                    letter_date=jday(0))
        in_review.status = Letter.Status.IN_REVIEW
        in_review.save(update_fields=['status'])
        sent = register_letter(office, Letter.LetterType.OUTGOING,
                               'معرفی نماینده سازمان در کارگروه فناوری', created_by=dabir,
                               body='جناب آقای مهندس رضایی به‌عنوان نماینده این سازمان معرفی می‌گردند.',
                               letter_date=jday(-1))
        LetterApproval.objects.create(letter=sent, step=1, approver=emza, approver_label='مدیرکل',
                                      decision=LetterApproval.Decision.APPROVED,
                                      note='بلامانع است.', decided_at=timezone.now())
        sent.status = Letter.Status.SENT
        sent.save(update_fields=['status'])
        LetterEvent.objects.create(letter=sent, actor=emza, action=LetterEvent.Action.STATUS_CHANGED,
                                   to_status=sent.status, note='تأیید، امضا و ارسال')

        # اعلان‌های نمونه
        Notification.objects.create(user=karbar, title='رونوشت نامه دعوت به جلسه کمیته فنی',
                                    body='جهت استحضار', url=f'/letters/{letters[2].pk}/')
        Notification.objects.create(user=karshenas, title='ارجاع نامه گزارش خرابی سرور',
                                    body='گزارش ریشه‌ای تهیه شود.', url=f'/letters/{overdue.pk}/')

        # پل تیکتینگ: نگاشت و همگام‌سازی نمونه
        IntegrationUserMap.objects.get_or_create(
            system='php-ticketing', external_username='m.rezaei',
            defaults={'platform_user': karshenas, 'external_user_id': 77})
        IntegrationUserMap.objects.get_or_create(
            system='php-ticketing', external_username='s.karimi',
            defaults={'platform_user': karbar, 'external_user_id': 88})
        try:
            call_command('sync_tickets')
        except Exception as exc:  # در نبود fixture
            self.stdout.write(self.style.WARNING(f'همگام‌سازی تیکت انجام نشد: {exc}'))

        call_command('check_deadlines')
        self.stdout.write(self.style.SUCCESS(
            'داده نمایشی آماده است.\n'
            '  ورود: dabir/dabir1234 | modir/modir1234 | karshenas/karshenas1234 |\n'
            '        emza/emza1234 | modir-arshad/modir-arshad1234 | karbar/karbar1234\n'
            '  سپس: python3 manage.py runserver  →  http://127.0.0.1:8000/'))
