"""موتور گزارش‌ها + گزارش‌ساز — تعریف امن (فیلدهای سفید) + خروجی CSV."""
import csv
import datetime as gregorian

import jdatetime
from django.contrib import messages
from django.http import HttpResponse
from django.shortcuts import get_object_or_404, redirect, render

from apps.attendance.models import (AttendancePolicy, DailyRecord, Punch,
                                    Request, RewardPenalty)
from apps.attendance.services import monthly_summary, parse_jalali
from apps.core.services import (audit, data_scope, has_perm, require_perm,
                                scoped_employees)
from apps.device.models import Device, RawRecord, Visitor
from apps.org.models import Employee

from .models import ReportDef

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
    today = jdatetime.date.today()
    return (int(request.GET.get('year') or today.year),
            int(request.GET.get('month') or today.month))


@require_perm('reports', 'view')
def index(request):
    return render(request, 'reports/index.html', {
        'standard': [
            ('sum', 'خلاصه کارکرد ماهانه'), ('late', 'تأخیر و تعجیل'),
            ('overtime', 'اضافه‌کاری'), ('requests', 'درخواست‌ها'),
            ('balances', 'مانده مرخصی'), ('daily', 'کارکرد روزانه'),
            ('raw', 'تردد خام دستگاه‌ها'), ('devices', 'وضعیت دستگاه‌ها'),
            ('visitors', 'بازدیدکنندگان'), ('cardex', 'کارنامه فرد (Cardex)')],
        'defs': ReportDef.objects.all(),
        'can_build': has_perm(request.user, 'reports', 'manage'),
    })


@require_perm('reports', 'report')
def run_standard(request, code):
    jyear, jmonth = _month_params(request)
    start = jdatetime.date(jyear, jmonth, 1).togregorian()
    end = (jdatetime.date(jyear + 1, 1, 1) if jmonth == 12
           else jdatetime.date(jyear, jmonth + 1, 1)).togregorian()
    employees = scoped_employees(request.user, 'reports', Employee.objects.all())
    header, rows, title = [], [], code

    if code == 'sum':
        title = 'خلاصه کارکرد ماهانه'
        header = ['کارمند', 'حاضر', 'تأخیر', 'غیبت', 'کارکرد (دقیقه)', 'اضافه‌کاری']
        for emp in employees:
            s = monthly_summary(emp, jyear, jmonth)
            rows.append([str(emp), s['present_days'], s['late_days'],
                         s['absent_days'], s['worked_minutes'],
                         s['overtime_minutes']])
    elif code == 'late':
        title = 'تأخیر و تعجیل'
        header = ['کارمند', 'تاریخ', 'تأخیر', 'تعجیل', 'وضعیت']
        for r in DailyRecord.objects.filter(
                employee__in=employees, date__gte=start, date__lt=end
        ).exclude(late_minutes=0, early_minutes=0).select_related('employee'):
            rows.append([str(r.employee), str(r.date), r.late_minutes,
                         r.early_minutes, r.get_state_display()])
    elif code == 'overtime':
        title = 'اضافه‌کاری'
        header = ['کارمند', 'تاریخ', 'اضافه‌کاری (دقیقه)']
        for r in DailyRecord.objects.filter(
                employee__in=employees, date__gte=start, date__lt=end,
                overtime_minutes__gt=0).select_related('employee'):
            rows.append([str(r.employee), str(r.date), r.overtime_minutes])
    elif code == 'requests':
        title = 'درخواست‌ها'
        header = ['کارمند', 'نوع', 'وضعیت', 'تاریخ', 'روز', 'شرح']
        for req in Request.objects.filter(
                employee__in=employees, created_at__date__gte=start,
                created_at__date__lt=end).select_related('employee'):
            rows.append([str(req.employee), req.get_kind_display(),
                         req.get_status_display(), str(req.for_date or req.from_date
                                                       or ''),
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
    elif code == 'daily':
        title = 'کارکرد روزانه'
        header = ['کارمند', 'تاریخ', 'ورود', 'خروج', 'کارکرد', 'وضعیت']
        for r in DailyRecord.objects.filter(
                employee__in=employees, date__gte=start, date__lt=end
        ).select_related('employee'):
            rows.append([str(r.employee), str(r.date),
                         r.check_in.strftime('%H:%M') if r.check_in else '',
                         r.check_out.strftime('%H:%M') if r.check_out else '',
                         r.worked_minutes, r.get_state_display()])
    elif code == 'raw':
        title = 'تردد خام دستگاه‌ها'
        header = ['دستگاه', 'کد', 'زمان', 'وضعیت']
        for r in RawRecord.objects.filter(
                punch_time__gte=start, punch_time__lt=end
        ).select_related('device')[:500]:
            rows.append([r.device.name, r.pin, str(r.punch_time), r.get_state_display()])
    elif code == 'devices':
        title = 'وضعیت دستگاه‌ها'
        header = ['نام', 'سریال', 'آخرین آنلاین', 'تعداد رکورد']
        for d in Device.objects.all():
            rows.append([d.name, d.serial, str(d.last_online_at or '—'),
                         d.records_count])
    elif code == 'visitors':
        title = 'بازدیدکنندگان'
        header = ['نام', 'میزبان', 'علت', 'ورود', 'خروج']
        for v in Visitor.objects.filter(
                enter_time__gte=start, enter_time__lt=end).select_related('host'):
            rows.append([v.name, str(v.host or '—'), str(v.reason or '—'),
                         str(v.enter_time), str(v.leave_time or '—')])

    elif code == 'cardex':
        title = 'کارنامه فرد (Cardex)'
        header = ['تاریخ', 'نوع رویداد', 'جزئیات']
        emp_id = request.GET.get('employee_id')
        base_qs = DailyRecord.objects.filter(
            employee__in=employees, date__gte=start, date__lt=end)
        if emp_id:
            base_qs = base_qs.filter(employee_id=emp_id)
        for r in base_qs.select_related('employee').order_by('date'):
            rows.append([str(r.date), f'کارکرد — {r.employee}',
                         f'{r.get_state_display()}، {r.worked_minutes} دقیقه'])
        req_qs = Request.objects.filter(
            employee__in=employees, created_at__date__gte=start,
            created_at__date__lt=end)
        if emp_id:
            req_qs = req_qs.filter(employee_id=emp_id)
        for req in req_qs.select_related('employee'):
            rows.append([str(req.created_at.date()), f'درخواست — {req.employee}',
                         f'{req.get_kind_display()} ({req.get_status_display()})'])
        rp_qs = RewardPenalty.objects.filter(
            employee__in=employees, date__gte=start, date__lt=end)
        if emp_id:
            rp_qs = rp_qs.filter(employee_id=emp_id)
        for rp in rp_qs.select_related('employee'):
            rows.append([str(rp.date), f'پاداش/تنبیه — {rp.employee}',
                         f'{rp.get_kind_display()}: {rp.title}'])
        rows.sort(key=lambda row: row[0])

    if request.GET.get('export') == 'csv' and has_perm(request.user, 'reports',
                                                       'export'):
        return _csv_response(title, header, rows)

    return render(request, 'reports/run.html', {
        'title': title, 'header': header, 'rows': rows,
        'year': jyear, 'month': jmonth, 'code': code,
        'can_export': has_perm(request.user, 'reports', 'export'),
    })


def _csv_response(title, header, rows):
    response = HttpResponse(content_type='text/csv; charset=utf-8')
    response['Content-Disposition'] = f'attachment; filename="report.csv"'
    response.write('\ufeff')  # BOM برای اکسل
    writer = csv.writer(response)
    writer.writerow(header)
    writer.writerows(rows)
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
            ReportDef.objects.update_or_create(
                code=request.POST.get('code', '').strip(),
                defaults={
                    'title': request.POST.get('title', '').strip(),
                    'module': model_key,
                    'columns_json': columns,
                    'definition_json': {
                        'model': model_key,
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
    if model_key in ('daily_record', 'request'):
        employees = scoped_employees(request.user, 'reports', Employee.objects.all())
        qs = qs.filter(employee__in=employees)
    order = definition.definition_json.get('order_by') or ''
    if order.lstrip('-') in fields:
        qs = qs.order_by(order)
    qs = qs.select_related('employee')[:500]

    header = columns
    rows = []
    for obj in qs:
        row = []
        for c in columns:
            value = getattr(obj, fields[c].split('__')[0], '') \
                if '__' not in fields[c] else getattr(obj, 'employee')
            if fields[c].endswith('__last_name'):
                value = obj.employee.last_name if obj.employee else ''
            row.append(str(value) if value is not None else '')
        rows.append(row)

    if request.GET.get('export') == 'csv' and has_perm(request.user, 'reports',
                                                       'export'):
        return _csv_response(definition.title, header, rows)
    return render(request, 'reports/run.html', {
        'title': definition.title, 'header': header, 'rows': rows,
        'code': 'def:' + code,
        'can_export': has_perm(request.user, 'reports', 'export'),
    })
