<?php
declare(strict_types=1);

/**
 * جست‌وجوی سراسری — فهرست بخش‌ها و صفحه‌های سامانه (۱.۳۷.۷)
 *
 * چرا این فایل؟ پیش‌تر جست‌وجوی سراسری فقط در «تیکت، دارایی و دانش‌نامه» می‌گشت؛
 * اگر کاربر «تردد» را می‌نوشت، صفحهٔ تردد — که با همین کلمه در منو دیده می‌شود — پیدا نمی‌شد.
 * اکنون هر صفحه/بخش سامانه با عنوان و «کلمات کلیدی فارسی» فهرست شده است و جست‌وجو
 * بر پایهٔ همین فهرست، صفحه‌های مرتبط را نشان می‌دهد (فقط صفحه‌هایی که کاربر اجازهٔ دیدنشان را دارد).
 *
 * توابع این فایل خالص‌اند (بدون دیتابیس و بدون نشست) تا آفلاین قابل آزمون باشند:
 *   php tools\test_global_search_offline.php
 */

/** نرمال‌سازی متن فارسی/عربی برای جست‌وجوی مطمئن. */
function global_search_normalize(string $text): string
{
    $text = str_replace(
        ["\u{200C}", "\u{200E}", "\u{200F}", "\u{064A}", "\u{0643}", "\u{0649}", "\u{0629}", "\u{0622}", "\u{0623}", "\u{0625}", 'ٔ', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'],
        ['', '', '', 'ی', 'ک', 'ی', 'ه', 'ا', 'ا', 'ا', '', '', '', '', '', '', '', '', '', ''],
        $text
    );
    // ارقام فارسی و عربی ⇒ لاتین (تا «۱» و «1» یکسان دیده شوند)
    $text = str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/**
 * فهرست بخش‌های سامانه برای جست‌وجو.
 *
 * هر ردیف: کلید یکتا، عنوان نمایشی، نشانی، کلمات کلیدی فارسی، و قاعدهٔ دسترسی:
 *   'perm'    => یک کد دسترسی (مثل queue.view)
 *   'perms'   => هر یک از این کدها کافی است
 *   'special' => قاعدهٔ ویژه: food (فهرست food.*) یا governance (کارمند + governance.manage)
 *   (بدون هیچ‌کدام ⇒ هر کاربر وارد‌شده‌ای)
 */
function global_search_catalog(): array
{
    return [
        ['key' => 'dashboard', 'title' => 'داشبورد', 'url' => 'index.php', 'keywords' => ['خانه', 'صفحه اصلی', 'خلاصه وضعیت', 'کارتابل من', 'home', 'داشبورد']],
        ['key' => 'new-ticket', 'title' => 'ثبت تیکت جدید', 'url' => 'index.php?page=new-ticket', 'keywords' => ['تیکت جدید', 'درخواست جدید', 'ثبت درخواست', 'پشتیبانی', 'خدمات کامپیوتری', 'تیکت']],
        ['key' => 'queue', 'title' => 'صف کاری (کارتابل تیکت‌ها)', 'url' => 'index.php?page=queue', 'keywords' => ['تیکت', 'کارتابل', 'صف', 'وظایف من', 'رسیدگی'], 'perm' => 'queue.view'],
        ['key' => 'notifications', 'title' => 'اعلان‌ها', 'url' => 'index.php?page=notifications', 'keywords' => ['اعلان', 'پیام', 'اطلاعیه', 'زنگوله']],
        ['key' => 'profile', 'title' => 'پروفایل من', 'url' => 'index.php?page=profile', 'keywords' => ['حساب کاربری', 'مشخصات من', 'عکس پروفایل', 'کد ملی']],
        ['key' => 'search', 'title' => 'جست‌وجوی سراسری', 'url' => 'index.php?page=search', 'keywords' => ['جستجو', 'جست‌وجو', 'search', 'پیدا کردن']],
        ['key' => 'reports', 'title' => 'گزارش تیکت‌ها', 'url' => 'index.php?page=reports', 'keywords' => ['گزارش', 'آمار تیکت', 'خروجی اکسل', 'عملکرد'], 'perm' => 'reports.view'],
        ['key' => 'analytics', 'title' => 'چارت‌ها و نمودارها', 'url' => 'index.php?page=analytics', 'keywords' => ['نمودار', 'چارت', 'آمار', 'تحلیل'], 'perm' => 'analytics.view'],
        ['key' => 'supervisor', 'title' => 'پنل سوپروایزر', 'url' => 'index.php?page=supervisor', 'keywords' => ['تأیید نهایی', 'بستن تیکت', 'سوپروایزر', 'بازگشایی', 'SLA', 'نظارت'], 'perm' => 'supervisor.panel'],
        ['key' => 'services', 'title' => 'خدمات و دسته‌بندی خدمات', 'url' => 'index.php?page=services', 'keywords' => ['کاتالوگ خدمات', 'دسته خدمات', 'فیلدهای خدمت', 'فرم خدمت'], 'perm' => 'services.view'],
        ['key' => 'knowledge', 'title' => 'دانش‌نامه', 'url' => 'index.php?page=knowledge', 'keywords' => ['راهنما', 'مقالات', 'حل مشکل', 'آموزش', 'FAQ', 'پرسش‌های متداول'], 'perm' => 'knowledge.view'],
        ['key' => 'assets', 'title' => 'شناسنامه‌های فنی (کامپیوترها)', 'url' => 'index.php?page=assets', 'keywords' => ['شناسنامه', 'کامپیوتر', 'سیستم', 'دارایی', 'تجهیزات', 'سخت‌افزار', 'asset'], 'perm' => 'assets.view'],
        ['key' => 'asset', 'title' => 'جزئیات شناسنامهٔ فنی', 'url' => 'index.php?page=assets', 'keywords' => ['شناسنامه فنی', 'مشخصات سیستم', 'کارت شبکه', 'رم', 'هارد', 'ویندوز'], 'perm' => 'assets.view'],
        ['key' => 'inventory', 'title' => 'Inventory (فهرست سیستم‌ها)', 'url' => 'index.php?page=inventory', 'keywords' => ['انبار', 'فهرست سیستم', 'Inventory', 'وضعیت سیستم‌ها'], 'perm' => 'inventory.view'],
        ['key' => 'inventory-diagnostics', 'title' => 'تشخیص و عیب‌یابی Inventory', 'url' => 'index.php?page=inventory-diagnostics', 'keywords' => ['عیب‌یابی', 'خطای استخراج', 'تشخیص', 'اشکال‌زدایی', 'اسکن'], 'perm' => 'inventory.diagnostics'],
        ['key' => 'domain-scan', 'title' => 'اسکن دامنه و استخراج اطلاعات سیستم‌ها', 'url' => 'index.php?page=domain-scan', 'keywords' => ['اسکن', 'دامنه', 'استخراج', 'AD', 'اکتیو دایرکتوری', 'وایرال', 'WinRM', 'WMI', 'DCOM'], 'perm' => 'domain.scan'],
        ['key' => 'cd-dvd', 'title' => 'کنترل CD/DVD', 'url' => 'index.php?page=cd-dvd', 'keywords' => ['CD', 'DVD', 'رسانه', 'ورود رسانه', 'خروج رسانه', 'تحویل رسانه'], 'perm' => 'cddvd.view'],
        ['key' => 'cd-dvd-history', 'title' => 'تاریخچهٔ CD/DVD', 'url' => 'index.php?page=cd-dvd-history', 'keywords' => ['سابقه رسانه', 'تاریخچه CD', 'گزارش رسانه'], 'perm' => 'cddvd.history'],
        ['key' => 'traffic-control', 'title' => 'کنترل تردد مراجعین و میهمانان', 'url' => 'index.php?page=traffic-control', 'keywords' => ['تردد', 'مراجعین', 'میهمان', 'مهمان', 'ورود و خروج', 'پذیرش', 'ویزیتور', 'گیت', 'ورودیه', 'کارت مراجع'], 'perm' => 'traffic.view'],
        ['key' => 'traffic-control-print', 'title' => 'چاپ برگ تردد مراجعین', 'url' => 'index.php?page=traffic-control-print', 'keywords' => ['چاپ تردد', 'برگ تردد', 'چاپ مراجع', 'پرینت میهمان', 'کارت ویزیتور'], 'perm' => 'traffic.view'],
        ['key' => 'food-ticket', 'title' => 'چاپ فیش غذا', 'url' => 'index.php?page=food-ticket', 'keywords' => ['فیش', 'غذا', 'ناهار', 'صبحانه', 'شام', 'رستوران', 'کارت غذا', 'سفارش غذا', 'تحویل غذا', 'چاپگر فیش'], 'special' => 'food'],
        ['key' => 'settings', 'title' => 'تنظیمات سامانه', 'url' => 'index.php?page=settings', 'keywords' => ['تنظیمات', 'پیکربندی', 'ادمین', 'دامین', 'LDAP', 'کاربران', 'نقش‌ها', 'دسترسی‌ها', 'لوگو', 'پشتیبان‌گیری'], 'perms' => ['settings.general', 'settings.domain', 'settings.users', 'settings.key_roles']],
        ['key' => 'organization', 'title' => 'چارت سازمانی و واحدها', 'url' => 'index.php?page=organization', 'keywords' => ['چارت', 'واحد', 'معاونت', 'سازمان', 'دپارتمان', 'ساختار'], 'perm' => 'org.view'],
        ['key' => 'holidays', 'title' => 'تعطیلات و مناسبت‌ها', 'url' => 'index.php?page=holidays', 'keywords' => ['تعطیلات', 'تقویم', 'مناسبت', 'روزهای تعطیل', 'مرخصی رسمی'], 'perm' => 'holidays.manage'],
        ['key' => 'governance', 'title' => 'مدیریت تغییر و مشکل', 'url' => 'governance.php', 'keywords' => ['تغییر', 'مشکل', 'CAB', 'Change', 'Problem', 'کمیته تغییر', 'ریسک'], 'special' => 'governance'],
        ['key' => 'ola', 'title' => 'تنظیمات OLA و SLA', 'url' => 'ola.php', 'keywords' => ['OLA', 'SLA', 'توافق سطح خدمت', 'زمان پاسخ', 'زمان حل', 'Escalation', 'ارتقا'], 'perm' => 'ola.manage'],
        ['key' => 'activity-log', 'title' => 'لاگ سامانه (فعالیت و ممیزی)', 'url' => 'index.php?page=activity-log', 'keywords' => ['فعالیت کاربران', 'ممیزی', 'لاگ', 'رویداد', 'ردیابی کاربر', 'گزارش عملیات', 'آی‌پی', 'نام کامپیوتر', 'activity', 'audit', 'ورود و خروج'], 'perms' => ['logs.activity', 'audit.view']],
        ['key' => 'logs', 'title' => 'خطاهای فنی در لاگ سامانه', 'url' => 'index.php?page=activity-log&category=errors', 'keywords' => ['خطا', 'error', 'لاگ سیستم', 'اشکال', 'سطح هشدار'], 'perms' => ['logs.view', 'logs.activity', 'audit.view']],
        ['key' => 'backup', 'title' => 'پشتیبان‌گیری و بازیابی', 'url' => 'index.php?page=backup', 'keywords' => ['بکاپ', 'پشتیبان', 'بازیابی', 'backup', 'restore'], 'perm' => 'backup.manage'],
    ];
}

/**
 * بخش‌های منطبق با عبارت جست‌وجو (به ترتیب میزان تطابق).
 *
 * @param callable(array):bool $allowed تعیین می‌کند کاربر اجازهٔ دیدن آن بخش را دارد یا نه.
 * @return array<int, array<string, mixed>>
 */
function global_search_sections(array $catalog, string $term, callable $allowed): array
{
    $normalized = global_search_normalize($term);
    if ($normalized === '') {
        return [];
    }
    $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));
    if ($words === []) {
        return [];
    }
    $hits = [];
    foreach ($catalog as $section) {
        if (!is_array($section) || !$allowed($section)) {
            continue;
        }
        $title = global_search_normalize((string) ($section['title'] ?? ''));
        $extra = global_search_normalize(implode(' ', array_map('strval', (array) ($section['keywords'] ?? []))) . ' ' . (string) ($section['url'] ?? '') . ' ' . (string) ($section['key'] ?? ''));
        $score = 0;
        $matched = true;
        foreach ($words as $word) {
            $inTitle = mb_strpos($title, $word) !== false;
            if ($inTitle) {
                $score += 2;
                continue;
            }
            if (mb_strpos($extra, $word) !== false) {
                $score += 1;
                continue;
            }
            $matched = false;
            break;
        }
        if ($matched) {
            $hits[] = ['score' => $score, 'order' => count($hits), 'section' => $section];
        }
    }
    usort($hits, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'] ?: $a['order'] <=> $b['order'];
    });
    return array_values(array_map(static fn (array $hit): array => (array) $hit['section'], $hits));
}
