<?php
declare(strict_types=1);

function inventory_render_assets_content(
    array $assetRows,
    string $assetSearch,
    string $networkFilter,
    bool $canImportAssets,
    bool $canPingAssets,
    bool $canExtractAssets,
    bool $canConfigureNetworkScan,
    bool $networkScanEnabled,
    bool $canSelectAssets,
    int $onlineCount,
    int $collectedCount
): void {
    echo '<section class="page-heading inventory-page-heading"><div><span class="eyebrow">Technical Inventory</span><h1>شناسنامه‌های فنی سیستم</h1><p>کامپیوترها را از دامنه وارد کنید، سیستم‌های انتخابیِ در دسترس شبکه را استخراج کنید و اطلاعات تکمیلی هر شناسنامه را جداگانه ویرایش کنید.</p></div><div class="inventory-top-actions">';
    if ($canImportAssets) {
        echo '<form method="post" class="inline-action">' . csrf_field() . '<input type="hidden" name="action" value="import_domain_assets"><button class="button secondary" type="submit">ورود کامپیوترهای دامنه</button></form>';
    }
    if ($canExtractAssets && $canConfigureNetworkScan) {
        echo '<a class="button secondary" href="index.php?page=settings#domain-scan">حساب استخراج شبکه</a>';
    }
    echo '<a class="button" href="index.php?action=export_assets">دانلود Excel همهٔ سیستم‌ها</a></div></section>';

    echo '<section class="inventory-summary" aria-label="وضعیت سیستم‌ها"><div><strong>' . count($assetRows) . '</strong><span>سیستم در این فهرست</span></div><div><strong data-online-total>' . $onlineCount . '</strong><span>آنلاین در آخرین بررسی</span></div><div><strong>' . $collectedCount . '</strong><span>دارای اطلاعات استخراج‌شده</span></div><p>خروجی Excel همهٔ سیستم‌های مجاز را می‌گیرد؛ هر سیستم دقیقاً یک ردیف دارد.</p></section>';
    echo '<form class="inventory-filter card" method="get" data-asset-live-filter><input type="hidden" name="page" value="assets"><label class="inventory-search">جست‌وجوی نام، شناسه، سریال یا IP<input name="q" id="asset-live-q" value="' . e($assetSearch) . '" placeholder="با تایپ، فهرست پایین فوراً فیلتر می‌شود…" autocomplete="off" data-asset-live-q></label><label>وضعیت شبکه<select name="network" id="asset-live-network" data-asset-live-network><option value="all"' . ($networkFilter === 'all' ? ' selected' : '') . '>همه وضعیت‌ها</option><option value="online"' . ($networkFilter === 'online' ? ' selected' : '') . '>آنلاین</option><option value="offline"' . ($networkFilter === 'offline' ? ' selected' : '') . '>آفلاین</option><option value="unknown"' . ($networkFilter === 'unknown' ? ' selected' : '') . '>بررسی‌نشده</option></select></label><span class="muted" data-asset-live-count></span>' . ($assetSearch !== '' || $networkFilter !== 'all' ? '<a class="button secondary" href="index.php?page=assets" data-asset-live-reset>پاک‌کردن فیلتر</a>' : '') . '</form>';

    if ($canExtractAssets && !$networkScanEnabled) {
        echo '<div class="alert danger inventory-scan-notice">' . e((string) domain_scan_preflight()) . '</div>';
    }
    echo '<div class="inventory-selection-bar"><div><strong id="asset-selection-count" data-asset-selection-count>۰ سیستم انتخاب شده</strong><span> برای هر نوبت حداکثر ۹۰۰ سیستم انتخاب کنید؛ برای ویرایش دستی، دکمهٔ هر ردیف را باز کنید.</span></div><div class="inventory-bulk-actions">';
    if ($canExtractAssets) {
        $scanReady = $networkScanEnabled ? '1' : '0';
        $scanDisabled = $networkScanEnabled ? '' : ' disabled aria-disabled="true" title="' . e((string) domain_scan_preflight()) . '"';
        echo '<button class="button" type="submit" form="assetSelectionForm" name="action" value="domain_scan_selected" data-asset-selection-action data-max-selection="900" data-scan-configured="' . $scanReady . '"' . $scanDisabled . '>استخراج اطلاعات سیستم‌های انتخاب‌شده</button>';
    }
    if ($canPingAssets) {
        echo '<button class="button secondary" type="submit" form="assetSelectionForm" name="action" value="ping_assets" data-asset-selection-action data-max-selection="5000" data-ping-chunk="40">فقط پینگ انتخاب‌شده‌ها</button><span class="muted" data-ping-progress hidden></span>';
    }
    echo '</div></div><form id="assetSelectionForm" method="post">' . csrf_field();
    echo '<section class="card ticket-table asset-table"><div class="asset-table-scroll"><div class="table-head"><span>' . ($canSelectAssets ? '<input type="checkbox" data-select-all="asset_ids[]" title="انتخاب همهٔ سیستم‌های فهرست‌شده">' : '') . '</span><span>سیستم</span><span>معاونت</span><span>IP</span><span>شبکه</span><span>وضعیت استخراج</span><span>آخرین استخراج</span><span>شناسنامه</span></div>';

    if ($assetRows === []) {
        $filtered = $assetSearch !== '' || $networkFilter !== 'all';
        $emptyHint = $filtered
            ? 'فیلتر جست‌وجو یا وضعیت شبکه را تغییر دهید.'
            : ($canImportAssets
                ? 'با «ورود کامپیوترهای دامنه» فهرست سیستم‌ها را اضافه کنید؛ سپس سیستم‌های انتخابی را از شبکه استخراج کنید.'
                : 'برای افزودن سیستم‌ها، از مدیر IT بخواهید کامپیوترهای دامنه را وارد کند.');
        echo '<div class="inventory-empty"><strong>' . ($filtered ? 'سیستمی با این فیلتر پیدا نشد.' : 'هنوز سیستمی در فهرست نیست.') . '</strong><p>' . $emptyHint . '</p></div>';
    }

    foreach ($assetRows as $asset) {
        $isOnline = (int) ($asset['ad_online'] ?? 0) === 1;
        $wasScanned = (string) ($asset['ad_scanned_at'] ?? '') !== '';
        $networkClass = $isOnline ? 'online' : ($wasScanned ? 'offline' : 'unknown');
        $networkLabel = $isOnline ? 'آنلاین' : ($wasScanned ? 'آفلاین' : 'بررسی‌نشده');
        $netHint = $asset['ad_last_seen'] ? 'آخرین اتصال: ' . persian_date((string) $asset['ad_last_seen']) : '';
        $collected = !empty($asset['last_inventory_at']);
        $collectionBadge = $collected ? '<small class="inventory-badge collected">استخراج شده</small>' : '<small class="inventory-badge unknown">استخراج نشده</small>';
        echo '<div class="table-row" data-asset-row="' . (int) $asset['id'] . '" data-asset-search="' . e($asset['hostname'] . ' ' . $asset['asset_tag'] . ' ' . ($asset['serial_number'] ?? '') . ' ' . ($asset['ip_address'] ?? '') . ' ' . ($asset['department_name'] ?? '')) . '" data-asset-net="' . $networkClass . '"><span>' . ($canSelectAssets ? '<input type="checkbox" name="asset_ids[]" value="' . (int) $asset['id'] . '">' : '') . '</span><span class="ticket-title"><a href="index.php?page=inventory&id=' . (int) $asset['id'] . '">' . (asset_is_auto_domain_tag($asset['asset_tag']) ? '' : '<b>' . e($asset['asset_tag']) . '</b>') . '<strong' . asset_name_attr($asset['hostname'], $asset['asset_tag']) . '>' . e(asset_display_name($asset['hostname'])) . '</strong></a>' . ($netHint !== '' ? '<small>' . e($netHint) . '</small>' : '') . '</span><span>' . e($asset['department_name'] ?: 'بدون معاونت') . '</span><span dir="ltr">' . e($asset['ip_address'] ?: '—') . '</span><span><small class="inventory-badge ' . $networkClass . '" data-net-badge>' . $networkLabel . '</small></span><span>' . $collectionBadge . '</span><span class="date-cell">' . e($asset['last_inventory_at'] ? persian_date((string) $asset['last_inventory_at']) : '—') . '</span><span><a class="button secondary inventory-row-edit" href="index.php?page=inventory&id=' . (int) $asset['id'] . '">تکمیل دستی</a></span></div>';
    }
    echo '<div class="inventory-empty" data-asset-live-empty hidden><strong>سیستمی با این جست‌وجو پیدا نشد.</strong><p>عبارت دیگری را امتحان کنید یا فیلتر وضعیت شبکه را روی «همه وضعیت‌ها» بگذارید.</p></div>';
    echo '</div></section></form>';
}
