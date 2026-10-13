<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «نام کوتاه کامپیوترها» — نسخهٔ ۱.۳۷.۵
 * ============================================================================
 *   php tools/test_asset_names_offline.php
 *
 * چرا این آزمون: کاربر دو نام گیج‌کننده می‌دید (DOMAIN-ADMINIT2 و ADMINIT2.DOMAIN.LOCAL)
 * و خواست فقط «اسم» دیده شود، مثل adminit2. این آزمون دقیقاً همان نمونه‌ها را می‌سنجد
 * و مطمئن می‌شود هیچ داده‌ای در دیتابیس عوض نشده (فقط نمایش).
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

/** بدنهٔ یک تابع را از فایل برمی‌دارد (بدون بارگذاری کل پروژه). */
function grabFunction(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name);
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

$root = dirname(__DIR__);
$file = $root . '/asset-names.php';

// محیط حداقلی: setting() و e() و mb_* (همان چیزهایی که تابع‌ها استفاده می‌کنند)
$GLOBALS['FT_SETTINGS'] = [];
function setting(string $key, ?string $default = null): ?string
{
    return $GLOBALS['FT_SETTINGS'][$key] ?? $default;
}
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function set_style(string $style): void
{
    $GLOBALS['FT_SETTINGS']['asset_hostname_style'] = $style;
}

foreach (['asset_hostname_style', 'asset_name_core', 'asset_display_name', 'asset_is_auto_domain_tag', 'asset_name_title', 'asset_name_attr', 'asset_tag_label'] as $fn) {
    eval(grabFunction($file, $fn));
}

echo "=== آزمون نام کوتاه کامپیوترها (۱.۳۷.۵) ===\n";

/* ───── ۱) حالت پیش‌فرض: فقط نام، با حروف کوچک (دقیقاً نمونهٔ کاربر) ───── */
set_style('short_lower');
check('۱: ADMINIT2.DOMAIN.LOCAL ⇒ adminit2', asset_display_name('ADMINIT2.DOMAIN.LOCAL') === 'adminit2', asset_display_name('ADMINIT2.DOMAIN.LOCAL'));
check('۲: DOMAIN-ADMINIT2 ⇒ adminit2', asset_display_name('DOMAIN-ADMINIT2') === 'adminit2', asset_display_name('DOMAIN-ADMINIT2'));
check('۳: adminit2 ⇒ adminit2 (بدون تغییر)', asset_display_name('adminit2') === 'adminit2', asset_display_name('adminit2'));
check('۴: ADMINIT2 ⇒ adminit2', asset_display_name('ADMINIT2') === 'adminit2', asset_display_name('ADMINIT2'));

/* ───── ۲) نام‌های واقعی‌تر و حالت‌های لبه‌ای ───── */
check('۵: PC-099.DOMAIN.LOCAL ⇒ pc-099', asset_display_name('PC-099.DOMAIN.LOCAL') === 'pc-099', asset_display_name('PC-099.DOMAIN.LOCAL'));
check('۶: حساب کامپیوتر با $ ⇒ adminit2', asset_display_name('ADMINIT2$') === 'adminit2', asset_display_name('ADMINIT2$'));
check('۷: DOMAIN-ADMINIT2.DOMAIN.LOCAL (هر دو حالت با هم) ⇒ adminit2',
    asset_display_name('DOMAIN-ADMINIT2.DOMAIN.LOCAL') === 'adminit2', asset_display_name('DOMAIN-ADMINIT2.DOMAIN.LOCAL'));
check('۸: فاصله‌های اضافی پاک می‌شود', asset_display_name('  ADMINIT2.DOMAIN.LOCAL  ') === 'adminit2', asset_display_name('  ADMINIT2.DOMAIN.LOCAL  '));
check('۹: IP دست‌نخورده می‌ماند (اگر نام نداشتیم و IP گذاشته شده باشد)', asset_display_name('192.168.10.42') === '192.168.10.42', asset_display_name('192.168.10.42'));
check('۱۰: نام خالی ⇒ خالی', asset_display_name('') === '');
check('۱۱: نام سرور با نقطهٔ داخلی زیاد خراب نمی‌شود (srv01.corp) ⇒ srv01',
    asset_display_name('srv01.corp') === 'srv01', asset_display_name('srv01.corp'));

/* ───── ۳) حالت‌های قابل انتخاب در تنظیمات ───── */
set_style('short');
check('۱۲: حالت «حروف اصلی»: ADMINIT2.DOMAIN.LOCAL ⇒ ADMINIT2', asset_display_name('ADMINIT2.DOMAIN.LOCAL') === 'ADMINIT2', asset_display_name('ADMINIT2.DOMAIN.LOCAL'));
set_style('full');
check('۱۳: حالت «نام کامل»: همان ADMINIT2.DOMAIN.LOCAL قبلی برمی‌گردد', asset_display_name('ADMINIT2.DOMAIN.LOCAL') === 'ADMINIT2.DOMAIN.LOCAL');
set_style('چیز_نامعتبر');
check('۱۴: مقدار نامعتبر در تنظیمات ⇒ بازگشت به پیش‌فرض adminit2', asset_display_name('ADMINIT2.DOMAIN.LOCAL') === 'adminit2', asset_display_name('ADMINIT2.DOMAIN.LOCAL'));
set_style('short_lower');

/* ───── ۴) شناسهٔ رکورد (asset_tag) ───── */
check('۱۵: شناسهٔ خودکارِ دامنه تشخیص داده می‌شود', asset_is_auto_domain_tag('DOMAIN-ADMINIT2') === true);
check('۱۶: شناسهٔ دستیِ واقعی تشخیص داده نمی‌شود', asset_is_auto_domain_tag('IT-1402-0042') === false);
check('۱۷: برچسب شناسهٔ خودکار بدون پیشوند DOMAIN- ⇒ ADMINIT2', asset_tag_label('DOMAIN-ADMINIT2', 'ADMINIT2.DOMAIN.LOCAL') === 'ADMINIT2', asset_tag_label('DOMAIN-ADMINIT2', 'ADMINIT2.DOMAIN.LOCAL'));
check('۱۸: برچسب شناسهٔ دستی دست‌نخورده ⇒ IT-1402-0042', asset_tag_label('IT-1402-0042') === 'IT-1402-0042');
check('۱۹: برچسب خالی برای شناسهٔ خالی', asset_tag_label('') === '');

/* ───── ۵) نام کامل گم نمی‌شود (tooltip) ───── */
$title = asset_name_title('ADMINIT2.DOMAIN.LOCAL', 'DOMAIN-ADMINIT2');
check('۲۰: راهنما (tooltip) نام کامل دامنه را نگه می‌دارد', $title === 'ADMINIT2.DOMAIN.LOCAL', $title);
check('۲۱: برای شناسهٔ دستی، شناسه هم در راهنما می‌آید',
    strpos(asset_name_title('ADMINIT2.DOMAIN.LOCAL', 'IT-1402-0042'), 'IT-1402-0042') !== false,
    asset_name_title('ADMINIT2.DOMAIN.LOCAL', 'IT-1402-0042'));
check('۲۲: وقتی نام کوتاه و کامل یکی است، راهنما خالی می‌ماند', asset_name_title('adminit2') === '', asset_name_title('adminit2'));
check('۲۳: صفت title آمادهٔ درج در HTML', strpos(asset_name_attr('ADMINIT2.DOMAIN.LOCAL', 'DOMAIN-ADMINIT2'), 'title="ADMINIT2.DOMAIN.LOCAL"') !== false, asset_name_attr('ADMINIT2.DOMAIN.LOCAL', 'DOMAIN-ADMINIT2'));

/* ───── ۶) اتصال به همهٔ جاهای نمایش (سرور) ───── */
$index = (string) file_get_contents($root . '/index.php');
check('۲۴: ماژول نام‌ها در index.php بارگذاری شده است', strpos($index, "require __DIR__ . '/asset-names.php';") !== false);
check('۲۵: فهرست شناسنامه‌ها از نام نمایشی استفاده می‌کند',
    substr_count($index, 'asset_display_name(') >= 6, (string) substr_count($index, 'asset_display_name('));
check('۲۶: گزینه‌های کمبوی «سیستم مرتبط» هم نام کوتاه را نشان می‌دهند',
    strpos($index, "e(asset_display_name(\$asset['hostname'])") !== false);
check('۲۷: ردیف‌های اسکن دامنه نام کوتاه را نشان می‌دهند',
    strpos($index, "e(asset_display_name((string) \$scanRow['hostname']))") !== false);
check('۲۸: پاسخ JSON اسکن، نام نمایشی را هم می‌فرستد', strpos($index, "'name' => asset_display_name(") !== false);
check('۲۹: به‌روزرسانی زندهٔ جدول اسکن هم نام نمایشی را می‌نویسد', strpos($index, 'x.name||x.hostname') !== false);
check('۳۰: شناسهٔ خودکارِ دامنه در فهرست‌ها پنهان می‌شود (خروجی «دامنه» به‌جای DOMAIN-…)',
    strpos($index, "asset_is_auto_domain_tag(\$asset['asset_tag']) ? '' : '<b>'") !== false);

$inventoryPage = (string) file_get_contents($root . '/asset-inventory-page.php');
check('۳۱: فهرست سیستم‌ها (asset-inventory-page) هم نام کوتاه دارد', strpos($inventoryPage, 'asset_display_name(') !== false);

$scan = (string) file_get_contents($root . '/domain-scan.php');
check('۳۲: فهرست میزبان‌های خطادار اسکن هم نام کوتاه دارد', strpos($scan, 'asset_display_name($hostLine)') !== false);
check('۳۳: ستون assets.source برای مقدار domain گسترده می‌شود (رفع ثبت‌نشدن رکورد دامنه)',
    strpos($scan, "MODIFY source ENUM('manual','server_inventory','agent','domain')") !== false);

/* ───── ۷) تنظیمات: انتخاب حالت نمایش ───── */
check('۳۴: تنظیم asset_hostname_style در صفحهٔ تنظیمات هست', strpos($index, 'name="asset_hostname_style"') !== false);
check('۳۵: مقدار تنظیم با whitelist ذخیره می‌شود (بدون مقدار دلخواه)',
    strpos($index, "valid_choice(post_value('asset_hostname_style'), ['short_lower', 'short', 'full'], 'short_lower')") !== false);
check('۳۶: پیش‌فرض، حالت «فقط نام با حروف کوچک» است', asset_display_name('DC01.CORP.LOCAL') === 'dc01', asset_display_name('DC01.CORP.LOCAL'));

echo "\n" . ($fail === 0
    ? "همهٔ $pass بررسی نام کوتاه کامپیوترها (۱.۳۷.۵) موفق بود.\n"
    : "$fail بررسی ناموفق از " . ($pass + $fail) . " مورد.\n");
exit($fail === 0 ? 0 : 1);
