from django.contrib.auth.decorators import login_required
from django.shortcuts import render


@login_required
def index(request):
    from .models import Department, Employee
    return render(request, 'org/index.html', {
        'departments': Department.objects.filter(is_active=True),
        'employees': Employee.objects.filter(is_active=True)[:50],
    })
