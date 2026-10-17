"""آزمون‌های بازرگانی حرفه‌ای: طرف تجاری، چرخه خرید، فروش، واردات و گزارش‌ها."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .models import (BusinessParty, Contract, ImportShipment, PaymentSchedule,
                     PurchaseInvoice, PurchaseOrder, PurchaseRequest,
                     PurchaseRequestLine, SalesDocument)
from .services import (add_import_cost, add_inquiry, cancel_purchase_invoice,
                       cancel_purchase_order, cancel_sales_document,
                       confirm_purchase_order, create_import_shipment,
                       create_installments, create_purchase_invoice,
                       create_purchase_order, create_sales_document,
                       decide_request, finalize_purchase_invoice,
                       finalize_sales_document, landed_unit_costs,
                       open_commitments, pay_installment, receive_purchase_order,
                       select_inquiry, set_import_status, submit_request,
                       three_way_match)

User = get_user_model()


class PartyTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_commerce'))

    def test_auto_code(self):
        self.assertEqual(BusinessParty.next_code(), 'T-0001')
        BusinessParty.objects.create(code='T-0001', name='الف')
        self.assertEqual(BusinessParty.next_code(), 'T-0002')

    def test_party_create_and_views(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('commerce:party_list'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(reverse('commerce:party_new'), {
            'name': 'شرکت پخش البرز', 'kind': 'supplier',
            'national_id': '10101234567', 'payment_terms_days': '30',
            'currency': 'IRR'})
        self.assertEqual(resp.status_code, 302)
        party = BusinessParty.objects.get(name='شرکت پخش البرز')
        self.assertTrue(party.code.startswith('T-'))
        self.assertEqual(party.payment_terms_days, 30)
        resp = self.client.get(reverse('commerce:party_detail', args=[party.pk]))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.get(
            reverse('commerce:party_list') + '?q=' + 'البرز')
        self.assertContains(resp, 'پخش البرز')


class PurchaseCycleTests(TestCase):
    """چرخه کامل P2P: درخواست → استعلام → سفارش → رسید → فاکتور → تطبیق."""

    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_commerce'))
        self.party = BusinessParty.objects.create(
            code='T-0001', name='تأمین‌کننده الف', kind='supplier')
        self.item = None
        from apps.inventory.models import Item, Warehouse
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 30)

    def test_request_lines_and_flow(self):
        req = PurchaseRequest.objects.create(
            title='خرید کاغذ', requested_by=self.manager,
            priority='urgent', purchase_kind='inquiry')
        PurchaseRequestLine.objects.create(
            request=req, description='کاغذ A4', quantity=10,
            estimated_price=Decimal('50000'))
        submit_request(req, self.manager)
        req.refresh_from_db()
        self.assertEqual(req.status, PurchaseRequest.Status.SUBMITTED)
        decide_request(req, self.manager, approve=True, note='تأیید شد')
        req.refresh_from_db()
        self.assertEqual(req.status, PurchaseRequest.Status.APPROVED)
        self.assertEqual(req.lines.get().quantity, Decimal('10'))

    def test_inquiry_select(self):
        req = PurchaseRequest.objects.create(
            title='خرید', requested_by=self.manager)
        inq1 = add_inquiry(purchase=req, supplier_name='الف',
                           quote_amount=Decimal('1000'), supplier=self.party)
        inq2 = add_inquiry(purchase=req, supplier_name='ب',
                           quote_amount=Decimal('900'))
        select_inquiry(inq1)
        inq1.refresh_from_db()
        inq2.refresh_from_db()
        self.assertTrue(inq1.selected)
        self.assertFalse(inq2.selected)

    def test_order_numbering_and_confirm(self):
        order = create_purchase_order(
            supplier=self.party, date=self.d, created_by=self.manager,
            lines=[{'description': 'کالا', 'quantity': 5,
                    'unit_price': Decimal('1000'), 'item': self.item}])
        self.assertEqual(order.number, 'PO-1404-0001')
        self.assertEqual(order.status, PurchaseOrder.Status.DRAFT)
        confirm_purchase_order(order, confirmed_by=self.manager)
        order.refresh_from_db()
        self.assertEqual(order.status, PurchaseOrder.Status.CONFIRMED)
        order2 = create_purchase_order(
            supplier=self.party, date=self.d,
            lines=[{'description': 'کالا', 'quantity': 1, 'unit_price': 100}])
        self.assertEqual(order2.number, 'PO-1404-0002')

    def test_receive_creates_inventory_document(self):
        from apps.inventory.services import current_stock
        order = create_purchase_order(
            supplier=self.party, date=self.d, created_by=self.manager,
            warehouse=self.wh, confirm=True,
            lines=[{'description': 'کالا', 'quantity': 10,
                    'unit_price': Decimal('2000'), 'item': self.item}])
        line = order.lines.get()
        receipt = receive_purchase_order(
            order, [(line, Decimal('6'))], warehouse=self.wh,
            date=self.d, created_by=self.manager)
        self.assertIsNotNone(receipt.document)
        self.assertEqual(current_stock(self.item, self.wh), Decimal('6'))
        line.refresh_from_db()
        self.assertEqual(line.received_quantity, Decimal('6'))
        order.refresh_from_db()
        self.assertEqual(order.status, PurchaseOrder.Status.PARTIAL)
        receive_purchase_order(
            order, [(line, Decimal('4'))], warehouse=self.wh, date=self.d)
        order.refresh_from_db()
        self.assertEqual(order.status, PurchaseOrder.Status.RECEIVED)

    def test_receive_over_order_raises(self):
        order = create_purchase_order(
            supplier=self.party, date=self.d, confirm=True,
            lines=[{'description': 'کالا', 'quantity': 2,
                    'unit_price': 100, 'item': self.item}])
        with self.assertRaises(ValueError):
            receive_purchase_order(
                order, [(order.lines.get(), Decimal('3'))],
                warehouse=self.wh, date=self.d)

    def test_invoice_three_way_match(self):
        order = create_purchase_order(
            supplier=self.party, date=self.d, created_by=self.manager,
            warehouse=self.wh, confirm=True,
            lines=[{'description': 'کالا', 'quantity': 10,
                    'unit_price': Decimal('1000'), 'item': self.item}])
        line = order.lines.get()
        receive_purchase_order(order, [(line, Decimal('10'))],
                               warehouse=self.wh, date=self.d)
        invoice = create_purchase_invoice(
            supplier=self.party, date=self.d, order=order,
            invoice_no='INV-1', created_by=self.manager,
            lines=[{'description': 'کالا', 'quantity': 10,
                    'unit_price': Decimal('1000'), 'item': self.item,
                    'order_line': None}])
        inv_line = invoice.lines.get()
        inv_line.order_line = line
        inv_line.save()
        finalize_purchase_invoice(invoice, confirmed_by=self.manager)
        line.refresh_from_db()
        self.assertEqual(line.invoiced_quantity, Decimal('10'))
        rows = three_way_match(order)
        self.assertTrue(rows[0]['match'])

    def test_invoice_totals_discount_tax(self):
        invoice = create_purchase_invoice(
            supplier=self.party, date=self.d, finalize=False,
            tax_percent=Decimal('10'), discount_amount=Decimal('1000'),
            extra_costs=Decimal('500'),
            lines=[{'description': 'خدمت', 'quantity': 2,
                    'unit_price': Decimal('5000')}])
        # زیرمجموعه 10000، منهای تخفیف 1000 + اضافات 500 = 9500، مالیات 10٪ = 10450
        self.assertEqual(invoice.subtotal, 10000)
        self.assertEqual(invoice.total, 10450)

    def test_installments_and_commitments(self):
        invoice = create_purchase_invoice(
            supplier=self.party, date=self.d,
            lines=[{'description': 'کالا', 'quantity': 1, 'unit_price': 100000}])
        create_installments(purchase_invoice=invoice, schedule=[
            (jdatetime.date(1404, 8, 1), Decimal('50000')),
            (jdatetime.date(1404, 9, 1), Decimal('50000'))])
        rows = open_commitments()
        self.assertEqual(len(rows), 2)
        first = invoice.installments.first()
        pay_installment(first, paid_date=self.d)
        self.assertEqual(len(open_commitments()), 1)

    def test_cancel_invoice_restores_invoiced_qty(self):
        order = create_purchase_order(
            supplier=self.party, date=self.d, confirm=True,
            lines=[{'description': 'کالا', 'quantity': 5,
                    'unit_price': 100, 'item': self.item}])
        line = order.lines.get()
        invoice = create_purchase_invoice(
            supplier=self.party, date=self.d, order=order,
            lines=[{'description': 'کالا', 'quantity': 5, 'unit_price': 100,
                    'item': self.item}])
        inv_line = invoice.lines.get()
        inv_line.order_line = line
        inv_line.save()
        finalize_purchase_invoice(invoice)
        cancel_purchase_invoice(invoice, reason='اشتباه')
        line.refresh_from_db()
        self.assertEqual(line.invoiced_quantity, Decimal('0'))
        invoice.refresh_from_db()
        self.assertEqual(invoice.status, PurchaseInvoice.Status.CANCELLED)

    def test_cancel_order_with_receipt_raises(self):
        order = create_purchase_order(
            supplier=self.party, date=self.d, confirm=True,
            lines=[{'description': 'کالا', 'quantity': 2,
                    'unit_price': 100, 'item': self.item}])
        receive_purchase_order(order, [(order.lines.get(), Decimal('1'))],
                               warehouse=self.wh, date=self.d)
        with self.assertRaises(ValueError):
            cancel_purchase_order(order)

    def test_purchase_views(self):
        self.client.force_login(self.manager)
        order = create_purchase_order(
            supplier=self.party, date=self.d, created_by=self.manager,
            lines=[{'description': 'کالا', 'quantity': 1, 'unit_price': 100}])
        invoice = create_purchase_invoice(
            supplier=self.party, date=self.d,
            lines=[{'description': 'کالا', 'quantity': 1, 'unit_price': 100}])
        for name, args in [
                ('commerce:order_list', None),
                ('commerce:order_detail', [order.pk]),
                ('commerce:order_print', [order.pk]),
                ('commerce:invoice_list', None),
                ('commerce:invoice_detail', [invoice.pk]),
                ('commerce:invoice_print', [invoice.pk]),
                ('commerce:report_purchases', None),
                ('commerce:report_sales', None),
                ('commerce:dashboard', None)]:
            resp = self.client.get(
                reverse(name, args=args) if args else reverse(name))
            self.assertEqual(resp.status_code, 200, name)

    def test_order_new_post(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('commerce:order_new'), {
            'supplier_id': self.party.pk, 'date': '1404/07/30',
            'warehouse_id': self.wh.pk,
            'line_desc_0': 'کالای سفارشی', 'line_qty_0': '3',
            'line_price_0': '2500', 'line_item_0': self.item.pk,
            'action': 'confirm'})
        self.assertEqual(resp.status_code, 302)
        order = PurchaseOrder.objects.latest('id')
        self.assertEqual(order.status, PurchaseOrder.Status.CONFIRMED)
        self.assertEqual(order.lines.get().quantity, Decimal('3'))


class SalesTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_commerce'))
        self.customer = BusinessParty.objects.create(
            code='T-0001', name='مشتری', kind='customer')
        from apps.inventory.models import Item, Warehouse
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار')
        self.d = jdatetime.date(1404, 7, 30)
        from apps.inventory.services import create_document
        create_document(doc_type='receive', warehouse=self.wh, date=self.d,
                        finalize=True,
                        lines=[{'item': self.item, 'quantity': 20}])

    def tearDown(self):
        clear_feature_cache()

    def test_proforma_no_stock_effect(self):
        from apps.inventory.services import current_stock
        doc = create_sales_document(
            customer=self.customer, kind='proforma', date=self.d,
            lines=[{'description': 'کالا', 'quantity': 5,
                    'unit_price': 1000, 'item': self.item}])
        finalize_sales_document(doc)
        self.assertEqual(current_stock(self.item), Decimal('20'))

    def test_invoice_issues_stock(self):
        from apps.inventory.services import current_stock
        doc = create_sales_document(
            customer=self.customer, kind='invoice', date=self.d,
            warehouse=self.wh, created_by=self.manager,
            lines=[{'description': 'کالا', 'quantity': 5,
                    'unit_price': 1000, 'item': self.item}])
        self.assertEqual(doc.number, 'SI-1404-0001')
        finalize_sales_document(doc, confirmed_by=self.manager)
        self.assertEqual(current_stock(self.item), Decimal('15'))

    def test_return_receives_stock(self):
        from apps.inventory.services import current_stock
        doc = create_sales_document(
            customer=self.customer, kind='invoice', date=self.d,
            warehouse=self.wh,
            lines=[{'description': 'کالا', 'quantity': 5,
                    'unit_price': 1000, 'item': self.item}])
        finalize_sales_document(doc)
        ret = create_sales_document(
            customer=self.customer, kind='return', date=self.d,
            warehouse=self.wh,
            lines=[{'description': 'کالا', 'quantity': 2,
                    'unit_price': 1000, 'item': self.item}])
        finalize_sales_document(ret)
        self.assertEqual(current_stock(self.item), Decimal('17'))

    def test_cancel_invoice_reverses_stock(self):
        from apps.inventory.services import current_stock
        doc = create_sales_document(
            customer=self.customer, kind='invoice', date=self.d,
            warehouse=self.wh, created_by=self.manager,
            lines=[{'description': 'کالا', 'quantity': 4,
                    'unit_price': 1000, 'item': self.item}])
        finalize_sales_document(doc)
        self.assertEqual(current_stock(self.item), Decimal('16'))
        cancel_sales_document(doc, reason='اشتباه')
        self.assertEqual(current_stock(self.item), Decimal('20'))

    def test_invoice_without_warehouse_raises(self):
        doc = create_sales_document(
            customer=self.customer, kind='invoice', date=self.d,
            lines=[{'description': 'کالا', 'quantity': 1,
                    'unit_price': 100, 'item': self.item}])
        with self.assertRaises(ValueError):
            finalize_sales_document(doc)

    def test_over_stock_raises(self):
        doc = create_sales_document(
            customer=self.customer, kind='invoice', date=self.d,
            warehouse=self.wh,
            lines=[{'description': 'کالا', 'quantity': 50,
                    'unit_price': 100, 'item': self.item}])
        with self.assertRaises(ValueError):
            finalize_sales_document(doc)

    def test_sales_views(self):
        self.client.force_login(self.manager)
        doc = create_sales_document(
            customer=self.customer, kind='invoice', date=self.d,
            warehouse=self.wh,
            lines=[{'description': 'کالا', 'quantity': 1,
                    'unit_price': 100, 'item': self.item}])
        for name, args in [('commerce:sales_list', None),
                           ('commerce:sales_detail', [doc.pk]),
                           ('commerce:sales_print', [doc.pk])]:
            resp = self.client.get(
                reverse(name, args=args) if args else reverse(name))
            self.assertEqual(resp.status_code, 200, name)


class ImportTests(TestCase):
    def setUp(self):
        self.supplier = BusinessParty.objects.create(
            code='T-0001', name='فروشنده چینی', kind='supplier')
        self.order_party = None
        self.d = jdatetime.date(1404, 7, 30)

    def test_shipment_numbering_and_costs(self):
        shipment = create_import_shipment(
            supplier=self.supplier, goods_value=Decimal('1000'),
            exchange_rate=Decimal('60000'), cost_freight=Decimal('2000000'),
            cost_insurance=Decimal('500000'), cost_customs=Decimal('1500000'),
            cost_clearing=Decimal('300000'), proforma_date=self.d)
        self.assertEqual(shipment.number, 'IM-1404-0001')
        add_import_cost(shipment, title='انبارداری', amount=Decimal('200000'),
                        stage='ترخیص')
        self.assertEqual(shipment.extra_costs_total, 4500000)
        self.assertEqual(shipment.total_landed_rial,
                         1000 * 60000 + 4500000)

    def test_landed_unit_costs_from_order(self):
        from apps.inventory.models import Item
        item1 = Item.objects.create(code='A', name='اول', unit='عدد')
        item2 = Item.objects.create(code='B', name='دوم', unit='عدد')
        party = BusinessParty.objects.create(code='T-0002', name='داخلی')
        order = create_purchase_order(
            supplier=party, date=self.d, confirm=True,
            lines=[{'description': 'اول', 'quantity': 10,
                    'unit_price': Decimal('1000'), 'item': item1},
                   {'description': 'دوم', 'quantity': 10,
                    'unit_price': Decimal('3000'), 'item': item2}])
        shipment = create_import_shipment(
            supplier=self.supplier, order=order, goods_value=Decimal('400'),
            exchange_rate=Decimal('1000'), cost_freight=Decimal('100000'))
        rows = landed_unit_costs(shipment)
        self.assertEqual(len(rows), 2)
        # ارزش کالا 400٬۰۰۰ ریال به نسبت ۱:۳؛ هزینه ۱۰۰٬۰۰۰ هم به همان نسبت
        self.assertEqual(rows[0]['cost_share'], 25000)
        self.assertEqual(rows[0]['unit_cost'], 12500)   # (100000+25000)/10
        self.assertEqual(rows[1]['unit_cost'], 37500)   # (300000+75000)/10

    def test_status_flow(self):
        shipment = create_import_shipment(supplier=self.supplier)
        set_import_status(shipment, ImportShipment.Status.SHIPPED)
        set_import_status(shipment, ImportShipment.Status.CLEARED)
        shipment.refresh_from_db()
        self.assertEqual(shipment.status, ImportShipment.Status.CLEARED)
        with self.assertRaises(ValueError):
            set_import_status(shipment, 'bad')

    def test_import_views(self):
        manager = User.objects.create_user('mgr', password='x')
        manager.user_permissions.add(
            Permission.objects.get(codename='manage_commerce'))
        shipment = create_import_shipment(
            supplier=self.supplier, goods_value=100, exchange_rate=50000)
        self.client.force_login(manager)
        for name, args in [('commerce:import_list', None),
                           ('commerce:import_detail', [shipment.pk]),
                           ('commerce:import_new', None)]:
            resp = self.client.get(
                reverse(name, args=args) if args else reverse(name))
            self.assertEqual(resp.status_code, 200, name)
        resp = self.client.post(
            reverse('commerce:import_cost_add', args=[shipment.pk]),
            {'title': 'هزینه', 'amount': '1000'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(shipment.costs.count(), 1)


class CommitmentAndContractTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_commerce'))
        self.party = BusinessParty.objects.create(code='T-0001', name='الف')

    def test_contract_auto_number_and_kind(self):
        contract = Contract.objects.create(
            title='قرارداد تأمین', party='الف', party_ref=self.party,
            amount=Decimal('1000000'), start_date=jdatetime.date(1404, 7, 1),
            kind='service', guarantee_amount=Decimal('50000'))
        self.assertEqual(contract.number, 'GC-1404-0001')
        contract2 = Contract.objects.create(
            title='قرارداد دوم', party='ب',
            amount=Decimal('2000000'), start_date=jdatetime.date(1404, 7, 2))
        self.assertEqual(contract2.number, 'GC-1404-0002')

    def test_commitments_view(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('commerce:report_commitments'))
        self.assertEqual(resp.status_code, 200)

    def test_purchase_excel(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('commerce:purchases_excel'))
        self.assertEqual(resp.status_code, 200)
        self.assertIn('spreadsheetml', resp['Content-Type'])
