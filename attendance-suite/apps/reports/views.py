from django.contrib.auth.decorators import login_required
from django.shortcuts import render


@login_required
def index(request):
    from .models import ReportDef
    return render(request, 'reports/index.html', {
        'reports': ReportDef.objects.all(),
    })
