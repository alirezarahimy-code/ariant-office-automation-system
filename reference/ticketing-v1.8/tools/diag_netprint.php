<?php
declare(strict_types=1);

/**
 * تشخیص چاپ شبکه (فقط CLI) — همان مسیر Worker را مرحله‌به‌مرحله بررسی می‌کند و علت دقیق شکست را نشان می‌دهد.
 *
 *   C:\xampp\php\php.exe tools\diag_netprint.php            فقط بررسی (چیزی چاپ نمی‌شود)
 *   C:\xampp\php\php.exe tools\diag_netprint.php --send     یک فیش آزمایشی هم چاپ می‌کند
 *
 * مهم: آن را با همان حسابی اجرا کنید که تسک Worker با آن اجرا می‌شود.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$ok = static function (string $label, bool $pass, string $detail = ''): void {
    echo ($pass ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

echo "PHP " . PHP_VERSION . ' | ini: ' . (php_ini_loaded_file() ?: '(none)') . "\n";
echo 'user: ' . (getenv('USERNAME') ?: get_current_user()) . ' | SystemRoot: ' . (getenv('SystemRoot') ?: '(خالی)') . "\n\n";

$ok('افزونه gd', extension_loaded('gd'));
$ok('imagettftext (FreeType)', function_exists('imagettftext'));
$ok('افزونه mbstring', extension_loaded('mbstring'));
$ok('proc_open', function_exists('proc_open'));

try {
    require dirname(__DIR__) . '/bootstrap.php';
    require dirname(__DIR__) . '/food-ticket.php';
    foreach (['food-ticket-runtime.php', 'food-ticket-engine.php', 'food-ticket-netprint.php'] as $extra) {
        $p = dirname(__DIR__) . '/' . $extra;
        if (is_file($p)) {
            require_once $p;
        }
    }
    $ok('بارگذاری برنامه', true);
} catch (Throwable $e) {
    $ok('بارگذاری برنامه', false, $e->getMessage());
    exit(1);
}

$ok('ماژول food-ticket-netprint', function_exists('food_ticket_np_send_event'));

try {
    $config = food_ticket_config(true);
    $mode = (string) ($config['printer_mode'] ?? '');
    $host = trim((string) ($config['printer_host'] ?? ''));
    $port = (int) ($config['printer_port'] ?? 9100);
    $share = trim((string) ($config['printer_share'] ?? ''));
    echo "\nتنظیم ذخیره‌شده در دیتابیس: mode=$mode | host=$host | port=$port | share=$share\n";
    $ok('حالت شبکه (tcp_raw) است', $mode === 'tcp_raw', $mode === 'tcp_raw' ? '' : 'حالت ذخیره‌شده ویندوز است؛ در پنل دوباره «چاپگر شبکه» را ذخیره کنید');
    $ok('آدرس چاپگر خالی نیست', $host !== '');
} catch (Throwable $e) {
    $ok('خواندن تنظیمات', false, $e->getMessage());
    exit(1);
}

$tpl = food_ticket_template();
$cfg = food_ticket_np_settings($tpl);
try {
    $reg = food_ticket_np_find_font_for($tpl, $cfg, false);
    $ok('فونت پیدا شد', is_readable($reg), $reg);
} catch (Throwable $e) {
    $ok('فونت', false, $e->getMessage());
}

if ($host !== '') {
    $errno = 0;
    $err = '';
    $t = microtime(true);
    $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $err, 4);
    $ok('اتصال TCP به چاپگر', (bool) $sock, sprintf('%.2f ثانیه %s', microtime(true) - $t, $sock ? '' : "($errno) $err"));
    if ($sock) {
        fclose($sock);
    }
}

$event = [
    'full_name' => 'علی رضایی', 'national_code' => '0012345678', 'personnel_code' => '377',
    'food_type' => 'چلوکباب کوبیده', 'punch_date' => date('Y-m-d'), 'punch_time' => date('H:i:s'),
    'event_type' => 'printed',
];
try {
    $im = food_ticket_np_render_layout(food_ticket_np_layout($event, $tpl), $tpl, $cfg);
    $payload = food_ticket_np_image_payload($im, $cfg);
    $ok('رسم فیش و ساخت دستورهای ESC/POS', true, imagesx($im) . 'x' . imagesy($im) . ' | ' . strlen($payload) . ' بایت');
} catch (Throwable $e) {
    $ok('رسم فیش', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}

if (in_array('--send', array_slice($argv, 1), true)) {
    try {
        $start = microtime(true);
        food_ticket_send_raw_payload(food_ticket_text($event), $config, $tpl, $event);
        $ok('ارسال به چاپگر', true, sprintf('%.2f ثانیه', microtime(true) - $start));
    } catch (Throwable $e) {
        $ok('ارسال به چاپگر', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

try {
    $rows = db()->query('SELECT id, print_status, printer_name, print_attempts, last_error, updated_at FROM food_ticket_events ORDER BY id DESC LIMIT 5')->fetchAll();
    echo "\nآخرین ۵ رویداد چاپ:\n";
    foreach ($rows as $r) {
        echo '  #' . $r['id'] . ' ' . $r['print_status'] . ' | ' . ($r['printer_name'] ?? '') . ' | تلاش=' . ($r['print_attempts'] ?? '') . ' | ' . ($r['updated_at'] ?? '') . "\n      last_error: " . (($r['last_error'] ?? '') !== '' ? $r['last_error'] : '-') . "\n";
    }
} catch (Throwable $e) {
    echo "\nخواندن رویدادها ناموفق: " . $e->getMessage() . "\n";
}
