from django.contrib.auth.decorators import login_required
from django.shortcuts import render


@login_required
def index(request):
    from .models import Device
    return render(request, 'device/index.html', {
        'devices': Device.objects.filter(is_active=True),
    })
