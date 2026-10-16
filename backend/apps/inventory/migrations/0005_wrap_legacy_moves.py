"""مهاجرت داده: رویدادهای انبار بدون سند → سند چندردیفی (ردیابی کامل).

رویدادهای قدیمی (که پیش از لایه اسناد ثبت شده‌اند) گروه‌بندی و به
سند قطعی تک‌/چندردیفی متصل می‌شوند؛ شماره سند از شمارنده سالانه.
"""
from collections import defaultdict
from decimal import Decimal

from django.db import migrations


TYPE_MAP = {'receive': 'receive', 'issue': 'issue', 'adjust': 'adjust'}


def wrap_legacy_moves(apps, schema_editor):
    StockMove = apps.get_model('inventory', 'StockMove')
    StockDocument = apps.get_model('inventory', 'StockDocument')
    StockDocumentLine = apps.get_model('inventory', 'StockDocumentLine')
    StockDocSequence = apps.get_model('inventory', 'StockDocSequence')

    legacy = StockMove.objects.filter(document__isnull=True).order_by('date', 'id')
    if not legacy.exists():
        return

    groups = defaultdict(list)
    for move in legacy:
        year = move.date.year if move.date else 1404
        key = (TYPE_MAP.get(move.kind, 'adjust'), move.warehouse_id, str(move.date),
               move.category, move.reference, move.created_by_id,
               str(move.unit_price), year)
        groups[key].append(move)

    for (doc_type, warehouse_id, date_str, category, reference, user_id,
         price_str, year), moves in groups.items():
        seq, _ = StockDocSequence.objects.get_or_create(
            year=year, doc_type=doc_type, defaults={'last_seq': 0})
        seq.last_seq += 1
        seq.save(update_fields=['last_seq'])
        prefix = {'receive': 'رس', 'issue': 'ح', 'adjust': 'تعدیل'}.get(doc_type, 'سند')
        date_value = moves[0].date
        doc = StockDocument.objects.create(
            number=f'{prefix}-{year}-{seq.last_seq:04d}',
            doc_type=doc_type, category=category or 'other',
            warehouse_id=warehouse_id, date=date_value, status='final',
            reference=reference or '', description='سند مهاجرت‌یافته از رویدادهای قبلی',
            year=year, seq=seq.last_seq, created_by_id=user_id,
            confirmed_by_id=user_id, confirmed_at=moves[0].created_at)
        for i, move in enumerate(moves):
            line = StockDocumentLine.objects.create(
                document=doc, item_id=move.item_id,
                quantity=move.quantity,
                unit_name=move.item.unit if move.item_id else '',
                unit_ratio=Decimal('1'), unit_price=move.unit_price,
                description=move.note or '', sequence=i)
            move.document = doc
            move.line = line
            move.save(update_fields=['document', 'line'])


def unwrap(apps, schema_editor):
    StockMove = apps.get_model('inventory', 'StockMove')
    StockMove.objects.filter(document__description='سند مهاجرت‌یافته از رویدادهای قبلی'
                             ).update(document=None, line=None)


class Migration(migrations.Migration):

    dependencies = [
        ('inventory', '0004_alter_stockmove_options'),
    ]

    operations = [
        migrations.RunPython(wrap_legacy_moves, unwrap),
    ]
