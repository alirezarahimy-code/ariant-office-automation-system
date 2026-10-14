"""نماهای وظایف و تقویم سازمانی شمسی."""
import jdatetime
from django.contrib import messages
from django.contrib.auth import get_user_model
from django.contrib.auth.decorators import login_required
from django.db.models import Q
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import Task
from .services import add_comment, change_status, create_task, update_progress

User = get_user_model()

WEEKDAYS = ('شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه')
MONTHS = ('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
          'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند')


def _can_manage(user) -> bool:
    return user.is_superuser or user.has_perm('tasks.manage_tasks')


def _participant(user, task) -> bool:
    return (task.responsible_id == user.pk or task.created_by_id == user.pk
            or task.collaborators.filter(pk=user.pk).exists())


def _visible_tasks(user):
    if _can_manage(user):
        return Task.objects.all()
    return Task.objects.filter(
        Q(responsible=user) | Q(created_by=user) | Q(collaborators=user)).distinct()


def _parse_jalali(raw: str):
    from apps.secretariat.views import _parse_jalali_input
    return _parse_jalali_input(raw)


@login_required
def task_list(request):
    tab = request.GET.get('tab', 'mine')
    q = request.GET.get('q', '').strip()
    tasks = _visible_tasks(request.user).select_related(
        'responsible', 'created_by').prefetch_related('collaborators')
    if tab == 'mine':
        tasks = tasks.filter(responsible=request.user).exclude(
            status__in=(Task.Status.DONE, Task.Status.CANCELLED))
    elif tab == 'collab':
        tasks = tasks.filter(collaborators=request.user).exclude(
            status__in=(Task.Status.DONE, Task.Status.CANCELLED))
    elif tab == 'created':
        tasks = tasks.filter(created_by=request.user)
    elif tab == 'done':
        tasks = tasks.filter(status=Task.Status.DONE)
    elif tab == 'overdue':
        tasks = tasks.filter(
            status__in=(Task.Status.OPEN, Task.Status.IN_PROGRESS),
            due_date__lt=jdatetime.date.today())
    elif tab != 'all':
        tab = 'mine'
        tasks = tasks.filter(responsible=request.user)
    if q:
        tasks = tasks.filter(Q(title__icontains=q) | Q(description__icontains=q))
    return render(request, 'tasks/list.html', {
        'tasks': tasks[:200], 'tab': tab, 'q': q,
        'can_manage': _can_manage(request.user),
        'users': User.objects.filter(is_active=True).order_by('first_name', 'username')[:200],
    })


@login_required
def task_new(request):
    if request.method == 'POST':
        title = request.POST.get('title', '').strip()[:300]
        if not title:
            messages.error(request, 'عنوان وظیفه الزامی است.')
        else:
            responsible = User.objects.filter(
                pk=request.POST.get('responsible_id') or request.user.pk).first()
            if responsible is None:
                responsible = request.user
            collaborators = User.objects.filter(
                pk__in=request.POST.getlist('collaborators')).exclude(pk=responsible.pk)
            source_letter = None
            source_letter_pk = request.POST.get('source_letter') or None
            if source_letter_pk:
                from apps.secretariat.models import Letter
                source_letter = Letter.objects.filter(pk=source_letter_pk).first()
            task = create_task(
                title=title,
                description=request.POST.get('description', ''),
                responsible=responsible,
                due_jalali=_parse_jalali(request.POST.get('due_date', '')),
                priority=request.POST.get('priority', Task.Priority.NORMAL),
                created_by=request.user,
                source_letter=source_letter,
                collaborators=list(collaborators))
            messages.success(request, f'وظیفه «{task.title}» ایجاد شد.')
            return redirect('tasks:detail', pk=task.pk)
    source_letter = None
    letter_pk = request.GET.get('letter')
    if letter_pk:
        from apps.secretariat.views import visible_letters
        source_letter = visible_letters(request.user).filter(pk=letter_pk).first()
    return render(request, 'tasks/new.html', {
        'users': User.objects.filter(is_active=True).order_by('first_name', 'username')[:200],
        'source_letter': source_letter,
    })


@login_required
def task_detail(request, pk):
    task = get_object_or_404(
        Task.objects.select_related('responsible', 'created_by', 'source_letter',
                                    'source_resolution', 'source_resolution__meeting'),
        pk=pk)
    if not (_can_manage(request.user) or _participant(request.user, task)):
        messages.error(request, 'به این وظیفه دسترسی ندارید.')
        return redirect('tasks:list')
    return render(request, 'tasks/detail.html', {
        'task': task,
        'events': task.events.select_related('actor')[:100],
        'can_edit': _can_manage(request.user) or _participant(request.user, task),
        'users': User.objects.filter(is_active=True).order_by('first_name', 'username')[:200],
    })


@login_required
@require_POST
def task_progress(request, pk):
    task = get_object_or_404(Task, pk=pk)
    if not (_can_manage(request.user) or _participant(request.user, task)):
        messages.error(request, 'اجازه ثبت پیشرفت ندارید.')
        return redirect('tasks:detail', pk=pk)
    try:
        percent = int(request.POST.get('percent', '0'))
    except ValueError:
        percent = 0
    update_progress(task, request.user, percent, request.POST.get('note', ''))
    messages.success(request, f'پیشرفت {task.progress}٪ ثبت شد.')
    return redirect('tasks:detail', pk=pk)


@login_required
@require_POST
def task_status(request, pk):
    task = get_object_or_404(Task, pk=pk)
    if not (_can_manage(request.user) or _participant(request.user, task)):
        messages.error(request, 'اجازه تغییر وضعیت ندارید.')
        return redirect('tasks:detail', pk=pk)
    if change_status(task, request.user, request.POST.get('status', ''),
                     request.POST.get('note', '')) is None:
        messages.error(request, 'وضعیت نامعتبر است.')
    else:
        messages.success(request, 'وضعیت وظیفه به‌روز شد.')
    return redirect('tasks:detail', pk=pk)


@login_required
@require_POST
def task_comment(request, pk):
    task = get_object_or_404(Task, pk=pk)
    if not (_can_manage(request.user) or _participant(request.user, task)):
        messages.error(request, 'اجازه گفت‌وگو ندارید.')
        return redirect('tasks:detail', pk=pk)
    note = request.POST.get('note', '').strip()
    if not note:
        messages.error(request, 'متن گفت‌وگو خالی است.')
    else:
        add_comment(task, request.user, note)
        messages.success(request, 'دیدگاه شما ثبت شد.')
    return redirect('tasks:detail', pk=pk)


@login_required
@require_POST
def from_letter(request, letter_pk):
    """وظیفه از نامه — برای کاربر دارای مجوز ارجاع."""
    from apps.secretariat.models import Letter
    from apps.secretariat.views import visible_letters
    if not (request.user.is_superuser
            or request.user.has_perm('secretariat.refer_letter')):
        messages.error(request, 'مجوز ساخت وظیفه از نامه را ندارید.')
        return redirect('secretariat:letter_detail', pk=letter_pk)
    letter = get_object_or_404(visible_letters(request.user), pk=letter_pk)
    responsible = User.objects.filter(
        pk=request.POST.get('responsible_id') or request.user.pk).first()
    title = request.POST.get('title', '').strip()[:300] or f'پیگیری نامه {letter.number_display}'
    task = create_task(
        title=title,
        description=f'برگرفته از نامه «{letter.subject}»',
        responsible=responsible or request.user,
        due_jalali=letter.deadline,
        priority=letter.priority,
        created_by=request.user,
        source_letter=letter)
    messages.success(request, f'وظیفه «{task.title}» از نامه ساخته شد.')
    return redirect('secretariat:letter_detail', pk=letter_pk)


def _month_days(year: int, month: int) -> int:
    if month <= 6:
        return 31
    if month <= 11:
        return 30
    try:
        jdatetime.date(year, 12, 30)
        return 30
    except ValueError:
        return 29


@login_required
def calendar_month(request):
    """تقویم سازمانی شمسی — جلسات، مهلت وظایف و مهلت نامه‌ها در یک نما."""
    from apps.meetings.models import Meeting
    from apps.secretariat.views import visible_letters

    today = jdatetime.date.today()
    try:
        year = int(request.GET.get('y', today.year))
        month = int(request.GET.get('m', today.month))
    except (TypeError, ValueError):
        year, month = today.year, today.month
    month = min(12, max(1, month))
    year = max(1300, min(1500, year))
    first = jdatetime.date(year, month, 1)
    days = _month_days(year, month)
    last = jdatetime.date(year, month, days)

    meetings = Meeting.objects.filter(jalali_date__gte=first, jalali_date__lte=last)\
        .exclude(status=Meeting.Status.CANCELLED)
    tasks = _visible_tasks(request.user).filter(due_date__gte=first, due_date__lte=last)
    letters = visible_letters(request.user).filter(
        deadline__gte=first, deadline__lte=last)

    by_day: dict[int, list] = {}

    def _add(day, kind, key, title, url):
        by_day.setdefault(day, []).append(
            {'kind': kind, 'key': key, 'title': title, 'url': url})

    for m in meetings:
        _add(m.jalali_date.day, 'جلسه', 'meeting', m.title, f'/meetings/{m.pk}/')
    for t in tasks:
        _add(t.due_date.day, 'وظیفه', 'task', t.title, f'/tasks/{t.pk}/')
    for letter in letters:
        _add(letter.deadline.day, 'مهلت نامه', 'letter', letter.subject[:60],
             f'/letters/{letter.pk}/')

    cells = []
    offset = first.weekday()  # شنبه = ۰
    for _ in range(offset):
        cells.append(None)
    for day in range(1, days + 1):
        cells.append({'day': day, 'is_today': jdatetime.date(year, month, day) == today,
                      'items': by_day.get(day, [])})
    while len(cells) % 7:
        cells.append(None)
    weeks = [cells[i:i + 7] for i in range(0, len(cells), 7)]

    prev_y, prev_m = (year - 1, 12) if month == 1 else (year, month - 1)
    next_y, next_m = (year + 1, 1) if month == 12 else (year, month + 1)
    return render(request, 'tasks/calendar.html', {
        'weeks': weeks, 'weekdays': WEEKDAYS,
        'year': year, 'month': month, 'month_name': MONTHS[month - 1],
        'prev': {'y': prev_y, 'm': prev_m}, 'next': {'y': next_y, 'm': next_m},
        'today': today,
    })
