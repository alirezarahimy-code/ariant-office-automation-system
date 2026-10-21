"""موتور گزارش‌ها + گزارش‌ساز — تعریف امن (فیلدهای سفید) + خروجی CSV."""
import csv
import datetime as gregorian
from urllib.parse import urlencode

import jdatetime
from django.contrib import messages
from django.core.paginator import Paginator
from django.db.models import Count, Exists, OuterRef, Q, Sum
from django.http import Http404, HttpResponse, HttpResponseForbidden, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone

from apps.attendance.models import (AttendancePolicy, DailyRecord, Punch,
                                    Request, RewardPenalty)
from apps.attendance.services import parse_jalali, to_jdate
from apps.core.models import AppSetting
from apps.core.services import (audit, data_scope, has_perm, require_perm,
                                scoped_employees)
from apps.device.models import Device, RawRecord, Visitor
from apps.org.models import (Department, Employee, EmployeeFieldDef,
                             EmployeeFieldVal, EmpType)

from .models import ReportDef

MAX_DAILY_EXPORT_ROWS = 10000

# فیلدهای مجاز هر مدل برای گزارش‌ساز (بدون SQL دل‌بخواه — امنیت)
MODEL_MAP = {
    'daily_record': (DailyRecord, {
        'employee': 'employee__last_name', 'date': 'date',
        'check_in': 'check_in', 'check_out': 'check_out',
        'late_minutes': 'late_minutes', 'early_minutes': 'early_minutes',
        'overtime_minutes': 'overtime_minutes', 'worked_minutes': 'worked_minutes',
        'state': 'state'}),
    'punch': (Punch, {
        'employee': 'employee__last_name', 'date': 'date', 'time': 'time',
        'direction': 'direction', 'source': 'source', 'description': 'description'}),
    'request': (Request, {
        'employee': 'employee__last_name', 'kind': 'kind', 'status': 'status',
        'for_date': 'for_date', 'from_date': 'from_date', 'to_date': 'to_date',
        'days': 'days', 'hours': 'hours', 'description': 'description'}),
}


def _month_params(request):
    today = jdatetime.date.fromgregorian(date=timezone.localdate())
    try:
        year = int(request.GET.get('year') or today.year)
        month = int(request.GET.get('month') or today.month)
        if not 1300 <= year <= 1600 or not 1 <= month <= 12:
            raise ValueError
        return year, month
    except (TypeError, ValueError):
        messages.error(request, 'سال یا ماه جلالی نامعتبر است؛ ماه جاری نمایش داده شد.')
        return today.year, today.month


def _jdate_text(value):
    if value is None:
        return ''
    date = to_jdate(value)
    return f'{date.year:04d}/{date.month:02d}/{date.day:02d}'


def _report_value(value):
    if isinstance(value, gregorian.datetime):
        local = timezone.localtime(value) if timezone.is_aware(value) else value
        return f'{_jdate_text(local.date())} {local.strftime("%H:%M:%S")}'
    if isinstance(value, gregorian.date):
        return _jdate_text(value)
    if isinstance(value, gregorian.time):
        return value.strftime('%H:%M:%S')
    return str(value) if value is not None else ''


def _daily_report_rows(records):
    """Render local daily records with the reference's three in/out slots."""
    records = list(records)
    if not records:
        return []
    employee_ids = {record.employee_id for record in records}
    dates = {record.date for record in records}
    punches_by_day = {}
    for punch in Punch.objects.filter(
            employee_id__in=employee_ids, date__in=dates
    ).order_by('employee_id', 'date', 'time', 'pk'):
        key = (punch.employee_id, punch.date)
        punches_by_day.setdefault(key, {Punch.Direction.IN: [], Punch.Direction.OUT: []})
        slot = punches_by_day[key].get(punch.direction)
        if slot is not None:
            slot.append(punch.time.strftime('%H:%M'))

    weekday_names = ('شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه',
                     'چهارشنبه', 'پنجشنبه', 'جمعه')
    rows = []
    for record in records:
        punches = punches_by_day.get((record.employee_id, record.date), {})
        in_times = list(punches.get(Punch.Direction.IN, ()))
        out_times = list(punches.get(Punch.Direction.OUT, ()))
        if not in_times and record.check_in:
            in_times.append(record.check_in.strftime('%H:%M'))
        if not out_times and record.check_out:
            out_times.append(record.check_out.strftime('%H:%M'))
        jalali_date = to_jdate(record.date)
        employee = record.employee
        rows.append([
            employee.badge_no, f'{employee.first_name} {employee.last_name}',
            employee.department.name if employee.department_id else '',
            _jdate_text(record.date), weekday_names[jalali_date.weekday()],
            *(in_times[:3] + [''] * (3 - len(in_times[:3]))),
            *(out_times[:3] + [''] * (3 - len(out_times[:3]))),
            record.worked_minutes, record.late_minutes, record.early_minutes,
            record.overtime_minutes, record.get_state_display(),
        ])
    return rows


def _certificate_choices():
    setting = AppSetting.objects.filter(key='certificates').first()
    if not setting:
        return []
    raw = setting.value.replace('|', '\n')
    return list(dict.fromkeys(item.strip() for item in raw.splitlines() if item.strip()))


def _department_subtree_ids(root_ids):
    departments = list(Department.objects.filter(is_active=True).values_list('pk', 'parent_id'))
    children = {}
    for department_id, parent_id in departments:
        children.setdefault(parent_id, []).append(department_id)
    expanded = set()
    pending = [department_id for department_id, _ in departments
               if department_id in root_ids]
    while pending:
        department_id = pending.pop()
        if department_id in expanded:
            continue
        expanded.add(department_id)
        pending.extend(children.get(department_id, ()))
    return expanded


def _department_tree(visible_employees):
    """Return an ordered, scope-safe tree limited to visible employees' branches."""
    all_departments = list(Department.objects.filter(is_active=True).order_by('name', 'pk'))
    by_id = {department.pk: department for department in all_departments}
    included_ids = set()
    for employee in visible_employees:
        current_id = employee.department_id
        chain_seen = set()
        while current_id in by_id and current_id not in chain_seen:
            chain_seen.add(current_id)
            included_ids.add(current_id)
            current_id = by_id[current_id].parent_id

    children = {department_id: [] for department_id in included_ids}
    roots = []
    for department_id in included_ids:
        department = by_id[department_id]
        if department.parent_id in included_ids:
            children[department.parent_id].append(department_id)
        else:
            roots.append(department_id)
    for ids in children.values():
        ids.sort(key=lambda item: (by_id[item].name.casefold(), item))
    roots.sort(key=lambda item: (by_id[item].name.casefold(), item))

    ordered = []
    visited = set()

    def append_branch(department_id, depth, ancestors=()):
        if department_id in visited:
            return
        visited.add(department_id)
        ordered.append((department_id, depth, ancestors))
        for child_id in children[department_id]:
            append_branch(child_id, depth + 1, ancestors + (department_id,))

    for root_id in roots:
        append_branch(root_id, 0)
    # Malformed cyclic organization data should not break reports or omit the nodes.
    for department_id in sorted(included_ids - visited,
                                key=lambda item: (by_id[item].name.casefold(), item)):
        append_branch(department_id, 0)

    nodes = []
    for department_id, depth, ancestor_ids in ordered:
        descendant_ids = set()
        pending = [department_id]
        while pending:
            current_id = pending.pop()
            if current_id in descendant_ids:
                continue
            descendant_ids.add(current_id)
            pending.extend(children[current_id])
        department = by_id[department_id]
        nodes.append({
            'id': department_id,
            'name': department.name,
            'depth': depth,
            'ancestor_ids': ','.join(str(item) for item in ancestor_ids),
            'subtree_ids': ','.join(str(item) for item in sorted(descendant_ids)),
        })
    return nodes


def _personnel_report(request, code):
    today_j = jdatetime.date.fromgregorian(date=timezone.localdate())
    visible = list(scoped_employees(
        request.user, 'reports', Employee.objects.filter(is_active=True)
    ).select_related('department').order_by('last_name', 'first_name', 'badge_no'))
    visible_by_badge = {employee.badge_no: employee for employee in visible}
    department_nodes = _department_tree(visible)
    employment_types = EmpType.objects.order_by('name')
    certificates = _certificate_choices()
    search_properties = list(EmployeeFieldDef.objects.filter(
        is_active=True, is_search_filter=True).order_by('pk'))
    for search_property in search_properties:
        search_property.filter_options = [
            item.strip() for item in
            search_property.options.replace('|', '\n').splitlines()
            if item.strip()
        ]
    requested_badges = request.GET.getlist('selectedBadgeNumbers')
    if len(requested_badges) > 1000:
        messages.error(request, 'در هر گزارش حداکثر ۱۰۰۰ پرسنل انتخاب کنید.')
        requested_badges = []
    selected_badges = set(requested_badges) & set(visible_by_badge)
    if set(requested_badges) - set(visible_by_badge):
        audit(request.user, 'report_scope_filtered',
              model='reports.employee_selection',
              detail=f'rejected={len(set(requested_badges) - set(visible_by_badge))}')
        messages.error(request, 'برخی پرسنل انتخاب‌شده خارج از دامنه دسترسی هستند.')
    selected_employees = [visible_by_badge[badge]
                          for badge in dict.fromkeys(requested_badges)
                          if badge in selected_badges]

    criteria_fields = ('isMonthly', 'year', 'month', 'FromDateHijri', 'ToDateHijri')
    criteria_submitted = any(name in request.GET for name in criteria_fields)
    if 'isMonthly' in request.GET:
        is_monthly = request.GET.get('isMonthly') == 'true'
    elif 'FromDateHijri' in request.GET or 'ToDateHijri' in request.GET:
        is_monthly = False
    else:
        is_monthly = True
    year = request.GET.get('year', str(today_j.year))
    month = request.GET.get('month', str(today_j.month))
    from_date_text = request.GET.get('FromDateHijri', _jdate_text(timezone.localdate()))
    to_date_text = request.GET.get('ToDateHijri', _jdate_text(timezone.localdate()))
    date_from = date_to = None
    date_error = ''
    try:
        if criteria_submitted:
            if is_monthly:
                year_value, month_value = int(year), int(month)
                if not 1300 <= year_value <= 1600 or not 1 <= month_value <= 12:
                    raise ValueError('سال یا ماه جلالی نامعتبر است.')
                first_j = jdatetime.date(year_value, month_value, 1)
                next_j = (jdatetime.date(year_value + 1, 1, 1)
                          if month_value == 12 else
                          jdatetime.date(year_value, month_value + 1, 1))
                date_from = first_j.togregorian()
                date_to = next_j.togregorian() - gregorian.timedelta(days=1)
                year, month = str(year_value), str(month_value)
            else:
                date_from = parse_jalali(from_date_text)
                date_to = parse_jalali(to_date_text)
            if date_from > date_to or (date_to - date_from).days > 366:
                raise ValueError('بازه باید معتبر و حداکثر یک سال باشد.')
    except (TypeError, ValueError, OverflowError) as exc:
        date_error = str(exc) or 'بازهٔ تاریخ معتبر نیست.'
        messages.error(request, date_error)
    if date_from is not None and date_to is not None and not date_error:
        from_date_text = _jdate_text(date_from)
        to_date_text = _jdate_text(date_to)

    title = ('گزارش روزانه فردی' if code == 'daily' else 'گزارش مجموع')
    header = (['شماره کارگری', 'نام', 'واحد', 'تاریخ', 'روز هفته',
               'ورود ۱', 'ورود ۲', 'ورود ۳', 'خروج ۱', 'خروج ۲', 'خروج ۳',
               'کارکرد (دقیقه)', 'تأخیر',
               'تعجیل', 'اضافه‌کاری', 'وضعیت']
              if code == 'daily' else
              ['پرسنل', 'روزهای ثبت‌شده', 'حاضر', 'غیبت', 'تأخیر (دقیقه)',
               'تعجیل (دقیقه)', 'کارکرد (دقیقه)', 'اضافه‌کاری (دقیقه)'])
    rows = []
    report_ready = criteria_submitted and bool(selected_employees) and not date_error

    if report_ready and code == 'daily':
        daily_qs = DailyRecord.objects.filter(
            employee__in=selected_employees,
            date__gte=date_from, date__lte=date_to,
        ).select_related('employee', 'employee__department').order_by(
            'employee__last_name', 'employee__first_name', 'date')
        if request.GET.get('export') == 'csv' and has_perm(request.user, 'reports', 'export'):
            raw = list(daily_qs[:MAX_DAILY_EXPORT_ROWS + 1])
            if len(raw) > MAX_DAILY_EXPORT_ROWS:
                return HttpResponse(
                    'برای جلوگیری از خروجی ناقص، بازه یا تعداد پرسنل را کمتر کنید.',
                    status=413, content_type='text/plain; charset=utf-8')
            return _csv_response(title, header, _daily_report_rows(raw))
        page_obj = Paginator(selected_employees, 1).get_page(request.GET.get('page'))
        page_employees = list(page_obj.object_list)
        page_records = daily_qs.filter(employee__in=page_employees)
        rows = _daily_report_rows(page_records)
    elif report_ready and code == 'sum':
        aggregates = {
            item['employee_id']: item
            for item in DailyRecord.objects.filter(
                employee__in=selected_employees,
                date__gte=date_from, date__lte=date_to,
            ).values('employee_id').annotate(
                total_days=Count('pk'),
                present_days=Count('pk', filter=~Q(state__in=(
                    DailyRecord.State.ABSENT, DailyRecord.State.HOLIDAY))),
                absent_days=Count('pk', filter=Q(state=DailyRecord.State.ABSENT)),
                late_minutes=Sum('late_minutes'),
                early_minutes=Sum('early_minutes'),
                worked_minutes=Sum('worked_minutes'),
                overtime_minutes=Sum('overtime_minutes'),
            )
        }
        summary_rows = []
        for employee in selected_employees:
            totals = aggregates.get(employee.pk, {})
            summary_rows.append([
                str(employee), totals.get('total_days', 0),
                totals.get('present_days', 0), totals.get('absent_days', 0),
                totals.get('late_minutes') or 0, totals.get('early_minutes') or 0,
                totals.get('worked_minutes') or 0,
                totals.get('overtime_minutes') or 0,
            ])
        if request.GET.get('export') == 'csv' and has_perm(request.user, 'reports', 'export'):
            return _csv_response(title, header, summary_rows)
        page_obj = Paginator(summary_rows, 1).get_page(request.GET.get('page'))
        rows = page_obj.object_list
    else:
        page_obj = None

    def page_url(number):
        params = request.GET.copy()
        params['page'] = number
        return '?' + urlencode(params, doseq=True)

    export_params = request.GET.copy()
    export_params.pop('page', None)
    export_params['export'] = 'csv'

    return render(request, 'reports/personnel_report.html', {
        'title': title, 'header': header, 'rows': rows,
        'employees': visible, 'department_nodes': department_nodes,
        'employment_types': employment_types, 'certificates': certificates,
        'search_properties': search_properties,
        'selected_badges': selected_badges,
        'selected_count': len(selected_employees),
        'criteria_submitted': criteria_submitted,
        'report_ready': report_ready,
        'is_monthly': is_monthly,
        'year': year, 'month': month,
        'from_date': from_date_text, 'to_date': to_date_text,
        'page_obj': page_obj,
        'previous_url': page_url(page_obj.previous_page_number()) if page_obj and page_obj.has_previous() else None,
        'next_url': page_url(page_obj.next_page_number()) if page_obj and page_obj.has_next() else None,
        'can_export': has_perm(request.user, 'reports', 'export'),
        'export_url': '?' + urlencode(export_params, doseq=True),
    })


@require_perm('reports', 'report')
def employee_search(request):
    """Scope-safe lookup endpoint used by the personnel selector filters."""
    queryset = scoped_employees(
        request.user, 'reports', Employee.objects.filter(is_active=True)
    ).select_related('department')
    term = request.GET.get('q', '').strip()[:100]
    if term:
        queryset = queryset.filter(
            Q(first_name__icontains=term) | Q(last_name__icontains=term)
            | Q(badge_no__icontains=term)
        )

    raw_department_ids = request.GET.getlist('department')
    if len(raw_department_ids) > 500:
        return JsonResponse({'error': 'تعداد واحدهای انتخابی بیش از حد مجاز است.'}, status=400)
    department_ids = set()
    for value in raw_department_ids:
        try:
            department_ids.add(int(value))
        except (TypeError, ValueError):
            continue
    if department_ids:
        queryset = queryset.filter(
            department_id__in=_department_subtree_ids(department_ids))

    certificate = request.GET.get('certificate', '')
    if certificate:
        if certificate not in _certificate_choices():
            return JsonResponse({'error': 'مدرک انتخاب‌شده معتبر نیست.'}, status=400)
        queryset = queryset.filter(certificate=certificate)
    employment_type = request.GET.get('employment_type', '')
    if employment_type:
        try:
            queryset = queryset.filter(emp_type_id=int(employment_type))
        except (TypeError, ValueError):
            return JsonResponse({'error': 'نوع استخدام نامعتبر است.'}, status=400)

    filter_ids = request.GET.getlist('custom_filter')
    if len(filter_ids) > 100:
        return JsonResponse({'error': 'تعداد فیلترهای سفارشی بیش از حد مجاز است.'}, status=400)
    for raw_id in dict.fromkeys(filter_ids):
        try:
            filter_id = int(raw_id)
        except (TypeError, ValueError):
            continue
        search_property = EmployeeFieldDef.objects.filter(
            pk=filter_id, is_active=True, is_search_filter=True).first()
        if not search_property:
            continue
        value = request.GET.get(f'custom_{filter_id}')
        if value is None:
            continue
        property_values = EmployeeFieldVal.objects.filter(
            employee_id=OuterRef('pk'), field_def=search_property)
        if search_property.field_type == EmployeeFieldDef.FieldType.BOOLEAN:
            if value == 'true':
                matching_values = property_values.filter(
                    value__in=('on', '1', 'true', 'True'))
                queryset = queryset.filter(Exists(matching_values))
            elif value == 'false':
                matching_values = property_values.filter(
                    value__in=('', '0', 'false', 'False'))
                queryset = queryset.filter(
                    Q(Exists(matching_values)) | ~Exists(property_values))
            else:
                return JsonResponse({'error': 'مقدار فیلتر بله/خیر نامعتبر است.'}, status=400)
        else:
            if search_property.field_type == EmployeeFieldDef.FieldType.DROPDOWN:
                allowed_values = [item.strip() for item in
                                  search_property.options.replace('|', '\n').splitlines()
                                  if item.strip()]
                if value and value not in allowed_values:
                    return JsonResponse({'error': 'گزینهٔ فیلتر سفارشی معتبر نیست.'}, status=400)
                if not value:
                    continue
            if value:
                queryset = queryset.filter(Exists(property_values.filter(value=value)))
            else:
                queryset = queryset.filter(
                    Q(Exists(property_values.filter(value=''))) | ~Exists(property_values))

    matches = list(queryset.order_by(
        'last_name', 'first_name', 'badge_no')[:501])
    results = [{
        'badge_no': employee.badge_no,
        'name': f'{employee.first_name} {employee.last_name}',
        'department_id': employee.department_id,
        'department': employee.department.name if employee.department_id else '',
    } for employee in matches[:500]]
    return JsonResponse({'results': results, 'truncated': len(matches) > 500})


@require_perm('reports', 'view')
def index(request):
    return render(request, 'reports/index.html', {
        'standard': [
            ('sum', 'گزارش مجموع'), ('late', 'تأخیر و تعجیل'),
            ('overtime', 'اضافه‌کاری'), ('requests', 'درخواست‌ها'),
            ('balances', 'مانده مرخصی'), ('daily', 'کارکرد روزانه'),
            ('raw', 'تردد خام دستگاه‌ها'), ('devices', 'وضعیت دستگاه‌ها'),
            ('visitors', 'بازدیدکنندگان'), ('cardex', 'کارنامه فرد (Cardex)')],
        'defs': ReportDef.objects.all(),
        'can_build': has_perm(request.user, 'reports', 'manage'),
    })


@require_perm('reports', 'report')
def run_standard(request, code):
    if code in ('daily', 'sum'):
        return _personnel_report(request, code)
    if code not in ('late', 'overtime', 'requests', 'balances', 'raw',
                    'devices', 'visitors', 'cardex'):
        raise Http404('گزارش پیدا نشد.')
    if code == 'devices' and (
            not has_perm(request.user, 'device', 'view')
            or data_scope(request.user, 'device') != 'all'):
        return HttpResponseForbidden(
            '<div dir="rtl">برای گزارش وضعیت دستگاه‌ها به مجوز و دامنهٔ کامل دستگاه نیاز است.</div>')
    jyear, jmonth = _month_params(request)
    month_start = jdatetime.date(jyear, jmonth, 1).togregorian()
    month_end = (jdatetime.date(jyear + 1, 1, 1) if jmonth == 12
                 else jdatetime.date(jyear, jmonth + 1, 1)).togregorian()
    start, end = month_start, month_end
    date_from_text = request.GET.get('date_from', '').strip()
    date_to_text = request.GET.get('date_to', '').strip()
    period_label = f'{jyear}/{jmonth:02d}'
    if (("date_from" in request.GET or "date_to" in request.GET)
            and request.GET.get('isMonthly') != 'true'):
        try:
            start = parse_jalali(date_from_text) if date_from_text else month_start
            inclusive_end = parse_jalali(date_to_text) if date_to_text else month_end - gregorian.timedelta(days=1)
            if start > inclusive_end or (inclusive_end - start).days > 366:
                raise ValueError('بازه باید معتبر و حداکثر یک سال باشد.')
            end = inclusive_end + gregorian.timedelta(days=1)
            period_label = f'{_jdate_text(start)} تا {_jdate_text(inclusive_end)}'
        except ValueError as exc:
            messages.error(request, str(exc) or 'بازهٔ تاریخ نامعتبر است؛ ماه انتخاب‌شده نمایش داده شد.')
            start, end = month_start, month_end
    employees = scoped_employees(request.user, 'reports', Employee.objects.all())
    start_dt = timezone.make_aware(gregorian.datetime.combine(start, gregorian.time.min))
    end_dt = timezone.make_aware(gregorian.datetime.combine(end, gregorian.time.min))
    header, rows, title = [], [], code

    if code == 'late':
        title = 'تأخیر و تعجیل'
        header = ['کارمند', 'تاریخ', 'تأخیر', 'تعجیل', 'وضعیت']
        for r in DailyRecord.objects.filter(
                employee__in=employees, date__gte=start, date__lt=end
        ).exclude(late_minutes=0, early_minutes=0).select_related('employee'):
            rows.append([str(r.employee), _jdate_text(r.date), r.late_minutes,
                         r.early_minutes, r.get_state_display()])
    elif code == 'overtime':
        title = 'اضافه‌کاری'
        header = ['کارمند', 'تاریخ', 'اضافه‌کاری (دقیقه)']
        for r in DailyRecord.objects.filter(
                employee__in=employees, date__gte=start, date__lt=end,
                overtime_minutes__gt=0).select_related('employee'):
            rows.append([str(r.employee), _jdate_text(r.date), r.overtime_minutes])
    elif code == 'requests':
        title = 'درخواست‌ها'
        header = ['کارمند', 'نوع', 'وضعیت', 'تاریخ', 'روز', 'شرح']
        for req in Request.objects.filter(
                employee__in=employees, created_at__date__gte=start,
                created_at__date__lt=end).select_related('employee'):
            rows.append([str(req.employee), req.get_kind_display(),
                         req.get_status_display(), _report_value(req.for_date or req.from_date),
                         req.days, req.description])
    elif code == 'balances':
        title = 'مانده مرخصی'
        header = ['کارمند', 'سال', 'اعتبار', 'مصرف', 'مانده']
        from apps.attendance.models import LeaveBalance
        for b in LeaveBalance.objects.filter(
                employee__in=employees, jyear=jyear).select_related(
                'employee', 'leave_type'):
            rows.append([str(b.employee), b.leave_type.name, b.granted, b.used,
                         b.remaining])
    elif code == 'raw':
        title = 'تردد خام دستگاه‌ها'
        header = ['دستگاه', 'کد', 'زمان', 'وضعیت']
        allowed_badges = employees.values_list('badge_no', flat=True)
        for r in RawRecord.objects.filter(
                pin__in=allowed_badges, punch_time__gte=start_dt, punch_time__lt=end_dt
        ).select_related('device')[:500]:
            rows.append([r.device.name, r.pin, _report_value(r.punch_time), r.get_state_display()])
    elif code == 'devices':
        title = 'وضعیت دستگاه‌ها'
        header = ['نام', 'سریال', 'آخرین آنلاین', 'تعداد رکورد']
        for d in Device.objects.all():
            rows.append([d.name, d.serial, _report_value(d.last_online_at) if d.last_online_at else '—',
                         d.records_count])
    elif code == 'visitors':
        title = 'بازدیدکنندگان'
        header = ['نام', 'میزبان', 'علت', 'ورود', 'خروج']
        for v in Visitor.objects.filter(
                host__in=employees, enter_time__gte=start_dt, enter_time__lt=end_dt
        ).select_related('host'):
            rows.append([v.name, str(v.host or '—'), str(v.reason or '—'),
                         _report_value(v.enter_time), _report_value(v.leave_time) if v.leave_time else '—'])

    elif code == 'cardex':
        title = 'کارنامه فرد (Cardex)'
        header = ['تاریخ', 'نوع رویداد', 'جزئیات']
        emp_id = request.GET.get('employee_id')
        base_qs = DailyRecord.objects.filter(
            employee__in=employees, date__gte=start, date__lt=end)
        if emp_id:
            base_qs = base_qs.filter(employee_id=emp_id)
        for r in base_qs.select_related('employee').order_by('date'):
            rows.append([_jdate_text(r.date), f'کارکرد — {r.employee}',
                         f'{r.get_state_display()}، {r.worked_minutes} دقیقه'])
        req_qs = Request.objects.filter(
            employee__in=employees, created_at__date__gte=start,
            created_at__date__lt=end)
        if emp_id:
            req_qs = req_qs.filter(employee_id=emp_id)
        for req in req_qs.select_related('employee'):
            created_local = timezone.localtime(req.created_at) if timezone.is_aware(req.created_at) else req.created_at
            rows.append([_jdate_text(created_local.date()), f'درخواست — {req.employee}',
                         f'{req.get_kind_display()} ({req.get_status_display()})'])
        rp_qs = RewardPenalty.objects.filter(
            employee__in=employees, date__gte=start, date__lt=end)
        if emp_id:
            rp_qs = rp_qs.filter(employee_id=emp_id)
        for rp in rp_qs.select_related('employee'):
            rows.append([_jdate_text(rp.date), f'پاداش/تنبیه — {rp.employee}',
                         f'{rp.get_kind_display()}: {rp.title}'])
        rows.sort(key=lambda row: row[0])

    if request.GET.get('export') == 'csv' and has_perm(request.user, 'reports',
                                                       'export'):
        return _csv_response(title, header, rows)

    export_params = request.GET.copy()
    export_params['export'] = 'csv'
    return render(request, 'reports/run.html', {
        'title': title, 'header': header, 'rows': rows,
        'year': jyear, 'month': jmonth, 'period_label': period_label,
        'date_from': date_from_text, 'date_to': date_to_text,
        'is_monthly': (request.GET.get('isMonthly') == 'true'
                        or not ('date_from' in request.GET or 'date_to' in request.GET)),
        'export_url': '?' + urlencode(export_params, doseq=True),
        'code': code,
        'can_export': has_perm(request.user, 'reports', 'export'),
    })


def _csv_safe(value):
    """Neutralize spreadsheet formulas in untrusted text while preserving numbers."""
    if isinstance(value, str):
        stripped = value.lstrip(' \t\r\n')
        if value[:1] in ('=', '+', '-', '@', '\t', '\r', '\n') or stripped[:1] in ('=', '+', '-', '@'):
            return "'" + value
    return value


def _csv_response(title, header, rows):
    response = HttpResponse(content_type='text/csv; charset=utf-8')
    response['Content-Disposition'] = f'attachment; filename="report.csv"'
    response.write('\ufeff')  # BOM برای اکسل
    writer = csv.writer(response)
    writer.writerow(header)
    writer.writerows([_csv_safe(value) for value in row] for row in rows)
    return response


# ---------- گزارش‌ساز ----------

@require_perm('reports', 'manage')
def builder(request):
    if request.method == 'POST':
        try:
            model_key = request.POST.get('model_key', 'daily_record')
            if model_key not in MODEL_MAP:
                raise ValueError('مدل نامعتبر است.')
            columns = [c.strip() for c in
                       request.POST.get('columns', '').split(',') if c.strip()]
            invalid = [c for c in columns if c not in MODEL_MAP[model_key][1]]
            if invalid:
                raise ValueError(f'ستون نامعتبر: {invalid}')
            sum_columns = [c.strip() for c in
                           request.POST.get('sum_columns', '').split(',')
                           if c.strip()]
            bad_sum = [c for c in sum_columns if c not in columns]
            if bad_sum:
                raise ValueError(f'ستون مجموع باید از ستون‌ها انتخاب شود: {bad_sum}')
            ReportDef.objects.update_or_create(
                code=request.POST.get('code', '').strip(),
                defaults={
                    'title': request.POST.get('title', '').strip(),
                    'module': model_key,
                    'columns_json': columns,
                    'filters_json': ['date_from', 'date_to'],
                    'definition_json': {
                        'model': model_key,
                        'sum': sum_columns,
                        'order_by': request.POST.get('order_by', '').strip()},
                })
            messages.success(request, 'گزارش ذخیره شد.')
            return redirect('reports:builder')
        except ValueError as exc:
            messages.error(request, str(exc))
    return render(request, 'reports/builder.html', {
        'defs': ReportDef.objects.all(),
        'models': {k: list(v[1].keys()) for k, v in MODEL_MAP.items()},
    })


@require_perm('reports', 'report')
def run_def(request, code):
    definition = get_object_or_404(ReportDef, code=code)
    model_key = definition.definition_json.get('model', '')
    if model_key not in MODEL_MAP:
        messages.error(request, 'تعریف گزارش نامعتبر است.')
        return redirect('reports:index')
    model, fields = MODEL_MAP[model_key]
    columns = [c for c in (definition.columns_json or []) if c in fields]
    qs = model.objects.all()
    if model_key in ('daily_record', 'punch', 'request'):
        employees = scoped_employees(request.user, 'reports', Employee.objects.all())
        qs = qs.filter(employee__in=employees)
    date_field = {'daily_record': 'date', 'punch': 'date',
                  'request': 'for_date'}[model_key]
    try:
        if request.GET.get('date_from'):
            qs = qs.filter(**{f'{date_field}__gte':
                              parse_jalali(request.GET['date_from'])})
        if request.GET.get('date_to'):
            qs = qs.filter(**{f'{date_field}__lte':
                              parse_jalali(request.GET['date_to'])})
    except ValueError:
        messages.error(request, 'بازه تاریخ نامعتبر است.')
        return redirect('reports:builder')
    order = definition.definition_json.get('order_by') or ''
    if order.lstrip('-') in fields:
        qs = qs.order_by(order)
    qs = qs.select_related('employee')[:500]

    header = columns
    rows = []
    totals = {c: 0.0 for c in (definition.definition_json.get('sum') or [])
              if c in columns}
    for obj in qs:
        row = []
        for c in columns:
            value = getattr(obj, fields[c].split('__')[0], '') \
                if '__' not in fields[c] else getattr(obj, 'employee')
            if fields[c].endswith('__last_name'):
                value = obj.employee.last_name if obj.employee else ''
            row.append(_report_value(value))
            if c in totals:
                try:
                    totals[c] += float(value or 0)
                except (TypeError, ValueError):
                    pass
        rows.append(row)
    if totals:
        rows.append(['مجموع'] + [
            (f'{totals[c]:g}' if c in totals else '') for c in columns[1:]])

    if request.GET.get('export') == 'csv' and has_perm(request.user, 'reports',
                                                       'export'):
        return _csv_response(definition.title, header, rows)
    return render(request, 'reports/run.html', {
        'title': definition.title, 'header': header, 'rows': rows,
        'code': 'def:' + code,
        'can_export': has_perm(request.user, 'reports', 'export'),
    })
