<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «مدیریت دسترسی نقش‌ها» — ۱.۳۷.۷
 *
 * قانونی که می‌سنجد:
 *   • تا وقتی نقش دست‌نخورده است ⇒ پیش‌فرض‌های داخل کد اعمال می‌شود
 *     (و تغییر پیش‌فرض‌ها در نسخه‌های بعدی خودکار به آن می‌رسد).
 *   • به‌محض ذخیره از فرم «نقش‌ها و دسترسی‌ها» ⇒ همان مقادیر اولویت دارد،
 *     حتی اگر خالی باشد.
 *   • «بازگردانی به پیش‌فرض کد» نقش را دوباره دست‌نخورده می‌کند.
 *   • نصب‌های قبلی (که دسترسی‌ها در جدول ذخیره شده) به‌درستی «آشتی» می‌شوند:
 *     نقش ویرایش‌شده حفظ می‌شود، نقش دست‌نخورده به پیش‌فرض کد برمی‌گردد.
 *
 * دیتابیس با SQLite درون‌حافظه‌ای شبیه‌سازی می‌شود؛ هیچ MySQL یا تنظیماتی لازم نیست.
 *
 * اجرا:  php tools\test_role_permissions_offline.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این فایل فقط از خط فرمان اجرا می‌شود.');
}

define('APP_ROOT', dirname(__DIR__));

$pass = 0;
$fail = 0;
$lines = [];

function check(string $title, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $lines;
    if ($condition) {
        $pass++;
        $lines[] = '  ✓ ' . $title;
        return;
    }
    $fail++;
    $lines[] = '  ✗ ' . $title . ($detail !== '' ? ' — ' . $detail : '');
}

/* ── ۱) شبیه‌ساز دیتابیس ───────────────────────────────────────────────── */
final class MysqlishSqlite extends PDO
{
    private static function translate(string $sql): string
    {
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", (string) $sql);
        return (string) $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query(self::translate($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::translate($statement));
    }
}

$pdo = new MysqlishSqlite('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE role_permissions (role TEXT NOT NULL, permission TEXT NOT NULL, PRIMARY KEY (role, permission))');
$pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT NULL)');

function db(): PDO
{
    global $pdo;
    return $pdo;
}

/** تنظیمات سادهٔ درون‌حافظه‌ای (به‌جای جدول settings واقعی) */
$GLOBALS['fake_settings'] = [];
function setting(string $key, ?string $default = null): ?string
{
    return $GLOBALS['fake_settings'][$key] ?? $default;
}
function save_setting(string $key, string $value): void
{
    $GLOBALS['fake_settings'][$key] = $value;
}

require APP_ROOT . '/permissions.php';

/** درج دسترسی‌های یک نقش مستقیماً در جدول (شبیه‌سازی نصب قبلی) */
function seedRows(string $role, array $codes): void
{
    db()->prepare('DELETE FROM role_permissions WHERE role = ?')->execute([$role]);
    $insert = db()->prepare('INSERT OR IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
    foreach ($codes as $code) {
        $insert->execute([$role, $code]);
    }
}

function rowsOf(string $role): array
{
    $query = db()->prepare('SELECT permission FROM role_permissions WHERE role = ? ORDER BY permission');
    $query->execute([$role]);
    $rows = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    sort($rows);
    return $rows;
}

function sortedCopy(array $codes): array
{
    $codes = array_values(array_unique(array_map('strval', $codes)));
    sort($codes);
    return $codes;
}

$defaults = permission_defaults();

/* ── ۲) نصب تازه: هیچ ردیفی در جدول نیست ───────────────────────────────── */
$lines[] = '── ۱) نصب تازه (جدول دسترسی خالی) ──';
check('نقش دست‌نخورده ⇐ پیش‌فرض کد', sortedCopy(role_permission_codes('agent')) === sortedCopy($defaults['agent']));
check('نقش دست‌نخورده «ویرایش‌شده» علامت نمی‌خورد', role_permissions_custom('agent') === false);
check('همهٔ نقش‌ها پیش‌فرض کد را می‌گیرند', sortedCopy(role_permission_codes('supervisor')) === sortedCopy($defaults['supervisor']));
check('نقش قدیمی admin کامل است', sortedCopy(role_permission_codes('admin')) === sortedCopy(permission_all_codes()));

/* ── ۳) ذخیره از فرم: اولویت با فرم ─────────────────────────────────────── */
$lines[] = '';
$lines[] = '── ۲) ذخیره از فرم «نقش‌ها و دسترسی‌ها» ──';
$customSet = ['dash.view', 'notif.view', 'ticket.create'];
role_permissions_save('agent', $customSet);
check('مجموعهٔ فرم اولویت گرفت', sortedCopy(role_permission_codes('agent')) === sortedCopy($customSet), implode(',', role_permission_codes('agent')));
check('نقش «ویرایش‌شده» علامت خورد', role_permissions_custom('agent') === true);
check('ردیف‌های جدول هم به‌روز شد', rowsOf('agent') === sortedCopy($customSet));
check('کد نامعتبر نادیده گرفته می‌شود', (function () use ($customSet): bool {
    role_permissions_save('agent', array_merge($customSet, ['no.such.code']));
    return sortedCopy(role_permission_codes('agent')) === sortedCopy(['dash.view', 'notif.view', 'ticket.create']);
})());
check('نقش ناشناس ذخیره نمی‌شود', (function (): bool {
    role_permissions_save('ghost_role', ['dash.view']);
    return role_permissions_custom('ghost_role') === false;
})());

// حتی اگر پیش‌فرض کد عوض شود، نقش ویرایش‌شده دست‌نخورده می‌ماند
seedRows('agent', ['totally.different']);
check('نقش ویرایش‌شده ردیف‌های جدول را نادیده نمی‌گیرد', sortedCopy(role_permission_codes('agent')) === sortedCopy($customSet));

/* ── ۴) ذخیرهٔ خالی عمدی ───────────────────────────────────────────────── */
$lines[] = '';
$lines[] = '── ۳) ذخیرهٔ «هیچ‌کدام» (عمدی) ──';
role_permissions_save('inspector', []);
role_permissions_flush_cache();
check('خالی عمدی خالی می‌ماند', role_permission_codes('inspector') === []);
check('خالی عمدی «ویرایش‌شده» است', role_permissions_custom('inspector') === true);
check('کاربر بازرسی با نقش خالی هیچ دسترسی‌ای ندارد', user_can(['role' => 'inspector'], 'dash.view') === false);

/* ── ۵) ذخیرهٔ برابر با پیش‌فرض ⇒ برگشت به حالت دست‌نخورده ──────────────── */
$lines[] = '';
$lines[] = '── ۴) ذخیرهٔ برابر با پیش‌فرض ──';
role_permissions_save('agent', $defaults['agent']);
check('نقش دوباره دست‌نخورده شد', role_permissions_custom('agent') === false);
seedRows('agent', ['stale.old.code']);
check('پیش‌فرض کد بر ردیف‌های کهنه اولویت دارد', sortedCopy(role_permission_codes('agent')) === sortedCopy($defaults['agent']));

/* ── ۶) بازگردانی به پیش‌فرض ───────────────────────────────────────────── */
$lines[] = '';
$lines[] = '── ۵) «بازگردانی به پیش‌فرض کد» ──';
role_permissions_save('manager', array_slice($defaults['manager'], 0, 3));
check('قبل از بازگردانی: فرم اولویت دارد', count(role_permission_codes('manager')) === 3);
role_permissions_reset('manager');
check('بعد از بازگردانی: پیش‌فرض کد', sortedCopy(role_permission_codes('manager')) === sortedCopy($defaults['manager']));
check('علامت «ویرایش‌شده» پاک شد', role_permissions_custom('manager') === false);
check('ردیف‌های جدول هم پیش‌فرض شد', rowsOf('manager') === sortedCopy($defaults['manager']));

/* ── ۷) آشتی نصب‌های قبلی ──────────────────────────────────────────────── */
$lines[] = '';
$lines[] = '── ۶) نصب قبلی (دسترسی‌ها در جدول ذخیره شده) ──';
$GLOBALS['fake_settings'] = [];
unset($GLOBALS['__role_permission_codes'], $GLOBALS['__role_permission_sets']);
// نقش user: ردیف‌هایش دقیقاً همان پیش‌فرض قدیم است ⇒ باید دست‌نخورده بماند
seedRows('user', $defaults['user']);
// نقش support_manager: یک کد عمداً برداشته شده ⇒ باید ویرایش‌شده علامت بخورد و حفظ شود
$editedSupport = $defaults['support_manager'];
$editedSupport = array_values(array_diff($editedSupport, ['holidays.manage']));
seedRows('support_manager', $editedSupport);
role_permissions_seed(true);
check('نقش دست‌نخورده علامت نخورد', role_permissions_custom('user') === false);
check('نقش ویرایش‌شدهٔ قبلی حفظ شد', role_permissions_custom('support_manager') === true);
check('کد برداشته‌شده برنگشت', !in_array('holidays.manage', role_permission_codes('support_manager'), true));
check('نقش دست‌نخورده به پیش‌فرض کد می‌چسبد', sortedCopy(role_permission_codes('user')) === sortedCopy($defaults['user']));
check('آشتی یک‌بار اجرا می‌شود (نشانه ثبت شد)', setting('role_permissions_reconciled') === '2026-10-07');

/* ── ۸) ادمین اصلی و سازگاری ───────────────────────────────────────────── */
$lines[] = '';
$lines[] = '── ۷) ادمین اصلی و سازگاری ──';
check('ادمین اصلی همهٔ کدها را دارد', count(role_permission_set('primary_admin')) === count(permission_all_codes()));
role_permissions_save('primary_admin', ['dash.view']);
check('ادمین اصلی قابل محدودکردن نیست', count(role_permission_set('primary_admin')) === count(permission_all_codes()));
check('نقش ناشناس ⇐ رفتار کاربر عادی', sortedCopy(role_permission_codes('who_is_this')) === sortedCopy($defaults['user']));
check('کاربر عادی بعد از همه تغییرها سالم است', user_can(['role' => 'user'], 'dash.view') === true && user_can(['role' => 'user'], 'settings.users') === false);

echo implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
echo 'نتیجه: ' . $pass . ' موفق، ' . $fail . ' ناموفق' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
