"""سرویس‌های دبیرخانه: شماره‌گذاری همزان‌امن، ثبت نامه و مرئی‌پذیری داده.

سطوح مرئی‌پذیری از واژگان مجوز سامانه تیکتینگ اقتباس شده‌اند
(own/unit/all — نگاشت کامل در docs/14 بند ۵).
"""
from __future__ import annotations

import jdatetime
from django.db import transaction
from django.db.models import Q
from django.utils import timezone

from .models import Letter, LetterEvent, NumberSequence


def jalali_today() -> jdatetime.date:
    """امروز به تقویم شمسی."""
    return jdatetime.date.today()


def current_jalali_year() -> int:
    return jalali_today().year


def next_serial(office, letter_type: str, jalali_year: int) -> int:
    """شماره سریال بعدی — همزان‌امن (select_for_update) و بدون پرش.

    همسان الگوی ticket_number_seq در سامانه تیکتینگ.
    """
    with transaction.atomic():
        seq, created = NumberSequence.objects.get_or_create(
            office=office, letter_type=letter_type, jalali_year=jalali_year,
            defaults={'last_serial': 0})
        if not created:
            seq = NumberSequence.objects.select_for_update().get(pk=seq.pk)
        seq.last_serial += 1
        seq.save(update_fields=['last_serial'])
        return seq.last_serial


def register_letter(
    office, letter_type: str, subject: str, *,
    created_by=None, jalali_year: int | None = None, status: str | None = None,
    **fields,
) -> Letter:
    """ثبت نامه با شماره‌گذاری اتمیک + رویداد «ثبت»."""
    year = jalali_year or current_jalali_year()
    received_at = fields.pop('received_at', None)
    if received_at is None and letter_type == Letter.LetterType.INCOMING:
        received_at = timezone.now()
    if status is None:
        status = (
            Letter.Status.DRAFT
            if letter_type == Letter.LetterType.OUTGOING
            else Letter.Status.REGISTERED
        )
    with transaction.atomic():
        serial = next_serial(office, letter_type, year)
        letter = Letter.objects.create(
            office=office, letter_type=letter_type, jalali_year=year, serial=serial,
            subject=subject, status=status, created_by=created_by,
            received_at=received_at, **fields)
        LetterEvent.objects.create(
            letter=letter, actor=created_by, action=LetterEvent.Action.REGISTERED,
            to_status=letter.status, note=f'ثبت با شماره {letter.number_display}')
    return letter


def department_subtree_ids(user) -> list[int]:
    """شناسه واحد سازمانی کاربر و همه زیرمجموعه‌ها (MPTT) — همسان org_unit_subtree_ids."""
    employee = getattr(user, 'employee_profile', None)
    if employee is None or employee.department_id is None:
        return []
    return list(
        employee.department.get_descendants(include_self=True).values_list('id', flat=True))


def apply_confidentiality(qs, user):
    """نامه‌های محرمانه/سری فقط با مجوز view_confidential_letter دیده می‌شوند."""
    if user.is_superuser or user.has_perm('secretariat.view_confidential_letter'):
        return qs
    return qs.exclude(confidentiality__in=[
        Letter.Confidentiality.CONFIDENTIAL, Letter.Confidentiality.SECRET])


def visible_letters(user):
    """مجموعه نامه‌های مرئی برای کاربر بر اساس سطح مجوز (all > unit > own)."""
    qs = Letter.objects.all()  # مدیر SoftDelete حذف‌شده‌ها را نمی‌دهد
    if user.is_superuser or user.has_perm('secretariat.view_all_letter'):
        return apply_confidentiality(qs, user)

    own = (Q(created_by=user) | Q(referrals__to_user=user) | Q(copies__to_user=user)
           | Q(to_user=user))
    # جانشینی فعال: نامه‌های تفویض‌کننده نیز در سطح «خود» دیده می‌شود
    from apps.core.models import Delegation
    delegator_ids = Delegation.active_delegators(user)
    if delegator_ids:
        own |= (Q(created_by_id__in=delegator_ids)
                | Q(referrals__to_user_id__in=delegator_ids)
                | Q(copies__to_user_id__in=delegator_ids))
    if user.has_perm('secretariat.view_unit_letter'):
        dept_ids = department_subtree_ids(user)
        qs = qs.filter(
            own
            | Q(referrals__to_department_id__in=dept_ids)
            | Q(office__department_id__in=dept_ids)
        ).distinct()
    elif user.has_perm('secretariat.view_own_letter'):
        qs = qs.filter(own).distinct()
    else:
        qs = qs.none()
    return apply_confidentiality(qs, user)


# ════════════════════════════════════════════════════════════════
# سرویس‌های لایه حرفه‌ای — رفتار مطابق سامانه‌های مرجع
# ════════════════════════════════════════════════════════════════
from django.db.models import Q
from django.utils import timezone


# ── پرونده (کلاسور) ──────────────────────────────────────────
def next_case_number() -> str:
    """شماره پرونده: پ-{سال}-{ردیف ۴ رقمی} — طبق الگوی رایج دبیرخانه."""
    from apps.secretariat.models import CaseFile
    year = current_jalali_year()
    prefix = f'پ-{year}-'
    last = (CaseFile.objects.filter(number__startswith=prefix)
            .order_by('-number').values_list('number', flat=True).first())
    seq = 1
    if last:
        try:
            seq = int(last.split('-')[-1]) + 1
        except ValueError:
            seq = 1
    return f'{prefix}{seq:04d}'


def open_case_file(title: str, user=None, **kwargs):
    from apps.secretariat.models import CaseFile, CaseFileEvent
    cf = CaseFile.objects.create(
        number=next_case_number(), title=title, created_by=user,
        opened_at=kwargs.get('opened_at') or jalali_today(), **{
            k: v for k, v in kwargs.items() if k != 'opened_at'})
    CaseFileEvent.objects.create(
        case_file=cf, actor=user, action=CaseFileEvent.ACTION_OPEN)
    return cf


def add_letter_to_case_file(letter, case_file, user=None):
    from apps.secretariat.models import CaseFile, CaseFileEvent
    if case_file.status != CaseFile.Status.OPEN:
        raise ValueError('پرونده بسته است — ابتدا آن را بازگشایی کنید.')
    letter.case_file = case_file
    letter.save(update_fields=['case_file'])
    CaseFileEvent.objects.create(
        case_file=case_file, actor=user, action=CaseFileEvent.ACTION_ADD_LETTER,
        note=f'نامه {letter.number_display}')
    return letter


def set_case_file_status(case_file, status, user=None, note=''):
    from apps.secretariat.models import CaseFile, CaseFileEvent
    case_file.status = status
    if status == CaseFile.Status.CLOSED:
        case_file.closed_at = jalali_today()
    elif status == CaseFile.Status.OPEN:
        case_file.closed_at = None
    case_file.save()
    action = {
        CaseFile.Status.CLOSED: CaseFileEvent.ACTION_CLOSE,
        CaseFile.Status.OPEN: CaseFileEvent.ACTION_REOPEN,
    }.get(status)
    if action:
        CaseFileEvent.objects.create(case_file=case_file, actor=user, action=action, note=note)
    return case_file


# ── دفتر اندیکاتور ───────────────────────────────────────────
def create_register_entry(letter, user=None, **fields):
    """ردیف دفتر اندیکاتور — snapshot رسمی ثبت؛ شماره ردیف سالانه per-office."""
    from django.db.models import Max
    from apps.secretariat.models import RegisterEntry
    if hasattr(letter, 'register_entry'):
        return letter.register_entry
    last_row = (RegisterEntry.objects
                .filter(office=letter.office, jalali_year=letter.jalali_year)
                .aggregate(m=Max('row_number'))['m'] or 0)
    prev = (RegisterEntry.objects
            .filter(office=letter.office, jalali_year=letter.jalali_year,
                    row_number=last_row).first())
    copies_to = '، '.join(
        str(c.to_user or c.to_department or '') for c in letter.copies.all())
    entry = RegisterEntry.objects.create(
        office=letter.office, jalali_year=letter.jalali_year,
        row_number=last_row + 1, letter=letter,
        summary=fields.get('summary') or letter.summary or letter.subject,
        attachment_count=fields.get('attachment_count', letter.attachments.count()),
        incoming_ref=fields.get('incoming_ref', ''),
        referred_to=fields.get('referred_to', ''),
        expected_return_date=fields.get('expected_return_date'),
        issue_date=fields.get('issue_date'),
        copy_to=fields.get('copy_to', copies_to),
        prev_row=prev,
        file_number=fields.get('file_number', letter.case_file.number if letter.case_file_id else ''),
        created_by=user)
    if prev is not None:
        RegisterEntry.objects.filter(pk=prev.pk).update(prev_row=None)
        entry.prev_row = prev
        entry.save(update_fields=['prev_row'])
    return entry


# ── کنترل پیش از ثبت ─────────────────────────────────────────
def register_check(letter, user, result, **flags) -> 'RegisterCheck':
    """گیت رسمی ثبت — fail یعنی نامه به پیش‌نویس برمی‌گردد."""
    from django.utils import timezone as tz
    from apps.secretariat.models import Letter, LetterEvent, RegisterCheck
    obj, _ = RegisterCheck.objects.update_or_create(
        letter=letter,
        defaults={'checked_by': user, 'checked_at': tz.now(), 'result': result, **flags})
    LetterEvent.objects.create(
        letter=letter, actor=user, action=LetterEvent.Action.CHECKED,
        note=f'کنترل ثبت: {obj.get_result_display()}')
    if result == RegisterCheck.Result.FAIL:
        letter.status = Letter.Status.DRAFT
        letter.save(update_fields=['status'])
    return obj


# ── قفل و نسخه‌ها ────────────────────────────────────────────
def is_locked(letter) -> bool:
    return letter.locked_at is not None


def lock_letter(letter, user):
    from django.utils import timezone as tz
    letter.locked_at = tz.now()
    letter.locked_by = user
    letter.save(update_fields=['locked_at', 'locked_by'])
    return letter


def unlock_letter(letter, user, reason=''):
    from apps.secretariat.models import LetterEvent
    letter.locked_at = None
    letter.locked_by = None
    letter.save(update_fields=['locked_at', 'locked_by'])
    from apps.secretariat.models import LetterEvent as _LE
    _LE.objects.create(
        letter=letter, actor=user, action=_LE.Action.EDITED, note=f'باز کردن قفل: {reason}')
    return letter


def new_revision(letter, user, reason=''):
    """نسخه جدید از متن نامه — خروج از وضعیت تأییدشده و نیاز به امضای مجدد."""
    from apps.secretariat.models import Letter, LetterEvent, LetterRevision
    letter.current_revision += 1
    letter.locked_at = None
    letter.locked_by = None
    if letter.status in (Letter.Status.SIGNED, Letter.Status.SENT):
        letter.status = Letter.Status.IN_REVIEW
    letter.save()
    rev = LetterRevision.objects.create(
        letter=letter, revision_no=letter.current_revision,
        subject=letter.subject, body=letter.body, changed_by=user, reason=reason)
    from apps.secretariat.models import LetterEvent as _LE2
    _LE2.objects.create(
        letter=letter, actor=user, action=_LE2.Action.EDITED,
        note=f'نسخه {rev.revision_no} — {reason}')
    return rev


def snapshot_revision(letter, user, reason=''):
    """ثبت snapshot نسخه جاری (بدون افزایش شماره) — مبنای امضای نسخه مشخص."""
    from apps.secretariat.models import LetterRevision
    rev, _ = LetterRevision.objects.get_or_create(
        letter=letter, revision_no=letter.current_revision,
        defaults={'subject': letter.subject, 'body': letter.body,
                  'changed_by': user, 'reason': reason})
    return rev


# ── امضای چندنفره ────────────────────────────────────────────
def create_signature_request(letter, signers, mode, user):
    """signers: لیست dict(user=, position=, label=) — ترتیب لیست = ترتیب امضا."""
    from apps.secretariat.models import Letter, SignatureRequest, SignatureStep
    req = SignatureRequest.objects.create(
        letter=letter, mode=mode, created_by=user,
        status=SignatureRequest.Status.PENDING)
    for i, s in enumerate(signers, start=1):
        SignatureStep.objects.create(
            request=req, order=i,
            signer_user=s.get('user'), signer_position=s.get('position'),
            signer_label=s.get('label', ''))
    letter.status = Letter.Status.IN_REVIEW
    letter.save(update_fields=['status'])
    return req


def _position_held_by(user):
    from apps.organization.models import Position
    return list(Position.objects.filter(holder=user, is_active=True))


def user_signature_steps(user):
    """گام‌های امضای در انتظار کاربر (شخصی یا جایگاه‌هایش)."""
    from apps.secretariat.models import SignatureStep
    return SignatureStep.objects.filter(
        Q(signer_user=user) | Q(signer_position__holder=user),
        status=SignatureStep.Status.PENDING).select_related('request__letter')


def decide_signature_step(step, user, decision, comment=''):
    """تصمیم روی گام امضا — تکمیل همه تأییدها = امضای نهایی و قفل نامه."""
    from django.utils import timezone as tz
    from apps.secretariat.models import (
        Letter, SignatureRequest, SignatureStep, LetterEvent)
    step.status = decision
    step.comment = comment
    step.signed_at = tz.now()
    step.signed_revision = snapshot_revision(step.request.letter, user)
    step.save()
    req = step.request
    letter = req.letter
    LetterEvent.objects.create(
        letter=letter, actor=user,
        action=(LetterEvent.Action.APPROVED
                if decision == SignatureStep.Status.APPROVED
                else LetterEvent.Action.REJECTED),
        note=f'گام امضا {step.order}: {step.get_status_display()} — {comment}')
    if decision in (SignatureStep.Status.REJECTED, SignatureStep.Status.CHANGES):
        req.status = SignatureRequest.Status.REJECTED
        req.save(update_fields=['status'])
        letter.status = Letter.Status.DRAFT if decision == SignatureStep.Status.CHANGES else Letter.Status.IN_REVIEW
        letter.save(update_fields=['status'])
        return req
    # ترتیبی: نوبت بعدی را آزاد کن؛ موازی: همه آزادند
    if req.mode == SignatureRequest.Mode.SEQUENTIAL:
        nxt = req.steps.filter(status=SignatureStep.Status.PENDING).order_by('order').first()
    else:
        nxt = None
    if req.steps.exclude(status=SignatureStep.Status.APPROVED).count() == 0:
        req.status = SignatureRequest.Status.COMPLETED
        req.save(update_fields=['status'])
        letter.status = Letter.Status.SIGNED
        letter.save(update_fields=['status'])
        lock_letter(letter, user)
        LetterEvent.objects.create(
            letter=letter, actor=user, action=LetterEvent.Action.APPROVED,
            note=f'امضای چندنفره تکمیل شد ({req.approved_count} امضا) — نامه قفل شد.')
    return req


# ── رونوشت اقدامی، رسید رؤیت، پاراف ─────────────────────────
def copy_mark_done(copy, user):
    from django.utils import timezone as tz
    copy.done_at = tz.now()
    copy.done_by = user
    copy.save(update_fields=['done_at', 'done_by'])
    return copy


def record_read_receipt(letter, user, source='direct'):
    from apps.secretariat.models import ReadReceipt
    obj, _ = ReadReceipt.objects.get_or_create(
        letter=letter, user=user, source=source)
    return obj


def add_paraph(letter, user, text):
    from apps.secretariat.models import Paraph
    return Paraph.objects.create(letter=letter, user=user, text=text)


# ── SLA و یادآوری ────────────────────────────────────────────
def sla_hours_for(letter):
    from apps.secretariat.models import SLARule
    rule = (SLARule.objects.filter(
        is_active=True, letter_type=letter.letter_type, priority=letter.priority)
        .order_by('-priority').first()
        or SLARule.objects.filter(is_active=True, letter_type=letter.letter_type, priority='')
        .first()
        or SLARule.objects.filter(is_active=True, letter_type='', priority='').first())
    return rule.action_hours if rule else None


def apply_sla(referral):
    """محاسبه مهلت SLA ارجاع از قاعده — فقط وقتی مهلت دستی تعیین نشده."""
    from datetime import timedelta
    from django.utils import timezone as tz
    hours = sla_hours_for(referral.letter)
    if hours and referral.sla_due_at is None:
        referral.sla_due_at = referral.created_at + timedelta(hours=hours)
        referral.save(update_fields=['sla_due_at'])
    return referral


def check_sla_overdue():
    """موتور یادآوری — اعلان قبل از موعد و علامت‌گذاری تأخیر. خروجی: (یادآوری‌شده, معطل‌شده)."""
    from datetime import timedelta
    from django.utils import timezone as tz
    from apps.core.models import Notification
    from apps.secretariat.models import Referral
    warned, overdue = [], []
    now = tz.now()
    for ref in Referral.objects.filter(
            status=Referral.Status.PENDING, sla_due_at__isnull=False).select_related('letter', 'to_user'):
        if ref.sla_due_at < now and ref.overdue_at is None:
            ref.overdue_at = now
            ref.save(update_fields=['overdue_at'])
            overdue.append(ref)
            target = ref.to_user or ref.from_user
            if target:
                Notification.objects.create(
                    user=target,
                    title='عبور از مهلت اقدام',
                    body=f'نامه {ref.letter.number_display} از مهلت مقرر گذشت.',
                    url=f'/letters/{ref.letter_id}/')
        elif (ref.warned_at is None and ref.sla_due_at - now < timedelta(hours=24)
              and ref.sla_due_at >= now):
            ref.warned_at = now
            ref.save(update_fields=['warned_at'])
            warned.append(ref)
            if ref.to_user:
                Notification.objects.create(
                    user=ref.to_user,
                    title='یادآوری مهلت اقدام',
                    body=f'نامه {ref.letter.number_display} تا مهلت مقرر فرصت کمی دارد.',
                    url=f'/letters/{ref.letter_id}/')
    return warned, overdue


# ── رزرو شماره ───────────────────────────────────────────────
def reserve_number(office, letter_type, user, note=''):
    from apps.secretariat.models import NumberReservation
    year = current_jalali_year()
    serial = next_serial(office, letter_type, year)
    return NumberReservation.objects.create(
        office=office, letter_type=letter_type, jalali_year=year,
        serial=serial, reserved_by=user, note=note)


# ── ثبت مقابل (زنجیره وارده ↔ صادره) ────────────────────────
def pair_answer(answer_letter, original_letter, user=None):
    """ثبت مقابل: «answer_letter» پاسخ «original_letter» است (زنجیره وارده↔صادره)."""
    from apps.secretariat.models import LetterRelation
    obj, _ = LetterRelation.objects.get_or_create(
        letter=answer_letter, related=original_letter,
        kind=LetterRelation.Kind.ANSWER)
    return obj


# ── جست‌وجوی سراسری (مجوز-محور) ──────────────────────────────
def search_all(user, query: str, limit: int = 20) -> dict:
    """جست‌وجو روی نامه/پرونده/طرف مکاتبه — همیشه در محدوده مجوز کاربر."""
    from apps.secretariat.models import CaseFile, Correspondent, Letter
    q = query.strip()
    results = {'letters': [], 'case_files': [], 'correspondents': []}
    if not q:
        return results
    letters = visible_letters(user).filter(
        Q(subject__icontains=q) | Q(body__icontains=q) | Q(summary__icontains=q)
        | Q(sender_name__icontains=q) | Q(sender_org__icontains=q)
        | Q(keywords__title__icontains=q)).distinct()[:limit]
    results['letters'] = list(letters)
    results['case_files'] = list(
        CaseFile.objects.filter(
            Q(title__icontains=q) | Q(number__icontains=q) | Q(description__icontains=q))[:limit])
    results['correspondents'] = list(
        Correspondent.objects.filter(
            Q(title__icontains=q) | Q(national_id__icontains=q))[:limit])
    return results


# ── ارجاع به جایگاه — کارتابل یکپارچه ───────────────────────
def visible_referrals(user):
    """ارجاع‌های قابل مشاهده توسط کاربر: شخصی + جایگاه‌های فعالش + تفویض‌ها."""
    from apps.secretariat.models import Referral
    from apps.organization.models import Position
    position_ids = list(Position.objects.filter(
        holder=user, is_active=True).values_list('id', flat=True))
    q = Q(to_user=user) | Q(target_position_id__in=position_ids)
    return Referral.objects.filter(q).exclude(
        status__in=[Referral.Status.CANCELLED])


# ════════════════════════════════════════════════════════════════
# تکمیل دبیرخانه — چاپ گروهی، دورنویس، بایگانی، تفویض امضا
# ════════════════════════════════════════════════════════════════

# ── دورنویس (فاکس) ──────────────────────────────────────────
def fax_to_letter(fax, user=None):
    """تبدیل رکورد فاکس به نامه — مطابق رفتار «تبدیل مستقیم فاکس به نامه»."""
    from apps.secretariat.models import FaxLog, Letter, Office
    if fax.letter_id:
        return fax.letter
    office = Office.objects.filter(is_active=True).first()
    letter = register_letter(
        office, Letter.LetterType.INCOMING,
        f'فاکس {fax.get_direction_display()} — {fax.correspondent or fax.fax_number}',
        created_by=user or fax.created_by)
    letter.body = fax.note
    letter.sender_name = fax.correspondent
    letter.delivery_method = Letter.DeliveryMethod.FAX
    letter.save()
    fax.letter = letter
    fax.save(update_fields=['letter'])
    return letter


# ── بایگانی شخصی ─────────────────────────────────────────────
def personal_archive_toggle(user, letter):
    from apps.secretariat.models import PersonalArchive
    obj = PersonalArchive.objects.filter(user=user, letter=letter).first()
    if obj:
        obj.delete()
        return None
    return PersonalArchive.objects.create(user=user, letter=letter)


# ── بازگشایی نامه بایگانی‌شده ────────────────────────────────
def reopen_letter(letter, user, note=''):
    """به‌گردش‌انداختن مجدد نامه بایگانی‌شده — مطابق رفتار آرپا/دیدگاه."""
    from apps.secretariat.models import Letter, LetterEvent
    old = letter.status
    letter.status = Letter.Status.IN_PROGRESS
    letter.save(update_fields=['status'])
    LetterEvent.objects.create(
        letter=letter, actor=user, action=LetterEvent.Action.STATUS_CHANGED,
        from_status=old, to_status=letter.status,
        note=f'بازگشایی از بایگانی و گردش مجدد — {note}')
    return letter


# ── ارجاع گروهی ──────────────────────────────────────────────
def refer_group(letter, users, from_user, note='', deadline=None, parent=None,
                attach_relations=False):
    """ارجاع هم‌زمان به چند نفر (موازی) — هرکدام یک ردیف ارجاع مستقل می‌گیرند."""
    from apps.secretariat.models import Referral
    refs = []
    for u in users:
        refs.append(Referral.objects.create(
            letter=letter, from_user=from_user, to_user=u,
            parent=parent, note=note, deadline=deadline))
    if attach_relations:
        # ارسال پیرو/عطف‌های نامه همراه ارجاع — در رویدادنامه ثبت می‌شود
        from apps.secretariat.models import LetterEvent
        rels = letter.outgoing_relations.select_related('related')[:10]
        desc = '، '.join(f'{r.get_kind_display()} {r.related.number_display}' for r in rels)
        if desc:
            LetterEvent.objects.create(
                letter=letter, actor=from_user, action=LetterEvent.Action.REFERRED,
                note=f'نامه‌های مرتبط همراه ارجاع: {desc}')
    apply_sla(refs[0]) if refs else None
    return refs


# ── تفویض امضا (امضا از طرف) ────────────────────────────────
def can_sign_for(user, step) -> bool:
    """آیا user می‌تواند این گام را امضا کند؟ (شخصی یا با تفویض فعال)"""
    if step.signer_user_id == user.pk:
        return True
    if step.signer_position_id and step.signer_position.holder_id == user.pk:
        return True
    if step.signer_user_id:
        from apps.core.models import Delegation
        from django.utils import timezone as tz
        today = tz.now().date()
        return Delegation.objects.filter(
            from_user=step.signer_user, to_user=user, is_active=True,
            start_date__lte=today, end_date__gte=today).exists()
    return False


# ── چاپ گروهی و نسخه‌گذاری ──────────────────────────────────
COPY_LABELS = (
    'نسخه ۱ — گیرنده اصلی',
    'نسخه ۲ — بایگانی کل',
    'نسخه ۳ — بایگانی واحد ذی‌نفع',
)


def print_copy_labels(letter):
    """برچسب نسخه‌های چاپ — حداقل ۳ نسخه + یک نسخه به‌ازای هر رونوشت."""
    labels = list(COPY_LABELS)
    for i, c in enumerate(letter.copies.all(), start=4):
        target = c.to_user.get_full_name() if c.to_user_id else c.to_department
        labels.append(f'نسخه {i} — رونوشت: {target}')
    return labels


# ── خروجی Word (HTML سازگار با MS Word) ─────────────────────
def letter_word_html(letter) -> str:
    """خروجی Word نامه — با سربرگ سازمان (رفتار «الگوهای Word» سامانه‌های مرجع)."""
    from html import escape
    from apps.core.models import OrgSettings
    org = OrgSettings.load()
    copies = ''.join(
        f'<p>{escape(str(c.to_user or c.to_department or ""))} — {c.get_kind_display()}</p>'
        for c in letter.copies.all())
    relations = ''.join(
        f'<p>{r.get_kind_display()}: {escape(r.related.number_display)} — {escape(r.related.subject)}</p>'
        for r in letter.outgoing_relations.select_related('related'))
    return f'''<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word" dir="rtl">
<head><meta charset="utf-8"><title>{escape(letter.number_display)}</title></head>
<body>
<h2 style="text-align:center">{escape(org.org_name)}</h2>
<p>شماره: {escape(letter.number_display)} — تاریخ: {escape(str(letter.letter_date or ""))}</p>
<p>موضوع: {escape(letter.subject)}</p>
<hr>
<p>{escape(letter.body).replace(chr(10), "<br>")}</p>
<hr>
{'<h4>رونوشت‌ها</h4>' + copies if copies else ''}
{'<h4>نامه‌های مرتبط</h4>' + relations if relations else ''}
</body></html>'''
