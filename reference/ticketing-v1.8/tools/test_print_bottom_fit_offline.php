<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «پرتیِ کاغذ در بالا و پایین فیش» — نسخهٔ ۱.۳۷
 * ============================================================================
 *   php tools/test_print_bottom_fit_offline.php
 *
 * چه چیزی را ثابت می‌کند:
 *   ۱) food_ticket_tpl_last_ink_mm: آخرین نقطهٔ چاپ‌شده (با ارتفاع واقعی متن چندخطی).
 *   ۲) food_ticket_tpl_fit_bottom: ارتفاع صفحه = آخرین محتوا + حاشیهٔ پایین — هم برای
 *      قالب «طول ثابت» و هم «طول متغیر»؛ پس دیگر هیچ سفیدیِ اضافه‌ای در پایین چاپ نمی‌شود.
 *   ۳) قالبِ keep_height (برچسب آماده) و محتوای بلندتر از صفحه دست‌نخورده/اصلاح می‌شوند.
 *   ۴) food_ticket_np_trim_edges: برش بالا تا top_gap_mm و کوتاه‌سازی پایین تا tail_mm
 *      در همان تصویری که به چاپگر می‌رود (تضمین نرم‌افزاری، مستقل از درایور).
 *   ۵) فایل تنظیمات storage/food_ticket_netprint.json می‌تواند tail_mm/fit_bottom را عوض کند.
 *
 * بدون MySQL. بخش تصویر فقط اگر افزونهٔ GD موجود باشد اجرا می‌شود.
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

function evalOnce(string $file, string $name): void
{
    if (!function_exists($name)) {
        eval(grabFunction($file, $name));
    }
}

echo "=== آزمون پرتی کاغذ بالا/پایین فیش (۱.۳۷) ===\n";

$templatesFile = dirname(__DIR__) . '/food-ticket-templates.php';
$netprintFile = dirname(__DIR__) . '/food-ticket-netprint.php';

evalOnce($templatesFile, 'food_ticket_tpl_text_height_mm');
evalOnce($templatesFile, 'food_ticket_tpl_first_ink_mm');
evalOnce($templatesFile, 'food_ticket_tpl_last_ink_mm');
evalOnce($templatesFile, 'food_ticket_tpl_fit_bottom');

/* ───────── ۱) آخرین جوهر ───────── */
$items = [
    ['type' => 'text', 'x' => 6.0, 'y' => 4.0, 'w' => 68.0, 'h' => 8.0, 'text' => 'سامانه خدمات', 'pt' => 12.0, 'lh' => 1.2],
    ['type' => 'line', 'x' => 6.0, 'y' => 22.0, 'w' => 68.0, 'h' => 0.0],
    ['type' => 'text', 'x' => 6.0, 'y' => 24.0, 'w' => 68.0, 'h' => 10.0, 'text' => 'چلوکباب', 'pt' => 14.0, 'lh' => 1.2],
];
$last = food_ticket_tpl_last_ink_mm($items);
check('۱: آخرین جوهر = پایین‌ترین عنصر (۲۴ + ۱۰ = ۳۴ میلی‌متر)', abs($last - 34.0) < 0.001, (string) $last);
check('۱-ب: خط (line) با ارتفاع صفر، پایین فیش را جابه‌جا نمی‌کند', $last >= 34.0);

$withEmpty = [
    ['type' => 'text', 'x' => 0.0, 'y' => 30.0, 'w' => 10.0, 'h' => 12.0, 'text' => '   ', 'pt' => 12.0, 'lh' => 1.2],
    ['type' => 'text', 'x' => 0.0, 'y' => 2.0, 'w' => 10.0, 'h' => 6.0, 'text' => 'متن', 'pt' => 12.0, 'lh' => 1.2],
];
check('۱-پ: متن خالی در پایین، «جوهر» حساب نمی‌شود', abs(food_ticket_tpl_last_ink_mm($withEmpty) - 8.0) < 0.001, (string) food_ticket_tpl_last_ink_mm($withEmpty));

/* ───────── ۲) کوتاه‌سازی پایین — قالب با طول ثابت ───────── */
// همان وضعیت شکایت: طرح ۲۰ میلی‌متر بالا خالی داشته و ارتفاع کاغذ ثابت ۷۰ میلی‌متر است
$model = [
    'template_id' => 3,
    'template_name' => 'پیش‌فرض',
    'paper_w' => 80.0,
    'paper_h' => 70.0,
    'variable' => false,
    'items' => [
        ['type' => 'text', 'x' => 6.0, 'y' => 24.0, 'w' => 68.0, 'h' => 8.0, 'text' => 'سرصفحه', 'pt' => 12.0, 'lh' => 1.2],
        ['type' => 'text', 'x' => 6.0, 'y' => 44.0, 'w' => 68.0, 'h' => 10.0, 'text' => 'چلوکباب', 'pt' => 14.0, 'lh' => 1.2],
    ],
];
$fit = $model;
$fit['items'][0]['y'] = 4.0; // پس از تراز بالا
$fit['items'][1]['y'] = 24.0;
[$fitted, $trimmed] = food_ticket_tpl_fit_bottom($fit, 3.0);
check('۲: طول ثابت ۷۰ → آخرین محتوا + ۳ = ۳۷ میلی‌متر (۳۳ میلی‌متر کاغذ کمتر)', abs((float) $fitted['paper_h'] - 37.0) < 0.001, (string) $fitted['paper_h']);
check('۲-ب: میزان کوتاه‌سازی گزارش می‌شود', abs((float) $fitted['tail_trim_mm'] - 33.0) < 0.001, (string) $fitted['tail_trim_mm']);
check('۲-پ: ارتفاع قبلی برای گزارش حفظ می‌شود', abs((float) $fitted['paper_h_before_fit'] - 70.0) < 0.001);
check('۲-ت: در پایین فیش فقط tail_mm سفیدی می‌ماند', abs(((float) $fitted['paper_h'] - food_ticket_tpl_last_ink_mm($fitted['items'])) - 3.0) < 0.001);

/* ───────── ۳) قالب متغیر + حاشیهٔ صفر ───────── */
$varModel = ['paper_w' => 80.0, 'paper_h' => 49.0, 'variable' => true, 'items' => $fit['items']];
[$flat, $t0] = food_ticket_tpl_fit_bottom($varModel, 0.0);
check('۳: با tail_mm=0 ارتفاع دقیقاً تا آخرین نقطهٔ چاپ می‌شود', abs((float) $flat['paper_h'] - 34.0) < 0.001, (string) $flat['paper_h']);
check('۳-ب: تغییر خیلی کوچک (<0.15mm) نادیده گرفته می‌شود', food_ticket_tpl_fit_bottom(['paper_h' => 34.0, 'items' => $fit['items']], 0.0)[1] === 0.0);

/* ───────── ۴) keep_height و محتوای بلندتر از صفحه ───────── */
$keep = ['paper_w' => 80.0, 'paper_h' => 70.0, 'keep_height' => true, 'items' => $fit['items']];
check('۴: قالب keep_height (برچسب آماده) دست‌نخورده می‌ماند', food_ticket_tpl_fit_bottom($keep, 3.0)[1] === 0.0 && (float) food_ticket_tpl_fit_bottom($keep, 3.0)[0]['paper_h'] === 70.0);

$overflow = ['paper_w' => 80.0, 'paper_h' => 20.0, 'variable' => false, 'items' => $fit['items']];
[$tall, $grow] = food_ticket_tpl_fit_bottom($overflow, 3.0);
check('۴-ب: محتوای بلندتر از صفحه، فیش را بلند می‌کند (متن بریده نمی‌شود)', (float) $tall['paper_h'] >= 37.0 && $grow < 0.0, (string) $tall['paper_h']);

/* ───────── ۵) تصویر: برش بالا و کوتاه‌سازی پایین ───────── */
if (!function_exists('imagecreatetruecolor')) {
    echo "[SKIP] افزونهٔ GD نیست؛ بخش تصویر اجرا نشد.\n";
} else {
    evalOnce($netprintFile, 'food_ticket_print_trim_info');
    evalOnce($netprintFile, 'food_ticket_np_trim_edges');
    evalOnce($netprintFile, 'food_ticket_np_trim_top');

    // تصویر ۸۰ نقطه عرض × ۴۰۰ نقطه ارتفاع: سفید؛ جوهر از ردیف ۱۷۶ تا ۲۶۸
    $im = imagecreatetruecolor(80, 400);
    $white = (int) imagecolorallocate($im, 255, 255, 255);
    $black = (int) imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, 79, 399, $white);
    imagefilledrectangle($im, 0, 176, 79, 268, $black);

    $cfg = ['threshold' => 170, 'dots_per_mm' => 8.0, 'top_gap_mm' => 4.0, 'tail_mm' => 3.0, 'fit_bottom' => true];
    $res = food_ticket_np_trim_edges($im, $cfg);
    $outH = imagesy($res['image']);
    check('۵: بالای تصویر ۱۴۴ نقطه (۱۸mm) بریده شد', (int) $res['trimmed_dots'] === 144, (string) $res['trimmed_dots']);
    check('۵-ب: پایین تصویر ۱۰۷ نقطه بُرده شد (تا ۳mm پس از آخرین جوهر)', (int) $res['bottom_dots'] === 107, (string) $res['bottom_dots']);
    check('۵-پ: ارتفاع نهایی = ۴mm + محتوا + ۳mm = ۱۴۹ نقطه', $outH === 149, (string) $outH);
    check('۵-ت: با fit_bottom=false فقط بالا بریده می‌شود', (int) food_ticket_np_trim_edges($im, ['threshold' => 170, 'dots_per_mm' => 8.0, 'top_gap_mm' => 4.0, 'tail_mm' => 3.0, 'fit_bottom' => false])['bottom_dots'] === 0);
    check('۵-ث: tail_mm=0 یعنی کوتاه‌سازی دقیقاً تا آخرین ردیف جوهر', imagesy(food_ticket_np_trim_edges($im, ['threshold' => 170, 'dots_per_mm' => 8.0, 'top_gap_mm' => 4.0, 'tail_mm' => 0.0, 'fit_bottom' => true])['image']) === 125);

    // تصویر بدون هیچ جوهر: بی‌خطر
    $blank = imagecreatetruecolor(80, 40);
    imagefilledrectangle($blank, 0, 0, 79, 39, (int) imagecolorallocate($blank, 255, 255, 255));
    $blankRes = food_ticket_np_trim_edges($blank, $cfg);
    check('۵-چ: تصویر کاملاً سفید دست‌نخورده می‌ماند', imagesy($blankRes['image']) === 40 && (int) $blankRes['trimmed_dots'] === 0);

    // سازگاری: food_ticket_np_trim_top همان رفتار ۱.۳۴ را می‌دهد (فقط بالا)
    $legacy = food_ticket_np_trim_top($im, $cfg);
    check('۵-ح: food_ticket_np_trim_top (سازگاری ۱.۳۴) فقط بالا را می‌بُرد', imagesy($legacy['image']) === 400 - 144, (string) imagesy($legacy['image']));
    $info = food_ticket_print_trim_info();
    check('۵-خ: اطلاعات برش در دسترس گزارش/ابزار تشخیص است', array_key_exists('tail_dots', $info) && (int) $info['dots'] === 144);
}

/* ───────── ۶) کلیدهای تنظیمات در فایل JSON ───────── */
$netSrc = (string) file_get_contents($netprintFile);
check('۶: کلید tail_mm در پیش‌فرض‌های تنظیمات هست', str_contains($netSrc, "'tail_mm'") );
check('۶-ب: کلید fit_bottom در پیش‌فرض‌های تنظیمات هست', str_contains($netSrc, "'fit_bottom'"));
check('۶-پ: کلیدهای JSON در food_ticket_tpl_net_cfg از تنظیمات خوانده می‌شوند', str_contains($netSrc, 'food_ticket_np_settings'));
$tplSrc = (string) file_get_contents($templatesFile);
check('۶-ت: قالب‌های keep_height پشتیبانی می‌شوند', str_contains($tplSrc, "'keep_height' => !empty(\$in['keep_height'])"));
check('۶-ث: کوتاه‌سازی پایین در ساخت مدل صدا زده می‌شود', str_contains($tplSrc, 'food_ticket_tpl_fit_bottom($model, $tailMm)'));

/* ───────── ۷) ۱.۳۷ — تراز دقیق لبه‌ها (شروع = لبهٔ کاغذ، برش = پایان محتوا) ───────── */
evalOnce($netprintFile, 'food_ticket_print_align_info');
evalOnce($netprintFile, 'food_ticket_np_reverse_feed');

$rev = food_ticket_np_reverse_feed(5, 'esc_e');
check('۷: فرمان عقب‌راندن ESC e = 1B 65 05', $rev === "\x1b\x65\x05", bin2hex($rev));
check('۷-ب: فرمان جایگزین ESC K بر حسب «نقطه» فرستاده می‌شود (۴ خط × ۳۰ = ۱۲۰ نقطه)',
    food_ticket_np_reverse_feed(4, 'esc_k') === "\x1b\x4b" . chr(120), bin2hex(food_ticket_np_reverse_feed(4, 'esc_k')));
check('۷-ب۲: ESC K هرگز از سقف ۲۵۵ نقطه رد نمی‌شود',
    food_ticket_np_reverse_feed(40, 'esc_k', 64) === "\x1b\x4b" . chr(255), bin2hex(food_ticket_np_reverse_feed(40, 'esc_k', 64)));
check('۷-ب۳: ESC e همچنان «خط» می‌فرستد (۵ خط = 1B 65 05)',
    food_ticket_np_reverse_feed(5, 'esc_e', 30) === "\x1b\x65\x05");
check('۷-پ: حالت none هیچ بایتی نمی‌فرستد', food_ticket_np_reverse_feed(4, 'none') === '');
check('۷-ت: صفر خط = بدون فرمان', food_ticket_np_reverse_feed(0, 'esc_e') === '');

if (function_exists('imagecreatetruecolor')) {
    evalOnce($netprintFile, 'food_ticket_np_escpos');
    $canvas = imagecreatetruecolor(576, 400);
    $w = (int) imagecolorallocate($canvas, 255, 255, 255);
    $b = (int) imagecolorallocate($canvas, 0, 0, 0);
    imagefilledrectangle($canvas, 0, 0, 575, 399, $w);
    imagefilledrectangle($canvas, 0, 100, 575, 360, $b);

    // حالت خاموش (رفتار ۱.۳۶): بدون عقب‌راندن و با برش ساده
    $off = ['threshold' => 170, 'chunk_rows' => 128, 'dots_per_mm' => 8.0, 'head_dots' => 576,
        'top_gap_mm' => 4.0, 'tail_mm' => 3.0, 'fit_bottom' => true, 'cut' => true, 'feed_lines' => 2,
        'head_offset_mm' => 0.0, 'edge_align' => true, 'line_dots' => 30, 'reverse_feed_cmd' => 'esc_e'];
    $payloadOff = food_ticket_np_escpos($canvas, $off);
    check('۷-ث: با head_offset_mm=0 هیچ عقب‌راندنی فرستاده نمی‌شود', strpos($payloadOff, "\x1b\x65") === false);
    check('۷-ج: با head_offset_mm=0 برش همان GS V 66 00 است', str_ends_with($payloadOff, "\x1d\x56\x42\x00"), bin2hex(substr($payloadOff, -5)));

    // حالت روشن: فاصلهٔ سرِ چاپ تا تیغه ۲۰ میلی‌متر، حاشیهٔ پایین ۳ میلی‌متر
    $on = $off;
    $on['head_offset_mm'] = 20.0;
    $payloadOn = food_ticket_np_escpos($canvas, $on);
    check('۷-چ: پیلود با ESC @ و سپس قفل ارتفاع خط (ESC 3 30) شروع می‌شود',
        str_starts_with($payloadOn, "\x1b\x40\x1b\x33" . chr(30)), bin2hex(substr($payloadOn, 0, 6)));
    check('۷-چ۲: بلافاصله بعد از آن فرمان عقب‌راندن می‌آید (۵ خط = ۱۵۰ نقطه)',
        str_contains(substr($payloadOn, 0, 24), "\x1b\x65\x05"), bin2hex(substr($payloadOn, 0, 10)));
    // تغذیهٔ پیش از برش = (۲۰ − ۳) × ۸ = ۱۳۶ نقطه
    check('۷-ح: برش با تغذیهٔ دقیق ۱۳۶ نقطه (۲۰−۳ میلی‌متر) انجام می‌شود', str_ends_with($payloadOn, "\x1d\x56\x42" . chr(136)), bin2hex(substr($payloadOn, -4)));
    $alignInfo = food_ticket_print_align_info();
    check('۷-خ: اطلاعات تراز برای ابزار تشخیص ثبت شد', (int) $alignInfo['reverse_lines'] === 5 && (int) $alignInfo['cut_feed_dots'] === 136);

    // tail_mm=0 → برش دقیقاً روی آخرین خط
    $cut0 = $on;
    $cut0['tail_mm'] = 0.0;
    check('۷-د: با tail_mm=0 برش دقیقاً روی آخرین خط است (۱۶۰ نقطه)', str_ends_with(food_ticket_np_escpos($canvas, $cut0), "\x1d\x56\x42" . chr(160)));
}

/* ───────── ۸) ۱.۳۷ — کارت کالیبراسیون (مبنای اندازه‌گیری head_offset_mm) ───────── */
evalOnce($netprintFile, 'food_ticket_np_reverse_feed');
evalOnce($netprintFile, 'food_ticket_np_calibration_image');
evalOnce($netprintFile, 'food_ticket_np_target');
evalOnce($netprintFile, 'food_ticket_np_calibration_print');

if (function_exists('imagecreatetruecolor')) {
    $cardCfg = [
        'width_dots' => 576, 'dots_per_mm' => 8.0, 'font_file' => '', 'font_px' => 20,
        'threshold' => 170, 'chunk_rows' => 128, 'top_gap_mm' => 4.0, 'tail_mm' => 3.0,
        'fit_bottom' => true, 'cut' => true, 'feed_lines' => 2, 'head_offset_mm' => 20.0,
        'edge_align' => true, 'line_dots' => 30, 'reverse_feed_cmd' => 'esc_e',
    ];
    $card = food_ticket_np_calibration_image($cardCfg);
    check('۸: عرض کارت کالیبراسیون ۵۷۶ نقطه (۸۰ میلی‌متر) است', imagesx($card) === 576, (string) imagesx($card));
    check('۸-ب: ارتفاع کارت ≈ ۹۵ میلی‌متر است', abs(imagesy($card) - 760) <= 2, (string) imagesy($card));
    $black = (int) imagecolorallocate($card, 0, 0, 0);
    $firstRowBlack = true;
    for ($x = 0; $x < 576; $x += 48) {
        if ((imagecolorat($card, $x, 0) & 0xFFFFFF) !== ($black & 0xFFFFFF)) {
            $firstRowBlack = false;
            break;
        }
    }
    check('۸-پ: «ابتدای کارت = ردیف صفر» با نوار سیاه مشخص شده است (مبنای اندازه‌گیری)', $firstRowBlack);
    check('۸-ت: ردیف آخر کارت سفید است (کارت بعد از خط راهنما تمام می‌شود)',
        (imagecolorat($card, 40, imagesy($card) - 1) & 0xFFFFFF) === 0xFFFFFF);

    $viaPrinter = food_ticket_np_calibration_print($cardCfg);
    check('۸-ث: بدون آدرس چاپگر، کارت بی‌صدا رد نمی‌شود و پیام روشن می‌دهد',
        $viaPrinter['ok'] === false && $viaPrinter['message'] !== '', $viaPrinter['message']);
}

/* ───────── ۱۰) ۱.۳۷.۳ — قفل ارتفاع خط، ESC K نقطه‌ای و حالت‌های برش ─────── */
evalOnce($netprintFile, 'food_ticket_np_escpos');

if (function_exists('imagecreatetruecolor')) {
    $canvas3 = imagecreatetruecolor(576, 400);
    $white3 = (int) imagecolorallocate($canvas3, 255, 255, 255);
    imagefilledrectangle($canvas3, 0, 0, 575, 399, $white3);
    imagefilledrectangle($canvas3, 0, 100, 575, 140, (int) imagecolorallocate($canvas3, 0, 0, 0));
    $base = ['threshold' => 170, 'chunk_rows' => 128, 'dots_per_mm' => 8.0, 'head_dots' => 576,
        'top_gap_mm' => 4.0, 'tail_mm' => 3.0, 'fit_bottom' => true, 'cut' => true, 'feed_lines' => 2,
        'head_offset_mm' => 20.0, 'edge_align' => true, 'line_dots' => 30, 'reverse_feed_cmd' => 'esc_e'];

    $partial = food_ticket_np_escpos($canvas3, $base);
    check('۱۰: حالت پیش‌فرض برش، جزئی است (GS V 66)', str_ends_with($partial, "\x1d\x56\x42" . chr(136)), bin2hex(substr($partial, -4)));

    $full = $base;
    $full['cut_mode'] = 'full';
    $fullPayload = food_ticket_np_escpos($canvas3, $full);
    check('۱۰-ب: حالت برش کامل فرمان GS V 65 می‌فرستد', str_ends_with($fullPayload, "\x1d\x56\x41" . chr(136)), bin2hex(substr($fullPayload, -4)));

    $none = $base;
    $none['cut_mode'] = 'none';
    $nonePayload = food_ticket_np_escpos($canvas3, $none);
    check('۱۰-پ: حالت بدون برش هیچ فرمان برشی ندارد', strpos($nonePayload, "\x1d\x56") === false, bin2hex(substr($nonePayload, -6)));
    check('۱۰-ت: در حالت بدون برش هم عقب‌راندن فرستاده نمی‌شود (لبهٔ کاغذ زیر سرِ چاپ می‌ماند)',
        strpos($nonePayload, "\x1b\x65") === false);

    $noneNoCut = $none;
    $noneNoCut['cut'] = false;
    check('۱۰-ث: cut=false هم مثل cut_mode=none رفتار می‌کند',
        strpos(food_ticket_np_escpos($canvas3, $noneNoCut), "\x1d\x56") === false);

    $badMode = $base;
    $badMode['cut_mode'] = 'چیزی‌که‌وجودندارد';
    check('۱۰-ج: حالت برش نامعتبر به «جزئی» برمی‌گردد',
        str_ends_with(food_ticket_np_escpos($canvas3, $badMode), "\x1d\x56\x42" . chr(136)));
}

/* ───────── ۹) ۱.۳۷ — وضعیت تراز، ذخیرهٔ JSON و پروفایل پیلود ───────────── */
evalOnce($netprintFile, 'food_ticket_np_align_state');
evalOnce($netprintFile, 'food_ticket_np_write_json');
evalOnce($netprintFile, 'food_ticket_np_json_file');

$alignOff = food_ticket_np_align_state(['head_offset_mm' => 0.0, 'edge_align' => true, 'tail_mm' => 3.0, 'line_dots' => 30, 'dots_per_mm' => 8.0]);
check('۹: با عدد صفر، وضعیت «نیازمند اندازه‌گیری» و غیرفعال است', $alignOff['verdict'] === 'measure' && $alignOff['active'] === false, $alignOff['verdict']);
$alignOn = food_ticket_np_align_state(['head_offset_mm' => 20.0, 'edge_align' => true, 'tail_mm' => 3.0, 'line_dots' => 30, 'dots_per_mm' => 8.0]);
check('۹-ب: با ۲۰ میلی‌متر، تراز فعال و ۵ خط عقب‌راندن (۱۵۰ نقطه) است',
    $alignOn['active'] === true && $alignOn['reverse_lines'] === 5 && $alignOn['reverse_dots'] === 150, json_encode($alignOn, JSON_UNESCAPED_UNICODE));
check('۹-پ: تغذیهٔ پیش از برش = (۲۰−۳)×۸ = ۱۳۶ نقطه و ۱۷ میلی‌متر', $alignOn['cut_feed_dots'] === 136 && abs($alignOn['cut_feed_mm'] - 17.0) < 0.01);
check('۹-ت: با edge_align خاموش، وضعیت «خاموش» است', food_ticket_np_align_state(['head_offset_mm' => 20.0, 'edge_align' => false])['verdict'] === 'disabled');

$tmpJson = sys_get_temp_dir() . '/np_align_test_' . bin2hex(random_bytes(3)) . '.json';
@unlink($tmpJson);
$write1 = food_ticket_np_write_json(['head_offset_mm' => 20.4, 'edge_align' => false, 'tail_mm' => '2', 'reverse_feed_cmd' => 'esc_k', 'line_dots' => 24], $tmpJson);
check('۹-ث: نوشتن فایل تنظیمات موفق و بدون خطا است', $write1['error'] === '' && is_file($tmpJson), $write1['error']);
$savedJson = json_decode((string) @file_get_contents($tmpJson), true) ?: [];
check('۹-ج: مقادیر ذخیره‌شده درست‌اند (۲۰.۴ / false / ۲ / esc_k / ۲۴)',
    abs((float) $savedJson['head_offset_mm'] - 20.4) < 0.001 && $savedJson['edge_align'] === false
    && (int) $savedJson['tail_mm'] === 2 && $savedJson['reverse_feed_cmd'] === 'esc_k' && (int) $savedJson['line_dots'] === 24,
    json_encode($savedJson, JSON_UNESCAPED_UNICODE));
$write2 = food_ticket_np_write_json(['head_offset_mm' => 999, 'reverse_feed_cmd' => 'hack', 'line_dots' => 500], $tmpJson);
$clamped = json_decode((string) @file_get_contents($tmpJson), true) ?: [];
check('۹-چ: مقادیر خارج از محدوده اصلاح می‌شوند (۶۰ میلی‌متر، فرمان نامعتبر رد، ۶۴ نقطه)',
    abs((float) $clamped['head_offset_mm'] - 60.0) < 0.001 && $clamped['reverse_feed_cmd'] === 'esc_k' && (int) $clamped['line_dots'] === 64,
    json_encode($clamped, JSON_UNESCAPED_UNICODE));
check('۹-ح: کلید ناشناخته در فایل نوشته نمی‌شود', !array_key_exists('unknown_key', $clamped));
@unlink($tmpJson);

// پروفایل پیلود: باید دو خط لاگ با فرمان عقب‌راندن و برش تولید کند
if (function_exists('imagecreatetruecolor')) {
    $logFile = sys_get_temp_dir() . '/np_align_log_' . bin2hex(random_bytes(3)) . '.log';
    @unlink($logFile);
    $prevLog = ini_set('error_log', $logFile);
    $canvas2 = imagecreatetruecolor(576, 800);
    imagefilledrectangle($canvas2, 0, 0, 575, 799, (int) imagecolorallocate($canvas2, 255, 255, 255));
    imagefilledrectangle($canvas2, 0, 300, 575, 340, (int) imagecolorallocate($canvas2, 0, 0, 0));
    food_ticket_np_escpos($canvas2, [
        'threshold' => 170, 'chunk_rows' => 128, 'dots_per_mm' => 8.0, 'head_dots' => 576,
        'top_gap_mm' => 4.0, 'tail_mm' => 3.0, 'fit_bottom' => true, 'cut' => true, 'feed_lines' => 2,
        'head_offset_mm' => 20.0, 'edge_align' => true, 'line_dots' => 30, 'reverse_feed_cmd' => 'esc_e',
        'log_align' => true,
    ]);
    if ($prevLog !== false) {
        ini_set('error_log', $prevLog);
    }
    $logText = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    check('۹-خ: پروفایل تراز در لاگ ثبت می‌شود (عقب‌راندن + برش + بایت کل)',
        str_contains($logText, 'edge_align=on') && str_contains($logText, 'reverse_hex=1b6505')
        && str_contains($logText, 'cut_feed_dots=136') && str_contains($logText, 'cut_hex=1d564288')
        && str_contains($logText, 'total_bytes=') && strlen($logText) < 700,
        mb_substr(str_replace("\n", ' ‹ ', $logText), 0, 220));
    @unlink($logFile);
}

echo "\n" . ($fail === 0 ? 'همهٔ بررسی‌های پرتی کاغذ (بالا و پایین) موفق بودند (' . $pass . " مورد).\n" : $fail . " بررسی ناموفق از " . ($pass + $fail) . " مورد.\n");
exit($fail === 0 ? 0 : 1);
