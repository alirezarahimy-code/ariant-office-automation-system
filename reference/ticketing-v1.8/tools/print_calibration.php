<?php
declare(strict_types=1);

/**
 * کارتِ «کالیبراسیون چاپ» — نسخهٔ ۱.۳۷
 * ============================================================================
 *   C:\xampp\php\php.exe tools\print_calibration.php              چاپ کارت کالیبراسیون (شبکه)
 *   C:\xampp\php\php.exe tools\print_calibration.php --dry-run    فقط ساخت PNG (بدون چاپ)
 *   C:\xampp\php\php.exe tools\print_calibration.php --host=192.168.1.50 --port=9100
 *
 * این کارت یک «خط‌کش میلی‌متری» است که از ردیف صفر (اولین نقطهٔ ممکن) شروع می‌شود.
 * پس از چاپ، با یک خط‌کش واقعی این دو عدد را اندازه بگیرید:
 *
 *   ۱) فاصلهٔ لبهٔ بالای کاغذ تا نوار سیاه/عدد ۰  →  همان «head_offset_mm» است
 *      (فاصلهٔ سرِ چاپ تا تیغهٔ برش). این عدد را در storage/food_ticket_netprint.json بگذارید:
 *          { "head_offset_mm": 20, "edge_align": true, "tail_mm": 3 }
 *      از این پس: ابتدای چاپ = ابتدای کاغذ و انتهای چاپ = انتهای کاغذ.
 *
 *   ۲) فاصلهٔ آخرین خط چاپ‌شده تا محل برش  →  باید برابر «tail_mm» باشد (پیش‌فرض ۳ میلی‌متر).
 *
 * اگر پس از تنظیم، شماره‌ها روی کارتِ بعدی جابه‌جا نشد، یعنی چاپگر فرمان عقب‌راندن (ESC e n)
 * را پشتیبانی نمی‌کند؛ در آن حالت مقدار reverse_feed_cmd را به esc_k تغییر دهید و باز امتحان
 * کنید. اگر هیچ‌کدام کار نکرد، پرتِ بالای فیش سخت‌افزاری است و باید در تنظیمات خود چاپگر
 * (Back feed / Cut position) تغییر داده شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', (string) $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}
$say = static function (string $line): void {
    echo $line . "\n";
};

$say('=== کارت کالیبراسیون چاپ فیش (۱.۳۷) ===');
$say('PHP ' . PHP_VERSION . ' | OS ' . PHP_OS_FAMILY);

if (!function_exists('imagecreatetruecolor')) {
    $say('[FAIL] افزونهٔ GD فعال نیست؛ بدون آن نمی‌توان کارت را ساخت.');
    exit(1);
}
if (!function_exists('imagettftext')) {
    $say('[WARN] GD بدون FreeType است؛ اعداد با فونت پیش‌فرض رسم می‌شوند.');
}

try {
    require dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/food-ticket-netprint.php';
    require_once dirname(__DIR__) . '/food-ticket-templates.php';
} catch (Throwable $e) {
    $say('[FAIL] بارگذاری برنامه: ' . $e->getMessage());
    exit(1);
}

$cfg = food_ticket_np_settings();
$root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
$jsonPath = $root . '/storage/food_ticket_netprint.json';

// ── تنظیمات فعلی و اینکه چه چیزی روی این کارت اثر می‌گذارد ────────────────────
$say('');
$say('--- تنظیمات فعلی (' . $jsonPath . ') ---');
foreach (['dots_per_mm', 'head_dots', 'top_gap_mm', 'tail_mm', 'fit_bottom', 'head_offset_mm', 'edge_align', 'line_dots', 'reverse_feed_cmd', 'feed_lines', 'cut'] as $k) {
    $v = $cfg[$k] ?? null;
    $say(sprintf('  %-18s = %s', $k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
}

$dpm = (float) $cfg['dots_per_mm'];
if ($dpm <= 0) {
    $dpm = 8.0;
}

// ── ساخت کارت با همان تابع مشترک چاپ (منبع یکتا: food_ticket_np_calibration_image) ──
$im = food_ticket_np_calibration_image($cfg);
$head = imagesx($im);
$H = imagesy($im);

$raw = food_ticket_np_escpos($im, $cfg);
$align = food_ticket_print_align_info();
$tmpPng = $root . '/storage/food_ticket_prints/calibration_' . date('Ymd_His') . '.png';
@mkdir(dirname($tmpPng), 0750, true);
@imagepng($im, $tmpPng);

$say('');
$say('--- وضعیت ترازِ لبه‌ها روی همین کارت ---');
$say(sprintf('  ارتفاع کارت: %.1f میلی‌متر | عرض سر چاپ: %d نقطه', $H / $dpm, $head));
$say('  ترازِ دقیق لبه‌ها: ' . (!empty($align['edge_align']) ? 'فعال' : 'غیرفعال (head_offset_mm صفر است)'));
if (!empty($align['edge_align'])) {
    $say(sprintf('  عقب‌راندن کاغذ پیش از چاپ: %d خط (%d نقطه ≈ %.1f میلی‌متر)', (int) $align['reverse_lines'], (int) $align['reverse_dots'], (int) $align['reverse_dots'] / $dpm));
    $say(sprintf('  تغذیهٔ پیش از برش: %d نقطه ≈ %.1f میلی‌متر (بعد از آخرین خط، تا محل برش)', (int) $align['cut_feed_dots'], (int) $align['cut_feed_dots'] / $dpm));
} else {
    $say('  توجه: چون head_offset_mm صفر است، این کارت مثل قبل با پرتِ بالای کاغذ چاپ می‌شود؛');
    $say('        فاصلهٔ لبهٔ کاغذ تا نوار سیاه را اندازه بگیرید و همان عدد را head_offset_mm بگذارید.');
}
$say('  تصویر کارت (برای مرور): ' . $tmpPng);

// ── ارسال به چاپگر ─────────────────────────────────────────────────────────
$mode = (string) setting('food_ticket_printer_mode', 'tcp_raw');
$host = trim((string) ($options['host'] ?? setting('food_ticket_netprint_host', setting('food_ticket_printer_host', ''))));
$port = max(1, (int) ($options['port'] ?? setting('food_ticket_netprint_port', setting('food_ticket_printer_port', 9100))));

$say('');
if (isset($options['dry_run'])) {
    $say('[SKIP] حالت --dry-run: چیزی به چاپگر فرستاده نشد. تصویر آماده در مسیر بالا است.');
    exit(0);
}
if ($mode === 'windows_share') {
    $say('[WARN] حالت چاپ این سامانه «صف ویندوز» است و این ابزار از مسیر شبکه چاپ می‌کند.');
    $say('        برای کالیبراسیون در حالت ویندوز: کارت را چاپ کنید، فاصلهٔ لبه تا نوار سیاه را');
    $say('        اندازه بگیرید و همان عدد را با علامت منفی در win_top_shift_mm بگذارید (مثلاً -20).');
}
if ($host === '') {
    $say('[FAIL] آدرس چاپگر معلوم نیست. با --host=IP اجرا کنید یا در پنل غذا آدرس چاپگر را ذخیره کنید.');
    exit(1);
}

try {
    food_ticket_np_send($host, $port, $raw, (int) ($cfg['io_timeout'] ?? 10));
    $say('[ OK ] کارت کالیبراسیون به ' . $host . ':' . $port . ' فرستاده شد (' . strlen($raw) . ' بایت).');
    $say('');
    $say('گام بعدی:');
    $say('  ۱) فاصلهٔ لبهٔ بالای کاغذ تا نوار سیاه را با خط‌کش اندازه بگیرید → همان عدد = head_offset_mm');
    $say('  ۲) در storage/food_ticket_netprint.json بگذارید: {"head_offset_mm": <عدد>, "edge_align": true, "tail_mm": 3}');
    $say('  ۳) همین ابزار را دوباره اجرا کنید: اگر نوار سیاه روی لبهٔ کاغذ افتاد، کار تمام است.');
    $say('  ۴) اگر تغییری نکرد: reverse_feed_cmd را به esc_k تغییر دهید؛ اگر باز هم نه، چاپگر');
    $say('     فرمان عقب‌راندن ندارد و باید در تنظیمات خود چاپگر (Back feed) اصلاح شود.');
} catch (Throwable $e) {
    $say('[FAIL] ارسال به چاپگر ناموفق بود: ' . $e->getMessage());
    exit(1);
}
