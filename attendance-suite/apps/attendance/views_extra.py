"""پردازش نهایی: ورود CSV، وضعیت پرسنل، گردش‌کار سفارشی."""
import csv
import datetime as gregorian
import io

import jdatetime
from django.contrib import messages
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import audit, has_perm, require_perm, scoped_employees
from apps.org.models import Employee

from .models import DailyRecord, LeaveDescription, Request, WorkflowRule
from .services import (parse_jalali, register_punch)


@require_perm('attendance', 'manage')
def import_csv(request):
    """ورود رویدادهای تردد از CSV (USB/اکسل): badge_no,date,time,direction."""
    result = None
    allowed_employees = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True))
    if request.method == 'POST' and request.FILES.get('file'):
        try:
            text = request.FILES['file'].read().decode('utf-8-sig')
            reader = csv.DictReader(io.StringIO(text))
            created = errors = 0
            for row in reader:
                try:
                    employee = allowed_employees.get(
                        badge_no=(row.get('badge_no') or '').strip())
                    date = parse_jalali(row.get('date') or '')
                    time_text = (row.get('time') or '').strip()
                    h, m = time_text.split(':')
                    register_punch(
                        employee, date, gregorian.time(int(h), int(m)),
                        (row.get('direction') or 'in').strip(),
                        source='import', created_by=request.user,
                        description=(row.get('description') or '')[:300])
                    created += 1
                except Exception:
                    errors += 1
            result = {'created': created, 'errors': errors}
            audit(request.user, 'import_csv', detail=str(result))
            messages.success(
                request, f"ورود اطلاعات: {created} ثبت شد، {errors} خطا.")
        except UnicodeDecodeError:
            messages.error(request, 'فرمت فایل نادرست است (UTF-8 مورد انتظار).')
    return render(request, 'attendance/import_csv.html', {'result': result})


@require_perm('attendance', 'manage')
def leave_descriptions(request, pk=None):
    """مدیریت LeaveDescription بر اساس کنترلر موجود در اسمبلی T5Web."""
    row = get_object_or_404(LeaveDescription, pk=pk) if pk is not None else None
    values = {
        'description': row.description if row else '',
        'for_leave_type': str(row.for_leave_type) if row else '',
    }
    if request.method == 'POST':
        action = request.POST.get('action', 'save')
        if row and action == 'delete':
            audit(request.user, 'delete', 'LeaveDescription', row.pk,
                  detail=row.description)
            row.delete()
            messages.success(request, 'شرح حذف شد.')
            return redirect('attendance:leave_descriptions')
        description = request.POST.get('description', '').strip()
        try:
            leave_type = int(request.POST.get('for_leave_type', ''))
            valid_types = {value for value, _ in LeaveDescription.ForLeaveType.choices}
            if leave_type not in valid_types:
                raise ValueError
        except (TypeError, ValueError):
            leave_type = None
        if not description or len(description) > 200 or leave_type is None:
            messages.error(request, 'شرح و نوع مرخصی/ماموریت را به‌درستی وارد کنید.')
            values = {
                'description': description[:200],
                'for_leave_type': request.POST.get('for_leave_type', ''),
            }
        else:
            was_new = row is None
            row = row or LeaveDescription()
            row.description = description
            row.for_leave_type = leave_type
            row.save()
            audit(request.user, 'add' if was_new else 'change',
                  'LeaveDescription', row.pk, detail=description)
            messages.success(request, 'شرح ذخیره شد.')
            return redirect('attendance:leave_descriptions')
    return render(request, 'attendance/leave_descriptions.html', {
        'rows': LeaveDescription.objects.all(),
        'row': row,
        'values': values,
        'can_manage': has_perm(request.user, 'attendance', 'manage'),
        'leave_types': LeaveDescription.ForLeaveType.choices,
    })


@require_perm('reports', 'report')
def personnel_status(request):
    """وضعیت پرسنل (PersonnelStatus) — نمای کلی ماه جاری."""
    today = jdatetime.date.today()
    rows = []
    for emp in scoped_employees(request.user, 'reports',
                                Employee.objects.filter(is_active=True)):
        records = DailyRecord.objects.filter(
            employee=emp, date__gte=jdatetime.date(today.year, today.month, 1)
            .togregorian())
        last = records.order_by('-date').first()
        rows.append({
            'employee': emp,
            'present': records.exclude(
                state__in=['absent', 'holiday']).count(),
            'late': records.filter(late_minutes__gt=0).count(),
            'absent': records.filter(state='absent').count(),
            'last': last,
        })
    return render(request, 'attendance/personnel_status.html', {
        'rows': rows, 'today': today})


@require_perm('requests', 'manage')
def custom_wf(request):
    """گردش‌کار سفارشی (CustomWF) — قاعده تأیید هر نوع درخواست."""
    if request.method == 'POST':
        kind = request.POST.get('kind')
        rule, _ = WorkflowRule.objects.get_or_create(kind=kind)
        rule.needs_approval = request.POST.get('needs_approval') == 'on'
        rule.use_deputy = request.POST.get('use_deputy') == 'on'
        rule.description = request.POST.get('description', '')
        rule.save()
        audit(request.user, 'change', 'WorkflowRule', kind)
        messages.success(request, 'قاعده گردش‌کار ذخیره شد.')
        return redirect('attendance:custom_wf')
    rules = {r.kind: r for r in WorkflowRule.objects.all()}
    kinds = [{'kind': k, 'label': label,
              'rule': rules.get(k)} for k, label in Request.Kind.choices]
    return render(request, 'attendance/custom_wf.html', {
        'kinds': kinds, 'chain_note':
        'زنجیره تأیید: مدیر مستقیم ← جانشین‌های فعال ← دارندگان مجوز تأیید (requests.approve)'})
