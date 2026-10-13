<?php
declare(strict_types=1);

/**
 * ابزار تشخیص «فاصلهٔ سفید بالای فیش» — نسخهٔ ۱.۳۴
 *
 * اجرا (روی سرور، با همان حسابی که سرویس وب/Worker با آن اجرا می‌شود):
 *   C:\xampp\php\php.exe tools\diag_print_margin.php
 *   C:\xampp\php\php.exe tools\diag_print_margin.php --template=3   بررسی یک قالب خاص
 *   C:\xampp\php\php.exe tools\diag_print_margin.php --render       رسم تصویر و شمارش دقیق ردیف‌های سفید
 *
 * چه چیزی نشان می‌دهد:
 *   ۱) مسیر فعال چاپ (ویندوز/شبکه) و تنظیمات واقعی چاپگر از storage/food_ticket_netprint.json
 *   ۲) اجزای قالب فعال با y (میلی‌متر) و اینکه اولین «جوهر» کجاست
 *   ۳) فاصله‌ای که سامانه به‌صورت خودکار حذف می‌کند (حداکثر ۴ میلی‌متر بالای فیش باقی می‌ماند)
 *   ۴) اگر --render بدهید: تصویر واقعی رسم می‌شود و شمارش ردیف‌های سفید بالا گزارش می‌گردد
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

$say('=== تشخیص فاصلهٔ بالای فیش (۱.۳۴) ===');
$say('PHP ' . PHP_VERSION . ' | OS ' . PHP_OS_FAMILY . ' ' . php_uname('r'));

$gd = function_exists('imagettftext');
$say(($gd ? '[ OK ]' : '[FAIL]') . ' GD با FreeType (imagettftext)');

try {
    require dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/food-ticket-netprint.php';
    require_once dirname(__DIR__) . '/food-ticket-templates.php';
} catch (Throwable $e) {
    $say('[FAIL] بارگذاری برنامه: ' . $e->getMessage());
    exit(1);
}

// ── ۱) تنظیمات چاپ ────────────────────────────────────────────────────────────
$root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
$jsonPath = $root . '/storage/food_ticket_netprint.json';
$say('');
$say('--- تنظیمات چاپ (' . $jsonPath . ') ---');
if (is_file($jsonPath)) {
    $raw = (string) @file_get_contents($jsonPath);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        foreach ($json as $k => $v) {
            $say(sprintf('  %-18s = %s', (string) $k, is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
        }
    } else {
        $say('  فایل JSON خوانده نشد (ساختار خراب است).');
    }
} else {
    $say('  فایل وجود ندارد → مقادیر پیش‌فرض به کار می‌رود (top_gap_mm=4 ، dots_per_mm=8).');
}

$netCfg = food_ticket_tpl_net_cfg();
$say('');
$say('--- مقادیر مؤثر ---');
foreach (['dots_per_mm', 'head_dots', 'top_gap_mm', 'tail_mm', 'fit_bottom', 'win_top_shift_mm', 'feed_lines', 'threshold', 'width_dots'] as $k) {
    if (array_key_exists($k, $netCfg)) {
        $say(sprintf('  %-18s = %s', $k, (string) $netCfg[$k]));
    }
}

// ── ۲) مسیر فعال چاپ ─────────────────────────────────────────────────────────
$say('');
$say('--- مسیر فعال چاپ ---');
$printer = '';
try {
    $printer = (string) setting('food_ticket_printer', '');
} catch (Throwable) {
}
$mode = '';
try {
    $mode = (string) setting('food_ticket_printer_mode', '');
} catch (Throwable) {
}
$say('  printer_mode      = ' . ($mode !== '' ? $mode : '(خالی)'));
$say('  printer name      = ' . ($printer !== '' ? $printer : '(خالی)'));
$netHost = '';
$netPort = 9100;
try {
    $netHost = (string) setting('food_ticket_netprint_host', '');
    $netPort = (int) (setting('food_ticket_netprint_port', 9100) ?: 9100);
} catch (Throwable) {
}
if ($netHost !== '') {
    $say('  netprint host     = ' . $netHost . ':' . $netPort);
}

// ── ۲.۱) ترازِ دقیق لبه‌ها (۱.۳۷): شروع چاپ = لبهٔ کاغذ ، برش = پایان آخرین خط ──
$alignCfg = [];
if (function_exists('food_ticket_np_settings')) {
    $alignCfg = food_ticket_np_settings();
}
$headOffset = (float) ($alignCfg['head_offset_mm'] ?? 0.0);
$edgeOn = !empty($alignCfg['edge_align']) && $headOffset > 0;
$say('');
$say('--- تراز لبه‌های کاغذ (۱.۳۷) ---');
$say('  head_offset_mm    = ' . $headOffset . '  (فاصلهٔ سرِ چاپ تا تیغهٔ برش؛ ۰ = اندازه‌گیری‌نشده)');
$say('  edge_align        = ' . (!empty($alignCfg['edge_align']) ? 'true' : 'false') . '  (تراز دقیق دو سر)');
$say('  tail_mm           = ' . (string) ($alignCfg['tail_mm'] ?? '') . '  (سفیدی عمدی بعد از آخرین خطِ چاپ)');
$say('  line_dots / feed  = ' . (string) ($alignCfg['line_dots'] ?? 30) . ' نقطه در هر خط ، فرمان عقب‌راندن: ' . (string) ($alignCfg['reverse_feed_cmd'] ?? 'esc_e'));
if ($edgeOn) {
    $dpm = (float) ($alignCfg['dots_per_mm'] ?? 8.0);
    $lines = (int) round($headOffset * $dpm / max(8, (int) ($alignCfg['line_dots'] ?? 30)));
    $cutDots = (int) round(max(0.0, $headOffset - (float) ($alignCfg['tail_mm'] ?? 3.0)) * $dpm);
    $say('  وضعیت             = فعال — پیش از چاپ ' . $lines . ' خط عقب‌راندن (≈ ' . round($lines * max(8, (int) ($alignCfg['line_dots'] ?? 30)) / $dpm, 1) . ' میلی‌متر) و برش پس از ' . $cutDots . ' نقطه تغذیه');
} else {
    $say('  وضعیت             = غیرفعال — برای حذف کاملِ پرتِ بالای کاغذ:');
    $say('                      ۱) کارت کالیبراسیون را بچاپید: php tools\print_calibration.php');
    $say('                      ۲) فاصلهٔ لبهٔ کاغذ تا نوار سیاه کارت را با خط‌کش اندازه بگیرید');
    $say('                      ۳) در storage/food_ticket_netprint.json بنویسید: {"head_offset_mm": <همان عدد>, "edge_align": true}');
    $say('                      (این کار فقط در چاپ شبکه‌ای TCP/RAW اثر دارد؛ در صف ویندوز باید Back-feed درایور فعال شود.)');
}

// ── ۳) قالب فعال و فاصلهٔ بالای محتوا ────────────────────────────────────────
$say('');
$say('--- قالب فعال ---');
$templateId = (int) ($options['template'] ?? 0);
try {
    $row = $templateId > 0 ? food_ticket_tpl_get($templateId) : food_ticket_tpl_active();
} catch (Throwable $e) {
    $say('[FAIL] قالب خوانده نشد: ' . $e->getMessage());
    $row = null;
}
if (!is_array($row)) {
    $say('[FAIL] قالب فعالی وجود ندارد؛ قالب پیش‌فرض استفاده می‌شود.');
    $row = food_ticket_tpl_default_row();
}
$say(sprintf('  #%d "%s"  کاغذ: %.1f x %.1f میلی‌متر', (int) ($row['id'] ?? 0), (string) ($row['name'] ?? ''), (float) ($row['paper_width'] ?? 80), (float) ($row['paper_height'] ?? 0)));
$tpl = (array) ($row['template'] ?? []);
$say('  عناصر فعال و y (میلی‌متر):');
$firstDeclared = null;
foreach ((array) ($tpl['elements'] ?? []) as $el) {
    if (empty($el['enabled'])) {
        continue;
    }
    $y = (float) ($el['y'] ?? 0);
    $firstDeclared = $firstDeclared === null ? $y : min($firstDeclared, $y);
    $say(sprintf('    %-16s y=%6.1f  h=%5.1f  %s', (string) ($el['field'] ?? '?'), $y, (float) ($el['height'] ?? 0), (string) ($el['text'] ?? '')));
}
if ($firstDeclared !== null) {
    $say(sprintf('  کمترین y اعلام‌شده در طرح: %.1f میلی‌متر', $firstDeclared));
}
// مدل نهایی (پس از حذف عناصر بدون مقدار و تراز خودکار)
try {
    $model = food_ticket_tpl_model(food_ticket_tpl_sample_event(), $row);
    $say(sprintf('  مدل نهایی: %d عنصر | ارتفاع کاغذ %.1f میلی‌متر | top_trim=%.1f میلی‌متر', count((array) $model['items']), (float) $model['paper_h'], (float) ($model['top_trim_mm'] ?? 0)));
    $say('  عناصر مدل (پس از تراز):');
    foreach ((array) $model['items'] as $it) {
        $say(sprintf('    %-8s y=%6.1f  h=%5.1f  %s', (string) $it['type'], (float) $it['y'], (float) ($it['h'] ?? 0), (string) ($it['text'] ?? '')));
    }
    $ink = food_ticket_tpl_first_ink_mm((array) $model['items']);
    $inkEnd = function_exists('food_ticket_tpl_last_ink_mm') ? food_ticket_tpl_last_ink_mm((array) $model['items']) : 0.0;
    $say(sprintf('  اولین جوهر در مدل: %.1f میلی‌متر  (حد مجاز: %.1f میلی‌متر)', $ink, (float) ($model['top_gap_mm'] ?? 4.0)));
    $say(sprintf('  آخرین جوهر در مدل: %.1f میلی‌متر', $inkEnd));
    $say(sprintf('  ارتفاع کاغذ نهایی : %.1f میلی‌متر  (حاشیهٔ پایین: %.1f میلی‌متر)', (float) $model['paper_h'], (float) $model['paper_h'] - $inkEnd));
    if (array_key_exists('paper_h_before_fit', $model)) {
        $say(sprintf('  ✔ کوتاه‌سازی پایین: از %.1f به %.1f میلی‌متر (%.1f میلی‌متر کاغذ کمتر)', (float) $model['paper_h_before_fit'], (float) $model['paper_h'], (float) ($model['tail_trim_mm'] ?? 0)));
    }
    if (!empty($model['keep_height'])) {
        $say('  توجه: این قالب «keep_height» است؛ ارتفاع کاغذ عمداً دست‌نخورده می‌ماند.');
    }
    if ((float) ($model['top_trim_mm'] ?? 0) > 0) {
        $say(sprintf('  ✔ تراز خودکار %.1f میلی‌متر از بالای فیش را حذف می‌کند و کاغذ کمتری مصرف می‌شود.', (float) $model['top_trim_mm']));
    } else {
        $say('  تراز خودکار فعال نشد (فاصلهٔ بالا از قبل کمتر از حد مجاز است).');
    }
} catch (Throwable $e) {
    $say('[FAIL] ساخت مدل قالب: ' . $e->getMessage());
}

// ── ۴) رسم واقعی و شمارش ردیف‌های سفید ───────────────────────────────────────
if (isset($options['render'])) {
    $say('');
    $say('--- رسم واقعی تصویر ---');
    if (!$gd) {
        $say('[FAIL] GD با FreeType فعال نیست؛ نمی‌توان رسم کرد.');
    } else {
        try {
            [$im, $info] = food_ticket_tpl_render_gd($model, $netCfg);
            $w = imagesx($im);
            $h = imagesy($im);
            $threshold = (int) ($netCfg['threshold'] ?? 170);
            $firstInk = $h;
            foreach ($im as $y => $row) {
                $ink = false;
                for ($x = 0; $x < $w; $x += 2) {
                    if ((imagecolorat($im, $x, $y) & 0xFF) < $threshold) {
                        $ink = true;
                        break;
                    }
                }
                if ($ink) {
                    $firstInk = $y;
                    break;
                }
            }
            $dpm = (float) ($netCfg['dots_per_mm'] ?? 8.0);
            $say(sprintf('  تصویر شبکه: %dx%d نقطه | سر چاپ %d نقطه', $w, $h, (int) ($info['head_dots'] ?? 0)));
            $say(sprintf('  اولین ردیف جوهر: %d نقطه = %.1f میلی‌متر', $firstInk, $firstInk / max(1.0, $dpm)));
            $trim = function_exists('food_ticket_np_trim_edges')
                ? food_ticket_np_trim_edges($im, $netCfg)
                : food_ticket_np_trim_top($im, $netCfg);
            $cutTop = (int) $trim['trimmed_dots'];
            $cutBottom = (int) ($trim['bottom_dots'] ?? 0);
            $say(sprintf('  برش بالا: %d نقطه = %.1f میلی‌متر (بالای فیش باقی‌مانده: %.1f میلی‌متر)', $cutTop, $cutTop / max(1.0, $dpm), max(0.0, $firstInk - $cutTop) / max(1.0, $dpm)));
            $say(sprintf('  کوتاه‌سازی پایین: %d نقطه = %.1f میلی‌متر', $cutBottom, $cutBottom / max(1.0, $dpm)));
            $finalH = imagesy($trim['image']);
            $say(sprintf('  ارتفاع نهایی تصویر: %d نقطه = %.1f میلی‌متر (قبل از برش: %.1f میلی‌متر → %.1f میلی‌متر کاغذ کمتر)',
                $finalH, $finalH / max(1.0, $dpm), $h / max(1.0, $dpm), ($h - $finalH) / max(1.0, $dpm)));
            $feed = max(0, (int) ($netCfg['feed_lines'] ?? 4));
            $lineMm = 30 / max(1.0, $dpm); // فاصلهٔ خط پیش‌فرض ESC/POS ≈ ۳۰ نقطه
            $say(sprintf('  تغذیهٔ پس از چاپ (feed_lines=%d): %.1f میلی‌متر — اگر می‌خواهید هیچ کاغذی هدر نرود این مقدار را ۰ یا ۱ بگذارید.', $feed, $feed * $lineMm));
            $showPreview = !empty($options['preview']);
            if ($showPreview) {
                $dir = $root . '/storage/food_ticket_prints';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                $file = $dir . '/diag_margin_' . date('Ymd_His') . '.png';
                imagepng($trim['image'], $file);
                $say('  تصویر برش‌خورده: ' . $file);
            }
        } catch (Throwable $e) {
            $say('[FAIL] رسم تصویر: ' . $e->getMessage());
        }
    }
} else {
    $say('');
    $say('برای شمارش دقیق ردیف‌های سفید، همین ابزار را با --render اجرا کنید.');
}

// ── ۵) اندازه‌گیری واقعی درایور ویندوز (بدون چاپ) ───────────────────────────
if (isset($options['probe']) || isset($options['win'])) {
    $say('');
    $say('--- اندازه‌گیری درایور ویندوز (بدون چاپ) ---');
    $winPrinter = (string) ($options['printer'] ?? $printer);
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        $say('[SKIP] این بخش فقط روی ویندوز معنا دارد.');
    } elseif ($winPrinter === '') {
        $say('[FAIL] نام چاپگر مشخص نیست؛ با --printer="نام چاپگر" اجرا کنید.');
    } elseif (!function_exists('food_ticket_tpl_windows_script')) {
        $say('[FAIL] ماژول قالب بارگذاری نشده است.');
    } else {
        try {
            $modelProbe = food_ticket_tpl_model(food_ticket_tpl_sample_event(), $row);
            $ps = food_ticket_tpl_windows_script($modelProbe, $winPrinter, '', true);
            $dir = $root . '/storage/food_ticket_spool';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $tmp = $dir . DIRECTORY_SEPARATOR . 'probe_' . bin2hex(random_bytes(4)) . '.ps1';
            file_put_contents($tmp, "\xEF\xBB\xBF" . $ps, LOCK_EX);
            $out = [];
            $code = 0;
            @exec('powershell -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            @unlink($tmp);
            $res = json_decode(trim((string) end($out)), true);
            if (!is_array($res)) {
                $say('[FAIL] پاسخ درایور خوانده نشد: ' . mb_substr(implode(' ', $out), 0, 300));
            } else {
                $say(sprintf('  چاپگر            = %s', (string) ($res['printer'] ?? '')));
                $say(sprintf('  اندازهٔ درخواستی   = %.1f x %.1f میلی‌متر', (float) ($res['paper_w'] ?? 0), (float) ($res['paper_h'] ?? 0)));
                $say(sprintf('  اندازهٔ اعمال‌شده  = %.1f x %.1f میلی‌متر %s', (float) ($res['applied_w'] ?? 0), (float) ($res['applied_h'] ?? 0), !empty($res['driver_form_matched']) ? '(Form آمادهٔ درایور)' : '(Form سفارشی)'));
                $say(sprintf('  محتوا (جوهر)      = %.1f تا %.1f میلی‌متر', (float) ($res['ink_first_mm'] ?? 0), (float) ($res['ink_last_mm'] ?? 0)));
                $extraH = (float) ($res['applied_h'] ?? 0) - (float) ($res['paper_h'] ?? 0);
                if (abs($extraH) > 1.0) {
                    $say(sprintf('  ⚠ اختلاف ارتفاع   = %.1f میلی‌متر → همین مقدار کاغذ اضافه تغذیه می‌شود. یک Form با ارتفاع %.1f میلی‌متر در تنظیمات چاپگر بسازید.', $extraH, (float) ($res['paper_h'] ?? 0)));
                } else {
                    $say('  ✔ درایور همان ارتفاعی را چاپ می‌کند که طرح لازم دارد (بدون پرت پایین فیش).');
                }
                $say(sprintf('  ناحیهٔ قابل چاپ   = %.1f x %.1f میلی‌متر | حاشیهٔ اجباری درایور: %.1f میلی‌متر', (float) ($res['printable_w_mm'] ?? 0), (float) ($res['printable_h_mm'] ?? 0), (float) ($res['margin_y_mm'] ?? 0)));
                if (!empty($res['note'])) {
                    $say('  یادداشت درایور    = ' . (string) $res['note']);
                }
                $forms = (array) ($res['forms'] ?? []);
                if ($forms !== []) {
                    $say('  Formهای درایور (نزدیک به عرض کاغذ):');
                    foreach ($forms as $f) {
                        $fm = (array) $f;
                        if (abs((float) ($fm['w_mm'] ?? 0) - (float) ($res['paper_w'] ?? 0)) <= 3) {
                            $say(sprintf('    - %-28s %.1f x %.1f میلی‌متر', (string) ($fm['name'] ?? ''), (float) ($fm['w_mm'] ?? 0), (float) ($fm['h_mm'] ?? 0)));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $say('[FAIL] اندازه‌گیری درایور: ' . $e->getMessage());
        }
    }
} else {
    $say('');
    $say('برای خواندن اندازه‌های واقعی درایور چاپگر ویندوز (بدون چاپ کاغذ) همین ابزار را با --probe اجرا کنید.');
}

$say('');
$say('=== پایان ===');
