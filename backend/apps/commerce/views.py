"""نماهای بازرگانی: درخواست خرید، استعلام و قراردادها."""
from __future__ import annotations

from decimal import Decimal

import jdatetime
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import Contract, Inquiry, PurchaseRequest
from .services import (add_inquiry, create_contract, decide_request,
                       select_inquiry, submit_request)


def _can_manage(user) -> bool:
    return user.is_superuser or user.has_perm('commerce.manage_commerce')


def _parse_jalali(raw: str):
    from apps.secretariat.views import _parse_jalali_input
    return _parse_jalali_input(raw)


def _parse_money(raw: str) -> Decimal:
    return Decimal((raw or '0').replace(',', '').strip() or '0')


@login_required
def purchase_list(request):
    tab = request.GET.get('tab', 'mine')
    qs = PurchaseRequest.objects.select_related('requested_by')
    if tab == 'mine':
        qs = qs.filter(requested_by=request.user)
    elif tab == 'pending':
        qs = qs.filter(status=PurchaseRequest.Status.SUBMITTED)
    elif not _can_manage(request.user):
        qs = qs.filter(requested_by=request.user)
    return render(request, 'commerce/list.html', {
        'requests': qs, 'tab': tab,
        'can_manage': _can_manage(request.user),
    })


@login_required
def purchase_new(request):
    if request.method != 'POST':
        return render(request, 'commerce/new.html', {
            'today': jdatetime.date.today().strftime('%Y/%m/%d'),
        })
    title = request.POST.get('title', '').strip()
    if not title:
        messages.error(request, 'عنوان خرید لازم است.')
        return redirect('commerce:new')
    purchase = PurchaseRequest.objects.create(
        title=title,
        description=request.POST.get('description', '').strip(),
        estimated_cost=_parse_money(request.POST.get('estimated_cost', '')),
        needed_by=_parse_jalali(request.POST.get('needed_by', '')),
        requested_by=request.user)
    if request.POST.get('action') == 'submit':
        submit_request(purchase, request.user)
        messages.success(request, 'درخواست برای تأیید ارسال شد.')
    else:
        messages.success(request, 'پیش‌نویس درخواست ذخیره شد.')
    return redirect('commerce:detail', pk=purchase.pk)


@login_required
def purchase_detail(request, pk):
    purchase = get_object_or_404(
        PurchaseRequest.objects.select_related('requested_by', 'decided_by'),
        pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('commerce:list')
    return render(request, 'commerce/detail.html', {
        'purchase': purchase, 'can_manage': _can_manage(request.user),
        'is_owner': purchase.requested_by == request.user,
    })


@login_required
@require_POST
def purchase_submit(request, pk):
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        return redirect('commerce:list')
    submit_request(purchase, request.user)
    messages.success(request, 'درخواست برای تأیید ارسال شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
@require_POST
def purchase_decide(request, pk):
    if not _can_manage(request.user):
        messages.error(request, 'امکان تأیید/رد ندارید.')
        return redirect('commerce:list')
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    decide_request(purchase, request.user,
                   approve=request.POST.get('decision') == 'approve',
                   note=request.POST.get('note', ''))
    messages.success(request, 'درخواست بررسی شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
@require_POST
def inquiry_add(request, pk):
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        return redirect('commerce:list')
    try:
        inquiry = add_inquiry(
            purchase=purchase,
            supplier_name=request.POST.get('supplier_name', '').strip(),
            quote_amount=_parse_money(request.POST.get('quote_amount', '')),
            quote_note=request.POST.get('quote_note', ''))
    except Exception as exc:  # noqa: BLE001 — پیام تمیز به کاربر
        messages.error(request, f'استعلام ثبت نشد: {exc}')
        return redirect('commerce:detail', pk=pk)
    messages.success(request, f'پیشنهاد {inquiry.supplier_name} ثبت شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
@require_POST
def inquiry_select(request, pk, inquiry_pk):
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        return redirect('commerce:list')
    inquiry = get_object_or_404(Inquiry, pk=inquiry_pk, request=purchase)
    select_inquiry(inquiry)
    messages.success(request, f'پیشنهاد {inquiry.supplier_name} انتخاب شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
def contract_list(request):
    contracts = Contract.objects.select_related('purchase_request')
    return render(request, 'commerce/contracts.html', {
        'contracts': contracts,
    })


@login_required
@require_POST
def contract_new(request):
    if not _can_manage(request.user):
        messages.error(request, 'امکان ثبت قرارداد ندارید.')
        return redirect('commerce:contract_list')
    title = request.POST.get('title', '').strip()
    if not title:
        messages.error(request, 'عنوان قرارداد لازم است.')
        return redirect('commerce:contract_list')
    purchase = None
    if request.POST.get('purchase_id'):
        purchase = PurchaseRequest.objects.filter(
            pk=request.POST.get('purchase_id')).first()
    create_contract(
        title=title, party=request.POST.get('party', '').strip(),
        amount=_parse_money(request.POST.get('amount', '')),
        start_date=_parse_jalali(request.POST.get('start_date', ''))
        or jdatetime.date.today(),
        end_date=_parse_jalali(request.POST.get('end_date', '')),
        purchase=purchase, description=request.POST.get('description', ''))
    messages.success(request, 'قرارداد ثبت شد.')
    return redirect('commerce:contract_list')
