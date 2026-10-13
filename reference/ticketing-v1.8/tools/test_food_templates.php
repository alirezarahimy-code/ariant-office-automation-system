<?php
declare(strict_types=1);

/**
 * آزمون قالب فیش غذا — بدون چاپ واقعی و بدون مصرف کاغذ.
 *
 *   C:\xampp\php\php.exe tools\test_food_templates.php
 *   C:\xampp\php\php.exe tools\test_food_templates.php --keep     (قالب‌های آزمون پاک نشوند)
 *
 * سناریوها: ۱) قالب ۵۰×۵۰ با لوگو/نام سامانه/نام کارمند  ۲) تغییر فونت نام به ۱۶  ۳) حذف کد ملی
 *           ۴) حداکثر ۴ قالب  ۵) تغییر قالب فعال و فیش بعدی
 * مسیرها:   شبکه (ESC/POS به یک سرور TCP محلی که بایت‌ها را می‌گیرد) و ویندوز (ساخت اسکریپت GDI+ و بررسی PaperSize).
 * خروجی PNGها: storage/food_ticket_prints/test_*.png
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';

$keep = in_array('--keep', $argv, true);
$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
};
$outDir = dirname(__DIR__) . '/storage/food_ticket_prints';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0750, true);
}

/** یک سرور TCP موقت روی پورت آزاد؛ بایت‌های دریافتی را برمی‌گرداند. */
$captureNetwork = static function (callable $send): string {
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        throw new RuntimeException('سرور آزمون ساخته نشد: ' . $errstr);
    }
    $name = stream_socket_get_name($server, false);
    $port = (int) substr((string) strrchr((string) $name, ':'), 1);
    stream_set_blocking($server, false);
    $data = '';
    $sender = static function () use ($send, $port): void {
        $send($port);
    };
    // ارسال در همین پردازش؛ سوکت سرور بافر سیستم‌عامل دارد پس بعد از ارسال می‌خوانیم.
    $sender();
    $deadline = microtime(true) + 3;
    $conn = null;
    while (microtime(true) < $deadline && !$conn) {
        $conn = @stream_socket_accept($server, 0.2);
    }
    if ($conn) {
        stream_set_timeout($conn, 1);
        while (!feof($conn)) {
            $chunk = fread($conn, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        fclose($conn);
    }
    fclose($server);
    return $data;
};

/** بایت‌های ESC/POS (GS v 0) → ابعاد و تعداد نقطهٔ سیاه. @return array{w:int,h:int,black:int,cut:bool} */
$parseEscpos = static function (string $b): array {
    $w = 0;
    $h = 0;
    $black = 0;
    $pos = 0;
    $len = strlen($b);
    while (($p = strpos($b, "\x1d\x76\x30\x00", $pos)) !== false) {
        $xl = unpack('v', substr($b, $p + 4, 2))[1];
        $yl = unpack('v', substr($b, $p + 6, 2))[1];
        $w = $xl * 8;
        $h += $yl;
        $data = substr($b, $p + 8, $xl * $yl);
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $black += substr_count(decbin(ord($data[$i])), '1');
        }
        $pos = $p + 8 + $xl * $yl;
        if ($pos >= $len) {
            break;
        }
    }
    return ['w' => $w, 'h' => $h, 'black' => $black, 'cut' => str_contains($b, "\x1d\x56")];
};

$event = [
    'id' => 4242, 'user_id' => 0,
    'full_name' => 'علی رضایی', 'personnel_code' => '1234', 'national_code' => '0012345678', 'department' => 'فناوری اطلاعات',
    'food_type' => 'چلوکباب', 'punch_date' => date('Y-m-d'), 'punch_time' => '12:41:00', 'ticket_key' => 'test:' . date('YmdHis'), 'event_type' => 'printed',
];

echo "== آماده‌سازی ==\n";
food_ticket_tpl_ensure();
$backup = db()->query('SELECT * FROM food_ticket_templates ORDER BY id')->fetchAll();
db()->exec('DELETE FROM food_ticket_templates');

$mkEl = static function (string $field, float $x, float $y, float $w, float $h, float $pt, bool $bold, string $align = 'center', array $extra = []): array {
    return array_merge([
        'id' => $field, 'field' => $field, 'enabled' => true, 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
        'font_family' => 'Tahoma', 'font_size' => $pt, 'bold' => $bold, 'align' => $align, 'line_height' => 1.2,
        'show_label' => false, 'label' => '', 'text' => '', 'border' => false,
    ], $extra);
};
$tpl50 = static function (float $namePt = 9.0, bool $withNational = true) use ($mkEl): array {
    return [
        'cut_paper' => true, 'border' => true, 'border_inset' => 2, 'logo_source' => 'system', 'name_source' => 'system',
        'elements' => array_values(array_filter([
            $mkEl('logo', 17, 2.2, 16, 9, 8, false),
            $mkEl('system_name', 3, 11.6, 44, 4, 7, true),
            $mkEl('employee_name', 3, 28, 44, 6, $namePt, true),
            $withNational ? $mkEl('national_code', 3, 38, 44, 5, 7, false, 'right', ['show_label' => true, 'label' => 'کد ملی']) : null,
            $mkEl('qrcode', 17, 36, 0, 0, 8, false, 'center', ['enabled' => false]),
        ])),
    ];
};

try {
    echo "\n== سناریو ۱: قالب ۵۰×۵۰ با لوگو + نام سامانه + نام کارمند ==\n";
    $id1 = food_ticket_tpl_save(null, 'قالب ۵۰×۵۰', 50, 50, $tpl50(9.0, true), true);
    $active = food_ticket_tpl_active();
    $check('قالب فعال از دیتابیس خوانده می‌شود', (int) $active['id'] === $id1);
    $check('paper_width/paper_height در دیتابیس ۵۰ و ۵۰', (float) $active['paper_width'] === 50.0 && (float) $active['paper_height'] === 50.0);
    $model = food_ticket_tpl_model($event);
    $types = array_map(static fn ($i) => $i['type'], $model['items']);
    $texts = array_column(array_filter($model['items'], static fn ($i) => $i['type'] === 'text'), 'text');
    $check('مدل چاپ شامل نام سامانه و نام کارمند', count($texts) >= 2 && in_array(food_ticket_tpl_fa('علی رضایی'), $texts, true));
    $check('مدل چاپ ابعاد ۵۰×۵۰ دارد', $model['paper_w'] === 50.0 && $model['paper_h'] === 50.0);
    echo '  (لوگو در مدل: ' . (in_array('image', $types, true) ? 'بله' : 'خیر — لوگوی سیستم تعریف نشده یا فایل پیدا نشد') . ")\n";

    $cfg = food_ticket_tpl_net_cfg();
    [$im, $info] = food_ticket_tpl_render_gd($model, $cfg);
    $check('تصویر شبکه ۸ نقطه بر میلی‌متر ⇒ ارتفاع ۴۰۰ نقطه', imagesy($im) === 400, 'ارتفاع=' . imagesy($im));
    $check('عرض تصویر برابر عرض سرِ چاپ (مضرب ۸)', imagesx($im) % 8 === 0 && imagesx($im) === (int) $info['head_dots']);
    imagepng(food_ticket_tpl_binarize($im, (int) $cfg['threshold']), $outDir . '/test_s1_50x50.png');

    // مسیر شبکه: همان بایت‌هایی که به چاپگر می‌رود
    [$bytes1] = food_ticket_tpl_network_payload($event);
    $p1 = $parseEscpos($bytes1);
    $check('مسیر شبکه: بایت ESC/POS raster ارسال شد', $p1['h'] === 400 && $p1['black'] > 100, json_encode($p1));
    $check('مسیر شبکه: برش کاغذ فعال', $p1['cut']);
    $tcp = $captureNetwork(static function (int $port) use ($event): void {
        food_ticket_tpl_print_network($event, '127.0.0.1', $port); // ارسال واقعی روی سوکت TCP محلی
    });
    $check('مسیر شبکه: ارسال TCP کامل رسید (هم‌اندازهٔ payload)', strlen($tcp) === strlen($bytes1), strlen($tcp) . ' vs ' . strlen($bytes1));

    // مسیر ویندوز: اسکریپت GDI+ با PaperSize ۵۰×۵۰
    $ps = food_ticket_tpl_windows_script(food_ticket_tpl_model($event), 'Test Printer');
    $payload = null;
    if (preg_match("/FromBase64String\\('([A-Za-z0-9+\\/=]+)'\\)/", $ps, $m) === 1) {
        $payload = json_decode((string) base64_decode($m[1]), true);
    }
    $check('مسیر ویندوز: payload اسکریپت ۵۰×۵۰ را دارد', is_array($payload) && (float) $payload['paper_w'] === 50.0 && (float) $payload['paper_h'] === 50.0);
    $check('مسیر ویندوز: PaperSize واقعی به درایور داده می‌شود', str_contains($ps, 'New-Object System.Drawing.Printing.PaperSize') && str_contains($ps, '$pd.DefaultPageSettings.PaperSize = $chosen'));
    $check('مسیر ویندوز و شبکه از یک مدل (همان تعداد آیتم)', is_array($payload) && count($payload['items']) === count($model['items']));

    echo "\n== سناریو ۲: فونت نام کارمند ۱۶ ==\n";
    $tplBig = $tpl50(16.0, true);
    food_ticket_tpl_save($id1, 'قالب ۵۰×۵۰', 50, 50, $tplBig, true);
    $m2 = food_ticket_tpl_model($event);
    $nameItem = null;
    foreach ($m2['items'] as $it) {
        if ($it['type'] === 'text' && str_contains((string) $it['text'], food_ticket_tpl_fa('علی'))) {
            $nameItem = $it;
        }
    }
    $check('فونت نام در مدل ۱۶pt', $nameItem !== null && (float) $nameItem['pt'] === 16.0);
    [$im2] = food_ticket_tpl_render_gd($m2, $cfg);
    imagepng(food_ticket_tpl_binarize($im2, (int) $cfg['threshold']), $outDir . '/test_s2_font16.png');
    [$bytes2] = food_ticket_tpl_network_payload($event);
    $p2 = $parseEscpos($bytes2);
    $check('مسیر شبکه: خروجی با فونت بزرگ‌تر تغییر کرد (نقاط سیاه بیشتر)', $p2['black'] > $p1['black'], $p1['black'] . ' → ' . $p2['black']);
    $ps2 = food_ticket_tpl_windows_script($m2, 'Test Printer');
    $check('مسیر ویندوز: اسکریپت فونت ۱۶ دارد', str_contains($ps2, 'FromBase64String') && $ps2 !== $ps);

    echo "\n== سناریو ۳: حذف کد ملی از قالب ==\n";
    $withNat = array_filter(food_ticket_tpl_model($event)['items'], static fn ($i) => $i['type'] === 'text' && str_contains((string) $i['text'], food_ticket_tpl_fa('0012345678')));
    $check('کد ملی قبل از حذف چاپ می‌شود', count($withNat) === 1);
    food_ticket_tpl_save($id1, 'قالب ۵۰×۵۰', 50, 50, $tpl50(16.0, false), true);
    $noNat = array_filter(food_ticket_tpl_model($event)['items'], static fn ($i) => $i['type'] === 'text' && str_contains((string) $i['text'], food_ticket_tpl_fa('0012345678')));
    $check('کد ملی بعد از حذف در مدل چاپ نیست', count($noNat) === 0);
    $psNo = food_ticket_tpl_windows_script(food_ticket_tpl_model($event), 'Test Printer');
    preg_match("/FromBase64String\\('([A-Za-z0-9+\\/=]+)'\\)/", $psNo, $m3);
    $check('مسیر ویندوز: کد ملی در payload نیست', !str_contains((string) base64_decode($m3[1] ?? ''), food_ticket_tpl_fa('0012345678')));
    [$bytes3] = food_ticket_tpl_network_payload($event);
    $p3 = $parseEscpos($bytes3);
    $check('مسیر شبکه: خروجی بدون کد ملی کم‌رنگ‌تر از قبل است', $p3['black'] < $p2['black'], $p2['black'] . ' → ' . $p3['black']);

    echo "\n== سناریو ۴: حداکثر ۴ قالب ==\n";
    for ($i = 2; $i <= 4; $i++) {
        food_ticket_tpl_save(null, 'قالب ' . $i, 80, 0, $tpl50(11.0, true), false);
    }
    $check('۴ قالب ذخیره شد', count(food_ticket_tpl_list()) === 4);
    $blocked = false;
    try {
        food_ticket_tpl_save(null, 'قالب ۵', 80, 0, $tpl50(11.0, true), false);
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), '۴') || str_contains($e->getMessage(), '4');
    }
    $check('قالب پنجم اجازهٔ ذخیره ندارد', $blocked);
    $check('تعداد قالب‌ها بعد از تلاش پنجم همچنان ۴', count(food_ticket_tpl_list()) === 4);

    echo "\n== سناریو ۵: تغییر قالب فعال ⇒ فیش بعدی با قالب جدید ==\n";
    $list = food_ticket_tpl_list();
    $other = null;
    foreach ($list as $t) {
        if ((int) $t['id'] !== $id1) {
            $other = (int) $t['id'];
            break;
        }
    }
    $before = food_ticket_tpl_model($event);
    food_ticket_tpl_activate((int) $other);
    $after = food_ticket_tpl_model($event);
    $check('قالب فعال عوض شد', (int) food_ticket_tpl_active()['id'] === $other);
    $check('فیش بعدی با اندازهٔ قالب جدید (۸۰mm) ساخته می‌شود', $before['paper_w'] === 50.0 && $after['paper_w'] === 80.0, $before['paper_w'] . ' → ' . $after['paper_w']);
    [$bytes5] = food_ticket_tpl_network_payload($event);
    $p5 = $parseEscpos($bytes5);
    $check('مسیر شبکه: عرض تصویر چاپی با قالب جدید فرق کرد', $p5['w'] !== $p3['w'] || $p5['h'] !== $p3['h'], $p3['w'] . 'x' . $p3['h'] . ' → ' . $p5['w'] . 'x' . $p5['h']);

    echo "\n== قالب پیش‌فرض وقتی قالبی نیست ==\n";
    db()->exec('DELETE FROM food_ticket_templates');
    $def = food_ticket_tpl_active();
    $check('بدون قالب ⇒ قالب پیش‌فرض استفاده می‌شود', (int) $def['id'] === 0 && count(food_ticket_tpl_model($event)['items']) > 0);

    echo "\n== QR Code ==\n";
    $qr = food_ticket_tpl_qr_matrix('food:1405/07/13:0012345678');
    $check('ماتریس QR مربعی و نسخهٔ ۲ (25×25)', count($qr) === 25 && strlen($qr[0]) === 25, (string) count($qr));
    $check('الگوی finder گوشه‌ها', str_starts_with($qr[0], '1111111') && str_starts_with($qr[6], '1000001'));
    $im = imagecreatetruecolor(count($qr) * 8 + 64, count($qr) * 8 + 64);
    imagefilledrectangle($im, 0, 0, imagesx($im), imagesy($im), 0xFFFFFF);
    foreach ($qr as $r => $row) {
        for ($c = 0; $c < strlen($row); $c++) {
            if ($row[$c] === '1') {
                imagefilledrectangle($im, 32 + $c * 8, 32 + $r * 8, 32 + $c * 8 + 7, 32 + $r * 8 + 7, 0x000000);
            }
        }
    }
    imagepng($im, $outDir . '/test_qr.png');
    echo "  (فایل test_qr.png را با گوشی بخوانید؛ باید food:1405/07/13:0012345678 باشد)\n";
} catch (Throwable $e) {
    $fail++;
    echo '  [FAIL] استثنا: ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if (!$keep) {
        db()->exec('DELETE FROM food_ticket_templates');
        foreach ($backup as $r) {
            db()->prepare('INSERT INTO food_ticket_templates (id, name, paper_width, paper_height, template_json, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$r['id'], $r['name'], $r['paper_width'], $r['paper_height'], $r['template_json'], $r['is_active'], $r['created_at'], $r['updated_at']]);
        }
    }
}

echo "\n== نتیجه: $pass موفق، $fail ناموفق ==\n";
echo "تصاویر آزمون: $outDir\\test_*.png\n";
exit($fail === 0 ? 0 : 1);
