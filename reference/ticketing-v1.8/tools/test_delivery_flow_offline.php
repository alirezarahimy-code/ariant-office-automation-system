<?php
declare(strict_types=1);

/**
 * آزمون سناریوی واقعی «دکمهٔ ثبت تحویل» — شبیه‌سازی دقیق محیط سرور
 *
 * این آزمون، دیتابیس را با SQLite شبیه‌سازی می‌کند: جدول food_ticket_events با ستون‌های
 * چاپ و تحویل، یک رویداد نمونه، و سپس مسیر واقعی سامانه:
 *     food_ticket_api_delivery(5, 'deliver', '', actor)
 * را اجرا می‌کند و انتظار دارد:
 *   ۱) هیچ خطای «ستون وضعیت تحویل ساخته نشده» رخ ندهد
 *   ۲) delivery_status روی «delivered» ثبت شود، delivered_at و delivered_by پر شوند
 *   ۳) درخواست دوم (idempotent) خطا ندهد و رکورد را خراب نکند
 *   ۴) «لغو تحویل» بدون دلیل خطا بدهد و با دلیل انجام شود
 *
 * اجرا: php tools/test_delivery_flow_offline.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$notes = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $notes;
    if ($ok) {
        $pass++;
        echo "  [OK] $label\n";
        return;
    }
    $fail++;
    $notes[] = $label . ($detail !== '' ? ' → ' . $detail : '');
    echo "  [!!] $label" . ($detail !== '' ? " → $detail" : '') . "\n";
}

/* ── ۱) شبیه‌ساز دیتابیس (SQLite درون‌حافظه‌ای) ───────────────────────────── */
/**
 * SQLite توابع MySQL مثل NOW() را ندارد؛ این پوسته فقط برای شبیه‌سازی آزمون،
 * آنها را به معادل SQLite ترجمه می‌کند (کد سامانه دست‌نخورده می‌ماند).
 */
final class MysqlishSqlite extends PDO
{
    private static function translate(string $sql): string
    {
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql);
        $sql = preg_replace('/\bCURDATE\(\)/i', "date('now')", $sql);
        // ALTER های MySQL (همان متن Migration) → معادل SQLite، فقط برای همین آزمون آفلاین
        if (preg_match('/^\s*ALTER TABLE .*\bADD COLUMN\b/is', $sql)) {
            $sql = preg_replace('/\s+AFTER\s+[a-z_]+/is', '', $sql);
            $sql = preg_replace('/\bENUM\s*\([^)]*\)/i', 'TEXT', $sql);
            $sql = preg_replace('/\bTINYINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql);
            $sql = preg_replace('/\bINT\s+UNSIGNED\b/i', 'INTEGER', $sql);
            $sql = preg_replace('/\bDATETIME\b/i', 'TEXT', $sql);
            $sql = str_replace('"', "'", $sql);
        }
        return $sql;
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
$pdo->exec('CREATE TABLE food_ticket_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_key TEXT, print_status TEXT DEFAULT "printed",
    delivery_status TEXT DEFAULT "pending", delivered_at TEXT NULL, delivered_by INTEGER NULL,
    source_deleted INTEGER DEFAULT 0
)');
$pdo->exec("INSERT INTO food_ticket_events (id, ticket_key, print_status) VALUES (5, 'evt:5', 'printed')");


function db(): PDO
{
    global $pdo;
    return $pdo;
}

/** شکل واقعی bootstrap: نقشهٔ کلید = نام ستون */
function db_table_columns(string $table): array
{
    global $pdo;
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(' . preg_replace('/[^a-z_]/i', '', $table) . ')')->fetchAll() as $row) {
        $cols[(string) $row['name']] = true;
    }
    return $cols;
}

/** ثبت لاگ ممیزی برای بررسی */
$GLOBALS['audit_log'] = [];
function activity_log(array $row): void
{
    $GLOBALS['audit_log'][] = $row;
}

/* ── ۲) بارگذاری توابع واقعی از فایل‌های پروژه ───────────────────────────── */
function grab(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    if (!preg_match('/\nfunction\s+' . preg_quote($name, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("تابع $name در $file پیدا نشد");
    }
    $start = (int) $m[0][1] + 1;
    $depth = 0;
    $len = strlen($src);
    $i = strpos($src, '{', $start);
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("انتهای تابع $name پیدا نشد");
}

$runtime = $root . '/food-ticket-runtime.php';
foreach ([
    'food_ticket_table_has_column',
    'food_ticket_delivery_supported',
    'food_ticket_events_ensure_delivery',
    'food_ticket_deliver_event',
    'food_ticket_undeliver_event',
] as $fn) {
    eval(grab($runtime, $fn));
}
eval(grab($root . '/food-ticket-groups.php', 'food_ticket_api_delivery'));

$actor = ['id' => 7, 'username' => 'admin'];

echo "== ۱) دکمهٔ «ثبت تحویل» (سناریوی گزارش‌شدهٔ کاربر) ==\n";
$thrown = '';
try {
    $result = food_ticket_api_delivery(5, 'deliver', '', $actor);
} catch (Throwable $e) {
    $thrown = $e->getMessage();
    $result = [];
}
check('۱-۱: هیچ خطایی («ستون وضعیت تحویل ساخته نشده») رخ نمی‌دهد', $thrown === '', $thrown);
check('۱-۲: پاسخ موفق و status=delivered برمی‌گردد',
    ($result['ok'] ?? false) === true && ($result['delivery_status'] ?? '') === 'delivered', json_encode($result, JSON_UNESCAPED_UNICODE));

$row = db()->query('SELECT delivery_status, delivered_at, delivered_by FROM food_ticket_events WHERE id = 5')->fetch(PDO::FETCH_ASSOC);
check('۱-۳: مقدار delivery_status در دیتابیس «delivered» شد', ($row['delivery_status'] ?? '') === 'delivered', json_encode($row));
check('۱-۴: زمان تحویل ثبت شد', !empty($row['delivered_at']));
check('۱-۵: شناسهٔ تحویل‌دهنده ثبت شد', (int) ($row['delivered_by'] ?? 0) === 7);
check('۱-۶: لاگ ممیزی «تحویل غذا» ثبت شد',
    count(array_filter($GLOBALS['audit_log'], static fn(array $a): bool => ($a['action_code'] ?? '') === 'food_ticket_delivered')) === 1);

echo "\n== ۲) فشار دوباره روی همان دکمه (idempotent) ==\n";
$again = food_ticket_api_delivery(5, 'deliver', '', $actor);
check('۲-۱: دوباره خطا نمی‌دهد', ($again['ok'] ?? false) === true);
check('۲-۲: تغییر جدیدی ثبت نمی‌شود (changed=false)', ($again['changed'] ?? null) === false, json_encode($again, JSON_UNESCAPED_UNICODE));

echo "\n== ۳) لغو تحویل (نیاز به دلیل) ==\n";
try {
    food_ticket_api_delivery(5, 'undeliver', '', $actor);
    $noReason = '';
} catch (Throwable $e) {
    $noReason = $e->getMessage();
}
check('۳-۱: بدون دلیل، خطای روشن می‌دهد', str_contains($noReason, 'دلیل'), $noReason);
$undone = food_ticket_api_delivery(5, 'undeliver', 'اشتباه ثبت شده بود', $actor);
check('۳-۲: با دلیل، لغو تحویل انجام می‌شود', ($undone['ok'] ?? false) === true && ($undone['delivery_status'] ?? '') === 'pending');
$row2 = db()->query('SELECT delivery_status, delivered_at, delivered_by FROM food_ticket_events WHERE id = 5')->fetch(PDO::FETCH_ASSOC);
check('۳-۳: فیلدهای تحویل پاک شدند', $row2['delivery_status'] === 'pending' && $row2['delivered_at'] === null && $row2['delivered_by'] === null, json_encode($row2));

echo "\n== ۴) حالت «ستون واقعاً نیست» (نصب قدیمی بدون Migration) ==\n";
$pdo->exec('DROP TABLE food_ticket_events');
$pdo->exec('CREATE TABLE food_ticket_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_key TEXT, print_status TEXT DEFAULT "printed", source_deleted INTEGER DEFAULT 0
)');
$pdo->exec("INSERT INTO food_ticket_events (id, ticket_key, print_status) VALUES (9, 'evt:9', 'printed')");
food_ticket_delivery_supported(null); // پاک‌کردن کش
// در SQLite امکان ALTER ADD COLUMN هست → سامانه خودش می‌سازد (همان رفتار MySQL با دسترسی ALTER)
try {
    $auto = food_ticket_api_delivery(9, 'deliver', '', $actor);
    $autoErr = '';
} catch (Throwable $e) {
    $autoErr = $e->getMessage();
    $auto = [];
}
check('۴-۱: اگر ستون نبود، خودکار ساخته می‌شود و تحویل ثبت می‌گردد',
    $autoErr === '' && ($auto['ok'] ?? false) === true, $autoErr);
$row3 = db()->query('SELECT delivery_status FROM food_ticket_events WHERE id = 9')->fetch(PDO::FETCH_ASSOC);
check('۴-۲: مقدار روی ردیف ثبت شد', ($row3['delivery_status'] ?? '') === 'delivered', json_encode($row3));

echo "\n" . str_repeat('─', 62) . "\n";
if ($fail === 0) {
    echo "✅ سناریوی تحویل غذا کامل موفق بود ($pass مورد).\n";
    exit(0);
}
echo "❌ $fail مورد ناموفق از " . ($pass + $fail) . " مورد:\n";
foreach ($notes as $note) {
    echo '  • ' . $note . "\n";
}
exit(1);
