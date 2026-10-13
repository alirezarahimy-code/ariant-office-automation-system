<?php
declare(strict_types=1);

/**
 * لایهٔ منبع سفارش فیش.
 * در این نسخه سفارش فقط از MySQL داخلی خوانده می‌شود؛ این فایل به ODBC سفارش وصل نمی‌شود.
 * Access منبع تردد TENTER در مسیر اصلی Worker دست‌نخورده باقی می‌ماند.
 */

function food_order_internal_map(string $isoDate): array
{
    food_order_schema_ensure();
    $date = food_order_iso_date($isoDate);
    if ($date === null) {
        throw new RuntimeException('تاریخ نقشهٔ سفارش داخلی معتبر نیست.');
    }
    // فقط آیتم‌ها و غذاهای فعال؛ غذای غیرفعال‌شده فیش نمی‌شود.
    $stmt = db()->prepare("SELECT o.employee_id, u.national_code, c.food_name
        FROM food_orders o
        JOIN users u ON u.id = o.employee_id
        JOIN food_calendar_items i ON i.id = o.calendar_item_id AND i.active = 1
        JOIN food_catalog c ON c.id = i.food_id AND c.active = 1
        WHERE o.food_date = ? AND o.status = 'active'
        ORDER BY o.id");
    $stmt->execute([$date]);
    $map = [];
    $nationalOwners = [];
    $conflicts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $national = food_order_digits($row['national_code'] ?? '');
        $food = trim((string) ($row['food_name'] ?? ''));
        $employeeId = (int) ($row['employee_id'] ?? 0);
        if ($national === '' || $food === '') {
            continue;
        }
        // کد ملی تکراری فقط همان فرد(ها) را از فیش خارج می‌کند؛ بقیهٔ کارکنان عادی چاپ می‌شوند.
        if (isset($nationalOwners[$national]) && $nationalOwners[$national] !== $employeeId) {
            $conflicts[$national] = true;
            continue;
        }
        $nationalOwners[$national] = $employeeId;
        $map[$national] = $food;
        $map[ltrim($national, '0') ?: '0'] = $food;
    }
    foreach (array_keys($conflicts) as $bad) {
        // کلیدهای عددی آرایه (مثلاً 1234567890) در PHP به int تبدیل می‌شوند؛ ltrim فقط رشته می‌پذیرد.
        $bad = (string) $bad;
        unset($map[$bad], $map[ltrim($bad, '0') ?: '0']);
    }
    $GLOBALS['__food_order_conflicts'][$date] = array_keys($conflicts);
    return $map;
}

/** خروجی با قرارداد صفحهٔ سفارش‌های پنل چاپ فیش (nat/pc/first/last/food/foodDate/...). */
function food_order_internal_items(string $fromDate, ?string $toDate = null): array
{
    food_order_schema_ensure();
    $from = food_order_iso_date($fromDate);
    $to = food_order_iso_date($toDate ?? $fromDate);
    if ($from === null || $to === null || new DateTimeImmutable($from, new DateTimeZone('UTC')) > new DateTimeImmutable($to, new DateTimeZone('UTC'))) {
        throw new RuntimeException('بازهٔ تاریخ سفارش داخلی معتبر نیست.');
    }
    $stmt = db()->prepare("SELECT o.employee_id, o.food_date, o.created_at, u.employee_number, u.national_code,
            u.first_name, u.last_name, u.full_name, c.food_name
        FROM food_orders o
        JOIN users u ON u.id = o.employee_id
        JOIN food_calendar_items i ON i.id = o.calendar_item_id
        JOIN food_catalog c ON c.id = i.food_id
        WHERE o.food_date BETWEEN ? AND ? AND o.status = 'active'
        ORDER BY o.food_date, u.full_name, o.id");
    $stmt->execute([$from, $to]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/u', trim((string) ($row['full_name'] ?? '')), 2) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = (string) ($parts[1] ?? '');
        }
        $created = (string) ($row['created_at'] ?? '');
        $isoDate = (string) $row['food_date'];
        $items[] = [
            'nat' => food_order_digits($row['national_code'] ?? ''),
            'pc' => (string) ($row['employee_number'] ?? ''),
            'first' => $first,
            'last' => $last,
            'food' => (string) ($row['food_name'] ?? ''),
            'foodDate' => $isoDate,
            'rawDate' => $isoDate,
            'reserveDate' => substr($created, 0, 10),
            'reserveTime' => substr($created, 11, 8),
        ];
    }
    return $items;
}
