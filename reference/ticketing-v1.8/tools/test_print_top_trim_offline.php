<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «فاصلهٔ بالای فیش» — نسخهٔ ۱.۳۴
 * ============================================================================
 *   php tools/test_print_top_trim_offline.php
 *
 * چه چیزی را ثابت می‌کند:
 *   ۱) food_ticket_tpl_trim_top: اگر بالای طرح جای خالی زیادی باشد (همان ۲۲ میلی‌متر
 *      قالب پیش‌فرض که لوگویش موجود نیست)، همهٔ عناصر به یک اندازه بالا می‌آیند؛
 *      چیدمان نسبی دست‌نخورده می‌ماند و فاصلهٔ بالا ≤ حد مجاز (۴mm) می‌شود.
 *   ۲) اگر فاصله از قبل کم باشد، هیچ‌چیز جابه‌جا نمی‌شود (طراحی کاربر نمی‌شکند).
 *   ۳) food_ticket_np_trim_top: ردیف‌های سفید ابتدای تصویر بُرده می‌شود، محتوا
 *      دست‌نخورده می‌ماند و فاصلهٔ باقی‌مانده از ۴ میلی‌متر بیشتر نمی‌شود.
 *
 * بدون MySQL، بدون GD اجباری (بخش تصویر فقط اگر GD موجود باشد اجرا می‌شود).
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
        echo '[PASS] ' . $label . "\n";
        return;
    }
    $fail++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

/** بدنهٔ یک تابع را از فایل برمی‌دارد (بدون بارگذاری کل پروژه). */
function grabFunction(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name . ' در ' . $file);
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

echo "=== آزمون فاصلهٔ بالای فیش (۱.۳۴) ===\n";

/* ───────── ۱) مدل: هم‌ترازی بالای طرح ───────── */
$templatesFile = dirname(__DIR__) . '/food-ticket-templates.php';
eval(grabFunction($templatesFile, 'food_ticket_tpl_first_ink_mm'));
eval(grabFunction($templatesFile, 'food_ticket_tpl_trim_top'));

// همان وضعیت قالب پیش‌فرضِ بدون لوگو: اولین متن در y=22mm
$model = [
    'template_id' => 1,
    'template_name' => 'پیش‌فرض',
    'paper_w' => 80.0,
    'paper_h' => 50.0,
    'variable' => true,
    'items' => [
        ['type' => 'text', 'x' => 6.0, 'y' => 22.0, 'w' => 68.0, 'h' => 8.0, 'text' => 'سامانه خدمات'],
        ['type' => 'line', 'x' => 6.0, 'y' => 37.5, 'w' => 68.0, 'h' => 0.0],
        ['type' => 'text', 'x' => 6.0, 'y' => 40.0, 'w' => 68.0, 'h' => 11.0, 'text' => 'چلوکباب'],
    ],
];
$first = food_ticket_tpl_first_ink_mm($model['items']);
check('۱: اولین جوهر مدل = ۲۲ میلی‌متر (وضعیت شکایت)', abs($first - 22.0) < 0.001, (string) $first);

[$trimmed, $shift] = food_ticket_tpl_trim_top($model, 4.0);
check('۱-ب: جابه‌جایی ۱۸ میلی‌متر محاسبه شد', abs($shift - 18.0) < 0.001, (string) $shift);
check('۱-پ: اولین عنصر در ۴ میلی‌متر نشست', abs((float) $trimmed['items'][0]['y'] - 4.0) < 0.001, (string) $trimmed['items'][0]['y']);
check('۱-ت: فاصلهٔ نسبی حفظ شد (۴۰ − ۲۲ = ۱۸ → ۲۲ − ۴ = ۱۸)', abs(((float) $model['items'][2]['y'] - (float) $model['items'][0]['y']) - ((float) $trimmed['items'][2]['y'] - (float) $trimmed['items'][0]['y'])) < 0.001);
check('۱-ث: ارتفاع کاغذ متغیر هم ۱۸ میلی‌متر کم شد', abs((float) $trimmed['paper_h'] - 32.0) < 0.001, (string) $trimmed['paper_h']);
check('۱-ج: حد مجاز روی مدل ثبت شد', (float) ($trimmed['top_gap_mm'] ?? 0) === 4.0);

/* ───────── ۲) طراحیِ با فاصلهٔ کم دست‌نخورده می‌ماند ───────── */
$tight = [
    'items' => [
        ['type' => 'text', 'x' => 6.0, 'y' => 3.0, 'w' => 68.0, 'h' => 8.0, 'text' => 'الف'],
        ['type' => 'text', 'x' => 6.0, 'y' => 20.0, 'w' => 68.0, 'h' => 8.0, 'text' => 'ب'],
    ],
    'variable' => false,
];
[$same, $shift2] = food_ticket_tpl_trim_top($tight, 4.0);
check('۲: فاصلهٔ کم (۳mm) دست‌نخورده می‌ماند', $shift2 === 0.0 && (float) $same['items'][0]['y'] === 3.0, (string) $shift2);

/* ───────── ۳) عناصر بدون جوهر در محاسبه نادیده گرفته می‌شوند ───────── */
$withEmpty = [
    'items' => [
        ['type' => 'text', 'x' => 0.0, 'y' => 2.0, 'w' => 10.0, 'h' => 4.0, 'text' => '   '],
        ['type' => 'qr', 'x' => 0.0, 'y' => 5.0, 'w' => 10.0, 'h' => 10.0, 'matrix' => []],
        ['type' => 'text', 'x' => 0.0, 'y' => 12.0, 'w' => 10.0, 'h' => 4.0, 'text' => 'متن'],
    ],
    'variable' => false,
];
check('۳: متن خالی و QR بدون ماتریس، «جوهر» حساب نمی‌شوند', abs(food_ticket_tpl_first_ink_mm($withEmpty['items']) - 12.0) < 0.001);
[$shifted, $shift3] = food_ticket_tpl_trim_top($withEmpty, 4.0);
check('۳-ب: به‌خاطر متن واقعی در ۱۲mm، طرح ۸mm بالا می‌آید', abs($shift3 - 8.0) < 0.001, (string) $shift3);

/* ───────── ۴) تصویر: برش ردیف‌های سفید (اگر GD باشد) ───────── */
$netprintFile = dirname(__DIR__) . '/food-ticket-netprint.php';
if (function_exists('imagecreatetruecolor')) {
    if (!function_exists('food_ticket_print_trim_info')) {
        eval(grabFunction($netprintFile, 'food_ticket_print_trim_info'));
    }
    // ۱.۳۵: برش‌دهندهٔ لبه‌ها (بالا+پایین) و پوشش سازگاریِ food_ticket_np_trim_top
    if (!function_exists('food_ticket_np_trim_edges')) {
        eval(grabFunction($netprintFile, 'food_ticket_np_trim_edges'));
    }
    if (!function_exists('food_ticket_np_trim_top')) {
        eval(grabFunction($netprintFile, 'food_ticket_np_trim_top'));
    }

    $cfg = ['threshold' => 170, 'dots_per_mm' => 8.0, 'top_gap_mm' => 4.0];
    $im = imagecreatetruecolor(128, 400);
    $white = (int) imagecolorallocate($im, 255, 255, 255);
    $black = (int) imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, 127, 399, $white);
    // ۲۲ میلی‌متر = ۱۷۶ نقطه سفید، سپس محتوا
    imagefilledrectangle($im, 10, 176, 118, 200, $black);
    imagefilledrectangle($im, 10, 260, 118, 268, $black);

    $res = food_ticket_np_trim_top($im, $cfg);
    $newFirstInk = (int) $res['first_ink_dots'] - (int) $res['trimmed_dots'];
    check('۴: ۱۴۴ ردیف سفید بُرده شد (۱۷۶ − ۳۲)', (int) $res['trimmed_dots'] === 144, (string) $res['trimmed_dots']);
    check('۴-ب: اولین جوهر جدید در ۴ میلی‌متر (۳۲ نقطه)', $newFirstInk === 32, (string) $newFirstInk);
    check('۴-پ: ارتفاع تصویر به همان اندازه کم شد', imagesy($res['image']) === 400 - 144, (string) imagesy($res['image']));
    check('۴-ت: محتوا سالم است (بلوک دوم هنوز سیاه)', (imagecolorat($res['image'], 60, 120) & 0xFF) < 170);
    $info = food_ticket_print_trim_info();
    check('۴-ث: گزارش برش برای لاگ ثبت شد', (int) $info['dots'] === 144 && abs((float) $info['mm'] - 18.0) < 0.01, json_encode($info, JSON_UNESCAPED_UNICODE));

    // تصویر تنگ: نباید دست بخورد
    $tightIm = imagecreatetruecolor(64, 80);
    imagefilledrectangle($tightIm, 0, 0, 63, 79, (int) imagecolorallocate($tightIm, 255, 255, 255));
    imagefilledrectangle($tightIm, 4, 8, 40, 30, (int) imagecolorallocate($tightIm, 0, 0, 0));
    $tightRes = food_ticket_np_trim_top($tightIm, $cfg);
    check('۴-ج: تصویر با فاصلهٔ کم دست‌نخورده می‌ماند', (int) $tightRes['trimmed_dots'] === 0 && imagesy($tightRes['image']) === 80);

    // تصویر کاملاً سفید: نه کرش، نه برش
    $blank = imagecreatetruecolor(64, 64);
    imagefilledrectangle($blank, 0, 0, 63, 63, (int) imagecolorallocate($blank, 255, 255, 255));
    $blankRes = food_ticket_np_trim_top($blank, $cfg);
    check('۴-چ: تصویر کاملاً سفید بی‌خطر است', (int) $blankRes['trimmed_dots'] === 0);
} else {
    echo "[SKIP] بخش تصویر: GD در این PHP فعال نیست.\n";
}

echo "\n";
echo $fail === 0
    ? 'همهٔ بررسی‌های فاصلهٔ بالای فیش موفق بودند (' . $pass . " مورد).\n"
    : $fail . ' مورد ناموفق از ' . ($pass + $fail) . " مورد.\n";
exit($fail === 0 ? 0 : 1);
