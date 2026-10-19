"""سازمان و پرسنل — فرم‌ها با الزام مجوز و دامنه داده."""
from django.contrib import messages
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import audit, has_perm, require_perm, scoped_employees

from .models import Department, EmpType, Employee, Position


@require_perm('org', 'view')
def index(request):
    return render(request, 'org/index.html', {
        'departments': Department.objects.filter(is_active=True),
        'employees': scoped_employees(
            request.user, 'org',
            Employee.objects.filter(is_active=True))[:200],
    })


@require_perm('org', 'add')
def employee_new(request):
    if request.method == 'POST':
        badge = (request.POST.get('badge_no') or '').strip()
        if Employee.objects.filter(badge_no=badge).exists():
            messages.error(request, 'شماره کارگری تکراری است.')
        else:
            emp = Employee.objects.create(
                badge_no=badge,
                first_name=request.POST.get('first_name', '').strip(),
                last_name=request.POST.get('last_name', '').strip(),
                national_id=request.POST.get('national_id', '').strip(),
                department_id=request.POST.get('department_id') or None,
                position_id=request.POST.get('position_id') or None,
                emp_type_id=request.POST.get('emp_type_id') or None,
                manager_id=request.POST.get('manager_id') or None,
            )
            audit(request.user, 'add', 'Employee', emp.pk)
            messages.success(request, 'کارمند ثبت شد.')
            return redirect('org:index')
    return render(request, 'org/employee_form.html', {
        'departments': Department.objects.filter(is_active=True),
        'positions': Position.objects.all(),
        'emp_types': EmpType.objects.all(),
        'employees': Employee.objects.filter(is_active=True),
    })


@require_perm('org', 'change')
def employee_edit(request, pk):
    emp = get_object_or_404(Employee, pk=pk)
    if request.method == 'POST':
        emp.first_name = request.POST.get('first_name', emp.first_name).strip()
        emp.last_name = request.POST.get('last_name', emp.last_name).strip()
        emp.national_id = request.POST.get('national_id', '').strip()
        emp.department_id = request.POST.get('department_id') or None
        emp.position_id = request.POST.get('position_id') or None
        emp.manager_id = request.POST.get('manager_id') or None
        emp.is_active = request.POST.get('is_active') == 'on'
        emp.save()
        audit(request.user, 'change', 'Employee', emp.pk)
        messages.success(request, 'تغییرات ذخیره شد.')
        return redirect('org:index')
    return render(request, 'org/employee_form.html', {
        'emp': emp,
        'departments': Department.objects.filter(is_active=True),
        'positions': Position.objects.all(),
        'emp_types': EmpType.objects.all(),
        'employees': Employee.objects.filter(is_active=True).exclude(pk=emp.pk),
    })


@require_perm('org', 'manage')
def department_new(request):
    if request.method == 'POST':
        Department.objects.create(
            name=request.POST.get('name', '').strip(),
            code=request.POST.get('code', '').strip(),
            parent_id=request.POST.get('parent_id') or None)
        audit(request.user, 'add', 'Department')
        messages.success(request, 'واحد ثبت شد.')
        return redirect('org:index')
    return render(request, 'org/department_form.html', {
        'departments': Department.objects.all()})


@require_perm('org', 'manage')
def position_new(request):
    if request.method == 'POST':
        Position.objects.create(
            title=request.POST.get('title', '').strip(),
            parent_id=request.POST.get('parent_id') or None,
            department_id=request.POST.get('department_id') or None,
            is_manager=request.POST.get('is_manager') == 'on')
        audit(request.user, 'add', 'Position')
        messages.success(request, 'سمت ثبت شد.')
        return redirect('org:index')
    return render(request, 'org/position_form.html', {
        'positions': Position.objects.all(),
        'departments': Department.objects.filter(is_active=True)})
