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

    own = Q(created_by=user) | Q(referrals__to_user=user) | Q(copies__to_user=user)
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
