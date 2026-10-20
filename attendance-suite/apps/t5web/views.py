from django.contrib import messages
from django.db import IntegrityError, transaction
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse

from apps.core.models import Permission, Role, User, UserRole
from apps.core.services import audit, require_perm, scoped_employees
from apps.org.models import Employee

from .forms import ManagerAccountForm, T5RoleForm


@require_perm("core", "manage")
def managers(request):
    """T5 manager screen: maintain the one account tied to each employee."""
    selected_id = request.POST.get("user_id") if request.method == "POST" else request.GET.get("user_id")
    allowed_people = scoped_employees(request.user, 'org', Employee.objects.all())
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
        "employee"
    ).prefetch_related("user_roles__role", "program_access").order_by("username")
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
