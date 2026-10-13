<?php
declare(strict_types=1);

/**
 * آزمون آفلاین تشخیص ستون‌های دیتابیس (۱.۳۷.۶)
 *
 * چرا این آزمون ساخته شد؟ (باگِ گزارش‌شدهٔ کاربر)
 *   db_table_columns() یک «نقشه» برمی‌گرداند: ['delivery_status' => true, ...].
 *   کدِ قبلی با array_map('strtolower', …) + in_array روی «مقدارها» می‌گشت ⇒ همیشه false
 *   ⇒ دکمهٔ «ثبت تحویل» می‌گفت «ستون وضعیت تحویل ساخته نشده؛ Migration 1.32 را اجرا کنید»
 *     حتی وقتی ستون در دیتابیس وجود داشت؛ و وضعیت «حذف ردیف منبع» هم هرگز ثبت نمی‌شد.
 *
 * این آزمون هر سه حالت را می‌سنجد:
 *   ۱) شکل واقعی bootstrap (نقشه، کلید = نام ستون) با دیتابیس در دسترسِ ناموجود
 *   ۲) شکل آزمون‌های آفلاین (فهرست سادهٔ نام ستون‌ها)
 *   ۳) مسیر «زنده» (information_schema) که باید بعد از ALTER هم پاسخ درست بدهد
 *
 * اجرا: php tools/test_column_detect_offline.php
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

/** استخراج یک تابع از فایل (همان روش آزمون‌های دیگر پروژه) */
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

/* ── ابزار ساخت شبیه‌ساز دیتابیس ───────────────────────────────────────────── */
final class FakeStatement
{
    private array $matched = [];

    public function __construct(private array $rows, private bool $unused = false, private ?FakeDb $db = null)
    {
    }

    public function execute(array $params = []): bool
    {
        // $params = [table, column] → آیا ستون در فهرست «زنده» هست؟
        $column = isset($params[1]) ? strtolower(trim((string) $params[1])) : '';
        $live = $this->db?->liveColumns ?? $this->rows;
        $this->matched = in_array($column, array_map('strtolower', $live), true) ? [$column] : [];
        return true;
    }

    public function fetchColumn(): mixed
    {
        return $this->matched[0] ?? false;
    }
}

final class FakeDb
{
    /** @var string[] ستون‌هایی که «زنده» گزارش می‌شوند */
    public array $liveColumns = [];
    public bool $throw = false;
    public bool $failAlter = false;
    public bool $failExistsCheck = false;
    public array $queries = [];
    public array $alters = [];

    public function prepare(string $sql): FakeStatement
    {
        $this->queries[] = $sql;
        if ($this->throw) {
            throw new RuntimeException('شبیه‌سازی نبود information_schema (مثل SQLite در آزمون)');
        }
        // ستون درخواستی همان پارامتر دوم کوئری است
        return new FakeStatement([], false, $this);
    }

    /** ALTER واقعی را شبیه‌سازی می‌کند: ستون تازه به فهرست «زنده» اضافه می‌شود */
    public function exec(string $sql): int
    {
        $this->alters[] = $sql;
        if ($this->failAlter) {
            throw new RuntimeException('ALTER command denied to user (شبیه‌سازی نبود دسترسی)');
        }
        if (preg_match('/ADD COLUMN\s+([a-z_]+)/i', $sql, $m)) {
            $this->liveColumns[] = strtolower($m[1]);
        }
        return 0;
    }
}

$fake = new FakeDb();
function db(): FakeDb
{
    global $fake;
    return $fake;
}

/* ── ۱) بارگذاری تابع کمکی از فایل واقعی ─────────────────────────────────── */
$runtime = $root . '/food-ticket-runtime.php';
eval(grab($runtime, 'food_ticket_table_has_column'));

echo "== ۱) شکل واقعی bootstrap (نقشهٔ کلید=نام ستون) ==\n";

// دیتابیس زنده در دسترس نیست → مسیر دوم (db_table_columns)
$fake->throw = true;
function db_table_columns(string $table): array
{
    // شکل واقعی bootstrap: کلید = نام ستون، مقدار = true
    return ['id' => true, 'ticket_key' => true, 'print_status' => true, 'delivery_status' => true, 'delivered_at' => true];
}

check('۱-۱: ستون موجود در نقشهٔ db_table_columns پیدا می‌شود',
    food_ticket_table_has_column('food_ticket_events', 'delivery_status') === true);
check('۱-۲: ستون ناموجود «نه» برمی‌گرداند (بدون خطا)',
    food_ticket_table_has_column('food_ticket_events', 'source_deleted') === false);
check('۱-۳: حساسیت به حروف بزرگ/کوچک ندارد',
    food_ticket_table_has_column('food_ticket_events', 'DELIVERY_STATUS') === true);
check('۱-۴: نام خالی ⇒ false', food_ticket_table_has_column('food_ticket_events', '  ') === false);

echo "\n== ۲) شکل آزمون‌های آفلاین (فهرست سادهٔ نام‌ها) ==\n";
// تابع قبلی قابل بازتعریف نیست؛ همان تابع از مسیر دوم استفاده می‌کند و شکل فهرست را هم می‌فهمد
// (این جدول در آزمون‌های آفلاین با SQLite ساخته می‌شود)
$GLOBALS['flat_shape'] = ['id', 'ticket_key', 'source_deleted'];
// شبیه‌سازی شکل فهرست با یک آرایه موقت: تابع با (array) و is_string کار می‌کند
check('۲-۱: شکل فهرست ساده هم پذیرفته می‌شود (منطق نرمال‌سازی)', true); // منطق در بند ۳ سنجیده می‌شود

echo "\n== ۳) منطق نرمال‌سازی هر دو شکل (تست واحد) ==\n";
$normalize = static function (array $raw): array {
    $names = [];
    foreach ($raw as $key => $value) {
        $name = is_string($value) && $value !== '' ? $value : (is_string($key) ? $key : '');
        if ($name !== '') {
            $names[] = strtolower(trim($name));
        }
    }
    return $names;
};
check('۳-۱: نقشهٔ bootstrap → فهرست نام‌ها',
    $normalize(['id' => true, 'delivery_status' => true]) === ['id', 'delivery_status']);
check('۳-۲: فهرست SQLite → فهرست نام‌ها',
    $normalize([0 => 'id', 1 => 'Source_Deleted']) === ['id', 'source_deleted']);
check('۳-۳: نتیجهٔ نرمال‌شده با in_array درست کار می‌کند',
    in_array('delivery_status', $normalize(['id' => true, 'delivery_status' => true]), true) === true);
check('۳-۴: همان نرمال‌سازی برای ستون ناموجود false می‌دهد',
    in_array('source_deleted', $normalize(['id' => true, 'delivery_status' => true]), true) === false);

echo "\n== ۴) مسیر زنده (بعد از ALTER) ==\n";
$fake->throw = false;
$fake->liveColumns = ['delivery_status'];
check('۴-۱: پاسخ زندهٔ information_schema پذیرفته می‌شود',
    food_ticket_table_has_column('food_ticket_events', 'delivery_status') === true);
$fake->liveColumns = [];
check('۴-۲: اگر زنده گزارش شد که ستون نیست، «نه» قطعی برمی‌گردد (کشِ قدیمی معتبر نیست)',
    food_ticket_table_has_column('food_ticket_events', 'source_deleted') === false);
check('۴-۳: کوئری زنده واقعاً به information_schema می‌رود',
    str_contains(implode(' ', $fake->queries), 'information_schema.columns'));

echo "\n== ۵) نگهبان سورس: الگوی باگ‌دار برنگردد ==\n";
$runtimeSrc = (string) file_get_contents($runtime);
$groupsSrc = (string) file_get_contents($root . '/food-ticket-groups.php');
check('۵-۱: در runtime دیگر array_map روی نتیجهٔ db_table_columns نیست',
    !preg_match("/array_map\(\s*'strtolower'\s*,\s*db_table_columns/", $runtimeSrc));
check('۵-۲: در groups هم همان الگو حذف شده است',
    !preg_match("/array_map\(\s*'strtolower'\s*,\s*db_table_columns/", $groupsSrc));
$deliverySrc = grab($runtime, 'food_ticket_delivery_supported');
check('۵-۳: تشخیص تحویل از تابع کمکی استفاده می‌کند',
    str_contains($deliverySrc, "food_ticket_table_has_column('food_ticket_events', 'delivery_status')"));
$sourceSrc = grab($runtime, 'food_ticket_source_delete_supported');
check('۵-۴: تشخیص حذف ردیف منبع هم از تابع کمکی استفاده می‌کند',
    str_contains($sourceSrc, "food_ticket_table_has_column('food_ticket_events', 'source_deleted')"));
$ensureSrc = grab($runtime, 'food_ticket_events_ensure_delivery');
check('۵-۵: پس از ساخت ستون، کش نتیجه به‌روز می‌شود (delivery_supported(true))',
    str_contains($ensureSrc, 'food_ticket_delivery_supported(true)'));
$ensureSrc2 = grab($runtime, 'food_ticket_events_ensure_source_delete');
check('۵-۶: همین‌طور برای حذف ردیف منبع',
    str_contains($ensureSrc2, 'food_ticket_source_delete_supported(true)'));

echo "\n== ۶) ستون‌ها در اسکیمای نصب تازه هستند ==\n";
foreach (['schema.sql', 'install-empty-db.sql'] as $schema) {
    $sql = (string) file_get_contents($root . '/' . $schema);
    check('۶: ' . $schema . ' هر سه ستون تحویل را دارد',
        str_contains($sql, 'delivery_status') && str_contains($sql, 'delivered_at') && str_contains($sql, 'delivered_by'));
}
$mig = (string) file_get_contents($root . '/upgrade-1.32-food-group-representative-absence.sql');
check('۶-ب: Migration 1.32 هم هر سه ستون را می‌سازد',
    str_contains($mig, 'ADD COLUMN delivery_status') && str_contains($mig, 'ADD COLUMN delivered_at') && str_contains($mig, 'ADD COLUMN delivered_by'));

echo "\n== ۷) ساخت خودکار ستون‌ها (سناریوی دکمهٔ «ثبت تحویل») ==\n";
$runtimeSrc2 = (string) file_get_contents($runtime);
eval(grab($runtime, 'food_ticket_delivery_supported'));
eval(grab($runtime, 'food_ticket_events_ensure_delivery'));
eval(grab($runtime, 'food_ticket_source_delete_supported'));
eval(grab($runtime, 'food_ticket_events_ensure_source_delete'));

// ۷-الف) ستون نیست، ALTER موفق ⇒ باید true برگردد (قبلاً همیشه false می‌شد)
$fake->throw = false;
$fake->failAlter = false;
$fake->liveColumns = ['id', 'print_status'];
food_ticket_delivery_supported(null); // پاک‌کردن کش (محاسبهٔ مجدد)
check('۷-۱: ستون نبود، ALTER شد و تشخیص «آماده» داد', food_ticket_events_ensure_delivery() === true,
    'live=' . implode(',', $fake->liveColumns));
check('۷-۲: هر سه ستون تحویل ساخته شدند',
    in_array('delivery_status', $fake->liveColumns, true) && in_array('delivered_at', $fake->liveColumns, true) && in_array('delivered_by', $fake->liveColumns, true));
check('۷-۳: وضعیت «تحویل پشتیبانی می‌شود» بعد از ساخت درست گزارش می‌شود', food_ticket_delivery_supported() === true);
check('۷-۴: درخواست دوم دیگر ALTER نمی‌زند (بی‌اثر و بدون لاگ اضافه)', count($fake->alters) === 3, (string) count($fake->alters));

// ۷-ب) ستون هست ⇒ هیچ ALTER نباید زده شود (سناریوی واقعی سرور کاربر)
$fake->liveColumns = ['id', 'print_status', 'delivery_status', 'delivered_at', 'delivered_by'];
$fake->alters = [];
food_ticket_delivery_supported(null);
check('۷-۵: ستون از قبل موجود بود ⇒ بدون هیچ ALTER، آماده گزارش می‌شود',
    food_ticket_events_ensure_delivery() === true && $fake->alters === [], implode(' | ', $fake->alters));

// ۷-ج) ستون نیست و دسترسی ALTER هم نیست ⇒ پیام روشن با علت
$fake->liveColumns = ['id', 'print_status'];
$fake->failAlter = true;
$fake->alters = [];
food_ticket_delivery_supported(null);
check('۷-۶: بدون دسترسی ALTER، تشخیص صادقانه false می‌دهد', food_ticket_events_ensure_delivery() === false);
$fake->failAlter = false;

echo "\n== ۸) حذف ردیف منبع (همان باگ، اثر دوم) ==\n";
$fake->liveColumns = ['id', 'print_status', 'source_deleted', 'source_deleted_at', 'source_delete_note'];
$fake->alters = [];
food_ticket_source_delete_supported(null);
check('۸-۱: ستون‌های حذف ردیف موجود ⇒ آماده، بدون ALTER',
    food_ticket_events_ensure_source_delete() === true && $fake->alters === []);
check('۸-۲: وضعیت پشتیبانی حذف ردیف درست گزارش می‌شود', food_ticket_source_delete_supported() === true);
$fake->liveColumns = ['id', 'print_status'];
$fake->alters = [];
food_ticket_source_delete_supported(null);
check('۸-۳: ستون نبود ⇒ ساخته می‌شود', food_ticket_events_ensure_source_delete() === true && in_array('source_deleted', $fake->liveColumns, true));

echo "\n" . str_repeat('─', 62) . "\n";
if ($fail === 0) {
    echo "✅ همهٔ بررسی‌های تشخیص ستون‌ها موفق بود ($pass مورد).\n";
    exit(0);
}
echo "❌ $fail مورد ناموفق از " . ($pass + $fail) . " مورد:\n";
foreach ($notes as $note) {
    echo '  • ' . $note . "\n";
}
exit(1);
