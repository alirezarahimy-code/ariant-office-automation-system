"""T5 manager allow-list scope; kept separate from TWF/Link5 and RBAC grants."""

from django.core.exceptions import ObjectDoesNotExist


def manager_profile_for(user):
    if not user or getattr(user, 'is_superuser', False):
        return None
    try:
        employee = user.employee
    except ObjectDoesNotExist:
        return None
    if employee is None:
        return None
    try:
        return employee.t5_manager_profile
    except ObjectDoesNotExist:
        return None


def scope_manager_employees(user, queryset):
    """Restrict T5 personnel operations to this manager's explicit AllowUsers."""
    profile = manager_profile_for(user)
    if profile is None:
        return queryset
    return queryset.filter(pk__in=profile.allowed_users.values('pk'))


def scope_manager_devices(user, queryset):
    """Restrict T5 manager device selection/operations to explicit AllowDevices."""
    profile = manager_profile_for(user)
    if profile is None:
        return queryset
    return queryset.filter(pk__in=profile.allowed_devices.values('pk'))


def set_employee_device_assignments(user, employee, upload_devices, valid_devices):
    """Update only devices the acting T5 manager is allowed to administer."""
    profile = manager_profile_for(user)
    if profile is None:
        employee.upload_devices.set(upload_devices)
        employee.valid_devices.set(valid_devices)
        return
    permitted_ids = set(profile.allowed_devices.values_list('pk', flat=True))
    upload_ids = set(employee.upload_devices.values_list('pk', flat=True)) - permitted_ids
    valid_ids = set(employee.valid_devices.values_list('pk', flat=True)) - permitted_ids
    upload_ids.update(device.pk for device in upload_devices)
    valid_ids.update(device.pk for device in valid_devices)
    employee.upload_devices.set(upload_ids)
    employee.valid_devices.set(valid_ids)
