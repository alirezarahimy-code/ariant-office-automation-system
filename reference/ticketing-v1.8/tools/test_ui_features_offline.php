<?php
declare(strict_types=1);

/** آزمون آفلاین ویژگی‌های لاگ یکپارچه، تم شخصی و مدیریت صفحه ورود. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$css = (string) file_get_contents($root . '/assets/style.css');
$search = (string) file_get_contents($root . '/global-search.php');
$checks = [];
$check = static function(string $name, bool $ok) use (&$checks): void { $checks[] = [$name, $ok]; };

$check('چهار گزینه تم در انتخاب کاربر تعریف شده', substr_count($index, '<option value="current"') >= 1 && substr_count($index, '<option value="ruby"') >= 1 && substr_count($index, '<option value="indigo"') >= 1 && substr_count($index, '<option value="copper"') >= 1);
$check('تم در کلید جداگانه مختص شناسه کاربر ذخیره می‌شود', str_contains($index, "theme_user_'") && str_contains($index, "save_setting('theme_user_"));
$check('تغییر تم فقط گزینه‌های مجاز را می‌پذیرد', str_contains($index, "['current', 'ruby', 'indigo', 'copper']"));
$check('انتخاب شخصی در پروفایل و میانبر سربرگ وجود دارد', str_contains($index, 'id="appearance"') && str_contains($index, '🎨 انتخاب تم'));
$check('تم روی html با data-theme اعمال می‌شود', str_contains($index, 'data-theme="\' . e($theme)'));
$check('CSS برای هر سه تم جداگانه رنگ‌بندی دارد', str_contains($css, 'html[data-theme="ruby"]') && str_contains($css, 'html[data-theme="indigo"]') && str_contains($css, 'html[data-theme="copper"]'));
$check('منوی اصلی نیز با هر تم هماهنگ می‌شود', str_contains($css, 'html[data-theme="ruby"] .navbar') && str_contains($css, 'html[data-theme="indigo"] .navbar') && str_contains($css, 'html[data-theme="copper"] .navbar'));
$check('قواعد تم چیدمان grid را دستکاری نمی‌کنند', !preg_match('/html\[data-theme="(?:ruby|indigo|copper)"\][^\n]*grid-template-columns/', $css));
$check('متن‌های صفحه ورود از تنظیمات خوانده می‌شوند', str_contains($index, "setting('login_title'") && str_contains($index, "setting('login_intro'") && str_contains($index, "setting('login_art_title'"));
$check('پیام متحرک صفحه ورود از تنظیمات خوانده می‌شود', str_contains($index, "setting('login_announcement'") && str_contains($css, '@keyframes login-message-scroll'));
$check('مدیریت صفحه ورود در تنظیمات عمومی قرار دارد', str_contains($index, 'id="login-branding"') && str_contains($index, 'login_branding') && str_contains($index, 'settings.general'));
$check('لوگوی جداگانه ورود با محدودیت نوع/حجم ذخیره می‌شود', str_contains($index, "name=\"login_logo\"") && str_contains($index, "'image/png' => 'png'") && str_contains($index, "'image/jpeg' => 'jpg'") && str_contains($index, '2 * 1024 * 1024'));
$check('ممیزی قدیمی به لاگ یکپارچه هدایت می‌شود', str_contains($index, "if (\$page === 'audit')") && str_contains($index, "redirect('index.php?page=activity-log')"));
$check('گزارش فعالیت و ممیزی فقط یک پیوند منویی دارند', str_contains($index, "'لاگ سامانه'") && !str_contains($index, 'گزارش ممیزی'));
$check('لاگ یکپارچه تبهای دسته‌بندی دارد', str_contains($index, 'همه رویدادها') && str_contains($index, 'ورود و خروج') && str_contains($index, 'مدیریتی و تنظیمات') && str_contains($index, 'خطاها و تلاش‌های ناموفق'));
$check('لاگ صفحه‌بندی‌شده و خروجی اکسل فعالیت‌ها را دارد', str_contains($index, '$perPage = (in_array($category') && str_contains($index, '? 50 : 100') && str_contains($index, 'export_activity_logs'));
$check('جزئیات لاگ ساختار قابل‌خواندن و پوشاندن کلیدهای محرمانه دارند', str_contains($index, 'activity_log_meta_lines') && str_contains($index, '••••••'));
$check('جست‌وجو پیام صریح یافت نشد دارد و دسته‌های خالی را حذف می‌کند', str_contains($index, 'یافت نشد') && str_contains($index, 'if($ticketRows)') && str_contains($index, 'if($assetRows)'));
$check('جست‌وجو سوابق تردد را فقط با مجوز traffic.view بررسی می‌کند', str_contains($index, 'user_can($searchUser') && str_contains($index, 'traffic.view') && str_contains($index, 'FROM traffic_visits v'));
$check('جست‌وجوی CD/DVD از محدوده دسترسی همان نقش پیروی می‌کند', str_contains($index, 'cd_dvd_scope($searchUser') && str_contains($index, 'FROM cd_dvd_records r'));
$check('جست‌وجو فیلدهای قابل‌مشاهده شناسنامه فنی را هم می‌گردد', str_contains($index, 'db_table_columns(\'asset_profiles\')') && str_contains($index, 'p.owner_full_name'));
$check('جست‌وجوی بخش لاگ به مسیر واحد می‌رود', str_contains($search, 'لاگ سامانه (فعالیت و ممیزی)') && !str_contains($search, "'key' => 'audit'"));
$check('برچسب تغییر تم برای لاگ فارسی شده', str_contains($bootstrap, 'user_theme_changed') && str_contains($bootstrap, 'تغییر تم شخصی'));
$check('لاگ‌های فنی قدیمی در تب خطاهای صفحه یکپارچه باز می‌شوند', str_contains($index, 'if ($page === \'logs\')') && str_contains($index, 'redirect(\'index.php?page=activity-log&category=errors\')'));
$check('خطاهای فنی از system_logs با مجوز جداگانه داخل لاگ دیده می‌شوند', str_contains($index, 'system_logs_ensure()') && str_contains($index, 'SELECT s.*, u.username, u.full_name') && str_contains($index, 'user_can($logUser,') && str_contains($index, 'logs.view'));

$failed = 0;
foreach ($checks as [$name, $ok]) { echo ($ok ? '✓ ' : '✗ ') . $name . PHP_EOL; if (!$ok) $failed++; }
echo PHP_EOL . (count($checks)-$failed) . '/' . count($checks) . ' آزمون موفق' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
