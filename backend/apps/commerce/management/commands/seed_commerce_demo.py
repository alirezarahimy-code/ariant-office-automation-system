"""کاشت داده نمایشی بازرگانی: طرف‌های تجاری، سفارش، فاکتور، فروش و واردات (idempotent)."""
from decimal import Decimal

import jdatetime
from django.core.management.base import BaseCommand

from apps.commerce.models import BusinessParty, PurchaseRequest
from apps.commerce.services import (create_import_shipment, create_purchase_invoice,
                                    create_purchase_order, create_sales_document,
                                    finalize_purchase_invoice,
                                    finalize_sales_document,
                                    receive_purchase_order)
from apps.inventory.models import Item, Warehouse


def _warehouse():
    wh, _ = Warehouse.objects.get_or_create(name='انبار مرکزی')
    return wh


class Command(BaseCommand):
    help = 'کاشت داده نمایشی بازرگانی (idempotent)'

    def handle(self, *args, **options):
        from django.contrib.auth import get_user_model
        User = get_user_model()
        demo_user = (User.objects.filter(username='modir-arshad').first()
                     or User.objects.filter(is_superuser=True).first()
                     or User.objects.order_by('pk').first())
        created = 0
        today = jdatetime.date.today()

        supplier, was = BusinessParty.objects.get_or_create(
            code='T-0001', defaults={
                'name': 'شرکت بازرگانی آریا', 'kind': 'supplier',
                'national_id': '10102030405', 'phone': '021-88776655',
                'payment_terms_days': 30, 'payment_terms_note': 'حواله ۳۰ روزه'})
        created += int(was)
        customer, was = BusinessParty.objects.get_or_create(
            code='T-0002', defaults={
                'name': 'سازمان خدمات شهری', 'kind': 'customer',
                'national_id': '20203040506', 'phone': '021-44332211'})
        created += int(was)
        foreign, was = BusinessParty.objects.get_or_create(
            code='T-0003', defaults={
                'name': 'Guangzhou Trading Co.', 'kind': 'supplier',
                'currency': 'USD', 'payment_terms_note': 'LC 90 روزه'})
        created += int(was)

        req = None
        if demo_user is not None:
            req, was = PurchaseRequest.objects.get_or_create(
                title='خرید لوازم اداری پاییز',
                defaults={'description': 'کاغذ و خودکار و پوشه',
                          'estimated_cost': Decimal('40000000'),
                          'needed_by': today + jdatetime.timedelta(days=10),
                          'status': 'approved', 'priority': 'normal',
                          'department': 'اداری', 'requested_by': demo_user})
            created += int(was)

        if not supplier.purchase_orders.exists():
            order = create_purchase_order(
                supplier=supplier, date=today - jdatetime.timedelta(days=12),
                warehouse=_warehouse(),
                request=req, description='سفارش نمایشی',
                tax_percent=Decimal('9'),
                lines=[{'item': Item.objects.filter(code='K-100').first(),
                        'description': 'کاغذ A4', 'quantity': 10,
                        'unit_name': 'بسته', 'unit_price': Decimal('280000')},
                       {'item': Item.objects.filter(code='K-101').first(),
                        'description': 'خودکار آبی', 'quantity': 50,
                        'unit_name': 'عدد', 'unit_price': Decimal('45000')}],
                confirm=True)
            created += 1
            line = order.lines.first()
            if line is not None and order.warehouse is not None:
                receive_purchase_order(
                    order, [(line, line.quantity)],
                    warehouse=order.warehouse,
                    date=today - jdatetime.timedelta(days=5))
                created += 1
            invoice = create_purchase_invoice(
                supplier=supplier, date=today - jdatetime.timedelta(days=4),
                order=order, invoice_no='INV-9001', kind='goods',
                tax_percent=Decimal('9'),
                lines=[{'item': Item.objects.filter(code='K-100').first(),
                        'description': 'کاغذ A4', 'quantity': 10,
                        'unit_name': 'بسته', 'unit_price': Decimal('280000')}])
            finalize_purchase_invoice(invoice)
            created += 1

        if not customer.sales_documents.exists() and Item.objects.filter(code='K-101').exists():
            doc = create_sales_document(
                customer=customer, kind='invoice', date=today - jdatetime.timedelta(days=2),
                warehouse=_warehouse(),
                description='فروش نمایشی',
                lines=[{'item': Item.objects.filter(code='K-101').first(),
                        'description': 'خودکار آبی', 'quantity': 10,
                        'unit_name': 'عدد', 'unit_price': Decimal('90000')}])
            finalize_sales_document(doc)
            created += 1

        if not foreign.import_shipments.exists():
            shipment = create_import_shipment(
                supplier=foreign, order=supplier.purchase_orders.first(),
                currency='USD', exchange_rate=Decimal('800000'),
                goods_value=Decimal('2000'), proforma_no='PF-551',
                bl_no='BL-7788', cost_freight=Decimal('25000000'),
                cost_insurance=Decimal('4000000'),
                proforma_date=today - jdatetime.timedelta(days=30))
            created += 1

        self.stdout.write(self.style.SUCCESS(
            f'بازرگانی نمایشی — {created} رکورد تازه.'))
