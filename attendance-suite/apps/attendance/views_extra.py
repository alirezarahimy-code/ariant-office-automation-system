"""پردازش نهایی: ورود CSV، وضعیت پرسنل، گردش‌کار سفارشی."""
import csv
import datetime as gregorian
import io

import jdatetime
from django.contrib import messages
from django.shortcuts import redirect, render

from apps.core.services import audit, has_perm, require_perm, scoped_employees
from apps.org.models import Employee

from .models import DailyRecord, Request, WorkflowRule
from .services import (parse_jalali, register_punch)


@require_perm('attendance', 'manage')
def import_csv(request):
    """ورود رویدادهای تردد از CSV (USB/اکسل): badge_no,date,time,direction."""
    result = None
    if request.method == 'POST' and request.FILES.get('file'):
        try:
            from apps.org.models import Employee
            text = request.FILES['file'].read().decode('utf-8-sig')
            reader = csv.DictReader(io.StringIO(text))
            created = errors = 0
            for row in reader:
                try:
                    employee = Employee.objects.get(
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
