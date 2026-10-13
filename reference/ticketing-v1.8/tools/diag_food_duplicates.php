<?php
declare(strict_types=1);

/**
 * عیب‌یابی رکوردهای دوتایی پایش (فقط CLI):
 *   C:\xampp\php\php.exe tools\diag_food_duplicates.php [YYYY-MM-DD]
 * رویدادهایی که کمتر از ۵ ثانیه از هم فاصله دارند را کنار هم نشان می‌دهد، همراه با L_UID / C_Card و ستون‌های خام ردیف SOURCE_TABLE،
 * تا معلوم شود یک کشیدن کارت چند ردیف ساخته است یا نه.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';

$date = $argv[1] ?? date('Y-m-d');
$q = db()->prepare('SELECT id, punch_time, source_uid, source_card, user_id, full_name, event_type, print_status, ticket_key, source_payload FROM food_ticket_events WHERE punch_date = ? ORDER BY punch_time, id');
$q->execute([$date]);
$rows = $q->fetchAll();
echo "تاریخ $date | تعداد رویداد: " . count($rows) . PHP_EOL . PHP_EOL;
$prev = null;
foreach ($rows as $r) {
    $close = $prev !== null && abs(strtotime('1970-01-01 ' . $r['punch_time']) - strtotime('1970-01-01 ' . $prev['punch_time'])) <= 5;
    echo ($close ? '  ↳ ' : '') . sprintf("#%d %s uid=%s card=%s user=%s [%s/%s] %s\n", $r['id'], $r['punch_time'], $r['source_uid'] ?: '-', $r['source_card'] ?: '-', $r['full_name'] ?: '-', $r['event_type'], $r['print_status'], (string) $r['ticket_key']);
    if ($close) {
        echo '      ردیف خام: ' . mb_substr((string) $r['source_payload'], 0, 300) . PHP_EOL;
    }
    $prev = $r;
}
