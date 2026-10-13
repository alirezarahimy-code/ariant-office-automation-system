<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «تراکنش ثبت تیکت» — رفع خطای «There is no active transaction» (۱.۳۴)
 * ============================================================================
 *   php tools/test_ticket_txn_offline.php
 *
 * ریشهٔ خطا: در MySQL هر DDL (مثل CREATE TABLE IF NOT EXISTS) یک COMMIT ضمنی می‌زند.
 * تابع ticket_number_next() داخل تراکنش ثبت تیکت صدا زده می‌شود و تابع
 * ticket_number_ensure_schema() در هر فراخوانی یک CREATE TABLE IF NOT EXISTS اجرا می‌کرد
 * → تراکنش همان‌جا بسته می‌شد → db()->commit() پایان کار با
 * «There is no active transaction» می‌شکست، در حالی که تیکت ثبت شده بود.
 *
 * این آزمون با یک PDO جعلی بررسی می‌کند:
 *   ۱) وقتی تراکنش باز است، هیچ DDL اجرا نمی‌شود (به shutdown موکول می‌شود)
 *   ۲) وقتی جدول/ستون موجود است، حتی بیرون از تراکنش هم DDL زده نمی‌شود
 *   ۳) بیرون از تراکنش و با نبود جدول، DDL لازم اجرا می‌شود
 *   ۴) db_commit/db_rollback روی تراکنشِ بسته خطا پرت نمی‌کنند (قبلاً Exception می‌داد)
 *   ۵) در کد create_ticket دیگر db()->commit() خام نمانده باشد
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
        echo '[PASS] ' . $label . "\n";
        return;
    }
    $fail++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function grabFunction(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, 'function ' . $name . '(');
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

/* ───────── PDO/MSQL جعلی ───────── */
final class FakeStmt
{
    public function __construct(private array $rows)
    {
    }

    public function fetchAll(int $mode = 0): array
    {
        return $this->rows;
    }
}

final class FakeDb
{
    public bool $inTx = false;
    public bool $tableExists = false;
    public bool $columnExists = true;
    public array $log = [];
    public int $ddlCount = 0;

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function commit(): bool
    {
        $this->log[] = 'commit';
        $this->inTx = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->log[] = 'rollback';
        $this->inTx = false;
        return true;
    }

    public function query(string $sql)
    {
        $this->log[] = 'query:' . $sql;
        if (stripos($sql, 'ticket_number_seq') !== false) {
            if (!$this->tableExists) {
                throw new RuntimeException('Base table or view not found: ticket_number_seq');
            }
            return new FakeStmt([['1' => 1]]);
        }
        if (stripos($sql, 'information_schema') !== false) {
            return new FakeStmt($this->columnExists ? [['ticket_no']] : [['id']]);
        }
        return new FakeStmt([]);
    }

    public function exec(string $sql): int
    {
        $this->log[] = 'exec:' . $sql;
        if (stripos($sql, 'CREATE TABLE') !== false) {
            $this->ddlCount++;
            $this->tableExists = true;
        }
        if (stripos($sql, 'ALTER TABLE') !== false) {
            $this->ddlCount++;
            $this->columnExists = true;
        }
        return 0;
    }
}

$GLOBALS['fakeDb'] = new FakeDb();
function db()
{
    return $GLOBALS['fakeDb'];
}
$GLOBALS['logs'] = [];
function system_log(string $level, string $context, string $message, array $meta = []): void
{
    $GLOBALS['logs'][] = [$level, $context, $message];
}
$GLOBALS['deferred'] = [];
function __test_register_shutdown(callable $cb): void
{
    $GLOBALS['deferred'][] = $cb;
}

/* ───────── بارگذاری توابع تحت آزمون (بدون bootstrap واقعی) ───────── */
$bootstrap = dirname(__DIR__) . '/bootstrap.php';
eval(grabFunction($bootstrap, 'db_in_transaction'));
eval(grabFunction($bootstrap, 'db_commit'));
eval(grabFunction($bootstrap, 'db_rollback'));
$ensureRun = grabFunction($bootstrap, 'ticket_number_ensure_schema_run');
$ensureRun = str_replace('register_shutdown_function(', '__test_register_shutdown(', $ensureRun);
eval($ensureRun);
eval(grabFunction($bootstrap, 'ticket_number_ensure_schema'));

echo "=== آزمون تراکنش تیکت (۱.۳۴) ===\n\n";

/* ───────── ۱) داخل تراکنش: هیچ DDL ───────── */
$GLOBALS['fakeDb'] = new FakeDb();
$GLOBALS['fakeDb']->inTx = true;
$GLOBALS['fakeDb']->tableExists = false; // جدول شماره‌گذاری هنوز ساخته نشده
ticket_number_ensure_schema_run();
check('۱: داخل تراکنش هیچ DDL اجرا نشد', $GLOBALS['fakeDb']->ddlCount === 0, 'ddl=' . $GLOBALS['fakeDb']->ddlCount);
check('۱-ب: اجرای DDL به بعد از تراکنش موکول شد', count($GLOBALS['deferred']) === 1, (string) count($GLOBALS['deferred']));
check('۱-پ: تراکنش دست‌نخورده ماند (commit/rollback نخورد)', !in_array('commit', $GLOBALS['fakeDb']->log, true) && !in_array('rollback', $GLOBALS['fakeDb']->log, true));

/* ───────── ۲) بعد از تراکنش: DDL موکول‌شده انجام می‌شود ───────── */
$GLOBALS['fakeDb']->inTx = false;
foreach ($GLOBALS['deferred'] as $cb) {
    $cb();
}
check('۲: تکلیف موکول‌شده بیرون از تراکنش اجرا شد', $GLOBALS['fakeDb']->tableExists === true && $GLOBALS['fakeDb']->ddlCount >= 1, 'ddl=' . $GLOBALS['fakeDb']->ddlCount);

/* ───────── ۳) جدول و ستون موجود: هیچ DDL (شرط کندی/خطا) ───────── */
$GLOBALS['fakeDb'] = new FakeDb();
$GLOBALS['fakeDb']->tableExists = true;
$GLOBALS['fakeDb']->columnExists = true;
$GLOBALS['fakeDb']->inTx = true;
ticket_number_ensure_schema_run();
check('۳: با وجود جدول و ستون، هیچ DDL اجرا نشد (نه حتی بیرون از تراکنش)', $GLOBALS['fakeDb']->ddlCount === 0, 'ddl=' . $GLOBALS['fakeDb']->ddlCount);
check('۳-ب: لازم هم نشد چیزی به shutdown موکول شود', count($GLOBALS['deferred']) === 1, (string) count($GLOBALS['deferred']));

/* ───────── ۴) بیرون از تراکنش و نبود جدول: DDL ساخته می‌شود ───────── */
$GLOBALS['fakeDb'] = new FakeDb();
$GLOBALS['fakeDb']->tableExists = false;
$GLOBALS['fakeDb']->inTx = false;
ticket_number_ensure_schema_run();
$ddlSql = implode(' | ', array_filter($GLOBALS['fakeDb']->log, static fn (string $l): bool => str_starts_with($l, 'exec:')));
check('۴: بیرون از تراکنش، CREATE TABLE اجرا شد', $GLOBALS['fakeDb']->ddlCount >= 1 && str_contains($ddlSql, 'CREATE TABLE IF NOT EXISTS ticket_number_seq'), $ddlSql);
check('۴-ب: ستون ticket_no موجود بود → ALTER اجرا نشد', !str_contains($ddlSql, 'ALTER TABLE'), $ddlSql);

/* ───────── ۵) db_commit / db_rollback روی تراکنش بسته ───────── */
$GLOBALS['fakeDb'] = new FakeDb();
$GLOBALS['fakeDb']->inTx = false;
$GLOBALS['logs'] = [];
$committed = db_commit('test');
$rolled = db_rollback('test');
check('۵: commit روی تراکنش بسته → false و بدون Exception', $committed === false && $rolled === false);
check('۵-ب: هیچ commit/rollback واقعی صدا زده نشد', !in_array('commit', $GLOBALS['fakeDb']->log, true) && !in_array('rollback', $GLOBALS['fakeDb']->log, true));
check('۵-پ: وضعیت در لاگ سامانه ثبت شد', count($GLOBALS['logs']) === 1 && $GLOBALS['logs'][0][1] === 'transaction', json_encode($GLOBALS['logs'], JSON_UNESCAPED_UNICODE));

/* ───────── ۶) db_commit روی تراکنش باز ───────── */
$GLOBALS['fakeDb'] = new FakeDb();
$GLOBALS['fakeDb']->inTx = true;
check('۶: commit روی تراکنش باز → true و commit واقعی', db_commit('test') === true && in_array('commit', $GLOBALS['fakeDb']->log, true));

/* ───────── ۷) بررسی خود فایل create_ticket ───────── */
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$createStart = strpos($index, "if (\$action === 'create_ticket') {");
// ۱.۳۷.۲: مرز بدنه با «اکشن بعدی» تعیین می‌شود، نه با عدد ثابت — تا افزودن چند خط
// کامنت/اعتبارسنجی در create_ticket این آزمون را بی‌دلیل قرمز نکند.
$createEnd = $createStart === false ? false : strpos($index, "if (\$action === ", (int) $createStart + 20);
$body = $createStart === false
    ? ''
    : substr($index, $createStart, ($createEnd === false ? 20000 : (int) $createEnd - (int) $createStart));
check('۷: در create_ticket هیچ db()->commit() خامی نمانده', strpos($body, 'db()->commit();') === false);
check('۷-ب: create_ticket از db_commit استفاده می‌کند', strpos($body, "db_commit('index.php')") !== false);
check('۷-پ: یک‌بار متغیر $ticketNo ساخته می‌شود و داخل تراکنش پیام خطا نمی‌دهد', substr_count($body, 'ticket_number_next(') === 1, (string) substr_count($body, 'ticket_number_next('));

$bootstrapSrc = (string) file_get_contents($bootstrap);
check('۷-ت: شماره‌گذاری تیکت، DDL خود را با SELECT بررسی می‌کند', strpos($bootstrapSrc, "SELECT 1 FROM ticket_number_seq LIMIT 1") !== false);
check('۷-ث: اسکیمای شماره‌گذاری قبل از هر تراکنش در ابتدای درخواست آماده می‌شود', strpos($index, 'ticket_number_ensure_schema();') !== false);

echo "\n";
echo $fail === 0
    ? 'همهٔ بررسی‌های تراکنش/تیکت موفق بودند (' . $pass . " مورد).\n"
    : $fail . ' مورد ناموفق از ' . ($pass + $fail) . " مورد.\n";
exit($fail === 0 ? 0 : 1);
