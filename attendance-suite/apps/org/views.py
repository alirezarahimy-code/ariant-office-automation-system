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


@require_perm('org', 'view')
def org_chart(request):
    """چارت درختی واحدها (DeptsChart)."""
    from .models import Department
    depts = list(Department.objects.filter(is_active=True))
    children = {}
    for d in depts:
        children.setdefault(d.parent_id, []).append(d)

    def tree(node_id):
        return [{'d': d, 'kids': tree(d.id)} for d in children.get(node_id, [])]
    return render(request, 'org/org_chart.html', {
        'tree': tree(None), 'count': len(depts)})


@require_perm('org', 'manage')
def titles(request):
    """سمت‌ها (Title)."""
    from .models import Position
    if request.method == 'POST':
        Position.objects.create(
            title=request.POST.get('title', '').strip(),
            department_id=request.POST.get('dept_id') or None,
            is_manager=request.POST.get('is_manager') == 'on')
        audit(request.user, 'add', 'Position')
        return redirect('org:titles')
    from .models import Department
    rows = [[p.title, p.department.name if p.department else '—',
             'بله' if p.is_manager else 'خیر']
            for p in Position.objects.select_related('department')]
    return render(request, 'org/simple_list.html', {
        'title': 'سمت‌ها', 'rows_data': rows,
        'cols': [('title', 'عنوان'), ('department', 'واحد'), ('is_manager', 'مدیریتی')],
        'form_fields': [('title', 'عنوان سمت', 'text'),
                        ('dept_id', 'واحد', 'dept'), ('is_manager', 'سمت مدیریتی', 'bool')],
        'depts': Department.objects.filter(is_active=True)})


@require_perm('org', 'manage')
def emp_types(request):
    """انواع استخدام (EmpType)."""
    from .models import EmpType
    if request.method == 'POST':
        EmpType.objects.get_or_create(
            name=request.POST.get('name', '').strip(),
            code=request.POST.get('code', '').strip())
        audit(request.user, 'add', 'EmpType')
        return redirect('org:emp_types')
    rows = [[t.name, t.code] for t in EmpType.objects.all()]
    return render(request, 'org/simple_list.html', {
        'title': 'انواع استخدام', 'rows_data': rows,
        'cols': [('name', 'نام'), ('code', 'کد')],
        'form_fields': [('name', 'نام', 'text'), ('code', 'کد', 'text')]})


@require_perm('org', 'manage')
def certificates(request):
    """مدارک تحصیلی (Certificate)."""
    if request.method == 'POST':
        from apps.core.models import AppSetting
        name = request.POST.get('name', '').strip()
        if name:
            vals = AppSetting.objects.filter(key='certificates').first()
            items = [x for x in (vals.value.split('|') if vals else []) if x]
            if name not in items:
                items.append(name)
            AppSetting.objects.update_or_create(
                key='certificates', defaults={'value': '|'.join(items),
                                              'title': 'مدارک تحصیلی'})
        return redirect('org:certificates')
    from apps.core.models import AppSetting
    vals = AppSetting.objects.filter(key='certificates').first()
    items = [x for x in (vals.value.split('|') if vals else []) if x]
    return render(request, 'org/certificates.html', {'items': items})


@require_perm('org', 'view')
def custom_fields(request):
    """فیلدهای سفارشی پرسنل (UserAddProperty)."""
    from .models import EmployeeFieldDef
    if request.method == 'POST' and has_perm(request.user, 'org', 'manage'):
        EmployeeFieldDef.objects.create(
            title=request.POST.get('title', '').strip(),
            field_type=request.POST.get('field_type') or 'text',
            options=request.POST.get('options', '').strip())
        audit(request.user, 'add', 'EmployeeFieldDef')
        return redirect('org:custom_fields')
    return render(request, 'org/custom_fields.html', {
        'fields': EmployeeFieldDef.objects.filter(is_active=True),
        'can_manage': has_perm(request.user, 'org', 'manage')})


@require_perm('org', 'view')
def userinfo(request, emp_id=None):
    """فایل پرسنلی کامل (UserInfo) — تب‌دار با همه فیلدها + فیلدهای سفارشی."""
    from .models import (Department, EmpType, Employee, EmployeeFieldDef,
                         EmployeeFieldVal, Position)
    emp = Employee.objects.select_related(
        'department', 'position', 'emp_type', 'manager').filter(pk=emp_id).first() if emp_id else None
    if request.method == 'POST':
        data = dict(
            badge_no=request.POST.get('badge_no', '').strip(),
            personnel_no=request.POST.get('personnel_no', '').strip(),
            first_name=request.POST.get('first_name', '').strip(),
            last_name=request.POST.get('last_name', '').strip(),
            father_name=request.POST.get('father_name', '').strip(),
            national_id=request.POST.get('national_id', '').strip(),
            gender=request.POST.get('gender', ''),
            birthday=request.POST.get('birthday') or None,
            certificate=request.POST.get('certificate', '').strip(),
            email=request.POST.get('email', '').strip(),
            tel=request.POST.get('tel', '').strip(),
            address=request.POST.get('address', '').strip(),
            janbaz_code=request.POST.get('janbaz_code', '').strip(),
            hire_date=request.POST.get('hire_date') or None,
            end_work_date=request.POST.get('end_work_date') or None,
            department_id=request.POST.get('dept_id') or None,
            position_id=request.POST.get('position_id') or None,
            emp_type_id=request.POST.get('emp_type_id') or None,
            device_card_no=request.POST.get('device_card_no', '').strip(),
            device_enable=request.POST.get('device_enable') == 'on',
            device_group=int(request.POST.get('device_group') or 1),
            device_password=request.POST.get('device_password', '').strip(),
            device_privilege=int(request.POST.get('device_privilege') or 0),
            web_user=request.POST.get('web_user') == 'on',
            program_user=request.POST.get('program_user') == 'on')
        if not data['badge_no'] or not data['first_name'] or not data['last_name']:
            messages.error(request, 'شماره کارگری/نام/نام خانوادگی الزامی است.')
        elif emp:
            for k, v in data.items():
                setattr(emp, k, v)
            emp.save()
            for fd in EmployeeFieldDef.objects.filter(is_active=True):
                val = request.POST.get(f'cf_{fd.pk}', '')
                EmployeeFieldVal.objects.update_or_create(
                    employee=emp, field_def=fd, defaults={'value': val})
            audit(request.user, 'change', 'Employee', emp.pk)
            messages.success(request, 'فایل پرسنلی به‌روزرسانی شد.')
            return redirect('org:userinfo_edit', emp_id=emp.pk)
        else:
            if Employee.objects.filter(badge_no=data['badge_no']).exists():
                messages.error(request, 'شماره کارگری تکراری است.')
            else:
                emp = Employee.objects.create(**data)
                audit(request.user, 'add', 'Employee', emp.pk)
                messages.success(request, 'پرسنل جدید ثبت شد.')
                return redirect('org:userinfo_edit', emp_id=emp.pk)
    cf_defs = EmployeeFieldDef.objects.filter(is_active=True)
    cf_vals = {}
    if emp:
        cf_vals = {v.field_def_id: v.value
                   for v in EmployeeFieldVal.objects.filter(employee=emp)}
    cf_pairs = [(fd, cf_vals.get(fd.pk, '')) for fd in cf_defs]
    return render(request, 'org/userinfo.html', {
        'emp': emp, 'cf_defs': cf_defs, 'cf_pairs': cf_pairs,
        'depts': Department.objects.filter(is_active=True),
        'positions': Position.objects.all(),
        'emp_types': EmpType.objects.all(),
        'employees': Employee.objects.select_related('department')[:400]})
