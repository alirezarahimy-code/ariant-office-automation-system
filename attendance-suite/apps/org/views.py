"""سازمان و پرسنل — فرم‌ها با الزام مجوز و دامنه داده."""
import datetime
from io import BytesIO
from uuid import uuid4

import jdatetime
from django.contrib import messages
from django.core.files.base import ContentFile
from django.core.files.storage import default_storage
from django.core.paginator import Paginator
from django.db.models import Count, Q
from django.http import FileResponse, Http404
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone

from apps.core.services import (audit, data_scope, has_perm, require_perm,
                                scoped_departments, scoped_employees, _forbidden)
from apps.t5web.scoping import (manager_profile_for, scope_manager_devices,
                                scope_manager_employees,
                                set_employee_device_assignments)

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


def _parse_optional_personnel_datetime(enabled, date_value, time_value, label):
    if not enabled:
        return None
    day = _parse_optional_personnel_date(date_value)
    if day is None:
        raise ValueError(f'تاریخ {label} را وارد کنید.')
    text = (time_value or '').strip().translate(str.maketrans(
        '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789'))
    try:
        clock = datetime.time.fromisoformat(text)
    except (TypeError, ValueError):
        raise ValueError(f'ساعت {label} معتبر نیست.')
    return timezone.make_aware(datetime.datetime.combine(day, clock))


def _personnel_datetime_values(value):
    if not value:
        return {'date': '', 'time': ''}
    local_value = timezone.localtime(value) if timezone.is_aware(value) else value
    return {
        'date': jdatetime.date.fromgregorian(date=local_value.date()).strftime('%Y-%m-%d'),
        'time': local_value.strftime('%H:%M'),
    }


def _validated_employee_photo(upload):
    """Validate and normalize a small raster personnel portrait to JPEG bytes."""
    from PIL import Image, UnidentifiedImageError

    max_bytes = 5 * 1024 * 1024
    if upload.size <= 0 or upload.size > max_bytes:
        raise ValueError('اندازهٔ عکس باید حداکثر ۵ مگابایت باشد.')
    raw = upload.read(max_bytes + 1)
    if len(raw) > max_bytes:
        raise ValueError('اندازهٔ عکس باید حداکثر ۵ مگابایت باشد.')
    try:
        image = Image.open(BytesIO(raw))
        if image.format not in ('JPEG', 'PNG', 'WEBP'):
            raise ValueError('قالب عکس باید JPEG، PNG یا WebP باشد.')
        width, height = image.size
        if width < 1 or height < 1 or width * height > 25_000_000:
            raise ValueError('ابعاد عکس معتبر نیست یا بیش از حد بزرگ است.')
        image.verify()
        image = Image.open(BytesIO(raw)).convert('RGB')
        image.thumbnail((2400, 2400))
        output = BytesIO()
        image.save(output, format='JPEG', quality=88, optimize=True)
        return output.getvalue()
    except UnidentifiedImageError as exc:
        raise ValueError('فایل بارگذاری‌شده تصویر معتبر نیست.') from exc
    except Image.DecompressionBombError as exc:
        raise ValueError('ابعاد تصویر از حد ایمن بزرگ‌تر است.') from exc
    except OSError as exc:
        raise ValueError('فایل بارگذاری‌شده تصویر سالمی نیست.') from exc


def _store_employee_photo(image_bytes):
    name = f'employee_photos/{uuid4().hex}.jpg'
    return default_storage.save(name, ContentFile(image_bytes))


def _delete_employee_photo(name):
    if name and name.startswith('employee_photos/') and default_storage.exists(name):
        default_storage.delete(name)


@require_perm('org', 'view')
def index(request):
    return render(request, 'org/index.html', {
        'departments': Department.objects.filter(is_active=True),
        'employees': scope_manager_employees(
            request.user,
            scoped_employees(request.user, 'org',
                             Employee.objects.filter(is_active=True)))[:200],
    })


@require_perm('org', 'add')
def employee_new(request):
    allowed_depts = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    allowed_employees = scope_manager_employees(
        request.user, scoped_employees(
            request.user, 'org', Employee.objects.filter(is_active=True)))
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
            manager_profile = manager_profile_for(request.user)
            if manager_profile:
                manager_profile.allowed_users.add(emp)
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
        scope_manager_employees(
            request.user,
            scoped_employees(request.user, 'org', Employee.objects.all())), pk=pk)
    allowed_depts = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    allowed_employees = scope_manager_employees(
        request.user, scoped_employees(
            request.user, 'org', Employee.objects.filter(is_active=True))
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

    departments = list(allowed_departments.select_related('parent').annotate(
        employee_count=Count('employees', distinct=True),
        child_count=Count('children', distinct=True),
    ).order_by('code'))
    dept_by_parent = {}
    dept_ids = {item.pk for item in departments}
    for item in departments:
        parent_id = item.parent_id if item.parent_id in dept_ids else None
        dept_by_parent.setdefault(parent_id, []).append(item)

    def build_department_tree(parent_id=None, visited=frozenset()):
        result = []
        for item in dept_by_parent.get(parent_id, []):
            if item.pk in visited:
                continue
            result.append({
                'department': item,
                'children': build_department_tree(item.pk, visited | {item.pk}),
            })
        return result

    return render(request, 'org/departments.html', {
        'departments': departments,
        'department_tree': build_department_tree(),
        'dept': dept,
        'parent_options': allowed_departments.filter(is_active=True).exclude(pk=dept.pk if dept else None),
    })


@require_perm('org', 'manage')
def position_new(request):
    """Legacy route; use the complete Title/Index form instead."""
    return redirect('org:titles')


@require_perm('org', 'view')
def org_chart(request):
    """چارت واحدها و انتقال امن پرسنل (DeptsChart)."""
    from django.db import transaction
    from .models import Department
    departments = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    employees = scope_manager_employees(
        request.user, scoped_employees(
            request.user, 'org', Employee.objects.filter(is_active=True)))

    if request.method == 'POST':
        if not has_perm(request.user, 'org', 'manage'):
            return _forbidden(request)
        action = request.POST.get('action')
        try:
            department_id = int(request.POST.get('dept_id'))
        except (TypeError, ValueError):
            department_id = 0
        department = departments.filter(pk=department_id).first()
        raw_badges = request.POST.getlist('badge_numbers')
        badges = list(dict.fromkeys(value.strip() for value in raw_badges if value.strip()))
        if not department:
            messages.error(request, 'واحد انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif action not in ('employ', 'fire'):
            messages.error(request, 'عملیات انتقال معتبر نیست.')
        elif not badges or len(badges) > 1000:
            messages.error(request, 'از فهرست حداکثر ۱۰۰۰ پرسنل انتخاب کنید.')
        else:
            candidates = employees.filter(badge_no__in=badges)
            if action == 'employ':
                # The reference chart shows personnel outside the selected unit,
                # including those currently assigned elsewhere.
                candidates = candidates.exclude(department=department)
            else:
                candidates = candidates.filter(department=department)
            if candidates.count() != len(badges):
                messages.error(
                    request, 'بعضی پرسنل در دامنهٔ مجاز یا فهرست انتخاب‌شده نیستند؛ تغییری انجام نشد.')
            else:
                with transaction.atomic():
                    candidates.update(department=(department if action == 'employ' else None))
                    audit(request.user, 'change', 'DepartmentPersonnel', department.pk,
                          detail=f'action={action}; count={len(badges)}')
                messages.success(request, 'انتقال پرسنل انجام شد.')
        return redirect(f'{reverse("org:org_chart")}?dept_id={request.POST.get("dept_id", "")}')

    depts = list(departments.select_related('parent').order_by('code'))
    children = {}
    dept_ids = {item.pk for item in depts}
    for item in depts:
        parent_id = item.parent_id if item.parent_id in dept_ids else None
        children.setdefault(parent_id, []).append(item)

    def tree(node_id, seen=None):
        seen = set(seen or ())
        result = []
        for item in children.get(node_id, []):
            if item.pk in seen:
                continue
            result.append({'d': item, 'kids': tree(item.pk, seen | {item.pk})})
        return result

    selected_id = request.GET.get('dept_id')
    try:
        selected_id = int(selected_id) if selected_id else None
    except (TypeError, ValueError):
        selected_id = None
    selected = departments.filter(pk=selected_id).first() if selected_id else None
    in_dept = employees.filter(department=selected).order_by('last_name', 'first_name')[:2000] if selected else []
    outside_people = employees.exclude(department=selected).order_by(
        'last_name', 'first_name')[:2000] if selected else []
    return render(request, 'org/org_chart.html', {
        'tree': tree(None), 'count': len(depts), 'selected': selected,
        'in_dept': in_dept, 'outside_people': outside_people,
        'can_manage': has_perm(request.user, 'org', 'manage'),
    })


@require_perm('org', 'manage')
def titles(request):
    """مدیریت درخت سمت‌ها و داده‌های Title مرجع."""
    from django.core.validators import validate_email
    from django.core.exceptions import ValidationError
    from django.db import IntegrityError, transaction
    from .models import Department, Position

    departments = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    positions = Position.objects.filter(
        Q(department__in=departments) | Q(department__isnull=True))
    if request.method == 'POST':
        action = request.POST.get('action', 'add')
        if action in ('delete', 'delete_all'):
            raw_ids = ([request.POST.get('position_id')]
                       if action == 'delete' else request.POST.getlist('ids'))
            ids = _parse_primary_keys(raw_ids)
            selected = positions.filter(pk__in=ids or [])
            if ids is None or selected.count() != len(ids):
                messages.error(request, 'فهرست سمت‌های انتخاب‌شده معتبر نیست.')
            elif selected.filter(Q(employees__isnull=False) |
                                 Q(children__isnull=False)).exists():
                messages.error(request, 'سمت دارای پرسنل یا زیرسمت حذف نمی‌شود.')
            else:
                deleted_ids = list(selected.values_list('pk', flat=True))
                selected.delete()
                for position_id in deleted_ids:
                    audit(request.user, 'delete', 'Position', position_id)
                messages.success(request, 'سمت(های) انتخاب‌شده حذف شد.')
            return redirect('org:titles')

        item = None
        if action == 'edit':
            try:
                position_id = int(request.POST.get('position_id'))
            except (TypeError, ValueError):
                messages.error(request, 'شناسهٔ سمت معتبر نیست.')
                return redirect('org:titles')
            item = get_object_or_404(positions, pk=position_id)
        elif action not in ('add', 'save'):
            messages.error(request, 'عملیات درخواستی معتبر نیست.')
            return redirect('org:titles')

        title = request.POST.get('title', '').strip()
        display_name = request.POST.get('display_name', '').strip() or title
        address = request.POST.get('address', '').strip()
        email_address = request.POST.get('email_address', '').strip()
        duties = request.POST.get('duties', '').strip()
        department_id_raw = request.POST.get('department_id') or None
        parent_id_raw = request.POST.get('parent_id') or None
        try:
            department_id = int(department_id_raw) if department_id_raw else None
            parent_id = int(parent_id_raw) if parent_id_raw else None
        except (TypeError, ValueError):
            department_id = parent_id = -1
        guard_ids_raw = request.POST.getlist('guard_departments')
        guard_ids = _parse_primary_keys(guard_ids_raw) if guard_ids_raw else []
        selected_guards = departments.filter(pk__in=guard_ids or [])
        department = departments.filter(pk=department_id).first() if department_id else None
        parent = positions.filter(pk=parent_id).first() if parent_id else None
        invalid_email = False
        if email_address:
            try:
                validate_email(email_address)
            except ValidationError:
                invalid_email = True
        if not title:
            messages.error(request, 'عنوان سمت الزامی است.')
        elif department_id and department is None:
            messages.error(request, 'واحد انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif parent_id and parent is None:
            messages.error(request, 'سمت بالادست معتبر نیست.')
        elif guard_ids is None or selected_guards.count() != len(guard_ids):
            messages.error(request, 'واحدهای دسترسی نگهبان معتبر نیستند.')
        elif invalid_email:
            messages.error(request, 'نشانی ایمیل معتبر نیست.')
        elif item and parent and parent.pk == item.pk:
            messages.error(request, 'یک سمت نمی‌تواند والد خودش باشد.')
        else:
            # Walk the proposed parent's ancestor chain before saving to prevent
            # a parent-cycle; this is bounded by the number of configured titles.
            ancestor = parent
            seen = set()
            cyclic = False
            while ancestor and ancestor.pk not in seen:
                if item and ancestor.pk == item.pk:
                    cyclic = True
                    break
                seen.add(ancestor.pk)
                ancestor = ancestor.parent
            if cyclic:
                messages.error(request, 'انتخاب زیرسمت به‌عنوان والد باعث چرخه در درخت می‌شود.')
            else:
                if item is None:
                    item = Position()
                    operation = 'add'
                else:
                    operation = 'change'
                item.title = title
                item.display_name = display_name
                item.address = address
                item.email_address = email_address
                item.duties = duties
                item.department = department
                item.parent = parent
                item.is_manager = request.POST.get('is_manager') == 'on'
                item.is_guard = request.POST.get('is_guard') == 'on'
                try:
                    with transaction.atomic():
                        item.save()
                        item.guard_departments.set(
                            selected_guards if item.is_guard else [])
                except IntegrityError:
                    messages.error(request, 'عنوان سمت در این واحد تکراری است.')
                else:
                    audit(request.user, operation, 'Position', item.pk)
                    messages.success(request, 'سمت ذخیره شد.')
                    return redirect('org:titles')

    edit_id = request.GET.get('edit')
    if edit_id:
        try:
            edit_id = int(edit_id)
        except (TypeError, ValueError):
            return _forbidden(request)
        selected = get_object_or_404(positions.prefetch_related('guard_departments'),
                                     pk=edit_id)
    else:
        selected = None
    selected_guard_ids = (set(selected.guard_departments.values_list('pk', flat=True))
                          if selected else set())
    all_positions = list(positions.select_related('parent', 'department').annotate(
        employee_count=Count('employees', distinct=True)).order_by('title'))
    parent_names = {position.pk: position.parent.title if position.parent_id else '—'
                    for position in all_positions}
    return render(request, 'org/titles.html', {
        'items': all_positions, 'parent_names': parent_names,
        'selected': selected, 'selected_guard_ids': selected_guard_ids,
        'departments': departments,
        'parent_options': positions.exclude(pk=selected.pk if selected else None),
    })


@require_perm('org', 'manage')
def deputies(request):
    """تعیین سمت‌های جانشین برای هر سمت (T5 Deputy/TitleChart)."""
    from .models import Department, Position
    departments = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    positions = Position.objects.filter(
        Q(department__in=departments) | Q(department__isnull=True)
    ).select_related('department', 'parent').order_by('title')

    if request.method == 'POST':
        try:
            title_id = int(request.POST.get('title_id'))
        except (TypeError, ValueError):
            messages.error(request, 'شناسهٔ سمت اصلی معتبر نیست.')
            return redirect('org:deputies')
        principal = get_object_or_404(positions, pk=title_id)
        raw_ids = request.POST.getlist('deputy_title_ids')
        deputy_ids = _parse_primary_keys(raw_ids) if raw_ids else []
        selected = positions.filter(pk__in=deputy_ids or [])
        if deputy_ids is None or selected.count() != len(deputy_ids):
            messages.error(request, 'فهرست سمت‌های جانشین معتبر نیست.')
        elif principal.pk in (deputy_ids or []):
            messages.error(request, 'هر سمت باید متفاوت از سمت جانشین باشد.')
        else:
            principal.deputy_titles.set(selected)
            audit(request.user, 'change', 'PositionDeputies', principal.pk,
                  detail=f'deputy_ids={",".join(map(str, deputy_ids))}')
            messages.success(request, 'جانشین‌های سمت ذخیره شدند.')
        return redirect(f'{reverse("org:deputies")}?title_id={principal.pk}')

    selected_id = request.GET.get('title_id')
    try:
        selected_id = int(selected_id) if selected_id else None
    except (TypeError, ValueError):
        return _forbidden(request)
    principal = positions.filter(pk=selected_id).first() if selected_id else None
    deputy_ids = (set(principal.deputy_titles.values_list('pk', flat=True))
                  if principal else set())
    return render(request, 'org/deputies.html', {
        'positions': positions, 'principal': principal,
        'deputy_ids': deputy_ids,
    })


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
def employee_photo(request, emp_id):
    """Serve a personnel portrait only to viewers within their data scope."""
    from .models import Employee
    employee = get_object_or_404(
        scope_manager_employees(
            request.user, scoped_employees(request.user, 'org', Employee.objects.all())),
        pk=emp_id)
    if not employee.photo or not employee.photo.startswith('employee_photos/'):
        raise Http404
    try:
        photo = default_storage.open(employee.photo, 'rb')
    except (FileNotFoundError, OSError):
        raise Http404
    response = FileResponse(photo, content_type='image/jpeg')
    response['Cache-Control'] = 'private, no-store'
    response['X-Content-Type-Options'] = 'nosniff'
    return response


@require_perm('org', 'view')
def userinfo(request, emp_id=None):
    """فایل پرسنلی کامل (UserInfo) — تب‌دار با همه فیلدها + فیلدهای سفارشی."""
    if request.method == 'POST' and not has_perm(request.user, 'org', 'manage'):
        return _forbidden(request)
    from .models import (Department, EmpType, Employee, EmployeeFieldDef,
                         EmployeeFieldVal, Position)
    from apps.device.models import Device
    employee_scope = scope_manager_employees(
        request.user, scoped_employees(request.user, 'org', Employee.objects.all()))
    allowed_depts = scoped_departments(
        request.user, 'org', Department.objects.filter(is_active=True))
    allowed_employees = scope_manager_employees(
        request.user, scoped_employees(
            request.user, 'org', Employee.objects.filter(is_active=True)))
    allowed_positions = Position.objects.filter(
        Q(department__in=allowed_depts) | Q(department__isnull=True))
    can_manage_user_devices = (has_perm(request.user, 'device', 'manage') and
                               data_scope(request.user, 'device') == 'all')
    can_queue_user_upload = (can_manage_user_devices and
                             data_scope(request.user, 'attendance') == 'all')
    emp = get_object_or_404(employee_scope.select_related(
        'department', 'position', 'emp_type', 'manager'), pk=emp_id) if emp_id else None
    device_filter = Q(is_active=True)
    if emp:
        device_filter |= Q(pk__in=emp.upload_devices.values('pk'))
        device_filter |= Q(pk__in=emp.valid_devices.values('pk'))
    available_devices = (scope_manager_devices(
        request.user, Device.objects.filter(device_filter).order_by('name'))
        if can_manage_user_devices else Device.objects.none())
    if request.method == 'POST' and request.POST.get('action') == 'queue_device_upload':
        if not emp or not can_queue_user_upload or not emp.is_active:
            return _forbidden(request)
        sync_scope = scoped_employees(
            request.user, 'device', Employee.objects.all())
        sync_scope = scoped_employees(request.user, 'attendance', sync_scope)
        sync_scope = scope_manager_employees(request.user, sync_scope)
        if (data_scope(request.user, 'attendance') != 'all' or
                not sync_scope.filter(pk=emp.pk).exists()):
            return _forbidden(request)
        devices = scope_manager_devices(
            request.user, emp.upload_devices.filter(is_active=True))
        if not emp.device_enable or not devices.exists():
            messages.error(request, 'پرسنل فعال یا دستگاه ارسال فعالی برای همگام‌سازی وجود ندارد.')
        else:
            from django.db import transaction
            from apps.device.models import DeviceUserSync
            with transaction.atomic():
                for device in devices:
                    DeviceUserSync.objects.update_or_create(
                        device=device, employee=emp,
                        direction=DeviceUserSync.Direction.UP,
                        defaults={'state': DeviceUserSync.State.PENDING})
                audit(request.user, 'change', 'DeviceUserSync', emp.pk,
                      detail=f'queued upload devices={devices.count()}')
            messages.success(request, 'کاربر برای ارسال به دستگاه‌های انتخاب‌شده در صف قرار گرفت.')
        return redirect('org:userinfo_edit', emp_id=emp.pk)
    form_data = request.POST if request.method == 'POST' else None
    selected_upload_devices = []
    selected_valid_devices = []
    if request.method == 'POST':
        data = None
        try:
            birthday = _parse_optional_personnel_date(request.POST.get('birthday'))
            hire_date = _parse_optional_personnel_date(request.POST.get('hire_date'))
            end_work_date = _parse_optional_personnel_date(
                request.POST.get('end_work_date'))
            valid_from = _parse_optional_personnel_datetime(
                request.POST.get('valid_from') == 'on',
                request.POST.get('valid_from_date'),
                request.POST.get('valid_from_time'), 'شروع اعتبار')
            valid_to = _parse_optional_personnel_datetime(
                request.POST.get('valid_to') == 'on',
                request.POST.get('valid_to_date'),
                request.POST.get('valid_to_time'), 'پایان اعتبار')
            if valid_from and valid_to and valid_to < valid_from:
                raise ValueError('پایان اعتبار نمی‌تواند قبل از شروع آن باشد.')
            device_group = int(request.POST.get('device_group') or 1)
            device_privilege = int(request.POST.get('device_privilege') or 0)
        except ValueError as exc:
            message = str(exc)
            messages.error(request, message if any(
                marker in message for marker in ('تاریخ', 'ساعت', 'اعتبار'))
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
                valid_from=valid_from,
                valid_to=valid_to,
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
                program_user=request.POST.get('program_user') == 'on',
                is_active=request.POST.get('employment_active') == 'on')
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
            if data is not None and can_manage_user_devices:
                raw_upload_ids = request.POST.getlist('upload_device_ids')
                raw_valid_ids = request.POST.getlist('valid_device_ids')
                upload_ids = (_parse_primary_keys(raw_upload_ids)
                              if raw_upload_ids else [])
                valid_ids = (_parse_primary_keys(raw_valid_ids)
                             if raw_valid_ids else [])
                selected_upload_devices = list(available_devices.filter(
                    pk__in=upload_ids or []))
                selected_valid_devices = list(available_devices.filter(
                    pk__in=valid_ids or []))
                if (upload_ids is None or valid_ids is None or
                        len(selected_upload_devices) != len(upload_ids or []) or
                        len(selected_valid_devices) != len(valid_ids or [])):
                    messages.error(request, 'فهرست دستگاه‌های انتخاب‌شده معتبر نیست.')
                    data = None
            if data is not None and not (0 <= device_group <= 32767 and
                    0 <= device_privilege <= 32767):
                messages.error(request, 'مقادیر دستگاه باید بین صفر و ۳۲۷۶۷ باشند.')
                data = None
        photo_bytes = None
        if data is not None and request.FILES.get('photo'):
            try:
                photo_bytes = _validated_employee_photo(request.FILES['photo'])
            except ValueError as exc:
                messages.error(request, str(exc))
                data = None
        if data is not None and emp and request.POST.get('remove_photo') == 'on':
            data['photo'] = ''
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
                old_photo = emp.photo if emp else ''
                new_photo = None
                if photo_bytes:
                    try:
                        new_photo = _store_employee_photo(photo_bytes)
                        data['photo'] = new_photo
                    except OSError:
                        messages.error(request, 'ذخیرهٔ عکس پرسنل انجام نشد؛ اطلاعات دیگر ذخیره نشدند.')
                        data = None
                if data is not None and emp:
                    for key, value in data.items():
                        setattr(emp, key, value)
                    try:
                        emp.save()
                    except Exception:
                        _delete_employee_photo(new_photo)
                        raise
                    if old_photo != emp.photo:
                        _delete_employee_photo(old_photo)
                    if can_manage_user_devices:
                        set_employee_device_assignments(
                            request.user, emp, selected_upload_devices,
                            selected_valid_devices)
                    if not emp.is_active or not emp.web_user or not emp.program_user:
                        # Disabling employment or either reference login gate blocks
                        # linked accounts immediately; re-enabling never auto-reactivates.
                        from django.contrib.auth import get_user_model
                        get_user_model().objects.filter(employee=emp).update(is_active=False)
                    for fd in EmployeeFieldDef.objects.filter(is_active=True):
                        val = request.POST.get(f'cf_{fd.pk}', '')
                        EmployeeFieldVal.objects.update_or_create(
                            employee=emp, field_def=fd, defaults={'value': val})
                    audit(request.user, 'change', 'Employee', emp.pk)
                    messages.success(request, 'فایل پرسنلی به‌روزرسانی شد.')
                    return redirect('org:userinfo_edit', emp_id=emp.pk)
                elif data is not None and Employee.objects.filter(
                        badge_no=data['badge_no']).exists():
                    _delete_employee_photo(new_photo)
                    messages.error(request, 'شماره کارگری تکراری است.')
                elif data is not None:
                    try:
                        emp = Employee.objects.create(**data)
                    except Exception:
                        _delete_employee_photo(new_photo)
                        raise
                    manager_profile = manager_profile_for(request.user)
                    if manager_profile:
                        manager_profile.allowed_users.add(emp)
                    if can_manage_user_devices:
                        set_employee_device_assignments(
                            request.user, emp, selected_upload_devices,
                            selected_valid_devices)
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
    valid_from_values = _personnel_datetime_values(emp.valid_from) if emp else {
        'date': '', 'time': ''}
    valid_to_values = _personnel_datetime_values(emp.valid_to) if emp else {
        'date': '', 'time': ''}
    valid_from_enabled = bool(emp and emp.valid_from)
    valid_to_enabled = bool(emp and emp.valid_to)
    if form_data is not None:
        for field in date_values:
            date_values[field] = form_data.get(field, '')
        valid_from_enabled = form_data.get('valid_from') == 'on'
        valid_to_enabled = form_data.get('valid_to') == 'on'
        valid_from_values = {
            'date': form_data.get('valid_from_date', ''),
            'time': form_data.get('valid_from_time', ''),
        }
        valid_to_values = {
            'date': form_data.get('valid_to_date', ''),
            'time': form_data.get('valid_to_time', ''),
        }
    upload_device_ids = (set(emp.upload_devices.values_list('pk', flat=True))
                         if emp else set())
    valid_device_ids = (set(emp.valid_devices.values_list('pk', flat=True))
                        if emp else set())
    if form_data is not None and can_manage_user_devices:
        posted_upload_ids = _parse_primary_keys(
            form_data.getlist('upload_device_ids'))
        posted_valid_ids = _parse_primary_keys(
            form_data.getlist('valid_device_ids'))
        upload_device_ids = set(posted_upload_ids or [])
        valid_device_ids = set(posted_valid_ids or [])
    personnel_query = request.GET.get('q', '').strip()
    personnel_list = employee_scope.select_related('department').order_by(
        'badge_no')
    if personnel_query:
        personnel_list = personnel_list.filter(
            Q(badge_no__icontains=personnel_query) |
            Q(personnel_no__icontains=personnel_query) |
            Q(first_name__icontains=personnel_query) |
            Q(last_name__icontains=personnel_query))
    employee_page = Paginator(personnel_list, 50).get_page(
        request.GET.get('page'))
    return render(request, 'org/userinfo.html', {
        'emp': emp, 'cf_defs': cf_defs, 'cf_pairs': cf_pairs,
        'photo_url': (reverse('org:employee_photo', args=[emp.pk])
                      if emp and emp.photo.startswith('employee_photos/') else ''),
        'date_values': date_values,
        'valid_from_values': valid_from_values,
        'valid_to_values': valid_to_values,
        'valid_from_enabled': valid_from_enabled,
        'valid_to_enabled': valid_to_enabled,
        'can_manage_user_devices': can_manage_user_devices,
        'can_queue_user_upload': can_queue_user_upload,
        'devices': available_devices,
        'upload_device_ids': upload_device_ids,
        'valid_device_ids': valid_device_ids,
        'depts': allowed_depts,
        'positions': allowed_positions,
        'emp_types': EmpType.objects.all(),
        'certificates': Certificate.objects.all(),
        'employees': employee_page.object_list,
        'employee_page': employee_page,
        'personnel_query': personnel_query})
