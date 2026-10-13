"""نماهای دبیرخانه و کارتابل — فاز ۱ (رابط مصوب «آرام و شفاف»)."""
from __future__ import annotations

import io
from datetime import date, timedelta
from urllib.parse import quote

import jdatetime
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.paginator import Paginator
from django.db.models import Q
from django.http import HttpResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_POST
from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill

from apps.core.models import Notification
from apps.core.persian import to_persian_digits
from .forms import (AnswerActionForm, CopyForm, DelegationForm, IncomingLetterForm,
                    OutgoingDraftForm, ReferralActionForm)
from .models import Attachment, Copy, Letter, LetterEvent, Office, Referral
from .services import (apply_confidentiality, current_jalali_year, register_letter,
                       visible_letters)

CLOSED_STATUSES = (Letter.Status.CLOSED, Letter.Status.ARCHIVED)
STEP_INDEX = {
    'draft': 0, 'registered': 1, 'in_review': 1, 'referred': 2,
    'in_progress': 2, 'waiting_user': 2, 'answered': 3,
    'sent': 4, 'closed': 4, 'archived': 4,
}


def deadline_gregorian(letter):
    d = letter.deadline
    if d is None:
        return None
    return d.togregorian() if hasattr(d, 'togregorian') else d


def _notify(user, title, body='', url=''):
    if user is None:
        return
    Notification.objects.create(user=user, title=title, body=body[:300], url=url)


def _form_errors_message(form):
    for field_errors in form.errors.values():
        for error in field_errors:
            return str(error)
    for error in form.non_field_errors():
        return str(error)
    return 'خطا در فرم.'


# ---------- ثبت نامه ----------

@login_required
def register_letter_view(request):
    can_incoming = request.user.is_superuser or request.user.has_perm('secretariat.manage_secretariat')
    incoming = IncomingLetterForm(prefix='in') if can_incoming else None
    outgoing = OutgoingDraftForm(prefix='out')

    if request.method == 'POST':
        which = request.POST.get('form')
        if which == 'incoming':
            if not can_incoming:
                messages.error(request, 'مجوز ثبت نامه وارده ندارید.')
                return redirect('secretariat:register')
            incoming = IncomingLetterForm(request.POST, request.FILES, prefix='in')
            outgoing = OutgoingDraftForm(prefix='out')
            if incoming.is_valid():
                letter = incoming.save(request.user)
                messages.success(request, f'نامه وارده با شماره {letter.number_display} ثبت شد.')
                for name in incoming.invalid_attachments:
                    messages.warning(request, f'پیوست «{name}» به دلیل فرمت/حجم پذیرفته نشد.')
                return redirect('secretariat:letter_detail', pk=letter.pk)
        elif which == 'outgoing':
            outgoing = OutgoingDraftForm(request.POST, prefix='out')
            incoming = IncomingLetterForm(prefix='in') if can_incoming else None
            if outgoing.is_valid():
                letter = outgoing.save(request.user)
                messages.success(request, f'پیش‌نویس صادره با شماره {letter.number_display} ایجاد شد.')
                return redirect('secretariat:letter_detail', pk=letter.pk)
    return render(request, 'secretariat/register.html', {
        'incoming_form': incoming,
        'outgoing_form': outgoing,
        'can_incoming': can_incoming,
    })


# ---------- دفترچه‌ها ----------

@login_required
def letter_list(request):
    qs = visible_letters(request.user).select_related('office', 'created_by')
    base_ids = qs.values_list('pk', flat=True)

    letter_type = request.GET.get('type', '')
    if letter_type in dict(Letter.LetterType.choices):
        qs = qs.filter(letter_type=letter_type)

    q = request.GET.get('q', '').strip()
    if q:
        qs = qs.filter(
            Q(subject__icontains=q) | Q(summary__icontains=q)
            | Q(sender_name__icontains=q) | Q(sender_org__icontains=q))

    status_f = request.GET.get('status', '')
    if status_f in dict(Letter.Status.choices):
        qs = qs.filter(status=status_f)
    priority_f = request.GET.get('priority', '')
    if priority_f in dict(Letter.Priority.choices):
        qs = qs.filter(priority=priority_f)
    conf_f = request.GET.get('conf', '')
    if conf_f in dict(Letter.Confidentiality.choices):
        qs = qs.filter(confidentiality=conf_f)
    office_f = request.GET.get('office', '')
    if office_f.isdigit():
        qs = qs.filter(office_id=int(office_f))

    # شمارش زبانه‌ها روی نتیجه جست‌وجو (بدون فیلتر نوع)
    counted = visible_letters(request.user)
    if q:
        counted = counted.filter(
            Q(subject__icontains=q) | Q(summary__icontains=q)
            | Q(sender_name__icontains=q) | Q(sender_org__icontains=q))
    counts = {
        'all': counted.count(),
        'incoming': counted.filter(letter_type='incoming').count(),
        'outgoing': counted.filter(letter_type='outgoing').count(),
        'internal': counted.filter(letter_type='internal').count(),
    }

    if request.GET.get('export') == 'xlsx':
        return _export_xlsx(qs.order_by('-jalali_year', '-serial')[:5000])

    paginator = Paginator(qs.order_by('-jalali_year', '-serial'), 20)
    page = paginator.get_page(request.GET.get('page'))
    params = request.GET.copy()
    params.pop('page', None)
    return render(request, 'secretariat/letter_list.html', {
        'page_obj': page,
        'letters': page.object_list,
        'counts': counts,
        'query_base': params.urlencode(),
        'offices': Office.objects.filter(is_active=True),
        'status_choices': Letter.Status.choices,
        'priority_choices': Letter.Priority.choices,
        'conf_choices': Letter.Confidentiality.choices,
        'cur': {'type': letter_type, 'q': q, 'status': status_f, 'priority': priority_f,
                'conf': conf_f, 'office': office_f},
    })


def _export_xlsx(letters):
    wb = Workbook()
    ws = wb.active
    ws.title = 'دفتر نامه'
    ws.sheet_view.rightToLeft = True
    headers = ['شماره', 'نوع', 'موضوع', 'فرستنده', 'تاریخ نامه', 'وضعیت', 'فوریت', 'محرمانگی', 'مهلت', 'دبیرخانه']
    header_fill = PatternFill('solid', fgColor='16365F')
    for col, title in enumerate(headers, 1):
        cell = ws.cell(row=1, column=col, value=title)
        cell.font = Font(bold=True, color='FFFFFF', name='Vazirmatn')
        cell.fill = header_fill
        cell.alignment = Alignment(horizontal='center')
    type_labels = dict(Letter.LetterType.choices)
    for row, letter in enumerate(letters, 2):
        j_letter_date = letter.letter_date
        values = [
            letter.number_display,
            type_labels.get(letter.letter_type, ''),
            letter.subject,
            letter.sender_org or letter.sender_name or '—',
            to_persian_digits(j_letter_date.strftime('%Y/%m/%d')) if j_letter_date else '',
            letter.get_status_display(),
            letter.get_priority_display(),
            letter.get_confidentiality_display(),
            to_persian_digits(letter.deadline.strftime('%Y/%m/%d')) if letter.deadline else '',
            letter.office.name,
        ]
        for col, value in enumerate(values, 1):
            ws.cell(row=row, column=col, value=value)
    widths = [16, 10, 46, 24, 14, 14, 10, 12, 14, 18]
    for col, width in enumerate(widths, 1):
        ws.column_dimensions[ws.cell(row=1, column=col).column_letter].width = width
    buffer = io.BytesIO()
    wb.save(buffer)
    buffer.seek(0)
    filename = quote('daftar-name-ha.xlsx')
    response = HttpResponse(
        buffer.getvalue(),
        content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    response['Content-Disposition'] = f"attachment; filename*=UTF-8''{filename}"
    return response


# ---------- کارت نامه ----------

@login_required
def letter_detail(request, pk):
    letter = get_object_or_404(
        visible_letters(request.user).select_related('office', 'created_by'), pk=pk)
    user = request.user
    can_refer = user.is_superuser or user.has_perm('secretariat.refer_letter')
    can_answer = user.is_superuser or user.has_perm('secretariat.answer_letter')
    can_close = user.is_superuser or user.has_perm('secretariat.close_letter')
    is_open = letter.status not in CLOSED_STATUSES

    referrals = letter.referrals.select_related('to_user', 'to_department', 'from_user', 'parent')
    return render(request, 'secretariat/letter_detail.html', {
        'letter': letter,
        'referrals': referrals,
        'copies': letter.copies.select_related('to_user', 'to_department'),
        'attachments': letter.attachments.all(),
        'events': letter.events.select_related('actor')[:50],
        'step_index': STEP_INDEX.get(letter.status, 0),
        'can_refer': can_refer and is_open,
        'can_answer': can_answer and is_open,
        'can_close': can_close and is_open,
        'referral_form': ReferralActionForm() if can_refer and is_open else None,
        'answer_form': AnswerActionForm() if can_answer and is_open else None,
        'copy_form': CopyForm() if can_refer and is_open else None,
        'deadline_g': deadline_gregorian(letter),
    })


@login_required
@require_POST
def letter_refer(request, pk):
    letter = get_object_or_404(visible_letters(request.user), pk=pk)
    if not (request.user.is_superuser or request.user.has_perm('secretariat.refer_letter')):
        messages.error(request, 'مجوز ارجاع نامه ندارید.')
        return redirect('secretariat:letter_detail', pk=pk)
    if letter.status in CLOSED_STATUSES:
        messages.error(request, 'نامه بسته/بایگانی شده قابل ارجاع نیست.')
        return redirect('secretariat:letter_detail', pk=pk)
    form = ReferralActionForm(request.POST)
    if not form.is_valid():
        messages.error(request, _form_errors_message(form))
        return redirect('secretariat:letter_detail', pk=pk)
    d = form.cleaned_data
    parent_id = request.POST.get('parent')
    parent = letter.referrals.filter(pk=parent_id).first() if parent_id else None
    referral = Referral.objects.create(
        letter=letter, parent=parent, from_user=request.user,
        to_user=d['to_user'], to_department=d['to_department'],
        note=d['note'], deadline=d['deadline'])
    old = letter.status
    letter.status = Letter.Status.REFERRED
    letter.save(update_fields=['status', 'updated_at'])
    LetterEvent.objects.create(
        letter=letter, actor=request.user, action=LetterEvent.Action.REFERRED,
        from_status=old, to_status=letter.status,
        note=(d['note'] or '')[:300])
    url = reverse('secretariat:letter_detail', args=[letter.pk])
    _notify(d['to_user'], f'ارجاع نامه {letter.number_display} — {letter.subject[:60]}',
            d['note'], url)
    messages.success(request, 'ارجاع ثبت شد.')
    return redirect('secretariat:letter_detail', pk=pk)


@login_required
@require_POST
def letter_answer(request, pk):
    letter = get_object_or_404(visible_letters(request.user), pk=pk)
    if not (request.user.is_superuser or request.user.has_perm('secretariat.answer_letter')):
        messages.error(request, 'مجوز ثبت پاسخ ندارید.')
        return redirect('secretariat:letter_detail', pk=pk)
    if letter.status in CLOSED_STATUSES:
        messages.error(request, 'نامه بسته/بایگانی شده قابل پاسخ نیست.')
        return redirect('secretariat:letter_detail', pk=pk)
    form = AnswerActionForm(request.POST)
    if not form.is_valid():
        messages.error(request, _form_errors_message(form))
        return redirect('secretariat:letter_detail', pk=pk)
    note = form.cleaned_data['note']
    old = letter.status
    letter.status = Letter.Status.ANSWERED
    letter.save(update_fields=['status', 'updated_at'])
    LetterEvent.objects.create(
        letter=letter, actor=request.user, action=LetterEvent.Action.ANSWERED,
        from_status=old, to_status=letter.status, note=note[:500])
    Referral.objects.filter(
        letter=letter, to_user=request.user, status=Referral.Status.PENDING,
    ).update(status=Referral.Status.ACTED, acted_at=timezone.now())
    url = reverse('secretariat:letter_detail', args=[letter.pk])
    recipients = {letter.created_by_id}
    recipients.update(letter.referrals.values_list('from_user_id', flat=True))
    from django.contrib.auth import get_user_model
    User = get_user_model()
    for uid in filter(None, recipients):
        if uid != request.user.pk:
            _notify(User.objects.filter(pk=uid).first(),
                    f'پاسخ نامه {letter.number_display} ثبت شد', note, url)
    messages.success(request, 'پاسخ ثبت شد.')
    return redirect('secretariat:letter_detail', pk=pk)


@login_required
@require_POST
def letter_attach(request, pk):
    letter = get_object_or_404(visible_letters(request.user), pk=pk)
    f = request.FILES.get('file')
    if not f:
        messages.error(request, 'فایلی انتخاب نشد.')
        return redirect('secretariat:letter_detail', pk=pk)
    attachment = Attachment(letter=letter, file=f, uploaded_by=request.user)
    try:
        attachment.full_clean()
    except Exception as exc:  # ValidationError
        message = exc.messages[0] if hasattr(exc, 'messages') and exc.messages else 'پیوست پذیرفته نشد.'
        messages.error(request, message)
        return redirect('secretariat:letter_detail', pk=pk)
    attachment.save()
    LetterEvent.objects.create(
        letter=letter, actor=request.user, action=LetterEvent.Action.ATTACHED,
        note=attachment.original_name)
    messages.success(request, f'پیوست «{attachment.original_name}» افزوده شد.')
    return redirect('secretariat:letter_detail', pk=pk)


@login_required
@require_POST
def letter_add_copy(request, pk):
    letter = get_object_or_404(visible_letters(request.user), pk=pk)
    form = CopyForm(request.POST)
    if not form.is_valid():
        messages.error(request, _form_errors_message(form))
        return redirect('secretariat:letter_detail', pk=pk)
    d = form.cleaned_data
    Copy.objects.create(
        letter=letter, to_user=d['to_user'], to_department=d['to_department'], note=d['note'])
    LetterEvent.objects.create(
        letter=letter, actor=request.user, action=LetterEvent.Action.COPIED,
        note=d['note'][:300])
    url = reverse('secretariat:letter_detail', args=[letter.pk])
    _notify(d['to_user'], f'رونوشت نامه {letter.number_display}', d['note'], url)
    messages.success(request, 'رونوشت افزوده شد.')
    return redirect('secretariat:letter_detail', pk=pk)


@login_required
@require_POST
def letter_close(request, pk):
    letter = get_object_or_404(visible_letters(request.user), pk=pk)
    if not (request.user.is_superuser or request.user.has_perm('secretariat.close_letter')):
        messages.error(request, 'مجوز بستن/بایگانی نامه را ندارید.')
        return redirect('secretariat:letter_detail', pk=pk)
    old = letter.status
    letter.status = Letter.Status.ARCHIVED
    letter.save(update_fields=['status', 'updated_at'])
    Referral.objects.filter(letter=letter, status=Referral.Status.PENDING).update(
        status=Referral.Status.CLOSED)
    LetterEvent.objects.create(
        letter=letter, actor=request.user, action=LetterEvent.Action.ARCHIVED,
        from_status=old, to_status=letter.status)
    messages.success(request, 'نامه بایگانی شد.')
    return redirect('secretariat:letter_detail', pk=pk)


# ---------- کارتابل ----------

@login_required
def kartable(request):
    user = request.user
    today = date.today()
    warning_days = today + timedelta(days=3)

    my_referrals_pending = Referral.objects.filter(
        to_user=user, status=Referral.Status.PENDING).select_related('letter', 'from_user', 'letter__office')
    waiting_ids = [r.letter_id for r in my_referrals_pending]
    waiting_letters = list(apply_confidentiality(
        Letter.objects.filter(pk__in=waiting_ids), user).select_related('office'))
    visible_ids = {letter.pk for letter in waiting_letters}
    referral_map = {r.letter_id: r for r in my_referrals_pending if r.letter_id in visible_ids}

    in_progress = Referral.objects.filter(
        to_user=user, status=Referral.Status.ACTED,
    ).exclude(letter__status__in=CLOSED_STATUSES).select_related(
        'letter', 'letter__office').order_by('-acted_at')[:50]

    followups = Referral.objects.filter(
        from_user=user, status=Referral.Status.PENDING,
    ).select_related('letter', 'to_user', 'to_department', 'letter__office').order_by('-created_at')[:50]

    sent = Letter.objects.filter(created_by=user).exclude(
        status__in=CLOSED_STATUSES).select_related('office').order_by('-jalali_year', '-serial')[:50]

    archive = apply_confidentiality(
        Letter.objects.filter(
            Q(created_by=user) | Q(referrals__to_user=user) | Q(copies__to_user=user),
            status__in=CLOSED_STATUSES,
        ).distinct(), user).select_related('office')[:50]

    near = overdue = 0
    for letter in waiting_letters:
        g = deadline_gregorian(letter)
        referral = referral_map.get(letter.pk)
        if referral is not None and referral.deadline is not None:
            rg = (referral.deadline.togregorian()
                  if hasattr(referral.deadline, 'togregorian') else referral.deadline)
            g = min(g, rg) if g else rg
        if g is None:
            continue
        if g < today:
            overdue += 1
        elif g <= warning_days:
            near += 1

    answered_events = LetterEvent.objects.filter(
        letter__created_by=user, action=LetterEvent.Action.ANSWERED).select_related('letter')
    total_answered = answered_events.count()
    on_time = sum(
        1 for event in answered_events
        if deadline_gregorian(event.letter) and event.created_at.date() <= deadline_gregorian(event.letter))
    rate = round(on_time / total_answered * 100) if total_answered else None

    tab = request.GET.get('tab', 'waiting')
    return render(request, 'secretariat/kartable.html', {
        'tab': tab,
        'waiting': waiting_letters,
        'referral_map': referral_map,
        'in_progress': [r.letter for r in in_progress],
        'followups': followups,
        'sent': sent,
        'archive': archive,
        'kpi': {
            'waiting': len(waiting_letters),
            'near': near,
            'overdue': overdue,
            'in_progress': len(in_progress),
            'rate': to_persian_digits(rate) if rate is not None else '—',
        },
    })
