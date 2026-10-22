from time import sleep

from django.contrib import messages
from django.db import IntegrityError, transaction
from django.http import HttpResponse, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.views.decorators.http import require_GET, require_POST

from apps.core.models import Permission, Role, User, UserRole
from apps.core.services import audit, require_perm, scoped_employees
from apps.org.models import Employee
from .scoping import scope_manager_employees

from .forms import ManagerAccountForm, T5RoleForm


LICENSE_CHANGE_TYPES = (
    ("1", "فعال‌سازی"),
    ("2", "محدودیت پرسنل"),
    ("3", "محدودیت ایستگاه"),
    ("4", "محدودیت دستگاه"),
    ("5", "محدودیت نسخهٔ آزمایشی"),
    ("6", "محدودیت زمانی برنامه"),
)


@require_perm("core", "manage")
@require_GET
def change_lock(request):
    """Render the ChangeLock form with the six reference change types."""
    return render(request, "t5web/change_lock.html", {
        "change_types": LICENSE_CHANGE_TYPES,
        "license_check_url": reverse("t5web:change_lock_check"),
    })


@require_perm("core", "manage")
@require_POST
def change_lock_check(request):
    """Pass one submitted code to the configured trusted provider, fail closed."""
    from apps.core.licensing import (
        LicenseCheckResult, configured_license_checker,
    )

    change_type = request.POST.get("ChangeType", "")
    code = request.POST.get("Code", "").strip()
    if change_type not in {value for value, _ in LICENSE_CHANGE_TYPES}:
        return JsonResponse({
            "ok": False, "message": "نوع تغییر مجوز معتبر نیست.",
        }, status=400)
    if not code or len(code) > 512:
        return JsonResponse({
            "ok": False, "message": "کد مجوز باید بین ۱ تا ۵۱۲ نویسه باشد.",
        }, status=400)

    try:
        checker = configured_license_checker()
        if checker is None:
            # The exact shipped ChangeLock.Check body in T5V3Web 3.5.7
            # returns Persian.ErrorOperation after a one-second delay; it
            # does not inspect either submitted argument or mutate license data.
            sleep(1)
            return HttpResponse(
                "خطا در انجام عملیات",
                content_type="text/plain; charset=utf-8",
                status=200,
            )
        result = checker.check(change_type, code)
        if not isinstance(result, LicenseCheckResult):
            raise TypeError("License provider returned an untyped result")
        # Do not surface provider-supplied text: even a trusted integration
        # could include the submitted activation code in its diagnostic.
        message = (
            "درخواست توسط سرویس معتبر لایسنس پذیرفته شد."
            if result.accepted else
            "درخواست توسط سرویس معتبر لایسنس پذیرفته نشد."
        )
        audit(request.user,
              "license_change_accepted" if result.accepted else "license_change_rejected",
              "T5License", detail=f"change_type={change_type}; provider=configured")
        return JsonResponse({"ok": result.accepted, "message": message},
                            status=200 if result.accepted else 400)
    except Exception:
        # Never expose provider exception details: an integration could echo
        # the submitted code or sensitive vendor response data.
        audit(request.user, "license_check_failed", "T5License",
              detail=f"change_type={change_type}; provider=configured")
        return JsonResponse({
            "ok": False,
            "message": "نتیجه از سرویس لایسنس دریافت نشد؛ وضعیت نهایی را از سرویس معتبر پیگیری کنید.",
        }, status=503)


@require_perm("core", "manage")
def managers(request):
    """T5 manager screen: maintain the one account tied to each employee."""
    selected_id = request.POST.get("user_id") if request.method == "POST" else request.GET.get("user_id")
    allowed_people = scope_manager_employees(
        request.user, scoped_employees(request.user, 'org', Employee.objects.all()))
    allowed_users = User.objects.filter(employee__in=allowed_people)
    instance = get_object_or_404(allowed_users, pk=selected_id) if selected_id else None

    if request.method == "POST":
        form = ManagerAccountForm(request.POST, instance=instance, actor=request.user)
        if form.is_valid():
            try:
                with transaction.atomic():
                    user = form.save(granted_by=request.user)
                    audit(
                        request.user,
                        "change" if instance else "add",
                        "T5UserAccess",
                        user.pk,
                        detail=(
                            f"employee={user.employee_id}; active={user.is_active}; "
                            f"roles={','.join(user.user_roles.values_list('role__code', flat=True))}; "
                            f"programs={','.join(user.program_access.filter(is_allowed=True).values_list('program', flat=True))}"
                        ),
                    )
                messages.success(request, "دسترسی کاربر ذخیره شد.")
                return redirect(f"{reverse('t5web:managers')}?user_id={user.pk}")
            except IntegrityError:
                form.add_error(
                    None,
                    "حساب یا پیوند پرسنلی هم‌زمان توسط کاربر دیگری ثبت شده است؛ صفحه را تازه‌سازی کنید.",
                )
    else:
        form = ManagerAccountForm(instance=instance, actor=request.user)

    users = allowed_users.select_related(
        "employee", "employee__t5_manager_profile"
    ).prefetch_related(
        "user_roles__role", "program_access",
        "employee__t5_manager_profile__allowed_users",
        "employee__t5_manager_profile__allowed_devices",
        "employee__t5_manager_profile__access_rules",
    ).order_by("username")
    return render(request, "t5web/managers.html", {
        "form": form,
        "users": users,
        "selected_user": instance,
    })


@require_perm("core", "manage")
def roles(request):
    """Create/update the T5-owned roles and their action/data-scope grants."""
    for module, _ in Permission.MODULES:
        for action, _ in Permission.ACTIONS:
            Permission.objects.get_or_create(module=module, action=action)

    selected_id = request.POST.get("role_id") if request.method == "POST" else request.GET.get("role_id")
    instance = get_object_or_404(Role, pk=selected_id) if selected_id else None

    if request.method == "POST":
        form = T5RoleForm(request.POST, instance=instance)
        if form.is_valid():
            try:
                with transaction.atomic():
                    role = form.save()
                    audit(
                        request.user,
                        "change" if instance else "add",
                        "T5Role",
                        role.pk,
                        detail=(
                            f"code={role.code}; "
                            f"permissions={','.join(sorted(f'{p.module}.{p.action}' for p in role.permissions.all()))}; "
                            f"scopes={','.join(f'{s.module}:{s.scope}' for s in role.data_scopes.order_by('module'))}"
                        ),
                    )
                messages.success(request, "نقش و دامنه‌های داده ذخیره شد.")
                return redirect(f"{reverse('t5web:roles')}?role_id={role.pk}")
            except IntegrityError:
                form.add_error(None, "نقش با این کد هم‌زمان ثبت شده است؛ فهرست را تازه کنید.")
    else:
        form = T5RoleForm(instance=instance)

    role_list = Role.objects.prefetch_related("permissions", "data_scopes").order_by("name")
    return render(request, "t5web/roles.html", {
        "form": form,
        "roles": role_list,
        "selected_role": instance,
    })
