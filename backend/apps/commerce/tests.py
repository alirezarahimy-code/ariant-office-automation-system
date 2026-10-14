"""آزمون‌های بازرگانی: گردش تأیید، استعلام و قرارداد."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from .models import Contract, Inquiry, PurchaseRequest
from .services import (add_inquiry, create_contract, decide_request,
                       select_inquiry, submit_request)

User = get_user_model()


class CommerceServiceTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.manager = User.objects.create_user('mgr', password='x', is_superuser=True)

    def _purchase(self):
        return PurchaseRequest.objects.create(
            title='خرید کاغذ', requested_by=self.user,
            estimated_cost=Decimal('5000000'))

    def test_submit_sets_status(self):
        purchase = submit_request(self._purchase(), self.user)
        self.assertEqual(purchase.status, PurchaseRequest.Status.SUBMITTED)

    def test_approve_flow(self):
        purchase = submit_request(self._purchase(), self.user)
        decide_request(purchase, self.manager, approve=True, note='تأیید شد')
        purchase.refresh_from_db()
        self.assertEqual(purchase.status, PurchaseRequest.Status.APPROVED)
        self.assertEqual(purchase.decided_by, self.manager)

    def test_reject_flow(self):
        purchase = submit_request(self._purchase(), self.user)
        decide_request(purchase, self.manager, approve=False, note='بودجه نیست')
        purchase.refresh_from_db()
        self.assertEqual(purchase.status, PurchaseRequest.Status.REJECTED)

    def test_select_inquiry_exclusive(self):
        purchase = self._purchase()
        a = add_inquiry(purchase=purchase, supplier_name='الف',
                        quote_amount=Decimal('100'))
        b = add_inquiry(purchase=purchase, supplier_name='ب',
                        quote_amount=Decimal('90'))
        select_inquiry(a)
        select_inquiry(b)
        a.refresh_from_db()
        b.refresh_from_db()
        self.assertFalse(a.selected)
        self.assertTrue(b.selected)

    def test_contract_completes_request(self):
        purchase = self._purchase()
        contract = create_contract(
            title='قرارداد کاغذ', party='الف', amount=Decimal('90'),
            start_date=jdatetime.date(1404, 7, 23), purchase=purchase)
        purchase.refresh_from_db()
        self.assertEqual(purchase.status, PurchaseRequest.Status.DONE)
        self.assertEqual(contract.purchase_request, purchase)


class CommerceViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.manager = User.objects.create_user('mgr', password='x', is_superuser=True)
        self.purchase = PurchaseRequest.objects.create(
            title='خرید کاغذ', requested_by=self.user)

    def test_list_view(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('commerce:list'))
        self.assertEqual(resp.status_code, 200)

    def test_new_post(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('commerce:new'), {
            'title': 'خرید تونر', 'estimated_cost': '8000000',
            'action': 'submit'})
        self.assertEqual(resp.status_code, 302)
        purchase = PurchaseRequest.objects.get(title='خرید تونر')
        self.assertEqual(purchase.status, PurchaseRequest.Status.SUBMITTED)

    def test_detail_forbidden_for_stranger(self):
        stranger = User.objects.create_user('u2', password='x')
        self.client.force_login(stranger)
        resp = self.client.get(reverse('commerce:detail', args=[self.purchase.pk]))
        self.assertEqual(resp.status_code, 302)

    def test_decide_requires_manager(self):
        self.client.force_login(self.user)
        self.client.post(reverse('commerce:decide', args=[self.purchase.pk]),
                         {'decision': 'approve'})
        self.purchase.refresh_from_db()
        self.assertNotEqual(self.purchase.status, PurchaseRequest.Status.APPROVED)

    def test_decide_by_manager(self):
        self.client.force_login(self.manager)
        self.client.post(reverse('commerce:decide', args=[self.purchase.pk]),
                         {'decision': 'approve', 'note': 'تأیید'})
        self.purchase.refresh_from_db()
        self.assertEqual(self.purchase.status, PurchaseRequest.Status.APPROVED)

    def test_contract_list_and_new(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('commerce:contract_list'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(reverse('commerce:contract_new'), {
            'title': 'قرارداد سالانه', 'party': 'شرکت الف',
            'amount': '100000000', 'start_date': '1404/07/24'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(Contract.objects.count(), 1)
