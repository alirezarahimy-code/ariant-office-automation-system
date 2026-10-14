"""نماهای بایگانی: فهرست زونکن‌ها، پوشه، جست‌وجو، بایگانی نامه و چرخه امحا."""
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.db.models import Count, Q
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import ArchiveBox, ArchiveFolder, ArchiveItem, DisposalRequest
from .services import (archive_object, ensure_folder, folder_for_letter,
                       decide_disposal, execute_disposal, request_disposal)


def _can(user, codename: str) -> bool:
    return user.is_superuser or user.has_perm(f'archive.{codename}')


@login_required
def archive_list(request):
    if not _can(request.user, 'view_archive'):
        messages.error(request, 'مجوز مشاهده بایگانی را ندارید.')
        return redirect('secretariat:kartable')
    q = request.GET.get('q', '').strip()
    results = None
    if q:
        results = ArchiveItem.objects.filter(
            Q(title__icontains=q) | Q(reference__icontains=q)
            | Q(folder__title__icontains=q)).select_related(
            'folder', 'folder__box')[:100]
    boxes = ArchiveBox.objects.filter(is_active=True)
    return render(request, 'archive/list.html', {
        'boxes': boxes, 'q': q, 'results': results,
        'can_manage': _can(request.user, 'manage_archive'),
    })


@login_required
def box_detail(request, pk):
    if not _can(request.user, 'view_archive'):
        messages.error(request, 'مجوز مشاهده بایگانی را ندارید.')
        return redirect('secretariat:kartable')
    box = get_object_or_404(ArchiveBox, pk=pk)
    folders = box.folders.annotate(items_count=Count('items'))
    return render(request, 'archive/box_detail.html', {
        'box': box, 'folders': folders,
        'can_manage': _can(request.user, 'manage_archive'),
    })


@login_required
def folder_detail(request, pk):
    if not _can(request.user, 'view_archive'):
        messages.error(request, 'مجوز مشاهده بایگانی را ندارید.')
        return redirect('secretariat:kartable')
    folder = get_object_or_404(
        ArchiveFolder.objects.select_related('box'), pk=pk)
    items = folder.items.select_related('archived_by')[:200]
    disposals = folder.disposals.select_related('requested_by', 'decided_by')
    pending = disposals.filter(status=DisposalRequest.Status.PENDING).first()
    approved = disposals.filter(status=DisposalRequest.Status.APPROVED).first()
    return render(request, 'archive/folder_detail.html', {
        'folder': folder, 'items': items, 'disposals': disposals,
        'pending': pending, 'approved': approved,
        'can_manage': _can(request.user, 'manage_archive'),
        'can_approve': _can(request.user, 'approve_disposal'),
    })


@login_required
@require_POST
def archive_letter(request, letter_pk):
    """بایگانی نامه بسته‌شده از کارت بایگانی در صفحه نامه."""
    from apps.secretariat.models import Letter
    if not _can(request.user, 'manage_archive'):
        messages.error(request, 'مجوز مدیریت بایگانی را ندارید.')
        return redirect('secretariat:letter_detail', pk=letter_pk)
    letter = get_object_or_404(Letter, pk=letter_pk)
    if letter.status not in (Letter.Status.CLOSED, Letter.Status.ARCHIVED):
        messages.error(request, 'فقط نامه بسته‌شده قابل بایگانی است.')
        return redirect('secretariat:letter_detail', pk=letter_pk)
    note = request.POST.get('note', '')[:500]
    folder_id = request.POST.get('folder')
    if folder_id:
        folder = get_object_or_404(ArchiveFolder, pk=folder_id, is_active=True)
    else:
        folder = folder_for_letter(letter)
    item, created = archive_object(letter, folder, request.user, note=note)
    if created:
        messages.success(request, f'نامه در پوشه «{folder.code} — {folder.title}» بایگانی شد.')
    else:
        messages.info(request, 'این نامه پیش‌تر در همین پوشه بایگانی شده بود.')
    return redirect('secretariat:letter_detail', pk=letter_pk)


@login_required
@require_POST
def folder_new(request, box_pk):
    if not _can(request.user, 'manage_archive'):
        messages.error(request, 'مجوز مدیریت بایگانی را ندارید.')
        return redirect('archive:list')
    box = get_object_or_404(ArchiveBox, pk=box_pk)
    title = request.POST.get('title', '').strip()[:200]
    if not title:
        messages.error(request, 'عنوان پوشه الزامی است.')
        return redirect('archive:box_detail', pk=box_pk)
    kind = request.POST.get('subject_kind', 'general')
    if kind not in ArchiveFolder.SubjectKind.values:
        kind = 'general'
    folder = ensure_folder(box, title, kind)
    messages.success(request, f'پوشه «{folder.code} — {folder.title}» آماده است.')
    return redirect('archive:box_detail', pk=box_pk)


@login_required
@require_POST
def disposal_request(request, folder_pk):
    if not _can(request.user, 'manage_archive'):
        messages.error(request, 'مجوز مدیریت بایگانی را ندارید.')
        return redirect('archive:folder_detail', pk=folder_pk)
    folder = get_object_or_404(ArchiveFolder, pk=folder_pk)
    reason = request.POST.get('reason', '').strip()[:1000]
    if len(reason) < 5:
        messages.error(request, 'دلیل امحا را کامل بنویسید.')
        return redirect('archive:folder_detail', pk=folder_pk)
    req = request_disposal(folder, request.user, reason)
    if req is None:
        messages.error(request, 'پوشه فعال نیست یا درخواست امحای باز دارد.')
    else:
        messages.success(request, 'درخواست امحا ثبت و برای تأیید ارسال شد.')
    return redirect('archive:folder_detail', pk=folder_pk)


@login_required
@require_POST
def disposal_decide(request, pk):
    if not _can(request.user, 'approve_disposal'):
        messages.error(request, 'مجوز تأیید امحا را ندارید.')
        return redirect('archive:folder_detail', pk=request.POST.get('back', pk))
    req = get_object_or_404(DisposalRequest.objects.select_related('folder'), pk=pk)
    approve = request.POST.get('approve') == '1'
    note = request.POST.get('note', '')[:500]
    if decide_disposal(req, request.user, approve, note) is None:
        messages.error(request, 'این درخواست پیش‌تر تصمیم‌گیری شده است.')
    else:
        messages.success(request, 'تأیید شد — قابل اجراست.' if approve else 'رد شد.')
    return redirect('archive:folder_detail', pk=req.folder_id)


@login_required
@require_POST
def disposal_execute(request, pk):
    if not _can(request.user, 'manage_archive'):
        messages.error(request, 'مجوز مدیریت بایگانی را ندارید.')
        return redirect('archive:list')
    req = get_object_or_404(DisposalRequest.objects.select_related('folder'), pk=pk)
    if execute_disposal(req, request.user) is None:
        messages.error(request, 'فقط درخواست تأییدشده قابل اجراست.')
    else:
        messages.success(request, f'پوشه «{req.folder.code}» امحا (غیرفعال) شد.')
    return redirect('archive:folder_detail', pk=req.folder_id)
