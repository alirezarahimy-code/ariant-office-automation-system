"""خدمات هسته: کنترل دسترسی (RBAC + دامنه داده)، گزارش دسترسی، محدودسازی نرخ."""
from __future__ import annotations

from functools import wraps

from django.core.cache import cache
from django.shortcuts import redirect

from .models import AuditLog, Permission, RoleDataScope, UserRole

RATE_LIMIT_ATTEMPTS = 5          # تعداد تلاش مجاز
RATE_LIMIT_WINDOW = 300          # بازه ثانیه


def user_permissions(user) -> set[str]:
    """کدهای مجوز کاربر به شکل 'module.action'."""
    if not user.is_authenticated:
        return set()
    if user.is_superuser:
        return {f'{m}.{a}' for m, _ in Permission.MODULES
                for a, _ in Permission.ACTIONS}
    codes = set()
    for role in user.user_roles.select_related('role').all():
        for p in role.role.permissions.all():
            codes.add(f'{p.module}.{p.action}')
    return codes


def has_perm(user, module: str, action: str) -> bool:
    return (user.is_superuser
            or f'{module}.{action}' in user_permissions(user))


def require_perm(module: str, action: str):
    """دکوراتور: الزام مجوز + ثبت در گزارش دسترسی."""

    def decorator(view):
        @wraps(view)
        def wrapper(request, *args, **kwargs):
            if not request.user.is_authenticated:
                return redirect('core:login')
            if not has_perm(request.user, module, action):
                audit(request.user, 'denied', model=f'{module}.{action}',
                      detail=request.path, ip=_client_ip(request))
                return _forbidden(request)
            return view(request, *args, **kwargs)
        return wrapper
    return decorator


def _forbidden(request):
    from django.http import HttpResponseForbidden
    return HttpResponseForbidden(
        '<div dir="rtl" style="font-family:Tahoma;padding:40px">'
        'شما مجوز دسترسی به این بخش را ندارید. '
        '<a href="/">بازگشت</a></div>')


def data_scope(user, module: str) -> str:
    """گسترده‌ترین دامنه داده کاربر در ماژول."""
    if user.is_superuser:
        return RoleDataScope.Scope.ALL
    scope = RoleDataScope.Scope.SELF
    rank = {'self': 0, 'dept': 1, 'dept_tree': 2, 'all': 3}
    roles = [ur.role_id for ur in user.user_roles.all()]
    for ds in RoleDataScope.objects.filter(role_id__in=roles, module=module):
        if rank[ds.scope] > rank[scope]:
            scope = ds.scope
    return scope


def scoped_employees(user, module: str, qs):
    """اعمال دامنه داده روی کوئری Employee بر اساس نقش کاربر."""
    scope = data_scope(user, module)
    if scope == RoleDataScope.Scope.ALL or user.is_superuser:
        return qs
    emp = getattr(user, 'employee', None)
    if emp is None:
        return qs.none()
    if scope == RoleDataScope.Scope.SELF:
        return qs.filter(pk=emp.pk)
    depts = [emp.department_id] if emp.department_id else []
    if scope == RoleDataScope.Scope.DEPT_TREE and emp.department_id:
        depts = [d.pk for d in emp.department.descendants()]
    return qs.filter(department_id__in=depts)


def audit(user, action: str, model: str = '', object_id='', detail: str = '',
          ip=None) -> None:
    AuditLog.objects.create(
        user=user if getattr(user, 'is_authenticated', False) else None,
        action=action, model=model, object_id=str(object_id)[:40],
        detail=detail[:1000], ip=ip)


def _client_ip(request) -> str | None:
    forwarded = request.META.get('HTTP_X_FORWARDED_FOR')
    if forwarded:
        return forwarded.split(',')[0].strip()
    return request.META.get('REMOTE_ADDR')


def login_throttled(username: str, ip: str) -> bool:
    """True یعنی محدود شده (بیش از حد مجاز در بازه)."""
    key = f'login-fail:{username}:{ip}'
    fails = cache.get(key, 0)
    return fails >= RATE_LIMIT_ATTEMPTS


def record_login_failure(username: str, ip: str) -> None:
    key = f'login-fail:{username}:{ip}'
    cache.set(key, cache.get(key, 0) + 1, RATE_LIMIT_WINDOW)


def clear_login_failures(username: str, ip: str) -> None:
    cache.delete(f'login-fail:{username}:{ip}')
