<?php
declare(strict_types=1);
const APP_ROOT = __DIR__ . '/..';
function db() { throw new RuntimeException('no db'); }
function db_table_exists($t) { return false; }
function cfg($k, $d = null) { return $d; }

require APP_ROOT . '/food-ticket.php';
require_once APP_ROOT . '/food-ticket-runtime.php';
require_once APP_ROOT . '/food-ticket-engine.php';

$failed = 0;
function expect(string $name, bool $ok): void {
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $failed++;
}

expect('date_int', food_ticket_parse_date(20260501) === '2026-05-01');
expect('date_str', food_ticket_parse_date('20260501') === '2026-05-01');
expect('time_130523', food_ticket_parse_time(130523) === '13:05:23');
expect('time_83000', food_ticket_parse_time(83000) === '08:30:00');
expect('norm_personnel_preserves_zeroes', food_ticket_norm_personnel('00377') === '00377');
expect('norm_personnel_persian_digits', food_ticket_norm_personnel('۰۰۳۷۷') === '00377');
expect('norm_personnel_decimal_preserves_zeroes', food_ticket_norm_personnel('00377.0') === '00377');
expect('norm_float', food_ticket_norm_personnel('377.0') === '377');
expect('code_decimal_preserves_zeroes', food_ticket_code('00377.0') === '00377');
expect('engine_loaded', function_exists('food_ticket_process_batch_v2'));
expect('decide_loaded', function_exists('food_ticket_decide_punch'));
$where = food_ticket_source_delete_where([
    'uid' => '00377', 'card' => 'A5659EE4', 'date_raw' => 20261002, 'time_raw' => 83000,
    'row' => ['l_uid' => '00377', 'c_card' => 'A5659EE4', 'c_date' => 20261002, 'c_time' => 83000],
    'columns' => ['l_uid' => 'L_UID', 'c_card' => 'C_Card', 'c_date' => 'C_Date', 'c_time' => 'C_Time'],
]);
expect('source_delete_preserves_uid_zeroes', is_string($where) && str_contains($where, "[L_UID] = '00377'"));
expect('source_delete_uses_exact_row_fields', is_string($where) && str_contains($where, '[C_Date]') && str_contains($where, '[C_Time]') && str_contains($where, '[C_Card]'));
expect('source_delete_rejects_incomplete_identity', food_ticket_source_delete_where(['uid' => '00377', 'row' => []]) === null);

$defaultTemplate = food_ticket_template_default();
expect('short_receipt_has_only_requested_fields', array_column($defaultTemplate['fields'], 'key') === ['full_name', 'food_type', 'food_date', 'attendance_time']);
$slip = food_ticket_render_from_template([
    'full_name' => 'علی تست', 'food_type' => 'چلو', 'punch_date' => '2026-05-01', 'punch_time' => '08:12:41',
], $defaultTemplate);
expect('short_receipt_prints_attendance_aliases', str_contains($slip, 'تاریخ غذا: 2026-05-01') && str_contains($slip, 'ساعت تردد: 08:12:41'));
expect('short_receipt_omits_personal_codes', !str_contains($slip, 'کد ملی') && !str_contains($slip, 'کد پرسنلی'));
expect('organization_name_is_optional', !str_contains($slip, 'سازمان'));
expect('print_status_guard_helper', food_ticket_can_delete_source_after_print('printed') && !food_ticket_can_delete_source_after_print('pending'));

echo $failed === 0 ? "\nALL PASSED\n" : "\n$failed FAILED\n";
exit($failed ? 1 : 0);
