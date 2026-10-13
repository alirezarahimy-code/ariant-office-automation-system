<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «میزبانی پنل طراحی فیش» (بدون دیتابیس، فقط CLI).
 *
 *   php tools/test_web_host_offline.php
 *
 * چرا؟ شکایت واقعی: «صفحهٔ index.php?page=food-ticket بعد از به‌روزرسانی می‌پرد/سفید می‌شود.»
 * علت ریشه‌ای: food_ticket_render_page() با str_replace روی رشته‌های ثابت index.html
 * تزریق می‌کرد؛ با تغییر شمارهٔ نسخه/نام فایل/ترتیب اسکریپت‌ها در نسخه‌های تازهٔ پنل،
 * تزریق بی‌صدا شکست می‌خورد (بدون API base/CSRF و با ۴۰۴ دارایی‌ها).
 *
 * این آزمون تضمین می‌کند تابع خالص food_ticket_web_host_html() برای همهٔ نسخه‌ها:
 *   - بوت‌استرپ را دقیقاً یک‌بار و در جای درست تزریق می‌کند (و دوباره تزریق نمی‌کند)،
 *   - مسیر دارایی‌های پنل را به food-ticket-web/assets/ بازنویسی می‌کند (و دوباره پیشوند نمی‌زند)،
 *   - استایل اصلی سامانه را پیش از استایل پنل لینک می‌کند،
 *   - nonce را روی اسکریپت‌های inline می‌گذارد.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/food-ticket-web-host.php';

$fail = 0;
$check = static function (string $label, bool $cond, string $detail = '') use (&$fail): void {
    if (!$cond) {
        $fail++;
    }
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

$assets = ['food-brand.js', 'food-designer.js', 'food-groups.js', 'food-ticket-persistence.js', 'food-ticket-theme.css', 'food-order.css', 'food-menu.js', 'food-menu.css', 'food-order-calendar.js', 'jalali-calendar.js'];
$bootstrap = food_ticket_web_host_bootstrap(
    'index.php?page=food-ticket&food_api=',
    'CSRF-TOKEN',
    ['brand_name' => 'سامانه چاپ فیش غذا', 'brand_logo' => '', 'return_url' => 'index.php'],
    'admin',
    true,
    'NONCE123'
);
$jalali = '<script nonce="NONCE123">window.ITSM_HOLIDAYS=[];</script><script src="assets/jalali-calendar.js?v=2"></script>';
$host = static function (string $html) use ($bootstrap, $jalali, $assets): array {
    return food_ticket_web_host_html($html, [
        'nonce' => 'NONCE123',
        'bootstrap' => $bootstrap,
        'jalali_boot' => $jalali,
        'assets' => $assets,
        'theme_href' => 'assets/style.css?v=3',
    ]);
};
$count = static fn (string $hay, string $needle): int => substr_count($hay, $needle);

echo "=== آزمون میزبانی پنل طراحی فیش (بدون دیتابیس) ===\n\n";

// ── ۱) نسخهٔ فعلی روی دیسک: رفتار «قبل» نباید تغییر کند ──
$current = (string) file_get_contents(dirname(__DIR__) . '/food-ticket-web/index.html');
$r1 = $host($current);
$h1 = $r1['html'];
$check('۱-الف: بوت‌استرپ یک‌بار تزریق شد', $count($h1, 'FOOD_TICKET_HOST_BOOT') === 1, 'count=' . $count($h1, 'FOOD_TICKET_HOST_BOOT'));
$check('۱-الف۲: انتساب window.FOOD_TICKET_API_BASE= یک‌بار', $count($h1, 'window.FOOD_TICKET_API_BASE=') === 1, 'count=' . $count($h1, 'window.FOOD_TICKET_API_BASE='));
$check('۱-ب: مسیر از لنگر food-brand.js انجام شد', in_array('bootstrap:brand', $r1['notes'], true), implode(',', $r1['notes']));
$check('۱-پ: food-designer.js به پوشهٔ پنل اشاره می‌کند', strpos($h1, 'src="food-ticket-web/assets/food-designer.js?v=1"') !== false);
$check('۱-ت: مسیر قدیمی assets/food-designer.js باقی نمانده', strpos($h1, 'src="assets/food-designer.js') === false);
$check('۱-ث: دارایی‌های برنامه غذایی هم به پوشهٔ پنل بازنویسی شدند', strpos($h1, 'href="food-ticket-web/assets/food-menu.css?v=8"') !== false && strpos($h1, 'src="food-ticket-web/assets/food-menu.js?v=10"') !== false, implode(',', $r1['notes']));
$check('۱-ج: استایل سامانه (style.css) پیش از استایل پنل', ($p1 = strpos($h1, 'assets/style.css?v=3')) !== false && ($p2 = strpos($h1, 'food-ticket-web/assets/food-ticket-theme.css')) !== false && $p1 < $p2);
$check('۱-چ: nonce روی اسکریپت inline', in_array('nonce:1', $r1['notes'], true) && strpos($h1, '<script nonce="NONCE123">') !== false, implode(',', $r1['notes']));
$check('۱-ح: انتساب تعطیلات سامانه یک‌بار تزریق شد (پنل خودش هم به ITSM_HOLIDAYS اشاره دارد)', $count($h1, 'window.ITSM_HOLIDAYS=') === 1, 'count=' . $count($h1, 'window.ITSM_HOLIDAYS='));
$check('۱-خ: اسکریپت تقویم محلی پنل فقط یک‌بار بارگذاری شد', $count($h1, 'src="food-ticket-web/assets/jalali-calendar.js?v=2"') === 1 && $count($h1, 'src="assets/jalali-calendar.js?v=2"') === 0, 'panel=' . $count($h1, 'src="food-ticket-web/assets/jalali-calendar.js?v=2"') . ' root=' . $count($h1, 'src="assets/jalali-calendar.js?v=2"'));
$check('۱-د: پل قالب (template-bridge) در بوت‌استرپ هست', $count($h1, 'food-ticket-web/template-bridge.js') === 1);

// ── ۲) نسخهٔ تازه‌تر پنل: شمارهٔ نسخه‌ها و نام فایل‌ها عوض شده + دارایی و صفحهٔ جدید ──
$newer = str_replace(
    ['?v=3"', '?v=2"', '?v=1"'],
    ['?v=9"', '?v=8"', '?v=7"'],
    $current
);
$newer = str_replace('<!--BOOT-->', '', $newer);
$newer .= "\n<!-- صفحهٔ طراحی فیلد آزاد --><script src=\"assets/food-designer-extras.js?v=7\"></script><link rel=\"stylesheet\" href=\"assets/food-designer-extras.css?v=7\">\n";
$hostNewer = static function (string $html) use ($bootstrap, $jalali, $assets): array {
    return food_ticket_web_host_html($html, [
        'nonce' => 'NONCE123',
        'bootstrap' => $bootstrap,
        'jalali_boot' => $jalali,
        'assets' => array_merge($assets, ['food-designer-extras.js', 'food-designer-extras.css']),
        'theme_href' => 'assets/style.css?v=3',
    ]);
};
$r2 = $hostNewer($newer);
$h2 = $r2['html'];
$check('۲-الف: با نسخه‌های v=7..v=9 هم بوت‌استرپ تزریق شد', $count($h2, 'FOOD_TICKET_HOST_BOOT') === 1);
$check('۲-ب: فایل تازهٔ پنل هم بازنویسی شد', strpos($h2, 'src="food-ticket-web/assets/food-designer-extras.js?v=7"') !== false);
$check('۲-پ: استایل تازهٔ پنل هم بازنویسی شد', strpos($h2, 'href="food-ticket-web/assets/food-designer-extras.css?v=7"') !== false);
$check('۲-ت: هیچ ارجاع assets/ پنل دست‌نخورده نمانده', strpos($h2, 'src="assets/food-designer') === false && strpos($h2, 'src="assets/food-groups.js') === false);

// ── ۳) نسخه‌ای که لنگر brand را ندارد (نام برند عوض شده) ──
$noBrand = str_replace('<script src="assets/food-brand.js?v=2"></script>', '<script src="assets/ft-panel-boot.js?v=1"></script>', $current);
$r3 = $host($noBrand);
$check('۳-الف: بوت‌استرپ از مسیر جایگزین (head) تزریق شد', in_array('bootstrap:head', $r3['notes'], true), implode(',', $r3['notes']));
$check('۳-ب: بوت‌استرپ یک‌بار و پیش از food-brand باقی‌مانده‌ها', $count($r3['html'], 'FOOD_TICKET_HOST_BOOT') === 1);
$check('۳-پ: بوت‌استرپ داخل head است (قبل از پایان head)', strpos($r3['html'], 'FOOD_TICKET_HOST_BOOT') < strpos($r3['html'], '</head>'));

// ── ۴) خروجی دوباره میزبانی شود → تزریق مضاعف/پیشوند مضاعف نباید رخ دهد ──
$r4 = $host($h1);
$check('۴-الف: تزریق دوباره انجام نشد', in_array('bootstrap:already', $r4['notes'], true), implode(',', $r4['notes']));
$check('۴-ب: هنوز فقط یک بوت‌استرپ', $count($r4['html'], 'FOOD_TICKET_HOST_BOOT') === 1);
$check('۴-پ: پیشوند food-ticket-web دوباره اضافه نشد', strpos($r4['html'], 'food-ticket-web/food-ticket-web/') === false);

// ── ۵) حالت پنل مستقل (C#): مسیر ../assets/style.css باید به assets/style.css برگردد ──
$standalone = '<html><head><link rel="stylesheet" href="../assets/style.css?v=1"><link rel="stylesheet" href="assets/food-ticket-theme.css?v=3"></head><body><script src="assets/food-designer.js?v=1"></script></body></html>';
$r5 = $host($standalone);
$check('۵-الف: مسیر ../assets/style.css اصلاح شد', strpos($r5['html'], 'href="assets/style.css?v=1"') !== false && strpos($r5['html'], '"../assets/style.css') === false);
$check('۵-ب: دارایی پنل بازنویسی شد', strpos($r5['html'], 'src="food-ticket-web/assets/food-designer.js?v=1"') !== false);
$check('۵-پ: بوت‌استرپ حتی بدون food-brand تزریق شد', $count($r5['html'], 'FOOD_TICKET_HOST_BOOT') === 1);

// ── ۶) HTML حداقلی/شکسته: نباید خطا بدهد و باید بوت‌استرپ را بچسباند ──
$r6 = $host('<div>panel</div>');
$check('۶-الف: بدون head هم بوت‌استرپ تزریق شد', in_array('bootstrap:prepend', $r6['notes'], true), implode(',', $r6['notes']));
$check('۶-ب: خروجی شامل محتوای اصلی است', strpos($r6['html'], '<div>panel</div>') !== false);
$r7 = $host('');
$check('۶-پ: ورودی خالی کرش نمی‌کند و بوت‌استرپ اضافه می‌شود', strpos($r7['html'] ?? '', 'FOOD_TICKET_HOST_BOOT') !== false);

echo "\n";
if ($fail === 0) {
    echo "همهٔ بررسی‌ها موفق بودند (آفلاین/بدون دیتابیس).\n";
    exit(0);
}
echo "ناموفق: {$fail}\n";
exit(1);
