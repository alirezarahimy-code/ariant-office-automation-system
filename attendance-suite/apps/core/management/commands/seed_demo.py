"""داده‌های نمایشی: نقش‌ها، مجوزها، سازمان، شیفت، سیاست."""
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand
from django.db import transaction

from apps.attendance.models import AttendancePolicy, LeaveType, Shift
from apps.core.models import Permission, Role, RoleDataScope, UserRole
from apps.org.models import Department, Employee, EmpType, Position

User = get_user_model()


class Command(BaseCommand):
    help = 'داده‌های نمایشی سامانه یکپارچه حضور و غیاب'

    @transaction.atomic
    def handle(self, *args, **options):
        # مجوزها
        perms = {}
        for module, _ in Permission.MODULES:
            for action, _ in Permission.ACTIONS:
                perms[f'{module}.{action}'], _ = Permission.objects.get_or_create(
                    module=module, action=action)

        # نقش‌ها
        admin_role, _ = Role.objects.get_or_create(
            code='admin', defaults={'name': 'مدیر سامانه', 'is_system': True})
        admin_role.permissions.set(perms.values())
        hr, _ = Role.objects.get_or_create(
            code='hr_manager', defaults={'name': 'مدیر منابع انسانی',
                                         'is_system': True})
        hr.permissions.set([p for k, p in perms.items()
                            if k.split('.')[0] in ('org', 'attendance', 'requests',
                                                   'reports', 'device')])
        RoleDataScope.objects.get_or_create(
            role=hr, module='attendance', defaults={'scope': 'all'})
        employee_role, _ = Role.objects.get_or_create(
            code='employee', defaults={'name': 'کارمند', 'is_system': True})
        employee_role.permissions.set(
            [perms['attendance.view'], perms['requests.view'],
             perms['requests.add'], perms['reports.view']])

        # سازمان
        it, _ = Department.objects.get_or_create(
            code='IT', defaults={'name': 'فناوری اطلاعات'})
        hr_dept, _ = Department.objects.get_or_create(
            code='HR', defaults={'name': 'منابع انسانی'})
        ceo, _ = Position.objects.get_or_create(
            title='مدیرعامل', defaults={'is_manager': True})
        it_mgr, _ = Position.objects.get_or_create(
            title='مدیر فناوری', defaults={'parent': ceo, 'is_manager': True,
                                            'department': it})
        et, _ = EmpType.objects.get_or_create(
            code='official', defaults={'name': 'رسمی'})

        mgr_emp, _ = Employee.objects.get_or_create(
            badge_no='1001', defaults={
                'first_name': 'مریم', 'last_name': 'مدیری',
                'department': it, 'position': it_mgr, 'emp_type': et})
        emp, _ = Employee.objects.get_or_create(
            badge_no='1002', defaults={
                'first_name': 'علی', 'last_name': 'کارمند',
                'department': it, 'emp_type': et, 'manager': mgr_emp})

        admin, _ = User.objects.get_or_create(
            username='admin', defaults={'is_staff': True, 'is_superuser': True})
        admin.set_password('admin')
        admin.save()
        UserRole.objects.get_or_create(user=admin, role=admin_role)

        mgr_user, _ = User.objects.get_or_create(
            username='manager', defaults={'employee': mgr_emp})
        mgr_user.set_password('manager')
        mgr_user.save()
        UserRole.objects.get_or_create(user=mgr_user, role=hr)

        emp_user, _ = User.objects.get_or_create(
            username='employee', defaults={'employee': emp})
        emp_user.set_password('employee')
        emp_user.save()
        UserRole.objects.get_or_create(user=emp_user, role=employee_role)

        # شیفت و سیاست
        Shift.objects.get_or_create(
            name='اداری', defaults={
                'start_time': '08:00', 'end_time': '16:00',
                'work_days': [0, 1, 2, 3, 4], 'grace_minutes': 15})
        AttendancePolicy.get()
        LeaveType.objects.get_or_create(
            code='annual', defaults={'name': 'استحقاقی', 'limit_per_year': 30,
                                     'needs_balance': True})
        LeaveType.objects.get_or_create(
            code='mission', defaults={'name': 'ماموریت', 'needs_balance': False})

        self.stdout.write(self.style.SUCCESS(
            'داده‌های نمایشی آماده: admin/admin — manager/manager — employee/employee'))
