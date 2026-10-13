<?php
declare(strict_types=1);

/**
 * میزبان پنل «food-ticket-web/index.html» داخل سامانهٔ PHP (index.php?page=food-ticket).
 *
 * چرا این فایل لازم شد؟
 * پیش‌تر food_ticket_render_page() رشته‌های ثابت index.html را با str_replace
 * جایگزین می‌کرد (مثل href="assets/food-ticket-theme.css?v=3" و
 * <script src="assets/food-brand.js?v=2"></script>). با هر تغییر شمارهٔ نسخه/نام فایل/ترتیب
 * اسکریپت‌ها در نسخه‌های تازهٔ پنل، آن «لنگر»ها پیدا نمی‌شدند و بدون هیچ خطایی:
 *   - اسکریپت بوت‌استرپ (FOOD_TICKET_API_BASE / CSRF) تزریق نمی‌شد → پنل بدون API بالا می‌آمد،
 *   - مسیر فایل‌های assets به food-ticket-web/assets/ بازنویسی نمی‌شد → 404، صفحهٔ سفید/پرش صفحه.
 * توابع این فایل «خالص» (بدون DB/سشن/خروجی) هستند تا در آزمون آفلاین قابل سنجش باشند و
 * هر نسخه‌ای از index.html را بدون تغییر کد PHP پشتیبانی کنند.
 *
 * قرارداد: هیچ رفتار قبلی تغییر نکرده؛ برای index.html فعلی دقیقاً همان خروجی سابق تولید می‌شود.
 * نشانگر window.FOOD_TICKET_HOST_BOOT=1 فقط یک‌بار تزریق می‌شود و برای تشخیص «قبلاً میزبانی شده» است.
 */

/**
 * رشتهٔ بوت‌استرپ (تزریق API base / CSRF / برند / نقش + پل قالب).
 */
function food_ticket_web_host_bootstrap(string $apiBase, string $csrf, array $brand, string $role, bool $canBrowse, string $nonce, ?array $dbState = null): string
{
    $dbBoot = '';
    if (is_array($dbState)) {
        $dbBoot = ';window.FOOD_TICKET_DB_TOKEN=' . json_encode((string) ($dbState['token'] ?? ''), JSON_UNESCAPED_SLASHES)
            . ';window.FOOD_TICKET_DB_FRESH=' . (!empty($dbState['fresh']) ? 'true' : 'false');
    }
    return '<script nonce="' . $nonce . '">window.FOOD_TICKET_HOST_BOOT=1;window.FOOD_TICKET_API_BASE='
        . json_encode($apiBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ';window.FOOD_TICKET_CSRF=' . json_encode($csrf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ';window.FOOD_TICKET_BRAND=' . json_encode([
            'name' => $brand['brand_name'] ?? 'سامانه چاپ فیش غذا',
            'logo' => $brand['brand_logo'] ?? '',
            'returnUrl' => $brand['return_url'] ?? 'index.php',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ';window.FOOD_TICKET_ROLE=' . json_encode($role, JSON_UNESCAPED_UNICODE)
        . ';window.FOOD_TICKET_CAN_BROWSE=' . ($canBrowse ? 'true' : 'false')
        . $dbBoot
        . ';window.FOOD_TICKET_AUTHENTICATED=true;</script>'
        . '<script src="food-ticket-web/template-bridge.js"></script>';
}

/**
 * آماده‌سازی HTML پنل برای میزبانی در PHP.
 *
 * گزینه‌ها:
 *   nonce        رشتهٔ nonce آماده (htmlspecialchars شده) — روی اسکریپت‌های inline گذاشته می‌شود.
 *   bootstrap    رشتهٔ بوت‌استرپ (خروجی food_ticket_web_host_bootstrap).
 *   jalali_boot  اسکریپت تقویم/تعطیلات سامانه.
 *   assets       فهرست نام فایل‌های پوشهٔ food-ticket-web/assets (برای بازنویسی خودکار مسیر).
 *   theme_href   آدرس استایل اصلی سامانه که باید قبل از استایل پنل لینک شود.
 *
 * خروجی: ['html' => ..., 'notes' => [...]] — notes برای error_log/عیب‌یابی.
 */
function food_ticket_web_host_html(string $html, array $opts = []): array
{
    $nonce = (string) ($opts['nonce'] ?? '');
    $bootstrap = (string) ($opts['bootstrap'] ?? '');
    $jalaliBoot = (string) ($opts['jalali_boot'] ?? '');
    $assets = array_values(array_filter(array_map('strval', (array) ($opts['assets'] ?? [])), static fn (string $x): bool => $x !== ''));
    $themeHref = (string) ($opts['theme_href'] ?? 'assets/style.css?v=3');
    $notes = [];

    // ۱) nonce روی اسکریپت‌های inline (بدون src و بدون nonce) — سازگار با هر تعداد/ترتیب.
    $count = 0;
    $html = (string) preg_replace_callback(
        '#<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)(\s[^>]*)?>#i',
        static function (array $m) use ($nonce): string {
            return '<script' . ($m[1] ?? '') . ($nonce !== '' ? ' nonce="' . $nonce . '"' : '') . '>';
        },
        $html,
        -1,
        $count
    );
    if ($count > 0) {
        $notes[] = 'nonce:' . $count;
    }

    // ۲) پنل مستقل (C#) استایل سامانه را با مسیر ../assets/ صدا می‌زند؛ داخل PHP باید assets/ شود.
    $html = (string) preg_replace(
        '#(\b(?:src|href)=)(["\'])(?:\.\./)+assets/style\.css#i',
        '$1$2assets/style.css',
        $html
    );

    // ۳) بازنویسی مسیر دارایی‌های پنل: assets/<name> → food-ticket-web/assets/<name>
    //    (فهرست از خود پوشه خوانده می‌شود، پس هر فایل تازه هم خودکار پوشش داده می‌شود.)
    $rewritten = 0;
    foreach ($assets as $name) {
        $name = basename($name);
        if ($name === '') {
            continue;
        }
        $pattern = '#(\b(?:src|href)=)(["\'])(?:\.\./)?assets/' . preg_quote($name, '#') . '(\?[^"\']*)?\2#i';
        $c = 0;
        $html = (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($name): string {
                return $m[1] . $m[2] . 'food-ticket-web/assets/' . $name . ($m[3] ?? '') . $m[2];
            },
            $html,
            -1,
            $c
        );
        $rewritten += $c;
    }
    if ($rewritten > 0) {
        $notes[] = 'assets:' . $rewritten;
    }

    // ۴) لینک استایل اصلی سامانه پیش از استایل پنل (اگر از قبل نیست).
    if (stripos($html, 'href="assets/style.css') === false) {
        $link = '<link rel="stylesheet" href="' . $themeHref . '">';
        $themePos = stripos($html, 'food-ticket-theme.css');
        if ($themePos !== false && ($linkStart = strrpos(substr($html, 0, $themePos), '<link')) !== false) {
            $html = substr($html, 0, $linkStart) . $link . substr($html, $linkStart);
            $notes[] = 'style:linked';
        } elseif (($headPos = stripos($html, '</head>')) !== false) {
            $html = substr($html, 0, $headPos) . $link . substr($html, $headPos);
            $notes[] = 'style:head';
        }
    }

    // ۵) تزریق بوت‌استرپ + تقویم (یک‌بار، مقاوم به نبود لنگر).
    if ($bootstrap === '' || strpos($html, 'FOOD_TICKET_HOST_BOOT') !== false) {
        $notes[] = 'bootstrap:already';
    } else {
        $inject = $bootstrap;
        // تعطیلات و مبدل تقویم جداگانه بررسی می‌شوند: index.html پنل ممکن است مبدل محلی
        // خودش را داشته باشد، اما همچنان به payload تعطیلات زندهٔ سامانه نیاز دارد.
        if ($jalaliBoot !== '') {
            $jalaliInject = $jalaliBoot;
            $hasCalendarScript = preg_match('#<script\b[^>]*\bsrc="(?:food-ticket-web/)?assets/jalali-calendar\.js[^"]*"[^>]*>\s*</script>#i', $html) === 1;
            $hasHolidayData = strpos($html, 'window.ITSM_HOLIDAYS=') !== false;
            if ($hasCalendarScript) {
                $jalaliInject = (string) preg_replace('#<script\b[^>]*\bsrc="assets/jalali-calendar\.js[^"]*"[^>]*>\s*</script>#i', '', $jalaliInject);
            }
            if ($hasHolidayData) {
                $jalaliInject = (string) preg_replace('#<script\b[^>]*>.*?window\.ITSM_HOLIDAYS=.*?</script>#is', '', $jalaliInject);
            }
            $inject .= $jalaliInject;
        }
        $anchorPos = stripos($html, 'food-brand.js');
        $tagStart = $anchorPos !== false ? strrpos(substr($html, 0, $anchorPos), '<script') : false;
        if ($tagStart !== false) {
            $html = substr($html, 0, $tagStart) . $inject . substr($html, $tagStart);
            $notes[] = 'bootstrap:brand';
        } elseif (($headPos = stripos($html, '</head>')) !== false) {
            $html = substr($html, 0, $headPos) . $inject . substr($html, $headPos);
            $notes[] = 'bootstrap:head';
        } else {
            $html = $inject . $html;
            $notes[] = 'bootstrap:prepend';
        }
    }

    return ['html' => $html, 'notes' => $notes];
}
