"""داشبورد و گزارش‌های مدیریتی — نمای تجمیعی همه زیرسامانه‌ها."""
import jdatetime
from django.contrib.auth.decorators import login_required
from django.db.models import Count, Q
from django.shortcuts import render

from apps.tasks.views import MONTHS

#: وضعیت‌هایی که نامه در آن‌ها «قابل اقدام» و در نتیجه مشمول عقب‌افتادگی است
ACTIONABLE_STATUSES = ['registered', 'in_review', 'referred', 'in_progress']


def _is_manager(user) -> bool:
    return user.is_superuser or user.has_perm('secretariat.view_all_letter')


def _bars(rows, label_map, total):
    """ساخت ردیف‌های نمودار میله‌ای تخت (سبک v3 — بدون سایه و گرادیان)."""
    out = []
    for value, count in rows:
        out.append({
            'label': label_map.get(value, value),
            'count': count,
            'pct': round(count * 100 / total) if total else 0,
        })
    return out


@login_required
def dashboard(request):
    from apps.meetings.models import Meeting
    from apps.secretariat.models import Letter, Referral
    from apps.secretariat.views import visible_letters
    from apps.tasks.models import Task
    from apps.tasks.views import _can_manage, _visible_tasks

    user = request.user
    today = jdatetime.date.today()
    month_start = jdatetime.date(today.year, today.month, 1)

    letters = visible_letters(user).select_related('office')
    tasks = _visible_tasks(user)

    # --- KPIهای شخصی ---
    my_pending = Referral.objects.filter(
        to_user=user, status=Referral.Status.PENDING).count()
    open_tasks = tasks.filter(
        status__in=[Task.Status.OPEN, Task.Status.IN_PROGRESS]).count()
    overdue_tasks = tasks.filter(
        status__in=[Task.Status.OPEN, Task.Status.IN_PROGRESS],
        due_date__lt=today).count()
    month_meetings = Meeting.objects.filter(
        jalali_date__gte=month_start, jalali_date__lte=today,
    ).exclude(status=Meeting.Status.CANCELLED).count()

    # --- نمودار نامه‌های من بر اساس وضعیت ---
    status_rows = list(letters.values_list('status').annotate(c=Count('pk')).order_by())
    status_map = dict(Letter.Status.choices)
    letters_total = letters.count()
    letter_bars = _bars([(s, c) for s, c in status_rows], status_map, letters_total)

    # --- نمودار وظایف بر اساس وضعیت ---
    task_rows = list(tasks.values_list('status').annotate(c=Count('pk')).order_by())
    task_bars = _bars([(s, c) for s, c in task_rows], dict(Task.Status.choices),
                      tasks.count())

    # --- نامه‌های عقب‌افتاده (مشترک) ---
    overdue_letters = letters.filter(
        status__in=ACTIONABLE_STATUSES, deadline__lt=today,
    ).exclude(deadline=None)[:8]

    ctx = {
        'today': today,
        'month_name': MONTHS[today.month - 1],
        'my_pending': my_pending,
        'open_tasks': open_tasks,
        'overdue_tasks': overdue_tasks,
        'month_meetings': month_meetings,
        'letter_bars': letter_bars,
        'task_bars': task_bars,
        'overdue_letters': overdue_letters,
        'is_manager': _is_manager(user),
        'can_manage_tasks': _can_manage(user),
    }

    if ctx['is_manager']:
        all_letters = Letter.objects.all()
        by_type = dict(
            all_letters.filter(jalali_year=today.year, letter_date__gte=month_start)
            .values_list('letter_type').annotate(c=Count('pk')))
        ctx.update({
            'org_letters_total': all_letters.count(),
            'org_in_progress': all_letters.filter(status__in=ACTIONABLE_STATUSES).count(),
            'org_overdue': all_letters.filter(
                status__in=ACTIONABLE_STATUSES, deadline__lt=today,
            ).exclude(deadline=None).count(),
            'org_archived': all_letters.filter(status=Letter.Status.ARCHIVED).count(),
            'org_month_by_type': [
                {'label': label, 'count': by_type.get(value, 0)}
                for value, label in Letter.LetterType.choices
            ],
            'org_tasks_overdue': Task.objects.filter(
                status__in=[Task.Status.OPEN, Task.Status.IN_PROGRESS],
                due_date__lt=today).count(),
        })
    return render(request, 'reports/dashboard.html', ctx)


# ---------------------------------------------------------------------------
# گزارش‌ساز پیشرفته (فاز ۳): فیلتر تاریخ شمسی، خروجی CSV/XLSX/PDF و چاپ
# ---------------------------------------------------------------------------

def _builder_params(request):
    from apps.secretariat.views import _parse_jalali_input
    slug = request.GET.get('report', 'letters')
    date_from = _parse_jalali_input(request.GET.get('date_from', ''))
    date_to = _parse_jalali_input(request.GET.get('date_to', ''))
    return slug, date_from, date_to


@login_required
def builder(request):
    """گزارش‌ساز: انتخاب گزارش و بازه شمسی + پیش‌نمایش جدول."""
    from apps.reports.datasets import REPORTS, report_titles, run_report

    slug, date_from, date_to = _builder_params(request)
    if slug not in REPORTS:
        slug = 'letters'
    title, headers, rows = run_report(slug, date_from, date_to)
    return render(request, 'reports/builder.html', {
        'slug': slug, 'title': title, 'headers': headers,
        'rows': rows[:200], 'row_count': len(rows),
        'reports': report_titles(),
        'date_from': request.GET.get('date_from', ''),
        'date_to': request.GET.get('date_to', ''),
    })


@login_required
def export(request):
    """خروجی گزارش: csv | xlsx | pdf | print."""
    from django.http import HttpResponseBadRequest
    from apps.reports.datasets import run_report
    from apps.reports.export import csv_response, pdf_response, xlsx_response

    slug, date_from, date_to = _builder_params(request)
    fmt = request.GET.get('format', 'csv')
    try:
        title, headers, rows = run_report(slug, date_from, date_to)
    except ValueError as exc:
        return HttpResponseBadRequest(str(exc))
    if fmt == 'xlsx':
        return xlsx_response(title, headers, rows)
    if fmt == 'pdf':
        return pdf_response(title, headers, rows)
    if fmt == 'print':
        return render(request, 'reports/print.html', {
            'title': title, 'headers': headers, 'rows': rows})
    return csv_response(title, headers, rows)
