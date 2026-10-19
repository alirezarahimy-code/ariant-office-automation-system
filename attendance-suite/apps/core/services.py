"""خدمات هسته: کنترل دسترسی (RBAC + دامنه داده) و گزارش دسترسی."""
from __future__ import annotations

from .models import AuditLog, Permission, RoleDataScope, UserRole


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
        action=action, model=model, object_id=str(object_id),
        detail=detail, ip=ip)
