"""نمای «بستر یکپارچه» — نقشه زنده زیرسامانه‌ها."""
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render

from .models import Subsystem


@login_required
def platform_view(request):
    """دروازه: مدیر بستر/ابرکاربر/دارنده view_all_letter؛ در غیر این صورت کارتابل."""
    user = request.user
    allowed = (user.is_superuser
               or user.has_perm('registry.manage_platform')
               or user.has_perm('secretariat.view_all_letter'))
    if not allowed:
        messages.error(request, 'به نقشه بستر دسترسی ندارید.')
        return redirect('secretariat:kartable')
    subsystems = Subsystem.objects.all()
    return render(request, 'registry/platform.html', {
        'subsystems': subsystems,
        'native': subsystems.filter(status=Subsystem.Status.NATIVE),
        'migrating': subsystems.filter(status=Subsystem.Status.MIGRATING),
        'planned': subsystems.filter(status=Subsystem.Status.PLANNED),
        'can_manage': user.is_superuser or user.has_perm('registry.manage_platform'),
    })
