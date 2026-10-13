<?php
declare(strict_types=1);

/**
 * تست چاپ شبکه (فقط CLI):
 *   C:\xampp\php\php.exe tools\print_test.php --png     فقط پیش‌نمایش PNG می‌سازد (چاپگر لازم نیست)
 *   C:\xampp\php\php.exe tools\print_test.php --send    فیش نمونه را روی چاپگر تنظیم‌شده در پنل چاپ می‌کند
 * پیش‌نمایش: storage\food_ticket_prints\netprint_preview.png
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';
foreach (['food-ticket-runtime.php', 'food-ticket-engine.php', 'food-ticket-netprint.php'] as $extra) {
    $p = dirname(__DIR__) . '/' . $extra;
    if (is_file($p)) {
        require_once $p;
    }
}

$args = array_slice($argv, 1);
$event = [
    'full_name' => 'علی رضایی', 'national_code' => '0012345678', 'personnel_code' => '377',
    'food_type' => 'چلوکباب کوبیده (ویژه)', 'punch_date' => date('Y-m-d'), 'punch_time' => date('H:i:s'),
    'event_type' => 'printed',
];
$text = food_ticket_text($event);
echo "--- متن فیش ---\n" . $text . "\n";

if (in_array('--png', $args, true) || !in_array('--send', $args, true)) {
    $tpl = food_ticket_template();
    $cfg = food_ticket_np_settings($tpl);
    $im = food_ticket_np_render_layout(food_ticket_np_layout($event, $tpl), $tpl, $cfg);
    $dir = APP_ROOT . '/storage/food_ticket_prints';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $file = $dir . '/netprint_preview.png';
    imagepng($im, $file);
    echo "پیش‌نمایش ذخیره شد: $file (" . imagesx($im) . 'x' . imagesy($im) . ")\n";
}
if (in_array('--send', $args, true)) {
    $config = food_ticket_config(true);
    $start = microtime(true);
    food_ticket_send_raw_payload($text, $config, food_ticket_template(), $event); // همان مسیر Worker
    printf("ارسال شد (%.2f ثانیه) به %s:%s\n", microtime(true) - $start, $config['printer_host'] ?? '?', $config['printer_port'] ?? '9100');
}
