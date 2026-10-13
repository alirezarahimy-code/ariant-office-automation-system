<?php
declare(strict_types=1);

/**
 * ایمپورت سفارش‌های غذا از فایل (خروجی جدول Access یا هر فایل Excel/CSV) به جدول داخلی food_orders.
 * ============================================================================
 * ستون‌های لازم (نام ستون‌ها نسبت به حروف و جداکننده‌ها بی‌حساس است):
 *   cod_meli            → کد ملی کارمند (کلید تطبیق با users.national_code)
 *   nahar_entekhabi     → نام غذا (باید دقیقاً با نام غذای «برنامهٔ غذایی» همان روز یکی باشد)
 *   tarikh_entekhabi    → تاریخ غذا (شمسی ۱۴۰۵/۰۷/۱۸ یا میلادی ۲۰۲۶-۱۰-۱۰)
 * ستون‌های دیگر (naam، famili، d، s، roz_entekhabi و …) نادیده گرفته می‌شوند.
 *
 * قواعد:
 *  - فایل ابتدا فقط «بررسی» می‌شود؛ هیچ‌چیزی ذخیره نمی‌شود تا تأیید کنید.
 *  - سفارش فقط وقتی ثبت می‌شود که: کارمند فعال با آن کد ملی باشد، برای آن روز برنامهٔ غذایی
 *    ثبت شده باشد، و غذا در همان روز در برنامه فعال باشد.
 *  - اگر کارمند برای آن روز سفارش فعال داشته باشد: همان غذا → «قبلاً ثبت شده» (رد می‌شود)،
 *    غذای دیگر → «تعارض» (رد می‌شود و دست‌نخورده می‌ماند).
 *  - سفارش لغوشده دوباره فعال می‌شود.
 *  - هر ردیف در food_order_logs با action = order_import ثبت می‌شود.
 */

function food_order_import_column_keys(): array
{
    // نام‌های ستون بعد از food_order_import_key() (حروف کوچک، بدون فاصله/زیرخط)
    return [
        'nat' => ['codmeli', 'nationalcode', 'nationalid', 'meli', 'کدملی'],
        'first' => ['naam', 'نام', 'firstname'],
        'last' => ['famili', 'فامیلی', 'نامخانوادگی', 'lastname'],
        'food' => ['naharentekhabi', 'nahar', 'foodname', 'foodtype', 'food', 'نامغذا', 'نوعغذا', 'نهارانتخابی'],
        'date' => ['tarikhentekhabi', 'fooddate', 'orderdate', 'تاریخانتخابی', 'تاریخغذا'],
        'resdate' => ['d', 'tarikhrezerv', 'reservedate', 'تاریخرزرو'],
        'restime' => ['s', 'saatrezerv', 'reservetime', 'ساعترزرو', 'ساعترزرو'],
    ];
}
/** ستون‌های الزامی برای تطبیق؛ بقیه اختیاری‌اند (نام، تاریخ و ساعت رزرو فقط برای نمایش و ثبت در تاریخچه). */
const FOOD_ORDER_IMPORT_REQUIRED = ['nat', 'food', 'date'];

/** کلید مقایسه‌ای ستون: حروف کوچک، بدون فاصله/خط‌تیره/زیرخط. */
function food_order_import_key(string $value): string
{
    if (function_exists('food_ticket_import_key')) {
        return strtolower(food_ticket_import_key($value));
    }
    return strtolower((string) preg_replace('/[\s_\-.]+/u', '', $value));
}

/**
 * خواندن فایل و تشخیص سرستون‌ها.
 * @return array{ok:bool,error?:string,rows?:array<int,array{line:int,nat:string,food:string,date:string}>}
 */
function food_order_import_read(string $path, string $name): array
{
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xlsm', 'csv', 'txt'], true)) {
        return ['ok' => false, 'error' => 'فقط فایل Excel (xlsx) یا CSV پذیرفته می‌شود.'];
    }
    if (!function_exists('food_ticket_import_read_xlsx') || !function_exists('food_ticket_import_read_csv')) {
        return ['ok' => false, 'error' => 'ماژول خواندن فایل (food-ticket-import.php) بارگذاری نشده است.'];
    }
    $read = in_array($extension, ['xlsx', 'xlsm'], true)
        ? food_ticket_import_read_xlsx($path)
        : food_ticket_import_read_csv($path);
    if (($read['error'] ?? '') !== '') {
        return ['ok' => false, 'error' => (string) $read['error']];
    }
    $sheet = is_array($read['rows'] ?? null) ? $read['rows'] : [];

    $keys = food_order_import_column_keys();
    $header = null;
    $cols = [];
    foreach ($sheet as $index => $cells) {
        if ($index > 20 || !is_array($cells)) {
            continue;
        }
        $found = [];
        foreach ($cells as $col => $cell) {
            $k = food_order_import_key((string) $cell);
            foreach ($keys as $field => $aliases) {
                if (!isset($found[$field]) && in_array($k, $aliases, true)) {
                    $found[$field] = (int) $col;
                }
            }
        }
        if (count(array_intersect_key($found, array_flip(FOOD_ORDER_IMPORT_REQUIRED))) === count(FOOD_ORDER_IMPORT_REQUIRED)) {
            $header = $index;
            $cols = $found;
            break;
        }
    }
    if ($header === null) {
        return ['ok' => false, 'error' => 'ستون‌های لازم پیدا نشد. فایل باید حداقل cod_meli، nahar_entekhabi و tarikh_entekhabi داشته باشد. ستون‌های کامل: کد ملی، نام، نام خانوادگی، نهار انتخابی، تاریخ انتخابی، تاریخ رزرو، ساعت رزرو (d و s).'];
    }

    $rows = [];
    foreach ($sheet as $index => $cells) {
        if ($index <= $header || !is_array($cells)) {
            continue;
        }
        $val = static fn (string $field): string => isset($cols[$field]) ? trim((string) ($cells[$cols[$field]] ?? '')) : '';
        $row = [
            'line' => $index + 1,
            'nat' => $val('nat'), 'first' => $val('first'), 'last' => $val('last'),
            'food' => $val('food'), 'date' => $val('date'),
            'resdate' => $val('resdate'), 'restime' => $val('restime'),
        ];
        if ($row['nat'] === '' && $row['food'] === '' && $row['date'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    return ['ok' => true, 'rows' => $rows];
}

/** تاریخ ورودی را به میلادی Y-m-d تبدیل می‌کند (شمسی/میلادی/سریال Excel/متن با ساعت). */
function food_order_import_date(string $raw): ?string
{
    $raw = trim(food_order_normalize_digits($raw));
    if ($raw === '') {
        return null;
    }
    // تاریخ با ساعت مثل «1405/07/18 00:00:00»
    $raw = trim((string) preg_replace('/[\sT]+\d{1,2}:\d{2}(:\d{2})?.*$/u', '', $raw));
    // سریال تاریخ Excel (عدد ۵ رقمی حدود ۴۰۰۰۰–۶۰۰۰۰)
    if (preg_match('/^\d{5}(\.\d+)?$/', $raw) && (float) $raw > 30000 && (float) $raw < 70000) {
        $ts = (int) round((((float) $raw) - 25569) * 86400);
        return gmdate('Y-m-d', $ts);
    }
    return food_order_iso_date($raw);
}

/** ساعت رزرو: 1300، 13:00، 13:00:00 یا عدد Excel (کسری از روز) → HH:MM */
function food_order_import_time(string $raw): ?string
{
    $raw = trim(food_order_normalize_digits($raw));
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
    if (preg_match('/^\d{1,2}\.\d+$/', $raw) || preg_match('/^0?\.\d+$/', $raw)) {
        $seconds = (int) round((float) $raw * 86400);
        return sprintf('%02d:%02d', intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60));
    }
    if (preg_match('/^\d{3,4}$/', $raw)) {
        $raw = str_pad($raw, 4, '0', STR_PAD_LEFT);
        return substr($raw, 0, 2) . ':' . substr($raw, 2, 2);
    }
    return null;
}

function food_order_import_norm_food(string $name): string
{
    if (function_exists('food_ticket_import_normalize')) {
        return mb_strtolower(food_ticket_import_normalize($name));
    }
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
}

/**
 * بررسی ردیف‌ها بدون نوشتن در دیتابیس.
 * @param array $rows خروجی food_order_import_read()['rows']
 * @return array{rows:array<int,array<string,mixed>>,counts:array<string,int>}
 */
function food_order_import_plan(array $rows): array
{
    food_order_schema_ensure();
    $pdo = db();

    // ۱) کارمندان با کد ملی
    $users = [];
    foreach ($pdo->query('SELECT id, national_code, full_name, is_active FROM users WHERE national_code IS NOT NULL AND national_code <> \'\'')->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $digits = food_order_digits($u['national_code']);
        if ($digits === '') {
            continue;
        }
        $users[$digits][] = $u;
        $users[ltrim($digits, '0') ?: '0'][] = $u;
    }

    // ۲) تاریخ‌ها و برنامهٔ غذایی همان تاریخ‌ها
    $plan = [];
    $isoDates = [];
    foreach ($rows as $row) {
        $iso = food_order_import_date((string) $row['date']);
        $plan[] = ['row' => $row, 'iso' => $iso];
        if ($iso !== null) {
            $isoDates[$iso] = true;
        }
    }
    $calendar = []; // iso => ['calendar_id'=>int, 'items'=>[normFood => ['item_id'=>, 'name'=>]]]
    $existing = []; // employee_id|iso => ['id','calendar_item_id','status','food']
    if ($isoDates !== []) {
        $dates = array_keys($isoDates);
        sort($dates);
        $in = implode(',', array_fill(0, count($dates), '?'));
        $stmt = $pdo->prepare("SELECT c.id AS calendar_id, c.food_date, i.id AS item_id, f.food_name
            FROM food_calendar c
            JOIN food_calendar_items i ON i.calendar_id = c.id AND i.active = 1
            JOIN food_catalog f ON f.id = i.food_id AND f.active = 1
            WHERE c.food_date IN ({$in})");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $iso = (string) $r['food_date'];
            $calendar[$iso]['calendar_id'] = (int) $r['calendar_id'];
            $calendar[$iso]['items'][food_order_import_norm_food((string) $r['food_name'])] = [
                'item_id' => (int) $r['item_id'],
                'name' => (string) $r['food_name'],
            ];
        }
        $stmt = $pdo->prepare("SELECT o.id, o.employee_id, o.calendar_item_id, o.food_date, o.status, f.food_name
            FROM food_orders o
            JOIN food_calendar_items i ON i.id = o.calendar_item_id
            JOIN food_catalog f ON f.id = i.food_id
            WHERE o.food_date IN ({$in})");
        $stmt->execute($dates);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[(int) $r['employee_id'] . '|' . $r['food_date']] = [
                'id' => (int) $r['id'],
                'calendar_item_id' => (int) $r['calendar_item_id'],
                'status' => (string) $r['status'],
                'food' => (string) $r['food_name'],
            ];
        }
    }

    $out = [];
    $seen = []; // employee|iso => true (تکراری داخل همین فایل)
    $counts = ['create' => 0, 'restore' => 0, 'exists' => 0, 'conflict' => 0, 'duplicate' => 0, 'invalid' => 0];
    foreach ($plan as $p) {
        $row = $p['row'];
        $iso = $p['iso'];
        $fileName = trim(($row['first'] ?? '') . ' ' . ($row['last'] ?? ''));
        $item = [
            'line' => $row['line'],
            'nat' => $row['nat'],
            'file_name' => $fileName,
            'food' => $row['food'],
            'date' => $row['date'],
            'iso' => $iso,
            'res_iso' => food_order_import_date((string) ($row['resdate'] ?? '')),
            'res_time' => food_order_import_time((string) ($row['restime'] ?? '')),
            'name_warning' => '',
            'employee_id' => null,
            'employee' => '',
            'name_warning' => '',
            'calendar_id' => null,
            'calendar_item_id' => null,
            'action' => 'invalid',
            'reason' => '',
        ];
        $nat = food_order_digits($row['nat']);
        $food = food_order_import_norm_food((string) $row['food']);
        if ($iso === null) {
            $item['reason'] = 'تاریخ نامعتبر است.';
        } elseif ($nat === '' || strlen($nat) !== 10) {
            $item['reason'] = 'کد ملی باید ۱۰ رقم باشد.';
        } elseif ($food === '') {
            $item['reason'] = 'نام غذا خالی است.';
        } else {
            $matches = $users[$nat] ?? $users[ltrim($nat, '0') ?: '0'] ?? [];
            $uniqueIds = array_values(array_unique(array_map(static fn ($u) => (int) $u['id'], $matches)));
            $active = array_values(array_filter($matches, static fn ($u) => (int) $u['is_active'] === 1));
            if ($uniqueIds === []) {
                $item['reason'] = 'کارمندی با این کد ملی در سامانه نیست.';
            } elseif (count($uniqueIds) > 1) {
                $item['reason'] = 'این کد ملی برای بیش از یک کارمند ثبت شده است.';
            } elseif ($active === []) {
                $item['reason'] = 'کارمند با این کد ملی غیرفعال است.';
            } elseif (!isset($calendar[$iso])) {
                $item['reason'] = 'برای این تاریخ برنامهٔ غذایی ثبت نشده است.';
            } elseif (!isset($calendar[$iso]['items'][$food])) {
                $names = array_map(static fn ($x) => $x['name'], $calendar[$iso]['items']);
                $item['reason'] = 'غذا در برنامهٔ این روز پیدا نشد. غذاهای این روز: ' . ($names ? implode('، ', $names) : '—');
            } else {
                $employeeId = (int) $active[0]['id'];
                $item['employee_id'] = $employeeId;
                $item['employee'] = (string) ($active[0]['full_name'] ?? '');
                $sysName = food_order_import_norm_food($item['employee']);
                $fileNorm = food_order_import_norm_food($fileName);
                if ($fileNorm !== '' && $sysName !== '' && $fileNorm !== $sysName) {
                    $item['name_warning'] = 'نام در فایل («' . $fileName . '») با سامانه یکی نیست؛ با کد ملی تطبیق شد.';
                }
                $item['calendar_id'] = $calendar[$iso]['calendar_id'];
                $item['calendar_item_id'] = $calendar[$iso]['items'][$food]['item_id'];
                $key = $employeeId . '|' . $iso;
                $old = $existing[$key] ?? null;
                if (isset($seen[$key])) {
                    $item['action'] = 'duplicate';
                    $item['reason'] = 'همین کارمند برای همین روز یک بار دیگر در فایل است.';
                } elseif ($old !== null && $old['status'] === 'active' && $old['calendar_item_id'] === $item['calendar_item_id']) {
                    $item['action'] = 'exists';
                    $item['reason'] = 'این سفارش قبلاً ثبت شده است.';
                } elseif ($old !== null && $old['status'] === 'active') {
                    $item['action'] = 'conflict';
                    $item['reason'] = 'کارمند برای این روز سفارش دیگری دارد: ' . $old['food'];
                } elseif ($old !== null) {
                    $item['action'] = 'restore';
                    $item['reason'] = 'سفارش لغوشده دوباره فعال می‌شود.';
                } else {
                    $item['action'] = 'create';
                    $item['reason'] = 'ثبت می‌شود.';
                }
                $seen[$key] = true;
            }
        }
        $counts[$item['action']] = ($counts[$item['action']] ?? 0) + 1;
        $out[] = $item;
    }
    return ['rows' => $out, 'counts' => $counts];
}

/**
 * ثبت سفارش‌های قابل‌ثبت (action = create | restore) در یک تراکنش.
 * @return array{created:int,restored:int,failed:int,errors:array<int,string>}
 */
function food_order_import_apply(array $plan, int $actorId): array
{
    food_order_schema_ensure();
    $pdo = db();
    $result = ['created' => 0, 'restored' => 0, 'failed' => 0, 'errors' => []];
    $pdo->beginTransaction();
    try {
        foreach ($plan['rows'] as $item) {
            if (!in_array($item['action'], ['create', 'restore'], true)) {
                continue;
            }
            $iso = (string) $item['iso'];
            $employeeId = (int) $item['employee_id'];
            $itemId = (int) $item['calendar_item_id'];
            if ($item['action'] === 'create') {
                $pdo->prepare("INSERT INTO food_orders (employee_id, calendar_item_id, food_date, created_by, status) VALUES (?, ?, ?, ?, 'active')")
                    ->execute([$employeeId, $itemId, $iso, $actorId]);
                $orderId = (int) $pdo->lastInsertId();
                $result['created']++;
            } else {
                $lock = $pdo->prepare('SELECT id FROM food_orders WHERE employee_id = ? AND food_date = ? FOR UPDATE');
                $lock->execute([$employeeId, $iso]);
                $orderId = (int) $lock->fetchColumn();
                $pdo->prepare("UPDATE food_orders SET calendar_item_id = ?, created_by = ?, status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([$itemId, $actorId, $orderId]);
                $result['restored']++;
            }
            food_order_log($orderId, (int) $item['calendar_id'], 'order_import', $actorId, null,
                ['calendar_item_id' => $itemId, 'employee_id' => $employeeId, 'line' => $item['line'], 'file_food' => $item['food'],
                 'reserved_date' => $item['res_iso'], 'reserved_time' => $item['res_time'], 'file_name' => $item['file_name']],
                'ایمپورت از فایل سفارش‌های قبلی');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['created'] = 0;
        $result['restored'] = 0;
        $result['failed'] = 1;
        $result['errors'][] = $e->getMessage();
        error_log('[food-order-import] rolled back: ' . $e->getMessage());
    }
    if (function_exists('system_log')) {
        system_log('info', 'food-orders', 'ایمپورت سفارش‌های غذا از فایل', [
            'created' => $result['created'], 'restored' => $result['restored'], 'by' => $actorId, 'failed' => $result['failed'],
        ]);
    }
    return $result;
}

function food_order_import_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** صفحهٔ آپلود/بررسی/ثبت (GET = فرم، POST = بررسی یا ثبت). */
function food_order_import_handle(array $user): never
{
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $isPrimary = function_exists('food_ticket_is_primary_admin') ? food_ticket_is_primary_admin($user) : false;
    // ایمپورت یک‌باره است و فقط برای ادمین اصلی.
    $canUse = $isPrimary;
    if (!$canUse) {
        http_response_code(403);
        exit('دسترسی به ایمپورت سفارش‌ها ندارید.');
    }
    $mode = (string) ($_POST['mode'] ?? '');
    $message = '';
    $error = '';
    $plan = null;
    $applied = null;
    $fileName = '';

    if ($isPost) {
        if (function_exists('require_csrf')) {
            require_csrf();
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file["tmp_name"] ?? ""))) {
            $error = 'فایل انتخاب نشده یا بارگذاری ناموفق بوده است.';
        } elseif ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $error = 'حجم فایل بیش از ۵ مگابایت است.';
        } else {
            $fileName = (string) ($file['name'] ?? 'orders');
            $read = food_order_import_read((string) $file['tmp_name'], $fileName);
            if (!$read['ok']) {
                $error = (string) $read['error'];
            } elseif (($read['rows'] ?? []) === []) {
                $error = 'در فایل ردیف داده‌ای پیدا نشد.';
            } else {
                $plan = food_order_import_plan($read['rows']);
                if ($mode === 'apply') {
                    $applied = food_order_import_apply($plan, (int) ($user['id'] ?? 0));
                    $message = 'ثبت انجام شد: ' . $applied['created'] . ' سفارش جدید، ' . $applied['restored'] . ' سفارش بازیابی‌شده.';
                    if ($applied['failed']) {
                        $error = 'ثبت انجام نشد و همهٔ تغییرات برگشت داده شد: ' . implode(' | ', $applied['errors']);
                    }
                    if (function_exists('save_audit') && !$applied['failed']) {
                        save_audit((int) ($user['id'] ?? 0), 'food_orders_imported', null, ['file' => $fileName, 'created' => $applied['created'], 'restored' => $applied['restored']]);
                    }
                }
            }
        }
    }

    $csrf = function_exists('csrf_field') ? csrf_field() : '';
    $action = 'index.php?page=food-ticket&food_api=orders-import';
    $h = 'food_order_import_h';
    $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ایمپورت سفارش‌های غذا</title>'
        . '<style>body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f5f7fb;margin:0;padding:24px;color:#1d2433}'
        . '.box{background:#fff;border-radius:12px;padding:20px;max-width:1100px;margin:0 auto 16px;box-shadow:0 1px 4px #0001}'
        . 'table{border-collapse:collapse;width:100%;font-size:13px}th,td{border-bottom:1px solid #e5e8f0;padding:6px 8px;text-align:right}'
        . 'th{background:#f0f3fa}.ok{color:#117a37}.bad{color:#b42318}.warn{color:#9a6700}'
        . 'button{padding:8px 16px;border-radius:8px;border:0;cursor:pointer;margin-left:8px}.p{background:#2563eb;color:#fff}.g{background:#16a34a;color:#fff}'
        . '.muted{color:#667085;font-size:13px}</style></head><body>';
    $html .= '<div class="box"><h2>ایمپورت سفارش‌های غذا</h2>'
        . '<p class="muted">فایل خروجی جدول سفارش‌های Access (Excel یا CSV) را انتخاب کنید. ستون‌های لازم: <b>cod_meli</b>، <b>nahar_entekhabi</b>، <b>tarikh_entekhabi</b>. '
        . 'ابتدا «بررسی فایل» را بزنید؛ تا وقتی «ثبت سفارش‌ها» را نزنید چیزی ذخیره نمی‌شود. '
        . 'غذا و تاریخ باید با «برنامهٔ غذایی» همان روز یکی باشند؛ برای روزهایی که برنامه ندارند ابتدا برنامه را ثبت کنید.</p>'
        . '<form method="post" action="' . $h($action) . '" enctype="multipart/form-data">' . $csrf
        . '<input type="file" name="file" accept=".xlsx,.xlsm,.csv,.txt" required> '
        . '<button class="p" type="submit" name="mode" value="preview">بررسی فایل</button></form></div>';

    if ($error !== '') {
        $html .= '<div class="box bad">' . $h($error) . '</div>';
    }
    if ($message !== '') {
        $html .= '<div class="box ok">' . $h($message) . '</div>';
    }
    if (is_array($plan)) {
        $c = $plan['counts'];
        $html .= '<div class="box"><h3>نتیجهٔ بررسی: ' . $h($fileName) . '</h3>'
            . '<p>قابل ثبت: <b class="ok">' . (int) (($c['create'] ?? 0) + ($c['restore'] ?? 0)) . '</b> | '
            . 'از قبل ثبت شده: ' . (int) ($c['exists'] ?? 0) . ' | تعارض با سفارش موجود: <b class="warn">' . (int) ($c['conflict'] ?? 0) . '</b> | '
            . 'تکراری در فایل: ' . (int) ($c['duplicate'] ?? 0) . ' | نامعتبر: <b class="bad">' . (int) ($c['invalid'] ?? 0) . '</b></p>';
        $ready = (int) (($c['create'] ?? 0) + ($c['restore'] ?? 0));
        if ($applied === null && $ready > 0) {
            $html .= '<form method="post" action="' . $h($action) . '" enctype="multipart/form-data" onsubmit="return confirm(\'سفارش‌های قابل‌ثبت ذخیره شوند؟\')">' . $csrf
                . '<input type="file" name="file" accept=".xlsx,.xlsm,.csv,.txt" required> '
                . '<p class="muted">برای ثبت، همان فایل را دوباره انتخاب کنید.</p>'
                . '<button class="g" type="submit" name="mode" value="apply">ثبت ' . $ready . ' سفارش</button></form>';
        }
        $html .= '<table><thead><tr><th>ردیف</th><th>کد ملی</th><th>نام (فایل)</th><th>نام (سامانه)</th><th>نهار انتخابی</th><th>تاریخ غذا</th><th>تاریخ رزرو</th><th>ساعت رزرو</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody>';
        foreach ($plan['rows'] as $r) {
            $cls = in_array($r['action'], ['create', 'restore'], true) ? 'ok' : (in_array($r['action'], ['conflict', 'duplicate'], true) ? 'warn' : ($r['action'] === 'exists' ? 'muted' : 'bad'));
            $label = ['create' => 'ثبت می‌شود', 'restore' => 'بازیابی', 'exists' => 'قبلاً ثبت', 'conflict' => 'تعارض', 'duplicate' => 'تکراری', 'invalid' => 'نامعتبر'][$r['action']] ?? $r['action'];
            $reason = $r['reason'] . ($r['name_warning'] !== '' ? ' ⚠ ' . $r['name_warning'] : '');
            $html .= '<tr><td>' . (int) $r['line'] . '</td><td>' . $h($r['nat']) . '</td><td>' . $h($r['file_name']) . '</td><td>' . $h($r['employee']) . '</td><td>' . $h($r['food']) . '</td><td>' . $h($r['iso'] ?? $r['date']) . '</td>'
                . '<td>' . $h($r['res_iso'] ?? '') . '</td><td>' . $h($r['res_time'] ?? '') . '</td>'
                . '<td class="' . $cls . '">' . $h($label) . '</td><td>' . $h($reason) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</body></html>';
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
