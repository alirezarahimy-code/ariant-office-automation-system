"""نماهای پل: «تیکت‌های من» و پرش SSO به سامانه تیکتینگ."""
from urllib.parse import urlencode

from django.conf import settings
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render

from .models import ExternalTicket
from .sso import build_sso_token


@login_required
def my_tickets(request):
    usernames = list(
        request.user.integration_maps.values_list('external_username', flat=True)
    ) or [request.user.username]
    tickets = ExternalTicket.objects.filter(requester_username__in=usernames)
    return render(request, 'bridge/my_tickets.html', {'tickets': tickets})


@login_required
def jump(request):
    """پرش یکپارچه با توکن کوتاه‌مدت امضاشده (قرارداد docs/14 بند ۷)."""
    conf = settings.TICKET_BRIDGE
    if not conf.get('base_url'):
        messages.error(request, 'آدرس سامانه تیکتینگ تنظیم نشده است (متغیر TICKET_BRIDGE_URL).')
        return redirect('secretariat:kartable')
    mapping = request.user.integration_maps.filter(system=conf['system']).first()
    external_id = mapping.external_user_id if mapping and mapping.external_user_id else request.user.pk
    role = 'admin' if (request.user.is_superuser
                       or request.user.has_perm('secretariat.view_all_letter')) else 'user'
    token = build_sso_token(
        external_id, role, 'it', conf['sso_key'], ttl=120,
        return_url=request.build_absolute_uri('/'))
    target = f"{conf['base_url'].rstrip('/')}/?{urlencode({'sso': token})}"
    return redirect(target)
