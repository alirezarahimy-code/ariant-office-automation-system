"""سازمان و پرسنل — فرم‌ها با الزام مجوز و دامنه داده."""
import datetime

import jdatetime
from django.contrib import messages
from django.db.models import Count, Q
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import (audit, data_scope, has_perm, require_perm,
                                scoped_departments, scoped_employees, _forbidden)

from .models import Certificate, Department, EmpType, Employee, Position


def _personnel_date_text(value):
    if not value:
        return ''
    return jdatetime.date.fromgregorian(date=value).strftime('%Y-%m-%d')


def _parse_primary_keys(values):
    try:
        parsed = [int(value) for value in values]
    except (TypeError, ValueError):
        return None
    if not parsed or any(value <= 0 for value in parsed):
        return None
    return list(dict.fromkeys(parsed))


def _parse_optional_personnel_date(value):
    """Parse personnel form dates without treating Jalali text as Gregorian.

    T5 operators commonly enter Persian/Jalali dates, while integrations may
    submit ISO Gregorian dates. Years in the 13xx/14xx range are interpreted as
    Jalali; other four-digit years are interpreted as Gregorian.
    """
    text = (value or '').strip()
    if not text:
        return None
    text = text.translate(str.maketrans(
        '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'))
    pieces = text.replace('-', '/').replace('.', '/').split('/')
    if len(pieces) != 3:
        raise ValueError('تاریخ باید به شکل سال/ماه/روز وارد شود.')
    try:
        year, month, day = (int(part) for part in pieces)
        if 1300 <= year <= 1499:
            return jdatetime.date(year, month, day).togregorian()
        return datetime.date(year, month, day)
    except (TypeError, ValueError, OverflowError) as exc:
        raise ValueError('تاریخ واردشده معتبر نیست.') from exc


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
        if action in ('delete', 'delete_all'):
            if action == 'delete':
                raw_ids = ([str(dept.pk)] if dept else
                           [request.POST.get('department_id')])
            else:
                raw_ids = request.POST.getlist('ids')
            ids = _parse_primary_keys(raw_ids)
            selected = allowed_departments.filter(pk__in=ids or [])
            if ids is None or selected.count() != len(ids):
                messages.error(request, 'فهرست واحدهای انتخاب‌شده معتبر نیست.')
            elif selected.filter(Q(employees__isnull=False) |
                                 Q(children__isnull=False)).exists():
                messages.error(request, 'واحد دارای پرسنل یا زیرواحد حذف نمی‌شود.')
            else:
                deleted_ids = list(selected.values_list('pk', flat=True))
                selected.delete()
                for department_id in deleted_ids:
                    audit(request.user, 'delete', 'Department', department_id)
                messages.success(request, 'واحد(های) انتخاب‌شده حذف شد.')
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
    """نوع استخدام و پیکربندی LeaveTypeهای مجاز (EmpType)."""
    from apps.attendance.models import LeaveType

    if request.method == 'POST':
        action = request.POST.get('action', 'add')
        if action in ('add', 'edit'):
            name = request.POST.get('name', '').strip()
            code = request.POST.get('code', '').strip()
            if action == 'edit':
                try:
                    item_id = int(request.POST.get('emp_type_id'))
                except (TypeError, ValueError):
                    messages.error(request, 'شناسهٔ نوع استخدام معتبر نیست.')
                    return redirect('org:emp_types')
                item = get_object_or_404(EmpType, pk=item_id)
            else:
                item = None
            raw_leave_ids = request.POST.getlist('valid_leave_types')
            leave_ids = (_parse_primary_keys(raw_leave_ids)
                         if raw_leave_ids else [])
            leave_types = LeaveType.objects.filter(pk__in=leave_ids or [])
            if not name or not code:
                messages.error(request, 'نام و کد نوع استخدام الزامی است.')
            elif leave_ids is None or leave_types.count() != len(leave_ids):
                messages.error(request, 'فهرست مرخصی‌های مجاز نامعتبر است.')
            elif EmpType.objects.filter(code=code).exclude(
                    pk=item.pk if item else None).exists():
                messages.error(request, 'کد نوع استخدام تکراری است.')
            else:
                if item is None:
                    item = EmpType()
                    operation = 'add'
                else:
                    operation = 'change'
                item.name = name
                item.code = code
                item.save()
                item.valid_leave_types.set(leave_types)
                audit(request.user, operation, 'EmpType', item.pk)
                messages.success(request, 'نوع استخدام ذخیره شد.')
        elif action in ('delete', 'delete_all'):
            raw_ids = ([request.POST.get('emp_type_id')]
                       if action == 'delete' else request.POST.getlist('ids'))
            ids = _parse_primary_keys(raw_ids)
            items = EmpType.objects.filter(pk__in=ids or [])
            if ids is None or items.count() != len(ids):
                messages.error(request, 'فهرست انواع استخدام انتخاب‌شده معتبر نیست.')
            elif items.filter(employees__isnull=False).exists():
                messages.error(request, 'نوع استخدام تخصیص‌یافته به پرسنل حذف نمی‌شود.')
            else:
                deleted_ids = list(items.values_list('pk', flat=True))
                items.delete()
                for item_id in deleted_ids:
                    audit(request.user, 'delete', 'EmpType', item_id)
                messages.success(request, 'نوع(های) استخدام انتخاب‌شده حذف شد.')
        return redirect('org:emp_types')

    edit_id = request.GET.get('edit')
    if edit_id:
        try:
            edit_id = int(edit_id)
        except (TypeError, ValueError):
            return _forbidden(request)
        selected = get_object_or_404(EmpType.objects.prefetch_related(
            'valid_leave_types'), pk=edit_id)
    else:
        selected = None
    selected_leave_ids = (set(selected.valid_leave_types.values_list('pk', flat=True))
                          if selected else set())
    items = EmpType.objects.annotate(
        employee_count=Count('employees', distinct=True)).prefetch_related(
            'valid_leave_types').order_by('name')
    return render(request, 'org/emp_types.html', {
        'items': items, 'selected': selected,
        'selected_leave_ids': selected_leave_ids,
        'leave_types': LeaveType.objects.order_by('name'),
    })


@require_perm('org', 'manage')
def certificates(request):
    """مدارک تحصیلی با شناسه و CRUD (Certificate)."""
    if request.method == 'POST':
        action = request.POST.get('action', 'add')
        if action in ('add', 'edit'):
            name = request.POST.get('name', '').strip()
            certificate_id = request.POST.get('certificate_id')
            certificate = (get_object_or_404(Certificate, pk=certificate_id)
                          if action == 'edit' else None)
            if not name:
                messages.error(request, 'نام مدرک الزامی است.')
            elif Certificate.objects.filter(name=name).exclude(
                    pk=certificate.pk if certificate else None).exists():
                messages.error(request, 'نام مدرک تکراری است.')
            else:
                if certificate is None:
                    certificate = Certificate(name=name)
                    operation = 'add'
                else:
                    certificate.name = name
                    operation = 'change'
                certificate.save()
                # Keep the existing text field in sync for legacy integrations
                # while the FK-backed catalog is adopted by local screens.
                Employee.objects.filter(certificate_ref=certificate).update(
                    certificate=certificate.name)
                audit(request.user, operation, 'Certificate', certificate.pk)
                messages.success(request, 'مدرک تحصیلی ذخیره شد.')
        elif action in ('delete', 'delete_all'):
            ids = ([request.POST.get('certificate_id')]
                   if action == 'delete' else request.POST.getlist('ids'))
            certificates_to_delete = Certificate.objects.filter(pk__in=ids)
            if not ids or certificates_to_delete.count() != len(set(ids)):
                messages.error(request, 'فهرست مدارک انتخاب‌شده معتبر نیست.')
            elif certificates_to_delete.filter(employees__isnull=False).exists():
                messages.error(request, 'مدرکی که به پروندهٔ پرسنلی متصل است حذف نمی‌شود.')
            else:
                deleted_ids = list(certificates_to_delete.values_list('pk', flat=True))
                certificates_to_delete.delete()
                for certificate_id in deleted_ids:
                    audit(request.user, 'delete', 'Certificate', certificate_id)
                messages.success(request, 'مدرک(های) انتخاب‌شده حذف شد.')
        return redirect('org:certificates')
    return render(request, 'org/certificates.html', {
        'items': Certificate.objects.all(),
    })


@require_perm('org', 'view')
def custom_fields(request):
    """فیلدهای سفارشی پرسنل (UserAddProperty)."""
    from .models import EmployeeFieldDef
    can_manage = has_perm(request.user, 'org', 'manage')
    if request.method == 'POST':
        if not can_manage:
            return _forbidden(request)
        action = request.POST.get('action', 'add')
        filter_id = request.POST.get('filter_id')
        if action == 'filter' or filter_id:
            try:
                field_id = int(filter_id)
            except (TypeError, ValueError):
                messages.error(request, 'شناسهٔ فیلد معتبر نیست.')
                return redirect('org:custom_fields')
            field = get_object_or_404(EmployeeFieldDef, pk=field_id, is_active=True)
            field.is_search_filter = request.POST.get('is_search_filter') == 'on'
            field.save(update_fields=['is_search_filter'])
            audit(request.user, 'change', 'EmployeeFieldDef', field.pk)
            messages.success(request, 'تنظیم فیلتر پیشرفته ذخیره شد.')
        elif action in ('add', 'edit'):
            title = request.POST.get('title', '').strip()
            field_type = request.POST.get('field_type') or EmployeeFieldDef.FieldType.TEXT
            if action == 'edit':
                try:
                    field_id = int(request.POST.get('field_id'))
                except (TypeError, ValueError):
                    messages.error(request, 'شناسهٔ فیلد معتبر نیست.')
                    return redirect('org:custom_fields')
                field = get_object_or_404(
                    EmployeeFieldDef, pk=field_id, is_active=True)
            else:
                field = None
            raw_options = request.POST.get('options', '').replace('|', '\n')
            options = list(dict.fromkeys(
                value.strip() for value in raw_options.splitlines()
                if value.strip()))
            if not title:
                messages.error(request, 'عنوان فیلد الزامی است.')
            elif field_type not in EmployeeFieldDef.FieldType.values:
                messages.error(request, 'نوع فیلد نامعتبر است.')
            elif field_type == EmployeeFieldDef.FieldType.DROPDOWN and not options:
                messages.error(request, 'برای فیلد فهرستی حداقل یک گزینه لازم است.')
            else:
                if field is None:
                    field = EmployeeFieldDef()
                    operation = 'add'
                else:
                    operation = 'change'
                field.title = title
                field.field_type = field_type
                field.options = '|'.join(options) if field_type == \
                    EmployeeFieldDef.FieldType.DROPDOWN else ''
                field.is_search_filter = request.POST.get('is_search_filter') == 'on'
                field.is_active = True
                field.save()
                audit(request.user, operation, 'EmployeeFieldDef', field.pk)
                messages.success(request, 'فیلد سفارشی ذخیره شد.')
        elif action in ('delete', 'delete_all'):
            raw_ids = ([request.POST.get('field_id')]
                       if action == 'delete' else request.POST.getlist('ids'))
            field_ids = _parse_primary_keys(raw_ids)
            fields = EmployeeFieldDef.objects.filter(
                pk__in=field_ids or [], is_active=True)
            if field_ids is None or fields.count() != len(field_ids):
                messages.error(request, 'فهرست فیلدهای انتخاب‌شده معتبر نیست.')
            else:
                deleted_ids = list(fields.values_list('pk', flat=True))
                fields.update(is_active=False, is_search_filter=False)
                for field_id in deleted_ids:
                    audit(request.user, 'delete', 'EmployeeFieldDef', field_id)
                messages.success(
                    request, 'فیلدها غیرفعال شدند؛ مقادیر ذخیره‌شدهٔ پرسنل حفظ شدند.')
        return redirect('org:custom_fields')

    edit_id = request.GET.get('edit')
    if edit_id:
        try:
            edit_id = int(edit_id)
        except (TypeError, ValueError):
            return _forbidden(request)
        selected = get_object_or_404(
            EmployeeFieldDef, pk=edit_id, is_active=True)
    else:
        selected = None
    return render(request, 'org/custom_fields.html', {
        'fields': EmployeeFieldDef.objects.filter(is_active=True).order_by('pk'),
        'selected': selected, 'can_manage': can_manage,
    })


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
    form_data = request.POST if request.method == 'POST' else None
    if request.method == 'POST':
        data = None
        try:
            birthday = _parse_optional_personnel_date(request.POST.get('birthday'))
            hire_date = _parse_optional_personnel_date(request.POST.get('hire_date'))
            end_work_date = _parse_optional_personnel_date(
                request.POST.get('end_work_date'))
            device_group = int(request.POST.get('device_group') or 1)
            device_privilege = int(request.POST.get('device_privilege') or 0)
        except ValueError as exc:
            messages.error(request, str(exc) if str(exc).startswith('تاریخ')
                           else 'مقادیر عددی دستگاه معتبر نیستند.')
        else:
            data = dict(
                badge_no=request.POST.get('badge_no', '').strip(),
                personnel_no=request.POST.get('personnel_no', '').strip(),
                first_name=request.POST.get('first_name', '').strip(),
                last_name=request.POST.get('last_name', '').strip(),
                father_name=request.POST.get('father_name', '').strip(),
                national_id=request.POST.get('national_id', '').strip(),
                gender=request.POST.get('gender', ''),
                birthday=birthday,
                certificate=request.POST.get('certificate', '').strip(),
                certificate_ref_id=None,
                email=request.POST.get('email', '').strip(),
                tel=request.POST.get('tel', '').strip(),
                address=request.POST.get('address', '').strip(),
                janbaz_code=request.POST.get('janbaz_code', '').strip(),
                hire_date=hire_date,
                end_work_date=end_work_date,
                department_id=request.POST.get('dept_id') or None,
                position_id=request.POST.get('position_id') or None,
                emp_type_id=request.POST.get('emp_type_id') or None,
                device_name=request.POST.get('device_name', '').strip(),
                device_card_no=request.POST.get('device_card_no', '').strip(),
                device_enable=request.POST.get('device_enable') == 'on',
                device_group=device_group,
                device_password=request.POST.get('device_password', '').strip(),
                device_privilege=device_privilege,
                web_user=request.POST.get('web_user') == 'on',
                program_user=request.POST.get('program_user') == 'on')
            certificate_id = request.POST.get('certificate_id') or None
            selected_certificate = None
            if certificate_id not in (None, 'legacy'):
                try:
                    selected_certificate = Certificate.objects.filter(
                        pk=int(certificate_id)).first()
                except (TypeError, ValueError):
                    pass
            if certificate_id not in (None, 'legacy') and selected_certificate is None:
                messages.error(request, 'مدرک تحصیلی انتخاب‌شده معتبر نیست.')
                data = None
            else:
                if certificate_id == 'legacy':
                    data['certificate'] = request.POST.get(
                        'legacy_certificate', '').strip()
                if selected_certificate:
                    data['certificate'] = selected_certificate.name
                    data['certificate_ref_id'] = selected_certificate.pk
                elif data['certificate']:
                    selected_certificate = Certificate.objects.filter(
                        name=data['certificate']).first()
                    data['certificate_ref_id'] = (
                        selected_certificate.pk if selected_certificate else None)
            if data is not None and not (0 <= device_group <= 32767 and
                    0 <= device_privilege <= 32767):
                messages.error(request, 'مقادیر دستگاه باید بین صفر و ۳۲۷۶۷ باشند.')
                data = None
        if data is not None:
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
    cf_pairs = [
        (fd, cf_vals.get(fd.pk, ''), [item.strip() for item in
                                     fd.options.replace('|', '\n').splitlines()
                                     if item.strip()])
        for fd in cf_defs
    ]
    date_values = {
        'birthday': _personnel_date_text(emp.birthday) if emp else '',
        'hire_date': _personnel_date_text(emp.hire_date) if emp else '',
        'end_work_date': _personnel_date_text(emp.end_work_date) if emp else '',
    }
    if form_data is not None:
        for field in date_values:
            date_values[field] = form_data.get(field, '')
    return render(request, 'org/userinfo.html', {
        'emp': emp, 'cf_defs': cf_defs, 'cf_pairs': cf_pairs,
        'date_values': date_values,
        'depts': allowed_depts,
        'positions': allowed_positions,
        'emp_types': EmpType.objects.all(),
        'certificates': Certificate.objects.all(),
        'employees': allowed_employees.select_related('department')[:400]})
