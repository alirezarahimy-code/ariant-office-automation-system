"""سرویس‌های بازرگانی: گردش تأیید درخواست، انتخاب استعلام و قرارداد."""
from __future__ import annotations

from django.contrib.auth import get_user_model

from apps.core.services import send_notification

from .models import Contract, Inquiry, PurchaseRequest


def _managers():
    User = get_user_model()
    return User.objects.filter(is_active=True, is_superuser=True)


def submit_request(purchase: PurchaseRequest, user) -> PurchaseRequest:
    """ارسال درخواست برای تأیید + اعلان مدیران."""
    purchase.status = PurchaseRequest.Status.SUBMITTED
    purchase.save(update_fields=['status', 'updated_at'])
    for manager in _managers():
        if manager.pk != user.pk:
            send_notification(manager, f'درخواست خرید تازه: {purchase.title}',
                              purchase.description[:200], f'/commerce/{purchase.pk}/')
    return purchase


def decide_request(purchase: PurchaseRequest, user, *, approve: bool,
                   note: str = '') -> PurchaseRequest:
    """تأیید/رد درخواست + اعلان درخواست‌کننده."""
    purchase.status = (PurchaseRequest.Status.APPROVED if approve
                       else PurchaseRequest.Status.REJECTED)
    purchase.decided_by = user
    purchase.decision_note = note[:300]
    purchase.save(update_fields=['status', 'decided_by', 'decision_note',
                                 'updated_at'])
    send_notification(
        purchase.requested_by,
        f'درخواست خرید {purchase.title} ' + ('تأیید شد' if approve else 'رد شد'),
        note[:200], f'/commerce/{purchase.pk}/')
    return purchase


def add_inquiry(*, purchase: PurchaseRequest, supplier_name: str,
                quote_amount, quote_note: str = '') -> Inquiry:
    return Inquiry.objects.create(
        request=purchase, supplier_name=supplier_name,
        quote_amount=quote_amount, quote_note=quote_note[:300])


def select_inquiry(inquiry: Inquiry) -> Inquiry:
    """انتخاب پیشنهاد برنده — سایر پیشنهادها کنار می‌روند."""
    inquiry.request.inquiries.update(selected=False)
    inquiry.selected = True
    inquiry.save(update_fields=['selected', 'updated_at'])
    return inquiry


def create_contract(*, title: str, party: str, amount, start_date,
                    end_date=None, purchase: PurchaseRequest | None = None,
                    description: str = '') -> Contract:
    contract = Contract.objects.create(
        title=title, party=party, amount=amount, start_date=start_date,
        end_date=end_date, purchase_request=purchase,
        description=description[:2000])
    if purchase is not None:
        purchase.status = PurchaseRequest.Status.DONE
        purchase.save(update_fields=['status', 'updated_at'])
    return contract
