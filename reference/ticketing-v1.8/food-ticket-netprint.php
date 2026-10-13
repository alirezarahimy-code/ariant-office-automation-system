<?php
declare(strict_types=1);

/**
 * چاپ فیش روی چاپگر شبکه (TCP 9100) با متن فارسی تمیز.
 *
 * مشکل قبلی: متن UTF-8 مستقیم به چاپگر فرستاده می‌شد. چاپگر حرارتی UTF-8 و حروف فارسی (اتصال حروف،
 * راست‌به‌چپ) را نمی‌فهمد و بایت‌ها را به‌صورت کاراکترهای بی‌معنی چاپ می‌کرد.
 *
 * راه‌حل: فیش مثل چاپ ویندوز «تصویر» رسم می‌شود (GD + فونت ویندوز) و به‌صورت ESC/POS raster
 * (GS v 0) ارسال می‌شود؛ پس به code page و فونت داخلی چاپگر وابسته نیست.
 * GD خودش حروف را به هم نمی‌چسباند و راست‌به‌چپ نمی‌کند، پس شکل‌دهی (اتصال حروف + bidi) در همین فایل است.
 *
 * تنظیمات اختیاری: storage/food_ticket_netprint.json  (نمونه در 00_NETPRINT_FA.txt)
 */

/** @return array<string,mixed> */
function food_ticket_np_settings(?array $tpl = null): array
{
    $defaults = [
        'width_dots'     => 576,   // 80mm ≈ 576 ، 58mm ≈ 384
        'font_px'        => 24,
        'title_scale'    => 1.3,
        'margin'         => 10,
        'line_gap'       => 8,
        'threshold'      => 170,   // پیکسل تیره‌تر از این = سیاه (بزرگ‌تر = پررنگ‌تر)
        'feed_lines'     => 2,   // تغذیهٔ کاغذ پیش از برش (۱.۳۵: از ۴ به ۲ کم شد — پرت کمتر؛ ۰ یا ۱ = کمترین پرت)
        'cut'            => true,
        'chunk_rows'     => 128,
        'dots_per_mm'    => 8.0,   // برای تراز عمودی/برش ردیف‌های سفید
        'top_gap_mm'     => 4.0,   // حداکثر فاصلهٔ سفید بالای فیش (دستور: ۴ میلی‌متر)
        'tail_mm'        => 3.0,   // فاصلهٔ سفید پایین فیش (۰ = دقیقاً تا آخرین نقطهٔ چاپ)
        'fit_bottom'     => true,  // کوتاه‌کردن فیش تا آخرین محتوا (کاغذ اضافه تغذیه نشود)
        'head_offset_mm' => 0.0,   // فاصلهٔ سرِ چاپ تا تیغهٔ برش (۰ = غیرفعال) — با tools/print_calibration.php اندازه بگیرید
        'win_top_shift_mm' => 0.0, // جبران عمودی صف ویندوز (منفی = انتقال طرح به بالا؛ فقط مسیر windows-spooler) — بدون این کلید، مقدارِ ذخیره‌شده خوانده نمی‌شد و پس از ذخیره «صفر» نمایش داده می‌شد (۱.۳۷.۴)
        'edge_align'     => true,  // تراز دقیق لبه‌ها: شروع چاپ = لبهٔ کاغذ ، برش = پایان آخرین خط چاپ‌شده
        'line_dots'      => 30,    // تعداد نقطه در هر «خط» فرمان‌های تغذیه/عقب‌راندن ESC/POS
        'reverse_feed_cmd' => 'esc_e', // فرمان عقب‌راندن کاغذ: esc_e | esc_k | none
        'font_file'      => '',
        'gd_native_bidi' => false, // فقط اگر GD سرور با libraqm ساخته شده و خودش شکل‌دهی می‌کند
        'save_preview'   => false, // true = PNG پیش‌نمایش هر فیش در storage/food_ticket_prints
        'log_align'      => true,
        'cut_mode'       => 'partial', // partial | full | none (none = بدون برش: سفیدیِ سرِ کاغذ از ریشه حذف می‌شود)  // ثبت پروفایل ترازِ لبه‌ها در لاگ (برای تشخیص «چرا هنوز سفید است»)
        'io_timeout'     => 10,
        'supersample'    => 2,     // رسم با کیفیت ۲ برابر و کوچک‌کردن؛ لبه‌های نرم‌تر
    ];
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    $file = $root . '/storage/food_ticket_netprint.json';
    $json = [];
    if (is_file($file)) {
        $json = json_decode((string) @file_get_contents($file), true);
        if (is_array($json)) {
            foreach ($defaults as $k => $v) {
                if (array_key_exists($k, $json) && $json[$k] !== null && $json[$k] !== '') {
                    $defaults[$k] = is_int($v) ? (int) $json[$k] : (is_float($v) ? (float) $json[$k] : (is_bool($v) ? (bool) $json[$k] : $json[$k]));
                }
            }
        }
    }
    // عرض کاغذ و برش از «قالب فیش» گرفته می‌شود، مگر اینکه در فایل json صریحاً تعیین شده باشد.
    if ($tpl !== null) {
        $explicit = isset($json) && is_array($json) ? $json : [];
        if (!array_key_exists('width_dots', $explicit)) {
            $mm = (int) ($tpl['paper_width_mm'] ?? 80);
            $defaults['width_dots'] = $mm >= 72 ? 576 : ($mm >= 50 ? 384 : 288);
        }
        if (!array_key_exists('cut', $explicit) && array_key_exists('cut_paper', $tpl)) {
            $defaults['cut'] = !empty($tpl['cut_paper']);
        }
    }
    $defaults['width_dots'] = max(192, min(832, (int) $defaults['width_dots']));
    $defaults['width_dots'] -= $defaults['width_dots'] % 8;
    $defaults['font_px'] = max(14, min(48, (int) $defaults['font_px']));
    return $defaults;
}

function food_ticket_np_find_font(string $custom = ''): string
{
    $candidates = [];
    if ($custom !== '') {
        $candidates[] = $custom;
    }
    $win = getenv('SystemRoot') ?: 'C:\\Windows';
    foreach (['tahoma.ttf', 'arial.ttf', 'segoeui.ttf', 'times.ttf', 'calibri.ttf'] as $f) {
        $candidates[] = $win . '\\Fonts\\' . $f;
    }
    $candidates[] = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $candidates[] = '/usr/share/fonts/dejavu/DejaVuSans.ttf';
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    throw new RuntimeException('فونت فارسی برای چاپ شبکه پیدا نشد (tahoma.ttf). مسیر فونت را در storage/food_ticket_netprint.json با کلید font_file بدهید.');
}

// ───────────────────────── شکل‌دهی حروف فارسی/عربی ─────────────────────────

/** @return array{dual:array<int,array<int,int>>,right:array<int,array<int,int>>,lam:array<int,array<int,int>>} */
function food_ticket_np_tables(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    // [مجزا, پایانی, آغازی, میانی]
    $dual = [
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59], 0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], 0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], 0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], 0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], 0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], 0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], 0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], 0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], 0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], 0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], 0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], 0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF], 0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];
    // [مجزا, پایانی] — به حرف بعدی نمی‌چسبند
    $right = [
        0x0627 => [0xFE8D, 0xFE8E], 0x0622 => [0xFE81, 0xFE82], 0x0623 => [0xFE83, 0xFE84],
        0x0625 => [0xFE87, 0xFE88], 0x062F => [0xFEA9, 0xFEAA], 0x0630 => [0xFEAB, 0xFEAC],
        0x0631 => [0xFEAD, 0xFEAE], 0x0632 => [0xFEAF, 0xFEB0], 0x0698 => [0xFB8A, 0xFB8B],
        0x0648 => [0xFEED, 0xFEEE], 0x0624 => [0xFE85, 0xFE86], 0x0629 => [0xFE93, 0xFE94],
        0x0649 => [0xFEEF, 0xFEF0], 0x0621 => [0xFE80, 0xFE80],
    ];
    $lam = [
        0x0627 => [0xFEFB, 0xFEFC], 0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA],
    ];
    $t = ['dual' => $dual, 'right' => $right, 'lam' => $lam];
    return $t;
}

/** @return list<int> */
function food_ticket_np_codepoints(string $s): array
{
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $out = [];
    foreach ($chars as $ch) {
        $out[] = (int) mb_ord($ch, 'UTF-8');
    }
    return $out;
}

/**
 * اتصال حروف (مجزا/آغازی/میانی/پایانی + لام‌الف). ورودی و خروجی به ترتیب منطقی است.
 * @param list<int> $cps
 * @return list<int>
 */
function food_ticket_np_shape(array $cps): array
{
    $t = food_ticket_np_tables();
    $dual = $t['dual'];
    $right = $t['right'];
    $lam = $t['lam'];
    $fix = [0x064A => 0x06CC, 0x0643 => 0x06A9]; // ی/ک عربی → فارسی

    $clean = [];
    foreach ($cps as $c) {
        if (($c >= 0x064B && $c <= 0x065F) || $c === 0x0670 || $c === 0x0640) {
            continue; // اعراب و کشیده
        }
        $clean[] = $fix[$c] ?? $c;
    }
    $n = count($clean);
    $out = [];
    $i = 0;
    while ($i < $n) {
        $c = $clean[$i];
        $isAr = isset($dual[$c]) || isset($right[$c]);
        if (!$isAr) {
            $out[] = $c;
            $i++;
            continue;
        }
        $prev = $i > 0 ? $clean[$i - 1] : null;
        $pj = $prev !== null && isset($dual[$prev]); // حرف قبلی می‌تواند به این بچسبد
        if ($c === 0x0644 && $i + 1 < $n && isset($lam[$clean[$i + 1]])) {
            $f = $lam[$clean[$i + 1]];
            $out[] = $pj ? $f[1] : $f[0];
            $i += 2;
            continue;
        }
        $next = $i + 1 < $n ? $clean[$i + 1] : null;
        $nj = isset($dual[$c]) && $next !== null && (isset($dual[$next]) || isset($right[$next]));
        if (isset($right[$c])) {
            $out[] = $pj ? $right[$c][1] : $right[$c][0];
        } else {
            $f = $dual[$c];
            $out[] = ($pj && $nj) ? $f[3] : ($pj ? $f[1] : ($nj ? $f[2] : $f[0]));
        }
        $i++;
    }
    return $out;
}

function food_ticket_np_class(int $c): string
{
    if (($c >= 0xFB50 && $c <= 0xFEFF)) {
        return 'R';
    }
    $isDigit = ($c >= 0x30 && $c <= 0x39) || ($c >= 0x0660 && $c <= 0x0669) || ($c >= 0x06F0 && $c <= 0x06F9);
    if ($isDigit) {
        return 'L'; // عدد: چپ‌به‌راست
    }
    if ($c >= 0x0600 && $c <= 0x06FF) {
        return 'R';
    }
    if (preg_match('/^[\p{L}\p{N}]$/u', (string) mb_chr($c, 'UTF-8')) === 1) {
        return 'L';
    }
    return 'N';
}

/**
 * متن منطقی → رشتهٔ «ظاهری» برای رسم چپ‌به‌راست توسط GD.
 * @return array{0:string,1:string} [متن ظاهری, جهت پایه R|L]
 */
function food_ticket_np_visual(string $text): array
{
    $cps = [];
    foreach (food_ticket_np_codepoints($text) as $c) {
        if ($c === 0x200C || $c === 0x200D || $c === 0x200E || $c === 0x200F || $c === 0xFEFF) {
            continue;
        }
        $cps[] = $c;
    }
    $shaped = food_ticket_np_shape($cps);
    $runs = [];
    $hasR = false;
    foreach ($shaped as $c) {
        $k = food_ticket_np_class($c);
        if ($k === 'R') {
            $hasR = true;
        }
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last][0] === $k) {
            $runs[$last][1][] = $c;
        } else {
            $runs[] = [$k, [$c]];
        }
    }
    $base = $hasR ? 'R' : 'L';
    $cnt = count($runs);
    for ($i = 0; $i < $cnt; $i++) {
        if ($runs[$i][0] === 'N') {
            $a = $i > 0 ? $runs[$i - 1][0] : $base;
            $b = $i + 1 < $cnt ? $runs[$i + 1][0] : $base;
            $runs[$i][0] = ($a === $b && $a !== 'N') ? $a : $base;
        }
    }
    $merged = [];
    foreach ($runs as $r) {
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last][0] === $r[0]) {
            $merged[$last][1] = array_merge($merged[$last][1], $r[1]);
        } else {
            $merged[] = $r;
        }
    }
    if ($base === 'R') {
        $merged = array_reverse($merged);
    }
    $mirror = [0x28 => 0x29, 0x29 => 0x28, 0x5B => 0x5D, 0x5D => 0x5B, 0x3C => 0x3E, 0x3E => 0x3C];
    $out = '';
    foreach ($merged as [$k, $chars]) {
        if ($k === 'R') {
            $chars = array_reverse($chars);
            foreach ($chars as $c) {
                $out .= (string) mb_chr($mirror[$c] ?? $c, 'UTF-8');
            }
        } else {
            foreach ($chars as $c) {
                $out .= (string) mb_chr($c, 'UTF-8');
            }
        }
    }
    return [$out, $base];
}

// ───────────────────────── رسم تصویر فیش ─────────────────────────

/** @return array{0:int,1:int,2:int} [عرض, بالازدگی(ascent), پایین‌زدگی(descent)] */
function food_ticket_np_measure(string $visual, int $px, string $font): array
{
    $box = imagettfbbox($px * 0.75, 0.0, $font, $visual === '' ? ' ' : $visual);
    if ($box === false) {
        return [0, $px, (int) ($px * 0.3)];
    }
    $w = max($box[2], $box[4]) - min($box[0], $box[6]);
    return [(int) $w, (int) -min($box[5], $box[7]), (int) max($box[1], $box[3])];
}

/**
 * متن فیش → تصویر سیاه/سفید.
 * @param array<string,mixed> $cfg
 */
function food_ticket_np_render(string $text, array $cfg): GdImage
{
    if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('افزونهٔ GD (با FreeType) در PHP فعال نیست؛ در php.ini خط extension=gd را فعال و سرویس را ری‌استارت کنید.');
    }
    $font = food_ticket_np_find_font((string) $cfg['font_file']);
    $W = (int) $cfg['width_dots'];
    $margin = (int) $cfg['margin'];
    $maxW = $W - 2 * $margin;
    $px = (int) $cfg['font_px'];
    $native = (bool) $cfg['gd_native_bidi'];
    $gap = (int) $cfg['line_gap'];

    [, $asc0, $desc0] = food_ticket_np_measure('ظخپگژgÁ', $px, $font);
    $lineH = $asc0 + $desc0 + $gap;

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $rows = [];     // ['t'=>'text'|'rule'|'blank', ...]
    $titleDone = false;
    foreach (explode("\n", $text) as $raw) {
        $line = rtrim(str_replace("\t", '    ', $raw));
        if (trim($line) === '') {
            $rows[] = ['t' => 'blank', 'h' => (int) ($lineH * 0.6)];
            continue;
        }
        if (preg_match('/^[\s=\-_*~.#+]{4,}$/u', $line) === 1) {
            $rows[] = ['t' => 'rule', 'h' => 12, 'dash' => str_contains($line, '-') || str_contains($line, '.')];
            continue;
        }
        $center = preg_match('/^\s{3,}\S/u', $line) === 1;
        $line = trim($line);
        $size = $px;
        if ($center && !$titleDone) {
            $size = (int) round($px * (float) $cfg['title_scale']);
        }
        $titleDone = true;

        // شکستن خط بلند بر اساس کلمات
        $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [$line];
        $chunks = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            $vis = $native ? $try : food_ticket_np_visual($try)[0];
            if ($cur !== '' && food_ticket_np_measure($vis, $size, $font)[0] > $maxW) {
                $chunks[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $chunks[] = $cur;
        }
        foreach ($chunks as $chunk) {
            if ($native) {
                $vis = $chunk;
                $base = preg_match('/[\x{0600}-\x{06FF}]/u', $chunk) === 1 ? 'R' : 'L';
            } else {
                [$vis, $base] = food_ticket_np_visual($chunk);
            }
            [$w, $asc, $desc] = food_ticket_np_measure($vis, $size, $font);
            $rows[] = ['t' => 'text', 'vis' => $vis, 'base' => $base, 'center' => $center, 'size' => $size, 'w' => $w, 'asc' => $asc, 'desc' => $desc];
        }
    }

    $H = $margin;
    foreach ($rows as $r) {
        $H += $r['t'] === 'text' ? ($r['asc'] + $r['desc'] + $gap) : $r['h'];
    }
    $H += $margin;

    $im = imagecreatetruecolor($W, max(8, $H));
    $white = (int) imagecolorallocate($im, 255, 255, 255);
    $black = (int) imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $white);

    $y = $margin;
    foreach ($rows as $r) {
        if ($r['t'] === 'blank') {
            $y += $r['h'];
            continue;
        }
        if ($r['t'] === 'rule') {
            $ry = $y + 5;
            if ($r['dash']) {
                for ($x = $margin; $x < $W - $margin; $x += 12) {
                    imagefilledrectangle($im, $x, $ry, min($x + 7, $W - $margin), $ry + 1, $black);
                }
            } else {
                imagefilledrectangle($im, $margin, $ry, $W - $margin, $ry + 2, $black);
            }
            $y += $r['h'];
            continue;
        }
        $w = (int) $r['w'];
        if ($r['center']) {
            $x = (int) (($W - $w) / 2);
        } elseif ($r['base'] === 'R') {
            $x = $W - $margin - $w;
        } else {
            $x = $margin;
        }
        $x = max(0, $x);
        $box = imagettfbbox($r['size'] * 0.75, 0.0, $font, $r['vis']);
        $offset = $box === false ? 0 : -min($box[0], $box[6]);
        imagettftext($im, $r['size'] * 0.75, 0.0, (int) ($x + $offset), (int) ($y + $r['asc']), $black, $font, $r['vis']);
        $y += $r['asc'] + $r['desc'] + $gap;
    }
    return $im;
}

/**
 * اطلاعات آخرین «برش بالای فیش» (برای گزارش چاپ/ابزار تشخیص).
 * @return array{dots:int,mm:float,first_ink_dots:int}
 */
function food_ticket_print_trim_info(?array $set = null): array
{
    // dots/mm = برشِ بالا، tail_dots/tail_mm = کوتاه‌سازیِ پایین
    static $info = ['dots' => 0, 'mm' => 0.0, 'first_ink_dots' => 0, 'tail_dots' => 0, 'tail_mm' => 0.0, 'last_ink_dots' => 0];
    if ($set !== null) {
        $info = $set + $info;
    }
    return $info;
}

/**
 * ردیف‌های سفید ابتدای و انتهای تصویر را می‌بُرد تا «پرتیِ کاغذ» صفر شود.
 *
 * این لایهٔ تضمین نرم‌افزاری است: مستقل از قالب، درایور و تنظیمات چاپگر
 *  • بالای فیش بیش از top_gap_mm سفید نمی‌ماند،
 *  • پایین فیش بیش از tail_mm سفید نمی‌ماند (اگر fit_bottom خاموش باشد دست نمی‌زند).
 *
 * @param array<string,mixed> $cfg
 * @return array{image:GdImage,trimmed_dots:int,bottom_dots:int,first_ink_dots:int,last_ink_dots:int}
 */
function food_ticket_np_trim_edges(GdImage $im, array $cfg): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $threshold = max(1, min(254, (int) ($cfg['threshold'] ?? 170)));
    $dpm = (float) ($cfg['dots_per_mm'] ?? 8.0);
    if ($dpm <= 0) {
        $dpm = 8.0;
    }

    // ── پیدا کردن اولین و آخرین ردیفِ دارای جوهر ────────────────────────────
    $inkRow = static function (GdImage $img, int $y, int $width, int $limit): bool {
        for ($x = 0; $x < $width; $x++) {
            if ((imagecolorat($img, $x, $y) & 0xFF) < $limit) {
                return true;
            }
        }
        return false;
    };
    $firstInk = -1;
    for ($y = 0; $y < $h; $y++) {
        if ($inkRow($im, $y, $w, $threshold)) {
            $firstInk = $y;
            break;
        }
    }
    if ($firstInk < 0) {
        // صفحهٔ کاملاً سفید: چیزی برای بریدن نیست
        food_ticket_print_trim_info(['dots' => 0, 'mm' => 0.0, 'first_ink_dots' => $h, 'tail_dots' => 0, 'tail_mm' => 0.0, 'last_ink_dots' => $h]);
        return ['image' => $im, 'trimmed_dots' => 0, 'bottom_dots' => 0, 'first_ink_dots' => $h, 'last_ink_dots' => $h];
    }
    $lastInk = $firstInk;
    for ($y = $h - 1; $y > $firstInk; $y--) {
        if ($inkRow($im, $y, $w, $threshold)) {
            $lastInk = $y;
            break;
        }
    }

    // ── برش بالا تا حد مجاز ────────────────────────────────────────────────
    $maxMm = max(0.0, min(20.0, (float) ($cfg['top_gap_mm'] ?? 4.0)));
    $allowed = (int) round($maxMm * $dpm);
    $cutTop = ($firstInk > $allowed + (int) round($dpm)) ? $firstInk - $allowed : 0;

    // ── کوتاه‌سازی پایین تا آخرین نقطهٔ چاپ + حاشیهٔ دلخواه ─────────────────
    $tailMm = max(0.0, min(20.0, (float) ($cfg['tail_mm'] ?? 3.0)));
    $cutBottom = 0;
    if (!empty($cfg['fit_bottom'])) {
        $allowedTail = (int) round($tailMm * $dpm);
        // مختصات برش‌ها مربوط به تصویر اصلی است: از ردیف cutTop تا $keep باقی می‌ماند
        $keep = $lastInk + 1 + $allowedTail;
        $cutBottom = max(0, $h - max($cutTop, $keep));
    }

    $newH = max(1, $h - $cutTop - $cutBottom);
    if ($cutTop === 0 && $cutBottom === 0) {
        food_ticket_print_trim_info(['dots' => 0, 'mm' => 0.0, 'first_ink_dots' => $firstInk, 'tail_dots' => 0, 'tail_mm' => 0.0, 'last_ink_dots' => $lastInk]);
        return ['image' => $im, 'trimmed_dots' => 0, 'bottom_dots' => 0, 'first_ink_dots' => $firstInk, 'last_ink_dots' => $lastInk];
    }

    $out = imagecreatetruecolor($w, $newH);
    $white = (int) imagecolorallocate($out, 255, 255, 255);
    imagefilledrectangle($out, 0, 0, $w - 1, $newH - 1, $white);
    imagecopy($out, $im, 0, 0, 0, $cutTop, $w, $newH);

    food_ticket_print_trim_info([
        'dots' => $cutTop,
        'mm' => $cutTop / $dpm,
        'first_ink_dots' => $firstInk,
        'tail_dots' => $cutBottom,
        'tail_mm' => $cutBottom / $dpm,
        'last_ink_dots' => $lastInk,
    ]);
    if (function_exists('error_log')) {
        error_log(sprintf(
            '[food-print] کوتاه‌سازی فیش: بالا %d نقطه (%.1fmm) ، پایین %d نقطه (%.1fmm) | جوهر: %d..%d از %d',
            $cutTop, $cutTop / $dpm, $cutBottom, $cutBottom / $dpm, $firstInk, $lastInk, $h
        ));
    }
    return ['image' => $out, 'trimmed_dots' => $cutTop, 'bottom_dots' => $cutBottom, 'first_ink_dots' => $firstInk, 'last_ink_dots' => $lastInk];
}

/**
 * سازگاری با نسخهٔ ۱.۳۴: فقط سقفِ سفیدیِ بالا را می‌بُرد و پایین را دست نمی‌زند.
 * @param array<string,mixed> $cfg
 * @return array{image:GdImage,trimmed_dots:int,first_ink_dots:int}
 */
function food_ticket_np_trim_top(GdImage $im, array $cfg): array
{
    $cfg['fit_bottom'] = false;
    $r = food_ticket_np_trim_edges($im, $cfg);
    return ['image' => $r['image'], 'trimmed_dots' => $r['trimmed_dots'], 'first_ink_dots' => $r['first_ink_dots']];
}

/** مسیر فایل تنظیمات ریزِ چاپ. */
function food_ticket_np_json_file(): string
{
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
    return $root . '/storage/food_ticket_netprint.json';
}

/**
 * نوشتن مقادیر تنظیمات چاپ در فایل JSON (فقط کلیدهای مجاز و با محدودهٔ امن).
 * @param array<string,mixed> $patch
 * @return array{saved:array<string,mixed>,file:string,error:string}
 */
function food_ticket_np_write_json(array $patch, ?string $path = null): array
{
    $path = $path ?? food_ticket_np_json_file();
    $current = [];
    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }
    $limits = [
        'head_offset_mm' => [0.0, 60.0], 'line_dots' => [8, 64], 'tail_mm' => [0.0, 20.0],
        'top_gap_mm' => [0.0, 20.0], 'feed_lines' => [0, 20], 'threshold' => [1, 254],
        'dots_per_mm' => [4.0, 16.0], 'win_top_shift_mm' => [-30.0, 30.0],
        'head_dots' => [0, 832], 'chunk_rows' => [16, 255], 'io_timeout' => [1, 60],
    ];
    foreach ($patch as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if ($key === 'cut_mode') {
            $candidate = strtolower(trim((string) $value));
            if (in_array($candidate, ['partial', 'full', 'none'], true)) {
                $current[$key] = $candidate;
            }
            continue;
        }
        if (in_array($key, ['edge_align', 'fit_bottom', 'cut'], true)) {
            $current[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
            continue;
        }
        if ($key === 'reverse_feed_cmd') {
            $candidate = strtolower(trim((string) $value));
            if (in_array($candidate, ['esc_e', 'esc_k', 'none'], true)) {
                $current[$key] = $candidate;
            }
            continue;
        }
        if (isset($limits[$key]) && is_numeric($value)) {
            [$min, $max] = $limits[$key];
            $number = max((float) $min, min((float) $max, (float) $value));
            $current[$key] = $number === (float) (int) $number ? (int) $number : round($number, 2);
        }
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $json = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        return ['saved' => [], 'file' => $path, 'error' => 'نوشتن فایل تنظیمات چاپ ناموفق بود: ' . $path];
    }
    return ['saved' => $current, 'file' => $path, 'error' => ''];
}

/**
 * وضعیت «تراز دقیق لبه‌های کاغذ»: عددهای واقعیِ فرمان‌هایی که به چاپگر می‌رود.
 * @return array<string,mixed>
 */
function food_ticket_np_align_state(?array $cfg = null): array
{
    $cfg = $cfg ?? food_ticket_np_settings();
    $dpm = (float) ($cfg['dots_per_mm'] ?? 8.0);
    if ($dpm <= 0) {
        $dpm = 8.0;
    }
    $lineDots = max(8, min(64, (int) ($cfg['line_dots'] ?? 30)));
    $headOffset = max(0.0, min(60.0, (float) ($cfg['head_offset_mm'] ?? 0.0)));
    $tailMm = max(0.0, min(20.0, (float) ($cfg['tail_mm'] ?? 3.0)));
    $edge = !empty($cfg['edge_align']);
    $reverseLines = (int) max(0, min(40, (int) round($headOffset * $dpm / $lineDots)));
    $reverseCmd = strtolower(trim((string) ($cfg['reverse_feed_cmd'] ?? 'esc_e')));
    $reverseArg = $reverseCmd === 'esc_k' ? (int) max(1, min(255, $reverseLines * $lineDots)) : $reverseLines;
    $cutDots = (int) max(0, min(255, (int) round(max(0.0, $headOffset - $tailMm) * $dpm)));
    $active = $edge && $headOffset > 0;
    if ($active) {
        $verdict = 'ready';
        $hint = sprintf(
            'تراز دقیق لبه‌ها فعال است: پیش از چاپ %d خط (≈%.1f میلی‌متر) کاغذ عقب می‌رود و برش %.1f میلی‌متر بعد از آخرین خط زده می‌شود. فرمان: %s %d %s (ارتفاع خط: %d نقطه).',
            $reverseLines,
            $reverseLines * $lineDots / $dpm,
            $cutDots / $dpm,
            $reverseCmd === 'esc_k' ? 'ESC K' : ($reverseCmd === 'none' ? 'بدون عقب‌راندن' : 'ESC e'),
            $reverseArg,
            $reverseCmd === 'esc_k' ? 'نقطه' : 'خط',
            $lineDots
        );
    } elseif (!$edge) {
        $verdict = 'disabled';
        $hint = 'تراز دقیق لبه‌ها خاموش است؛ برای حذف سفیدیِ بالای فیش گزینهٔ «تراز دقیق» را روشن کنید.';
    } else {
        $verdict = 'measure';
        $hint = 'عدد «فاصلهٔ سرِ چاپ تا تیغه» صفر است. کارت کالیبراسیون را بچاپید، فاصلهٔ لبهٔ کاغذ تا نوار سیاه را اندازه بگیرید و همان عدد را اینجا وارد کنید (فیش‌پرینترهای رایج ≈۲۰ میلی‌متر).';
    }
    return [
        // ۱.۳۷.۴ — همهٔ کلیدهایی که فرم پنل نشان می‌دهد باید همین‌جا برگردند، وگرنه
        // پس از هر ذخیره/بارگذاری، فرم مقدار «صفر/پیش‌فرض» را نشان می‌دهد (باگِ گزارش‌شده).
        'head_offset_mm' => $headOffset,
        'edge_align' => $edge,
        'tail_mm' => $tailMm,
        'line_dots' => $lineDots,
        'dots_per_mm' => $dpm,
        'reverse_feed_cmd' => (string) ($cfg['reverse_feed_cmd'] ?? 'esc_e'),
        'reverse_lines' => $reverseLines,
        'reverse_arg' => $reverseArg,
        'reverse_unit' => $reverseCmd === 'esc_k' ? 'dots' : 'lines',
        'reverse_dots' => $reverseLines * $lineDots,
        'reverse_mm' => round($reverseLines * $lineDots / $dpm, 1),
        'cut_feed_dots' => $cutDots,
        'cut_feed_mm' => round($cutDots / $dpm, 1),
        'cut_mode' => (string) ($cfg['cut_mode'] ?? 'partial'),
        'win_top_shift_mm' => (float) ($cfg['win_top_shift_mm'] ?? 0.0),
        'top_gap_mm' => (float) ($cfg['top_gap_mm'] ?? 4.0),
        'feed_lines' => (int) ($cfg['feed_lines'] ?? 2),
        'threshold' => (int) ($cfg['threshold'] ?? 170),
        'chunk_rows' => (int) ($cfg['chunk_rows'] ?? 128),
        'head_dots' => (int) ($cfg['head_dots'] ?? 576),
        'fit_bottom' => !empty($cfg['fit_bottom']),
        'cut' => !empty($cfg['cut']),
        'log_align' => !empty($cfg['log_align']),
        'save_preview' => !empty($cfg['save_preview']),
        'io_timeout' => (int) ($cfg['io_timeout'] ?? 10),
        'active' => $active,
        'verdict' => $verdict,
        'hint' => $hint,
    ];
}

/**
 * فرمان «چاپ و عقب‌راندن کاغذ» (ESC/POS).
 *
 * چرا لازم است؟ پس از هر برش، لبهٔ کاغذ روی «تیغه» می‌ایستد؛ تیغه حدود ۲۰ میلی‌متر
 * پایین‌تر از سرِ چاپ است. اگر همان‌جا چاپ شروع شود، ۲۰ میلی‌متر اول کاغذ سفید می‌ماند.
 * با عقب‌راندن کاغذ به اندازهٔ همان فاصله، لبهٔ کاغذ دقیقاً زیر سرِ چاپ می‌آید و چاپ از
 * «ابتدای کاغذ» شروع می‌شود.
 *
 * @param int $lines تعداد خط (هر خط = $lineDots نقطه؛ پیش‌فرض ۳۰)
 * @param string $cmd esc_e (ESC e n) | esc_k (ESC K n) | none
 * @param int $lineDots تعداد نقطهٔ هر خط
 */
function food_ticket_np_reverse_feed(int $lines, string $cmd = 'esc_e', int $lineDots = 30): string
{
    $lines = max(0, min(40, $lines));
    if ($lines === 0) {
        return '';
    }
    switch (strtolower(trim($cmd))) {
        case 'esc_k':
        case '1b4b':
            // ۱.۳۷.۳ — اصلاح مهم: ESC K n بر حسب «نقطه» است، نه «خط».
            // تا ۱.۳۷.۲ همان عدد خط را می‌فرستادیم (مثلاً 5 به‌جای 150) و نتیجه‌اش این بود که
            // کاربر «ESC K n» را امتحان می‌کرد، کاغذ ۰.۶ میلی‌متر عقب می‌رفت و به این نتیجه
            // می‌رسید که چاپگر عقب‌راندن ندارد. حالا خط‌ها به نقطه تبدیل می‌شوند (سقف ۲۵۵ نقطه).
            $dots = (int) max(1, min(255, $lines * max(1, $lineDots)));
            return "\x1b\x4b" . chr($dots);
        case 'none':
        case 'off':
        case 'disabled':
            return '';
        default:
            // ESC e n — چاپ و عقب‌راندن n «خط» (ارتفاع خط با ESC 3 قفل شده است)
            return "\x1b\x65" . chr($lines);
    }
}

/** اطلاعات آخرین ترازِ لبه‌ها (برای لاگ/ابزار تشخیص). */
function food_ticket_print_align_info(?array $set = null): array
{
    static $info = ['reverse_lines' => 0, 'reverse_dots' => 0, 'cut_feed_dots' => 0, 'head_offset_mm' => 0.0, 'tail_mm' => 0.0, 'edge_align' => false];
    if ($set !== null) {
        $info = $set + $info;
    }
    return $info;
}

/**
 * تصویر → دستورهای ESC/POS (GS v 0) + تغذیهٔ کاغذ + برش.
 *
 * قاعدهٔ قطعی (۱.۳۷): ابتدای چاپ = ابتدای کاغذ ، انتهای چاپ = انتهای کاغذ.
 * این کار فقط وقتی امکان‌پذیر است که فاصلهٔ «سرِ چاپ تا تیغه» (head_offset_mm) را بدانیم
 * (با tools/print_calibration.php اندازه بگیرید) و چاپگر فرمان عقب‌راندن را پشتیبانی کند.
 *
 * @param array<string,mixed> $cfg
 */
function food_ticket_np_escpos(GdImage $im, array $cfg): string
{
    $W = imagesx($im);
    $H = imagesy($im);
    $bytesPerRow = intdiv($W + 7, 8);
    $threshold = max(1, min(254, (int) $cfg['threshold']));
    $chunkRows = max(16, min(255, (int) $cfg['chunk_rows']));
    $dpm = (float) ($cfg['dots_per_mm'] ?? 8.0);
    if ($dpm <= 0) {
        $dpm = 8.0;
    }
    $lineDots = max(8, min(64, (int) ($cfg['line_dots'] ?? 30)));
    $headOffsetMm = max(0.0, min(60.0, (float) ($cfg['head_offset_mm'] ?? 0.0)));
    $tailMm = max(0.0, min(20.0, (float) ($cfg['tail_mm'] ?? 3.0)));
    // ۱.۳۷.۳ — ESC @ (init) و سپس ESC 3 n: «ارتفاع خط» چاپگر را رسماً روی line_dots قفل می‌کنیم.
    // چرا حیاتی است؟ فرمان ESC e n یعنی «n *خط* عقب برو» و اندازهٔ آن «خط» از همین تنظیم می‌آید.
    // اگر این را ندهیم، ارتفاع خط پیش‌فرض چاپگر (معمولاً ۱/۶ اینچ = ۳۳.۸ نقطه در ۲۰۳dpi، نه ۳۰)
    // ملاک می‌شود و فاصلهٔ عقب‌راندن واقعی با آنچه در پنل محاسبه می‌شود فرق می‌کند؛ همچنین اگر
    // درایور/تنظیمات دستی ارتفاع خط را عوض کرده باشد، کالیبراسیون بی‌سروصدا از دست می‌رود.
    $cutMode = strtolower(trim((string) ($cfg['cut_mode'] ?? 'partial')));
    if (!in_array($cutMode, ['partial', 'full', 'none'], true)) {
        $cutMode = 'partial';
    }
    $doCut = !empty($cfg['cut']) && $cutMode !== 'none';
    $edgeAlign = !empty($cfg['edge_align']) && $headOffsetMm > 0 && $doCut;

    $out = "\x1b\x40"; // ESC @  (init)
    $out .= "\x1b\x33" . chr($lineDots); // ESC 3 n — قفل ارتفاع خط روی line_dots
    // ── تراز لبهٔ بالا: کاغذ را به عقب می‌کشیم تا لبهٔ کاغذ زیر سرِ چاپ بیاید ──
    if ($edgeAlign) {
        $lines = (int) round($headOffsetMm * $dpm / $lineDots);
        $reverse = food_ticket_np_reverse_feed($lines, (string) ($cfg['reverse_feed_cmd'] ?? 'esc_e'), $lineDots);
        if ($reverse !== '') {
            $out .= $reverse;
        }
        food_ticket_print_align_info([
            'reverse_lines' => $lines,
            'reverse_dots' => $lines * $lineDots,
            'head_offset_mm' => $headOffsetMm,
            'tail_mm' => $tailMm,
            'edge_align' => true,
        ]);
    }
    $out .= "\x1b\x61\x00"; // چپ‌چین (تصویر خودش عرض کامل است)
    for ($y0 = 0; $y0 < $H; $y0 += $chunkRows) {
        $rows = min($chunkRows, $H - $y0);
        $data = '';
        for ($y = $y0; $y < $y0 + $rows; $y++) {
            $line = '';
            for ($bx = 0; $bx < $bytesPerRow; $bx++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $bx * 8 + $bit;
                    if ($x < $W && (imagecolorat($im, $x, $y) & 0xFF) < $threshold) {
                        $byte |= (0x80 >> $bit);
                    }
                }
                $line .= chr($byte);
            }
            $data .= $line;
        }
        $out .= "\x1d\x76\x30\x00" . pack('v', $bytesPerRow) . pack('v', $rows) . $data;
    }
    $cutCode = $cutMode === 'full' ? "\x41" : "\x42"; // GS V 65 = برش کامل | GS V 66 = برش جزئی
    if ($doCut) {
        if ($edgeAlign) {
            // ── تراز لبهٔ پایین: تیغه دقیقاً «tail_mm» بعد از آخرین خط چاپ‌شده ببرد ──
            $feedDots = (int) round(max(0.0, $headOffsetMm - $tailMm) * $dpm);
            $feedDots = max(0, min(255, $feedDots));
            $out .= "\x1d\x56" . $cutCode . chr($feedDots); // GS V m n = تغذیه n نقطه سپس برش
            food_ticket_print_align_info(['cut_feed_dots' => $feedDots]);
        } else {
            $out .= "\x1b\x64" . chr(max(0, min(20, (int) $cfg['feed_lines'])));
            $out .= "\x1d\x56" . $cutCode . "\x00"; // feed to cut position + cut
        }
    } else {
        // cut_mode=none: هیچ برشی زده نمی‌شود؛ کاغذ عقب نمی‌رود و لبهٔ کاغذ زیر سرِ چاپ می‌ماند
        // ⇒ فیش بعدی از همان‌جا ادامه می‌یابد و «سفیدیِ ۲۰ میلی‌متری» موضوعیت ندارد.
        $out .= "\x1b\x64" . chr(max(0, min(20, (int) $cfg['feed_lines'])));
    }
    // ۱.۳۷ — پروفایلِ کاملِ بایت‌های تراز در یک خط لاگ: با این خط می‌توان بدون حضور روی
    // سرور فهمید کاغذ عقب می‌رود یا نه (reverse_hex خالی = فرمان عقب‌راندن فرستاده نشده).
    if (!empty($cfg['log_align'])) {
        error_log(sprintf(
            '[food-print-align] edge_align=%s head_offset=%.1fmm dpm=%.1f line_dots=%d reverse_cmd=%s reverse_hex=%s cut_hex=%s cut_feed_dots=%d img=%dx%d total_bytes=%d',
            $edgeAlign ? 'on' : 'off',
            $headOffsetMm,
            $dpm,
            $lineDots,
            (string) ($cfg['reverse_feed_cmd'] ?? 'esc_e'),
            ($edgeAlign && isset($reverse) && $reverse !== '') ? bin2hex($reverse) : '-',
            bin2hex(substr($out, -4)),
            isset($feedDots) ? (int) $feedDots : -1,
            $W,
            $H,
            strlen($out)
        ));
    }
    return $out;
}

/** @param array<string,mixed>|null $cfgOverride */
function food_ticket_np_payload(string $text, ?array $cfgOverride = null): string
{
    $cfg = $cfgOverride ?? food_ticket_np_settings();
    $im = food_ticket_np_render($text, $cfg);
    try {
        if (!empty($cfg['save_preview'])) {
            $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
            $dir = $root . '/storage/food_ticket_prints';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            @imagepng($im, $dir . '/net_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.png');
        }
        return food_ticket_np_escpos($im, $cfg);
    } finally {
        unset($im);
    }
}

/**
 * ارسال کامل بایت‌ها به چاپگر شبکه؛ تا نوشته‌شدن همهٔ داده تکرار می‌کند (fwrite ممکن است جزئی بنویسد).
 */
function food_ticket_np_send(string $host, int $port, string $payload, int $timeout = 10): void
{
    $errno = 0;
    $error = '';
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, 4);
    if (!$socket) {
        throw new RuntimeException('اتصال به چاپگر ناموفق بود: ' . ($error ?: ('خطای ' . $errno)));
    }
    stream_set_blocking($socket, true);
    stream_set_timeout($socket, $timeout);
    $len = strlen($payload);
    $sent = 0;
    $deadline = microtime(true) + $timeout + ($len / 200000);
    try {
        while ($sent < $len) {
            $n = @fwrite($socket, substr($payload, $sent, 8192));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out']) || microtime(true) > $deadline) {
                    throw new RuntimeException('زمان ارسال به چاپگر به پایان رسید.');
                }
                if ($n === false) {
                    throw new RuntimeException('ارسال کامل فیش به چاپگر انجام نشد.');
                }
                usleep(20000);
                continue;
            }
            $sent += $n;
        }
        @fflush($socket);
        if (function_exists('stream_socket_shutdown')) {
            @stream_socket_shutdown($socket, STREAM_SHUT_WR);
        }
        usleep(150000);
    } finally {
        @fclose($socket);
    }
}

// ───────────────────── چاپ شبکه با چیدمان قالب فیش (همان ظاهر چاپ ویندوز) ─────────────────────

/**
 * فایل فونت TTF برای چاپ شبکه. اولویت: font_file در تنظیمات، سپس فونت قالب (مثلاً B Nazanin)، سپس Tahoma/Arial.
 */
function food_ticket_np_find_font_for(array $tpl, array $cfg, bool $bold): string
{
    $win = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\Fonts\\';
    $custom = trim((string) ($cfg['font_file'] ?? ''));
    $names = [];
    if ($custom !== '') {
        $names[] = $custom;
    }
    $family = strtolower(trim((string) ($tpl['font_family'] ?? '')));
    $map = [
        'b nazanin' => $bold ? ['BNazaninBd.ttf', 'BNazanin-Bold.ttf', 'BNazanin.ttf'] : ['BNazanin.ttf', 'Nazanin.ttf'],
        'b titr'    => ['BTitrBd.ttf', 'BTitr.ttf'],
        'vazirmatn' => $bold ? ['Vazirmatn-Bold.ttf', 'Vazirmatn-Regular.ttf'] : ['Vazirmatn-Regular.ttf', 'Vazirmatn[wght].ttf'],
        'vazir'     => $bold ? ['Vazir-Bold.ttf', 'Vazir.ttf'] : ['Vazir.ttf', 'Vazir-Regular.ttf'],
        'tahoma'    => $bold ? ['tahomabd.ttf', 'tahoma.ttf'] : ['tahoma.ttf'],
    ];
    foreach ($map[$family] ?? [] as $f) {
        $names[] = $win . $f;
    }
    // جایگزین مطمئن: Tahoma (فارسی و ارقام فارسی را دارد)، سپس Arial
    foreach ($bold ? ['tahomabd.ttf', 'tahoma.ttf', 'arialbd.ttf', 'arial.ttf'] : ['tahoma.ttf', 'arial.ttf', 'segoeui.ttf'] as $f) {
        $names[] = $win . $f;
    }
    $names[] = '/usr/share/fonts/truetype/dejavu/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
    foreach ($names as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    throw new RuntimeException('فونت فارسی برای چاپ شبکه پیدا نشد (tahoma.ttf). مسیر فونت را در storage/food_ticket_netprint.json با کلید font_file بدهید.');
}

/** @return array{0:int,1:int} [ascent, descent] ثابت برای یک اندازه، تا فاصلهٔ خطوط ناهموار نشود */
function food_ticket_np_metrics(int $size, string $font): array
{
    static $cache = [];
    $k = $font . '|' . $size;
    if (!isset($cache[$k])) {
        [, $a, $d] = food_ticket_np_measure('ظخپگژلgÁ', $size, $font);
        $cache[$k] = [max(1, $a), max(1, $d)];
    }
    return $cache[$k];
}

/**
 * شکستن متن به خطوط با عرض مجاز؛ هر خط به ترتیب ظاهری (RTL شکل‌داده‌شده) برگردانده می‌شود.
 * @return list<array{vis:string,w:int}>
 */
function food_ticket_np_wrap(string $text, int $size, string $font, int $maxW, bool $native): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return [];
    }
    $vis = static fn (string $t): string => $native ? $t : food_ticket_np_visual($t)[0];
    $lines = [];
    $cur = '';
    foreach (preg_split('/ /u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text] as $word) {
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        if ($cur !== '' && food_ticket_np_measure($vis($try), $size, $font)[0] > $maxW) {
            $lines[] = $cur;
            $cur = $word;
        } else {
            $cur = $try;
        }
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }
    $out = [];
    foreach ($lines as $l) {
        $v = $vis($l);
        $out[] = ['vis' => $v, 'w' => food_ticket_np_measure($v, $size, $font)[0]];
    }
    return $out;
}

/**
 * ساخت چیدمان فیش از رویداد و قالب (همان داده‌ای که چاپ ویندوز استفاده می‌کند).
 * @return array<string,mixed>
 */
function food_ticket_np_layout(array $event, array $tpl): array
{
    $g = function_exists('food_ticket_graphic_rows') ? food_ticket_graphic_rows($event, $tpl) : ['food' => '', 'food_label' => 'نوع غذا', 'rows' => []];
    $fa = static fn (string $s): string => function_exists('food_ticket_fa_digits') ? food_ticket_fa_digits($s) : $s;
    $logo = function_exists('food_ticket_template_logo_base64') ? food_ticket_template_logo_base64($tpl) : '';
    return [
        'company' => $fa((string) ($tpl['company'] ?? '')),
        'title' => $fa((string) ($tpl['title'] ?? '')),
        'footer' => $fa((string) ($tpl['footer'] ?? '')),
        'logo' => $logo,
        'food' => (string) $g['food'],
        'food_label' => (string) $g['food_label'],
        'rows' => $g['rows'],
        'separator' => !empty($tpl['show_separator']),
    ];
}

/**
 * رسم فیش (سطر برچسب/مقدار، کادر نوع غذا، کادر دور فیش) با GD، با نمونه‌برداری ۲ برابر برای لبه‌های نرم.
 * @param array<string,mixed> $layout
 * @param array<string,mixed> $tpl
 * @param array<string,mixed> $cfg
 */
function food_ticket_np_render_layout(array $layout, array $tpl, array $cfg): GdImage
{
    if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('افزونهٔ GD (با FreeType) در PHP فعال نیست؛ در php.ini خط extension=gd را فعال و سرویس را ری‌استارت کنید.');
    }
    $S = max(1, min(3, (int) ($cfg['supersample'] ?? 2)));
    $W1 = (int) $cfg['width_dots'];
    $W = $W1 * $S;
    $px = (int) $cfg['font_px'] * $S;
    $native = (bool) $cfg['gd_native_bidi'];
    $reg = food_ticket_np_find_font_for($tpl, $cfg, false);
    $bld = food_ticket_np_find_font_for($tpl, $cfg, true);
    $faux = ($bld === $reg);

    $outer = 3 * $S;
    $pad = 12 * $S;
    $x0 = $outer + $pad;
    $innerW = $W - 2 * $x0;
    $gap = 6 * $S;

    $ops = [];
    $y = $outer + 8 * $S;

    $text = static function (string $t, int $size, bool $bold, string $align, int $left, int $width) use (&$ops, &$y, $reg, $bld, $native, $gap): int {
        $font = $bold ? $bld : $reg;
        $lines = food_ticket_np_wrap($t, $size, $font, $width, $native);
        [$asc, $desc] = food_ticket_np_metrics($size, $font);
        $h = 0;
        foreach ($lines as $ln) {
            $lw = $ln['w'];
            $x = $align === 'c' ? $left + intdiv($width - $lw, 2) : ($align === 'r' ? $left + $width - $lw : $left);
            $ops[] = ['t' => 'text', 'vis' => $ln['vis'], 'x' => max(0, $x), 'y' => $y + $h, 'size' => $size, 'bold' => $bold, 'asc' => $asc];
            $h += $asc + $desc + $gap;
        }
        return $h;
    };

    $logoImg = null;
    if ((string) $layout['logo'] !== '') {
        $raw = base64_decode((string) $layout['logo'], true);
        $logoImg = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($logoImg) {
            $lw = min((int) ($innerW * 0.34), imagesx($logoImg) * $S);
            $lh = (int) ($lw * imagesy($logoImg) / max(1, imagesx($logoImg)));
            $ops[] = ['t' => 'logo', 'x' => $x0 + intdiv($innerW - $lw, 2), 'y' => $y, 'w' => $lw, 'h' => $lh];
            $y += $lh + 8 * $S;
        }
    }
    if ((string) $layout['company'] !== '') {
        $y += $text((string) $layout['company'], (int) round($px * 1.25), true, 'c', $x0, $innerW);
    }
    if ((string) $layout['title'] !== '') {
        $y += $text((string) $layout['title'], $px, false, 'c', $x0, $innerW);
    }
    if ((string) $layout['company'] !== '' || (string) $layout['title'] !== '' || $logoImg) {
        $ops[] = ['t' => 'dash', 'y' => $y + 3 * $S];
        $y += 12 * $S;
    }

    if ((string) $layout['food'] !== '') {
        $y += $text((string) $layout['food_label'], (int) round($px * 0.85), false, 'c', $x0, $innerW);
        $boxPad = 8 * $S;
        $startY = $y;
        $y += $boxPad;
        $h = $text((string) $layout['food'], (int) round($px * 1.5), true, 'c', $x0 + $boxPad, $innerW - 2 * $boxPad);
        $y += $h + $boxPad - $gap;
        $ops[] = ['t' => 'box', 'x' => $x0, 'y' => $startY, 'w' => $innerW, 'h' => $y - $startY];
        $y += 12 * $S;
    }

    $labelW = (int) ($innerW * 0.38);
    $valueW = $innerW - $labelW - 8 * $S;
    foreach ((array) $layout['rows'] as $r) {
        $yy = $y;
        $h1 = $text((string) ($r['l'] ?? ''), (int) round($px * 0.92), false, 'r', $x0 + $valueW + 8 * $S, $labelW);
        $y = $yy;
        $h2 = $text((string) ($r['v'] ?? ''), $px, true, 'l', $x0, $valueW);
        $y = $yy + max($h1, $h2) + 2 * $S;
    }

    if (!empty($layout['separator'])) {
        $ops[] = ['t' => 'dash', 'y' => $y + 2 * $S];
        $y += 10 * $S;
    }
    if ((string) $layout['footer'] !== '') {
        $y += $text((string) $layout['footer'], $px, false, 'c', $x0, $innerW);
    }
    $y += 8 * $S;
    $H = $y + $outer;

    $im = imagecreatetruecolor($W, max(8, $H));
    $white = (int) imagecolorallocate($im, 255, 255, 255);
    $black = (int) imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $white);
    imagesetthickness($im, max(1, $S));

    foreach ($ops as $op) {
        switch ($op['t']) {
            case 'text':
                $font = $op['bold'] ? $bld : $reg;
                $box = imagettfbbox($op['size'] * 0.75, 0.0, $font, $op['vis']);
                $off = $box === false ? 0 : -min($box[0], $box[6]);
                $bx = (int) ($op['x'] + $off);
                $by = (int) ($op['y'] + $op['asc']);
                imagettftext($im, $op['size'] * 0.75, 0.0, $bx, $by, $black, $font, $op['vis']);
                if ($op['bold'] && $faux) {
                    imagettftext($im, $op['size'] * 0.75, 0.0, $bx + $S, $by, $black, $font, $op['vis']);
                }
                break;
            case 'dash':
                for ($x = $x0; $x < $W - $x0; $x += 10 * $S) {
                    imagefilledrectangle($im, $x, $op['y'], min($x + 6 * $S, $W - $x0), $op['y'] + max(1, $S), $black);
                }
                break;
            case 'box':
                imagesetthickness($im, 2 * $S);
                imagerectangle($im, $op['x'], $op['y'], $op['x'] + $op['w'], $op['y'] + $op['h'], $black);
                imagesetthickness($im, max(1, $S));
                break;
            case 'logo':
                if ($logoImg) {
                    imagecopyresampled($im, $logoImg, $op['x'], $op['y'], 0, 0, $op['w'], $op['h'], imagesx($logoImg), imagesy($logoImg));
                }
                break;
        }
    }
    // کادر دور فیش
    imagesetthickness($im, max(1, $S));
    imagerectangle($im, $outer, $outer, $W - 1 - $outer, $H - 1 - $outer, $black);

    if ($S === 1) {
        return $im;
    }
    $out = imagecreatetruecolor($W1, max(8, intdiv($H, $S)));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $W1, imagesy($out), $W, $H);
    return $out;
}

/**
 * آدرس/پورت چاپگر شبکه‌ای از تنظیمات سامانه (یا مقدار پیش‌فرض 9100).
 * @return array{host:string,port:int}
 */
function food_ticket_np_target(?array $cfg = null): array
{
    $cfg = $cfg ?? food_ticket_np_settings();
    $host = '';
    $port = 9100;
    $source = 'none';
    try {
        $host = trim((string) (setting('food_ticket_netprint_host', '') ?: setting('food_ticket_printer_host', '')));
        $port = (int) (setting('food_ticket_netprint_port', 0) ?: setting('food_ticket_printer_port', 9100) ?: 9100);
        if ($host !== '') {
            $source = 'settings';
        }
    } catch (Throwable) {
    }
    // ── ۱.۳۷.۲ (ریشهٔ «کارت کالیبراسیون چاپ نمی‌شود») ───────────────────────────────
    // تا ۱.۳۷.۱ آدرس کارت کالیبراسیون فقط از جدول settings خوانده می‌شد؛ ولی پنل، آدرس را در
    // food_ticket_config ذخیره می‌کند و هیچ‌جای پروژه آن کلیدِ settings را نمی‌نویسد ⇒ host خالی
    // می‌ماند و کارت کالیبراسیون هرگز ارسال نمی‌شد («آدرس چاپگر شبکه‌ای تنظیم نشده است»).
    // اکنون اگر settings خالی بود، همان چاپگر واقعیِ فیش (جدول food_ticket_config) مبنا می‌شود تا
    // کارت کالیبراسیون دقیقاً به همان چاپگری برود که فیش‌های واقعی می‌روند.
    if ($host === '' && function_exists('food_ticket_config')) {
        try {
            $db = food_ticket_config();
            $mode = function_exists('food_ticket_normalize_printer_mode')
                ? food_ticket_normalize_printer_mode($db['printer_mode'] ?? 'tcp_raw')
                : (string) ($db['printer_mode'] ?? 'tcp_raw');
            $dbHost = trim((string) ($db['printer_host'] ?? ''));
            $dbPort = max(1, (int) ($db['printer_port'] ?? 9100));
            if ($dbHost !== '') {
                $host = $dbHost;
                $port = $dbPort;
                $source = 'food_ticket_config';
            } elseif ($mode === 'windows_share' && trim((string) ($db['printer_share'] ?? '')) !== '') {
                $source = 'windows_spooler';
            }
        } catch (Throwable) {
        }
    }
    return ['host' => $host, 'port' => max(1, $port), 'source' => $source];
}

/**
 * تصویر «کارت کالیبراسیون»: خط‌کش میلی‌متری که از ردیف صفر شروع می‌شود.
 * با این کارت، فاصلهٔ «سرِ چاپ تا تیغه» (head_offset_mm) را با خط‌کش واقعی اندازه می‌گیرید.
 */
function food_ticket_np_calibration_image(array $cfg): GdImage
{
    $w = isset($cfg['width_dots']) ? (int) $cfg['width_dots'] : 576;
    $w = max(192, min(832, $w - $w % 8));
    $dpm = (float) ($cfg['dots_per_mm'] ?? 8.0);
    if ($dpm <= 0) {
        $dpm = 8.0;
    }
    $mm = static fn (float $v): int => (int) round($v * $dpm);
    $h = max(120, $mm(95.0));
    $im = imagecreatetruecolor($w, $h);
    $white = (int) imagecolorallocate($im, 255, 255, 255);
    $black = (int) imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $white);

    $font = function_exists('food_ticket_np_find_font') ? food_ticket_np_find_font((string) ($cfg['font_file'] ?? '')) : '';
    $text = static function (int $x, int $baseline, int $size, string $label) use ($im, $black, $font): void {
        if ($font !== '' && function_exists('imagettftext')) {
            @imagettftext($im, $size, 0, $x, $baseline, $black, $font, $label);
            return;
        }
        @imagestring($im, 4, $x, max(0, $baseline - 12), $label, $black);
    };

    // نوار سیاه ردیف صفر = «ابتدای کاغذ»
    imagefilledrectangle($im, 0, 0, $w - 1, max(1, $mm(2.0)), $black);
    $text((int) ($w * 0.06), $mm(6.5), 13, 'کارت کالیبراسیون فیش غذا');

    for ($i = 0; $i <= 17; $i++) {
        $yMm = 8.0 + $i * 5.0;
        $yy = $mm($yMm);
        if ($yy >= $h - 8) {
            break;
        }
        $tick = ($i % 2 === 0) ? (int) ($w * 0.20) : (int) ($w * 0.12);
        imagefilledrectangle($im, 0, $yy, $tick, $yy + 2, $black);
        $text((int) ($w * 0.24), $yy + 4, 11, (string) $yMm . ' mm');
    }
    $text((int) ($w * 0.06), $h - $mm(6.0), 10, 'فاصلهٔ لبهٔ کاغذ تا نوار سیاه = head_offset_mm');
    $text((int) ($w * 0.06), $h - $mm(1.5), 10, 'فاصلهٔ آخرین خط تا برش = tail_mm');
    return $im;
}

/**
 * چاپ کارت کالیبراسیون روی چاپگر تنظیم‌شده (شبکه‌ای).
 * @return array{ok:bool,message:string,bytes:int,host:string,port:int}
 */
function food_ticket_np_calibration_print(?array $cfg = null): array
{
    $cfg = $cfg ?? food_ticket_np_settings();
    $target = food_ticket_np_target($cfg);
    if ($target['host'] === '') {
        $message = ($target['source'] ?? '') === 'windows_spooler'
            ? 'روش چاپ روی «صف ویندوز» است و کارت کالیبراسیون فقط با چاپ شبکه (IP) ارسال می‌شود. برای حالت صف ویندوز، مقدار «جابه‌جایی بالای فیش (win_top_shift_mm)» را منفی بگذارید و «چاپ آزمایشی» بگیرید.'
            : 'آدرس چاپگر شبکه‌ای تنظیم نشده است. در «تنظیمات چاپگر» روش «چاپگر شبکه — TCP/RAW» را انتخاب کنید، IP را وارد و ذخیره کنید؛ سپس کارت کالیبراسیون را دوباره بزنید.';
        return ['ok' => false, 'message' => $message, 'bytes' => 0, 'host' => '', 'port' => $target['port'], 'source' => (string) ($target['source'] ?? 'none')];
    }
    $im = food_ticket_np_calibration_image($cfg);
    try {
        if (!empty($cfg['save_preview'])) {
            $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
            @mkdir($root . '/storage/food_ticket_prints', 0750, true);
            @imagepng($im, $root . '/storage/food_ticket_prints/calibration_' . date('Ymd_His') . '.png');
        }
        $payload = food_ticket_np_escpos($im, $cfg);
        food_ticket_np_send($target['host'], $target['port'], $payload, (int) ($cfg['io_timeout'] ?? 10));
        return ['ok' => true, 'message' => 'کارت کالیبراسیون به چاپگر فرستاده شد (' . $target['host'] . ':' . $target['port'] . ').', 'bytes' => strlen($payload), 'host' => $target['host'], 'port' => $target['port'], 'source' => (string) ($target['source'] ?? '')];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'ارسال کارت کالیبراسیون ناموفق بود: ' . $e->getMessage(), 'bytes' => 0, 'host' => $target['host'], 'port' => $target['port'], 'source' => (string) ($target['source'] ?? '')];
    } finally {
        unset($im);
    }
}

/** تصویر → بایت‌های ESC/POS (با پیش‌نمایش اختیاری) */
function food_ticket_np_image_payload(GdImage $im, array $cfg): string
{
    // تضمین: بالای فیش ≤ top_gap_mm و پایین فیش ≤ tail_mm سفید بماند (مستقل از قالب و چاپگر)
    $trim = food_ticket_np_trim_edges($im, $cfg);
    $im = $trim['image'];
    if (!empty($cfg['save_preview'])) {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $dir = $root . '/storage/food_ticket_prints';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @imagepng($im, $dir . '/net_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.png');
    }
    return food_ticket_np_escpos($im, $cfg);
}

/** چاپ فیش یک رویداد روی چاپگر شبکه (قالب کامل). */
function food_ticket_np_send_event(array $event, array $tpl, string $host, int $port): void
{
    $cfg = food_ticket_np_settings($tpl);
    $im = food_ticket_np_render_layout(food_ticket_np_layout($event, $tpl), $tpl, $cfg);
    food_ticket_np_send($host, $port, food_ticket_np_image_payload($im, $cfg), (int) $cfg['io_timeout']);
}

/** چاپ متن ساده (بدون رویداد) روی چاپگر شبکه — به‌صورت تصویر، نه بایت خام. */
function food_ticket_np_send_text(string $text, string $host, int $port): void
{
    $cfg = food_ticket_np_settings();
    food_ticket_np_send($host, $port, food_ticket_np_payload($text, $cfg), (int) $cfg['io_timeout']);
}
