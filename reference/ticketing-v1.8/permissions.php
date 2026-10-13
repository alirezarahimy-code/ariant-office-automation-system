<?php
declare(strict_types=1);

/**
 * Role-based permissions.
 *
 * Roles are stored in users.role (existing enum + new values added by upgrade-1.22).
 * Permissions per role are stored in the role_permissions table.
 * A permission row means "this role has this permission".
 */

function permission_roles(): array
{
    return [
        'primary_admin' => 'ادمین اصلی',
        'supervisor' => 'سوپروایز',
        'support_manager' => 'مدیر پشتیبانی',
        'inspector' => 'بازرسی',
        'manager' => 'مدیر',
        'agent' => 'کارشناس',
        'user' => 'کاربر',
    ];
}

/**
 * Full catalog grouped by area: group label => [permission => label].
 */
function permission_catalog(): array
{
    return [
        [
            'key' => 'base',
            'label' => 'داشبورد و پایه',
            'items' => [
                ['code' => 'dash.view', 'label' => 'مشاهدهٔ داشبورد'],
                ['code' => 'search.global', 'label' => 'جست‌وجوی سراسری'],
                ['code' => 'notif.view', 'label' => 'اعلان‌های من'],
                ['code' => 'profile.edit', 'label' => 'ویرایش پروفایل خودم'],
            ],
        ],
        [
            'key' => 'ticket',
            'label' => 'تیکت',
            'items' => [
                ['code' => 'ticket.create', 'label' => 'ثبت تیکت جدید'],
                ['code' => 'ticket.view_own', 'label' => 'مشاهدهٔ تیکت‌های خودم'],
                ['code' => 'ticket.view_unit', 'label' => 'مشاهدهٔ تیکت‌های واحد/زیرمجموعه'],
                ['code' => 'ticket.view_all', 'label' => 'مشاهدهٔ همهٔ تیکت‌ها'],
                ['code' => 'ticket.reply', 'label' => 'پاسخ/مکالمه در تیکت'],
                ['code' => 'ticket.edit', 'label' => 'ویرایش'],
                ['code' => 'ticket.assign', 'label' => 'ارجاع تیکت به کارشناس'],
                ['code' => 'ticket.bulk', 'label' => 'ویرایش'],
                ['code' => 'ticket.rate', 'label' => 'امتیازدهی به تیکت'],
                ['code' => 'ticket.attach_asset', 'label' => 'اتصال دارایی به تیکت'],
                ['code' => 'queue.view', 'label' => 'صف کاری'],
                ['code' => 'supervisor.panel', 'label' => 'پنل سوپروایزر'],
                ['code' => 'supervisor.decide', 'label' => 'ویرایش'],
                ['code' => 'supervisor.reopen', 'label' => 'بازگشایی/برگشت تیکت'],
            ],
        ],
        [
            'key' => 'services',
            'label' => 'خدمات و دسته‌بندی',
            'items' => [
                ['code' => 'services.view', 'label' => 'مشاهدهٔ خدمات'],
                ['code' => 'services.manage', 'label' => 'ویرایش'],
                ['code' => 'service_cat.manage', 'label' => 'مدیریت دسته‌بندی خدمات'],
                ['code' => 'service_field.manage', 'label' => 'مدیریت فیلدهای خدمت'],
            ],
        ],
        [
            'key' => 'knowledge',
            'label' => 'دانش‌نامه',
            'items' => [
                ['code' => 'knowledge.view', 'label' => 'مشاهدهٔ دانش‌نامه'],
                ['code' => 'knowledge.manage', 'label' => 'ویرایش'],
            ],
        ],
        [
            'key' => 'cddvd',
            'label' => 'کنترل CD/DVD',
            'items' => [
                ['code' => 'cddvd.view', 'label' => 'مشاهدهٔ فرم کنترل CD/DVD'],
                ['code' => 'cddvd.submit_out', 'label' => 'ثبت خروج رسانه'],
                ['code' => 'cddvd.submit_in', 'label' => 'ثبت ورود رسانه (بازرسی)'],
                ['code' => 'cddvd.edit', 'label' => 'ویرایش'],
                ['code' => 'cddvd.view_all', 'label' => 'مشاهدهٔ همهٔ ثبت‌ها (نه فقط واحد خود)'],
                ['code' => 'cddvd.history', 'label' => 'تاریخچهٔ سریال'],
                ['code' => 'cddvd.export', 'label' => 'خروجی Excel/CSV'],
            ],
        ],
        [
            'key' => 'traffic',
            'label' => 'کنترل تردد مراجعین',
            'items' => [
                ['code' => 'traffic.view', 'label' => 'مشاهدهٔ کنترل تردد'],
                ['code' => 'traffic.manage', 'label' => 'ویرایش'],
                ['code' => 'traffic.destinations', 'label' => 'مدیریت مقصدهای ملاقات'],
            ],
        ],
        [
            'key' => 'assets',
            'label' => 'دارایی و شناسنامه فنی',
            'items' => [
                ['code' => 'assets.view', 'label' => 'فهرست شناسنامه‌ها'],
                ['code' => 'assets.own_unit', 'label' => 'فقط دارایی‌های واحد خودم'],
                ['code' => 'asset.view', 'label' => 'مشاهدهٔ شناسنامهٔ دارایی'],
                ['code' => 'asset.edit', 'label' => 'ویرایش'],
                ['code' => 'asset.extract', 'label' => 'استخراج سخت‌افزار (همین سرور)'],
                ['code' => 'asset.remote_extract', 'label' => 'ویرایش'],
                ['code' => 'asset.history', 'label' => 'تاریخچهٔ دارایی'],
                ['code' => 'asset.ping', 'label' => 'پینگ سیستم‌های انتخابی'],
                ['code' => 'inventory.view', 'label' => 'فهرست موجودی'],
                ['code' => 'inventory.diagnostics', 'label' => 'تشخیص موجودی'],
                ['code' => 'domain.import', 'label' => 'ورود کامپیوترهای دامنه'],
                ['code' => 'domain.scan', 'label' => 'اسکن دامنه'],
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'گزارش و تحلیل',
            'items' => [
                ['code' => 'reports.view', 'label' => 'گزارش‌ها'],
                ['code' => 'analytics.view', 'label' => 'چارت‌ها'],
                ['code' => 'audit.view', 'label' => 'ممیزی'],
                ['code' => 'logs.view', 'label' => 'گزارش خطا'],
                ['code' => 'logs.activity', 'label' => 'فعالیت کاربران'],
                ['code' => 'logs.activity_export', 'label' => 'خروجی فعالیت کاربران'],
            ],
        ],
        [
            'key' => 'food',
            'label' => 'چاپ فیش غذا',
            'items' => [
                ['code' => 'food.dashboard', 'label' => 'داشبورد'],
                ['code' => 'food.monitor', 'label' => 'پایش لحظه‌ای'],
                ['code' => 'food.monitor_edit', 'label' => 'ویرایش'],
                ['code' => 'food.reports', 'label' => 'گزارش‌ها'],
                ['code' => 'food.employees', 'label' => 'کارکنان'],
                ['code' => 'food.employees_edit', 'label' => 'ویرایش'],
                ['code' => 'food.guest', 'label' => 'کارت مهمان'],
                ['code' => 'food.guest_edit', 'label' => 'ویرایش'],
                ['code' => 'food.groups', 'label' => 'گروه‌های غذا (نماینده با L_UID و غیبت روزانه)'],
                ['code' => 'food.groups_edit', 'label' => 'ویرایش'],
                ['code' => 'food.groups_override', 'label' => 'اصلاح غیبت بعد از قفل شدن (Audit)'],
                ['code' => 'food.db', 'label' => 'اتصال پایگاه‌ها'],
                ['code' => 'food.db_edit', 'label' => 'ویرایش'],
                ['code' => 'food.printer', 'label' => 'چاپگر سیستم'],
                ['code' => 'food.printer_edit', 'label' => 'ویرایش'],
                ['code' => 'food.design', 'label' => 'طراحی فیش'],
                ['code' => 'food.design_edit', 'label' => 'ویرایش'],
                ['code' => 'food.health', 'label' => 'سلامت سیستم'],
                ['code' => 'food.health_edit', 'label' => 'ویرایش'],
                ['code' => 'food.menu', 'label' => 'برنامه غذایی و بانک غذا'],
                ['code' => 'food.menu_edit', 'label' => 'ویرایش'],
                ['code' => 'food.order_close', 'label' => 'بستن/بازکردن روز و مشاهدهٔ همهٔ سفارش‌ها'],
            ],
        ],
        [
            'key' => 'foodorder',
            'label' => 'سفارش غذای کارکنان',
            'items' => [
                ['code' => 'foodorder.self', 'label' => 'ثبت و مشاهدهٔ سفارش خود'],
                ['code' => 'foodorder.proxy', 'label' => 'ثبت سفارش برای کارکنان دیگر'],
            ],
        ],
        [
            'key' => 'org',
            'label' => 'سازمان',
            'items' => [
                ['code' => 'org.view', 'label' => 'مشاهدهٔ چارت سازمانی'],
                ['code' => 'org.manage', 'label' => 'ویرایش'],
            ],
        ],
        [
            'key' => 'system',
            'label' => 'تنظیمات و سیستم',
            'items' => [
                ['code' => 'settings.general', 'label' => 'تنظیمات عمومی'],
                ['code' => 'settings.general_edit', 'label' => 'ویرایش'],
                ['code' => 'settings.domain', 'label' => 'اتصال دامین و اسکن دامنه'],
                ['code' => 'settings.domain_edit', 'label' => 'ویرایش'],
                ['code' => 'settings.users', 'label' => 'مدیریت کاربران و دسترسی‌ها'],
                ['code' => 'settings.users_edit', 'label' => 'ویرایش'],
                ['code' => 'settings.key_roles', 'label' => 'نقش‌های کلیدی'],
                ['code' => 'settings.key_roles_edit', 'label' => 'ویرایش'],
                ['code' => 'backup.view', 'label' => 'پشتیبان‌گیری/بازگردانی'],
                ['code' => 'backup.manage', 'label' => 'ویرایش'],
                ['code' => 'ola.view', 'label' => 'تنظیمات OLA/SLA'],
                ['code' => 'ola.manage', 'label' => 'ویرایش'],
                ['code' => 'governance.view', 'label' => 'تغییر و مشکل'],
                ['code' => 'governance.manage', 'label' => 'ویرایش'],
                ['code' => 'holidays.view', 'label' => 'مدیریت تعطیلات'],
                ['code' => 'holidays.manage', 'label' => 'ویرایش'],
            ],
        ],
    ];
}

function permission_all_codes(): array
{
    $codes = [];
    foreach (permission_catalog() as $group) {
        foreach ($group['items'] as $item) {
            $codes[] = $item['code'];
        }
    }
    return $codes;
}

/**
 * نقشهٔ «بخش ← ویرایش»: کد ویرایش فرزند کد مشاهدهٔ همان بخش است.
 * فرزند فقط وقتی معنا دارد که والدش فعال باشد؛ با تیک بخش، ویرایش به‌صورت پیش‌فرض فعال می‌شود.
 */
function permission_edit_map(): array
{
    return [
        'services.view' => 'services.manage',
        'knowledge.view' => 'knowledge.manage',
        'cddvd.view' => 'cddvd.edit',
        'traffic.view' => 'traffic.manage',
        'asset.view' => 'asset.edit',
        'org.view' => 'org.manage',
        'food.groups' => 'food.groups_edit',
        'food.monitor' => 'food.monitor_edit',
        'food.employees' => 'food.employees_edit',
        'food.guest' => 'food.guest_edit',
        'food.db' => 'food.db_edit',
        'food.printer' => 'food.printer_edit',
        'food.design' => 'food.design_edit',
        'food.health' => 'food.health_edit',
        'food.menu' => 'food.menu_edit',
        'ola.view' => 'ola.manage',
        'governance.view' => 'governance.manage',
        'holidays.view' => 'holidays.manage',
        'backup.view' => 'backup.manage',
        'settings.general' => 'settings.general_edit',
        'settings.domain' => 'settings.domain_edit',
        'settings.users' => 'settings.users_edit',
        'settings.key_roles' => 'settings.key_roles_edit',
        'ticket.view_unit' => 'ticket.edit',
        'queue.view' => 'ticket.bulk',
        'supervisor.panel' => 'supervisor.decide',
        'domain.scan' => 'asset.remote_extract',
    ];
}

/**
 * بخش‌هایی که قبلاً نوشتن‌شان فقط با کد بخش کنترل می‌شد. کد ویرایش تازه‌شان به نقش‌هایی که بخش را دارند
 * داده می‌شود تا دسترسی قبلی حفظ شود (پیش‌فرض‌ها و داده‌های ذخیره‌شده).
 */
function permission_legacy_parent_edit_pairs(): array
{
    return [
        'food.groups' => 'food.groups_edit',
        'food.monitor' => 'food.monitor_edit',
        'food.employees' => 'food.employees_edit',
        'food.guest' => 'food.guest_edit',
        'food.db' => 'food.db_edit',
        'food.printer' => 'food.printer_edit',
        'food.design' => 'food.design_edit',
        'food.health' => 'food.health_edit',
        'food.menu' => 'food.menu_edit',
        'settings.general' => 'settings.general_edit',
        'settings.domain' => 'settings.domain_edit',
        'settings.users' => 'settings.users_edit',
        'settings.key_roles' => 'settings.key_roles_edit',
        'domain.scan' => 'asset.remote_extract',
    ];
}

/** اگر کد ویرایش داخل مجموعه باشد ولی بخش (والد) نباشد، والد هم اضافه می‌شود (حفظ دسترسی‌های قبلی). */
function role_permissions_add_parents(array $codes): array
{
    $set = array_fill_keys($codes, true);
    foreach (permission_edit_map() as $parent => $child) {
        if (isset($set[$child])) {
            $set[$parent] = true;
        }
    }
    return array_keys($set);
}

/** ویرایشِ بدون بخش حذف می‌شود؛ ویرایش بی‌والد معنی ندارد. */
function role_permissions_strip_orphan_edits(array $codes): array
{
    $set = array_fill_keys($codes, true);
    foreach (permission_edit_map() as $parent => $child) {
        if (!isset($set[$parent])) {
            unset($set[$child]);
        }
    }
    return array_keys($set);
}

/**
 * Default permissions per role. primary_admin implicitly has all.
 */
/** نقش‌هایی که به ماژول چاپ فیش دسترسی دارند؛ بقیه هیچ کد food.* ندارند. */
function print_fish_allowed_roles(): array
{
    return ['primary_admin', 'support_manager', 'inspector'];
}

/** پیش‌فرض کدهای چاپ فیش برای نقش‌های غیرادمین مجاز. ادمین اصلی همهٔ کدها را دارد. */
function print_fish_role_targets(): array
{
    return [
        // مدیر پشتیبانی: داشبورد، پایش، گزارش، کارکنان، گروه‌ها، برنامهٔ غذایی و بستن روز؛ بدون تنظیمات.
        'support_manager' => ['food.dashboard', 'food.monitor', 'food.monitor_edit', 'food.reports', 'food.employees', 'food.employees_edit', 'food.groups', 'food.groups_edit', 'food.groups_override', 'food.menu', 'food.menu_edit', 'food.order_close'],
        // بازرس: فقط داشبورد و مشاهدهٔ گروه‌های غذا.
        'inspector' => ['food.dashboard', 'food.groups'],
    ];
}

/** کدهای چاپ فیش یک نقش را با پیش‌فرض‌های مجاز جایگزین می‌کند (ادمین اصلی دست‌نخورده). */
function print_fish_role_codes(string $role, array $codes): array
{
    if ($role === 'primary_admin') {
        return $codes;
    }
    $kept = array_values(array_filter($codes, static fn ($c): bool => !str_starts_with((string) $c, 'food.')));
    $targets = print_fish_role_targets();
    return array_values(array_unique(array_merge($kept, $targets[$role] ?? [])));
}

function permission_defaults(): array
{
    $defaults = permission_defaults_raw();
    foreach ($defaults as $role => $codes) {
        $codes = role_permissions_normalize_codes($codes);
        // ویرایشِ بی‌والد نباشد؛ دسترسی‌های پیش‌فرض دیگری اضافه نمی‌شود.
        $codes = role_permissions_add_parents($codes);
        // ویرایش تیکت: پیش‌تر کسانی تیکت را تغییر می‌دادند که مدیر ارجاع (ticket.assign) یا کارشناس قابل ارجاع بودند.
        if (in_array('ticket.assign', $codes, true) || in_array($role, assignable_role_codes(), true)) {
            $codes[] = 'ticket.edit';
        }
        // بخش‌های قبلاً بی‌ویرایش: هرکس بخش را داشت، کارهای ثبتی‌اش را هم داشت؛ پس ویرایش‌شان هم داده می‌شود.        // بخش‌های قبلاً بی‌ویرایش: هرکس بخش را داشت، کارهای ثبتی‌اش را هم داشت؛ پس ویرایششان هم داده می‌شود.
        foreach (permission_legacy_parent_edit_pairs() as $legacyParent => $legacyChild) {
            if (in_array($legacyParent, $codes, true)) {
                $codes[] = $legacyChild;
            }
        }
        // بعد از افزودن ویرایش‌های قدیمی هم فیلتر می‌شود تا چیزی از چاپ فیش به نقش‌های غیرمجاز نرسد.
        $codes = print_fish_role_codes($role, $codes);
        $defaults[$role] = array_values(array_unique($codes));
    }
    return $defaults;
}

/** کدهایی که از پیش‌فرض نقش user برداشته شدند (تصمیم کاربر). */
function user_default_removed_codes(): array
{
    return ['cddvd.view', 'cddvd.submit_out', 'traffic.view', 'food.dashboard', 'food.monitor', 'food.orders', 'food.reports', 'food.monitor_edit', 'food.orders_edit', 'governance.view', 'governance.manage'];
}

function permission_defaults_raw(): array
{
    $all = ['dash.view', 'search.global', 'notif.view', 'profile.edit', 'ticket.create', 'ticket.view_own', 'ticket.reply', 'ticket.rate', 'services.view', 'knowledge.view', 'cddvd.view', 'cddvd.submit_out', 'traffic.view', 'food.dashboard', 'food.monitor', 'food.orders', 'food.reports', 'foodorder.self', 'org.view', 'governance.manage'];
    $common = array_merge($all, ['ticket.view_unit', 'queue.view', 'assets.view', 'assets.own_unit', 'asset.view', 'asset.history', 'reports.view', 'inventory.view', 'cddvd.history', 'cddvd.export', 'traffic.manage']);
    return [
        'primary_admin' => permission_all_codes(),
        // ۱.۳۷.۷ — پیش‌فرض نقش قدیمی «admin» (قابل ویرایش از تنظیمات ← نقش‌ها).
        'supervisor' => array_values(array_unique(array_merge($common, ['ticket.view_all', 'ticket.edit', 'supervisor.panel', 'supervisor.decide', 'supervisor.reopen', 'analytics.view', 'asset.edit', 'asset.extract', 'asset.ping', 'domain.import', 'domain.scan', 'knowledge.manage', 'cddvd.edit', 'cddvd.view_all', 'traffic.destinations', 'logs.activity', 'food.dashboard', 'food.monitor', 'food.orders', 'food.reports', 'food.employees', 'food.guest', 'food.groups', 'food.groups_override']))),
        'support_manager' => array_values(array_unique(array_merge($common, ['ticket.view_all', 'ticket.edit', 'ticket.assign', 'ticket.bulk', 'ticket.attach_asset', 'services.manage', 'service_cat.manage', 'service_field.manage', 'knowledge.manage', 'cddvd.edit', 'cddvd.view_all', 'traffic.destinations', 'analytics.view', 'asset.edit', 'asset.extract', 'asset.ping', 'asset.remote_extract', 'inventory.diagnostics', 'domain.import', 'domain.scan', 'audit.view', 'logs.view', 'logs.activity', 'logs.activity_export', 'food.dashboard', 'food.monitor', 'food.orders', 'food.reports', 'food.employees', 'food.guest', 'food.groups', 'food.groups_override', 'food.menu', 'food.order_close', 'settings.general', 'settings.domain', 'holidays.manage']))),
        'inspector' => array_values(array_unique(array_merge($all, ['ticket.view_unit', 'ticket.view_all', 'ticket.edit', 'queue.view', 'assets.view', 'asset.view', 'asset.history', 'asset.ping', 'reports.view', 'analytics.view', 'audit.view', 'inventory.view', 'cddvd.submit_in', 'cddvd.view_all', 'cddvd.history', 'cddvd.export', 'traffic.manage']))),
        'manager' => array_values(array_unique(array_merge($common, ['ticket.edit', 'ticket.assign', 'ticket.bulk', 'ticket.attach_asset', 'knowledge.manage', 'asset.edit', 'asset.extract', 'asset.ping', 'analytics.view']))),
        'agent' => array_values(array_unique(array_merge($common, ['ticket.attach_asset', 'knowledge.manage', 'asset.edit', 'asset.extract', 'asset.ping']))),
        // کاربر عادی (از جمله کاربران AD): بخش‌های چاپ فیش، نظارت و سیاست‌های دارایی/ترافیک ندارد.
        // «سفارش غذای خود» (foodorder.self) عمداً باقی است تا کاربر بتواند برای خودش سفارش دهد.
        'user' => array_values(array_diff($all, user_default_removed_codes())),
    ];
}

function permission_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT 1 FROM role_permissions LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Stored permission codes for a role (fallback to defaults when table missing/empty).
 */
/**
 * ۱.۳۷.۷ — قانون «قابل‌مدیریت» دسترسی نقش‌ها (تصمیم کاربر، ۲۰۲۶-۱۰-۰۶):
 *   • تا وقتی نقش از فرم «نقش‌ها و دسترسی‌ها» ذخیره نشده ⇒ پیش‌فرض‌های همین فایل اعمال می‌شود
 *     (تغییر پیش‌فرض‌ها در نسخه‌های بعدی خودکار به این نقش‌ها می‌رسد).
 *   • به‌محض «ذخیره» از فرم ⇒ دقیقاً همان مجموعه اولویت دارد؛ حتی اگر خالی باشد یا بعداً
 *     پیش‌فرض کد عوض شود.
 *   • «بازگردانی به پیش‌فرض کد» نقش را دوباره دست‌نخورده می‌کند.
 * نشانهٔ «دست‌خورده» در جدول settings با کلید role_permissions_custom_<role> نگه داشته می‌شود؛
 * هیچ ستون یا جدول تازه‌ای ساخته نمی‌شود.
 */
function role_permissions_custom(string $role): bool
{
    if (!function_exists('setting')) {
        return false;
    }
    return setting('role_permissions_custom_' . $role) === '1';
}

function role_permissions_mark_custom(string $role, bool $custom = true): void
{
    if (!function_exists('save_setting')) {
        return;
    }
    save_setting('role_permissions_custom_' . $role, $custom ? '1' : '0');
}

function role_permissions_normalize_codes(array $codes): array
{
    $codes = array_map('strval', $codes);
    return array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== '')));
}

/**
 * مجموعهٔ مؤثر یک نقش (تابع خالص؛ بدون دیتابیس قابل آزمون است).
 */
function role_permission_effective_codes(string $role, array $storedCodes, bool $custom): array
{
    if ($custom) {
        // نقش‌های ذخیره‌شده: ویرایش بدون بخش (داده‌های قدیمی) بخش را هم می‌گیرد تا دسترسی قبلی حفظ شود.
        return role_permissions_add_parents(role_permissions_normalize_codes($storedCodes));
    }
    $defaults = permission_defaults();
    return role_permissions_normalize_codes($defaults[$role] ?? []);
}

/** مقایسهٔ مجموعه‌ای دو فهرست کد؛ ترتیب و تکرار مهم نیست. */
function role_permissions_same_set(array $a, array $b): bool
{
    $a = role_permissions_normalize_codes($a);
    $b = role_permissions_normalize_codes($b);
    sort($a);
    sort($b);
    return $a === $b;
}

/** پاک‌کردن کش درون‌درخواستی (بعد از ذخیره/بازگردانی). */
function role_permissions_flush_cache(?string $role = null): void
{
    if ($role === null) {
        $GLOBALS['__role_permission_codes'] = [];
        $GLOBALS['__role_permission_sets'] = [];
        return;
    }
    unset($GLOBALS['__role_permission_codes'][$role], $GLOBALS['__role_permission_sets'][$role]);
}

/**
 * مهاجرت یک‌باره: نقش‌هایی که «گروه‌های غذا» را داشتند، ویرایش آن را هم می‌گیرند (دسترسی قبلی حفظ شود).
 */
function role_permissions_migrate_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!permission_table_ready() || !function_exists('setting') || !function_exists('save_setting')) {
        return;
    }
    if (setting('print_fish_roles_v1') !== '1') {
        // چاپ فیش فقط برای نقش‌های مجاز. نقش‌های دست‌نخورده از پیش‌فرض تازه می‌گیرند؛
        // نقش‌های ویرایش‌شده فقط کدهای food.* غیرمجاز را از دست می‌دهند.
        try {
            db()->exec("DELETE FROM role_permissions WHERE permission IN ('food.orders', 'food.orders_edit')");
            foreach (array_keys(permission_roles()) as $role) {
                if ($role === 'primary_admin') {
                    continue;
                }
                $allowed = in_array($role, print_fish_allowed_roles(), true);
                if (!role_permissions_custom($role)) {
                    db()->prepare('DELETE FROM role_permissions WHERE role = ?')->execute([$role]);
                } elseif (!$allowed) {
                    db()->prepare("DELETE FROM role_permissions WHERE role = ? AND permission LIKE 'food.%'")->execute([$role]);
                }
            }
            save_setting('print_fish_roles_v1', '1');
            role_permissions_flush_cache();
        } catch (Throwable $exception) {
            // دفعهٔ بعد دوباره تلاش می‌شود.
        }
    }
    if (setting('user_defaults_narrowed_v1') !== '1') {
        // ردیف‌های ذخیره‌شدهٔ نقش user هم باید همین تغییر را ببینند. اگر ادمین آن را دستی تنظیم کرده باشد، دست نمی‌خورد.
        try {
            if (setting('role_permissions_custom_user') !== '1') {
                $removed = user_default_removed_codes();
                $placeholders = implode(',', array_fill(0, count($removed), '?'));
                $delete = db()->prepare("DELETE FROM role_permissions WHERE role = 'user' AND permission IN ($placeholders)");
                $delete->execute($removed);
                save_setting('user_defaults_narrowed_report', (string) $delete->rowCount());
            } else {
                save_setting('user_defaults_narrowed_report', 'custom');
            }
            save_setting('user_defaults_narrowed_v1', '1');
            role_permissions_flush_cache();
        } catch (Throwable $exception) {
            // دفعهٔ بعد دوباره تلاش می‌شود.
        }
    }
    if (setting('legacy_admin_removed_v1') !== '1') {
        // نقش قدیمی «admin» حذف شد. ادمین اصلی به primary_admin می‌رود و بقیه به supervisor.
        // هیچ ادمینی بی‌صدا تنزل نمی‌یابد؛ تعداد هر گروه در تنظیمات ثبت می‌شود.
        try {
            $countPrimary = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_primary_admin = 1")->fetchColumn();
            $countOther = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND (is_primary_admin = 0 OR is_primary_admin IS NULL)")->fetchColumn();
            db()->exec("UPDATE users SET role = 'primary_admin', is_primary_admin = 1 WHERE role = 'admin' AND is_primary_admin = 1");
            db()->exec("UPDATE users SET role = 'supervisor' WHERE role = 'admin'");
            db()->exec("DELETE FROM role_permissions WHERE role = 'admin'");
            save_setting('legacy_admin_removed_report', json_encode(['to_primary_admin' => $countPrimary, 'to_supervisor' => $countOther], JSON_UNESCAPED_UNICODE));
            save_setting('legacy_admin_removed_v1', '1');
            role_permissions_flush_cache();
        } catch (Throwable $exception) {
            // دفعهٔ بعد دوباره تلاش می‌شود.
        }
    }
    if (setting('perm_edit_v3') !== '1') {
        try {
            // پاسخ به تیکت پیش‌تر با کد کنترل نمی‌شد؛ پس به هر نقش ذخیره‌شده‌ای داده می‌شود تا دسترسی حفظ شود.
            db()->exec("INSERT IGNORE INTO role_permissions (role, permission) SELECT DISTINCT role, 'ticket.reply' FROM role_permissions");
            // امتیازدهی به تیکت هم پیش‌تر کد نداشت؛ به همهٔ نقش‌های ذخیره‌شده داده می‌شود.
            db()->exec("INSERT IGNORE INTO role_permissions (role, permission) SELECT DISTINCT role, 'ticket.rate' FROM role_permissions");
            // داشبورد پیش‌تر همیشه باز بود؛ کد دیدنش به همهٔ نقش‌های ذخیره‌شده داده می‌شود.
            db()->exec("INSERT IGNORE INTO role_permissions (role, permission) SELECT DISTINCT role, 'dash.view' FROM role_permissions");
            // اسکن دامنه پیش‌تر استخراج از راه دور هم بود؛ همان دسترسی برای کد ویرایش حفظ می‌شود.
            db()->exec("INSERT IGNORE INTO role_permissions (role, permission) SELECT DISTINCT role, 'asset.remote_extract' FROM role_permissions WHERE permission = 'domain.scan'");
            $assignableList = implode(',', array_map(static fn (string $r): string => db()->quote($r), assignable_role_codes()));
            db()->exec("INSERT IGNORE INTO role_permissions (role, permission) SELECT DISTINCT role, 'ticket.edit' FROM role_permissions WHERE permission = 'ticket.assign' OR role IN ($assignableList)");
            save_setting('perm_edit_v3', '1');
            role_permissions_flush_cache();
        } catch (Throwable $exception) {
            // دفعهٔ بعد دوباره تلاش می‌شود.
        }
    }
    if (setting('perm_edit_v2') === '1') {
        return;
    }
    try {
        foreach (permission_legacy_parent_edit_pairs() as $legacyParent => $legacyChild) {
            $copy = db()->prepare("INSERT IGNORE INTO role_permissions (role, permission) SELECT role, ? FROM role_permissions WHERE permission = ?");
            $copy->execute([$legacyChild, $legacyParent]);
        }
        save_setting('perm_edit_v2', '1');
        role_permissions_flush_cache();
    } catch (Throwable $exception) {
        // در صورت خطا دفعهٔ بعد دوباره تلاش می‌شود.
    }
}

function role_permission_codes(string $role): array
{
    role_permissions_migrate_once();
    $role = permission_roles()[$role] ?? null ? $role : 'user';
    if (!isset($GLOBALS['__role_permission_codes']) || !is_array($GLOBALS['__role_permission_codes'])) {
        $GLOBALS['__role_permission_codes'] = [];
    }
    if (!array_key_exists($role, $GLOBALS['__role_permission_codes'])) {
        $stored = [];
        if (permission_table_ready()) {
            try {
                $query = db()->prepare('SELECT permission FROM role_permissions WHERE role = ?');
                $query->execute([$role]);
                $stored = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $exception) {
                $stored = [];
            }
        }
        $GLOBALS['__role_permission_codes'][$role] = role_permission_effective_codes($role, $stored, role_permissions_custom($role));
    }
    return $GLOBALS['__role_permission_codes'][$role];
}

/**
 * نسخهٔ هش‌ست مجوزهای نقش برای بررسی سریع user_can (به‌جای in_array خطی).
 */
function role_permission_set(string $role): array
{
    $role = permission_roles()[$role] ?? null ? $role : 'user';
    if (!isset($GLOBALS['__role_permission_sets']) || !is_array($GLOBALS['__role_permission_sets'])) {
        $GLOBALS['__role_permission_sets'] = [];
    }
    if (!array_key_exists($role, $GLOBALS['__role_permission_sets'])) {
        $GLOBALS['__role_permission_sets'][$role] = array_fill_keys(role_permission_codes($role), true);
    }
    return $GLOBALS['__role_permission_sets'][$role];
}

function role_permissions_seeded(): bool
{
    if (!permission_table_ready()) {
        return false;
    }
    try {
        return (int) db()->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * ۱.۳۷.۷ — پیش‌فرض‌های کد دیگر داخل جدول role_permissions ریخته نمی‌شوند.
 * دلیل: با ریختن پیش‌فرض‌ها در جدول، همهٔ نقش‌ها از همان نصب اول «دست‌خورده» حساب می‌شدند و
 * از آن به بعد هیچ تغییر پیش‌فرضی در نسخه‌های بعدی به آن‌ها نمی‌رسید.
 *
 * این تابع فقط یک‌بار وضعیت نصب‌های قبلی را آشتی می‌دهد: اگر دسترسی‌های ذخیره‌شدهٔ یک نقش با
 * پیش‌فرض کد تفاوت داشته باشد (یعنی قبلاً دستی ویرایش شده)، همان نقش «ویرایش‌شده» علامت می‌خورد
 * تا تغییرش حفظ شود. نقش‌هایی که تفاوتی ندارند، دست‌نخورده می‌مانند و پیش‌فرض‌های آینده به آن‌ها می‌رسد.
 */
function role_permissions_seed(bool $force = false): void
{
    if (!permission_table_ready()) {
        return;
    }
    if (!$force && function_exists('setting') && setting('role_permissions_reconciled') === '2026-10-07') {
        return;
    }
    foreach (array_keys(permission_roles()) as $role) {
        if ($role === 'primary_admin' || role_permissions_custom($role)) {
            continue;
        }
        $stored = [];
        try {
            $query = db()->prepare('SELECT permission FROM role_permissions WHERE role = ?');
            $query->execute([$role]);
            $stored = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $exception) {
            $stored = [];
        }
        if ($stored !== [] && !role_permissions_same_set($stored, role_permission_effective_codes($role, [], false))) {
            role_permissions_mark_custom($role, true);
        }
    }
    save_setting('role_permissions_reconciled', '2026-10-07');
}

/**
 * ذخیرهٔ دسترسی‌های یک نقش از فرم. از این پس تا وقتی «بازگردانی به پیش‌فرض» زده نشود،
 * همین مجموعه اولویت دارد.
 */
function role_permissions_save(string $role, array $codes): void
{
    // چاپ فیش فقط برای نقش‌های مجاز است.
    if (!in_array($role, print_fish_allowed_roles(), true)) {
        $codes = array_values(array_filter($codes, static fn ($c): bool => !str_starts_with((string) $c, 'food.')));
    }
    if (!isset(permission_roles()[$role])) {
        return;
    }
    $valid = permission_all_codes();
    $codes = role_permissions_normalize_codes($codes);
    $codes = array_values(array_intersect($codes, $valid));
    // ویرایش بدون بخش ذخیره نمی‌شود.
    $codes = role_permissions_strip_orphan_edits($codes);
    if ($role === 'primary_admin') {
        $codes = $valid;
    }
    if (permission_table_ready()) {
        $delete = db()->prepare('DELETE FROM role_permissions WHERE role = ?');
        $delete->execute([$role]);
        $insert = db()->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
        foreach ($codes as $code) {
            $insert->execute([$role, $code]);
        }
    }
    // اگر مجموعه دقیقاً همان پیش‌فرض کد شد، نقش دوباره «دست‌نخورده» می‌ماند تا پیش‌فرض‌های
    // نسخه‌های بعدی خودکار به آن برسد.
    role_permissions_mark_custom($role, !role_permissions_same_set($codes, role_permission_effective_codes($role, [], false)));
    role_permissions_flush_cache($role);
}

/**
 * ۱.۳۷.۷ — بازگرداندن یک نقش به پیش‌فرض‌های داخل کد.
 */
function role_permissions_reset(string $role): void
{
    if (!isset(permission_roles()[$role])) {
        return;
    }
    $defaults = role_permission_effective_codes($role, [], false);
    if (permission_table_ready()) {
        $delete = db()->prepare('DELETE FROM role_permissions WHERE role = ?');
        $delete->execute([$role]);
        $insert = db()->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
        foreach ($defaults as $code) {
            $insert->execute([$role, $code]);
        }
    }
    role_permissions_mark_custom($role, false);
    role_permissions_flush_cache($role);
}

function user_role_code(array $user): string
{
    return (string) ($user['role'] ?? 'user');
}

function user_is_primary_admin(array $user): bool
{
    return user_role_code($user) === 'primary_admin'
        || (user_role_code($user) === 'admin' && (int) ($user['is_primary_admin'] ?? 0) === 1);
}

/**
 * True when the user's role has the given permission.
 * primary_admin (and admin+is_primary_admin) always pass.
 */
function user_can(array $user, string $permission): bool
{
    if (!$user) {
        return false;
    }
    if (user_is_primary_admin($user)) {
        return true;
    }
    return isset(role_permission_set(user_role_code($user))[$permission]);
}

function user_can_any(array $user, array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (user_can($user, $permission)) {
            return true;
        }
    }
    return false;
}

/** Requires an authenticated user with the given permission, else 403. */
function require_permission(string $permission): array
{
    $user = require_login();
    if (!user_can($user, $permission)) {
        http_response_code(403);
        exit('دسترسی به این بخش مجاز نیست.');
    }
    return $user;
}

function require_any_permission(array $permissions): array
{
    $user = require_login();
    if (!user_can_any($user, $permissions)) {
        http_response_code(403);
        exit('دسترسی به این بخش مجاز نیست.');
    }
    return $user;
}

/**
 * Inline SVG icon for role/group cards. Keeps the panel look consistent
 * with the organization page (no emoji).
 */
function permission_icon(string $key): string
{
    $paths = [
        'owner' => '<path d="M12 3l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 8.7l5.4-.8z"/>',
        'supervisor' => '<path d="M12 3l7 3v5c0 4.4-2.9 8.3-7 9.5C7.9 19.3 5 15.4 5 11V6z"/><path d="M9.5 11.5l1.8 1.8 3.4-3.6"/>',
        'manager' => '<path d="M4 20v-1.5a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4V20"/><circle cx="12" cy="8" r="3.4"/>',
        'inspector' => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-3.6-3.6"/>',
        'expert' => '<path d="M14.5 5.5l4 4-8.5 8.5H6v-4z"/><path d="M13 7l4 4"/>',
        'user' => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20v-1.5A4.5 4.5 0 0 1 9.5 14h5A4.5 4.5 0 0 1 19 18.5V20"/>',
        'g-base' => '<rect x="4" y="4" width="7" height="7" rx="1.6"/><rect x="13" y="4" width="7" height="7" rx="1.6"/><rect x="4" y="13" width="7" height="7" rx="1.6"/><rect x="13" y="13" width="7" height="7" rx="1.6"/>',
        'g-ticket' => '<path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2.4 2.4 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2.4 2.4 0 0 0 0-6z"/><path d="M12 7v10"/>',
        'g-services' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
        'g-knowledge' => '<path d="M4 6a2 2 0 0 1 2-2h5v16H6a2 2 0 0 1-2-2z"/><path d="M20 6a2 2 0 0 0-2-2h-5v16h5a2 2 0 0 0 2-2z"/>',
        'g-cddvd' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2.6"/>',
        'g-traffic' => '<path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M4 21h16"/><circle cx="12" cy="9" r="2.4"/><path d="M9 16.5c0-1.7 1.3-2.8 3-2.8s3 1.1 3 2.8"/>',
        'g-assets' => '<rect x="4" y="5" width="16" height="11" rx="2"/><path d="M2 19h20"/>',
        'g-reports' => '<path d="M4 20V6M10 20V10M16 20v-7M22 20H2"/>',
        'g-food' => '<path d="M5 3v8a3 3 0 0 0 6 0V3"/><path d="M8 11v10"/><path d="M17 3c-1.7 1-2.5 3-2.5 5.5S15 13 17 13v8"/>',
        'g-org' => '<rect x="9" y="3" width="6" height="5" rx="1.4"/><rect x="3" y="16" width="6" height="5" rx="1.4"/><rect x="15" y="16" width="6" height="5" rx="1.4"/><path d="M12 8v4M6 16v-4h12v4"/>',
        'g-system' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6L17 7M7 17l-1.4 1.4"/>',
    ];
    $path = $paths[$key] ?? $paths['user'];
    return '<svg class="perm-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

/** Roles treated as staff (see queue/assignee logic). */
function staff_role_codes(): array
{
    return ['agent', 'manager', 'supervisor', 'primary_admin', 'support_manager', 'inspector'];
}

function is_staff_role(?string $role): bool
{
    return in_array((string) $role, staff_role_codes(), true);
}

/** Roles that can administer the whole system. */
function admin_role_codes(): array
{
    return ['primary_admin'];
}

function is_admin_role(?string $role): bool
{
    return in_array((string) $role, admin_role_codes(), true);
}

/** Roles allowed to see/handle the whole ticket pool. */
function global_ticket_role_codes(): array
{
    return ['supervisor', 'primary_admin', 'support_manager', 'inspector'];
}

function is_global_ticket_role(?string $role): bool
{
    return in_array((string) $role, global_ticket_role_codes(), true);
}

/** Roles scoped to their own unit/department (not the whole pool). */
function is_department_scoped_role(?string $role): bool
{
    return in_array((string) $role, ['manager', 'support_manager'], true);
}

/** Roles that may be assigned tickets (ticket handlers). */
function assignable_role_codes(): array
{
    return ['agent', 'manager', 'support_manager', 'primary_admin'];
}

function is_assignable_role(?string $role): bool
{
    return in_array((string) $role, assignable_role_codes(), true);
}
