"""سرویس‌های بایگانی: ساختار پیش‌فرض، کدگذاری، بایگانی سند و چرخه امحا."""
from __future__ import annotations

from django.contrib.contenttypes.models import ContentType
from django.utils import timezone

from .models import ArchiveBox, ArchiveFolder, ArchiveItem, DisposalRequest

DEFAULT_BOX_CODE = 'GEN'
DEFAULT_BOX_TITLE = 'بایگانی عمومی'


def ensure_box(code: str = DEFAULT_BOX_CODE, title: str = DEFAULT_BOX_TITLE) -> ArchiveBox:
    box, _ = ArchiveBox.objects.get_or_create(code=code, defaults={'title': title})
    return box


def next_folder_code(box: ArchiveBox) -> str:
    """کد یکتا به شکل GEN-001 با عبور از کدهای مصرف‌شده."""
    n = box.folders.count() + 1
    while ArchiveFolder.objects.filter(code=f'{box.code}-{n:03d}').exists():
        n += 1
    return f'{box.code}-{n:03d}'


def ensure_folder(box: ArchiveBox, title: str, subject_kind: str = 'general') -> ArchiveFolder:
    folder, _ = ArchiveFolder.objects.get_or_create(
        box=box, title=title,
        defaults={'subject_kind': subject_kind, 'code': next_folder_code(box)})
    return folder


def folder_for_letter(letter) -> ArchiveFolder:
    """پوشه پیش‌فرض نامه: به تفکیک دبیرخانه مبدأ."""
    box = ensure_box()
    title = f'مکاتبات {letter.office.name}' if letter.office_id else 'مکاتبات عمومی'
    return ensure_folder(box, title, ArchiveFolder.SubjectKind.ADMIN)


def letter_reference(letter) -> str:
    prefix = letter.TYPE_PREFIX.get(letter.letter_type, 'د')
    return f'{letter.jalali_year}/{prefix}/{letter.serial:04d}'


def archive_object(obj, folder: ArchiveFolder, user=None, note: str = '') -> tuple[ArchiveItem, bool]:
    """بایگانی یک سند در پوشه؛ برای نامه بسته‌شده وضعیت به «بایگانی» و رویداد ثبت می‌شود."""
    ct = ContentType.objects.get_for_model(obj)
    from apps.secretariat.models import Letter, LetterEvent
    title = getattr(obj, 'subject', '') or str(obj)[:300]
    reference = letter_reference(obj) if isinstance(obj, Letter) else ''
    item, created = ArchiveItem.objects.get_or_create(
        content_type=ct, object_id=obj.pk, folder=folder,
        defaults={'title': title, 'reference': reference, 'archived_by': user, 'note': note})
    if created and isinstance(obj, Letter):
        if obj.status == Letter.Status.CLOSED:
            obj.status = Letter.Status.ARCHIVED
            obj.save(update_fields=['status', 'updated_at'])
        obj.events.create(
            actor=user, action=LetterEvent.Action.ARCHIVED,
            to_status=Letter.Status.ARCHIVED,
            note=note or f'بایگانی در پوشه {folder.code} — {folder.title}')
    return item, created


def items_for(obj):
    """سوابق بایگانی یک سند (برای کارت بایگانی در صفحه سند)."""
    return ArchiveItem.objects.filter(
        content_type=ContentType.objects.get_for_model(obj), object_id=obj.pk,
    ).select_related('folder', 'folder__box')


def request_disposal(folder: ArchiveFolder, user, reason: str) -> DisposalRequest | None:
    if not folder.is_active:
        return None
    if folder.disposals.filter(status=DisposalRequest.Status.PENDING).exists():
        return None
    return DisposalRequest.objects.create(
        folder=folder, reason=reason, requested_by=user)


def decide_disposal(req: DisposalRequest, user, approve: bool, note: str = '') -> DisposalRequest | None:
    if req.status != DisposalRequest.Status.PENDING:
        return None
    req.status = (DisposalRequest.Status.APPROVED if approve
                  else DisposalRequest.Status.REJECTED)
    req.decided_by = user
    req.decided_at = timezone.now()
    req.decision_note = note
    req.save(update_fields=['status', 'decided_by', 'decided_at', 'decision_note', 'updated_at'])
    return req


def execute_disposal(req: DisposalRequest, user=None) -> DisposalRequest | None:
    """اجرای امحا فقط پس از تأیید — پوشه غیرفعال می‌شود (اسناد برای ممیزی می‌مانند)."""
    if req.status != DisposalRequest.Status.APPROVED:
        return None
    req.status = DisposalRequest.Status.EXECUTED
    req.executed_at = timezone.now()
    req.save(update_fields=['status', 'executed_at', 'updated_at'])
    req.folder.is_active = False
    req.folder.save(update_fields=['is_active', 'updated_at'])
    return req
