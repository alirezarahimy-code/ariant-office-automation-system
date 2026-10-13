<?php
declare(strict_types=1);

/**
 * نام نمایشی کامپیوترها — نسخهٔ ۱.۳۷.۵
 * ============================================================================
 * مسئلهٔ کاربر: در فهرست‌ها دو نام طولانی و گیج‌کننده دیده می‌شد:
 *      DOMAIN-ADMINIT2            ← شناسهٔ خودکارِ رکورد (asset_tag)
 *      ADMINIT2.DOMAIN.LOCAL       ← نام کامل دامنه (assets.hostname)
 * خواستهٔ کاربر: برای هر کامپیوتر فقط «اسم» دیده شود، مثال: adminit2
 *
 * قاعده‌ها (بدون هیچ تغییری در دادهٔ دیتابیس — فقط نمایش):
 *   • «ADMINIT2.DOMAIN.LOCAL» → «adminit2»        (بخش دامنه حذف می‌شود)
 *   • «DOMAIN-ADMINIT2»      → «adminit2»        (پیشوند خودکارِ رکورد حذف می‌شود)
 *   • «ADMINIT2$»            → «adminit2»        (پسوند $ حساب کامپیوتر در AD)
 *   • «adminit2»             → «adminit2»        (بدون تغییر)
 *   • IP و هر نام دیگری دست‌نخورده می‌ماند.
 *   • نام کامل دامنه گم نمی‌شود: به‌صورت tooltip (title) روی همان نام می‌ماند و
 *     در «شناسنامه رایانه» هم قابل دیدن است.
 *
 * حالت نمایش با تنظیم «ظاهر نام کامپیوترها» در تنظیمات ← اطلاعات سامانه قابل تغییر است:
 *   short_lower (پیش‌فرض) = adminit2   |   short = ADMINIT2   |   full = ADMINIT2.DOMAIN.LOCAL
 */

/** حالت نمایش نام کامپیوتر: short_lower | short | full */
function asset_hostname_style(): string
{
    $style = (string) setting('asset_hostname_style', 'short_lower');
    return in_array($style, ['short_lower', 'short', 'full'], true) ? $style : 'short_lower';
}

/**
 * هستهٔ نام: حذف بخش دامنه، پیشوند خودکار «DOMAIN-» و پسوند «$» حساب کامپیوتر.
 * هیچ حرفی از نام اصلی حذف نمی‌شود جز این‌ها.
 */
function asset_name_core(string $hostname): string
{
    $name = trim(str_replace(["\u{200C}", "\u{200F}", "\u{200E}"], '', $hostname));
    $name = trim($name, " \t\n\r\0\x0B\"'");
    if ($name === '') {
        return '';
    }
    // آدرس IP یک نام است، نه «نام کامل دامنه» ⇒ دست‌نخورده برمی‌گردد
    if (filter_var($name, FILTER_VALIDATE_IP) !== false || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $name) === 1) {
        return $name;
    }
    // نام کامل دامنه: هرچه بعد از اولین نقطه است حذف می‌شود (ADMINIT2.DOMAIN.LOCAL ⇒ ADMINIT2)
    $dot = strpos($name, '.');
    if ($dot !== false && $dot > 0) {
        $name = substr($name, 0, $dot);
    }
    // پیشوند خودکارِ رکوردهای اسکن دامنه (چند بار، برای اطمینان)
    for ($i = 0; $i < 3; $i++) {
        if (stripos($name, 'DOMAIN-') === 0) {
            $name = substr($name, 7);
        }
    }
    // پسوند $ حساب کامپیوتر در Active Directory (مثل PC1$)
    $name = rtrim($name, '$');
    $name = trim($name);
    return $name;
}

/** نام نمایشی: قاعده + حالت انتخاب‌شده در تنظیمات. */
function asset_display_name(?string $hostname): string
{
    $raw = trim((string) $hostname);
    if ($raw === '') {
        return '';
    }
    $style = asset_hostname_style();
    if ($style === 'full') {
        return $raw;
    }
    $core = asset_name_core($raw);
    if ($core === '') {
        return $raw;
    }
    return $style === 'short_lower' ? (function_exists('mb_strtolower') ? mb_strtolower($core, 'UTF-8') : strtolower($core)) : $core;
}

/**
 * شناسهٔ نمایشی رکورد (asset_tag): اگر شناسه فقط «DOMAIN-<نام>» خودکار است،
 * نمایش آن بی‌فایده و تکراری است ⇒ رشتهٔ خالی برمی‌گردد تا فقط «نام» دیده شود.
 */
function asset_is_auto_domain_tag(?string $tag, ?string $hostname = null): bool
{
    $tag = trim((string) $tag);
    if ($tag === '' || stripos($tag, 'DOMAIN-') !== 0) {
        return false;
    }
    return true;
}

/** متن tooltip: نام کامل (اگر با نام نمایشی یکی نباشد). */
function asset_name_title(?string $hostname, ?string $tag = null): string
{
    $raw = trim((string) $hostname);
    $parts = [];
    if ($raw !== '' && $raw !== asset_display_name($raw)) {
        $parts[] = $raw;
    }
    $tag = trim((string) $tag);
    if ($tag !== '' && !asset_is_auto_domain_tag($tag)) {
        $parts[] = 'شناسه: ' . $tag;
    }
    return implode(' • ', $parts);
}

/** صفت آمادهٔ HTML برای عنوان (title)؛ اگر چیزی برای نشان‌دادن نبود، رشتهٔ خالی. */
function asset_name_attr(?string $hostname, ?string $tag = null): string
{
    $title = asset_name_title($hostname, $tag);
    return $title === '' ? '' : ' title="' . e($title) . '"';
}

/** برچسب کوتاه رکورد برای جاهایی که شناسه لازم است ولی مزاحم نباشد. */
function asset_tag_label(?string $tag, ?string $hostname = null): string
{
    $tag = trim((string) $tag);
    if ($tag === '') {
        return '';
    }
    if (asset_is_auto_domain_tag($tag, $hostname)) {
        // رکورد خودکارِ اسکن دامنه: «DOMAIN-ADMINIT2» ⇒ «ADMINIT2»
        // (ستون «منبع استخراج» در خروجی، همین را «اسکن/دامنه» نشان می‌دهد)
        $core = asset_name_core($tag);
        return $core !== '' ? $core : $tag;
    }
    return $tag;
}
