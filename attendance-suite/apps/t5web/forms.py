from django import forms
from django.contrib.auth import get_user_model
from django.contrib.auth.password_validation import validate_password
from django.core.exceptions import ValidationError
from django.db.models import Q

from apps.core.models import Permission, ProgramAccess, Role, RoleDataScope, UserRole
from apps.core.services import data_scope, has_perm, scoped_employees
from apps.device.models import Device
from apps.org.models import Employee, ManagerAccessRule, ManagerProfile
from .scoping import scope_manager_devices, scope_manager_employees

User = get_user_model()


class ManagerAccountForm(forms.Form):
    """Manage a T5 account against an existing personnel identity."""

    employee = forms.ModelChoiceField(
        label="پرسنل", queryset=Employee.objects.filter(is_active=True),
        empty_label="انتخاب پرسنل", required=True,
    )
    username = forms.CharField(label="نام کاربری", max_length=150)
    password = forms.CharField(
        label="گذرواژه جدید", required=False, strip=False,
        widget=forms.PasswordInput(render_value=False),
        help_text="برای حفظ گذرواژه فعلی، این بخش را خالی بگذارید.",
    )
    roles = forms.ModelMultipleChoiceField(
        label="نقش‌ها و مجوزها", queryset=Role.objects.order_by("name"),
        required=False, widget=forms.CheckboxSelectMultiple,
    )
    t5web_access = forms.BooleanField(label="T5Web", required=False)
    twf_access = forms.BooleanField(label="TWF", required=False)
    link5_access = forms.BooleanField(label="Link5", required=False)
    is_active = forms.BooleanField(label="حساب فعال", required=False, initial=True)
    manager_profile_enabled = forms.BooleanField(
        label="ثبت این پرونده در Manager مرجع T5", required=False)
    manager_name = forms.CharField(
        label="نام مدیر T5", max_length=160, required=False)
    manager_is_main = forms.BooleanField(label="مدیر اصلی T5", required=False)
    manager_allowed_users = forms.ModelMultipleChoiceField(
        label="پرسنل مجاز مدیر", queryset=Employee.objects.none(), required=False,
        widget=forms.SelectMultiple(attrs={'size': 7}))
    manager_allowed_devices = forms.ModelMultipleChoiceField(
        label="دستگاه‌های مجاز مدیر", queryset=Device.objects.none(), required=False,
        widget=forms.SelectMultiple(attrs={'size': 7}))
    manager_access_rules = forms.CharField(
        label="AccessType و سطح دسترسی T5", required=False,
        widget=forms.Textarea(attrs={'rows': 5, 'dir': 'ltr'}),
        help_text=("هر سطر: کلید عددی AccessType=allow یا deny یا read_only. "
                   "کلید به‌صورت خام نگهداری می‌شود؛ این تنظیم به‌تنهایی مجوز Django ایجاد نمی‌کند."))

    def __init__(self, *args, instance=None, actor=None, **kwargs):
        self.instance = instance
        self.actor = actor
        super().__init__(*args, **kwargs)
        people = Employee.objects.all()
        if actor is not None:
            people = scoped_employees(actor, 'org', people)
            people = scope_manager_employees(actor, people)
        self.manager_people = people.filter(is_active=True)
        self.fields['manager_allowed_users'].queryset = self.manager_people
        self.can_manage_manager_devices = bool(
            actor and has_perm(actor, 'device', 'manage') and
            data_scope(actor, 'device') == 'all')
        self.fields['manager_allowed_devices'].queryset = (
            scope_manager_devices(actor, Device.objects.filter(is_active=True).order_by('name'))
            if self.can_manage_manager_devices else Device.objects.none())
        if not self.can_manage_manager_devices:
            self.fields['manager_allowed_devices'].disabled = True
        self.manager_profile = None
        if instance and instance.employee_id:
            try:
                self.manager_profile = instance.employee.t5_manager_profile
            except ManagerProfile.DoesNotExist:
                pass
        if self.manager_profile:
            self.initial.update({
                'manager_profile_enabled': True,
                'manager_name': self.manager_profile.name,
                'manager_is_main': self.manager_profile.is_main_manager,
                'manager_allowed_users': self.manager_profile.allowed_users.filter(
                    pk__in=self.manager_people.values('pk')).values_list('pk', flat=True),
                'manager_access_rules': '\\n'.join(
                    f'{rule.access_type}={rule.access_level}'
                    for rule in self.manager_profile.access_rules.all()),
            })
            if self.can_manage_manager_devices:
                self.initial['manager_allowed_devices'] = (
                    scope_manager_devices(
                        actor, self.manager_profile.allowed_devices.filter(is_active=True))
                    .values_list('pk', flat=True))
            else:
                visible_devices = scope_manager_devices(
                    actor, self.manager_profile.allowed_devices.all())
                self.fields['manager_allowed_devices'].queryset = visible_devices
                self.initial['manager_allowed_devices'] = visible_devices.values_list(
                    'pk', flat=True)
        if instance:
            available = Q(user__isnull=True) | Q(user=instance)
            self.fields["employee"].queryset = people.filter(available)
            self.initial.update({
                "employee": instance.employee_id,
                "username": instance.username,
                "roles": instance.user_roles.values_list("role_id", flat=True),
                "is_active": instance.is_active,
                "t5web_access": self._has_access(instance, ProgramAccess.Program.T5WEB),
                "twf_access": self._has_access(instance, ProgramAccess.Program.TWF),
                "link5_access": self._has_access(instance, ProgramAccess.Program.LINK5),
            })
        else:
            # Show active personnel even when already linked so validation can
            # explain the one-account-per-person rule instead of a generic
            # invalid-choice error.
            self.fields["employee"].queryset = people.filter(is_active=True)
            self.initial.setdefault("t5web_access", True)

    @staticmethod
    def _has_access(user, program):
        if (program == ProgramAccess.Program.T5WEB and user.employee_id
                and not user.employee.program_user):
            return False
        grant = ProgramAccess.objects.filter(user=user, program=program).first()
        if grant is not None:
            return grant.is_allowed
        return bool(program == ProgramAccess.Program.T5WEB
                    and user.employee_id and user.employee.program_user)

    def clean_username(self):
        username = self.cleaned_data["username"].strip()
        qs = User.objects.filter(username__iexact=username)
        if self.instance:
            qs = qs.exclude(pk=self.instance.pk)
        if qs.exists():
            raise ValidationError("این نام کاربری قبلاً ثبت شده است.")
        return username

    def clean_employee(self):
        employee = self.cleaned_data["employee"]
        qs = User.objects.filter(employee=employee)
        if self.instance:
            qs = qs.exclude(pk=self.instance.pk)
        if qs.exists():
            raise ValidationError("برای این پرسنل حساب کاربری دیگری وجود دارد.")
        return employee

    def clean_manager_access_rules(self):
        text = self.cleaned_data.get('manager_access_rules', '')
        rows = []
        seen = set()
        allowed_levels = set(ManagerProfile.AccessLevel.values)
        for line_number, line in enumerate(text.splitlines(), start=1):
            line = line.strip()
            if not line:
                continue
            if '=' not in line:
                raise ValidationError(
                    f'سطر {line_number}: قالب باید AccessType=سطح باشد.')
            raw_type, raw_level = (part.strip() for part in line.split('=', 1))
            try:
                access_type = int(raw_type)
            except ValueError as exc:
                raise ValidationError(f'سطر {line_number}: AccessType عددی نیست.') from exc
            level = raw_level.lower().replace('-', '_')
            if level == 'readonly':
                level = 'read_only'
            if access_type < 0 or access_type > 2147483647:
                raise ValidationError(f'سطر {line_number}: AccessType خارج از محدوده است.')
            if level not in allowed_levels:
                raise ValidationError(
                    f'سطر {line_number}: سطح باید allow، deny یا read_only باشد.')
            if access_type in seen:
                raise ValidationError(f'AccessType تکراری است: {access_type}.')
            seen.add(access_type)
            rows.append((access_type, level))
        return rows

    def clean(self):
        cleaned = super().clean()
        employee = cleaned.get("employee")
        if employee and not employee.is_active:
            if cleaned.get("is_active"):
                self.add_error("is_active", "برای پرسنل غیرفعال، حساب نیز باید غیرفعال باشد.")
            if any(cleaned.get(name) for name in ("t5web_access", "twf_access", "link5_access")):
                self.add_error("employee", "دسترسی برنامه‌ای برای پرسنل غیرفعال مجاز نیست.")
        password = cleaned.get("password")
        if not self.instance and not password:
            self.add_error("password", "برای حساب جدید گذرواژه الزامی است.")
        elif password:
            candidate = User(
                username=cleaned.get("username", ""),
                employee=cleaned.get("employee"),
            )
            try:
                validate_password(password, candidate)
            except ValidationError as exc:
                self.add_error("password", exc)
        return cleaned

    def save(self, *, commit=True, granted_by=None):
        if self.instance:
            user = self.instance
        else:
            user = User()
        employee = self.cleaned_data["employee"]
        user.employee = employee
        user.username = self.cleaned_data["username"]
        user.is_active = self.cleaned_data["is_active"]
        if not self.instance or self.cleaned_data.get("password"):
            user.must_change_password = True
        if self.cleaned_data.get("password"):
            user.set_password(self.cleaned_data["password"])
        if commit:
            user.save()
            employee.web_user = self.cleaned_data["t5web_access"]
            employee.program_user = self.cleaned_data["t5web_access"]
            employee.save(update_fields=["web_user", "program_user"])
            UserRole.objects.filter(user=user).delete()
            UserRole.objects.bulk_create([
                UserRole(user=user, role=role)
                for role in self.cleaned_data["roles"]
            ])
            for program, field_name in (
                (ProgramAccess.Program.T5WEB, "t5web_access"),
                (ProgramAccess.Program.TWF, "twf_access"),
                (ProgramAccess.Program.LINK5, "link5_access"),
            ):
                ProgramAccess.objects.update_or_create(
                    user=user,
                    program=program,
                    defaults={
                        "is_allowed": self.cleaned_data[field_name],
                        "granted_by": granted_by,
                    },
                )
            if self.cleaned_data['manager_profile_enabled']:
                profile, _ = ManagerProfile.objects.get_or_create(employee=employee)
                profile.name = self.cleaned_data['manager_name'].strip() or str(employee)
                profile.is_main_manager = self.cleaned_data['manager_is_main']
                profile.save()
                selected_user_ids = set(
                    self.cleaned_data['manager_allowed_users'].values_list('pk', flat=True))
                editable_user_ids = set(self.manager_people.values_list('pk', flat=True))
                preserved_user_ids = set(profile.allowed_users.values_list(
                    'pk', flat=True)) - editable_user_ids
                profile.allowed_users.set(preserved_user_ids | selected_user_ids)
                if self.can_manage_manager_devices:
                    selected_device_ids = set(
                        self.cleaned_data['manager_allowed_devices'].values_list(
                            'pk', flat=True))
                    editable_device_ids = set(
                        self.fields['manager_allowed_devices'].queryset.values_list(
                            'pk', flat=True))
                    preserved_device_ids = set(profile.allowed_devices.values_list(
                        'pk', flat=True)) - editable_device_ids
                    profile.allowed_devices.set(
                        preserved_device_ids | selected_device_ids)
                profile.access_rules.all().delete()
                ManagerAccessRule.objects.bulk_create([
                    ManagerAccessRule(manager=profile, access_type=access_type,
                                      access_level=level)
                    for access_type, level in self.cleaned_data['manager_access_rules']
                ])
            elif self.manager_profile:
                # Remove reference-manager metadata only; retain the login account.
                self.manager_profile.delete()
        return user


class T5RoleForm(forms.Form):
    name = forms.CharField(label="نام نقش", max_length=80)
    code = forms.SlugField(label="کد نقش", max_length=40, allow_unicode=False)
    permissions = forms.ModelMultipleChoiceField(
        label="مجوزهای عملیاتی",
        queryset=Permission.objects.none(),
        required=False,
        widget=forms.CheckboxSelectMultiple,
    )
    def __init__(self, *args, instance=None, **kwargs):
        self.instance = instance
        super().__init__(*args, **kwargs)
        self.fields["permissions"].queryset = Permission.objects.order_by("module", "action")
        self.fields["permissions"].label_from_instance = lambda p: (
            f"{p.get_module_display()} — {p.get_action_display()}"
        )
        if instance:
            self.initial.update({
                "name": instance.name,
                "code": instance.code,
                "permissions": instance.permissions.values_list("pk", flat=True),
            })
        for module, module_label in Permission.MODULES:
            field_name = f"scope_{module}"
            self.fields[field_name] = forms.ChoiceField(
                label=f"دامنهٔ داده — {module_label}",
                choices=RoleDataScope.Scope.choices,
            )
            current = (
                RoleDataScope.objects.filter(role=instance, module=module)
                .values_list("scope", flat=True).first()
                if instance else None
            )
            self.initial.setdefault(field_name, current or RoleDataScope.Scope.SELF)

    def clean_code(self):
        code = self.cleaned_data["code"].strip().lower()
        qs = Role.objects.filter(code=code)
        if self.instance:
            qs = qs.exclude(pk=self.instance.pk)
        if qs.exists():
            raise ValidationError("این کد نقش قبلاً استفاده شده است.")
        return code

    def save(self, *, commit=True):
        role = self.instance or Role()
        role.name = self.cleaned_data["name"].strip()
        role.code = self.cleaned_data["code"]
        if commit:
            role.save()
            role.permissions.set(self.cleaned_data["permissions"])
            for module, _ in Permission.MODULES:
                RoleDataScope.objects.update_or_create(
                    role=role,
                    module=module,
                    defaults={"scope": self.cleaned_data[f"scope_{module}"]},
                )
        return role
