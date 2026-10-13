<?php
/**
 * تشخیص سریع فیش‌غذا — از مرورگر:
 *   index.php?page=food-ticket&food_api=diag
 * یا مستقیم اگر bootstrap لود شود.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$out = ['ok' => true, 'checks' => []];

try {
    if (!function_exists('food_ticket_config')) {
        echo json_encode(['ok' => false, 'error' => 'food_ticket functions not loaded'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cfg = food_ticket_config(true);
    $out['config'] = [
        'order_source' => 'INTERNAL-DB',
        'access_orders_connected' => false,
        'attendance_path' => $cfg['attendance_path'] ?? '',
        'printer_mode' => $cfg['printer_mode'] ?? '',
    ];

    // --- Internal orders diagnostic (aggregate only; no Access or personal identifiers) ---
    try {
        $today = function_exists('food_order_today') ? food_order_today() : date('Y-m-d');
        food_order_schema_ensure();
        $count = db()->prepare("SELECT COUNT(*) FROM food_orders WHERE food_date = ? AND status = 'active'");
        $count->execute([$today]);
        $foods = db()->prepare("SELECT c.food_name, COUNT(*) AS n
            FROM food_orders o JOIN food_calendar_items i ON i.id = o.calendar_item_id
            JOIN food_catalog c ON c.id = i.food_id
            WHERE o.food_date = ? AND o.status = 'active' GROUP BY c.food_name ORDER BY c.food_name");
        $foods->execute([$today]);
        $out['orders'] = [
            'source' => 'INTERNAL-DB',
            'date' => $today,
            'active_count' => (int) $count->fetchColumn(),
            'by_food' => array_map(static fn (array $row): array => ['food_name' => (string) $row['food_name'], 'count' => (int) $row['n']], $foods->fetchAll(PDO::FETCH_ASSOC)),
            'access_read' => false,
        ];
    } catch (Throwable $e) {
        $out['orders_error'] = $e->getMessage();
    }

    // --- User lookup 377 ---
    $codes = ['377', '0377', '00377', '378', '0378'];
    $out['users'] = [];
    foreach ($codes as $c) {
        $u = function_exists('food_ticket_find_user_strict') ? food_ticket_find_user_strict($c) : null;
        $out['users'][$c] = $u ? [
            'id' => $u['id'] ?? null,
            'full_name' => $u['full_name'] ?? null,
            'employee_number' => $u['employee_number'] ?? null,
            'is_active' => $u['is_active'] ?? null,
        ] : null;
    }
    try {
        $all = db()->query("SELECT id, full_name, employee_number, is_active FROM users WHERE employee_number IS NOT NULL AND TRIM(employee_number)<>'' ORDER BY id LIMIT 30")->fetchAll();
        $out['users_sample'] = $all;
    } catch (Throwable $e) {
        $out['users_sample_error'] = $e->getMessage();
    }

    // --- نمونهٔ ردیف‌های منبع تردد ---
    try {
        $conn = food_ticket_odbc((string) ($cfg['attendance_path'] ?? ''), function_exists('food_ticket_attendance_password') ? food_ticket_attendance_password($cfg) : '');
        $rows = food_ticket_access_rows($conn, food_ticket_source_table(), 5);
        food_ticket_access_close($conn);
        $us = [];
        foreach ($rows as $item) {
            $us[] = [
                'uid' => $item['uid'] ?? null,
                'card' => $item['card'] ?? null,
                'date_raw' => $item['date_raw'] ?? null,
                'time_raw' => $item['time_raw'] ?? null,
                'date_parsed' => food_ticket_parse_date($item['date_raw'] ?? null),
                'time_parsed' => food_ticket_parse_time($item['time_raw'] ?? null),
            ];
        }
        $out['attendance'] = $us;
    } catch (Throwable $e) {
        $out['attendance_error'] = $e->getMessage();
    }

    $out['php'] = [
        'version' => PHP_VERSION,
        'os' => PHP_OS,
        'timezone' => date_default_timezone_get(),
        'now_tehran' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s'),
        'file' => __FILE__,
        'food_ticket_mtime' => @filemtime(dirname(__DIR__) . '/food-ticket.php'),
    ];
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
