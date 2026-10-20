"""T5Web navigation reconstructed from the embedded Persian resources.

This is deliberately the T5Web shell only. TWF and Link5 will have separate
shells and navigation trees rather than being folded into these menus.
"""
from django.urls import NoReverseMatch, reverse

from apps.core.services import user_permissions


# The ordering and captions follow T5V3Web's embedded menu resources. A menu
# entry is included only when its destination exists in this implementation;
# missing reference screens must be implemented, not linked to look-alikes.
T5_MENU = (
    (
        "اطلاعات پایه",
        (
            ("مدرک تحصیلی", "org:certificates", "org.manage"),
            ("سمت", "org:titles", "org.manage"),
            ("انتخاب جانشین", "attendance:deputy", "requests.manage"),
            ("نوع استخدام", "org:emp_types", "org.manage"),
            ("نوع خروج", "attendance:exit_types", "attendance.manage"),
            ("شرح خروج", "attendance:leave_descriptions", "attendance.manage"),
            ("تقویم", "attendance:holidays", "attendance.manage"),
            ("ماه رمضان", "attendance:ramadan", "attendance.view"),
            ("قانون", "attendance:rules", "attendance.view"),
            ("قسمت", "org:department_admin", "org.manage"),
            ("مشخصات اضافه پرسنل", "org:custom_fields", "org.view"),
            ("ورود اطلاعات کاربران از Excel", "org:import_excel", "org.manage"),
            ("اطلاعات پرسنلی", "org:userinfo", "org.view"),
            ("گروهبندی پرسنل", "org:org_chart", "org.view"),
        ),
    ),
    (
        "اطلاعات حضور و غیاب",
        (
            ("جدول زمانی", "attendance:schedule_definitions", "attendance.manage"),
            ("شیفت", "attendance:shifts", "attendance.manage"),
            ("اختصاص شیفت به پرسنل", "attendance:shift_assign", "attendance.manage"),
            ("شیفت‌های موقت", "attendance:temp_schedules", "attendance.manage"),
            ("اختصاص قوانین", "attendance:rules", "attendance.view"),
        ),
    ),
    (
        "کنترل حضور و غیاب",
        (
            ("درج تردد", "attendance:insert_record", "attendance.add"),
            ("درج مرخصی و مأموریت روزانه", "attendance:daily_leave_batch", "attendance.manage"),
            ("مدیریت اطلاعات USB", "device:usb_import",
             ("attendance.manage", "device.manage")),
            ("ویرایش تأخیر و تعجیل", "attendance:edit_late_early", "attendance.manage"),
            ("صدور مجوز کسر کار", "attendance:valid_leaves", "attendance.manage"),
            ("رکورد تردد", "attendance:punches", "attendance.view"),
            ("کنترل یکپارچه", "attendance:total_control", "attendance.view"),
            ("گزارش جدولی", "reports:index", "reports.view"),
            ("انتقال مانده مرخصی", "attendance:leave_transfer", "attendance.manage"),
            ("وضعیت پرسنل", "attendance:personnel_status", "reports.report"),
        ),
    ),
    (
        "دستگاه",
        (
            ("گیت", "device:gates", "device.view"),
            ("دستگاه", "device:index", "device.view"),
            ("عملیات روی دستگاه‌ها", "device:operations", "device.view"),
        ),
    ),
    (
        "گزارشات",
        (
            ("گزارش روزانه فردی", "reports:run_standard", "reports.report", "daily"),
            ("گزارش مجموع", "reports:run_standard", "reports.report", "sum"),
            ("گزارش تأخیر و تعجیل", "reports:run_standard", "reports.report", "late"),
            ("گزارش وضعیت پرسنل", "attendance:personnel_status", "reports.report"),
            ("گزارش اضافه‌کارها", "reports:run_standard", "reports.report", "overtime"),
            ("گزارش مانده مرخصی", "reports:run_standard", "reports.report", "balances"),
            ("گزارش درخواست‌ها", "reports:run_standard", "reports.report", "requests"),
            ("پارامترها", "attendance:calc_parameters", "attendance.view"),
            ("طراحی گزارش", "reports:builder", "reports.manage"),
            ("نمایش گزارشات", "reports:index", "reports.view"),
        ),
    ),
    (
        "مدیریت",
        (
            ("Log", "core:system_logs", "core.view"),
            ("گردش کار", "attendance:custom_wf", "requests.manage"),
            ("مدیر", "t5web:managers", "core.manage"),
        ),
    ),
)


def t5web_navigation(request):
    """Add the permission-filtered T5 menu to authenticated page contexts."""
    user = getattr(request, "user", None)
    if not user or not user.is_authenticated:
        return {"t5_menu_groups": ()}

    granted = user_permissions(user)
    groups = []
    for title, entries in T5_MENU:
        visible = []
        for entry in entries:
            label, route, permission, *args = entry
            required_permissions = (permission if isinstance(permission, (tuple, list))
                                    else (permission,))
            if not user.is_superuser and not all(
                    required in granted for required in required_permissions):
                continue
            try:
                url = reverse(route, args=args or None)
            except NoReverseMatch:
                # Keep an incomplete reference screen out of the live menu;
                # never silently route it to a different feature.
                continue
            visible.append({"label": label, "url": url})
        if visible:
            groups.append({"title": title, "items": visible})
    return {"t5_menu_groups": groups}
