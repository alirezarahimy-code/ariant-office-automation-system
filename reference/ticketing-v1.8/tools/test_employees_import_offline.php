<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «بارگذاری لیست کارکنان از Excel/CSV» — نسخهٔ ۱.۳۷.۴
 * ============================================================================
 *   php tools/test_employees_import_offline.php
 *
 * چه چیزی را ثابت می‌کند:
 *   ۱) فایل xlsx واقعی (ZipArchive + sharedStrings) با سرستون فارسی خوانده می‌شود.
 *   ۲) فایل CSV با/بدون سرستون، جداکنندهٔ «;» و ارقام فارسی خوانده می‌شود.
 *   ۳) رکورد در جدول users نوشته می‌شود: کلید یکتایی = employee_number (= L_UID).
 *   ۴) رکورد موجود به‌روزرسانی می‌شود؛ کد ملی خالی در فایل، مقدار سالمِ موجود را پاک نمی‌کند.
 *   ۵) تکرار در همان فایل رد می‌شود؛ ردیف بدون کد پرسنلی رد می‌شود.
 *   ۶) قرارداد پنل: دکمهٔ browse (input type=file) و ارسال CSRF به مسیر employees-import.
 *
 * بدون MySQL: db() با PDO/SQLite در حافظه شبیه‌سازی می‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $label\n";
        return;
    }
    $fail++;
    echo "[FAIL] $label" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

$root = dirname(__DIR__);

/* ─────────────── دیتابیس آزمون (PDO/SQLite در حافظه) ─────────────── */
$GLOBALS['FT_DB'] = null;
function db(): PDO
{
    if ($GLOBALS['FT_DB'] === null) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            password_hash TEXT,
            full_name TEXT,
            first_name TEXT,
            last_name TEXT,
            employee_number TEXT,
            national_code TEXT,
            role TEXT,
            auth_source TEXT,
            is_active INTEGER
        )');
        $GLOBALS['FT_DB'] = $pdo;
    }
    return $GLOBALS['FT_DB'];
}

require_once $root . '/food-ticket-import.php';

/* ─────────────── ساخت فایل xlsx واقعی بدون Composer ─────────────── */
function make_xlsx(string $file, array $rows): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    $shared = [];
    $index = [];
    $sheetRows = '';
    foreach ($rows as $ri => $row) {
        $cells = '';
        foreach ($row as $ci => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $value = (string) $value;
            if (!isset($index[$value])) {
                $index[$value] = count($shared);
                $shared[] = $value;
            }
            $column = chr(65 + $ci);
            $cells .= '<c r="' . $column . ($ri + 1) . '" t="s"><v>' . $index[$value] . '</v></c>';
        }
        $sheetRows .= '<row r="' . ($ri + 1) . '">' . $cells . '</row>';
    }
    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $text) {
        $sharedXml .= '<si><t>' . $esc($text) . '</t></si>';
    }
    $sharedXml .= '</sst>';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    return $zip->close();
}

$tmp = sys_get_temp_dir() . '/ft_emp_' . getmypid();
@mkdir($tmp, 0777, true);
echo "=== آزمون ایمپورت کارکنان از Excel/CSV (۱.۳۷.۴) ===\n";

/* ─────────── ۱) فایل نمونهٔ xlsx با سرستون فارسی ─────────── */
$xlsx = $tmp . '/sample.xlsx';
$made = make_xlsx($xlsx, [
    ['کد پرسنلی', 'نام', 'نام خانوادگی', 'کد ملی'],
    ['1001', 'علی', 'رضایی', '0012345678'],
    ['1002', 'مریم', 'حسینی', '0087654321'],
]);
if (!class_exists('ZipArchive')) {
    echo "[SKIP] افزونهٔ zip در این PHP نیست؛ بخش xlsx رد شد.\n";
} else {
    check('۱: فایل نمونهٔ xlsx ساخته شد', $made);
    $parsed = food_ticket_import_parse_file($xlsx, 'نمونه-لیست-کارکنان.xlsx');
    check('۱-ب: قالب xlsx تشخیص داده شد', ($parsed['format'] ?? '') === 'xlsx', (string) ($parsed['error'] ?? ''));
    check('۱-ج: سرستون‌ها شناسایی شدند (detected)', !empty($parsed['detected']));
    check('۱-د: دو ردیف داده خوانده شد', ($parsed['total'] ?? 0) === 2, (string) ($parsed['total'] ?? -1));
    $row1 = $parsed['rows'][0] ?? [];
    check('۱-ه: ستون‌ها درست نگاشت شدند (کد/نام/خانوادگی/کد ملی)',
        ($row1['pc'] ?? '') === '1001' && ($row1['first'] ?? '') === 'علی'
        && ($row1['last'] ?? '') === 'رضایی' && ($row1['nat'] ?? '') === '0012345678',
        json_encode($row1, JSON_UNESCAPED_UNICODE));
}

/* ─────────── ۲) CSV بدون سرستون ⇒ ترتیب پیش‌فرض ─────────── */
$csvNoHeader = $tmp . '/no-header.csv';
file_put_contents($csvNoHeader, "1003,سارا,کریمی,1112223334\n1004,رضا,مهدوی,\n");
$p2 = food_ticket_import_parse_file($csvNoHeader, 'no-header.csv');
check('۲: فایل بدون سرستون هم خوانده می‌شود (ترتیب پیش‌فرض ستون‌ها)', ($p2['total'] ?? 0) === 2);
check('۲-ب: کد پرسنلی از ستون اول و نام خانوادگی از ستون سوم', ($p2['rows'][0]['pc'] ?? '') === '1003' && ($p2['rows'][1]['last'] ?? '') === 'مهدوی');
check('۲-ج: کد ملی خالی خطا نیست (ردیف ثبت می‌شود)',
    ($p2['rows'][1]['nat'] ?? 'x') === '' && ($p2['rows'][1]['status'] ?? '') !== 'skipped');

/* ─────────── ۳) CSV با سرستون انگلیسی، جداکنندهٔ «;» و ارقام فارسی ─────────── */
$csvFa = $tmp . '/fa.csv';
file_put_contents($csvFa, "کد کارمندی;first_name;last_name;کد ملی\n۱۰۰۵;نگار;سلطانی;۱۲۳۴۵۶۷۸۹۰\n");
$p3 = food_ticket_import_parse_file($csvFa, 'fa.csv');
check('۳: ارقام فارسی به لاتین تبدیل می‌شوند', ($p3['rows'][0]['pc'] ?? '') === '1005', (string) ($p3['rows'][0]['pc'] ?? ''));
check('۳-ب: مترادف‌های انگلیسی سرستون‌ها شناسایی می‌شوند',
    ($p3['rows'][0]['first'] ?? '') === 'نگار' && ($p3['rows'][0]['nat'] ?? '') === '1234567890',
    (string) ($p3['rows'][0]['nat'] ?? ''));

/* ─────────── ۴) نرمال‌سازی ی/ك و رونویسی نام ─────────── */
$csvArabic = $tmp . '/arabic.csv';
file_put_contents($csvArabic, "کد پرسنلی,نام,نام خانوادگی,کد ملی\n 1007 ,زهرا,اكبري,\n1006,حسن,ميرزايي,1010101010\n");
$p4 = food_ticket_import_parse_file($csvArabic, 'arabic.csv');
check('۴: «ي/ك» عربی به «ی/ک» فارسی تبدیل می‌شود',
    ($p4['rows'][1]['last'] ?? '') === 'میرزایی' && ($p4['rows'][0]['last'] ?? '') === 'اکبری',
    json_encode([$p4['rows'][0]['last'] ?? '', $p4['rows'][1]['last'] ?? ''], JSON_UNESCAPED_UNICODE));
check('۴-ب: فاصلهٔ اضافی کد پرسنلی حذف می‌شود', ($p4['rows'][0]['pc'] ?? '') === '1007', (string) ($p4['rows'][0]['pc'] ?? ''));

/* ─────────── ۵) اعتبارسنجی ─────────── */
$v1 = food_ticket_import_validate_row(['pc' => '2001', 'first' => 'الف', 'last' => 'ب', 'nat' => '1234567890']);
$v2 = food_ticket_import_validate_row(['pc' => '', 'first' => 'بی‌نام', 'last' => 'خ', 'nat' => '']);
$v3 = food_ticket_import_validate_row(['pc' => '2002', 'first' => 'ج', 'last' => 'د', 'nat' => '']);
$v4 = food_ticket_import_validate_row(['pc' => '2003', 'first' => 'ه', 'last' => 'و', 'nat' => '123']);
check('۵: ردیف کامل سالم است', $v1['ok'] === true && $v1['warnings'] === 0);
check('۵-ب: ردیف بدون کد پرسنلی رد می‌شود', $v2['ok'] === false);
check('۵-ج: کد ملی خالی = هشدار (نه خطا)', $v3['ok'] === true && $v3['warnings'] === 1, json_encode($v3, JSON_UNESCAPED_UNICODE));
check('۵-د: کد ملی کوتاه هشدار می‌دهد ولی ردیف رد نمی‌شود', $v4['ok'] === true && $v4['warnings'] === 1);

/* ─────────── ۶) نوشتن در جدول users + به‌روزرسانی + تکرار ─────────── */
$applyFile = $tmp . '/apply.csv';
file_put_contents($applyFile, "کد پرسنلی,نام,نام خانوادگی,کد ملی\n"
    . "1001,علی,رضایی,0012345678\n"
    . "1002,مریم,حسینی,0087654321\n"
    . "1001,علی,رضایی‌نژاد,0012345678\n"
    . ",بی‌کد,بی‌نام,1234567890\n"
    . "1003,سارا,کریمی,\n");
$parsed = food_ticket_import_parse_file($applyFile, 'apply.csv');
$applied = food_ticket_import_apply($parsed, true);
check('۶: سه رکورد تازه در جدول users نوشته شد', $applied['created'] === 3, (string) $applied['created']);
check('۶-ب: تکرار داخل فایل رد شد (duplicate)', $applied['duplicates'] === 1, (string) $applied['duplicates']);
check('۶-ج: ردیف بدون کد پرسنلی رد شد', $applied['skipped'] === 1, (string) $applied['skipped']);
$count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
check('۶-د: تعداد ردیف‌های جدول users درست است', $count === 3, (string) $count);
$stored = db()->query('SELECT * FROM users WHERE employee_number = "1001"')->fetch(PDO::FETCH_ASSOC);
check('۶-ه: کد پرسنلی همان employee_number ذخیره شده (کلید تطبیق با L_UID)',
    is_array($stored) && (string) $stored['employee_number'] === '1001' && (string) $stored['national_code'] === '0012345678',
    json_encode($stored, JSON_UNESCAPED_UNICODE));
check('۶-و: نام کامل خودکار ساخته شده است',
    is_array($stored) && (string) $stored['full_name'] === 'علی رضایی', (string) ($stored['full_name'] ?? ''));

/* ─────────── ۷) اجرای دوبارهٔ همان فایل = به‌روزرسانی، نه تکرار ─────────── */
$applied2 = food_ticket_import_apply($parsed, true);
check('۷: اجرای دوباره هیچ رکورد تازه‌ای نمی‌سازد', $applied2['created'] === 0, (string) $applied2['created']);
check('۷-ب: رکوردهای موجود به‌روزرسانی می‌شوند (updated=3)',
    $applied2['updated'] === 3, (string) $applied2['updated']);
check('۷-ج: تعداد ردیف‌های جدول تغییر نکرد (بدون تکرار)',
    (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3);

/* ─────────── ۸) کد ملی خالی، مقدار سالم موجود را پاک نمی‌کند ─────────── */
$keepFile = $tmp . '/keep.csv';
file_put_contents($keepFile, "کد پرسنلی,نام,نام خانوادگی,کد ملی\n1001,علی,رضایی,\n");
$applied3 = food_ticket_import_apply(food_ticket_import_parse_file($keepFile, 'keep.csv'), true);
$after = db()->query('SELECT national_code FROM users WHERE employee_number = "1001"')->fetchColumn();
check('۸: کد ملی خالی در فایل، مقدار ثبت‌شدهٔ قبلی را پاک نمی‌کند',
    (string) $after === '0012345678' && $applied3['updated'] === 1, (string) $after);

/* ─────────── ۹) update=0 ⇒ رکورد موجود دست‌نخورده می‌ماند ─────────── */
$applied4 = food_ticket_import_apply($parsed, false);
check('۹: با update=0 رکورد موجود دست‌نخورده می‌ماند (status=exists)',
    $applied4['updated'] === 0 && in_array('exists', array_column($applied4['rows'], 'status'), true),
    json_encode(array_column($applied4['rows'], 'status')));

/* ─────────── ۱۰) خلاصهٔ قابل نمایش در پنل ─────────── */
$summary = food_ticket_import_summary($parsed, $applied, 'apply.csv');
check('۱۰: خلاصه، آمار لازم برای نمایش در پنل را دارد',
    !empty($summary['ok']) && $summary['total'] === 5 && $summary['created'] === 3
    && $summary['duplicates'] === 1 && $summary['skipped'] === 1 && is_array($summary['rows']));
check('۱۰-ب: خلاصه اعداد را با واحد «کارمند» توضیح می‌دهد',
    strpos((string) $summary['message'], 'کارمند') !== false, (string) $summary['message']);
check('۱۰-ج: سقف ۳۰۰ ردیف برای نمایش رعایت می‌شود', count($summary['rows']) <= 300);

/* ─────────── ۱۱) قرارداد پنل و سرور ─────────── */
$html = (string) file_get_contents($root . '/food-ticket-web/index.html');
check('۱۱: دکمهٔ browse (input type=file) در پنل هست',
    strpos($html, 'data-action="import-employees"') !== false && strpos($html, 'type="file"') !== false);
check('۱۱-ب: پنل فایل را به مسیر /api/employees-import می‌فرستد',
    strpos($html, '/api/employees-import') !== false);
check('۱۱-ج: توکن CSRF همراه آپلود فرستاده می‌شود',
    strpos($html, "headers['X-CSRF-Token']=window.FOOD_TICKET_CSRF") !== false);
$server = (string) file_get_contents($root . '/food-ticket.php');
check('۱۱-د: مسیر employees-import در سرور ثبت و به هندلر وصل شده است',
    strpos($server, "'employees-import'") !== false && strpos($server, 'food_ticket_employees_import_handle') !== false);
check('۱۱-ه: ورودی مسیر ایمپورت به مجوز کارکنان گره خورده است',
    (bool) preg_match("/employees-import'\\s*=>\\s*'food\\.employees'/", $server));
check('۱۱-و: نوشتن ردیف‌ها روی جدول users انجام می‌شود (نه جدول موازی)',
    strpos((string) file_get_contents($root . '/food-ticket-import.php'), 'INSERT INTO users') !== false
    && strpos((string) file_get_contents($root . '/food-ticket-import.php'), 'UPDATE users SET') !== false);

check('۱۱-ز: مسیر دانلود نمونهٔ فایل با مجوز کارکنان ثبت شده است',
    (bool) preg_match("/'employees-sample'\\s*=>\\s*'food\\.employees'/", $server));
$samplePath = $root . '/food-ticket-web/samples/employees-sample.xlsx';
$sampleParsed = is_file($samplePath) ? food_ticket_import_parse_file($samplePath, 'employees-sample.xlsx') : ['total' => -1, 'rows' => []];
check('۱۱-ح: فایل نمونهٔ دانلودی بدون دادهٔ شخصی و فقط شامل سرستون‌هاست',
    ($sampleParsed['ok'] ?? false) === true
    && ($sampleParsed['total'] ?? -1) === 0
    && ($sampleParsed['rows'] ?? null) === [], (string) ($sampleParsed['total'] ?? -1));
check('۱۱-ی: لینک دانلود نمونه در پنل به مسیر سرور (نه مسیر نسبی) وصل است',
    strpos($html, "apiUrl('/api/employees-sample?format=xlsx')") !== false
    && strpos($html, 'href="samples/employees-sample.xlsx"') === false);

echo "\n" . ($fail === 0
    ? "همهٔ $pass بررسی ایمپورت کارکنان (۱.۳۷.۴) موفق بود.\n"
    : "$fail بررسی ناموفق از " . ($pass + $fail) . " مورد.\n");

foreach (glob($tmp . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmp);
exit($fail === 0 ? 0 : 1);
