<?php
declare(strict_types=1);

/**
 * اجرای واقعی منطق «نمایندهٔ گروه + غیبت روزانه» بدون MySQL — روی SQLite (فقط CLI)
 * =============================================================================
 *   php tools/test_group_flow_offline.php
 *
 * چرا؟ آزمون‌های ۱۵گانهٔ tools/test_food_group_representative.php روی MySQL اجرا می‌شوند و
 * سرور تولید لازم دارند. این فایل با یک PDO شبیه‌سازِ سبک روی SQLite، همان مسیرهای اصلی
 * (ثبت گروه/غیبت، قفل با تردد نماینده، تردد مجدد، L_UID ناشناخته، محاسبهٔ واجد شرایط،
 * API وضعیت غیبت) را واقعاً اجرا می‌کند تا کد آن پیاده‌سازی‌شده پیش از اجرای آزمون‌های
 * سرتاسری، حداقل یک‌بار اجرا شده باشد.
 *
 * محدودیت: جایگزین آزمون‌های MySQL نیست (ENUM/کلید خارجی/تایم‌زون/چاپ واقعی پوشش داده نمی‌شود).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
if (!extension_loaded('sqlite3')) {
    echo "sqlite3 در این PHP فعال نیست؛ آزمون آفلاین رد شد (اجرای آزمون‌های MySQL لازم است).\n";
    exit(0);
}

/* ───────────── شبیه‌ساز کوچک PDO روی SQLite (فقط متدهای استفاده‌شده) ───────────── */

final class OfflinePdoException extends PDOException
{
}

final class OfflineStmt
{
    private ?SQLite3Stmt $stmt = null;
    private ?SQLite3Result $result = null;
    private SQLite3 $db;
    private array $rows = [];
    private int $pointer = -1;
    private bool $materialized = false;
    private int $affected = 0;

    public function __construct(SQLite3 $db, string $sql)
    {
        $this->db = $db;
        $stmt = @$db->prepare($sql);
        if (!$stmt instanceof SQLite3Stmt) {
            throw new OfflinePdoException('prepare failed: ' . $db->lastErrorMsg() . ' | SQL: ' . $sql, 42000);
        }
        $this->stmt = $stmt;
    }

    public function execute(?array $args = null): bool
    {
        $args = $args ?? [];
        foreach (array_values($args) as $i => $v) {
            $type = is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : (is_null($v) ? SQLITE3_NULL : SQLITE3_TEXT));
            $this->stmt->bindValue($i + 1, $v, $type);
        }
        $res = @$this->stmt->execute();
        if ($res === false) {
            $msg = $this->db->lastErrorMsg();
            $code = str_contains($msg, 'UNIQUE') || str_contains($msg, 'constraint') ? 23000 : 42000;
            throw new OfflinePdoException('execute failed: ' . $msg, $code);
        }
        $this->result = $res instanceof SQLite3Result ? $res : null;
        $this->affected = $this->db->changes();
        return true;
    }

    private function materialize(): void
    {
        if ($this->materialized) {
            return;
        }
        $this->rows = [];
        if ($this->result !== null) {
            while (($row = $this->result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $this->rows[] = $row;
            }
        }
        $this->materialized = true;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH): mixed
    {
        $this->materialize();
        $this->pointer++;
        return $this->rows[$this->pointer] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_BOTH): array
    {
        $this->materialize();
        if ($mode === PDO::FETCH_COLUMN) {
            $out = [];
            foreach ($this->rows as $row) {
                $out[] = array_values($row)[0] ?? null;
            }
            return $out;
        }
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $this->materialize();
        $row = $this->rows[0] ?? null;
        if ($row === null) {
            return false;
        }
        $values = array_values($row);
        return $values[$column] ?? false;
    }

    public function rowCount(): int
    {
        return count($this->rows) > 0 ? count($this->rows) : $this->affected;
    }
}

final class OfflinePdo
{
    private SQLite3 $db;

    public function __construct(string $file)
    {
        $this->db = new SQLite3($file);
        $this->db->busyTimeout(2000);
        // توابع MySQL که کد از آن‌ها استفاده می‌کند
        $this->db->createFunction('TIME_TO_SEC', static function ($v): int {
            $parts = explode(':', (string) $v);
            if (count($parts) < 2) {
                return 0;
            }
            return ((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + (int) ($parts[2] ?? 0);
        });
        $this->db->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
    }

    public function exec(string $sql): int|false
    {
        // DDL مخصوص MySQL (CREATE/ALTER/DROP با ENUM/ENGINE/AUTO_INCREMENT) بی‌اثر رد می‌شود؛
        // جدول‌های معادل از پیش در همین فایل ساخته شده‌اند.
        if (preg_match('/^\s*(CREATE|ALTER|DROP|SET)\b/i', $sql)) {
            return 0;
        }
        $ok = $this->db->exec($sql);
        return $ok === false ? false : $this->db->changes();
    }

    public function prepare(string $sql): OfflineStmt
    {
        return new OfflineStmt($this->db, $sql);
    }

    public function query(string $sql): OfflineStmt
    {
        $stmt = new OfflineStmt($this->db, $sql);
        $stmt->execute([]);
        return $stmt;
    }

    public function lastInsertId(): string
    {
        return (string) $this->db->lastInsertRowID();
    }

    public function beginTransaction(): bool
    {
        return $this->db->exec('BEGIN');
    }

    public function commit(): bool
    {
        return $this->db->exec('COMMIT');
    }

    public function rollBack(): bool
    {
        return $this->db->exec('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return false;
    }
}

/* ───────────── محیط شبیه‌سازی‌شده ───────────── */

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['FT_DB'] = null;
$GLOBALS['FT_AUDIT'] = [];
$GLOBALS['FT_ORDERS'] = [];
$GLOBALS['FT_PRINT_QUEUE'] = [];

function db(): OfflinePdo
{
    if ($GLOBALS['FT_DB'] === null) {
        $GLOBALS['FT_DB'] = new OfflinePdo('/tmp/ft_offline_' . getmypid() . '.sqlite');
    }
    return $GLOBALS['FT_DB'];
}

function activity_log(array $data): void
{
    $GLOBALS['FT_AUDIT'][] = $data;
}

function system_log(string $level, string $context, string $message, array $meta = []): void
{
    $GLOBALS['FT_AUDIT'][] = ['action_code' => 'system_log:' . $level, 'context' => $context, 'message' => $message] + $meta;
}

function db_table_columns(string $table): array
{
    $q = db()->query('PRAGMA table_info(' . preg_replace('/[^a-z_]/i', '', $table) . ')');
    $out = [];
    foreach ($q->fetchAll() as $row) {
        $out[] = (string) $row['name'];
    }
    return $out;
}

/* ── جایگزین توابع وابسته (همان قرارداد فایل‌های واقعی) ── */
function food_ticket_normalize_digits(string $value): string
{
    return strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
}
function food_ticket_code(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $clean = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00A0}\s]+/u', '', (string) $value);
    $value = food_ticket_normalize_digits(trim($clean ?? (string) $value));
    if (preg_match('/^\d+\.0+$/', $value, $m)) {
        $value = $m[1];
    }
    if (preg_match('/^\d+$/', $value)) {
        $value = ltrim($value, '0');
        if ($value === '') {
            $value = '0';
        }
    }
    return $value;
}
function food_ticket_digits(mixed $value): string
{
    return preg_replace('/\D+/', '', food_ticket_normalize_digits((string) $value)) ?? '';
}
function food_ticket_card_key(string $card): string
{
    $n = strtoupper((string) preg_replace('/[\s:\-]+/', '', trim($card)));
    if ($n !== '' && preg_match('/^\d+$/', $n)) {
        $n = ltrim($n, '0') ?: '0';
    }
    return $n;
}
function food_ticket_card_equals(string $a, string $b): bool
{
    $a = food_ticket_card_key($a);
    $b = food_ticket_card_key($b);
    return $a !== '' && $b !== '' && $a !== '0' && $b !== '0' && $a === $b;
}
function food_ticket_parse_date(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return null;
}
function food_ticket_parse_time(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('H:i:s');
    }
    if (is_string($value) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
        return strlen($value) === 5 ? $value . ':00' : $value;
    }
    return null;
}
function food_ticket_source_key(array $item): string
{
    return hash('sha256', json_encode([
        (string) ($item['uid'] ?? ''), (string) ($item['card'] ?? ''),
        $item['date_raw'] instanceof DateTimeInterface ? $item['date_raw']->format('Y-m-d H:i:s') : (string) ($item['date_raw'] ?? ''),
        $item['time_raw'] instanceof DateTimeInterface ? $item['time_raw']->format('Y-m-d H:i:s') : (string) ($item['time_raw'] ?? ''),
        $item['row'] ?? [],
    ], JSON_UNESCAPED_UNICODE));
}
function food_ticket_normalize_event_type(mixed $type): string
{
    $type = strtolower(trim((string) $type));
    $allowed = ['printed', 'print_error', 'no_food', 'unknown', 'inactive', 'repeat', 'guest', 'guest_limit', 'config_error'];
    return in_array($type, $allowed, true) ? $type : 'config_error';
}
function food_ticket_normalize_print_status(mixed $status): string
{
    $status = strtolower(trim((string) $status));
    return in_array($status, ['not_printed', 'pending', 'printing', 'printed', 'print_error', 'failed'], true) ? $status : 'not_printed';
}
function food_ticket_add_event(array $event): int
{
    db()->prepare('INSERT INTO food_ticket_events (source_key, source_uid, source_card, punch_date, punch_time, personnel_code, user_id, national_code, full_name, food_type, ticket_key, event_type, print_status, last_error, source_payload, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $event['source_key'], $event['source_uid'] ?? null, $event['source_card'] ?? null,
            $event['punch_date'] ?? null, $event['punch_time'] ?? null, $event['personnel_code'] ?? null,
            $event['user_id'] ?? null, $event['national_code'] ?? null, $event['full_name'] ?? null,
            $event['food_type'] ?? null, $event['ticket_key'] ?? null,
            food_ticket_normalize_event_type($event['event_type'] ?? 'config_error'),
            food_ticket_normalize_print_status($event['print_status'] ?? 'not_printed'),
            $event['last_error'] ?? null, $event['source_payload'] ?? null, date('Y-m-d H:i:s'),
        ]);
    return (int) db()->lastInsertId();
}
function food_ticket_existing_source(string $sourceKey): ?array
{
    $q = db()->prepare('SELECT * FROM food_ticket_events WHERE source_key = ? LIMIT 1');
    $q->execute([$sourceKey]);
    return $q->fetch() ?: null;
}
function food_ticket_existing_ticket(string $ticketKey): ?array
{
    $q = db()->prepare('SELECT * FROM food_ticket_events WHERE ticket_key = ? LIMIT 1');
    $q->execute([$ticketKey]);
    return $q->fetch() ?: null;
}
function food_ticket_config(bool $refresh = false): array
{
    return ['orders_table' => 'food_fish', 'orders_path' => '', 'enabled' => 1, 'cut_source_rows' => 1];
}
function food_ticket_odbc(string $path, ?string $password = null): null
{
    return null;
}
function food_ticket_order_map_lazy($connection, string $table, string $date): array
{
    return $GLOBALS['FT_ORDERS'][$date] ?? [];
}
function food_ticket_template(bool $refresh = false): array
{
    return ['retry_count' => 5, 'retry_delay_seconds' => 30];
}
function food_ticket_enqueue_print(int $eventId, array $config): void
{
    db()->prepare('UPDATE food_ticket_events SET print_status = "pending" WHERE id = ?')->execute([$eventId]);
    $GLOBALS['FT_PRINT_QUEUE'][] = $eventId;
}
function food_ticket_process_print_queue_locked(array $config, int $limit = 30): array
{
    return ['printed' => 0, 'failed' => 0, 'claimed' => 0, 'skipped' => 0];
}
function food_ticket_decide_for_user(array $user, string $date, array $base, array $config, array &$orderMaps, $ordersConnection): array
{
    $nat = food_ticket_digits($user['national_code'] ?? '');
    $food = $orderMaps[$date][$nat] ?? null;
    if ($food === null || $food === '') {
        return ['event_type' => 'no_food', 'print' => false, 'delete' => true, 'payload' => $base + ['event_type' => 'no_food']];
    }
    // «یک فیش در روز برای هر نفر» — همان قاعدهٔ موتور
    if (food_ticket_existing_ticket('food:' . $date . ':' . $nat) !== null) {
        return [
            'event_type' => 'repeat',
            'print' => false,
            'delete' => true,
            'payload' => $base + ['user_id' => (int) $user['id'], 'national_code' => $nat, 'full_name' => $user['full_name'] ?? '', 'event_type' => 'repeat'],
        ];
    }
    return [
        'event_type' => 'printed',
        'print' => true,
        'delete' => true,
        'payload' => $base + [
            'user_id' => (int) $user['id'], 'national_code' => $nat, 'full_name' => $user['full_name'] ?? '',
            'food_type' => $food, 'ticket_key' => 'food:' . $date . ':' . $nat, 'event_type' => 'printed', 'print_status' => 'pending',
        ],
    ];
}
function food_ticket_delivery_supported(): bool
{
    return false; // ستون تحویل در این شبیه‌ساز وجود ندارد؛ آزمون تحویل روی MySQL انجام می‌شود.
}
function user_can(array $user, string $permission): bool
{
    return false;
}

require dirname(__DIR__) . '/food-ticket-groups.php';

/* ───────────── ساخت جدول‌های معادل ───────────── */
$schema = [
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, full_name TEXT, employee_number TEXT, national_code TEXT, is_active INTEGER DEFAULT 1)',
    'CREATE TABLE food_ticket_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, l_uid TEXT, rfid_card TEXT, description TEXT, status TEXT DEFAULT "active", created_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(l_uid), UNIQUE(rfid_card))',
    'CREATE TABLE food_ticket_group_members (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, user_id INTEGER, created_at TEXT, UNIQUE(user_id))',
    'CREATE TABLE food_ticket_group_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, group_title TEXT, rfid_card TEXT DEFAULT "", trigger_kind TEXT, trigger_uid TEXT, source_key TEXT UNIQUE, punch_date TEXT, punch_time TEXT, run_kind TEXT, status TEXT, members_total INTEGER DEFAULT 0, printed_count INTEGER DEFAULT 0, repeat_count INTEGER DEFAULT 0, no_food_count INTEGER DEFAULT 0, absent_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0, error_count INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE food_ticket_daily_absence (id INTEGER PRIMARY KEY AUTOINCREMENT, absence_date TEXT, group_id INTEGER, user_id INTEGER, reason TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(absence_date, user_id))',
    'CREATE TABLE food_ticket_group_uid_history (id INTEGER PRIMARY KEY AUTOINCREMENT, uid TEXT, group_id INTEGER, action TEXT, actor_id INTEGER, created_at TEXT)',
    'CREATE TABLE food_ticket_events (id INTEGER PRIMARY KEY AUTOINCREMENT, source_key TEXT, source_uid TEXT, source_card TEXT, punch_date TEXT, punch_time TEXT, personnel_code TEXT, user_id INTEGER, national_code TEXT, full_name TEXT, food_type TEXT, ticket_key TEXT UNIQUE, event_type TEXT, print_status TEXT, delivery_status TEXT DEFAULT "pending", delivered_at TEXT, delivered_by INTEGER, print_job_id TEXT, printer_name TEXT, print_attempts INTEGER DEFAULT 0, retry_count INTEGER DEFAULT 0, last_error TEXT, next_retry_at TEXT, source_payload TEXT, processed_at TEXT, updated_at TEXT)',
    'CREATE TABLE food_ticket_guest_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_uid TEXT, guest_name TEXT)',
    'CREATE TABLE food_ticket_card_map (card_key TEXT PRIMARY KEY, user_id INTEGER)',
];
foreach ($schema as $ddl) {
    try {
        $GLOBALS['FT_DB'] = new OfflinePdo('/tmp/ft_offline_' . getmypid() . '.sqlite');
        db()->exec('PRAGMA foreign_keys = ON');
        // exec() در این شبیه‌ساز DDL را رد می‌کند؛ برای ساخت جدول از SQLite3 مستقیم استفاده می‌کنیم.
        $raw = new SQLite3('/tmp/ft_offline_' . getmypid() . '.sqlite');
        $raw->exec($ddl);
        $raw->close();
    } catch (Throwable $e) {
        echo 'DB init: ' . $e->getMessage() . "\n";
    }
}

$fail = 0;
$check = static function (string $label, bool $cond, string $detail = '') use (&$fail): void {
    if (!$cond) {
        $fail++;
    }
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

$tz = new DateTimeZone('Asia/Tehran');
$now = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$tomorrow = $now->modify('+1 day')->format('Y-m-d');

$mkUser = static function (string $name, string $nat): int {
    db()->prepare('INSERT INTO users (username, full_name, employee_number, national_code, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([strtolower(preg_replace('/\s+/', '', $name)), $name, 'EMP' . random_int(1000, 9999), $nat]);
    return (int) db()->lastInsertId();
};
$admin = ['id' => 0, 'role' => 'support_manager'];
$normal = ['id' => 0, 'role' => 'user'];

$users = [
    'ali' => $mkUser('علی تست', '1111111111'),
    'reza' => $mkUser('رضا تست', '2222222222'),
    'sara' => $mkUser('سارا تست', '3333333333'),
    'nima' => $mkUser('نیما تست', '4444444444'),
];
$GLOBALS['FT_ORDERS'][$today] = [
    '1111111111' => 'چلوکباب',
    '2222222222' => 'قیمه',
    '3333333333' => 'زرشک‌پلو',
];

echo "=== اجرای واقعی منطق گروهی روی SQLite (آفلاین) ===\n\n";

// ۱) ساخت گروه با L_UID
$gid = food_ticket_api_save_group([
    'title' => 'گروه تست ۱', 'l_uid' => '00908', 'active' => true,
    'members' => array_values($users),
], 0);
$check('۱: گروه با L_UID ساخته شد', $gid > 0, 'id=' . $gid);
$row = db()->prepare('SELECT l_uid, rfid_card FROM food_ticket_groups WHERE id = ?');
$row->execute([$gid]);
$g = $row->fetch();
$check('۲: L_UID ذخیره شد و کارت خالی ماند', (string) $g['l_uid'] === '908' && ($g['rfid_card'] === null || $g['rfid_card'] === ''), (string) $g['l_uid'] . '/' . var_export($g['rfid_card'], true));

// ۳) شناسایی با L_UID (با نرمال‌سازی صفر ابتدایی)
$found = food_ticket_group_find_by_uid('908');
$check('۳: L_UID → گروه', is_array($found) && (int) $found['id'] === $gid);
$check('۳-ب: L_UID ناشناخته → null', food_ticket_group_find_by_uid('777123') === null);

// ۴) رد L_UID تکراری
$dup = false;
try {
    food_ticket_api_save_group(['title' => 'گروه تکراری', 'l_uid' => '908', 'members' => []], 0);
} catch (Throwable $e) {
    $dup = true;
}
$check('۴: L_UID تکراری رد شد', $dup);

// ۵) رد عضویت تکراری در دو گروه
$dup2 = false;
try {
    food_ticket_api_save_group(['title' => 'گروه دوم', 'l_uid' => '909', 'members' => [$users['ali']]], 0);
} catch (Throwable $e) {
    $dup2 = true;
}
$check('۵: عضویت هم‌زمان در دو گروه رد شد', $dup2);

// ۶) غیبت روزانه (Exception) + قفل‌نبودن قبل از تردد
food_ticket_absence_set($gid, (int) $users['reza'], $today, true, 'مأموریت', $admin);
$abs = food_ticket_absence_load($today, $gid);
$check('۶-الف: غیبت ثبت شد (فقط یک رکورد)', count($abs) === 1 && isset($abs[(int) $users['reza']]));
$check('۶-ب: قفل نیست', food_ticket_group_absence_locked($gid, $today) === false);
$overview = food_ticket_group_overview($gid, $today);
$check('۶-پ: خلاصه: ۴ عضو / ۱ غایب / ۳ حاضر', $overview['total'] === 4 && $overview['absent'] === 1 && $overview['present'] === 3,
    'total=' . $overview['total'] . ' absent=' . $overview['absent'] . ' present=' . $overview['present']);
$check('۶-ت: واجد شرایط = ۲ (رضا غایب است، نیما سفارش ندارد)', $overview['eligible'] === 2, 'eligible=' . var_export($overview['eligible'], true));
$check('۶-ث: نیما بدون سفارش است (no_order)', $overview['no_order'] === 1, 'no_order=' . var_export($overview['no_order'], true));

// ۷) تردد نماینده (L_UID) → فیش اعضای واجد شرایط
$config = food_ticket_config();
$config['_group_skip_print'] = true;
$maps = [$today => $GLOBALS['FT_ORDERS'][$today]];
$summary = ['processed' => 0, 'queued' => 0, 'errors' => 0];
$item = ['uid' => '000908', 'card' => '', 'date_raw' => $now->setTime(11, 30), 'time_raw' => $now->setTime(11, 30), 'row' => ['L_UID' => '908', 'n' => 1]];
$res = food_ticket_group_try_process($item, $config, $maps, null, $summary);
$groupEventIds = array_values(array_unique(array_map('intval', $res['event_ids'] ?? [])));
$check('تردد گروهی شناسهٔ همهٔ رویدادهای اعضا را برمی‌گرداند', count($groupEventIds) === 3);
$doneAgain = food_ticket_group_try_process($item, $config, $maps, null, $summary);
$doneEventIds = array_values(array_unique(array_map('intval', $doneAgain['event_ids'] ?? [])));
$check('اجرای تکراری همان ردیف نیز شناسه‌های قبلی را برای اصلاح وضعیت برمی‌گرداند', count($doneEventIds) === 3);
$check('۷-الف: اجرای اول انجام شد', ($res['kind'] ?? '') === 'first', json_encode($res, JSON_UNESCAPED_UNICODE));
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_events WHERE punch_date = ? AND event_type = "printed"');
$q->execute([$today]);
$printedCount = (int) $q->fetchColumn();
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_events WHERE punch_date = ?');
$q->execute([$today]);
$allEvents = (int) $q->fetchColumn();
$check('۷-ب: ۲ فیش صادر شد و غایب‌ها هیچ رویدادی نگرفتند', $printedCount === 2 && $allEvents === 3, 'printed=' . $printedCount . ' all=' . $allEvents);
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_group_runs WHERE group_id = ? AND run_kind = "first"');
$q->execute([$gid]);
$check('۷-پ: رکورد اجرا با نوع first ثبت شد', (int) $q->fetchColumn() === 1);
q2: {
    $q2 = db()->prepare('SELECT trigger_kind, trigger_uid, absent_count, printed_count FROM food_ticket_group_runs WHERE group_id = ? ORDER BY id DESC LIMIT 1');
    $q2->execute([$gid]);
    $run = $q2->fetch();
    $check('۷-ت: ماشه با L_UID ثبت شد و غایب‌ها شمرده شدند', (string) $run['trigger_kind'] === 'uid' && (string) $run['trigger_uid'] === '908' && (int) $run['absent_count'] === 1 && (int) $run['printed_count'] === 2,
        json_encode($run, JSON_UNESCAPED_UNICODE));
}

// ۸) قفل بعد از تردد + رد ویرایش کاربر عادی + اجازهٔ ادمین با Audit
$check('۸-الف: بعد از تردد نماینده قفل شد', food_ticket_group_absence_locked($gid, $today) === true);
$rejected = false;
try {
    food_ticket_absence_set($gid, (int) $users['sara'], $today, true, 'تلاش عادی', $normal);
} catch (Throwable $e) {
    $rejected = true;
}
$check('۸-ب: کاربر عادی بعد از قفل رد شد', $rejected);
$adminOk = true;
try {
    food_ticket_absence_set($gid, (int) $users['sara'], $today, true, 'اصلاح مدیریت', $admin);
} catch (Throwable $e) {
    $adminOk = false;
    echo '   خطا: ' . $e->getMessage() . "\n";
}
$check('۸-پ: ادمین با دلیل اصلاح کرد', $adminOk && count(food_ticket_absence_load($today, $gid)) === 2);
$codes = array_column($GLOBALS['FT_AUDIT'], 'action_code');
$check('۸-ت: Audit قفل + اصلاح ثبت شد', in_array('food_group_absence_frozen', $codes, true) && in_array('food_absence_override', $codes, true), implode(',', array_slice($codes, 0, 8)));

// ۹) تردد مجدد همان نماینده → تکرار، بدون فیش جدید
$summary = ['processed' => 0];
$item2 = ['uid' => '908', 'card' => '', 'date_raw' => $now->setTime(12, 10), 'time_raw' => $now->setTime(12, 10), 'row' => ['L_UID' => '908', 'n' => 2]];
$res2 = food_ticket_group_try_process($item2, $config, $maps, null, $summary);
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_events WHERE punch_date = ? AND event_type = "printed"');
$q->execute([$today]);
$printedAfterRepeat = (int) $q->fetchColumn();
$check('۹-الف: تردد دوم = repeat', ($res2['kind'] ?? '') === 'repeat', json_encode($res2, JSON_UNESCAPED_UNICODE));
$check('۹-ب: تعداد فیش تغییر نکرد (۲ چاپ‌شده)', $printedAfterRepeat === 2, 'printed=' . $printedAfterRepeat);
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_group_runs WHERE group_id = ?');
$q->execute([$gid]);
$check('۹-پ: خلاصهٔ تکرار هم ثبت شد (۲ رکورد اجرا)', (int) $q->fetchColumn() === 2);

// ۱۰) کشیدن دوبارهٔ همان ردیف (≤۴ ثانیه) → نادیده
$dupItem = ['uid' => '908', 'card' => '', 'date_raw' => $now->setTime(12, 10, 2), 'time_raw' => $now->setTime(12, 10, 2), 'row' => ['L_UID' => '908', 'n' => 77]];
$summary = [];
$res3 = food_ticket_group_try_process($dupItem, $config, $maps, null, $summary);
$check('۱۰: ردیف تکراری SOURCE_TABLE نادیده گرفته شد', ($res3['kind'] ?? '') === 'duplicate', json_encode($res3, JSON_UNESCAPED_UNICODE));

// ۱۱) استقلال تاریخ‌ها: فردا غیبتی ندارد
$overviewTomorrow = food_ticket_group_overview($gid, $tomorrow);
$check('۱۱: غیبت امروز روی فردا اثر ندارد', $overviewTomorrow['absent'] === 0 && count(food_ticket_absence_load($tomorrow, $gid)) === 0);

// ۱۲) API وضعیت غیبت (داخل فرم گروه‌ها)
$status = food_ticket_api_absence_status($gid, $today, $admin);
$check('۱۲-الف: API غیبت، اعضا و خلاصه را می‌دهد', count($status['members']) === 4 && (int) $status['summary']['absent'] === 2, json_encode($status['summary'], JSON_UNESCAPED_UNICODE));
$check('۱۲-ب: قفل در API گزارش شد', $status['locked'] === true && $status['editable'] === true && str_contains((string) $status['lock_label'], 'قفل'));
$statusNormal = food_ticket_api_absence_status($gid, $today, $normal);
$check('۱۲-پ: کاربر عادی editable=false می‌بیند', $statusNormal['editable'] === false);

// ۱۳) ذخیرهٔ گروهی غیبت از طریق API
$saved = food_ticket_api_absence_save([
    'group_id' => $gid, 'date' => $today,
    'absent' => [$users['nima']], 'present' => [$users['sara']], 'reason' => 'اصلاح از فرم',
], $admin);
$check('۱۳: API ذخیرهٔ غیبت درست عمل کرد', (int) $saved['summary']['absent'] === 2 && (int) $saved['summary']['present'] === 2, json_encode($saved['summary'], JSON_UNESCAPED_UNICODE));

// ۱۴) حذف همهٔ غیبت‌ها (همه حاضر)
$cleared = food_ticket_api_absence_clear($gid, $today, 'اصلاح گروهی', $admin);
$check('۱۴: «همه حاضر» کار کرد', (int) $cleared['summary']['absent'] === 0);

// ۱۵) تردد با L_UID ناشناخته (سابقهٔ نمایندگی دارد)
food_ticket_group_uid_history_record('955123', $gid, 'removed', 0);
$summary = [];
$itemUnknown = ['uid' => '955123', 'card' => '', 'date_raw' => $now->setTime(13, 0), 'time_raw' => $now->setTime(13, 0), 'row' => ['L_UID' => '955123', 'n' => 9]];
$resUnknown = food_ticket_group_try_process($itemUnknown, $config, $maps, null, $summary);
$check('۱۵-الف: تردد با L_UID ناشناخته شناسایی شد', ($resUnknown['kind'] ?? '') === 'unknown_uid', json_encode($resUnknown, JSON_UNESCAPED_UNICODE));
$q = db()->prepare('SELECT event_type, print_status FROM food_ticket_events WHERE ticket_key = ?');
$q->execute(['unknown:' . $today . ':' . '955123']);
$unknownEvent = $q->fetch();
$check('۱۵-ب: رویداد ناشناخته با چاپ‌نشده ثبت شد', is_array($unknownEvent) && (string) $unknownEvent['event_type'] === 'unknown' && (string) $unknownEvent['print_status'] === 'not_printed', json_encode($unknownEvent));
$codes = array_column($GLOBALS['FT_AUDIT'], 'action_code');
$check('۱۵-پ: Audit تردد ناشناخته ثبت شد', in_array('food_group_unknown_uid', $codes, true));
$s15 = [];
$check('۱۵-ت: تردد کاربر عادی (بدون سابقهٔ نمایندگی) دست‌نخورده ماند', food_ticket_group_try_unknown(['uid' => '666666', 'card' => '', 'date_raw' => $now, 'time_raw' => $now, 'row' => []], $s15) === null);

// ۱۵-۵) آمار نگهبانی: غایب‌کردن بعد از صدور فیش → توقف خودکار چاپ، و برگشت با «حاضر»
$heldUser = (int) $users['ali'];   // عضوی که فیش امروزش در صف چاپ است
$q = db()->prepare('SELECT id, ticket_key, print_status FROM food_ticket_events WHERE user_id = ? AND punch_date = ?');
$q->execute([$heldUser, $today]);
$heldEvent = $q->fetch();
$check('۱۵-۵-الف: فیش عضو گروه برای آزمون موجود است', is_array($heldEvent), json_encode($heldEvent, JSON_UNESCAPED_UNICODE));
db()->prepare('UPDATE food_ticket_events SET print_status = "pending" WHERE id = ?')->execute([(int) $heldEvent['id']]);

$apiHold = food_ticket_api_absence_save([
    'group_id' => $gid, 'date' => $today,
    'absent' => [$heldUser], 'present' => [], 'reason' => 'آمار نگهبانی: نیامده',
], $admin);
$q = db()->prepare('SELECT print_status FROM food_ticket_events WHERE id = ?');
$q->execute([(int) $heldEvent['id']]);
$statusAfterHold = (string) $q->fetchColumn();
$check('۱۵-۵-ب: غایب‌کردن، فیش در صف چاپ را متوقف کرد', $statusAfterHold === 'held_absent', 'status=' . $statusAfterHold);
$check('۱۵-۵-پ: API تعداد فیش‌های متوقف‌شده را گزارش می‌کند', (int) ($apiHold['tickets_held'] ?? 0) === 1, json_encode(['held' => $apiHold['tickets_held'] ?? null]));
$check('۱۵-۵-ت: شمارش «فیش متوقف‌شده» در وضعیت غیبت هست', (int) ($apiHold['held_tickets'] ?? 0) === 1, 'held_tickets=' . (int) ($apiHold['held_tickets'] ?? 0));
$codesNow = array_column($GLOBALS['FT_AUDIT'], 'action_code');
$check('۱۵-۵-ث: Audit توقف فیش ثبت شد', in_array('food_ticket_ticket_held', $codesNow, true));

// فیش متوقف‌شده در فهرست چاپ مجدد خطاها نیست (فقط pending/print_error/failed)
$q = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE id = ? AND print_status IN ("print_error","failed")');
$q->execute([(int) $heldEvent['id']]);
$check('۱۵-۵-ج: فیش متوقف در صف چاپ مجدد قرار نمی‌گیرد', (int) $q->fetchColumn() === 0);

// «حاضر» شدن دوباره → برگشت به صف چاپ
$apiRelease = food_ticket_api_absence_save([
    'group_id' => $gid, 'date' => $today,
    'absent' => [], 'present' => [$heldUser], 'reason' => 'اصلاح آمار نگهبانی',
], $admin);
$q = db()->prepare('SELECT print_status FROM food_ticket_events WHERE id = ?');
$q->execute([(int) $heldEvent['id']]);
$statusAfterRelease = (string) $q->fetchColumn();
$check('۱۵-۵-چ: برگشت به صف چاپ با «حاضر» شدن', $statusAfterRelease === 'pending', 'status=' . $statusAfterRelease);
$check('۱۵-۵-ح: API تعداد فیش‌های برگشتی را گزارش می‌کند', (int) ($apiRelease['tickets_released'] ?? 0) === 1, 'released=' . (int) ($apiRelease['tickets_released'] ?? 0));
$codesNow = array_column($GLOBALS['FT_AUDIT'], 'action_code');
$check('۱۵-۵-خ: Audit بازگشت فیش ثبت شد', in_array('food_ticket_ticket_released', $codesNow, true));

// فیش چاپ‌شده هرگز با غیبت تغییر نمی‌کند
db()->prepare('UPDATE food_ticket_events SET print_status = "printed" WHERE id = ?')->execute([(int) $heldEvent['id']]);
food_ticket_hold_ticket_for_absence($heldUser, $today, $admin, 'تست');
$q = db()->prepare('SELECT print_status FROM food_ticket_events WHERE id = ?');
$q->execute([(int) $heldEvent['id']]);
$check('۱۵-۵-د: فیش چاپ‌شده با اعلام غیبت دست‌نخورده می‌ماند', (string) $q->fetchColumn() === 'printed');

// ۱۶) گروه غیرفعال → هیچ فیش جدیدی
$gid2 = food_ticket_api_save_group(['title' => 'گروه غیرفعال', 'l_uid' => '911', 'active' => false, 'members' => []], 0);
$summary = [];
$item3 = ['uid' => '911', 'card' => '', 'date_raw' => $now->setTime(13, 30), 'time_raw' => $now->setTime(13, 30), 'row' => ['L_UID' => '911', 'n' => 3]];
$resInactive = food_ticket_group_try_process($item3, $config, $maps, null, $summary);
$check('۱۶: گروه غیرفعال → فقط خلاصه، بدون فیش', ($resInactive['kind'] ?? '') === 'inactive' && (int) ($summary['queued'] ?? 0) === 0);

// ۱۷) کسی که امروز از مسیر عادی فیش گرفته، از مسیر گروهی فیش دوم نمی‌گیرد
$minaId = $mkUser('مینا تست', '5555555555');
$GLOBALS['FT_ORDERS'][$today]['5555555555'] = 'کباب کوبیده';
// فیش فردی امروز (مثل تردد عادی همان کارمند)
food_ticket_add_event([
    'source_key' => hash('sha256', 'normal-punch-mina'),
    'punch_date' => $today, 'punch_time' => '09:00:00', 'personnel_code' => 'EMP5555',
    'user_id' => $minaId, 'national_code' => '5555555555', 'full_name' => 'مینا تست',
    'food_type' => 'کباب کوبیده', 'ticket_key' => 'food:' . $today . ':5555555555',
    'event_type' => 'printed', 'print_status' => 'printed',
]);
$gid3 = food_ticket_api_save_group(['title' => 'گروه سوم', 'l_uid' => '912', 'members' => [$minaId]], 0);
$summary = ['processed' => 0, 'repeat' => 0];
$item4 = ['uid' => '912', 'card' => '', 'date_raw' => $now->setTime(14, 0), 'time_raw' => $now->setTime(14, 0), 'row' => ['L_UID' => '912', 'n' => 4]];
$maps17 = [$today => $GLOBALS['FT_ORDERS'][$today]];
$res4 = food_ticket_group_try_process($item4, $config, $maps17, null, $summary);
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_events WHERE user_id = ? AND event_type = "printed"');
$q->execute([$minaId]);
$minaPrinted = (int) $q->fetchColumn();
$q = db()->prepare('SELECT COUNT(*) c FROM food_ticket_events WHERE user_id = ? AND event_type = "repeat"');
$q->execute([$minaId]);
$minaRepeat = (int) $q->fetchColumn();
$check('۱۷: عضو دارای فیش امروز، از مسیر گروهی فیش دوم نمی‌گیرد (رویداد repeat ثبت می‌شود)',
    ($res4['kind'] ?? '') === 'first' && $minaPrinted === 1 && $minaRepeat === 1 && (int) ($summary['repeat'] ?? 0) >= 1,
    'printed=' . $minaPrinted . ' repeat=' . $minaRepeat . ' summary=' . json_encode($summary, JSON_UNESCAPED_UNICODE));

// ۱۸) دسترسی‌ها/پاک‌سازی داده
$list = food_ticket_api_groups($today);
$check('۱۸: خروجی مدیریت گروه‌ها شامل L_UID و وضعیت امروز است', count($list['groups']) === 3 && isset($list['groups'][0]['today']['locked']),
    'groups=' . count($list['groups']) . ' runs=' . count($list['runs']));

echo "\n" . ($fail === 0 ? "همهٔ بررسی‌ها موفق بودند (آفلاین/SQLite).\n" : ($fail . " بررسی ناموفق بود.\n"));
@unlink('/tmp/ft_offline_' . getmypid() . '.sqlite');
exit($fail === 0 ? 0 : 1);
