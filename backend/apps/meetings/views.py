"""نماهای جلسات: فهرست، ایجاد، جزئیات، دستور، صورت‌جلسه، امضا و مصوبات."""
from django.contrib import messages
from django.contrib.auth import get_user_model
from django.contrib.auth.decorators import login_required
from django.db.models import Q
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import (AgendaItem, Attendee, Meeting, MinutesSignature,
                     Resolution)
from .services import (convert_resolution_to_letter, finalize_minutes,
                       get_or_create_minutes, notify, notify_attendees,
                       sign_minutes)

User = get_user_model()


def _can_manage(user) -> bool:
    return user.is_superuser or user.has_perm('meetings.manage_meetings')


def _can_edit(user, meeting) -> bool:
    """برگزارکننده، رئیس/دبیر جلسه یا دارنده مجوز مدیریت."""
    if _can_manage(user) or meeting.organizer_id == user.pk:
        return True
    return Attendee.objects.filter(
        meeting=meeting, user=user,
        role__in=(Attendee.Role.CHAIR, Attendee.Role.SECRETARY)).exists()


def _is_attendee(user, meeting) -> bool:
    return Attendee.objects.filter(meeting=meeting, user=user).exists()


def _visible_meetings(user):
    if _can_manage(user):
        return Meeting.objects.all()
    return Meeting.objects.filter(
        Q(organizer=user) | Q(attendees__user=user)).distinct()


@login_required
def meeting_list(request):
    status = request.GET.get('status', '')
    meetings = _visible_meetings(request.user).prefetch_related('attendees')
    if status in Meeting.Status.values:
        meetings = meetings.filter(status=status)
    return render(request, 'meetings/list.html', {
        'meetings': meetings[:100], 'status': status,
        'can_manage': _can_manage(request.user),
    })


@login_required
def meeting_new(request):
    from apps.secretariat.views import _parse_jalali_input
    if request.method == 'POST':
        title = request.POST.get('title', '').strip()[:200]
        date = _parse_jalali_input(request.POST.get('jalali_date', ''))
        if not title or date is None:
            messages.error(request, 'عنوان و تاریخ شمسی معتبر (۱۴۰۵-۰۷-۲۲) الزامی است.')
        else:
            start_time = None
            raw_time = request.POST.get('start_time', '').strip()
            if raw_time:
                from datetime import datetime
                try:
                    start_time = datetime.strptime(raw_time, '%H:%M').time()
                except ValueError:
                    start_time = None
            meeting = Meeting.objects.create(
                title=title, jalali_date=date, organizer=request.user,
                start_time=start_time,
                location=request.POST.get('location', '')[:200],
                description=request.POST.get('description', ''))
            user_ids = request.POST.getlist('attendees')
            for uid in user_ids:
                user = User.objects.filter(pk=uid).first()
                if user and user.pk != request.user.pk:
                    Attendee.objects.get_or_create(meeting=meeting, user=user)
            messages.success(request, f'جلسه «{meeting.title}» ثبت شد.')
            notify_attendees(meeting, f'دعوت به جلسه «{meeting.title}»')
            return redirect('meetings:detail', pk=meeting.pk)
    users = User.objects.filter(is_active=True).order_by('first_name', 'username')[:200]
    return render(request, 'meetings/new.html', {'users': users})


@login_required
def meeting_detail(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    if not (_can_manage(request.user) or _is_attendee(request.user, meeting)
            or meeting.organizer_id == request.user.pk):
        messages.error(request, 'به این جلسه دسترسی ندارید.')
        return redirect('meetings:list')
    minutes = get_or_create_minutes(meeting)
    my_signature = MinutesSignature.objects.filter(
        minutes=minutes, user=request.user).first()
    return render(request, 'meetings/detail.html', {
        'meeting': meeting,
        'attendees': meeting.attendees.select_related('user'),
        'agenda': meeting.agenda.select_related('presenter'),
        'minutes': minutes,
        'signatures': minutes.signatures.select_related('user'),
        'my_signature': my_signature,
        'resolutions': meeting.resolutions.select_related(
            'responsible', 'converted_letter'),
        'can_edit': _can_edit(request.user, meeting),
        'can_manage': _can_manage(request.user),
        'users': User.objects.filter(is_active=True).order_by('first_name', 'username')[:200],
        'is_attendee': _is_attendee(request.user, meeting),
    })


@login_required
@require_POST
def meeting_status(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه تغییر وضعیت جلسه را ندارید.')
        return redirect('meetings:detail', pk=pk)
    new_status = request.POST.get('status')
    if new_status in (Meeting.Status.HELD, Meeting.Status.CANCELLED,
                      Meeting.Status.SCHEDULED):
        meeting.status = new_status
        meeting.save(update_fields=['status', 'updated_at'])
        messages.success(request, f'وضعیت جلسه به «{meeting.get_status_display()}» تغییر کرد.')
        notify_attendees(meeting, f'جلسه «{meeting.title}» — {meeting.get_status_display()}')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def attendee_add(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه افزودن دعوت‌شده ندارید.')
        return redirect('meetings:detail', pk=pk)
    user = User.objects.filter(pk=request.POST.get('user_id')).first()
    role = request.POST.get('role', Attendee.Role.MEMBER)
    if user is None or role not in Attendee.Role.values:
        messages.error(request, 'کاربر یا نقش نامعتبر است.')
        return redirect('meetings:detail', pk=pk)
    _, created = Attendee.objects.get_or_create(
        meeting=meeting, user=user, defaults={'role': role})
    if created:
        notify(user, f'دعوت به جلسه «{meeting.title}»', meeting.description[:200],
               f'/meetings/{meeting.pk}/')
        messages.success(request, f'{user.username} به جلسه افزوده شد.')
    else:
        messages.info(request, 'این کاربر پیش‌تر دعوت شده بود.')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def attendee_status(request, pk, attendee_pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    attendee = get_object_or_404(Attendee, pk=attendee_pk, meeting=meeting)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه ثبت حضور و غیاب ندارید.')
        return redirect('meetings:detail', pk=pk)
    value = request.POST.get('attendance')
    if value in Attendee.Attendance.values:
        attendee.attendance = value
        attendee.save(update_fields=['attendance', 'updated_at'])
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def agenda_add(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه افزودن دستور جلسه ندارید.')
        return redirect('meetings:detail', pk=pk)
    title = request.POST.get('title', '').strip()[:300]
    if not title:
        messages.error(request, 'عنوان بند دستور الزامی است.')
        return redirect('meetings:detail', pk=pk)
    last_order = meeting.agenda.count()
    AgendaItem.objects.create(
        meeting=meeting, order=last_order + 1, title=title,
        description=request.POST.get('description', ''))
    messages.success(request, 'بند دستور افزوده شد.')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def minutes_save(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'فقط برگزارکننده/رئیس/دبیر جلسه صورت‌جلسه را ویرایش می‌کند.')
        return redirect('meetings:detail', pk=pk)
    minutes = get_or_create_minutes(meeting)
    if minutes.is_final:
        messages.error(request, 'صورت‌جلسه نهایی شده و قابل ویرایش نیست.')
        return redirect('meetings:detail', pk=pk)
    minutes.body = request.POST.get('body', '')
    minutes.save(update_fields=['body', 'updated_at'])
    messages.success(request, 'صورت‌جلسه ذخیره شد.')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def minutes_sign(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    attendee = Attendee.objects.filter(meeting=meeting, user=request.user).first()
    if attendee is None and not _can_manage(request.user):
        messages.error(request, 'فقط دعوت‌شدگان صورت‌جلسه را امضا می‌کنند.')
        return redirect('meetings:detail', pk=pk)
    minutes = get_or_create_minutes(meeting)
    if not minutes.body.strip():
        messages.error(request, 'صورت‌جلسه هنوز متنی ندارد.')
        return redirect('meetings:detail', pk=pk)
    position = attendee.get_role_display() if attendee else 'مدیر جلسات'
    sign_minutes(minutes, request.user, position=position)
    messages.success(request, 'امضای شما ثبت شد.')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def minutes_finalize(request, pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه نهایی‌سازی صورت‌جلسه را ندارید.')
        return redirect('meetings:detail', pk=pk)
    minutes = get_or_create_minutes(meeting)
    # دکمه «ذخیره و نهایی‌سازی» متن را همراه می‌فرستد — ابتدا ذخیره شود
    body = request.POST.get('body')
    if body is not None and not minutes.is_final:
        minutes.body = body
        minutes.save(update_fields=['body', 'updated_at'])
    if finalize_minutes(minutes, request.user) is None:
        messages.error(request, 'صورت‌جلسه خالی یا پیش‌تر نهایی شده است.')
    else:
        messages.success(request, 'صورت‌جلسه نهایی شد.')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def resolution_add(request, pk):
    from apps.secretariat.views import _parse_jalali_input
    meeting = get_object_or_404(Meeting, pk=pk)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه ثبت مصوبه ندارید.')
        return redirect('meetings:detail', pk=pk)
    title = request.POST.get('title', '').strip()[:300]
    if not title:
        messages.error(request, 'عنوان مصوبه الزامی است.')
        return redirect('meetings:detail', pk=pk)
    responsible = User.objects.filter(
        pk=request.POST.get('responsible_id') or 0).first()
    due = _parse_jalali_input(request.POST.get('due_date', ''))
    number = (meeting.resolutions.count() or 0) + 1
    Resolution.objects.create(
        meeting=meeting, number=number, title=title,
        description=request.POST.get('description', ''),
        responsible=responsible, due_date=due)
    if responsible:
        notify(responsible, f'مصوبه {number} — «{meeting.title}»',
               title, f'/meetings/{meeting.pk}/')
    messages.success(request, f'مصوبه شماره {number} ثبت شد.')
    return redirect('meetings:detail', pk=pk)


@login_required
@require_POST
def resolution_convert(request, pk, resolution_pk):
    meeting = get_object_or_404(Meeting, pk=pk)
    resolution = get_object_or_404(Resolution, pk=resolution_pk, meeting=meeting)
    if not _can_edit(request.user, meeting):
        messages.error(request, 'اجازه تبدیل مصوبه را ندارید.')
        return redirect('meetings:detail', pk=pk)
    target = request.POST.get('target', 'letter')
    if target == 'letter':
        letter = convert_resolution_to_letter(resolution, request.user)
        if letter is None:
            messages.error(request, 'تبدیل ممکن نشد — دبیرخانه فعال نیست یا پیش‌تر تبدیل شده.')
        else:
            messages.success(request, f'پیش‌نویس نامه صادره «{letter.subject}» ساخته شد.')
    elif target == 'task':
        try:
            from .services import convert_resolution_to_task
            task = convert_resolution_to_task(resolution, request.user)
        except ImportError:
            task = None
        if task is None:
            messages.error(request, 'تبدیل به وظیفه ممکن نشد.')
        else:
            messages.success(request, f'وظیفه «{task.title}» از مصوبه ساخته شد.')
    else:
        messages.error(request, 'مقصد نامعتبر است.')
    return redirect('meetings:detail', pk=pk)
