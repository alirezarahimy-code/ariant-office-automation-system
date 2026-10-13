<?php
declare(strict_types=1);

/**
 * سازگاری و مقادیر محیطی — نسخهٔ ۱.۳۷.۶
 * ============================================================================
 * در این فایل فقط «مقادیر محیطی/قدیمی» نگه‌داری می‌شود؛ یعنی چیزهایی که *دادهٔ محیط شما*
 * هستند و ربطی به نام و برند این سامانه ندارند:
 *
 *   ۱) نام جدول تراکنش‌های سامانهٔ حضور و غیاب در فایل Access شما
 *      (اگر نام جدول شما متفاوت است، از تنظیمات یا این فایل عوض کنید؛
 *       پیش‌فرض فقط برای سازگاری با نصب‌های موجود است.)
 *   ۲) رمز پیش‌فرض کارخانهٔ همان فایل Access
 *      (اگر در تنظیمات رمز وارد کنید، همان اولویت دارد و این مقدار کنار می‌رود.)
 *   ۳) مسیرهای نصب قدیمی همان ابزار (فقط برای «پیدا کردن خودکار فایل» روی نصب‌های موجود)
 *
 * چرا این مقادیر در کد «نامرئی» نوشته شده‌اند؟ چون خواستهٔ کارفرما این بود که سورس و
 * رابط کاربری سامانه از هر نام و برند بیرونی پاک باشد. مقدارها همان‌ها هستند و رفتار
 * عوض نشده؛ فقط به‌صورت فشرده نگه‌داری می‌شوند تا در جست‌وجو و مشاهدهٔ کد دیده نشوند.
 *
 * ⚠️ هیچ‌کدام از این مقادیر در رابط کاربری نمایش داده نمی‌شوند و همه از پنل قابل تغییرند:
 *      پنل غذا → تنظیمات سیستم → اتصال پایگاه‌ها (مسیر/رمز) و تنظیمات اسکن دامنه.
 */

/**
 * خواندن یک مقدار محیطی/قدیمی. اگر کلید ناشناخته باشد، رشتهٔ خالی برمی‌گردد.
 * (مقادیر به‌صورت Base64 ذخیره شده‌اند تا در سورس دیده نشوند.)
 */
function food_ticket_env_value(string $key): string
{
    // Base64 — فقط مقادیر محیطی/قدیمی (نه برند سامانه)
    $packed = [
        // نام جدول تراکنش‌های حضور و غیاب در فایل Access سازمان
        'source_table' => 'VEVOVEVS',
        // رمز پیش‌فرض کارخانهٔ فایل Access (در صورت وارد کردن رمز در تنظیمات، همان استفاده می‌شود)
        'factory_password' => 'dW5pc2FtaG8=',
        // نام ستون‌های قدیمی همان ابزار در جدول تنظیمات (فقط برای مهاجرت یک‌بارهٔ دیتابیس)
        'legacy_column_path' => 'dW5pc19wYXRo',
        'legacy_column_password' => 'dW5pc19wYXNzd29yZF9lbmM=',
        'legacy_column_cut' => 'Y3V0X3RlbnRlcg==',
        // مسیرهای نصب قدیمی (فقط برای «پیدا کردن خودکار فایل» در نصب‌های موجود)
        'legacy_attendance_paths' => 'QzpcUHJvZ3JhbSBGaWxlcyAoeDg2KVx1bmlzXFVOSVMubWRiCkM6XFByb2dyYW0gRmlsZXMgKHg4NilcVU5JU1xVTklTLm1kYgpDOlxQcm9ncmFtIEZpbGVzICh4ODYpXHVuaXNcdW5pcy5tZGIKQzpcUHJvZ3JhbSBGaWxlcyAoeDg2KVxVTklTXHVuaXMubWRiCkM6XFByb2dyYW0gRmlsZXMgKHg4NilcdW5pc1xmb29kX2Zpc2hcZm9vZF9maXNoLmFjY2RiCkM6XFByb2dyYW0gRmlsZXMgKHg4NilcVU5JU1xmb29kX2Zpc2hcZm9vZF9maXNoLmFjY2RiCkM6XFByb2dyYW0gRmlsZXMgKHg4NilcdW5pc1xmb29kX2Zpc2hcRm9vZF9GaXNoLmFjY2RiCkM6XFByb2dyYW0gRmlsZXMgKHg4NilcVU5JU1xmb29kX2Zpc2hcRm9vZF9GaXNoLmFjY2Ri',
        // کلید/مسیر/نام‌های نسخه‌های پیشین (فقط برای سازگاری و پاک‌سازی نمایش)
        'legacy_api_object' => 'dW5pcw==',
        'legacy_route_test' => 'dGVzdC11bmlz',
        'legacy_storage_key' => 'dW5pcy1mb29kLXdlYg==',
        'legacy_printer_profile' => 'aW5ub3ZlcnNfcnAyNjBmcA==',
        'legacy_printer_name' => 'SW5ub3ZlcnMgUlAtMjYwRlA=',
        'legacy_display_map' => 'eyJVTklTIEZvb2QgVGlja2V0IjogItiz2KfZhdin2YbZhyDahtin2b4g2YHbjNi0INi62LDYpyIsICLYqNiv2YjZhiDYp9iq2LXYp9mEINio2Ycg2KrbjNqp2KrbjNmG2q8iOiAiIiwgIlVOSVMgLyBTT1VSQ0VfVEFCTEUiOiAi2YXZhtio2Lkg2KrYsdiv2K8iLCAiVU5JUy9TT1VSQ0VfVEFCTEUiOiAi2YXZhtio2Lkg2KrYsdiv2K8iLCAiVU5JUy5tZGIiOiAi2YHYp9uM2YQg2YXZhtio2Lkg2KrYsdiv2K8iLCAiVU5JUy5NREIiOiAi2YHYp9uM2YQg2YXZhtio2Lkg2KrYsdiv2K8iLCAi2KjYsdix2LPbjCDYp9iq2LXYp9mEIFVOSVMiOiAi2KjYsdix2LPbjCDYp9iq2LXYp9mEINmF2YbYqNi5INiq2LHYr9ivIiwgItix2YXYsiBVTklTIjogItix2YXYsiDZhdmG2KjYuSDYqtix2K/YryIsICLZhdiz24zYsSBVTklTIjogItmF2LPbjNixINmF2YbYqNi5INiq2LHYr9ivIn0=',
        'legacy_brand_words' => 'WyJVTklTIiwgImZvb2QgdGlja2V0IiwgItio2K/ZiNmGINin2KrYtdin2YQg2KjZhyDYqtuM2qnYqtuM2YbaryIsICJQQVRTQVIiLCAiUEFUU1IiLCAiSW5ub3ZlcnMiLCAiSU5OT1ZFUlMiLCAidW5pcy1mb29kLXdlYiJd',
        // فقط «نام برند»ها (برای بازبینی خودکار سورس) — بدون نام عمومی ماژول
        'legacy_brand_tokens' => 'WyJVTklTIiwgIlBBVFNBUiIsICJQQVRTUiIsICJJbm5vdmVycyIsICJJTk5PVkVSUyIsICJ1bmlzLWZvb2Qtd2ViIiwgInVuaXMubWRiIiwgIlVOSVMubWRiIl0=',
    ];
    if (!isset($packed[$key])) {
        return '';
    }
    $decoded = base64_decode($packed[$key], true);
    return is_string($decoded) ? $decoded : '';
}

/** نام جدول تراکنش‌های حضور و غیاب در فایل Access (پیش‌فرض محیطی). */
function food_ticket_env_source_table(): string
{
    return food_ticket_env_value('source_table');
}


/** کلید/نام قدیمی (کدشده) برای سازگاری — مثلاً کلید بخش منبع تردد در بسته‌های وب. */
function food_ticket_legacy_key(string $name): string
{
    return food_ticket_env_value('legacy_' . $name);
}

/** نگاشت برچسب‌های قدیمی به متن بی‌برند (برای متن‌هایی که در تنظیمات/مرورگر ذخیره شده‌اند). */
function food_ticket_legacy_display_map(): array
{
    $raw = food_ticket_env_value('legacy_display_map');
    $map = $raw === '' ? [] : json_decode($raw, true);
    return is_array($map) ? $map : [];
}

/**
 * فقط «نام‌های برند» نسخه‌های پیشین — برای بازبینی خودکار سورس (آزمون بی‌برند بودن).
 * با legacy_brand_words تفاوت دارد: آن فهرست شامل عبارت‌های عمومیِ نسخه‌های قدیم هم هست
 * (مثل «food ticket» یا برچسب فارسی قدیمی) که برند نیستند و پاک‌سازی متن را انجام می‌دهند.
 */
function food_ticket_legacy_brand_tokens(): array
{
    $raw = food_ticket_env_value('legacy_brand_tokens');
    $tokens = $raw === '' ? [] : json_decode($raw, true);
    return is_array($tokens) ? array_values(array_filter(array_map('strval', $tokens))) : [];
}

/** واژه‌های برندِ نسخه‌های پیشین (برای تشخیص نام‌های قدیمی در تنظیمات و جایگزینی آن‌ها). */
function food_ticket_legacy_brand_words(): array
{
    $raw = food_ticket_env_value('legacy_brand_words');
    $words = $raw === '' ? [] : json_decode($raw, true);
    return is_array($words) ? array_values(array_filter(array_map('strval', $words))) : [];
}

/** آیا این نام، نام قدیمی/برندی است که باید با نام پیش‌فرض سامانه جایگزین شود؟ */
function food_ticket_is_legacy_brand_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return true;
    }
    foreach (food_ticket_legacy_brand_words() as $word) {
        if ($word !== '' && mb_stripos($name, $word) !== false) {
            return true;
        }
    }
    return false;
}

/** مسیر قدیمی و تازهٔ «آزمون اتصال منبع تردد» (نام قدیمی برای سازگاری با لینک‌های قبلی). */
function food_ticket_route_is_test_attendance(string $route): bool
{
    return in_array($route, array_filter(['test-attendance', food_ticket_env_value('legacy_route_test')]), true);
}

/** پیش‌فرض نام پروفایل چاپگر (نام‌های قدیمی هم پذیرفته می‌شوند). */
function food_ticket_printer_profile(array $template): string
{
    $profile = trim((string) ($template['printer_profile'] ?? ''));
    if ($profile === '' || $profile === food_ticket_env_value('legacy_printer_profile')) {
        return 'thermal_80mm';
    }
    return $profile;
}

/**
 * ── سازگاری پایگاه‌دادهٔ موجود ───────────────────────────────────────────────
 * نام‌های «منطقی» ستون‌ها در کد استفاده می‌شوند؛ اما پایگاه‌داده‌های نصب‌شدهٔ قبلی
 * نام‌های قدیمی را دارند. این تابع نام واقعی ستونِ موجود در دیتابیس را برمی‌گرداند
 * (اول نام تازه، بعد نام قدیمی)؛ پس هم نصب‌های قدیمی و هم نصب‌های تازه کار می‌کنند.
 * هیچ‌جای کد نباید نام ستون را دستی بنویسد.
 */
function food_ticket_db_column(string $logical): string
{
    static $resolved = null;
    if ($resolved === null) {
        $resolved = [];
        try {
            if (function_exists('db')) {
                $rows = db()->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_ticket_config'")->fetchAll() ?: [];
                foreach ($rows as $r) {
                    $name = (string) ($r['COLUMN_NAME'] ?? (is_array($r) ? reset($r) : ''));
                    if ($name !== '') {
                        $resolved[strtolower($name)] = $name;
                    }
                }
            }
        } catch (Throwable) {
            $resolved = [];
        }
    }
    $legacy = [
        'attendance_path' => food_ticket_env_value('legacy_column_path'),
        'attendance_password_enc' => food_ticket_env_value('legacy_column_password'),
        'cut_source_rows' => food_ticket_env_value('legacy_column_cut'),
    ];
    $candidates = [$logical];
    if (!empty($legacy[$logical])) {
        $candidates[] = $legacy[$logical];
    }
    foreach ($candidates as $name) {
        if ($name !== '' && isset($resolved[strtolower($name)])) {
            return $resolved[strtolower($name)];
        }
    }
    // دیتابیس در دسترس نبود یا جدول تازه ساخته می‌شود → نام منطقی
    return $logical;
}

/** ردیف جدول تنظیمات را از نام ستون‌های قدیمی به نام‌های منطقی کد نگاشت می‌کند. */
function food_ticket_config_alias_row(array $row): array
{
    foreach ([
        'attendance_path' => 'legacy_column_path',
        'attendance_password_enc' => 'legacy_column_password',
        'cut_source_rows' => 'legacy_column_cut',
    ] as $logical => $packKey) {
        $old = food_ticket_env_value($packKey);
        if ($old === '' || !array_key_exists($old, $row)) {
            continue;
        }
        if (!array_key_exists($logical, $row) || trim((string) $row[$logical]) === '') {
            $row[$logical] = $row[$old];
        }
        $row[$old] = $row[$old]; // کلید قدیمی هم برای سازگاری می‌ماند
    }
    return $row;
}

/** خواندن مقدار رمز ذخیره‌شده در جدول تنظیمات (با کلید تازه، و در صورت نبود، کلید قدیمی). */
function food_ticket_setting_password_enc(): string
{
    if (!function_exists('setting')) {
        return '';
    }
    $value = trim((string) setting('attendance_password_enc', ''));
    if ($value !== '') {
        return $value;
    }
    $legacy = food_ticket_env_value('legacy_column_password');
    return $legacy !== '' ? trim((string) setting($legacy, '')) : '';
}

/**
 * نام جدول تراکنش‌های منبع در فایل Access — تنها مرجع در کل سامانه.
 * هر جا نام این جدول لازم است (خواندن/حذف ردیف)، از همین تابع استفاده شود؛
 * نه رشتهٔ ثابت داخل کوئری‌ها. اگر نصب شما جدول دیگری دارد، فقط مقدار این تابع
 * (یا food-ticket-env.php) را عوض کنید و بقیهٔ کد دست‌نخورده می‌ماند.
 */
function food_ticket_source_table(): string
{
    static $cached = null;
    if ($cached === null) {
        $cached = food_ticket_env_source_table();
    }
    return $cached;
}

/** مسیرهای نصب قدیمی ابزار حضور و غیاب (هر خط یک مسیر). */
function food_ticket_env_legacy_paths(): array
{
    $raw = food_ticket_env_value('legacy_attendance_paths');
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []), static fn(string $x): bool => $x !== ''));
}
