from django.contrib.auth import get_user_model
from django.test import RequestFactory, TestCase, override_settings
from django.urls import resolve, reverse

import datetime as gregorian
import jdatetime
from apps.attendance.models import (
    DailyRecord, LeaveBalance, LeaveType, Request, Shift, ShiftAssignment,
)
from apps.core.models import (
    AuditLog, Permission, ProgramAccess, Role, RoleDataScope, UserRole,
)
from apps.core.licensing import LicenseCheckResult
from apps.core.services import has_program_access
from apps.org.models import Department, Employee
from .menu import T5_MENU, t5web_navigation

User = get_user_model()


class FakeLicenseChecker:
    calls = []

    def check(self, change_type, code):
        self.calls.append((change_type, code))
        if code.startswith("explode-"):
            raise RuntimeError(f"provider diagnostic: {code}")
        if code == "valid-test-code":
            return LicenseCheckResult(True, f"provider received: {code}")
        return LicenseCheckResult(False, f"provider rejected: {code}")


class T5NavigationTests(TestCase):
    def test_reference_groups_and_order_are_stable(self):
        self.assertEqual(
            [title for title, _ in T5_MENU],
            [
                "اطلاعات پایه",
                "اطلاعات حضور و غیاب",
                "کنترل حضور و غیاب",
                "دستگاه",
                "گزارشات",
                "مدیریت",
            ],
        )
        self.assertEqual(
            [label for label, *_ in T5_MENU[0][1]][:5],
            ["مدرک تحصیلی", "سمت", "انتخاب جانشین", "نوع استخدام", "انواع خروج"],
        )

    def test_every_exposed_menu_destination_resolves(self):
        user = User.objects.create_superuser("t5-admin", password="test-pass")
        request = RequestFactory().get("/")
        request.user = user
        context = t5web_navigation(request)
        self.assertTrue(context["t5_menu_groups"])
        for group in context["t5_menu_groups"]:
            for item in group["items"]:
                self.assertIsNotNone(resolve(item["url"]))
        self.assertEqual(
            context["t5_menu_groups"][0]["items"][0]["label"], "مدرک تحصیلی"
        )

    def test_unis_virdi_bridge_is_not_misrepresented_as_t5_usb_management(self):
        user = User.objects.create_superuser("t5-menu-admin", password="test-pass")
        request = RequestFactory().get("/")
        request.user = user
        items = [item for group in t5web_navigation(request)["t5_menu_groups"]
                 for item in group["items"]]
        self.assertNotIn(reverse("device:usb_import"),
                         [item["url"] for item in items])
        self.assertNotIn("مدیریت اطلاعات USB", [item["label"] for item in items])

    def test_menu_is_permission_filtered(self):
        user = User.objects.create_user("t5-employee", password="test-pass")
        role = Role.objects.create(name="گزارش‌خوان", code="report-reader")
        report_view = Permission.objects.create(module="reports", action="view")
        role.permissions.add(report_view)
        UserRole.objects.create(user=user, role=role)
        request = RequestFactory().get("/")
        request.user = user

        groups = t5web_navigation(request)["t5_menu_groups"]
        self.assertEqual(
            [group["title"] for group in groups],
            ["کنترل حضور و غیاب", "گزارشات"],
        )
        visible_labels = [
            item["label"] for group in groups for item in group["items"]
        ]
        self.assertEqual(visible_labels, ["گزارش جدولی", "نمایش گزارشات"])

    def test_report_menu_uses_real_jalali_report_route(self):
        self.assertEqual(reverse("reports:run_standard", args=["daily"]), "/reports/run/daily/")


class T5ManagerAccountTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser("manager-admin", password="Admin-Pass-123!")
        self.employee = Employee.objects.create(
            badge_no="T5001", first_name="آرمان", last_name="آزمایشی",
            web_user=True, program_user=True,
        )
        self.role = Role.objects.create(name="کارشناس", code="t5-operator")
        self.url = reverse("t5web:managers")
        self.client.force_login(self.admin)

    def test_create_account_links_existing_employee_and_assigns_role(self):
        response = self.client.post(self.url, {
            "employee": self.employee.pk,
            "username": "operator-1",
            "password": "New-Passphrase-789!",
            "roles": [self.role.pk],
            "t5web_access": "on",
            "twf_access": "on",
            "is_active": "on",
        })
        self.assertEqual(response.status_code, 302)
        account = User.objects.get(username="operator-1")
        self.assertEqual(account.employee_id, self.employee.pk)
        self.assertTrue(account.check_password("New-Passphrase-789!"))
        self.assertTrue(account.must_change_password)
        self.assertEqual(list(account.user_roles.values_list("role_id", flat=True)), [self.role.pk])
        self.assertEqual(
            set(account.program_access.filter(is_allowed=True).values_list("program", flat=True)),
            {ProgramAccess.Program.T5WEB, ProgramAccess.Program.TWF},
        )
        self.employee.refresh_from_db()
        self.assertTrue(self.employee.web_user)
        self.assertTrue(self.employee.program_user)
        self.assertTrue(AuditLog.objects.filter(
            user=self.admin, model="T5UserAccess", action="add",
            object_id=str(account.pk),
        ).exists())

    def test_password_reset_forces_change_at_next_login(self):
        account = User.objects.create_user(
            "reset-me", password="Old-Passphrase-789!", employee=self.employee
        )
        response = self.client.post(self.url, {
            "user_id": account.pk,
            "employee": self.employee.pk,
            "username": account.username,
            "password": "Reset-Passphrase-123!",
            "roles": [],
            "t5web_access": "on",
            "is_active": "on",
        })
        self.assertEqual(response.status_code, 302)
        account.refresh_from_db()
        self.assertTrue(account.check_password("Reset-Passphrase-123!"))
        self.assertTrue(account.must_change_password)

    def test_role_editor_stores_action_permissions_and_per_module_scopes(self):
        permission, _ = Permission.objects.get_or_create(
            module="attendance", action="view"
        )
        data = {
            "name": "سرپرست واحد",
            "code": "unit-supervisor",
            "permissions": [permission.pk],
        }
        for module, _label in Permission.MODULES:
            data[f"scope_{module}"] = RoleDataScope.Scope.SELF
        data["scope_org"] = RoleDataScope.Scope.DEPT
        data["scope_attendance"] = RoleDataScope.Scope.DEPT_TREE

        response = self.client.post(reverse("t5web:roles"), data)
        self.assertEqual(response.status_code, 302)
        role = Role.objects.get(code="unit-supervisor")
        self.assertEqual(list(role.permissions.all()), [permission])
        self.assertEqual(
            RoleDataScope.objects.get(role=role, module="org").scope,
            RoleDataScope.Scope.DEPT,
        )
        self.assertEqual(
            RoleDataScope.objects.get(role=role, module="attendance").scope,
            RoleDataScope.Scope.DEPT_TREE,
        )
        self.assertTrue(AuditLog.objects.filter(model="T5Role", object_id=str(role.pk)).exists())

    def test_department_tree_rejects_circular_parent(self):
        parent = Department.objects.create(name="ستاد", code="HQ")
        child = Department.objects.create(name="مالی", code="FIN", parent=parent)
        response = self.client.post(reverse("org:department_edit", args=[parent.pk]), {
            "name": parent.name,
            "code": parent.code,
            "parent_id": child.pk,
            "is_active": "on",
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "انتخاب زیرواحد به‌عنوان والد باعث چرخه در چارت می‌شود.")
        parent.refresh_from_db()
        self.assertIsNone(parent.parent_id)

    def test_department_tree_rejects_duplicate_codes(self):
        Department.objects.create(name="مالی", code="FIN")
        response = self.client.post(reverse("org:department_admin"), {
            "name": "مالی دیگر",
            "code": "FIN",
            "parent_id": "",
            "is_active": "on",
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "کد واحد تکراری است.")
        self.assertEqual(Department.objects.filter(code="FIN").count(), 1)

    def test_daily_leave_batch_uses_valid_kind_and_updates_balance(self):
        date = gregorian.date.today() + gregorian.timedelta(days=1)
        shift = Shift.objects.create(
            name="تمام‌روز آزمون", start_time=gregorian.time(8),
            end_time=gregorian.time(16), work_days=[0, 1, 2, 3, 4, 5, 6],
        )
        ShiftAssignment.objects.create(
            employee=self.employee, shift=shift, from_date=date
        )
        leave_type = LeaveType.objects.create(
            name="استحقاقی", code="annual-test", limit_per_year=30,
            needs_balance=True,
        )
        jalali_date = jdatetime.date.fromgregorian(date=date).strftime("%Y/%m/%d")
        response = self.client.post(reverse("attendance:daily_leave_batch"), {
            "selectedBadgeNumbers": [self.employee.badge_no],
            "operation": Request.Kind.LEAVE_DAILY,
            "leave_type_id": leave_type.pk,
            "date_from": jalali_date,
            "date_to": jalali_date,
            "description": "آزمون ثبت گروهی",
        })
        self.assertEqual(response.status_code, 302)
        req = Request.objects.get(employee=self.employee)
        self.assertEqual(req.kind, Request.Kind.LEAVE_DAILY)
        self.assertEqual(req.status, Request.Status.APPROVED)
        self.assertEqual(req.days, 1)
        balance = LeaveBalance.objects.get(employee=self.employee, leave_type=leave_type)
        self.assertEqual(balance.used, 1)

    def test_duplicate_employee_cannot_get_second_account(self):
        User.objects.create_user("existing", password="Existing-Pass-789!", employee=self.employee)
        response = self.client.post(self.url, {
            "employee": self.employee.pk,
            "username": "second-account",
            "password": "New-Passphrase-789!",
            "roles": [],
            "is_active": "on",
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "برای این پرسنل حساب کاربری دیگری وجود دارد.")
        self.assertFalse(User.objects.filter(username="second-account").exists())

    def test_manager_account_list_obeys_organizational_scope(self):
        dept = Department.objects.create(name="واحد مجاز", code="T5-SCOPE")
        manager_employee = Employee.objects.create(
            badge_no="T5-MGR", first_name="مدیر", last_name="محدود",
            department=dept, program_user=True,
        )
        in_scope = Employee.objects.create(
            badge_no="T5-IN", first_name="درون", last_name="دامنه",
            department=dept,
        )
        outside_dept = Department.objects.create(name="واحد دیگر", code="T5-OTHER")
        Employee.objects.create(
            badge_no="T5-OUT", first_name="بیرون", last_name="دامنه",
            department=outside_dept,
        )
        manager_user = User.objects.create_user(
            "scoped-core-manager", password="Manager-Passphrase-123!",
            employee=manager_employee,
        )
        role = Role.objects.create(name="مدیر محلی", code="local-core-manager")
        role.permissions.add(Permission.objects.get_or_create(
            module="core", action="manage"
        )[0])
        UserRole.objects.create(user=manager_user, role=role)
        RoleDataScope.objects.create(
            role=role, module="org", scope=RoleDataScope.Scope.DEPT
        )
        self.client.force_login(manager_user)

        response = self.client.get(self.url)
        self.assertEqual(response.status_code, 200)
        allowed_badges = set(response.context["form"].fields["employee"].queryset.values_list(
            "badge_no", flat=True
        ))
        self.assertIn(in_scope.badge_no, allowed_badges)
        self.assertNotIn("T5-OUT", allowed_badges)

    def test_non_manager_cannot_view_account_administration(self):
        ordinary = User.objects.create_user("ordinary", password="Ordinary-Pass-123!")
        self.client.force_login(ordinary)
        response = self.client.get(self.url)
        self.assertEqual(response.status_code, 403)

    def test_change_lock_matches_reference_error_without_provider(self):
        FakeLicenseChecker.calls.clear()
        page_url = reverse("t5web:change_lock")
        check_url = reverse("t5web:change_lock_check")
        page = self.client.get(page_url)
        self.assertEqual(page.status_code, 200)
        for value in ("1", "2", "3", "4", "5", "6"):
            self.assertContains(page, f'value="{value}"')
        invalid = self.client.post(check_url, {
            "ChangeType": "999", "Code": "never-store-this-license-key",
        })
        self.assertEqual(invalid.status_code, 400)
        self.assertNotIn("never-store-this-license-key", invalid.content.decode())
        response = self.client.post(check_url, {
            "ChangeType": "1", "Code": "never-store-this-license-key",
        })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response["Content-Type"], "text/plain; charset=utf-8")
        self.assertEqual(response.content.decode(), "خطا در انجام عملیات")
        self.assertNotIn("never-store-this-license-key", response.content.decode())
        self.assertEqual(FakeLicenseChecker.calls, [])
        self.assertFalse(AuditLog.objects.filter(
            model="T5License", detail__contains="never-store-this-license-key"
        ).exists())

        ordinary = User.objects.create_user("license-user", password="License-Pass-123!")
        self.client.force_login(ordinary)
        self.assertEqual(self.client.get(page_url).status_code, 403)
        self.assertEqual(self.client.post(check_url, {
            "ChangeType": "1", "Code": "valid-test-code",
        }).status_code, 403)

    @override_settings(T5_LICENSE_CHECKER="apps.t5web.tests.FakeLicenseChecker")
    def test_change_lock_uses_typed_provider_and_never_logs_or_echoes_codes(self):
        FakeLicenseChecker.calls.clear()
        check_url = reverse("t5web:change_lock_check")
        accepted = self.client.post(check_url, {
            "ChangeType": "4", "Code": "valid-test-code",
        })
        self.assertEqual(accepted.status_code, 200)
        self.assertEqual(accepted.json(), {
            "ok": True, "message": "درخواست توسط سرویس معتبر لایسنس پذیرفته شد."
        })
        self.assertNotIn("valid-test-code", accepted.content.decode())
        self.assertEqual(FakeLicenseChecker.calls, [("4", "valid-test-code")])
        self.assertFalse(AuditLog.objects.filter(
            model="T5License", detail__contains="valid-test-code"
        ).exists())

        rejected = self.client.post(check_url, {
            "ChangeType": "4", "Code": "invalid-test-code",
        })
        self.assertEqual(rejected.status_code, 400)
        self.assertFalse(rejected.json()["ok"])
        self.assertNotIn("invalid-test-code", rejected.content.decode())

        failing_code = "explode-license-secret"
        failed = self.client.post(check_url, {
            "ChangeType": "1", "Code": failing_code,
        })
        self.assertEqual(failed.status_code, 503)
        self.assertNotIn(failing_code, failed.content.decode())
        self.assertFalse(AuditLog.objects.filter(
            model="T5License", detail__contains=failing_code
        ).exists())

    def test_application_grants_are_independent_and_explicit(self):
        account = User.objects.create_user(
            "program-user", password="Strong-Passphrase-789!",
            employee=self.employee,
        )
        self.assertTrue(has_program_access(account, ProgramAccess.Program.T5WEB))
        self.assertFalse(has_program_access(account, ProgramAccess.Program.TWF))
        ProgramAccess.objects.create(
            user=account, program=ProgramAccess.Program.T5WEB, is_allowed=False
        )
        ProgramAccess.objects.create(
            user=account, program=ProgramAccess.Program.TWF, is_allowed=True
        )
        self.assertFalse(has_program_access(account, ProgramAccess.Program.T5WEB))
        self.assertTrue(has_program_access(account, ProgramAccess.Program.TWF))
        self.assertFalse(has_program_access(account, ProgramAccess.Program.LINK5))
        self.client.force_login(account)
        response = self.client.get(reverse("attendance:dashboard"))
        self.assertEqual(response.status_code, 302)
        self.assertEqual(response.url, reverse("core:login"))
        self.assertTrue(AuditLog.objects.filter(
            user=account, action="program_access_revoked", model="T5Web"
        ).exists())

    def test_revoking_legacy_program_user_terminates_existing_session(self):
        account = User.objects.create_user(
            "legacy-program-user", password="Strong-Passphrase-789!",
            employee=self.employee,
        )
        self.client.force_login(account)
        before_revoke = self.client.get(reverse("attendance:dashboard"))
        self.assertTrue(before_revoke.wsgi_request.user.is_authenticated)

        self.employee.program_user = False
        self.employee.save(update_fields=["program_user"])
        after_revoke = self.client.get(reverse("attendance:dashboard"))
        self.assertEqual(after_revoke.status_code, 302)
        self.assertEqual(after_revoke.url, reverse("core:login"))
        self.assertFalse(after_revoke.wsgi_request.user.is_authenticated)
        self.assertTrue(AuditLog.objects.filter(
            user=account, action="program_access_revoked", model="T5Web"
        ).exists())

    @override_settings(LOGIN_CAPTCHA_ENABLED=False)
    def test_login_requires_reference_program_user_flag(self):
        restricted_employee = Employee.objects.create(
            badge_no="T5002", first_name="نرگس", last_name="بدون‌دسترسی",
            web_user=True, program_user=False,
        )
        restricted_user = User.objects.create_user(
            "restricted-user", password="Strong-Passphrase-789!",
            employee=restricted_employee,
        )
        # An explicit local grant must not bypass T5 3.5.7's ProgramUser gate.
        ProgramAccess.objects.create(
            user=restricted_user, program=ProgramAccess.Program.T5WEB,
            is_allowed=True,
        )
        self.client.logout()
        response = self.client.post(reverse("core:login"), {
            "username": "restricted-user",
            "password": "Strong-Passphrase-789!",
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "دسترسی T5Web برای این پروندهٔ پرسنلی فعال نیست.")
        self.assertFalse(response.wsgi_request.user.is_authenticated)
        self.assertTrue(AuditLog.objects.filter(
            model="T5Web", action="login_denied",
        ).exists())


class T5ScopedReadTests(TestCase):
    def setUp(self):
        self.department = Department.objects.create(name="مالی", code="FIN")
        self.employee = Employee.objects.create(
            badge_no="SCOPE-1", first_name="آ", last_name="م",
            department=self.department, program_user=True,
        )
        self.other = Employee.objects.create(
            badge_no="SCOPE-2", first_name="ب", last_name="ن", department=self.department
        )
        self.user = User.objects.create_user(
            "scoped-user", password="Scoped-Passphrase-123!", employee=self.employee
        )
        self.role = Role.objects.create(name="کارمند محدود", code="scoped-user-role")
        self.role.permissions.add(
            Permission.objects.create(module="attendance", action="view"),
            Permission.objects.create(module="requests", action="view"),
            Permission.objects.create(module="requests", action="approve"),
            Permission.objects.create(module="org", action="view"),
        )
        UserRole.objects.create(user=self.user, role=self.role)
        RoleDataScope.objects.create(
            role=self.role, module="attendance", scope=RoleDataScope.Scope.SELF
        )
        RoleDataScope.objects.create(
            role=self.role, module="requests", scope=RoleDataScope.Scope.SELF
        )
        RoleDataScope.objects.create(
            role=self.role, module="org", scope=RoleDataScope.Scope.SELF
        )
        self.client.force_login(self.user)

    def test_total_control_only_returns_scoped_employees(self):
        DailyRecord.objects.create(employee=self.employee, date=gregorian.date.today())
        DailyRecord.objects.create(employee=self.other, date=gregorian.date.today())
        response = self.client.get(reverse("attendance:total_control"))
        self.assertEqual(response.status_code, 200)
        self.assertEqual([row["emp"].pk for row in response.context["rows"]], [self.employee.pk])

    def test_pending_request_queue_is_scoped(self):
        mine = Request.objects.create(employee=self.employee, kind=Request.Kind.OVERTIME)
        Request.objects.create(employee=self.other, kind=Request.Kind.OVERTIME)
        response = self.client.get(reverse("attendance:request_list"), {"tab": "pending"})
        self.assertEqual(response.status_code, 200)
        self.assertEqual([req.pk for req in response.context["requests"]], [mine.pk])

    def test_foreign_request_details_are_forbidden(self):
        foreign = Request.objects.create(employee=self.other, kind=Request.Kind.OVERTIME)
        response = self.client.get(reverse("attendance:request_detail", args=[foreign.pk]))
        self.assertEqual(response.status_code, 403)

    def test_personnel_file_listing_is_scoped_and_read_only_without_manage(self):
        response = self.client.get(reverse("org:userinfo"))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            [employee.pk for employee in response.context["employees"]],
            [self.employee.pk],
        )
        self.assertEqual(
            self.client.get(reverse("org:userinfo_edit", args=[self.other.pk])).status_code,
            404,
        )
        response = self.client.post(reverse("org:userinfo"), {
            "badge_no": "SHOULD-NOT-CREATE", "first_name": "م", "last_name": "م",
        })
        self.assertEqual(response.status_code, 403)
        self.assertFalse(Employee.objects.filter(badge_no="SHOULD-NOT-CREATE").exists())
