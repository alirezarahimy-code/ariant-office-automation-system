"""سازمان و پرسنل — فرم‌ها با الزام مجوز و دامنه داده."""
from django.contrib import messages
from django.db.models import Count, Q
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import (audit, data_scope, has_perm, require_perm,
                                scoped_departments, scoped_employees, _forbidden)

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
    allowed_depts = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    allowed_employees = scoped_employees(
        request.user, 'org', Employee.objects.filter(is_active=True))
    if request.method == 'POST':
        badge = (request.POST.get('badge_no') or '').strip()
        first_name = request.POST.get('first_name', '').strip()
        last_name = request.POST.get('last_name', '').strip()
        department = allowed_depts.filter(pk=request.POST.get('department_id')).first()
        position = Position.objects.filter(
            pk=request.POST.get('position_id') or 0
        ).filter(Q(department=department) | Q(department__isnull=True)).first()
        manager = allowed_employees.filter(pk=request.POST.get('manager_id') or 0).first()
        has_dept_wide_scope = request.user.is_superuser or data_scope(
            request.user, 'org') == 'all'
        if not badge or not first_name or not last_name:
            messages.error(request, 'شماره کارگری، نام و نام خانوادگی الزامی است.')
        elif Employee.objects.filter(badge_no=badge).exists():
            messages.error(request, 'شماره کارگری تکراری است.')
        elif request.POST.get('department_id') and department is None:
            messages.error(request, 'واحد انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif request.POST.get('department_id') and request.POST.get('position_id') and position is None:
            messages.error(request, 'سمت انتخاب‌شده با واحد مجاز مطابقت ندارد.')
        elif request.POST.get('manager_id') and manager is None:
            messages.error(request, 'مدیر انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif department is None and not has_dept_wide_scope:
            messages.error(request, 'برای ثبت پرسنل باید واحدی در دامنهٔ مجاز انتخاب شود.')
        else:
            emp = Employee.objects.create(
                badge_no=badge, first_name=first_name, last_name=last_name,
                national_id=request.POST.get('national_id', '').strip(),
                department=department, position=position,
                emp_type_id=request.POST.get('emp_type_id') or None,
                manager=manager,
            )
            audit(request.user, 'add', 'Employee', emp.pk)
            messages.success(request, 'کارمند ثبت شد.')
            return redirect('org:index')
    allowed_positions = Position.objects.filter(
        Q(department__in=allowed_depts) | Q(department__isnull=True))
    return render(request, 'org/employee_form.html', {
        'departments': allowed_depts,
        'positions': allowed_positions,
        'emp_types': EmpType.objects.all(),
        'employees': allowed_employees,
    })


@require_perm('org', 'change')
def employee_edit(request, pk):
    emp = get_object_or_404(
        scoped_employees(request.user, 'org', Employee.objects.all()), pk=pk)
    allowed_depts = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    allowed_employees = scoped_employees(
        request.user, 'org', Employee.objects.filter(is_active=True)
    ).exclude(pk=emp.pk)
    if request.method == 'POST':
        department_id = request.POST.get('department_id') or None
        department = allowed_depts.filter(pk=department_id).first() if department_id else None
        position = Position.objects.filter(
            pk=request.POST.get('position_id') or 0
        ).filter(Q(department=department) | Q(department__isnull=True)).first()
        manager = allowed_employees.filter(pk=request.POST.get('manager_id') or 0).first()
        has_all_dept_scope = request.user.is_superuser or data_scope(
            request.user, 'org') == 'all'
        first_name = request.POST.get('first_name', '').strip()
        last_name = request.POST.get('last_name', '').strip()
        if not first_name or not last_name:
            messages.error(request, 'نام و نام خانوادگی الزامی است.')
        elif department_id and department is None:
            messages.error(request, 'واحد انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif request.POST.get('position_id') and position is None:
            messages.error(request, 'سمت انتخاب‌شده با واحد مجاز مطابقت ندارد.')
        elif request.POST.get('manager_id') and manager is None:
            messages.error(request, 'مدیر انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif department is None and not has_all_dept_scope:
            messages.error(request, 'انتخاب واحد در دامنهٔ مجاز الزامی است.')
        else:
            emp.first_name = first_name
            emp.last_name = last_name
            emp.national_id = request.POST.get('national_id', '').strip()
            emp.department = department
            emp.position = position
            emp.manager = manager
            emp.is_active = request.POST.get('is_active') == 'on'
            emp.save()
            audit(request.user, 'change', 'Employee', emp.pk)
            messages.success(request, 'تغییرات ذخیره شد.')
            return redirect('org:index')
    allowed_positions = Position.objects.filter(
        Q(department__in=allowed_depts) | Q(department__isnull=True))
    return render(request, 'org/employee_form.html', {
        'emp': emp,
        'departments': allowed_depts,
        'positions': allowed_positions,
        'emp_types': EmpType.objects.all(),
        'employees': allowed_employees,
    })


@require_perm('org', 'manage')
def department_new(request):
    """Compatibility path; department maintenance lives in the T5 tree screen."""
    return redirect('org:department_admin')


@require_perm('org', 'manage')
def department_admin(request, pk=None):
    """Department tree maintenance with duplicate and cycle protection."""
    allowed_departments = scoped_departments(request.user, 'org', Department.objects.all())
    dept = get_object_or_404(allowed_departments, pk=pk) if pk else None
    if request.method == 'POST' and not pk and not (
            request.user.is_superuser or data_scope(request.user, 'org') == 'all'):
        return _forbidden(request)
    if request.method == 'POST':
        action = request.POST.get('action', 'save')
        if action == 'toggle' and dept:
            dept.is_active = not dept.is_active
            dept.save(update_fields=['is_active'])
            audit(request.user, 'change', 'Department', dept.pk,
                  detail=f'is_active={dept.is_active}')
            messages.success(request, 'وضعیت واحد تغییر کرد.')
            return redirect('org:department_admin')

        name = request.POST.get('name', '').strip()
        code = request.POST.get('code', '').strip()
        parent_id = request.POST.get('parent_id') or None
        if not name or not code:
            messages.error(request, 'نام و کد واحد الزامی است.')
        elif Department.objects.filter(code=code).exclude(pk=dept.pk if dept else None).exists():
            messages.error(request, 'کد واحد تکراری است.')
        else:
            try:
                parent = allowed_departments.get(pk=parent_id, is_active=True) if parent_id else None
            except (Department.DoesNotExist, ValueError, TypeError):
                parent = None
                messages.error(request, 'واحد بالادست انتخاب‌شده معتبر یا فعال نیست.')
            if parent_id and parent is None:
                pass
            elif dept and parent and parent.pk == dept.pk:
                messages.error(request, 'یک واحد نمی‌تواند والد خودش باشد.')
            elif dept and parent and parent.pk in {d.pk for d in dept.descendants()}:
                messages.error(request, 'انتخاب زیرواحد به‌عنوان والد باعث چرخه در چارت می‌شود.')
            else:
                if dept is None:
                    dept = Department()
                dept.name = name
                dept.code = code
                dept.parent = parent
                dept.is_active = request.POST.get('is_active') == 'on'
                dept.save()
                audit(request.user, 'change' if pk else 'add', 'Department', dept.pk)
                messages.success(request, 'واحد سازمانی ذخیره شد.')
                return redirect('org:department_admin')

    departments = allowed_departments.select_related('parent').annotate(
        employee_count=Count('employees', distinct=True),
        child_count=Count('children', distinct=True),
    ).order_by('code')
    return render(request, 'org/departments.html', {
        'departments': departments,
        'dept': dept,
        'parent_options': allowed_departments.filter(is_active=True).exclude(pk=dept.pk if dept else None),
    })


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
    if request.method == 'POST' and not has_perm(request.user, 'org', 'manage'):
        return _forbidden(request)
    from .models import (Department, EmpType, Employee, EmployeeFieldDef,
                         EmployeeFieldVal, Position)
    employee_scope = scoped_employees(request.user, 'org', Employee.objects.all())
    allowed_depts = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    allowed_employees = scoped_employees(
        request.user, 'org', Employee.objects.filter(is_active=True))
    allowed_positions = Position.objects.filter(
        Q(department__in=allowed_depts) | Q(department__isnull=True))
    emp = get_object_or_404(employee_scope.select_related(
        'department', 'position', 'emp_type', 'manager'), pk=emp_id) if emp_id else None
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
            device_name=request.POST.get('device_name', '').strip(),
            device_card_no=request.POST.get('device_card_no', '').strip(),
            device_enable=request.POST.get('device_enable') == 'on',
            device_group=int(request.POST.get('device_group') or 1),
            device_password=request.POST.get('device_password', '').strip(),
            device_privilege=int(request.POST.get('device_privilege') or 0),
            web_user=request.POST.get('web_user') == 'on',
            program_user=request.POST.get('program_user') == 'on')
        department = allowed_depts.filter(pk=data['department_id'] or 0).first()
        position = allowed_positions.filter(pk=data['position_id'] or 0).first()
        manager = allowed_employees.exclude(pk=emp.pk if emp else None).filter(
            pk=request.POST.get('manager_id') or 0).first()
        emp_type = EmpType.objects.filter(pk=data['emp_type_id'] or 0).first()
        has_all_dept_scope = request.user.is_superuser or data_scope(
            request.user, 'org') == 'all'
        if not data['badge_no'] or not data['first_name'] or not data['last_name']:
            messages.error(request, 'شماره کارگری/نام/نام خانوادگی الزامی است.')
        elif data['department_id'] and department is None:
            messages.error(request, 'واحد انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif data['position_id'] and (
                position is None or position.department_id not in
                (department.pk if department else None, None)):
            messages.error(request, 'سمت انتخاب‌شده با واحد مجاز مطابقت ندارد.')
        elif request.POST.get('manager_id') and manager is None:
            messages.error(request, 'مدیر انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif data['emp_type_id'] and emp_type is None:
            messages.error(request, 'نوع استخدام انتخاب‌شده معتبر نیست.')
        elif department is None and not has_all_dept_scope:
            messages.error(request, 'انتخاب واحد در دامنهٔ مجاز الزامی است.')
        else:
            data['department_id'] = department.pk if department else None
            data['position_id'] = position.pk if position else None
            data['manager_id'] = manager.pk if manager else None
            if emp:
                for key, value in data.items():
                    setattr(emp, key, value)
                emp.save()
                if not emp.web_user:
                    # Revoking the T5Web flag blocks any linked account immediately;
                    # re-enabling the flag never silently reactivates an account.
                    from django.contrib.auth import get_user_model
                    get_user_model().objects.filter(employee=emp).update(is_active=False)
                for fd in EmployeeFieldDef.objects.filter(is_active=True):
                    val = request.POST.get(f'cf_{fd.pk}', '')
                    EmployeeFieldVal.objects.update_or_create(
                        employee=emp, field_def=fd, defaults={'value': val})
                audit(request.user, 'change', 'Employee', emp.pk)
                messages.success(request, 'فایل پرسنلی به‌روزرسانی شد.')
                return redirect('org:userinfo_edit', emp_id=emp.pk)
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
        'depts': allowed_depts,
        'positions': allowed_positions,
        'emp_types': EmpType.objects.all(),
        'employees': allowed_employees.select_related('department')[:400]})
