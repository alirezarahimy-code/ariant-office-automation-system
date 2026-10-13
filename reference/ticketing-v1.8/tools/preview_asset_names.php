<?php
declare(strict_types=1);

/**
 * پیش‌نمایش نام کوتاه کامپیوترها — نسخهٔ ۱.۳۷.۵
 * ============================================================================
 *   php tools/preview_asset_names.php
 *
 * یک فایل HTML می‌سازد که «قبل و بعد» را با همان توابع واقعیِ سرور نشان می‌دهد:
 *   storage/asset-name-preview.html
 * (مارک‌آپ جدول‌ها از خود index.php کپی شده تا آنچه می‌بینید همان چیزی باشد که
 *  کاربر در سامانه می‌بیند.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);

function grabFunction(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name);
    }
    $brace = strpos($src, '{', $start);
    $depth = 0;
    for ($i = $brace; $i < strlen($src); $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('بدنهٔ تابع بسته نشد: ' . $name);
}

$GLOBALS['FT_SETTINGS'] = ['asset_hostname_style' => 'short_lower'];
function setting(string $key, ?string $default = null): ?string
{
    return $GLOBALS['FT_SETTINGS'][$key] ?? $default;
}
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
foreach (['asset_hostname_style', 'asset_name_core', 'asset_display_name', 'asset_is_auto_domain_tag', 'asset_name_title', 'asset_name_attr', 'asset_tag_label'] as $fn) {
    eval(grabFunction($root . '/asset-names.php', $fn));
}

$rows = [
    ['hostname' => 'ADMINIT2.DOMAIN.LOCAL', 'asset_tag' => 'DOMAIN-ADMINIT2', 'dept' => 'معاونت اداری', 'os' => 'Windows 11 Pro'],
    ['hostname' => 'PC-099.DOMAIN.LOCAL',   'asset_tag' => 'DOMAIN-PC-099',   'dept' => 'معاونت مالی',  'os' => 'Windows 10 Pro'],
    ['hostname' => 'IT-SRV01.DOMAIN.LOCAL', 'asset_tag' => 'IT-1402-0042',    'dept' => 'فناوری اطلاعات', 'os' => 'Windows Server 2019'],
    ['hostname' => '192.168.10.42',        'asset_tag' => 'DOMAIN-192-168-10-42', 'dept' => 'حراست', 'os' => 'Windows 10'],
];

$html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
    . '<title>پیش‌نمایش نام کوتاه کامپیوترها — ۱.۳۷.۵</title><style>'
    . 'body{font-family:Tahoma,sans-serif;background:#f5f7fb;color:#1b2740;margin:0;padding:24px;line-height:1.9}'
    . 'h1{font-size:20px}h2{font-size:16px;margin-top:28px}'
    . '.card{background:#fff;border:1px solid #e3e8f0;border-radius:14px;padding:16px 18px;margin:14px 0;max-width:900px}'
    . '.table-head,.table-row{display:grid;grid-template-columns:2.2fr 1.2fr 1.2fr;gap:10px}'
    . '.table-head{font-size:12px;color:#6b7280;border-bottom:1px solid #e3e8f0;padding-bottom:8px}'
    . '.table-row{border-bottom:1px solid #f0f3f8;padding:10px 0;align-items:center}'
    . '.ticket-title b{font-weight:700}.ticket-title strong{direction:ltr;unicode-bidi:isolate;margin-inline-start:8px}'
    . '.asset-tag-chip{display:inline-block;margin-inline-start:6px;padding:1px 6px;border-radius:999px;background:#eef2ff;color:#3b4a7a;font-size:10px;font-weight:600}'
    . 'code{background:#f1f5f9;padding:2px 6px;border-radius:6px;direction:ltr;display:inline-block}'
    . '.old{color:#b42318}.new{color:#047857}'
    . 'small.badge{background:#ecfdf5;color:#065f46;border-radius:999px;padding:2px 8px;font-size:11px}'
    . '</style></head><body>';

$html .= '<h1>پیش‌نمایش نام کوتاه کامپیوترها (نسخهٔ ۱.۳۷.۵)</h1>'
    . '<div class="card"><p>این فایل با <b>همان توابع واقعی سرور</b> و همان مارک‌آپ فهرست شناسنامه‌ها ساخته شده است. '
    . 'حالت پیش‌فرض: «فقط نام، با حروف کوچک». نام کامل دامنه به‌صورت tooltip روی نام می‌ماند (موس را روی نام ببرید). '
    . 'برای تغییر حالت: تنظیمات ← اطلاعات سامانه ← «ظاهر نام کامپیوترها».</p></div>';

$html .= '<h2>۱) قبل و بعد (تبدیل‌ها)</h2><div class="card"><table style="width:100%;border-collapse:collapse">'
    . '<tr><th align="right" style="font-size:12px;color:#6b7280">نام واقعی (دیتابیس)</th>'
    . '<th align="right" style="font-size:12px;color:#6b7280">قبل (۱.۳۷.۴)</th>'
    . '<th align="right" style="font-size:12px;color:#6b7280">بعد (۱.۳۷.۵)</th></tr>';
foreach ($rows as $row) {
    $html .= '<tr><td><code>' . e($row['hostname']) . '</code> + <code>' . e($row['asset_tag']) . '</code></td>'
        . '<td class="old"><b>' . e($row['asset_tag']) . '</b> ' . e($row['hostname']) . '</td>'
        . '<td class="new"><b>' . e(asset_display_name($row['hostname'])) . '</b> <small class="badge">نام کوتاه</small></td></tr>';
}
$html .= '</table></div>';

$html .= '<h2>۲) فهرست شناسنامه‌ها — دقیقاً همان چیزی که در سامانه می‌بینید</h2>'
    . '<div class="card"><div class="table-head"><span>سیستم</span><span>معاونت</span><span>سیستم‌عامل</span></div>';
foreach ($rows as $row) {
    $html .= '<div class="table-row"><span class="ticket-title">'
        . (asset_is_auto_domain_tag($row['asset_tag']) ? '' : '<b>' . e($row['asset_tag']) . '</b>')
        . '<strong' . asset_name_attr($row['hostname'], $row['asset_tag']) . '>' . e(asset_display_name($row['hostname'])) . '</strong>'
        . (asset_is_auto_domain_tag($row['asset_tag']) ? '<small class="asset-tag-chip">دامنه</small>' : '')
        . '</span><span>' . e($row['dept']) . '</span><span>' . e($row['os']) . '</span></div>';
}
$html .= '</div>';

$html .= '<h2>۳) کمبوی «سیستم مرتبط» در فرم تیکت</h2><div class="card"><select style="width:100%;padding:8px">';
foreach ($rows as $row) {
    $tagText = asset_is_auto_domain_tag($row['asset_tag']) ? '' : asset_tag_label($row['asset_tag'], $row['hostname']);
    $html .= '<option>' . e(asset_display_name($row['hostname']) . ($tagText !== '' ? ' - ' . $tagText : '')) . '</option>';
}
$html .= '</select><p><small>فهرست نتایج زندهٔ همین کمبو هم از همین متن ساخته می‌شود.</small></p></div>';

$html .= '<h2>۴) خروجی Excel/CSV (صفحهٔ شناسنامه‌های فنی)</h2><div class="card"><div class="table-head"><span>شناسه سیستم</span><span>نام سیستم</span><span>منبع استخراج</span></div>';
foreach ($rows as $row) {
    $html .= '<div class="table-row"><span>' . e(asset_tag_label($row['asset_tag'], $row['hostname'])) . '</span>'
        . '<span><code>' . e(asset_display_name($row['hostname'])) . '</code></span><span>اسکن/دامنه</span></div>';
}
$html .= '</div>';

$html .= '<p style="color:#6b7280;font-size:12px">— پایان پیش‌نمایش —</p></body></html>';

$target = $root . '/storage/asset-name-preview.html';
@mkdir(dirname($target), 0775, true);
file_put_contents($target, $html);
echo "نوشته شد: $target (" . strlen($html) . " بایت)\n";
