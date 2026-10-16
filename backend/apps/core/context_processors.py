"""زمینه مشترک همه صفحه‌ها: هویت سازمان، تاریخ شمسی، اعلان‌های نخوانده."""
import jdatetime
from django.conf import settings

from apps.core.persian import to_persian_digits


def platform_context(request):
    today = jdatetime.date.today()
    org_name = getattr(settings, 'PLATFORM_ORG_NAME', '')
    motto = getattr(settings, 'PLATFORM_ORG_MOTTO', '')
    try:
        from apps.core.models import OrgSettings
        obj = OrgSettings.objects.filter(pk=1).first()
        if obj is not None:
            org_name = obj.org_name or org_name
            motto = obj.motto or motto
    except Exception:  # noqa: BLE001 — پیش از مهاجرت یا خطای دیتابیس
        pass
    ctx = {
        'ORG_NAME': org_name,
        'ORG_MOTTO': motto,
        'JALALI_TODAY': to_persian_digits(today.strftime('%Y/%m/%d')),
        'JALALI_WEEKDAY': today.strftime('%A'),
        'UNREAD_NOTIFICATIONS': 0,
    }
    if request.user.is_authenticated:
        ctx['UNREAD_NOTIFICATIONS'] = request.user.notifications.filter(is_read=False).count()
    return ctx


def feature_flags(request):
    """کلیدهای قابلیت برای قالب‌ها: {% if features.secretariat_casefiles %}"""
    from apps.core.services import feature_enabled
    codes = [
        'secretariat.casefiles', 'secretariat.register_book', 'secretariat.index_book',
        'secretariat.topics', 'secretariat.register_check', 'secretariat.signatures',
        'secretariat.revisions_lock', 'secretariat.copy_action', 'secretariat.sla',
        'secretariat.read_receipt', 'secretariat.number_reserve', 'secretariat.correspondents',
        'secretariat.frequent_phrases', 'secretariat.memo_letters', 'secretariat.paraph',
        'secretariat.answer_pairing', 'secretariat.global_search',
        'secretariat.batch_print', 'secretariat.copy_labels',
        'secretariat.delivery_receipt', 'secretariat.fax_book',
        'secretariat.personal_archive', 'secretariat.retention',
        'secretariat.group_referral', 'secretariat.sign_proxy',
        'secretariat.number_format', 'secretariat.word_export',
        'secretariat.courier_book',
        'inventory.documents', 'inventory.doc_numbering', 'inventory.doc_temp',
        'inventory.doc_cancel', 'inventory.doc_return',
        'inventory.item_groups', 'inventory.item_specs', 'inventory.item_image',
        'inventory.item_barcode', 'inventory.multi_unit', 'inventory.serial_expiry',
        'inventory.locations', 'inventory.min_max',
        'inventory.price_lists', 'inventory.fifo', 'inventory.batch_pricing',
        'inventory.count_tags', 'inventory.count_stages', 'inventory.count_excel',
        'inventory.count_auto_docs', 'inventory.warehouse_users',
        'inventory.low_stock_alert', 'inventory.no_movement',
        'inventory.excel_reports', 'inventory.barcode_labels',
        'inventory.dashboard', 'inventory.financial_period',
        'platform.pwa',
    ]
    nested: dict = {}
    for c in codes:
        group, key = c.split('.', 1)
        nested.setdefault(group, {})[key] = feature_enabled(c)
    return {'features': nested}
