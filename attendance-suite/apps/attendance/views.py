import jdatetime
from django.contrib.auth.decorators import login_required
from django.shortcuts import render

from .services import monthly_summary


@login_required
def dashboard(request):
    today = jdatetime.date.today()
    employee = getattr(request.user, 'employee', None)
    summary = None
    if employee:
        summary = monthly_summary(employee, today.year, today.month)
    return render(request, 'attendance/dashboard.html', {
        'summary': summary, 'today': today,
    })
