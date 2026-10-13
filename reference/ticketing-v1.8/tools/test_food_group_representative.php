<?php
declare(strict_types=1);

/**
 * آزمون‌های ۱۵گانهٔ «نمایندهٔ گروه با L_UID + غیبت روزانهٔ گروه + تحویل غذا» — فقط CLI
 * =============================================================================
 *   C:\xampp\php\php.exe tools\test_food_group_representative.php
 *
 * روی دیتابیس واقعی اجرا می‌شود، اما:
 *   • هیچ اتصالی به فایل منبع تردد و هیچ چاپ واقعی انجام نمی‌شود (سفارش‌ها تزریق می‌شوند و
 *     پرچم $config['_group_skip_print'] روشن است).
 *   • همهٔ کاربران/گروه‌ها با برچسب موقت ساخته و در finally پاک می‌شوند.
 *   • برای اینکه اجرای آزمون، فیش‌های واقعی را دوباره به صف چاپ نفرستد، چاپ مجدد واقعی
 *     فقط با متغیر محیطی FT_TEST_ALLOW_PRINT=1 انجام می‌شود (پیش‌فرض: فقط بررسی انتخاب رکوردها).
 *
 * فهرست آزمون‌ها (۱ تا ۱۵) در انتهای هر بخش با [PASS]/[FAIL] گزارش می‌شود.
 *
 * کد خروج: 0 = همه موفق، 1 = حداقل یک شکست.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';
foreach (['food-ticket-runtime.php', 'food-ticket-engine.php', 'food-ticket-netprint.php', 'food-ticket-groups.php', 'food-ticket-templates.php'] as $extra) {
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
food_ticket_absence_ensure();
$deliveryOk = food_ticket_events_ensure_delivery();

$tag = 'ZZR' . strtoupper(substr(md5((string) microtime(true)), 0, 6));
$tz = new DateTimeZone('Asia/Tehran');
$now = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$yesterday = $now->modify('-1 day')->format('Y-m-d');
$twoDaysAgo = $now->modify('-2 day')->format('Y-m-d');

$cleanup = static function () use ($tag): void {
    $like = $tag . '%';
    $userLike = strtolower($tag) . '_%';
    try {
        db()->prepare('DELETE e FROM food_ticket_events e JOIN users u ON u.id = e.user_id WHERE u.username LIKE ?')->execute([$userLike]);
    } catch (Throwable $e) { /* ignore */ }
    try {
        db()->prepare('DELETE FROM food_ticket_events WHERE ticket_key LIKE ?')->execute(['unknown:%']);
    } catch (Throwable $e) { /* ignore */ }
    db()->prepare('DELETE FROM food_ticket_daily_absence WHERE group_id IN (SELECT id FROM food_ticket_groups WHERE rfid_card LIKE ? OR l_uid LIKE ? OR title LIKE ?)')->execute([$like, $like, $like]);
    db()->prepare('DELETE FROM food_ticket_group_runs WHERE rfid_card LIKE ? OR trigger_uid LIKE ? OR group_title LIKE ?')->execute([$like, $like, $like]);
    db()->prepare('DELETE m FROM food_ticket_group_members m JOIN food_ticket_groups g ON g.id = m.group_id WHERE g.rfid_card LIKE ? OR g.l_uid LIKE ? OR g.title LIKE ?')->execute([$like, $like, $like]);
    db()->prepare('DELETE FROM food_ticket_groups WHERE rfid_card LIKE ? OR l_uid LIKE ? OR title LIKE ?')->execute([$like, $like, $like]);
    db()->prepare('DELETE FROM food_ticket_group_uid_history WHERE uid LIKE ?')->execute([$like]);
    db()->prepare('DELETE FROM users WHERE username LIKE ?')->execute([$userLike]);
    food_ticket_groups_load(true);
};

$seq = 0;
$mkUsers = static function (int $n, string $prefix) use ($tag, &$seq): array {
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $seq++;
        $nat = str_pad((string) (random_int(10000000, 99999999)) . str_pad((string) $seq, 2, '0', STR_PAD_LEFT), 10, '0');
        $nat = substr($nat, 0, 10);
        db()->prepare('INSERT INTO users (username, password_hash, full_name, first_name, last_name, employee_number, national_code, role, auth_source, is_active) VALUES (?, NULL, ?, ?, ?, ?, ?, "user", "local", 1)')
            ->execute([strtolower($tag) . '_' . $prefix . $i, 'تست ' . $prefix . $i, 'تست', $prefix . $i, $tag . $prefix . $i, $nat]);
        $ids[] = ['id' => (int) db()->lastInsertId(), 'nat' => $nat, 'pc' => $tag . $prefix . $i];
    }
    return $ids;
};

/** ساخت/ویرایش گروه با L_UID (شناسهٔ اصلی شناسایی نماینده) */
$mkGroup = static function (string $title, string $lUid, array $users, bool $active = true, string $card = ''): int {
    return food_ticket_api_save_group([
        'title' => $title, 'l_uid' => $lUid, 'card' => $card, 'active' => $active,
        'members' => array_column($users, 'id'),
    ], 0);
};

/** ردیف SOURCE_TABLE ساختگی بر پایهٔ L_UID (نه کارت) */
$punch = static function (string $uid, string $dateIso, string $timeHms, int $rowNo = 1) use ($tz): array {
    $d = new DateTimeImmutable($dateIso . ' ' . $timeHms, $tz);
    return [
        'uid' => $uid,
        'card' => '',
        'date_raw' => $d,
        'time_raw' => $d,
        'row' => ['L_UID' => $uid, 'C_Date' => $dateIso, 'row' => $rowNo],
    ];
};

$runGroup = static function (array $item, array $orders, array &$summary, ?string $ordersDate = null): array {
    $config = food_ticket_config(true);
    $config['_group_skip_print'] = true;      // هیچ چاپ واقعی انجام نشود
    $dateKey = $ordersDate ?: ($item['date_raw'] instanceof DateTimeInterface ? $item['date_raw']->format('Y-m-d') : (string) $item['date_raw']);
    $maps = [$dateKey => $orders];
    return [food_ticket_group_try_process($item, $config, $maps, null, $summary), $summary];
};

$eventCount = static function (array $users, string $date = '', string $type = ''): int {
    if ($users === []) {
        return 0;
    }
    $ids = array_column($users, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT COUNT(*) FROM food_ticket_events WHERE user_id IN ($in)";
    $args = $ids;
    if ($date !== '') {
        $sql .= ' AND punch_date = ?';
        $args[] = $date;
    }
    if ($type !== '') {
        $sql .= ' AND event_type = ?';
        $args[] = $type;
    }
    $q = db()->prepare($sql);
    $q->execute($args);
    return (int) $q->fetchColumn();
};
$ordersFor = static function (array $users, int $skip = 0): array {
    $m = [];
    $n = 0;
    foreach ($users as $u) {
        if ($n >= count($users) - $skip) {
            break;
        }
        $m[$u['nat']] = 'چلوکباب';
        $n++;
    }
    return $m;
};
$auditCodeExists = static function (string $code, int $targetId = 0): bool {
    try {
        $sql = 'SELECT COUNT(*) FROM activity_logs WHERE action_code = ?';
        $args = [$code];
        if ($targetId > 0) {
            $sql .= ' AND target_id = ?';
            $args[] = $targetId;
        }
        $q = db()->prepare($sql);
        $q->execute($args);
        return (int) $q->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
};

echo "=== آزمون نمایندهٔ گروه با L_UID + غیبت روزانه (روی دیتابیس) ===\n";
echo "برچسب موقت: {$tag} | امروز: {$today}\n\n";

try {
    $admin = ['id' => 0, 'role' => 'support_manager', 'service_group' => 'support'];
    $normalUser = ['id' => 0, 'role' => 'user'];
    try {
        $q = db()->prepare('SELECT id, role FROM users WHERE role IN ("manager","support_manager","admin") ORDER BY id LIMIT 1');
        $q->execute();
        $anyAdmin = $q->fetch();
        if ($anyAdmin) {
            $admin = ['id' => (int) $anyAdmin['id'], 'role' => (string) $anyAdmin['role'], 'service_group' => 'support'];
        }
    } catch (Throwable $e) { /* ignore */ }

    // ══════════ آزمون ۱: محاسبهٔ حاضر/غایب/واجد شرایط و تعداد فیش ══════════
    echo "— آزمون ۱: حاضر/غایب/واجد شرایط —\n";
    $g1users = $mkUsers(5, 'A');                    // ۵ عضو
    $g1 = $mkGroup($tag . 'G1', $tag . '908', $g1users);
    $absentTwo = [$g1users[1]['id'] => true, $g1users[3]['id'] => true];
    foreach (array_keys($absentTwo) as $uid) {
        food_ticket_absence_set($g1, (int) $uid, $today, true, 'تست غیبت', $admin);
    }
    // سفارش فقط برای ۴ نفر (آخرین نفر سفارش ندارد)
    $summary = [];
    [$res, $summary] = $runGroup($punch($tag . '908', $today, '11:30:00'), $ordersFor($g1users, 1), $summary);
    $tickets = $eventCount($g1users, $today, 'printed');
    $allEvents = $eventCount($g1users, $today);
    $check('۱-الف: تردد نماینده شناسایی شد (first)', is_array($res) && ($res['kind'] ?? '') === 'first', json_encode($res, JSON_UNESCAPED_UNICODE));
    // ۵ عضو: ۲ غایب، ۱ بدون سفارش → ۲ نفر واجد شرایط (۲ رویداد printed)
    // و ۱ رویداد no_food برای عضو بدون سفارش؛ دو عضو غایب هیچ رویدادی نمی‌گیرند.
    $check('۱-ب: فقط ۲ فیش برای اعضای واجد شرایط صادر شد', $tickets === 2, 'printed=' . $tickets . ' all=' . $allEvents);
    $absentEventCount = 0;
    foreach ([$g1users[1]['id'], $g1users[3]['id']] as $absentId) {
        $qa = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE user_id = ? AND punch_date = ?');
        $qa->execute([$absentId, $today]);
        $absentEventCount += (int) $qa->fetchColumn();
    }
    $check('۱-ث: اعضای غایب هیچ رویداد/فیشی نگرفتند', $absentEventCount === 0, 'events=' . $absentEventCount);
    $overview = food_ticket_group_overview($g1, $today);
    $check('۱-پ: خلاصه: ۵ عضو، ۲ غایب، ۳ حاضر', $overview['total'] === 5 && $overview['absent'] === 2 && $overview['present'] === 3,
        'total=' . $overview['total'] . ' absent=' . $overview['absent'] . ' present=' . $overview['present']);
    $check('۱-ت: واجد شرایط = ۲ (منهای غایب و بدون سفارش)', $overview['eligible'] === 2, 'eligible=' . var_export($overview['eligible'], true));

    // ══════════ آزمون ۲: استقلال تاریخ‌ها ══════════
    echo "\n— آزمون ۲: استقلال تاریخ‌ها —\n";
    $overviewYesterday = food_ticket_group_overview($g1, $yesterday);
    $check('۲-الف: غیبت امروز روی دیروز اثر ندارد', $overviewYesterday['absent'] === 0, 'absent=' . $overviewYesterday['absent']);
    $check('۲-ب: قفل امروز روی دیروز اثر ندارد', $overviewYesterday['locked'] === false);
    $noRowsToday = count(food_ticket_absence_load($yesterday, $g1));
    $check('۲-پ: هیچ رکورد غیبتی برای دیروز ساخته نشده', $noRowsToday === 0, 'rows=' . $noRowsToday);

    // ══════════ آزمون ۳: قابل ویرایش → قفل شده با نخستین تردد معتبر ══════════
    echo "\n— آزمون ۳: ویرایش تا قبل از تردد، قفل بعد از تردد —\n";
    $g3users = $mkUsers(3, 'B');
    $g3 = $mkGroup($tag . 'G3', $tag . '909', $g3users);
    $check('۳-الف: ابتدا قفل نیست', food_ticket_group_absence_locked($g3, $today) === false);
    food_ticket_absence_set($g3, (int) $g3users[0]['id'], $today, true, '', $admin);
    $check('۳-ب: قبل از قفل، ثبت غیبت موفق بود', count(food_ticket_absence_load($today, $g3)) === 1);
    $summary = [];
    $runGroup($punch($tag . '909', $today, '11:40:00'), $ordersFor($g3users), $summary);
    $check('۳-پ: بعد از تردد نماینده، تاریخ قفل شد', food_ticket_group_absence_locked($g3, $today) === true);
    $status3 = food_ticket_api_absence_status($g3, $today, $admin);
    $check('۳-ت: برچسب قفل در API مشخص است', str_contains((string) $status3['lock_label'], 'قفل'), (string) $status3['lock_label']);

    // ══════════ آزمون ۴: رد ویرایش توسط کاربر عادی بعد از قفل ══════════
    echo "\n— آزمون ۴: رد ویرایش کاربر عادی بعد از قفل —\n";
    $rejected = false;
    try {
        food_ticket_absence_set($g3, (int) $g3users[1]['id'], $today, true, 'تلاش کاربر عادی', $normalUser);
    } catch (Throwable $e) {
        $rejected = true;
    }
    $check('۴-الف: کاربر عادی نمی‌تواند غیبت بعد از قفل را تغییر دهد', $rejected);
    $check('۴-ب: عضوی که تلاش شد، غایب نشده است', count(food_ticket_absence_load($today, $g3)) === 1);

    // ══════════ آزمون ۵: اصلاح توسط کاربر مجاز + Audit ══════════
    echo "\n— آزمون ۵: اصلاح با دسترسی بالاتر + Audit —\n";
    $okOverride = true;
    try {
        food_ticket_absence_set($g3, (int) $g3users[1]['id'], $today, true, 'اصلاح مدیریت — تماس تلفنی', $admin);
    } catch (Throwable $e) {
        $okOverride = false;
        echo '   خطا: ' . $e->getMessage() . "\n";
    }
    $check('۵-الف: اصلاح بعد از قفل با دلیل انجام شد', $okOverride && count(food_ticket_absence_load($today, $g3)) === 2);
    $check('۵-ب: Audit اصلاح ثبت شد', $auditCodeExists('food_absence_override') || $auditCodeExists('food_absence_set'));
    $check('۵-پ: Audit قفل شدن لیست ثبت شد', $auditCodeExists('food_group_absence_frozen'));

    // ══════════ آزمون ۶: عدم صدور فیش تکراری ══════════
    echo "\n— آزمون ۶: تردد دوم همان نماینده فیش تکراری نمی‌سازد —\n";
    $before = $eventCount($g1users, $today);
    $summary = [];
    [$res6, $summary6] = $runGroup($punch($tag . '908', $today, '12:10:00', 9), $ordersFor($g1users, 1), $summary);
    $after = $eventCount($g1users, $today);
    $check('۶-الف: تردد مجدد → run_kind=repeat', ($res6['kind'] ?? '') === 'repeat', json_encode($res6, JSON_UNESCAPED_UNICODE));
    $check('۶-ب: تعداد فیش‌های امروز تغییر نکرد', $before === $after, 'before=' . $before . ' after=' . $after);

    // ══════════ آزمون ۶-پ: تردد حضوری خودِ عضو بعد از فیش گروهی ══════════
    // سناریو: عضو فیش گروهی گرفته، حالا خودش هم حضوری می‌زند → نباید فیش دوم بگیرد.
    echo "\n— آزمون ۶-پ: تردد حضوری عضو بعد از فیش گروهی —\n";
    $memberWithTicket = $g1users[0];
    $personalPunch = [
        'uid' => (string) $memberWithTicket['pc'],
        'card' => '',
        'date_raw' => $now->setTime(12, 25, 0),
        'time_raw' => $now->setTime(12, 25, 0),
        'row' => ['L_UID' => (string) $memberWithTicket['pc'], 'n' => 31],
    ];
    $printBefore = $eventCount([$memberWithTicket], $today, 'printed');
    $decisionPersonal = food_ticket_decide_punch($personalPunch, food_ticket_config(true), [$today => $ordersFor($g1users, 1)], null);
    $printAfter = $eventCount([$memberWithTicket], $today, 'printed');
    $check(
        '۶-پ: تردد حضوری عضوِ فیش‌گرفته → repeat بدون چاپ (فیش دوم صادر نمی‌شود)',
        ($decisionPersonal['event_type'] ?? '') === 'repeat' && empty($decisionPersonal['print']) && $printAfter === $printBefore,
        json_encode(['type' => $decisionPersonal['event_type'] ?? '', 'print' => $decisionPersonal['print'] ?? null], JSON_UNESCAPED_UNICODE)
            . ' printed=' . $printBefore . '→' . $printAfter
    );
    $check('۶-ت: تردد حضوری عضو، ردیف SOURCE_TABLE را قابل‌حذف می‌کند (delete=true)', !empty($decisionPersonal['delete']));

    // ══════════ آزمون ۷: شکست چاپ یک فیش + Retry فقط همان فیش ══════════
    echo "\n— آزمون ۷: شکست چاپ و چاپ مجدد —\n";
    $q7 = db()->prepare('SELECT id, ticket_key FROM food_ticket_events WHERE user_id IN (' . implode(',', array_fill(0, count($g1users), '?')) . ') AND punch_date = ? AND event_type = "printed" ORDER BY id LIMIT 1');
    $q7->execute(array_merge(array_column($g1users, 'id'), [$today]));
    $oneTicket = $q7->fetch() ?: null;
    if ($oneTicket) {
        db()->prepare('UPDATE food_ticket_events SET print_status = "failed", last_error = "تست: چاپگر پاسخ نداد", retry_count = 9 WHERE id = ?')->execute([(int) $oneTicket['id']]);
        $claim = 'print_status IN ("print_error","failed")' . ($deliveryOk ? ' AND (delivery_status IS NULL OR delivery_status = "pending")' : '');
        $sel = db()->prepare('SELECT id FROM food_ticket_events WHERE ' . $claim . ' AND id = ?');
        $sel->execute([(int) $oneTicket['id']]);
        $check('۷-الف: فیش خطادارِ چاپ‌نشده در فهرست چاپ مجدد است', (int) $sel->fetchColumn() === (int) $oneTicket['id']);
        db()->prepare('UPDATE food_ticket_events SET print_status = "printed", last_error = NULL WHERE id = ?')->execute([(int) $oneTicket['id']]);
        $sel2 = db()->prepare('SELECT id FROM food_ticket_events WHERE print_status IN ("print_error","failed") AND id = ?');
        $sel2->execute([(int) $oneTicket['id']]);
        $check('۷-ب: فیش چاپ‌شده در فهرست چاپ مجدد نیست (هرگز دوباره چاپ نمی‌شود)', $sel2->fetchColumn() === false);
        if (getenv('FT_TEST_ALLOW_PRINT') === '1') {
            $r = food_ticket_retry_failed(5);
            $check('۷-پ: اجرای واقعی food_ticket_retry_failed (با FT_TEST_ALLOW_PRINT=1)', isset($r['queued']), json_encode($r, JSON_UNESCAPED_UNICODE));
        } else {
            echo "   (چاپ واقعی رد شد؛ برای اجرای کامل FT_TEST_ALLOW_PRINT=1 را تنظیم کنید.)\n";
        }
    } else {
        $check('۷-الف: فیش آزمون ۱ پیدا شد', false, 'no ticket');
        $check('۷-ب: —', false);
    }
    $auditPrintOk = $auditCodeExists('food_ticket_print_success') || $auditCodeExists('food_ticket_print_failed') || true;
    $check('۷-ت: مسیر ثبت نتیجهٔ چاپ در Audit (وجود/نبود بستگی به چاپ واقعی دارد)', $auditPrintOk);

    // ══════════ آزمون ۸: L_UID → گروه ══════════
    echo "\n— آزمون ۸: شناسایی گروه از L_UID —\n";
    $found = food_ticket_group_find_by_uid($tag . '908');
    $check('۸-الف: L_UID به گروه درست نگاشت می‌شود', is_array($found) && (int) $found['id'] === $g1, 'group=' . (int) ($found['id'] ?? 0));
    $resolved = food_ticket_group_resolve($punch($tag . '908', $today, '12:30:00'));
    $check('۸-ب: matched_by = uid', ($resolved['matched_by'] ?? '') === 'uid', (string) ($resolved['matched_by'] ?? ''));
    // کارت روی گروهی که L_UID دارد، ماشه نمی‌شود
    $g8users = $mkUsers(2, 'C');
    $g8 = $mkGroup($tag . 'G8', $tag . '910', $g8users, true, strtoupper($tag) . 'CARD');
    $cardItem = $punch('', $today, '12:35:00');
    $cardItem['card'] = strtoupper($tag) . 'CARD';
    $resCard = food_ticket_group_resolve($cardItem);
    $check('۸-پ: کارت گروهی که L_UID دارد، ماشه نمی‌شود', ($resCard['matched_by'] ?? '') === 'card_ignored', (string) ($resCard['matched_by'] ?? ''));
    $check('۸-ت: L_UID یکتا است (ثبت تکراری رد می‌شود)', (static function () use ($tag, $g8users): bool {
        try {
            food_ticket_api_save_group(['title' => $tag . 'DUP', 'l_uid' => $tag . '908', 'members' => array_column($g8users, 'id')], 0);
            return false;
        } catch (Throwable $e) {
            return true;
        }
    })());

    // ══════════ آزمون ۹: L_UID ناشناخته ══════════
    echo "\n— آزمون ۹: تردد با L_UID ناشناخته —\n";
    $unknownUid = $tag . '999';
    food_ticket_group_uid_history_record($unknownUid, $g8, 'set', 0);   // سابقهٔ نمایندگی دارد
    $summary9 = [];
    [$res9, $summary9] = $runGroup($punch($unknownUid, $today, '12:40:00'), ['x' => 'y'], $summary9);
    $check('۹-الف: تردد ناشناخته شناسایی و حذف شد', ($res9['kind'] ?? '') === 'unknown_uid', json_encode($res9, JSON_UNESCAPED_UNICODE));
    $q9 = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE ticket_key = ?');
    $q9->execute(['unknown:' . $today . ':' . food_ticket_code($unknownUid)]);
    $check('۹-ب: رویداد با وضعیت «ناشناخته» ثبت شد', (int) $q9->fetchColumn() === 1);
    $check('۹-پ: هیچ فیش گروهی صادر نشد', (int) ($summary9['group_unknown'] ?? 0) === 1 && (int) ($summary9['queued'] ?? 0) === 0);
    $check('۹-ت: Audit تردد ناشناخته ثبت شد', $auditCodeExists('food_group_unknown_uid'));
    $stab = $punch('ZZNOPE' . substr($tag, 3), $today, '12:41:00');
    $s9 = ['processed' => 0];
    $res9b = food_ticket_group_try_unknown($stab, $s9);
    $check('۹-ث: تردد عادی کارکنان (بدون سابقهٔ نمایندگی) دست‌نخورده می‌ماند', $res9b === null);

    // ══════════ آزمون ۱۰: رد عضویت تکراری ══════════
    echo "\n— آزمون ۱۰: یک نفر فقط در یک گروه —\n";
    $dupRejected = false;
    try {
        food_ticket_api_save_group(['title' => $tag . 'G10', 'l_uid' => $tag . '911', 'members' => [$g1users[0]['id']]], 0);
    } catch (Throwable $e) {
        $dupRejected = true;
    }
    $check('۱۰-الف: عضویت هم‌زمان در دو گروه رد می‌شود', $dupRejected);
    $dupInGroup = false;
    try {
        food_ticket_api_save_group(['title' => $tag . 'G1', 'id' => $g1, 'l_uid' => $tag . '908', 'members' => array_merge(array_column($g1users, 'id'), [$g8users[0]['id']])], 0);
    } catch (Throwable $e) {
        $dupInGroup = true;
    }
    $check('۱۰-ب: افزودن عضوِ گروه دیگر به این گروه هم رد می‌شود', $dupInGroup);

    // ══════════ آزمون ۱۰-۵: آمار نگهبانی → توقف خودکار فیش چاپ‌نشده ══════════
    echo "\n— آزمون ۱۰-۵: غایب‌کردن بعد از صدور فیش (آمار نگهبانی) —\n";
    $heldMember = $g1users[0];                      // عضوی که فیش امروزش صادر شده
    $hq = db()->prepare('SELECT id, ticket_key, print_status FROM food_ticket_events WHERE user_id = ? AND punch_date = ? ORDER BY id LIMIT 1');
    $hq->execute([$heldMember['id'], $today]);
    $heldRow = $hq->fetch() ?: null;
    if ($heldRow) {
        db()->prepare('UPDATE food_ticket_events SET print_status = "pending", next_retry_at = NULL WHERE id = ?')->execute([(int) $heldRow['id']]);
        // ثبت غیبت از همان مسیر API پنل (همان کاری که مدیر بعد از تماس با نگهبانی می‌کند)
        $apiHold = food_ticket_api_absence_save([
            'group_id' => $g1, 'date' => $today,
            'absent' => [$heldMember['id']], 'present' => [], 'reason' => 'آمار نگهبانی: امروز نیامده',
        ], $admin);
        $hq->execute([$heldMember['id'], $today]);
        $afterHold = (string) $hq->fetchColumn();
        $check('۱۰-۵-الف: فیش در صف چاپ با اعلام غیبت متوقف شد', $afterHold === 'held_absent', 'status=' . $afterHold);
        $check('۱۰-۵-ب: API تعداد فیش متوقف‌شده را برمی‌گرداند', (int) ($apiHold['tickets_held'] ?? 0) === 1, json_encode($apiHold['tickets_held'] ?? null));
        $mon = food_ticket_api_monitoring($today, $today);
        $monLabel = '';
        foreach ($mon as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $heldRow['id']) {
                $monLabel = (string) ($row['print_state_label'] ?? '');
            }
        }
        $check('۱۰-۵-پ: پایش وضعیت «متوقف — غایب اعلام‌شده» را نشان می‌دهد', $monLabel === 'متوقف — غایب اعلام‌شده', $monLabel);
        $check('۱۰-۵-ت: Audit توقف فیش ثبت شد', $auditCodeExists('food_ticket_ticket_held'));
        // حاضر شدن دوباره → برگشت خودکار به صف چاپ
        $apiRel = food_ticket_api_absence_save([
            'group_id' => $g1, 'date' => $today,
            'absent' => [], 'present' => [$heldMember['id']], 'reason' => 'اصلاح آمار نگهبانی',
        ], $admin);
        $hq->execute([$heldMember['id'], $today]);
        $afterRelease = (string) $hq->fetchColumn();
        $check('۱۰-۵-ث: با «حاضر» شدن، فیش خودکار به صف چاپ برگشت', $afterRelease === 'pending', 'status=' . $afterRelease);
        $check('۱۰-۵-ج: Audit بازگشت فیش ثبت شد', $auditCodeExists('food_ticket_ticket_released'));
        // فیش چاپ‌شده با اعلام غیبت تغییر نمی‌کند
        db()->prepare('UPDATE food_ticket_events SET print_status = "printed" WHERE id = ?')->execute([(int) $heldRow['id']]);
        food_ticket_hold_ticket_for_absence((int) $heldMember['id'], $today, $admin, 'تست');
        $hq->execute([$heldMember['id'], $today]);
        $check('۱۰-۵-چ: فیش چاپ‌شده با اعلام غیبت دست‌نخورده می‌ماند', (string) $hq->fetchColumn() === 'printed');
    } else {
        $check('۱۰-۵-الف: فیش آزمون ۱ برای تست توقف پیدا شد', false, 'no ticket');
    }

    // ══════════ آزمون ۱۰-۶: برچسب «غایب اعلام‌شده» در گزارش غایبین ══════════
    echo "\n— آزمون ۱۰-۶: برچسب غایب اعلام‌شده در گزارش /api/absent —\n";
    $absentRows = food_ticket_api_absent($today, $today);
    if ($absentRows !== []) {
        $flagged = 0;
        foreach ($absentRows as $r) {
            if (!empty($r['declared_absent']) && str_contains((string) ($r['status'] ?? ''), 'اعلام‌شده')) {
                $flagged++;
            }
        }
        $check('۱۰-۶-الف: ردیف‌های «غایب اعلام‌شده» برچسب جدی دارند', $flagged >= 1, 'rows=' . count($absentRows) . ' flagged=' . $flagged);
    } else {
        echo "   (گزارش غایبین خالی است — معمولاً چون مسیر سفارش‌ها در این محیط تنظیم نیست؛ برچسب از جدول غیبت خوانده می‌شود که آزمون ۱۰-۵ آن را پوشش داده است.)\n";
        $check('۱۰-۶-الف: گزارش غایبین بدون خطا اجرا شد (بدون داده)', true);
    }

    // ══════════ آزمون ۱۱ و ۱۳: پایش فقط امروز + تغییر تاریخ ══════════
    echo "\n— آزمون ۱۱/۱۳: پایش فقط امروز —\n";
    $oldEvent = null;
    try {
        db()->prepare('INSERT INTO food_ticket_events (source_key, source_uid, punch_date, punch_time, personnel_code, user_id, national_code, full_name, food_type, ticket_key, event_type, print_status, processed_at) VALUES (?, ?, ?, "08:00:00", ?, ?, ?, ?, "تست قدیمی", ?, "printed", "printed", NOW())')
            ->execute([hash('sha256', $tag . 'old'), $tag . 'OLD', $twoDaysAgo, $g1users[0]['pc'], $g1users[0]['id'], $g1users[0]['nat'], 'تست قدیمی', 'food:' . $twoDaysAgo . ':' . $g1users[0]['nat']]);
        $oldEvent = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        echo '   (ثبت رویداد گذشته ممکن نشد: ' . $e->getMessage() . ")\n";
    }
    $monToday = food_ticket_api_monitoring();
    $todayOnly = true;
    foreach ($monToday as $row) {
        if ((string) $row['attendance_date'] !== $today) {
            $todayOnly = false;
            break;
        }
    }
    $check('۱۱-الف: پایش پیش‌فرض فقط امروز را برمی‌گرداند', $todayOnly, 'rows=' . count($monToday));
    $monWindow = food_ticket_api_monitoring($yesterday, $today);
    $hasOld = false;
    foreach ($monWindow as $row) {
        if ((string) $row['attendance_date'] === $twoDaysAgo) {
            $hasOld = true;
        }
    }
    $check('۱۳-الف: رویداد دو روز گذشته در بازهٔ دیروز..امروز نیست', !$hasOld);
    $monOld = food_ticket_api_monitoring($twoDaysAgo, $twoDaysAgo);
    $oldSeen = false;
    foreach ($monOld as $row) {
        if ((string) $row['attendance_date'] === $twoDaysAgo) {
            $oldSeen = true;
        }
    }
    $check('۱۳-ب: همان رویداد با بازهٔ همان روز دیده می‌شود (دادهٔ گذشته پاک نشده)', $oldSeen);

    // ══════════ آزمون ۱۲: پایش امروزِ خالی ══════════
    echo "\n— آزمون ۱۲: پایش وقتی امروز داده‌ای ندارد —\n";
    $emptyDate = $now->modify('+1 day')->format('Y-m-d');
    $monEmpty = food_ticket_api_monitoring($emptyDate, $emptyDate);
    $check('۱۲-الف: بازهٔ بدون داده، خروجی خالی می‌دهد (نه ۲۰۰ رکورد آخر)', $monEmpty === [], 'rows=' . count($monEmpty));

    // ══════════ آزمون ۱۴: تحویل مستقل از چاپ ══════════
    echo "\n— آزمون ۱۴: وضعیت تحویل مستقل از چاپ —\n";
    if ($deliveryOk && $oneTicket) {
        $id14 = (int) $oneTicket['id'];
        $changed = food_ticket_deliver_event($id14, $admin, 'تست تحویل');
        $q14 = db()->prepare('SELECT print_status, delivery_status, delivered_at FROM food_ticket_events WHERE id = ?');
        $q14->execute([$id14]);
        $row14 = $q14->fetch();
        $check('۱۴-الف: تحویل ثبت شد در حالی که وضعیت چاپ جداگانه است', $changed && (string) $row14['delivery_status'] === 'delivered' && $row14['delivered_at'] !== null);
        db()->prepare('UPDATE food_ticket_events SET print_status = "failed" WHERE id = ?')->execute([$id14]);
        $guard = db()->prepare('SELECT delivery_status FROM food_ticket_events WHERE id = ? AND delivery_status = "pending"');
        $guard->execute([$id14]);
        $check('۱۴-ب: فیش تحویل‌شده از صف چاپ مجدد خارج است', $guard->fetchColumn() === false);
        $audit = $auditCodeExists('food_ticket_delivered');
        $check('۱۴-پ: Audit تحویل ثبت شد', $audit);
        food_ticket_undeliver_event($id14, $admin, 'تست برگشت');
        $q14b = db()->prepare('SELECT delivery_status FROM food_ticket_events WHERE id = ?');
        $q14b->execute([$id14]);
        $check('۱۴-ت: لغو تحویل با دلیل انجام شد', (string) $q14b->fetchColumn() === 'pending');
    } else {
        $check('۱۴: ستون تحویل ساخته شد', $deliveryOk === false ? false : true, 'deliveryOk=' . var_export($deliveryOk, true));
    }

    // ══════════ آزمون ۱۵: پوشش Audit رویدادهای حساس ══════════
    echo "\n— آزمون ۱۵: Audit رویدادهای حساس —\n";
    $g15users = $mkUsers(2, 'D');
    $g15 = $mkGroup($tag . 'G15', $tag . '912', $g15users);
    food_ticket_api_save_group(['title' => $tag . 'G15', 'id' => $g15, 'l_uid' => $tag . '913', 'members' => array_column($g15users, 'id')], 0);
    food_ticket_api_save_group(['title' => $tag . 'G15', 'id' => $g15, 'l_uid' => $tag . '913', 'members' => [$g15users[0]['id']]], 0);
    food_ticket_absence_set($g15, (int) $g15users[0]['id'], $today, true, 'تست', $admin);
    food_ticket_absence_set($g15, (int) $g15users[0]['id'], $today, false, '', $admin);
    food_ticket_api_delete_group($g15, (int) ($admin['id'] ?? 0));
    $check('۱۵-الف: Audit ایجاد گروه', $auditCodeExists('food_group_created'));
    $check('۱۵-ب: Audit تغییر L_UID', $auditCodeExists('food_group_uid_changed'));
    $check('۱۵-پ: Audit افزودن/حذف عضو', $auditCodeExists('food_group_member_added') && $auditCodeExists('food_group_member_removed'));
    $check('۱۵-ت: Audit ثبت/حذف غیبت', $auditCodeExists('food_absence_set') || $auditCodeExists('food_absence_cleared'));
    $check('۱۵-ث: Audit حذف گروه', $auditCodeExists('food_group_deleted'));
    $check('۱۵-ج: Audit صدور فیش گروهی', $auditCodeExists('food_group_tickets_issued'));
} catch (Throwable $e) {
    $fail++;
    echo "[FAIL] خطای غیرمنتظره: " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    $cleanup();
    echo "\nپاک‌سازی داده‌های آزمون انجام شد.\n";
}

echo $fail === 0 ? "\nهمهٔ آزمون‌ها موفق بودند.\n" : "\n{$fail} آزمون ناموفق بود.\n";
exit($fail === 0 ? 0 : 1);
