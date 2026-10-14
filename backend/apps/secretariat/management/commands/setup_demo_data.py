"""داده نمایشی فاز ۱+۲ — سامانه را با طراحی مصوب و داده واقعی‌نما پر می‌کند.

اجرا: python3 manage.py setup_demo_data [--force]
کاربران نمونه (رمز یکسان با نام کاربری + 1234):
  dabir (دبیر)، modir (مدیر واحد)، karshenas (کارشناس)، emza (بازبین/امضا)، modir-arshad، karbar
فاز ۲ افزوده بر فاز ۱: گردش کار، جلسات و مصوبات، وظایف، بایگانی، تیکت بومی و فهرست بستر.
"""
from datetime import date, time, timedelta

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
from apps.tasks.models import Task
from apps.ticket_bridge.models import IntegrationUserMap

User = get_user_model()


def jday(offset: int) -> jdatetime.date:
    return jdatetime.date.fromgregorian(date=date.today() + timedelta(days=offset))


class Command(BaseCommand):
    help = 'ساخت داده نمایشی فاز ۱+۲ (کاربران، نامه‌ها، جلسات، وظایف، بایگانی، تیکت‌ها)'

    def add_arguments(self, parser):
        parser.add_argument('--force', action='store_true', help='بازسازی حتی اگر داده هست')

    def _seed_phase2(self, users: dict, letters: list, answered) -> None:
        """زیرسامانه‌های فاز ۲ — همه فرمان‌ها/سرویس‌ها ایدِمپوتنت‌اند."""
        dabir, modir = users['dabir'], users['modir']
        karshenas, karbar = users['karshenas'], users['karbar']
        arshad, emza = users['arshad'], users['emza']

        # شماره همراه نمونه برای اعلان پیامکی
        for u, phone in ((dabir, '09121110001'), (karshenas, '09121110002'),
                         (arshad, '09121110003')):
            Employee.objects.filter(user=u).update(phone=phone)

        # ۱) گردش کار — کاشت + اتصال به سه نامه نخست
        call_command('setup_default_workflow')
        from apps.workflow.models import Workflow
        from apps.workflow.services import bind_workflow
        wf = Workflow.objects.filter(code='letter-default').first()
        if wf:
            for letter in letters[:3]:
                bind_workflow(letter, wf, dabir)

        # ۲) جلسات — یک جلسه برگزارشده کامل و یک جلسه پیش‌رو
        from apps.meetings.models import AgendaItem, Attendee, Meeting, Resolution
        from apps.meetings.services import (convert_resolution_to_task, finalize_minutes,
                                            get_or_create_minutes, sign_minutes)
        held, created = Meeting.objects.get_or_create(
            title='شورای فناوری اطلاعات',
            defaults={
                'jalali_date': jday(-2), 'start_time': time(10, 0),
                'location': 'اتاق جلسات — طبقه ۲',
                'description': 'بررسی خرابی سرور پایگاه‌داده و بودجه تجهیزات',
                'status': Meeting.Status.HELD, 'organizer': arshad})
        if created:
            for u, role in ((arshad, Attendee.Role.CHAIR), (dabir, Attendee.Role.SECRETARY),
                            (karshenas, Attendee.Role.MEMBER), (modir, Attendee.Role.MEMBER)):
                Attendee.objects.create(meeting=held, user=u, role=role,
                                        attendance=Attendee.Attendance.PRESENT)
            AgendaItem.objects.create(meeting=held, order=1, presenter=karshenas,
                                      title='گزارش ریشه‌ای خرابی سرور پایگاه‌داده')
            item2 = AgendaItem.objects.create(meeting=held, order=2, presenter=modir,
                                              title='تصمیم‌گیری درباره خرید تجهیزات رایانه‌ای')
            minutes = get_or_create_minutes(held)
            minutes.body = (
                'جلسه با نام خدا آغاز شد. گزارش خرابی سرور قرائت و مقرر شد پیمانکار '
                'پشتیبانی پاسخ‌گو باشد. در خصوص خرید تجهیزات، استعلام از سه تأمین‌کننده '
                'تصویب شد.')
            minutes.save(update_fields=['body'])
            sign_minutes(minutes, arshad, 'رئیس جلسه')
            sign_minutes(minutes, dabir, 'دبیر جلسه')
            finalize_minutes(minutes, dabir)
            r1 = Resolution.objects.create(
                meeting=held, agenda_item=item2, number=1,
                title='استعلام قیمت تجهیزات از سه تأمین‌کننده',
                description='فهرست تجهیزات پیوست بخشنامه بودجه مبنا است.',
                responsible=karshenas, due_date=jday(5))
            Resolution.objects.create(
                meeting=held, number=2, title='ارائه گزارش ریشه‌ای خرابی سرور',
                responsible=karshenas, due_date=jday(3))
            convert_resolution_to_task(r1, dabir)
        Meeting.objects.get_or_create(
            title='کمیته فنی پروژه اتوماسیون',
            defaults={
                'jalali_date': jday(3), 'start_time': time(14, 0),
                'location': 'سالن کنفرانس', 'organizer': dabir,
                'description': 'هم‌اندیشی فاز ۲ و بازخورد کاربران',
                'status': Meeting.Status.SCHEDULED})
        sched = Meeting.objects.get(title='کمیته فنی پروژه اتوماسیون')
        for u in (modir, karshenas, karbar):
            Attendee.objects.get_or_create(meeting=sched, user=u,
                                           defaults={'role': Attendee.Role.MEMBER})

        # ۳) وظایف — مستقیم و از نامه
        from apps.tasks.services import create_task, update_progress
        if not Task.objects.filter(title='تمدید قرارداد پشتیبانی شبکه').exists():
            t1 = create_task(
                title='تمدید قرارداد پشتیبانی شبکه',
                description='پیش‌نویس تمدید برای شرکت رایان‌پشتیبان آماده شود.',
                responsible=karshenas, due_jalali=jday(4), priority=Task.Priority.URGENT,
                created_by=modir, source_letter=letters[7])
            update_progress(t1, karshenas, 40, 'استعلام اول دریافت شد')
        if not Task.objects.filter(title='به‌روزرسانی فهرست کالاهای رسوبی').exists():
            create_task(
                title='به‌روزرسانی فهرست کالاهای رسوبی',
                responsible=karbar, due_jalali=jday(-1), created_by=modir,
                source_letter=letters[6])

        # ۴) بایگانی — بستن نامه پاسخ‌شده و بایگانی دسته‌ای
        if answered.status != Letter.Status.CLOSED:
            answered.status = Letter.Status.CLOSED
            answered.save(update_fields=['status'])
            LetterEvent.objects.create(letter=answered, actor=dabir,
                                       action=LetterEvent.Action.CLOSED,
                                       to_status=answered.status, note='بایگانی نمونه')
        call_command('archive_closed_letters')

        # ۵) تیکت بومی از آینه + ۶) manifest بستر
        call_command('import_mirror_tickets')
        call_command('setup_platform_manifest')

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

        # فاز ۲ — گردش کار، جلسات، وظایف، بایگانی، تیکت بومی و فهرست بستر
        self._seed_phase2(
            {'dabir': dabir, 'modir': modir, 'karshenas': karshenas, 'emza': emza,
             'arshad': arshad, 'karbar': karbar},
            letters, answered)

        call_command('check_deadlines')
        self.stdout.write(self.style.SUCCESS(
            'داده نمایشی آماده است (فاز ۱+۲).\n'
            '  ورود: dabir/dabir1234 | modir/modir1234 | karshenas/karshenas1234 |\n'
            '        emza/emza1234 | modir-arshad/modir-arshad1234 | karbar/karbar1234\n'
            '  زیرسامانه‌ها: /reports/ /tasks/ /tasks/calendar/ /meetings/ /archive/\n'
            '                /workflow/ /tickets/ /platform/\n'
            '  سپس: python3 manage.py runserver  →  http://127.0.0.1:8000/'))
