<?php
declare(strict_types=1);

/**
 * آزمون کارت گروهی غذا (فقط CLI) — روی دیتابیس واقعی، با داده‌های موقتی که در پایان پاک می‌شوند.
 * هیچ فیشی روی چاپگر چاپ نمی‌شود و به فایل منبع تردد وصل نمی‌شود (سفارش‌ها به‌صورت نقشهٔ ساختگی تزریق می‌شوند).
 *
 *   C:\xampp\php\php.exe tools\test_food_groups.php
 *
 * سناریوها: ۱) ۱۰ نفر با سفارش → ۱۰ فیش  ۲) ۳ نفر بدون سفارش → ۷ فیش  ۳) چند نفر فیش قبلی دارند → فقط بقیه
 *          ۴) کارت ناشناس → هیچ  ۵) گروه غیرفعال → هیچ  ۶) تکرار همان کارت → فقط خلاصه  ۷) ردیف دوم همان کشیدن → نادیده
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';
foreach (['food-ticket-runtime.php', 'food-ticket-engine.php', 'food-ticket-netprint.php', 'food-ticket-groups.php'] as $extra) {
    $p = dirname(__DIR__) . '/' . $extra;
    if (is_file($p)) {
        require_once $p;
    }
}

$fail = 0;
$check = static function (string $label, bool $cond, string $detail = '') use (&$fail): void {
    if (!$cond) {
        $fail++;
    }
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

if (!food_ticket_groups_ensure()) {
    exit("جدول‌های گروه ساخته نشد.\n");
}

$tag = 'ZZT' . strtoupper(substr(md5((string) microtime(true)), 0, 6));
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
$date = $now->format('Y-m-d');

$cleanup = static function () use ($tag): void {
    $like = $tag . '%';
    db()->prepare('DELETE e FROM food_ticket_events e JOIN users u ON u.id = e.user_id WHERE u.username LIKE ?')->execute([strtolower($tag) . '_%']);
    db()->prepare('DELETE FROM food_ticket_group_runs WHERE rfid_card LIKE ?')->execute([$like]);
    db()->prepare('DELETE m FROM food_ticket_group_members m JOIN food_ticket_groups g ON g.id = m.group_id WHERE g.rfid_card LIKE ?')->execute([$like]);
    db()->prepare('DELETE FROM food_ticket_groups WHERE rfid_card LIKE ?')->execute([$like]);
    db()->prepare('DELETE FROM users WHERE username LIKE ?')->execute([strtolower($tag) . '_%']);
    food_ticket_groups_load(true);
};

$seq = 0;
$mkUsers = static function (int $n, string $prefix) use ($tag, &$seq): array {
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $seq++;
        $nat = (string) (9000000000 + random_int(100000, 999999) + $seq * 1000000 % 99999999);
        $nat = substr(str_pad($nat, 10, '7'), 0, 10);
        db()->prepare('INSERT INTO users (username, password_hash, full_name, first_name, last_name, employee_number, national_code, role, auth_source, is_active) VALUES (?, NULL, ?, ?, ?, ?, ?, "user", "local", 1)')
            ->execute([strtolower($tag) . '_' . $prefix . $i, 'تست ' . $prefix . $i, 'تست', $prefix . $i, 'FG' . $tag . $prefix . $i, $nat]);
        $ids[] = ['id' => (int) db()->lastInsertId(), 'nat' => $nat];
    }
    return $ids;
};
$mkGroup = static function (string $title, string $card, array $users, bool $active = true): int {
    return food_ticket_api_save_group(['title' => $title, 'card' => $card, 'active' => $active, 'members' => array_column($users, 'id')], 0);
};
$tap = static function (string $card, int $secondsOffset, int $n) use ($now): array {
    $t = $now->setTime(12, 0, 0)->modify(($secondsOffset >= 0 ? '+' : '') . $secondsOffset . ' seconds');
    return ['uid' => '', 'card' => $card, 'date_raw' => $now, 'time_raw' => $t, 'row' => ['C_Card' => $card, 'n' => $n]];
};
$events = static function (array $users): array {
    $ids = array_column($users, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = db()->prepare("SELECT event_type, print_status, COUNT(*) c FROM food_ticket_events WHERE user_id IN ($in) GROUP BY event_type, print_status");
    $q->execute($ids);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[$r['event_type']] = (int) $r['c'];
    }
    return $out;
};
$run = static function (array $item, array $orders) use ($date): array {
    $config = food_ticket_config(true);
    $config['_group_skip_print'] = true;   // بدون چاپ واقعی
    $orderMaps = [$date => $orders];        // سفارش ساختگی؛ بدون اتصال به Access
    $summary = ['processed' => 0, 'queued' => 0, 'errors' => 0, 'skipped' => 0, 'repeat' => 0, 'no_food' => 0];
    $res = food_ticket_group_try_process($item, $config, $orderMaps, null, $summary);
    return [$res, $summary];
};
$orderFor = static function (array $users, int $skip = 0): array {
    $m = [];
    foreach ($users as $i => $u) {
        if ($i >= count($users) - $skip) {
            break;
        }
        $m[$u['nat']] = 'چلوکباب';
    }
    return $m;
};

try {
    $cleanup();

    // ── سناریو ۱ ──
    $u1 = $mkUsers(10, 'a');
    $c1 = $tag . 'A1';
    $g1 = $mkGroup('گروه تست ۱', $c1, $u1);
    [$res, $sum] = $run($tap($c1, 0, 1), $orderFor($u1));
    $ev = $events($u1);
    $check('۱) ۱۰ عضو با سفارش → ۱۰ فیش (printed/pending)', ($ev['printed'] ?? 0) === 10 && $res !== null && $res['delete'] === true, json_encode($ev));
    $r = db()->prepare('SELECT run_kind, status, printed_count FROM food_ticket_group_runs WHERE group_id = ?');
    $r->execute([$g1]);
    $row = $r->fetch();
    $check('۱) اجرا در food_ticket_group_runs ثبت شد (first/done/10)', $row && $row['run_kind'] === 'first' && $row['status'] === 'done' && (int) $row['printed_count'] === 10);

    // ── سناریو ۶: تکرار همان کارت همان روز ──
    [$res2, $sum2] = $run($tap($c1, 60, 2), $orderFor($u1));
    $ev2 = $events($u1);
    $r->execute([$g1]);
    $kinds = array_column($r->fetchAll(), 'run_kind');
    sort($kinds);
    $check('۶) تکرار کارت: فقط خلاصه، بدون Event جدید برای کاربران', $ev2 === $ev && $kinds === ['first', 'repeat'] && $res2 !== null && $res2['kind'] === 'repeat', json_encode($ev2) . ' ' . json_encode($kinds));

    // ── سناریو ۷: ردیف دوم همان کشیدن (۲ ثانیه بعد) ──
    $before = (int) db()->query('SELECT COUNT(*) FROM food_ticket_group_runs WHERE rfid_card = ' . db()->quote($c1))->fetchColumn();
    [$res3] = $run($tap($c1, 62, 3), $orderFor($u1));
    $after = (int) db()->query('SELECT COUNT(*) FROM food_ticket_group_runs WHERE rfid_card = ' . db()->quote($c1))->fetchColumn();
    $check('۷) ردیف دوم همان کشیدن: رکورد جدید ساخته نشد، ردیف حذف می‌شود', $before === $after && $res3 !== null && $res3['kind'] === 'duplicate' && $res3['delete'] === true);

    // ── سناریو ۲: ۳ نفر بدون سفارش ──
    $u2 = $mkUsers(10, 'b');
    $c2 = $tag . 'B2';
    $mkGroup('گروه تست ۲', $c2, $u2);
    $run($tap($c2, 0, 4), $orderFor($u2, 3));
    $ev = $events($u2);
    $check('۲) ۳ نفر بدون سفارش → ۷ فیش و ۳ no_food', ($ev['printed'] ?? 0) === 7 && ($ev['no_food'] ?? 0) === 3, json_encode($ev));

    // ── سناریو ۳: چند نفر قبلاً فیش گرفته‌اند ──
    $u3 = $mkUsers(10, 'c');
    $c3 = $tag . 'C3';
    $mkGroup('گروه تست ۳', $c3, $u3);
    foreach (array_slice($u3, 0, 4) as $u) {
        food_ticket_add_event([
            'source_key' => hash('sha256', 'pre|' . $tag . '|' . $u['id']), 'punch_date' => $date, 'punch_time' => '08:00:00',
            'personnel_code' => 'x', 'user_id' => $u['id'], 'national_code' => $u['nat'], 'full_name' => 'قبلی', 'food_type' => 'چلوکباب',
            'ticket_key' => 'food:' . $date . ':' . $u['nat'], 'event_type' => 'printed', 'print_status' => 'printed',
        ]);
    }
    $run($tap($c3, 0, 5), $orderFor($u3));
    $q = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE user_id = ? AND event_type = "printed"');
    $newPrinted = 0;
    $repeat = 0;
    foreach ($u3 as $i => $u) {
        $q->execute([$u['id']]);
        $newPrinted += ((int) $q->fetchColumn() - ($i < 4 ? 1 : 0));
    }
    $ev = $events($u3);
    $repeat = $ev['repeat'] ?? 0;
    $check('۳) ۴ نفر فیش قبلی دارند → فقط ۶ نفر چاپ و ۴ رویداد repeat', $newPrinted === 6 && $repeat === 4, 'newPrinted=' . $newPrinted . ' repeat=' . $repeat);

    // ── سناریو ۴: کارت ناشناس ──
    [$resU] = $run($tap($tag . 'ZZ9', 0, 6), []);
    $check('۴) کارت ناشناس → hook null (مسیر ناشناس فعلی، هیچ فیشی)', $resU === null);

    // ── سناریو ۵: گروه غیرفعال ──
    $u5 = $mkUsers(5, 'e');
    $c5 = $tag . 'E5';
    $g5 = $mkGroup('گروه تست ۵', $c5, $u5, false);
    [$res5] = $run($tap($c5, 0, 7), $orderFor($u5));
    $k = db()->prepare('SELECT run_kind FROM food_ticket_group_runs WHERE group_id = ?');
    $k->execute([$g5]);
    $check('۵) گروه غیرفعال → بدون فیش و Event، فقط خلاصه (inactive)', $events($u5) === [] && $k->fetchColumn() === 'inactive' && $res5 !== null && $res5['delete'] === true);

    // ── قوانین عضویت و کارت ──
    $u6 = $mkUsers(2, 'f');
    $threw = static function (callable $fn): bool {
        try {
            $fn();
        } catch (RuntimeException) {
            return true;
        }
        return false;
    };
    $check('عضویت: کاربر عضو گروه دیگر قابل افزودن نیست', $threw(fn () => food_ticket_api_save_group(['title' => 'x', 'card' => $tag . 'F6', 'members' => [$u1[0]['id']]], 0)));
    $check('کارت تکراری برای گروه دیگر پذیرفته نمی‌شود', $threw(fn () => food_ticket_api_save_group(['title' => 'x', 'card' => $c1, 'members' => [$u6[0]['id']]], 0)));
    $check('گروه بدون کارت معتبر ذخیره نمی‌شود', $threw(fn () => food_ticket_api_save_group(['title' => 'x', 'card' => '', 'members' => []], 0)));
    $free = array_column(food_ticket_api_groups()['free_users'], 'id');
    $check('لیست کاربران آزاد شامل اعضای گروه‌های دیگر نیست', !in_array($u1[0]['id'], $free, true) && in_array($u6[0]['id'], $free, true));
} catch (Throwable $e) {
    $fail++;
    echo '[ERROR] ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    try {
        $cleanup();
        echo "داده‌های آزمون پاک شد.\n";
    } catch (Throwable $e) {
        echo 'پاک‌سازی ناموفق: ' . $e->getMessage() . "\n";
    }
}

echo $fail === 0 ? "\nهمهٔ آزمون‌ها موفق بود.\n" : "\n{$fail} آزمون ناموفق بود.\n";
exit($fail === 0 ? 0 : 1);
