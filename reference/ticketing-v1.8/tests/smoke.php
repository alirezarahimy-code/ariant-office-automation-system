<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "FAIL: config.php پیدا نشد. ابتدا install.php را اجرا کنید.\n");
    exit(1);
}
require $root . '/bootstrap.php';

$checks = [];
$checks['اتصال PDO'] = static function (): bool { return db() instanceof PDO; };
$checks['جدول تنظیمات'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'settings'")->fetchColumn(); };
$checks['جدول واحدها'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'departments'")->fetchColumn(); };
$checks['جدول کاربران'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'users'")->fetchColumn(); };
$checks['جدول تیکت‌ها'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'tickets'")->fetchColumn(); };
$checks['جدول پیام‌ها'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'ticket_messages'")->fetchColumn(); };
$checks['جدول رضایت‌سنجی'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'ticket_ratings'")->fetchColumn(); };
$checks['جدول رویدادهای تیکت'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'ticket_events'")->fetchColumn(); };
$checks['جدول دارایی‌ها'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'assets'")->fetchColumn(); };
$checks['جدول تاریخچه دارایی'] = static function (): bool { return (bool) db()->query("SHOW TABLES LIKE 'asset_history_events'")->fetchColumn(); };
$checks['جداول Inventory ساختاریافته'] = static function (): bool {
    $tables = ['asset_memory_modules', 'asset_storage_devices', 'asset_graphics_adapters', 'asset_peripherals'];
    foreach ($tables as $table) {
        if (!(bool) db()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
            return false;
        }
    }
    return true;
};
$checks['جدول شناسنامه دارایی'] = static function (): bool {
    return (bool) db()->query("SHOW TABLES LIKE 'asset_profiles'")->fetchColumn();
};
$checks['سکشن‌ها و ستون‌های فرم شناسنامه'] = static function () use ($root): bool {
    require_once $root . '/asset-profile.php';
    $sections = asset_profile_sections();
    return isset($sections['g_plaque'], $sections['meta'], $sections['a_user'], $sections['b_os'], $sections['c_hw'], $sections['d_net'], $sections['e_peripheral'], $sections['f_case'])
        && count(asset_profile_all_columns()) >= 60;
};
$checks['جداول عملیات آفلاین'] = static function (): bool {
    $tables = ['handling_units', 'service_catalog', 'service_catalog_fields', 'notifications', 'login_attempts', 'ticket_sla_alerts', 'knowledge_articles', 'holidays', 'asset_relations', 'activity_logs', 'traffic_visits', 'traffic_destinations'];
    foreach ($tables as $table) {
        if (!(bool) db()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
            return false;
        }
    }
    return true;
};
$checks['جداول حاکمیت ITSM'] = static function (): bool {
    $tables = ['change_records', 'change_approvals', 'change_ticket_links', 'problem_records', 'problem_ticket_links', 'ola_policies', 'ticket_ola_alerts'];
    foreach ($tables as $table) {
        if (!(bool) db()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
            return false;
        }
    }
    return true;
};
$checks['جداول کنترل CD/DVD'] = static function (): bool {
    foreach (['cd_dvd_types', 'cd_dvd_records'] as $table) {
        if (!(bool) db()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
            return false;
        }
    }
    return true;
};
$checks['جداول چاپ فیش غذا'] = static function (): bool {
    foreach (['food_ticket_config', 'food_ticket_events', 'food_ticket_guest_cards'] as $table) {
        if (!(bool) db()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn()) {
            return false;
        }
    }
    return true;
};
$checks['ستون کد ملی کاربران'] = static function (): bool {
    foreach (db()->query('SHOW COLUMNS FROM users')->fetchAll() as $column) {
        if ((string) $column['Field'] === 'national_code') {
            return true;
        }
    }
    return false;
};
$checks['ارتباط واردکننده CD/DVD با کاربر'] = static function (): bool {
    $columns = [];
    foreach (db()->query("SHOW COLUMNS FROM cd_dvd_records")->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }
    return isset($columns['brought_by_user_id']);
};
$checks['ساختار نهایی CD/DVD بدون فرستنده'] = static function () use ($root): bool {
    $source = (string) file_get_contents($root . '/schema.sql');
    return is_file($root . '/rebuild-final.sql') && !str_contains($source, 'sender_unit') && !str_contains((string) file_get_contents($root . '/cd-dvd.php'), 'r.sender');
};
$checks['ستون‌های نسخه ۱.۵ تیکت'] = static function (): bool {
    $columns = [];
    foreach (db()->query("SHOW COLUMNS FROM tickets")->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }
    return isset($columns['service_id'], $columns['ticket_type'], $columns['parent_ticket_id'], $columns['custom_fields'], $columns['sla_paused_at'], $columns['sla_pause_minutes']);
};
$checks['ستون‌های OLA و مسیردهی خدمات'] = static function (): bool {
    $ticketColumns = [];
    foreach (db()->query("SHOW COLUMNS FROM tickets")->fetchAll() as $column) {
        $ticketColumns[(string) $column['Field']] = true;
    }
    $serviceColumns = [];
    foreach (db()->query("SHOW COLUMNS FROM service_catalog")->fetchAll() as $column) {
        $serviceColumns[(string) $column['Field']] = true;
    }
    return isset($ticketColumns['ola_policy_id'], $ticketColumns['ola_response_due_at'], $ticketColumns['ola_due_at'], $ticketColumns['ola_escalation_1_at'], $ticketColumns['ola_escalation_2_at'], $ticketColumns['service_group'], $ticketColumns['handling_unit_id'], $ticketColumns['support_location'], $ticketColumns['support_equipment']) && isset($serviceColumns['service_group'], $serviceColumns['handling_unit_id'], $serviceColumns['requires_asset'], $serviceColumns['default_ticket_type']);
};
$checks['Job هشدار SLA'] = static function () use ($root): bool { return is_file($root . '/cron/sla_maintenance.php'); };
$checks['Job همگام‌سازی LDAP'] = static function () use ($root): bool { return is_file($root . '/cron/ldap_sync.php'); };
$checks['فیلتر LDAP فقط کاربران واقعی'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/bootstrap.php'); $sample = (string) file_get_contents($root . '/config.sample.php'); return str_contains($source, 'function ldap_person_filter') && str_contains($source, '(objectCategory=person)') && str_contains($sample, '(objectCategory=person)'); };
$checks['پاپ‌آپ اعلان داخلی'] = static function () use ($root): bool { return is_file($root . '/assets/app.js') && str_contains((string) file_get_contents($root . '/assets/app.js'), 'notification_feed'); };
$checks['صفحه پشتیبان‌گیری'] = static function () use ($root): bool { return is_file($root . '/backup.php') && str_contains((string) file_get_contents($root . '/index.php'), "page === 'backup'"); };
$checks['ماژول کنترل CD/DVD'] = static function () use ($root): bool { return is_file($root . '/cd-dvd.php') && is_file($root . '/assets/cd-dvd.js') && str_contains((string) file_get_contents($root . '/index.php'), "page === 'cd-dvd'"); };
$checks['ماژول چاپ فیش غذا'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/food-ticket.php'); $index = (string) file_get_contents($root . '/index.php'); $schema = (string) file_get_contents($root . '/schema.sql'); return is_file($root . '/food-ticket.php') && is_file($root . '/cron/food_ticket_worker.php') && is_file($root . '/upgrade-1.9-food-ticket.sql') && is_file($root . '/upgrade-1.12-food-ticket-management.sql') && is_file($root . '/upgrade-1.19-food-ticket-queue.sql') && is_file($root . '/tools/install_food_ticket_worker_windows.bat') && is_file($root . '/tools/install_food_ticket_worker.ps1') && str_contains($source, 'function food_ticket_render_page') && str_contains($source, 'food_ticket_worker_heartbeat') && str_contains($index, 'food_ticket_render_page($user') && str_contains($schema, 'food_ticket_worker_status'); };
$checks['Fallback پاورشل Access برای خواندن و حذف SOURCE_TABLE'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/food-ticket.php'); return str_contains($source, 'function food_ticket_access_delete_powershell') && str_contains($source, 'food_ticket_is_ps_access($connection)') && str_contains($source, "'columns' => \$columns") && str_contains($source, '$connection.CreateCommand()'); };
$checks['پایداری فرم کارکنان و کارت مهمان'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/food-ticket.php'); $web = (string) file_get_contents($root . '/food-ticket-web/index.html'); $asset = (string) file_get_contents($root . '/food-ticket-web/assets/food-ticket-persistence.js'); return str_contains($source, "\$route === 'employees'") && str_contains($source, "\$route === 'guest-cards'") && str_contains($source, 'food_ticket_guest_cards') && str_contains($web, 'food-ticket-persistence.js') && str_contains($asset, '/api/employees') && str_contains($asset, '/api/guest-cards'); };
$checks['پنل کامل داخل مسیر اصلی'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/food-ticket.php'); $index = (string) file_get_contents($root . '/index.php'); $web = (string) file_get_contents($root . '/food-ticket-web/index.html'); $brand = (string) file_get_contents($root . '/food-ticket-web/assets/food-brand.js'); return str_contains($source, 'FOOD_TICKET_API_BASE') && str_contains($source, 'food_ticket_api_handle') && str_contains($source, 'food-ticket-persistence.js') && str_contains($index, "isset(\$_GET['food_api'])") && str_contains($web, 'const apiBase=window.FOOD_TICKET_API_BASE') && str_contains($brand, 'serviceUrl'); };
$checks['تعیین ادمین اصلی از تنظیمات'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/index.php'); return str_contains($source, 'id="users"') && str_contains($source, 'name="is_primary_admin"') && str_contains($source, 'value="admin"') && str_contains($source, '$canManageSettingsUsers'); };
$checks['تب‌های تنظیمات اصلی با گروه‌بندی و چیدمان فرم‌ها'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/index.php'); $script = (string) file_get_contents($root . '/assets/app.js'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($source, 'class="settings-tabs"') && str_contains($source, 'id="food-ticket-brand"') && str_contains($source, 'id="ldap-role-map"') && str_contains($source, 'id="ldap-sync"') && str_contains($source, 'id="cd-dvd-settings"') && str_contains($script, "['domain', 'تنظیمات دامین']") && str_contains($script, "moveInto('food-ticket-brand'") && str_contains($script, 'wrapPanelForm') && str_contains($script, "roleMap.appendChild(ldapSync)") && str_contains($script, 'panel.hidden = panel.id !== selected') && str_contains($style, '.settings-grid > #general, .settings-grid > #domain') && str_contains($style, 'grid-template-columns: repeat(2, minmax(0, 1fr))') && str_contains($style, '.settings-grid > [hidden]'); };
$checks['جست‌وجوی فوری و فعال‌سازی کاربران'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/index.php'); $script = (string) file_get_contents($root . '/assets/app.js'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($source, 'name="is_active"') && str_contains($source, 'is_active = ?') && str_contains($script, 'bindSettingsUserSearch') && str_contains($script, 'نام، نام خانوادگی یا نام کاربری') && str_contains($style, '.settings-user-search') && str_contains($style, '.category-list > [hidden]'); };
$checks['تشخیص مرحله‌ای ورود LDAP'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/bootstrap.php'); $index = (string) file_get_contents($root . '/index.php'); $upnFirst = strpos($source, '$userBindOk = $userUpn') !== false && strpos($source, '$userBindOk = $userDn') !== false && strpos($source, '$userBindOk = $userUpn') < strpos($source, '$userBindOk = $userDn'); return str_contains($source, 'function ldap_auth_log') && str_contains($source, 'LDAP_OPT_DIAGNOSTIC_MESSAGE') && str_contains($source, 'userprincipalname') && str_contains($source, 'service-bind-failed') && str_contains($source, 'direct-bind-failed') && str_contains($source, '$serviceBindFailed') && str_contains($source, 'userPrincipalName=') && str_contains($source, 'defaultNamingContext') && str_contains($source, '$domainSuffix') && str_contains($source, "(\$entry['dn'] ?? '')") && str_contains($source, "'groups' => [],") && $upnFirst && str_contains($index, 'حساب کاربری شبکه غیرفعال است'); };
$checks['مجوز و ممیزی CD/DVD'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); return str_contains($source, 'function cd_dvd_can_edit_records') && str_contains($source, "'before' => cd_dvd_audit_snapshot(\$record)") && str_contains($source, "'after' => cd_dvd_audit_snapshot(\$after)") && str_contains($source, 'request=cd_dvd_edit'); };
$checks['سطح مشترک داشبورد CD/DVD'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); return str_contains($source, 'cd_dvd_render_surface') && str_contains($source, 'رسانه‌های خارج از سازمان') && !str_contains($source, 'overdue_outgoing'); };
$checks['سه زیر‌فرم کنترل CD/DVD با نمایش تک‌فرمی'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); $script = (string) file_get_contents($root . '/assets/cd-dvd.js'); return str_contains($source, 'id="cd-dvd-dashboard"') && str_contains($source, 'id="cd-dvd-register"') && str_contains($source, 'id="cd-dvd-history"') && substr_count($source, 'class="cd-dvd-subform') >= 3 && str_contains($source, 'data-cd-dvd-tab') && str_contains($source, 'data-cd-dvd-panel') && str_contains($script, 'activateTab'); };
$checks['فیلتر CD/DVD بدون رفرش کامل'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); $script = (string) file_get_contents($root . '/assets/app.js'); return str_contains($source, 'data-cd-dvd-surface') && str_contains($source, 'data-cd-dvd-history-surface') && str_contains($script, 'bindCdDvdSurface') && str_contains($script, 'bindCdDvdHistorySurface'); };
$checks['چیدمان و عملیات سوابق CD/DVD'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); $script = (string) file_get_contents($root . '/assets/cd-dvd.js'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($source, 'page=cd-dvd-history&amp;serial=') && str_contains($source, 'data-confirm="این ثبت حذف شود؟"') && str_contains($script, '#cd-dvd-history .cd-dvd-record-table') && str_contains($style, 'grid-template-columns:repeat(6,minmax(0,1fr))') && str_contains($source, 'class="wide-half"'); };
$checks['اتصال معاونت CD/DVD به چارت سازمان'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); return str_contains($source, 'org_deputy_units()') && str_contains($source, 'unit_type') && str_contains($source, 'data-required-label="معاونت مربوط"') && str_contains($source, 'ابتدا در بخش سازمان یک معاونت فعال ثبت کنید') && !str_contains($source, 'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'); };
$checks['پیام فارسی برای فیلدهای خالی CD/DVD'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/cd-dvd.php'); $script = (string) file_get_contents($root . '/assets/cd-dvd.js'); return str_contains($source, 'فیلد «معاونت مربوط» خالی است و باید پر شود.') && str_contains($source, 'فیلد «تاریخ» خالی است و باید پر شود.') && str_contains($source, 'assets/cd-dvd.js?v=3.4.8') && str_contains($script, 'form.noValidate = true') && str_contains($script, 'فیلد «\' + label + \'» خالی است و باید پر شود.'); };
$checks['فیلتر و پنجره‌های صفحه خدمات با CSP'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $script = (string) file_get_contents($root . '/assets/app.js'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($index, 'data-service-filter') && str_contains($index, 'value="tab-list"') && str_contains($index, 'data-modal-open="service-edit-') && str_contains($index, 'data-modal-open="category-edit-') && str_contains($index, 'data-confirm="خدمت از فهرست فعال خارج شود؟"') && str_contains($script, 'form.requestSubmit()') && str_contains($script, '/delete|remove|destroy/i') && str_contains($style, '.content-wrap > .service-modal-layer { position: fixed; }') && str_contains($index, 'assets/style.css?v=3.4.22'); };
$checks['استخراج شبکه‌ای، تکمیل دستی و Excel همه سیستم‌ها'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $scan = (string) file_get_contents($root . '/domain-scan.php'); $page = (string) file_get_contents($root . '/asset-inventory-page.php'); $exportStart = strpos($index, "if ((\$_GET['action'] ?? '') === 'export_assets')"); $exportEnd = strpos($index, "if ((\$_GET['action'] ?? '') === 'export_cd_dvd')", $exportStart === false ? 0 : $exportStart); $export = $exportStart !== false && $exportEnd !== false ? substr($index, $exportStart, $exportEnd - $exportStart) : ''; return is_file($root . '/tools/collect_server_inventory.ps1') && str_contains($index, "if (\$action === 'domain_scan_selected')") && str_contains($scan, 'function domain_scan_start_selected(') && str_contains($page, 'domain_scan_selected') && str_contains($page, 'تکمیل دستی') && str_contains($page, 'دانلود Excel همهٔ سیستم‌ها') && str_contains($export, '$rows[] = $exportRow;') && str_contains($export, 'asset-full-inventory-') && !str_contains($export, 'LIMIT'); };
$checks['دکمه استخراج با مجوز صریح و وضعیت تنظیمات'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $page = (string) file_get_contents($root . '/asset-inventory-page.php'); $script = (string) file_get_contents($root . '/assets/app.js'); $pageStart = strpos($index, "if (\$page === 'assets')"); $pageEnd = strpos($index, "if (\$page === 'asset')", $pageStart === false ? 0 : $pageStart); $pageSource = $pageStart !== false && $pageEnd !== false ? substr($index, $pageStart, $pageEnd - $pageStart) : ''; $defaults = permission_defaults(); return str_contains($pageSource, "\$canImportAssets = user_can(\$assetUser, 'domain.import')") && str_contains($pageSource, "\$canExtractAssets = user_can(\$assetUser, 'domain.scan')") && str_contains($pageSource, "\$canConfigureNetworkScan = user_can(\$assetUser, 'settings.domain')") && in_array('domain.import', $defaults['support_manager'], true) && in_array('domain.scan', $defaults['support_manager'], true) && in_array('domain.import', $defaults['supervisor'], true) && in_array('domain.scan', $defaults['supervisor'], true) && str_contains($page, 'استخراج اطلاعات سیستم‌های انتخاب‌شده') && str_contains($page, 'data-scan-configured=') && str_contains($script, 'var scanConfigured = action.getAttribute(\'data-scan-configured\') !== \'0\'') && str_contains($script, 'action.disabled = !scanConfigured || selected === 0 || selected > maximum') && !str_contains($index, "if (!is_admin_role(\$scanPageUser['role'])") && !str_contains($index, "if (!is_admin_role(\$scanUser['role'])"); };
$checks['منوی نمونه با زیرمنو و مجوزهای سامانه'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $script = (string) file_get_contents($root . '/assets/app.js'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($index, '<nav class="navbar"') && str_contains($index, '<ul class="menu">') && str_contains($index, "\$submenu('menu-support', 'پشتیبانی'") && str_contains($index, "\$submenu('menu-reports', 'گزارش‌ها'") && str_contains($index, "\$submenu('menu-management', 'مدیریت'") && str_contains($index, "user_can(\$user, 'cddvd.view')") && str_contains($index, "user_can(\$user, 'traffic.view')") && str_contains($index, 'اعلان‌ها</span>') && str_contains($index, 'value="logout"') && str_contains($script, "event.target.closest('.navbar')") && str_contains($script, "event.key !== 'Escape'") && str_contains($style, '.submenu { position: absolute;') && str_contains($style, '.nav-dropdown[open] > .submenu { display: block; }') && str_contains($style, '@media (max-width: 600px)') && str_contains($style, '.menu > li.has-submenu.open { flex: 1 1 100%; }') && str_contains($style, '.menu > li { flex: 1 1 50%; }'); };
$checks['چاپ فقط پس از ثبت تردد'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/traffic-control.php'); $script = (string) file_get_contents($root . '/assets/traffic-control.js'); return str_contains($source, 'disabled aria-disabled="true" title="پس از ثبت تردد فعال می‌شود"') && str_contains($source, 'data-traffic-print-saved') && str_contains($source, 'traffic-control-print&amp;id=<?= (int) $lastCreated[\'id\'] ?>') && str_contains($source, 'assets/traffic-control.js?v=4') && !str_contains($source, 'data-traffic-print-draft') && !str_contains($script, 'window.open'); };
$checks['گزارش Excel کنترل تردد'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/traffic-control.php'); $index = (string) file_get_contents($root . '/index.php'); $script = (string) file_get_contents($root . '/assets/traffic-control.js'); return str_contains($source, 'function traffic_export_report()') && str_contains($source, 'from_date') && str_contains($source, 'to_date') && str_contains($source, 'destinations[]') && str_contains($source, 'name="all_destinations"') && str_contains($index, "\$_GET['action'] ?? '') === 'export_traffic_report'") && str_contains($index, "require_permission('traffic.view')") && str_contains($index, 'traffic_export_report();') && str_contains($script, 'traffic-report-all') && str_contains($script, 'all.setCustomValidity') && str_contains($source, 'Shabnam-Regular.woff2') && str_contains($source, 'Shabnam-Bold.woff2'); };
$checks['داشبورد کنترل تردد و آماده‌سازی امن آمار'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/traffic-control.php'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($source, "\$_GET['tab'] ?? 'dashboard'") && str_contains($source, 'traffic-dashboard-grid') && str_contains($source, 'function traffic_empty_stats()') && str_contains($source, 'Traffic dashboard stats failed:') && str_contains($source, 'آمار داشبورد از پایگاه داده دریافت نشد') && str_contains($source, 'هنوز ترددی ثبت نشده است') && str_contains($source, 'foreach ((array) $stats[\'daily\'] as $day)') && !str_contains($source, 'max(1, ...array_map') && str_contains($style, '.traffic-dashboard-empty {') && str_contains($style, '.traffic-dashboard-status'); };
$checks['فرم تنظیم مقصدهای تردد'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/traffic-control.php'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($source, 'traffic-destinations-card') && str_contains($source, 'traffic-destination-add-form') && str_contains($style, '.traffic-destinations-card {') && str_contains($style, '.traffic-destinations-list > div > span'); };
$checks['فرم و داشبورد کنترل تردد'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/traffic-control.php'); $script = (string) file_get_contents($root . '/assets/traffic-control.js'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($source, 'امضای ملاقات‌شونده') && str_contains($source, 'data-search="') && str_contains($source, 'traffic-dashboard-grid') && str_contains($source, 'traffic-history-new-button') && str_contains($script, 'data-search') && str_contains($style, '.traffic-checks label') && str_contains($style, 'white-space: nowrap') && str_contains($style, '.traffic-stat-grid'); };
$checks['تغییر بازه CD/DVD'] = static function () use ($root): bool { $script = (string) file_get_contents($root . '/assets/cd-dvd.js'); return str_contains($script, 'const bounds = mediaBounds(selectedMedia)') && str_contains($script, '!rangeIsValid') && str_contains($script, 'event.target.matches'); };
$checks['فونت‌های محلی رابط کاربری'] = static function () use ($root): bool { foreach (['Vazirmatn-Regular.woff2', 'Vazirmatn-SemiBold.woff2', 'Vazirmatn-Bold.woff2', 'Shabnam-Regular.woff2', 'Shabnam-Medium.woff2', 'Shabnam-Bold.woff2', 'Samim-Regular.woff2', 'Vazir-Code.woff2'] as $font) { if (!is_file($root . '/assets/fonts/' . $font)) { return false; } } return !str_contains((string) file_get_contents($root . '/assets/style.css'), 'fonts.googleapis.com'); };
$checks['نمونه و migration تقویم/سخت‌سازی/حاکمیت/OLA/مسیردهی/CD/DVD/پروفایل'] = static function () use ($root): bool { return is_file($root . '/calendar-template.csv') && is_file($root . '/calendar-template.ics') && is_file($root . '/upgrade-1.6-hardening.sql') && is_file($root . '/upgrade-1.7-governance.sql') && is_file($root . '/upgrade-1.8-final.sql') && is_file($root . '/upgrade-1.8-routing.sql') && is_file($root . '/upgrade-1.8-cd-dvd.sql') && is_file($root . '/upgrade-1.8-cd-dvd-identity.sql') && is_file($root . '/upgrade-1.10-priority.sql') && is_file($root . '/upgrade-1.11-user-profile.sql') && is_file($root . '/governance.php') && is_file($root . '/ola.php'); };
$checks['شماره‌گذاری تیکت'] = static function (): bool { return ticket_number(86) === 'SBU1-86-000086'; };
$checks['SLA ساعات کاری'] = static function (): bool { return priority_sla_minutes('critical') === 240 && priority_sla_minutes('urgent') === 2880 && priority_sla_minutes('normal') === 4320; };
$checks['اعتبارسنجی تاریخ جلالی'] = static function (): bool { return jalali_input_to_gregorian('۱۴۰۵/۰۱/۰۱') !== null && jalali_input_to_gregorian('۱۴۰۵/۱۳/۰۱') === null; };
$checks['تنظیم نام سامانه'] = static function (): bool { return setting('app_name') !== null && setting('app_name') !== ''; };
$checks['هویت بصری و هدر پویا'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $governance = (string) file_get_contents($root . '/governance.php'); $ola = (string) file_get_contents($root . '/ola.php'); return str_contains($index, 'topbar-inner') && str_contains($index, 'app_logo') && str_contains($governance, 'app_logo') && str_contains($ola, 'app_logo') && str_contains($index, 'e($appName)'); };
$checks['وجود مدیر'] = static function (): bool { return (bool) db()->query("SELECT id FROM users WHERE role IN ('admin', 'primary_admin') LIMIT 1")->fetchColumn(); };
$checks['پوشه ذخیره پیوست'] = static function () use ($root): bool { return is_dir($root . '/storage/uploads') && is_writable($root . '/storage/uploads'); };
$checks['پروفایل کاربری و هویت پنل فیش'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $food = (string) file_get_contents($root . '/food-ticket.php'); $schema = (string) file_get_contents($root . '/schema.sql'); $style = (string) file_get_contents($root . '/assets/style.css'); return str_contains($index, "page === 'profile'") && str_contains($index, 'name="profile_photo"') && str_contains($index, 'save_profile') && str_contains($schema, 'profile_photo') && str_contains($index, 'food-ticket-brand') && str_contains($food, 'food_ticket_brand_name') && str_contains($style, 'ambient-orbits'); };
$checks['اصلاح امنیت و مسیر پیوست'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); return str_contains($index, "\$directory = APP_ROOT . '/storage/uploads'") && str_contains($index, "APP_ROOT . '/storage/uploads/' . basename") && is_file($root . '/storage/.htaccess') && is_file($root . '/tools/migrate-legacy-attachments.php'); };
$checks['جداسازی حساب local و LDAP'] = static function () use ($root): bool { $bootstrap = (string) file_get_contents($root . '/bootstrap.php'); return str_contains($bootstrap, 'auth_source = "ldap"') && str_contains($bootstrap, 'حساب محلی اختصاص دارد'); };
$checks['حذف دقیق SOURCE_TABLE پس از ثبت پایدار'] = static function () use ($root): bool { $engine = (string) file_get_contents($root . '/food-ticket-engine.php'); $food = (string) file_get_contents($root . '/food-ticket.php'); $insert = strpos($engine, '$eventId = food_ticket_add_event($payload);'); $delete = $insert === false ? false : strpos($engine, '$okDel = food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, $eventId);', $insert); return $insert !== false && $delete !== false && $delete > $insert && str_contains($engine, 'if (!$eventIds)') && !str_contains($engine, '$pendingSourceDeletes') && str_contains($food, 'function food_ticket_source_delete_where') && str_contains($food, 'SELECT COUNT(*) FROM ') && str_contains($food, 'function food_ticket_source_primary_key') && str_contains($food, 'function food_ticket_source_delete_candidates') && str_contains($food, 'function food_ticket_source_sql_literal') && str_contains($food, 'function food_ticket_mark_source_delete') && str_contains($engine, 'function food_ticket_engine_delete_source') && str_contains($engine, 'source_delete_failed') && !str_contains($food, "'source_deleted' => true") && str_contains($food, 'function food_ticket_cleanup_source_rows'); };
$checks['چاپ مجدد Worker بعد از پایان retry'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/food-ticket.php'); return str_contains($source, 'print_status IN ("print_error", "failed")') && str_contains($source, 'retry_count = 0, print_attempts = 0, next_retry_at = NULL') && str_contains($source, "return ['ok' => (int) (\$q['printed'] ?? 0)") && str_contains($source, "food_ticket_api_json(['error' => 'چاپ فیش غیرفعال است.'], 409)"); };
$checks['CSRF و CSP پنل چاپ فیش'] = static function () use ($root): bool { $bootstrap = (string) file_get_contents($root . '/bootstrap.php'); $food = (string) file_get_contents($root . '/food-ticket.php'); $web = (string) file_get_contents($root . '/food-ticket-web/index.html'); return str_contains($bootstrap, 'HTTP_X_CSRF_TOKEN') && str_contains($bootstrap, "'nonce-") && str_contains($bootstrap, 'csp_nonce') && str_contains($food, 'window.FOOD_TICKET_CSRF') && str_contains($food, 'nonce="') && str_contains($food, 'require_csrf();') && str_contains($web, "headers['X-CSRF-Token']"); };
$checks['نصب قابل rollback و retry'] = static function () use ($root): bool { $source = (string) file_get_contents($root . '/install.php'); return str_contains($source, '$pdo->beginTransaction()') && str_contains($source, '$pdo->commit()') && str_contains($source, '$pdo->rollBack()') && str_contains($source, 'ON DUPLICATE KEY UPDATE'); };
$checks['اصلاح ممیزی Inventory و حذف کد مرده'] = static function () use ($root): bool { $index = (string) file_get_contents($root . '/index.php'); $food = (string) file_get_contents($root . '/food-ticket.php'); return !str_contains($index, "'asset_tag' => \$assetTag") && !str_contains($food, '$foodUser = require_food_ticket_access();'); };
$checks['ثبت هویت کلاینت در اولین ورود'] = static function () use ($root): bool { $inventory = (string) file_get_contents($root . '/inventory.php'); $index = (string) file_get_contents($root . '/index.php'); return str_contains($inventory, 'function inventory_collect_client_asset') && str_contains($inventory, 'function inventory_client_ip') && str_contains($inventory, 'gethostbyaddr') && str_contains($inventory, 'client_request_identity') && str_contains($index, 'inventory_collect_client_asset($local)') && str_contains($index, 'inventory_collect_client_asset($domainUser)') && str_contains($index, 'ip_address = ?'); };

$checks['ثبت نتیجهٔ حذف SOURCE_TABLE برای همهٔ فیش‌های تردد گروهی'] = static function () use ($root): bool {
    $engine = (string) file_get_contents($root . '/food-ticket-engine.php');
    $groups = (string) file_get_contents($root . '/food-ticket-groups.php');
    return str_contains($engine, 'relatedEventIds')
        && str_contains($engine, 'groupResult')
        && str_contains($engine, 'event_ids')
        && str_contains($groups, 'function food_ticket_group_event_ids_for_run')
        && str_contains($groups, "'event_ids' => $groupEventIds");
};

$failed = 0;
foreach ($checks as $name => $check) {
    try {
        $ok = (bool) $check();
    } catch (Throwable $exception) {
        $ok = false;
    }
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    $failed += $ok ? 0 : 1;
}
echo $failed === 0 ? "\nهمه بررسی‌های سلامت با موفقیت انجام شد.\n" : "\nتعداد خطاها: {$failed}\n";
exit($failed === 0 ? 0 : 1);
