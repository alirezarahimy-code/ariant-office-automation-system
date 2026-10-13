<?php
declare(strict_types=1);

/**
 * آزمون «قرارداد توابع» — نسخهٔ ۱.۳۷
 * ============================================================================
 *   php tools/test_api_contract_offline.php
 *
 * چرا این آزمون لازم شد؟ در بستهٔ ۱.۳۷ یک مسیر API نوشته شده بود که توابع کمکی
 * «فرضی» را صدا می‌زد (food_ticket_api_ok / food_ticket_api_log) که در پروژه وجود
 * ندارند؛ php -l چنین چیزی را نمی‌گیرد و خطا فقط در زمان اجرا (وقتی کاربر دکمه را
 * می‌زند) ظاهر می‌شود. این آزمون، همهٔ فراخوانی‌های توابع پروژه را با فهرست
 * تعریف‌شده‌ها مقایسه می‌کند.
 *
 * بررسی‌ها:
 *   ۱) هیچ فراخوانی به تابعِ تعریف‌نشدهٔ پروژه وجود ندارد (index.php، food-ticket.php، …)
 *   ۲) هر مسیر API که پنل غذا صدا می‌زند، در نگاشت مجوزهای food-ticket.php هست
 *   ۳) مسیرهای حساس (چاپ/تنظیمات) مجوز دارند
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '[PASS] ' . $label . "\n";
    } else {
        $fail++;
        echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = (string) $file;
    if (substr($path, -4) !== '.php') {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    if (preg_match('#^(vendor|node_modules|storage|uploads|snapshots|proj|proj2|received|tests)#', $rel) === 1) {
        continue;
    }
    $phpFiles[$rel] = (string) file_get_contents($path);
}
check('۰: فایل‌های PHP پروژه خوانده شدند', count($phpFiles) > 50, (string) count($phpFiles));

/* ── ۱) تحلیل توکنی: تعریف‌ها و فراخوانی‌ها بدون کامنت و رشته ──────────────
   توکن‌گیرِ خودِ PHP استفاده می‌شود تا نام جدول‌ها در SQL یا متن کامنت‌ها با نام
   توابع اشتباه گرفته نشود. */
$hasTokenizer = function_exists('token_get_all');
check('۱: توکن‌گیر PHP در دسترس است', $hasTokenizer);

$defined = [];
$calls = [];   // name => [files...]
foreach ($phpFiles as $rel => $src) {
    if (!$hasTokenizer) {
        break;
    }
    $tokens = @token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_FUNCTION) {
            // نام تابع: نخستین T_STRING پس از T_FUNCTION (با پرش از &، فاصله و نوع بازگشتی)
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $defined[$tokens[$j][1]] = $rel;
                    $i = $j;
                    break;
                }
                if (is_string($tokens[$j]) && $tokens[$j] === '(') {
                    break;
                }
            }
            continue;
        }
        if (is_array($token) && $token[0] === T_STRING) {
            // فراخوانی = نامی که بعدش '(' بیاید و قبلش T_FUNCTION/T_NEW/-> نباشد
            $prev = $i > 0 ? $tokens[$i - 1] : null;
            $prevIsCallableKeyword = is_array($prev) && in_array($prev[0], [T_FUNCTION, T_NEW, T_CLASS, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST], true);
            if ($prevIsCallableKeyword) {
                continue;
            }
            $next = $i + 1 < $count ? $tokens[$i + 1] : null;
            if (is_string($next) && $next === '(') {
                $calls[$token[1]][] = $rel;
            }
            continue;
        }
    }
}
check('۲: فهرست توابع پروژه ساخته شد', count($defined) > 400, (string) count($defined));

/* ── ۲) هیچ فراخوانی به تابعِ تعریف‌نشدهٔ پروژه نباشد ─────────────────────── */
$prefixes = ['food_ticket_', 'food_', 'domain_scan_', 'inventory_', 'asset_', 'ticket_', 'org_', 'cd_dvd_', 'system_'];
$runtimeDefined = [
    // توابعی که در زمان اجرا (افزونه/فایل تولیدشده) تعریف می‌شوند
    'db', 'db_commit', 'db_rollback', 'asset_profile_apply_auto', 'record_asset_history',
    'ticket_number_cache_store', 'ticket_number_seq',
    // توجه: هر نام دیگری که «در زمان اجرا» تعریف نمی‌شود باید در همین فهرست نباشد،
    // وگرنه آزمون زیر خطای «فراخوانی تابع ناموجود» را نمی‌گیرد (درس ۱.۳۷.۶).
];
$missing = [];
foreach ($calls as $name => $files) {
    if (isset($defined[$name]) || in_array($name, $runtimeDefined, true)) {
        continue;
    }
    foreach ($prefixes as $prefix) {
        if (str_starts_with($name, $prefix)) {
            $missing[] = array_unique($files)[0] . ' → ' . $name . '()';
            break;
        }
    }
}
check('۳: هیچ فراخوانی به تابع تعریف‌نشده‌ای نیست', $missing === [], implode(' | ', array_slice($missing, 0, 8)));

/* ── ۳) مسیرهای API پنل غذا در نگاشت مجوزها باشند ─────────────────────────── */
$foodTicket = $phpFiles['food-ticket.php'] ?? '';
$panel = (string) file_get_contents($root . '/food-ticket-web/index.html');
preg_match_all("#api\(\s*'/api/([A-Za-z0-9_\-/]+)#", $panel, $routes);
$used = array_unique($routes[1]);
check('۴: مسیرهای API پنل استخراج شدند', count($used) > 8, (string) count($used));

// مسیرهای عمومی (ورود/نشست) در ابتدای فایل با in_array مجاز می‌شوند، نه در جدول مجوز.
$publicSection = substr($foodTicket, 0, 4000);
$unmapped = [];
foreach ($used as $route) {
    if (str_contains($foodTicket, "'" . $route . "' => '")) {
        continue;
    }
    if (str_contains($publicSection, "'" . $route . "'")) {
        continue;
    }
    $unmapped[] = $route;
}
check('۵: هر مسیر API پنل در نگاشت مجوز food-ticket.php هست', $unmapped === [], implode(' | ', $unmapped));

/* ── ۴) مسیرهای تازهٔ ۱.۳۷ با توابع واقعی کار می‌کنند ─────────────────────── */
foreach (['calibrate-print', 'print-align'] as $route) {
    check('۶-' . $route . ': مسیر در نگاشت مجوز هست', str_contains($foodTicket, "'" . $route . "' => 'food.printer'"));
}
$handlerStart = strpos($foodTicket, "if (\$route === 'calibrate-print')");
$handlerEnd = strpos($foodTicket, "if (\$route === 'print-align')");
$handlerCal = ($handlerStart !== false && $handlerEnd !== false) ? substr($foodTicket, (int) $handlerStart, (int) $handlerEnd - (int) $handlerStart) : '';
check('۷: هندلر کارت کالیبراسیون وجود دارد و food_ticket_np_calibration_print را صدا می‌زند',
    $handlerCal !== '' && str_contains($handlerCal, 'food_ticket_np_calibration_print('));
check('۸: هندلر فقط از توابع موجود پروژه استفاده می‌کند (بدون api_ok/api_log فرضی)',
    !str_contains($handlerCal, 'food_ticket_api_ok(')
    && !str_contains($handlerCal, 'food_ticket_api_log(')
    && !str_contains($handlerCal, 'food_ticket_api_input(')
    && !str_contains($handlerCal, 'food_ticket_api_error('));
$handlerAlignEnd = strpos($foodTicket, "\$route === 'test-print'", (int) $handlerEnd);
$handlerAlign = ($handlerEnd !== false && $handlerAlignEnd !== false) ? substr($foodTicket, (int) $handlerEnd, (int) $handlerAlignEnd - (int) $handlerEnd) : '';
check('۹: هندلر تراز لبه‌ها ذخیره و وضعیت را برمی‌گرداند',
    $handlerAlign !== '' && str_contains($handlerAlign, 'food_ticket_np_write_json(') && str_contains($handlerAlign, 'food_ticket_np_align_state('));
check('۱۰: پاسخ مسیرها با food_ticket_api_json (تابع واقعی) فرستاده می‌شود',
    substr_count($handlerCal . $handlerAlign, 'food_ticket_api_json(') >= 4,
    (string) substr_count($handlerCal . $handlerAlign, 'food_ticket_api_json('));

/* ── ۵) پنل و CSS: رابط کامل تراز لبه‌ها ─────────────────────────────────── */
$css = (string) file_get_contents($root . '/assets/style.css');
check('۱۲: پنل دکمهٔ «ذخیره و چاپ کارت آزمون» و هندلر save-align را دارد',
    str_contains($panel, "data-action=\"save-align\"") && str_contains($panel, "act==='save-align'")
    && str_contains($panel, "api('/api/print-align'"));
check('۱۳: کادر تراز شامل همهٔ ورودی‌های لازم است',
    str_contains($panel, 'id="align-form"')
    && str_contains($panel, 'name="head_offset_mm"')
    && str_contains($panel, 'name="tail_mm"')
    && str_contains($panel, 'name="line_dots"')
    && str_contains($panel, 'name="reverse_feed_cmd"')
    && str_contains($panel, 'name="edge_align"')
    && str_contains($panel, 'name="test_card"'));
check('۱۴: وضعیت تراز (فعال/خاموش/نیازمند اندازه‌گیری) در پنل نمایش داده می‌شود',
    str_contains($panel, "verdict==='disabled'") && str_contains($panel, 'align-on') && str_contains($panel, 'align-off'));
check('۱۵: CSS کادر تراز و گزارش اتصال موجود است',
    str_contains($css, '.align-card') && str_contains($css, '.align-facts') && str_contains($css, '.conn-issues'));
check('۱۶: نتیجهٔ آزمون اتصال خط‌به‌خط نمایش داده می‌شود (نه یک رشتهٔ طولانی)',
    str_contains($panel, '__ftConnLines') && str_contains($panel, 'function connIssuesBox()') && str_contains($foodTicket, "$" . "result['lines'] = array_values("));

/* ── ۶) ۱.۳۷.۲: ریشهٔ «کارت کالیبراسیون چاپ نمی‌شود» و قاعده‌های فرم تیکت ────── */
$netprint = (string) file_get_contents($root . '/food-ticket-netprint.php');
$appJs = (string) file_get_contents($root . '/assets/app.js');
$indexPhp = (string) file_get_contents($root . '/index.php');
check('۱۷: کارت کالیبراسیون اگر تنظیمات settings خالی باشد از چاپگر واقعی فیش استفاده می‌کند',
    str_contains($netprint, "food_ticket_config()") && str_contains($netprint, "\$source = 'food_ticket_config';"));
check('۱۸: پیام روشن برای «آدرس چاپگر تنظیم نشده» و حالت صف ویندوز وجود دارد',
    str_contains($netprint, 'روش چاپ روی «صف ویندوز» است') && str_contains($netprint, 'آدرس چاپگر شبکه‌ای تنظیم نشده است'));
check('۱۹: مسیر print-diag با وضعیت واقعی چاپگر وجود دارد',
    str_contains($foodTicket, "'print-diag' => 'food.printer'")
    && str_contains($foodTicket, "function food_ticket_api_print_diag()")
    && str_contains($foodTicket, "$" . "route === 'print-diag'"));
check('۲۰: ذخیرهٔ تنظیمات چاپگر، همان مقدار ذخیره‌شده را برمی‌گرداند',
    str_contains($foodTicket, 'food_ticket_api_printer_state()'));
check('۲۱: پنل کادر «وضعیت واقعی روی سرور» و ورودی دستی نام چاپگر دارد',
    str_contains($panel, 'function serverPrinterBox()') && str_contains($panel, 'name="printerNameManual"')
    && str_contains($panel, "data-action=\"print-diag\""));
check('۲۲: سوئیچ روش چاپ زنده است (بدون رندر مجدد و بدون پاک‌کردن IP)',
    str_contains($panel, 'function togglePrinterModeFields(') && str_contains($panel, "t.name==='mode'"));
check('۲۳: فرم تیکت با حوزهٔ «خدمات کامپیوتری» باز می‌شود',
    str_contains($indexPhp, "\$ticketDefaultGroup = \$cdDvdRequestRecord ? 'support' : 'it';"));
check('۲۴: الزام «سیستم مرتبط» در حوزهٔ IT در سرور و پنل اعمال شده است',
    str_contains($indexPhp, "\$requiresAsset = \$serviceGroup === 'it' ? true :")
    && str_contains($indexPhp, 'در حوزهٔ خدمات کامپیوتری، انتخاب «سیستم مرتبط» الزامی است')
    && str_contains($appJs, "var requiresAsset = group === 'it';")
    && str_contains($appJs, "getGroup() === 'it' && assetSelector.value === ''"));
check('۲۵: نمایش/مخفای «سیستم مرتبط» بر پایهٔ حوزه است و Enter با یک نتیجه انتخاب می‌کند',
    str_contains($appJs, 'assetField.hidden = !requiresAsset;')
    && str_contains($appJs, 'if (assetRows.length === 1) node = assetRows[0];'));
check('۲۷: ارتفاع خط با ESC 3 قفل می‌شود (عقب‌راندن دقیق، مستقل از تنظیم چاپگر)',
    str_contains($netprint, '$out .= "\\x1b\\x33" . chr($lineDots);'));
check('۲۸: فرمان جایگزین ESC K بر حسب نقطه فرستاده می‌شود',
    str_contains($netprint, '$dots = (int) max(1, min(255, $lines * max(1, $lineDots)));'));
check('۲۹: حالت‌های برش partial/full/none پشتیبانی می‌شوند',
    str_contains($netprint, "['partial', 'full', 'none']") && str_contains($netprint, "$" . "cutCode = $" . "cutMode === 'full' ?"));
check('۳۰: کلید cut_mode در فهرست مجاز ذخیره و در مسیر print-align پذیرفته می‌شود',
    str_contains($netprint, "$" . "key === 'cut_mode'") && str_contains($foodTicket, "'cut_mode'"));
check('۳۱: پنل انتخاب حالت برش و نمایش واحد فرمان را دارد',
    str_contains($panel, 'name="cut_mode"') && str_contains($panel, 'reverse_unit') || str_contains($panel, 'نقطه'));

check('۲۶: مقصد کارت کالیبراسیون در پاسخ «وضعیت واقعی» گزارش می‌شود',
    str_contains($foodTicket, "'calibration_host'") && str_contains($foodTicket, "'calibration_source'"));

/* ── ۵) پنل: دکمه‌ها و رابط تراز لبه‌ها ───────────────────────────────────── */
check('۱۱: پنل دکمهٔ «کارت کالیبراسیون» را دارد و صفحهٔ تنظیمات به آن وصل است',
    str_contains($panel, 'calibrate-print') && (str_contains($panel, 'print-align') || str_contains($panel, 'head_offset_mm')));

echo "\n" . ($fail === 0
    ? 'همهٔ ' . $pass . " بررسی قرارداد توابع/مسیرها موفق بود.\n"
    : $fail . ' بررسی ناموفق از ' . ($pass + $fail) . " مورد.\n");
exit($fail === 0 ? 0 : 1);
