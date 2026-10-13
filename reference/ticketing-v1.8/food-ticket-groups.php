<?php
declare(strict_types=1);

/**
 * گروه‌های غذای گروهی — نمایندهٔ گروه با L_UID + غیبت روزانه
 * =============================================================================
 * معماری فعلی حفظ شده است و فقط منطق «شناسایی نماینده» و «واجد شرایط بودن اعضا»
 * توسعه یافته است:
 *
 *  - شناسایی نماینده: L_UID دستگاه حضور و غیاب (شناسهٔ اصلی).
 *        L_UID → GroupID → اعضای گروه → سفارش غذا → غیبت روز → افراد واجد شرایط → فیش
 *    کارت RFID (C_Card) دیگر مبنای شناسایی نماینده نیست؛ فقط برای گروه‌های
 *    ۱.۳۴: شمارهٔ کارت (C_CARD) به‌طور کامل از شناسایی نماینده حذف شد؛ فقط L_UID معیار است.
 *
 *  - غیبت روزانه: «غیبت» به‌صورت Exception ذخیره می‌شود (نبودِ رکورد = حاضر).
 *    رکوردها تاریخ‌محور هستند (absence_date) و هیچ Reset/Cron روزانه‌ای لازم نیست.
 *
 *  - قفل لیست غیبت: فقط تا قبل از ثبت تردد معتبر نماینده قابل ویرایش است؛
 *    بعد از آن، اصلاح فقط با مجوز food.groups_override و با ثبت Audit.
 *    ⚠️ هیچ قاعده‌ای بر اساس ساعت ۱۲ (یا هر ساعت دیگر) وجود ندارد.
 *
 *  - اجرای اول (first): اعضای واجد شرایط (سفارش دارد، غایب نیست، قبلاً فیش نگرفته)
 *    با همان کنترل‌های فعلی کاربر بررسی و فیش‌شان در صف چاپ فعلی قرار می‌گیرد.
 *  - اجرای مجدد همان نماینده در همان روز (repeat): فقط یک رکورد خلاصه؛ بدون
 *    بررسی اعضا، بدون Event کاربر و بدون چاپ (پس فیش تکراری صادر نمی‌شود).
 *  - گروه غیرفعال (inactive): فقط رکورد خلاصه.
 *
 *  چاپ، صف، Retry و Worker بدون تغییر از همان توابع فعلی استفاده می‌کنند.
 */

/** ثبت Audit برای عملیات حساس غذا (کاربر/اکشن/زمان/موجودیت/مقدار قبل و بعد). */
function food_ticket_audit(string $code, array $data = []): void
{
    if (!function_exists('activity_log')) {
        return;
    }
    $meta = $data['meta'] ?? [];
    if ($data['reason'] ?? null) {
        $meta['reason'] = (string) $data['reason'];
    }
    if (array_key_exists('old', $data)) {
        $meta['old'] = $data['old'];
    }
    if (array_key_exists('new', $data)) {
        $meta['new'] = $data['new'];
    }
    try {
        activity_log([
            'action_code' => $code,
            'module' => 'food',
            'target_type' => (string) ($data['target_type'] ?? 'food_group'),
            'target_id' => (int) ($data['target_id'] ?? 0),
            'target_label' => (string) ($data['target_label'] ?? ''),
            'user_id' => $data['user_id'] ?? null,
            'meta' => $meta,
        ]);
    } catch (Throwable $e) {
        error_log('[food-groups] audit failed: ' . $e->getMessage());
    }
}

/**
 * ساخت و مهاجرت جدول‌های گروه (idempotent).
 * تغییرات ۱.۳۲ (L_UID، نوع ماشه، شمارندهٔ غایب) این‌جا هم به‌صورت محافظت‌شده اعمال می‌شوند
 * تا بدون اجرای دستی Migration هم سیستم کار کند.
 */
function food_ticket_groups_ensure(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS food_ticket_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            l_uid VARCHAR(80) NULL,
            rfid_card VARCHAR(120) NULL,
            description VARCHAR(500) NULL,
            status ENUM("active","inactive") NOT NULL DEFAULT "active",
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_food_group_uid (l_uid),
            UNIQUE KEY uq_food_group_card (rfid_card),
            INDEX idx_food_group_status (status)
        )' . $engine);
        $membersBase = 'CREATE TABLE IF NOT EXISTS food_ticket_group_members (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_food_group_member_user (user_id),
            INDEX idx_food_group_member_group (group_id)';
        try {
            db()->exec($membersBase . ',
                CONSTRAINT fk_food_group_member_group FOREIGN KEY (group_id) REFERENCES food_ticket_groups(id) ON DELETE CASCADE,
                CONSTRAINT fk_food_group_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )' . $engine);
        } catch (Throwable $fkError) {
            // اگر کلید خارجی ممکن نبود، جدول بدون FK ساخته می‌شود؛ UNIQUE(user_id) همچنان یک‌گروهی بودن را تضمین می‌کند.
            error_log('[food-groups] members FK unavailable: ' . $fkError->getMessage());
            db()->exec($membersBase . ')' . $engine);
        }
        db()->exec('CREATE TABLE IF NOT EXISTS food_ticket_group_uid_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uid VARCHAR(80) NOT NULL,
            group_id INT UNSIGNED NULL,
            action ENUM("set","changed","removed") NOT NULL,
            actor_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_food_uid_history_uid (uid),
            INDEX idx_food_uid_history_group (group_id)
        )' . $engine);
        db()->exec('CREATE TABLE IF NOT EXISTS food_ticket_group_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NULL,
            group_title VARCHAR(190) NULL,
            rfid_card VARCHAR(120) NOT NULL DEFAULT "",
            trigger_kind ENUM("uid","card","legacy_card") NOT NULL DEFAULT "card",
            trigger_uid VARCHAR(80) NULL,
            source_key CHAR(64) NOT NULL,
            punch_date DATE NOT NULL,
            punch_time TIME NULL,
            run_kind ENUM("first","repeat","inactive") NOT NULL,
            status ENUM("processing","done") NOT NULL DEFAULT "done",
            members_total INT UNSIGNED NOT NULL DEFAULT 0,
            printed_count INT UNSIGNED NOT NULL DEFAULT 0,
            repeat_count INT UNSIGNED NOT NULL DEFAULT 0,
            no_food_count INT UNSIGNED NOT NULL DEFAULT 0,
            absent_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_food_group_run_source (source_key),
            INDEX idx_food_group_run_day (group_id, punch_date, run_kind)
        )' . $engine);
        food_ticket_groups_migrate();
        $ok = true;
    } catch (Throwable $e) {
        error_log('[food-groups] tables unavailable: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/**
 * اعمال تغییرات ۱.۳۲ روی نصب‌های موجود (هر تغییر جدا و بی‌خطر اجرا می‌شود).
 * روی نصب‌های تازه، ستون‌ها از قبل وجود دارند و این تابع کاری نمی‌کند.
 */
function food_ticket_groups_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // ۱.۳۷.۶ — db_table_columns() «نقشهٔ کلید=نام ستون» برمی‌گرداند؛ array_map روی *مقدارها*
    // (که true هستند) اعمال می‌شد و in_array همیشه false می‌داد ⇒ هر درخواست ALTER بی‌فایده و
    // لاگ پرخطا. این‌جا هر دو شکل (نقشه یا فهرست سادهٔ نام‌ها) به «فهرست نام‌های lowercase» تبدیل می‌شود.
    $columns = static function (string $table): array {
        if (!function_exists('db_table_columns')) {
            return [];
        }
        try {
            $raw = db_table_columns($table);
        } catch (Throwable) {
            return [];
        }
        $names = [];
        foreach ((array) $raw as $key => $value) {
            $name = is_string($value) && $value !== '' ? $value : (is_string($key) ? $key : '');
            if ($name !== '') {
                $names[] = strtolower(trim($name));
            }
        }
        return $names;
    };
    $step = static function (string $label, string $sql): void {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
            // ستون/ایندکس از قبل وجود دارد یا موتور اجازه نمی‌دهد؛ در لاگ ثبت می‌شود.
            error_log('[food-groups] migrate ' . $label . ': ' . $e->getMessage());
        }
    };

    $groupCols = $columns('food_ticket_groups');
    if ($groupCols !== [] && !in_array('l_uid', $groupCols, true)) {
        $step('groups.l_uid', 'ALTER TABLE food_ticket_groups ADD COLUMN l_uid VARCHAR(80) NULL AFTER title');
        $step('groups.uid-index', 'ALTER TABLE food_ticket_groups ADD UNIQUE KEY uq_food_group_uid (l_uid)');
        // کارت دیگر اجباری نیست (گروه‌های L_UID-محور کارت ندارند)
        $step('groups.card-nullable', 'ALTER TABLE food_ticket_groups MODIFY COLUMN rfid_card VARCHAR(120) NULL');
    }

    $runCols = $columns('food_ticket_group_runs');
    if ($runCols !== [] && !in_array('trigger_kind', $runCols, true)) {
        $step('runs.trigger_kind', 'ALTER TABLE food_ticket_group_runs ADD COLUMN trigger_kind ENUM("uid","card","legacy_card") NOT NULL DEFAULT "card" AFTER group_title');
        $step('runs.trigger_uid', 'ALTER TABLE food_ticket_group_runs ADD COLUMN trigger_uid VARCHAR(80) NULL AFTER trigger_kind');
    }
    if ($runCols !== [] && !in_array('absent_count', $runCols, true)) {
        $step('runs.absent_count', 'ALTER TABLE food_ticket_group_runs ADD COLUMN absent_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER no_food_count');
    }
}

// ───────────────────────── غیبت روزانه ─────────────────────────

/** ساخت جدول غیبت روزانه (idempotent). */
function food_ticket_absence_ensure(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS food_ticket_daily_absence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            absence_date DATE NOT NULL,
            group_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            reason VARCHAR(300) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_food_absence_day_user (absence_date, user_id),
            INDEX idx_food_absence_group_day (group_id, absence_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ok = true;
    } catch (Throwable $e) {
        error_log('[food-absence] table unavailable: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/**
 * رکوردهای غیبت یک تاریخ (اختیاری: محدود به یک گروه).
 * @return array<int,array<string,mixed>> کلید = user_id
 */
function food_ticket_absence_load(string $date, ?int $groupId = null): array
{
    if (!food_ticket_absence_ensure()) {
        return [];
    }
    try {
        if ($groupId !== null) {
            $q = db()->prepare('SELECT * FROM food_ticket_daily_absence WHERE absence_date = ? AND (group_id = ? OR group_id IS NULL)');
            $q->execute([$date, $groupId]);
        } else {
            $q = db()->prepare('SELECT * FROM food_ticket_daily_absence WHERE absence_date = ?');
            $q->execute([$date]);
        }
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $out[(int) $row['user_id']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[food-absence] load failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * آیا لیست غیبت این گروه در این تاریخ قفل شده است؟
 * شرط قفل: ثبت تردد معتبر نماینده = وجود یک اجرای «first» برای همان گروه در همان تاریخ.
 * ⚠️ هیچ شرط ساعتی (مثلاً ساعت ۱۲) در این تصمیم دخالت ندارد.
 */
function food_ticket_group_absence_locked(int $groupId, string $date): bool
{
    if ($groupId <= 0 || !food_ticket_groups_ensure()) {
        return false;
    }
    try {
        $q = db()->prepare('SELECT 1 FROM food_ticket_group_runs WHERE group_id = ? AND punch_date = ? AND run_kind = "first" LIMIT 1');
        $q->execute([$groupId, $date]);
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        error_log('[food-absence] lock check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * ثبت/حذف غیبت یک عضو برای یک تاریخ.
 * قاعده: قبل از قفل، ویرایش با مجوز عادی گروه؛ بعد از قفل فقط با مجوز override.
 * @param array<string,mixed> $actor کاربر انجام‌دهنده (id/role) برای Audit
 * @return array<string,mixed> وضعیت نهایی
 */
function food_ticket_absence_set(int $groupId, int $userId, string $date, bool $absent, string $reason, array $actor = []): array
{
    if (!food_ticket_absence_ensure()) {
        throw new RuntimeException('جدول غیبت روزانه ساخته نشد.');
    }
    if ($groupId <= 0 || $userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('گروه، کاربر یا تاریخ معتبر نیست.');
    }
    // عضو باید واقعاً عضو همین گروه باشد (غیبت فقط برای اعضای گروه معنا دارد).
    $m = db()->prepare('SELECT 1 FROM food_ticket_group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
    $m->execute([$groupId, $userId]);
    if (!$m->fetchColumn()) {
        throw new RuntimeException('این کاربر عضو این گروه نیست.');
    }

    $locked = food_ticket_group_absence_locked($groupId, $date);
    $override = false;
    if ($locked) {
        $override = food_ticket_can_override_absence($actor);
        if (!$override) {
            throw new RuntimeException('لیست غیبت این گروه برای این تاریخ قفل شده است (تردد نماینده ثبت شده). اصلاح فقط با دسترسی سطح بالاتر امکان دارد.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('برای اصلاح بعد از قفل، ثبت «دلیل اصلاح» الزامی است.');
        }
    }

    $current = db()->prepare('SELECT id FROM food_ticket_daily_absence WHERE absence_date = ? AND user_id = ? LIMIT 1');
    $current->execute([$date, $userId]);
    $rowId = (int) ($current->fetchColumn() ?: 0);
    $before = $rowId > 0;

    if ($absent) {
        if ($before) {
            db()->prepare('UPDATE food_ticket_daily_absence SET group_id = ?, reason = ?, created_by = ? WHERE id = ?')
                ->execute([$groupId, $reason !== '' ? mb_substr($reason, 0, 300) : null, (int) ($actor['id'] ?? 0) ?: null, $rowId]);
        } else {
            db()->prepare('INSERT INTO food_ticket_daily_absence (absence_date, group_id, user_id, reason, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$date, $groupId, $userId, $reason !== '' ? mb_substr($reason, 0, 300) : null, (int) ($actor['id'] ?? 0) ?: null]);
        }
    } elseif ($before) {
        db()->prepare('DELETE FROM food_ticket_daily_absence WHERE id = ?')->execute([$rowId]);
    }

    $code = $locked ? 'food_absence_override' : ($absent ? 'food_absence_set' : 'food_absence_cleared');
    food_ticket_audit($code, [
        'user_id' => $actor['id'] ?? null,
        'target_type' => 'food_absence',
        'target_id' => $userId,
        'target_label' => 'گروه ' . $groupId . ' / تاریخ ' . $date,
        'old' => $before ? 'absent' : 'present',
        'new' => $absent ? 'absent' : 'present',
        'reason' => $reason,
        'meta' => ['group_id' => $groupId, 'date' => $date, 'frozen' => $locked],
    ]);

    // اثر فوری روی فیش‌های همان روز: غایب → فیش چاپ‌نشده متوقف می‌شود؛ حاضر → فیش متوقف‌شده برمی‌گردد.
    $ticketAction = '';
    if (function_exists('food_ticket_hold_ticket_for_absence')) {
        if ($absent) {
            $ticketAction = food_ticket_hold_ticket_for_absence($userId, $date, $actor, $reason) ? 'held' : '';
        } else {
            $ticketAction = food_ticket_release_held_ticket($userId, $date, $actor, $reason) ? 'released' : '';
        }
    }
    return ['ok' => true, 'group_id' => $groupId, 'user_id' => $userId, 'date' => $date, 'absent' => $absent, 'locked' => $locked, 'override' => $override, 'ticket_action' => $ticketAction];
}

/** همهٔ اعضای گروه را برای یک تاریخ «حاضر» می‌کند (حذف رکوردهای غیبت همان تاریخ). */
function food_ticket_absence_clear_group(int $groupId, string $date, string $reason, array $actor = []): int
{
    if (!food_ticket_absence_ensure()) {
        throw new RuntimeException('جدول غیبت روزانه ساخته نشد.');
    }
    $locked = food_ticket_group_absence_locked($groupId, $date);
    if ($locked && !food_ticket_can_override_absence($actor)) {
        throw new RuntimeException('لیست غیبت قفل شده است؛ حذف گروهی غیبت‌ها مجاز نیست.');
    }
    if ($locked && trim($reason) === '') {
        throw new RuntimeException('برای اصلاح بعد از قفل، ثبت «دلیل اصلاح» الزامی است.');
    }
    $beforeIds = [];
    try {
        $bq = db()->prepare('SELECT user_id FROM food_ticket_daily_absence WHERE absence_date = ? AND group_id = ?');
        $bq->execute([$date, $groupId]);
        $beforeIds = array_map('intval', $bq->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[food-absence] clear lookup: ' . $e->getMessage());
    }
    $q = db()->prepare('DELETE FROM food_ticket_daily_absence WHERE absence_date = ? AND group_id = ?');
    $q->execute([$date, $groupId]);
    $n = $q->rowCount();
    // فیش‌های متوقف‌شدهٔ همین اعضا به صف چاپ برمی‌گردند (عضو دیگر غایب نیست)
    $releasedCount = 0;
    if (function_exists('food_ticket_release_held_ticket')) {
        foreach ($beforeIds as $uid) {
            if (food_ticket_release_held_ticket((int) $uid, $date, $actor, $reason)) {
                $releasedCount++;
            }
        }
    }
    if ($n > 0) {
        food_ticket_audit($locked ? 'food_absence_override' : 'food_absence_cleared', [
            'user_id' => $actor['id'] ?? null,
            'target_type' => 'food_group',
            'target_id' => $groupId,
            'target_label' => 'گروه ' . $groupId . ' / تاریخ ' . $date,
            'old' => $n . ' غایب',
            'new' => 'همه حاضر',
            'reason' => $reason,
            'meta' => ['group_id' => $groupId, 'date' => $date, 'cleared' => $n, 'frozen' => $locked, 'tickets_released' => $releasedCount],
        ]);
    }
    return $n;
}

/** تعداد فیش‌های متوقف‌شده (held_absent) اعضای یک گروه در یک تاریخ. */
function food_ticket_absence_held_count(int $groupId, string $date): int
{
    if ($groupId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 0;
    }
    if (!function_exists('food_ticket_events_ensure_hold_status') || !food_ticket_events_ensure_hold_status()) {
        return 0;
    }
    try {
        $members = food_ticket_group_members($groupId);
        if ($members === []) {
            return 0;
        }
        $ids = array_map('intval', array_column($members, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE punch_date = ? AND print_status = "held_absent" AND user_id IN (' . $in . ')');
        $q->execute(array_merge([$date], $ids));
        return (int) $q->fetchColumn();
    } catch (Throwable $e) {
        error_log('[food-absence] held count: ' . $e->getMessage());
        return 0;
    }
}

/** آیا کاربر مجاز به اصلاح غیبت بعد از قفل است؟ (مجوز صریح یا نقش‌های ارشد) */
function food_ticket_can_override_absence(array $user): bool
{
    if (function_exists('user_can')) {
        if (user_can($user, 'food.groups_override')) {
            return true;
        }
    }
    try {
        if (function_exists('food_ticket_is_primary_admin') && food_ticket_is_primary_admin($user)) {
            return true;
        }
        if (function_exists('food_ticket_is_support_manager') && food_ticket_is_support_manager($user)) {
            return true;
        }
    } catch (Throwable) {
        // نادیده: بررسی مجوز کافی است
    }
    return in_array((string) ($user['role'] ?? ''), ['supervisor', 'support_manager'], true);
}

// ───────────────────────── گروه‌ها: بارگذاری و شناسایی ─────────────────────────

/**
 * همهٔ گروه‌ها (فعال و غیرفعال)، با کش ۳۰ ثانیه‌ای.
 * @return list<array{id:int,title:string,l_uid:string,rfid_card:string,status:string}>
 */
function food_ticket_groups_load(bool $refresh = false): array
{
    static $groups = null;
    static $loadedAt = 0;
    if (!food_ticket_groups_ensure()) {
        return [];
    }
    if ($refresh || $groups === null || (time() - $loadedAt) > 30) {
        $groups = [];
        try {
            foreach (db()->query('SELECT id, title, l_uid, rfid_card, status FROM food_ticket_groups ORDER BY id')->fetchAll() as $row) {
                $groups[] = [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'l_uid' => food_ticket_code((string) ($row['l_uid'] ?? '')),
                    'rfid_card' => (string) ($row['rfid_card'] ?? ''),
                    'status' => (string) $row['status'],
                ];
            }
        } catch (Throwable $e) {
            error_log('[food-groups] load failed: ' . $e->getMessage());
        }
        $loadedAt = time();
    }
    return $groups;
}

/**
 * گروهی که L_UID نماینده‌اش با این مقدار برابر است (شناسهٔ اصلی شناسایی).
 * مقایسه با همان نرمال‌سازی کدها (۱۰۰ ≡ 0100 ≡ «۱۰۰»).
 */
function food_ticket_group_find_by_uid(string $uid, bool $refresh = false): ?array
{
    $uid = food_ticket_code($uid);
    if ($uid === '' || $uid === '0' || $uid === '-1') {
        return null;
    }
    foreach (food_ticket_groups_load($refresh) as $group) {
        if ($group['l_uid'] !== '' && $group['l_uid'] === $uid) {
            return $group;
        }
    }
    return null;
}

/**
 * گروهی که کارتش با C_Card برابر است — فقط پل سازگاری برای گروه‌های قدیمی.
 * گروه‌هایی که L_UID دارند از این مسیر شناسایی نمی‌شوند.
 */
function food_ticket_group_find_by_card(string $card, bool $refresh = false): ?array
{
    if ($card === '' || $card === '0') {
        return null;
    }
    foreach (food_ticket_groups_load($refresh) as $group) {
        if ($group['l_uid'] !== '') {
            continue; // شناسایی این گروه فقط با L_UID است
        }
        if (food_ticket_card_equals($card, (string) $group['rfid_card'])) {
            return $group;
        }
    }
    return null;
}

/**
 * شناسایی گروه از یک ردیف SOURCE_TABLE: اول L_UID، بعد (فقط برای گروه‌های بدون L_UID) کارت.
 * @param array<string,mixed> $item
 * @return array{group:array<string,mixed>,matched_by:string}|null
 */
function food_ticket_group_resolve(array $item): ?array
{
    $uid = food_ticket_code($item['uid'] ?? '');
    if ($uid !== '' && $uid !== '0' && $uid !== '-1') {
        $byUid = food_ticket_group_find_by_uid($uid);
        if ($byUid !== null) {
            return ['group' => $byUid, 'matched_by' => 'uid'];
        }
    }
    // ⚠️ شمارهٔ کارت (C_CARD) از شناسایی نمایندهٔ گروه کاملاً حذف شد (۱.۳۴).
    // ردیفی که فقط کارت دارد و L_UID ندارد، به هیچ گروهی نسبت داده نمی‌شود و
    // مسیر عادی موتور (کارت↔پرسنل با نگاشت داخلی) برایش اجرا می‌شود.
    return null;
}

/** گروهی که این کارت روی آن ثبت شده، حتی اگر L_UID داشته باشد (فقط برای لاگ). */
function food_ticket_group_find_by_card_any(string $card): ?array
{
    if ($card === '' || $card === '0') {
        return null;
    }
    foreach (food_ticket_groups_load() as $group) {
        if ((string) $group['rfid_card'] !== '' && food_ticket_card_equals($card, (string) $group['rfid_card'])) {
            return $group;
        }
    }
    return null;
}

/** همان بازهٔ مجاز موتور: دیروز تا امروز (به وقت تهران). */
function food_ticket_group_date_ok(string $date): bool
{
    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    } catch (Throwable) {
        $today = date('Y-m-d');
    }
    $minDate = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    return $date >= $minDate && $date <= $today;
}

function food_ticket_group_member_key(string $triggerKey, int $userId): string
{
    return hash('sha256', 'food-group-member|' . $triggerKey . '|' . $userId);
}

/** شناسهٔ رویدادهای ساخته‌شده از یک اجرای گروه را برای ثبت نتیجهٔ حذف SOURCE_TABLE برمی‌گرداند. */
function food_ticket_group_event_ids_for_run(int $runId): array
{
    if ($runId <= 0) {
        return [];
    }
    try {
        // source_payload هر فیش گروهی شامل "run_id":N است. الگوی دقیقِ ویرگول‌دار
        // از اشتباه‌گرفتن run id کوتاه با پیشوند run id بلندتر جلوگیری می‌کند.
        $needle = '%"run_id":' . $runId . ',%';
        $query = db()->prepare('SELECT id FROM food_ticket_events WHERE source_payload LIKE ? ORDER BY id');
        $query->execute([$needle]);
        $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    } catch (Throwable $e) {
        error_log('[food-groups] event lookup by run failed: ' . $e->getMessage());
        return [];
    }
}

// ───────────────────────── محاسبهٔ واجد شرایط (هستهٔ منطق، بدون DB) ─────────────────────────

/**
 * برنامهٔ فیش یک گروه = تابع خالص (بدون DB) تا قابل تست باشد.
 *
 * واجد شرایط بودن (با وضعیت‌های واقعی همین سیستم تطبیق داده شده):
 *   Eligible = فعال بودن کاربر
 *              AND غایب‌نبودن در غیبت روزانهٔ همان تاریخ
 *              AND داشتن کد ملی (کلید تطبیق سفارش)
 *              AND داشتن سفارش غذا برای همان تاریخ (سفارشِ لغوشده/خالی = ندارد)
 *              AND قبلاً فیش نگرفته/تحویل نگرفته بودن («یک فیش در روز برای هر نفر»)
 *
 * ترتیب تصمیم برای هر عضو (اولین موردی که برقرار بود، وضعیت اوست):
 *   inactive → absent → no_national → no_order → already_ticketed → eligible
 *
 * @param list<array<string,mixed>> $members        ردیف‌های users
 * @param array<int,bool>           $absentUserIds  کاربران غایب همان تاریخ
 * @param array<string,bool>        $ticketedKeys   کلیدهای فیش صادرشده (food:تاریخ:کدملی)
 * @param callable(array):?string   $foodFor        تابع «سفارش این کاربر برای این تاریخ»
 * @param string                    $date           تاریخ میلادی Y-m-d (برای ساخت کلید فیش)
 * @return array{rows:list<array<string,mixed>>,member_states:array<int,string>,total:int,present:int,absent:int,eligible:int,no_order:int,already_ticketed:int,inactive:int,no_national:int}
 */
function food_ticket_group_plan(array $members, array $absentUserIds, array $ticketedKeys, callable $foodFor, string $date = ''): array
{
    $rows = [];
    $counts = [
        'total' => 0, 'present' => 0, 'absent' => 0, 'eligible' => 0,
        'no_order' => 0, 'already_ticketed' => 0, 'inactive' => 0, 'no_national' => 0,
    ];
    foreach ($members as $member) {
        $userId = (int) ($member['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $counts['total']++;
        $national = food_ticket_digits($member['national_code'] ?? '');
        $ticketKey = $national !== '' ? ('food:' . $date . ':' . $national) : '';
        $food = '';
        if (!(int) ($member['is_active'] ?? 0)) {
            $state = 'inactive';
        } elseif (!empty($absentUserIds[$userId])) {
            $state = 'absent';
        } elseif ($national === '') {
            $state = 'no_national';
        } else {
            $looked = $foodFor($member);
            $food = is_string($looked) ? trim($looked) : '';
            if ($food === '') {
                $state = 'no_order';
            } elseif ($ticketKey !== '' && !empty($ticketedKeys[$ticketKey])) {
                $state = 'already_ticketed';
            } else {
                $state = 'eligible';
            }
        }
        $counts[$state]++;
        if ($state !== 'absent' && $state !== 'inactive') {
            $counts['present']++;
        }
        $rows[] = [
            'user_id' => $userId,
            'full_name' => (string) ($member['full_name'] ?? ''),
            'national_code' => $national,
            'employee_number' => (string) ($member['employee_number'] ?? ''),
            'state' => $state,
            'food' => $food,
            'ticket_key' => $ticketKey,
        ];
    }
    $states = [];
    foreach ($rows as $row) {
        $states[(int) $row['user_id']] = (string) $row['state'];
    }
    return ['rows' => $rows, 'member_states' => $states] + $counts;
}

/**
 * اعضای گروه از دیتابیس.
 * @return list<array<string,mixed>>
 */
function food_ticket_group_members(int $groupId, bool $activeOnly = false): array
{
    if ($groupId <= 0 || !food_ticket_groups_ensure()) {
        return [];
    }
    $sql = 'SELECT u.id, u.full_name, u.employee_number, u.national_code, u.is_active '
        . 'FROM food_ticket_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ?';
    if ($activeOnly) {
        $sql .= ' AND u.is_active = 1';
    }
    $sql .= ' ORDER BY m.id';
    $q = db()->prepare($sql);
    $q->execute([$groupId]);
    return $q->fetchAll() ?: [];
}

/**
 * کلیدهای فیش صادرشدهٔ امروزِ این اعضا (food:تاریخ:کدملی).
 * @param list<array<string,mixed>> $members
 * @return array<string,bool>
 */
function food_ticket_group_ticketed_keys(array $members, string $date): array
{
    $keys = [];
    foreach ($members as $m) {
        $nat = food_ticket_digits($m['national_code'] ?? '');
        if ($nat !== '') {
            $keys['food:' . $date . ':' . $nat] = true;
        }
    }
    if ($keys === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $args = array_keys($keys);
    try {
        $q = db()->prepare('SELECT ticket_key FROM food_ticket_events WHERE punch_date = ? AND ticket_key IN (' . $placeholders . ')');
        $q->execute(array_merge([$date], $args));
        $found = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $found[(string) $key] = true;
        }
        return $found;
    } catch (Throwable $e) {
        error_log('[food-groups] ticketed lookup failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * نقشهٔ سفارش یک تاریخ (کش ۳۰ ثانیه‌ای مشترک با موتور). در صورت خطا null برمی‌گرداند.
 * @return array<string,string>|null
 */
function food_ticket_group_order_map(array &$orderMaps, string $date): ?array
{
    if (array_key_exists($date, $orderMaps)) {
        return $orderMaps[$date];
    }
    try {
        $orderMaps[$date] = food_ticket_order_map_lazy(null, 'food_orders', $date);
        return $orderMaps[$date];
    } catch (Throwable $e) {
        error_log('[food-groups] order map failed: ' . $e->getMessage());
        // در مسیر داخلی، خطای MySQL نباید به «بدون سفارش» تبدیل شود.
        if (function_exists('food_order_mode') && food_order_mode() === 'INTERNAL-DB') {
            throw $e;
        }
        return null;
    }
}

/** تابع انتخاب سفارش یک عضو از نقشهٔ سفارش (کد ملی / بدون صفر ابتدایی). */
function food_ticket_group_food_lookup(?array $orderMap): callable
{
    return static function (array $member) use ($orderMap): ?string {
        if ($orderMap === null) {
            return null;
        }
        $national = food_ticket_digits($member['national_code'] ?? '');
        if ($national === '') {
            return null;
        }
        $food = $orderMap[$national] ?? $orderMap[ltrim($national, '0') ?: '0'] ?? null;
        return is_string($food) ? $food : null;
    };
}

/**
 * خلاصهٔ امروزِ یک گروه برای پنل: اعضا، حاضر، غایب، واجد شرایط، وضعیت قفل.
 * @return array<string,mixed>
 */
function food_ticket_group_overview(int $groupId, string $date, bool $withOrders = true): array
{
    $members = food_ticket_group_members($groupId);
    $absences = food_ticket_absence_load($date, $groupId);
    $absentIds = [];
    foreach (array_keys($absences) as $uid) {
        $absentIds[(int) $uid] = true;
    }
    $ticketed = food_ticket_group_ticketed_keys($members, $date);
    $orderMaps = [];
    $orderMap = null;
    $ordersAvailable = false;
    if ($withOrders) {
        // Compatibility parameter is null; internal-only map lookup never opens Access orders.
        $orderMap = food_ticket_group_order_map($orderMaps, $date);
        $ordersAvailable = $orderMap !== null;
    }
    $plan = food_ticket_group_plan($members, $absentIds, $ticketed, food_ticket_group_food_lookup($orderMap), $date);
    $memberStates = $plan['member_states'] ?? [];
    return [
        'date' => $date,
        'locked' => food_ticket_group_absence_locked($groupId, $date),
        'orders_available' => $ordersAvailable,
        'total' => $plan['total'],
        'present' => $plan['present'],
        'absent' => $plan['absent'],
        'eligible' => $ordersAvailable ? $plan['eligible'] : null,
        'no_order' => $ordersAvailable ? $plan['no_order'] : null,
        'already_ticketed' => $plan['already_ticketed'],
        'inactive' => $plan['inactive'],
        'no_national' => $plan['no_national'],
        'member_states' => $memberStates,
        'absent_user_ids' => array_keys($absentIds),
    ];
}

// ───────────────────────── پردازش تردد نماینده ─────────────────────────

/**
 * hook حلقهٔ موتور. اگر ردیف مربوط به گروه نبود null برمی‌گرداند (مسیر فعلی ادامه می‌یابد)؛
 * وگرنه ['delete' => bool] — آیا ردیف SOURCE_TABLE حذف شود.
 *
 * @param array<string,mixed> $item
 * @param array<string,mixed> $config
 * @param array<string,mixed> $orderMaps
 * @param array<string,mixed> $summary
 * @return array{delete:bool,kind:string,event_ids?:list<int>,delete_note?:string}|null
 *
 * نکته: اعضای «غایب» و «غیرفعال» هیچ رویدادی ثبت نمی‌کنند؛ برای بقیهٔ اعضا (واجد شرایط،
 * بدون سفارش، دارای فیش امروز، بدون کد ملی) تصمیم‌گیری و ثبت رویداد با همان
 * food_ticket_decide_for_user() موتور انجام می‌شود تا با مسیر تردد عادی یکسان باشد.
 */
function food_ticket_group_try_process(array $item, array $config, array &$orderMaps, array &$summary): ?array
{
    $resolved = food_ticket_group_resolve($item);
    if ($resolved === null) {
        return food_ticket_group_try_unknown($item, $summary);
    }
    $group = $resolved['group'];
    $matchedBy = $resolved['matched_by'];

    $date = food_ticket_parse_date($item['date_raw'] ?? null);
    $time = food_ticket_parse_time($item['time_raw'] ?? null);
    if ($date === null || !food_ticket_group_date_ok($date)) {
        return null; // تاریخ نامعتبر/قدیمی را همان مسیر فعلی (config_error / skipped_old) رسیدگی می‌کند
    }

    $groupId = (int) $group['id'];
    $triggerUid = food_ticket_code($item['uid'] ?? '');
    $triggerCard = (string) ($group['rfid_card'] ?? '');
    $sourceKey = food_ticket_source_key($item);

    // ۱) همین ردیف SOURCE_TABLE قبلاً دیده شده؟
    $q = db()->prepare('SELECT id, status, run_kind FROM food_ticket_group_runs WHERE source_key = ? LIMIT 1');
    $q->execute([$sourceKey]);
    $run = $q->fetch() ?: null;

    if ($run !== null) {
        if ((string) $run['status'] === 'done') {
            return [
                'delete' => true,
                'kind' => 'duplicate',
                'event_ids' => food_ticket_group_event_ids_for_run((int) ($run['id'] ?? 0)),
            ]; // حذف SOURCE_TABLE دور قبل ناموفق بود؛ وضعیت همهٔ فیش‌های همان اجرا هم به‌روز شود
        }
        $runId = (int) $run['id']; // processing: ادامهٔ اجرای ناتمام
        $kind = 'first';
    } else {
        // ۲) همان کشیدن فیزیکی کارت با ردیف دیگری از SOURCE_TABLE (≤ ۴ ثانیه) → بی‌صدا حذف
        if ($time !== null) {
            $t = db()->prepare(
                'SELECT id FROM food_ticket_group_runs WHERE group_id = ? AND punch_date = ? AND punch_time IS NOT NULL '
                . 'AND ABS(TIME_TO_SEC(punch_time) - TIME_TO_SEC(?)) <= 4 LIMIT 1'
            );
            $t->execute([$groupId, $date, $time]);
            if ($t->fetchColumn()) {
                return ['delete' => true, 'kind' => 'duplicate'];
            }
        }
        // ۳) نوع اجرا
        if ($group['status'] !== 'active') {
            $kind = 'inactive';
        } else {
            $f = db()->prepare('SELECT 1 FROM food_ticket_group_runs WHERE group_id = ? AND punch_date = ? AND run_kind = "first" LIMIT 1');
            $f->execute([$groupId, $date]);
            $kind = $f->fetchColumn() ? 'repeat' : 'first';
        }
        $membersTotal = count(food_ticket_group_members($groupId));
        db()->prepare(
            'INSERT INTO food_ticket_group_runs (group_id, group_title, rfid_card, trigger_kind, trigger_uid, source_key, punch_date, punch_time, run_kind, status, members_total) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $groupId, $group['title'], $triggerCard, 'uid',
            $triggerUid !== '' ? $triggerUid : null, $sourceKey, $date, $time, $kind,
            $kind === 'first' ? 'processing' : 'done', $membersTotal,
        ]);
        $runId = (int) db()->lastInsertId();

        // قفل لیست غیبت با اولین تردد معتبر نماینده (بدون هیچ شرط ساعتی)
        if ($kind === 'first') {
            food_ticket_audit('food_group_absence_frozen', [
                'target_type' => 'food_group',
                'target_id' => $groupId,
                'target_label' => (string) $group['title'],
                'new' => 'frozen',
                'meta' => ['group_id' => $groupId, 'date' => $date, 'trigger_uid' => $triggerUid, 'matched_by' => $matchedBy],
            ]);
        }
    }

    $summary['group_runs'] = (int) ($summary['group_runs'] ?? 0) + 1;
    $groupEventIds = [];

    // repeat / inactive: فقط خلاصه؛ اعضا بررسی نمی‌شوند و Event/چاپی ساخته نمی‌شود.
    if ($kind !== 'first') {
        $summary['skipped'] = (int) ($summary['skipped'] ?? 0) + 1;
        return ['delete' => true, 'kind' => $kind];
    }

    // اجرای اول: محاسبهٔ واجد شرایط‌ها و پردازش فقط همان‌ها
    $members = food_ticket_group_members($groupId);
    $absences = food_ticket_absence_load($date, $groupId);
    $absentIds = [];
    foreach (array_keys($absences) as $uid) {
        $absentIds[(int) $uid] = true;
    }
    $ticketed = food_ticket_group_ticketed_keys($members, $date);
    $orderMap = food_ticket_group_order_map($orderMaps, $date);
    $plan = food_ticket_group_plan($members, $absentIds, $ticketed, food_ticket_group_food_lookup($orderMap), $date);
    if (empty($plan['member_states']) && !empty($plan['rows'])) {
        foreach ($plan['rows'] as $row) {
            $plan['member_states'][(int) $row['user_id']] = (string) $row['state'];
        }
    }

    $counts = ['printed' => 0, 'repeat' => 0, 'no_food' => 0, 'skipped' => 0, 'error' => 0, 'absent' => $plan['absent']];
    $payloadJson = json_encode(
        [
            'group_id' => $groupId,
            'group_title' => $group['title'],
            'group_l_uid' => $group['l_uid'],
            'run_id' => $runId,
            'matched_by' => $matchedBy,
            'absent' => $plan['absent'],
            'eligible' => $plan['eligible'],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    foreach ($members as $user) {
        $userId = (int) $user['id'];
        $state = $plan['member_states'][$userId] ?? null;

        // غایب: هیچ غذایی نمی‌گیرد و هیچ رویداد فیشی برایش ثبت نمی‌شود
        // (سند غیبت، همان جدول غیبت روزانه است).
        if ($state === 'absent') {
            $counts['skipped']++;
            continue;
        }
        // اعضای غیرفعال از این مسیر پردازش نمی‌شوند (همان رفتار فعلی موتور برای تردد غیرفعال).
        if ($state === 'inactive') {
            $counts['skipped']++;
            continue;
        }

        // بقیهٔ اعضا (واجد شرایط / بدون سفارش / دارای فیش امروز / بدون کد ملی) با همان
        // تابع تصمیم فعلی موتور بررسی می‌شوند تا رفتار (printed/no_food/repeat) و ثبت
        // رویداد دقیقاً مانند مسیر تردد عادی باشد و «یک فیش در روز برای هر نفر» حفظ شود.
        try {
            $memberKey = food_ticket_group_member_key($sourceKey, $userId);
            $existingGroupEvent = food_ticket_existing_source($memberKey);
            if ($existingGroupEvent) {
                $existingId = (int) ($existingGroupEvent['id'] ?? 0);
                if ($existingId > 0) {
                    $groupEventIds[] = $existingId;
                }
                continue; // در تلاش قبلیِ همین اجرا پردازش شده؛ شناسه برای وضعیت حذف لازم است
            }
            $base = [
                'source_key' => $memberKey,
                'source_uid' => $triggerUid !== '' ? $triggerUid : null,
                'source_card' => null,
                'punch_date' => $date,
                'punch_time' => $time,
                'personnel_code' => food_ticket_code($user['employee_number'] ?? ''),
                'source_payload' => $payloadJson,
            ];
            $decision = food_ticket_decide_for_user($user, $date, $base, $config, $orderMaps);
            try {
                $eventId = food_ticket_add_event($decision['payload']);
            } catch (PDOException $pdoEx) {
                if (str_contains($pdoEx->getMessage(), 'Duplicate') || (int) $pdoEx->getCode() === 23000) {
                    $counts['repeat']++; // فیش همین کاربر در همین لحظه از مسیر دیگری ثبت شد
                    continue;
                }
                throw $pdoEx;
            }
            if ($eventId <= 0) {
                throw new RuntimeException('ثبت رویداد عضو گروه انجام نشد (user=' . $userId . ').');
            }
            $groupEventIds[] = $eventId;
            if (!empty($decision['print'])) {
                food_ticket_enqueue_print($eventId, $config);
                $counts['printed']++;
            } elseif ($decision['event_type'] === 'repeat') {
                $counts['repeat']++;
            } elseif ($decision['event_type'] === 'no_food') {
                $counts['no_food']++;
            } else {
                $counts['skipped']++; // غیرفعال و مانند آن
            }
        } catch (Throwable $ex) {
            $counts['error']++;
            $summary['last_error'] = $ex->getMessage();
            error_log('[food-groups] member failed run=' . $runId . ' user=' . $userId . ': ' . $ex->getMessage());
        }
    }

    // چاپ: همان صف فعلی، یک‌جا برای همهٔ اعضای صف‌شده
    if ($counts['printed'] > 0 && empty($config['_group_skip_print'])) {
        try {
            $early = food_ticket_process_print_queue_locked($config, max(30, $counts['printed']));
            $summary['queue_early_printed'] = (int) ($summary['queue_early_printed'] ?? 0) + (int) ($early['printed'] ?? 0);
            $summary['queue_early_failed'] = (int) ($summary['queue_early_failed'] ?? 0) + (int) ($early['failed'] ?? 0);
        } catch (Throwable $printEx) {
            error_log('[food-groups] print drain failed: ' . $printEx->getMessage());
        }
    }

    $allOk = $counts['error'] === 0;
    db()->prepare(
        'UPDATE food_ticket_group_runs SET printed_count = printed_count + ?, repeat_count = repeat_count + ?, no_food_count = no_food_count + ?, '
        . 'absent_count = ?, skipped_count = skipped_count + ?, error_count = error_count + ?, status = ? WHERE id = ?'
    )->execute([$counts['printed'], $counts['repeat'], $counts['no_food'], $counts['absent'], $counts['skipped'], $counts['error'], $allOk ? 'done' : 'processing', $runId]);

    food_ticket_audit('food_group_tickets_issued', [
        'target_type' => 'food_group',
        'target_id' => $groupId,
        'target_label' => (string) $group['title'],
        'new' => (string) $counts['printed'] . ' فیش',
        'meta' => [
            'group_id' => $groupId, 'run_id' => $runId, 'date' => $date,
            'members_total' => $plan['total'], 'present' => $plan['present'], 'absent' => $plan['absent'],
            'eligible' => $plan['eligible'], 'printed' => $counts['printed'], 'repeat' => $counts['repeat'],
            'no_order' => $counts['no_food'], 'skipped' => $counts['skipped'], 'error' => $counts['error'],
            'orders_available' => $orderMap !== null,
        ],
    ]);

    $summary['queued'] = (int) ($summary['queued'] ?? 0) + $counts['printed'];
    $summary['processed'] = (int) ($summary['processed'] ?? 0) + 1;
    $summary['repeat'] = (int) ($summary['repeat'] ?? 0) + $counts['repeat'];
    $summary['no_food'] = (int) ($summary['no_food'] ?? 0) + $counts['no_food'];
    $summary['absent'] = (int) ($summary['absent'] ?? 0) + $counts['absent'];
    $summary['errors'] = (int) ($summary['errors'] ?? 0) + $counts['error'];

    // اگر عضوی خطا داشت، ردیف SOURCE_TABLE می‌ماند تا دور بعد فقط همان‌ها دوباره پردازش شوند.
    // در حذف موفق یا ناموفق، موتور باید نتیجه را روی تمام فیش‌های ساخته‌شده از این اجرا ثبت کند.
    $groupEventIds = array_values(array_unique(array_filter(array_map('intval', $groupEventIds), static fn (int $id): bool => $id > 0)));
    return [
        'delete' => $allOk,
        'kind' => 'first',
        'event_ids' => $groupEventIds,
        'delete_note' => $allOk ? '' : 'حذف ردیف منبع تا رفع خطای پردازش عضو گروه به تعویق افتاد.',
    ];
}

/**
 * ── نگه‌داشتن فیشِ چاپ‌نشده به‌خاطر اعلام غیبت ─────────────────────────────────
 * سناریوی کاری: آمار نگهبانی گروه‌های بیرون از ساختمان وارد می‌شود و مدیر همان روز
 * یک یا چند عضو را «غایب» می‌زند. اگر فیش آن عضو در صف چاپ باشد (هنوز چاپ نشده)،
 * باید چاپ نشود؛ پس وضعیتش «held_absent» می‌شود:
 *   • از صف چاپ خارج است (claim query فقط pending/print_error/failed و printing کهنه را می‌گیرد)،
 *   • در چاپ مجدد خطاها هم نمی‌آید (آن مسیر فقط print_error/failed را می‌گیرد)،
 *   • فیشِ چاپ‌شده و در حال چاپ دست‌نخورده می‌ماند (لغو نمی‌شود).
 * اگر عضو بعداً «حاضر» شود، با food_ticket_release_held_ticket دوباره به صف برمی‌گردد.
 *
 * @return bool آیا فیشی نگه داشته شد؟
 */
function food_ticket_hold_ticket_for_absence(int $userId, string $date, array $actor = [], string $reason = ''): bool
{
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    if (!food_ticket_events_ensure_hold_status()) {
        return false;
    }
    try {
        $q = db()->prepare(
            'SELECT id, ticket_key, print_status FROM food_ticket_events '
            . 'WHERE user_id = ? AND punch_date = ? AND ticket_key IS NOT NULL AND ticket_key <> "" '
            . 'AND print_status IN ("not_printed", "pending", "print_error", "failed")'
        );
        $q->execute([$userId, $date]);
        $rows = $q->fetchAll();
    } catch (Throwable $e) {
        error_log('[food-absence-hold] lookup failed: ' . $e->getMessage());
        return false;
    }
    $held = false;
    foreach ($rows as $row) {
        try {
            try {
                db()->prepare('UPDATE food_ticket_events SET print_status = "held_absent", next_retry_at = NULL, processed_at = NOW() WHERE id = ?')
                    ->execute([(int) $row['id']]);
            } catch (Throwable $colErr) {
                // نصب‌های قدیمی که ستون next_retry_at را ندارند
                db()->prepare('UPDATE food_ticket_events SET print_status = "held_absent", processed_at = NOW() WHERE id = ?')
                    ->execute([(int) $row['id']]);
            }
            if (function_exists('food_ticket_print_log')) {
                food_ticket_print_log((int) $row['id'], null, null, (string) $row['ticket_key'], null, 'held_absent', 0, 'توقف چاپ به‌خاطر اعلام غیبت');
            }
            $held = true;
            if (function_exists('activity_log')) {
                activity_log([
                    'action_code' => 'food_ticket_ticket_held',
                    'module' => 'food',
                    'target_type' => 'food_ticket_event',
                    'target_id' => (int) $row['id'],
                    'target_label' => (string) $row['ticket_key'],
                    'user_id' => $actor['id'] ?? null,
                    'meta' => ['old' => (string) $row['print_status'], 'new' => 'held_absent', 'date' => $date, 'user_id' => $userId, 'reason' => $reason],
                ]);
            }
        } catch (Throwable $e) {
            error_log('[food-absence-hold] update failed: ' . $e->getMessage());
        }
    }
    return $held;
}

/**
 * آزادکردن فیشِ نگه‌داشته‌شده وقتی عضو دوباره «حاضر» می‌شود (برگشت به صف چاپ).
 */
function food_ticket_release_held_ticket(int $userId, string $date, array $actor = [], string $reason = ''): bool
{
    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    if (!food_ticket_events_ensure_hold_status()) {
        return false;
    }
    try {
        $q = db()->prepare('SELECT id, ticket_key FROM food_ticket_events WHERE user_id = ? AND punch_date = ? AND print_status = "held_absent"');
        $q->execute([$userId, $date]);
        $rows = $q->fetchAll();
    } catch (Throwable $e) {
        error_log('[food-absence-release] lookup failed: ' . $e->getMessage());
        return false;
    }
    $released = false;
    foreach ($rows as $row) {
        try {
            try {
                db()->prepare('UPDATE food_ticket_events SET print_status = "pending", last_error = NULL, next_retry_at = NULL, processed_at = NOW() WHERE id = ?')
                    ->execute([(int) $row['id']]);
            } catch (Throwable $colErr) {
                db()->prepare('UPDATE food_ticket_events SET print_status = "pending", last_error = NULL, processed_at = NOW() WHERE id = ?')
                    ->execute([(int) $row['id']]);
            }
            if (function_exists('food_ticket_print_log')) {
                food_ticket_print_log((int) $row['id'], null, null, (string) $row['ticket_key'], null, 'pending', 0, 'بازگشت به صف چاپ — عضو حاضر شد');
            }
            $released = true;
            if (function_exists('activity_log')) {
                activity_log([
                    'action_code' => 'food_ticket_ticket_released',
                    'module' => 'food',
                    'target_type' => 'food_ticket_event',
                    'target_id' => (int) $row['id'],
                    'target_label' => (string) $row['ticket_key'],
                    'user_id' => $actor['id'] ?? null,
                    'meta' => ['old' => 'held_absent', 'new' => 'pending', 'date' => $date, 'user_id' => $userId, 'reason' => $reason],
                ]);
            }
        } catch (Throwable $e) {
            error_log('[food-absence-release] update failed: ' . $e->getMessage());
        }
    }
    return $released;
}

/**
 * اطمینان از این‌که مقدار «held_absent» در ENUM ستون print_status پذیرفته می‌شود.
 * (همان Migration ۱.۳۲؛ روی نصب‌های تازه از قبل وجود دارد.)
 */
function food_ticket_events_ensure_hold_status(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = true;
    try {
        if (function_exists('db_table_columns')) {
            db()->prepare('SELECT print_status FROM food_ticket_events LIMIT 1')->execute();
            try {
                db()->prepare('SELECT 1 FROM food_ticket_events WHERE print_status = "held_absent" LIMIT 1')->execute();
                return $ready;
            } catch (Throwable $probe) {
                // مقدار در ENUM نیست → گسترش می‌دهیم
            }
            db()->exec('ALTER TABLE food_ticket_events MODIFY COLUMN print_status ENUM("not_printed","pending","printing","printed","print_error","failed","held_absent") NOT NULL DEFAULT "not_printed"');
        }
    } catch (Throwable $e) {
        error_log('[food-absence-hold] ensure enum failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

// ───────── تاریخچهٔ L_UID نماینده‌ها و تردد با شناسهٔ ناشناخته ─────────

/** ثبت اینکه این L_UID (در گذشته یا حال) نمایندهٔ یک گروه بوده است. */
function food_ticket_group_uid_history_record(string $uid, ?int $groupId, string $action, int $actorId = 0): void
{
    $uid = food_ticket_code($uid);
    if ($uid === '' || !in_array($action, ['set', 'changed', 'removed'], true) || !food_ticket_groups_ensure()) {
        return;
    }
    try {
        db()->prepare('INSERT INTO food_ticket_group_uid_history (uid, group_id, action, actor_id) VALUES (?, ?, ?, ?)')
            ->execute([$uid, $groupId !== null && $groupId > 0 ? $groupId : null, $action, $actorId > 0 ? $actorId : null]);
    } catch (Throwable $e) {
        error_log('[food-groups] uid history: ' . $e->getMessage());
    }
    food_ticket_groups_load(true);
}

/** آیا این L_UID سابقهٔ نمایندگی دارد؟ (برای تشخیص «تردد با شناسهٔ ناشناخته») */
function food_ticket_group_uid_history_has(string $uid): bool
{
    $uid = food_ticket_code($uid);
    if ($uid === '' || !food_ticket_groups_ensure()) {
        return false;
    }
    try {
        $q = db()->prepare('SELECT 1 FROM food_ticket_group_uid_history WHERE uid = ? LIMIT 1');
        $q->execute([$uid]);
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        error_log('[food-groups] uid history lookup: ' . $e->getMessage());
        return false;
    }
}

/**
 * تردد با L_UID ناشناخته: شناسه‌ای که سابقهٔ «نماینده بودن» دارد ولی به هیچ گروه فعالی
 * وصل نیست (مثلاً L_UID گروه عوض یا گروه حذف شده). این تردد:
 *   • به‌عنوان رویداد «ناشناخته» ثبت می‌شود (بدون هیچ فیش گروهی)،
 *   • در لاگ فعالیت‌ها و لاگ سیستم ثبت می‌شود تا اپراتور ببیند،
 *   • ردیف SOURCE_TABLE حذف می‌شود تا به‌اشتباه فیش شخصی صادر نشود.
 * ترددهای عادیِ کارکنانی که هرگز نماینده نبوده‌اند دست‌نخورده به مسیر فعلی می‌روند.
 */
function food_ticket_group_try_unknown(array $item, array &$summary): ?array
{
    $uid = food_ticket_code($item['uid'] ?? '');
    if ($uid === '' || $uid === '0' || $uid === '-1') {
        return null;
    }
    if (!food_ticket_group_uid_history_has($uid)) {
        return null;
    }
    $date = food_ticket_parse_date($item['date_raw'] ?? null);
    $time = food_ticket_parse_time($item['time_raw'] ?? null);
    if ($date === null || !food_ticket_group_date_ok($date)) {
        return null;
    }
    $ticketKey = 'unknown:' . $date . ':' . $uid;
    $message = 'تردد با L_UID ناشناخته: ' . $uid . ' به هیچ گروه فعالی وصل نیست.';
    $counted = (int) ($summary['group_unknown'] ?? 0) + 1;
    $summary['group_unknown'] = $counted;
    $summary['group_unknown_uid'] = $uid;
    $summary['last_error'] = $summary['last_error'] ?? $message;
    try {
        $payload = json_encode(['reason' => 'unknown_group_uid', 'uid' => $uid, 'source_key' => food_ticket_source_key($item)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hasDelivery = function_exists('food_ticket_delivery_supported') && food_ticket_delivery_supported();
        $cols = 'source_key, source_uid, punch_date, punch_time, event_type, print_status, ticket_key, source_payload, last_error, processed_at';
        $vals = '?, ?, ?, ?, "unknown", "not_printed", ?, ?, ?, NOW()';
        $args = [food_ticket_source_key($item), $uid, $date, $time, $ticketKey, $payload, $message];
        if ($hasDelivery) {
            $cols .= ', delivery_status';
            $vals .= ', "pending"';
        }
        if (!food_ticket_existing_ticket($ticketKey)) {
            db()->prepare('INSERT INTO food_ticket_events (' . $cols . ') VALUES (' . $vals . ')')->execute($args);
        }
    } catch (Throwable $e) {
        // اگر ساختار جدول اجازه نداد، حداقل در لاگ سیستم ثبت می‌شود (خطای قابل‌مشاهده برای اپراتور)
        error_log('[food-groups] unknown uid event: ' . $e->getMessage());
        if (function_exists('system_log')) {
            try {
                system_log('error', 'food_group', $message, ['uid' => $uid, 'date' => $date]);
            } catch (Throwable) {
            }
        }
    }
    food_ticket_audit('food_group_unknown_uid', [
        'target_type' => 'food_group',
        'target_id' => 0,
        'target_label' => 'L_UID ' . $uid,
        'new' => 'unknown',
        'meta' => ['uid' => $uid, 'date' => $date, 'count' => $counted],
    ]);
    if (function_exists('system_log')) {
        try {
            system_log('warning', 'food_group', $message, ['uid' => $uid, 'date' => $date]);
        } catch (Throwable) {
        }
    }
    return ['delete' => true, 'kind' => 'unknown_uid'];
}

// ───────────────────────── API مدیریت گروه‌ها ─────────────────────────

/** @return array<string,mixed> */
function food_ticket_api_groups(string $overviewDate = ''): array
{
    if (!food_ticket_groups_ensure()) {
        throw new RuntimeException('جدول‌های گروه غذا ساخته نشد؛ upgrade-1.13-food-groups.sql را اجرا کنید.');
    }
    food_ticket_absence_ensure();
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $overviewDate) ? $overviewDate : date('Y-m-d');
    $userOut = static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'pc' => (string) ($r['employee_number'] ?? ''),
            'name' => (string) ($r['full_name'] ?? ''),
            'nat' => (string) ($r['national_code'] ?? ''),
            'active' => (bool) ($r['is_active'] ?? false),
        ];
    };
    $groups = db()->query('SELECT id, title, l_uid, rfid_card, description, status, created_at FROM food_ticket_groups ORDER BY status ASC, id DESC')->fetchAll();
    $byGroup = [];
    $mrows = db()->query(
        'SELECT m.group_id, u.id, u.employee_number, u.national_code, u.full_name, u.is_active '
        . 'FROM food_ticket_group_members m JOIN users u ON u.id = m.user_id ORDER BY u.full_name'
    )->fetchAll();
    foreach ($mrows as $r) {
        $byGroup[(int) $r['group_id']][] = $userOut($r);
    }
    $items = [];
    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        $overview = food_ticket_group_overview($gid, $date);
        $items[] = [
            'id' => $gid,
            'title' => (string) $g['title'],
            'l_uid' => food_ticket_code((string) ($g['l_uid'] ?? '')),
            // شمارهٔ کارت دیگر در رابط کاربری وجود ندارد؛ فقط برای سازگاری داده‌ای در دیتابیس می‌ماند.
            'card' => '',
            'legacy_card' => false,
            'description' => (string) ($g['description'] ?? ''),
            'active' => (string) $g['status'] === 'active',
            'created_at' => (string) $g['created_at'],
            'members' => $byGroup[$gid] ?? [],
            'today' => $overview,
        ];
    }
    $free = db()->query(
        'SELECT u.id, u.employee_number, u.national_code, u.full_name, u.is_active FROM users u '
        . 'LEFT JOIN food_ticket_group_members m ON m.user_id = u.id '
        . "WHERE m.id IS NULL AND u.employee_number IS NOT NULL AND TRIM(u.employee_number) <> '' "
        . 'ORDER BY u.is_active DESC, u.full_name'
    )->fetchAll();
    $runs = db()->query(
        'SELECT id, group_id, group_title, rfid_card, trigger_kind, trigger_uid, punch_date, punch_time, run_kind, status, members_total, printed_count, repeat_count, '
        . 'no_food_count, absent_count, skipped_count, error_count FROM food_ticket_group_runs ORDER BY id DESC LIMIT 30'
    )->fetchAll();
    return [
        'groups' => $items,
        'free_users' => array_map($userOut, $free),
        'runs' => $runs,
        'absence_date' => $date,
    ];
}

function food_ticket_api_save_group(array $data, int $actorId): int
{
    if (!food_ticket_groups_ensure()) {
        throw new RuntimeException('جدول‌های گروه غذا ساخته نشد.');
    }
    $id = max(0, (int) ($data['id'] ?? 0));
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('نام گروه الزامی است.');
    }
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 190) : substr($title, 0, 190);
    $description = trim((string) ($data['description'] ?? ''));
    $description = function_exists('mb_substr') ? mb_substr($description, 0, 500) : substr($description, 0, 500);

    // ── شناسهٔ نماینده: L_UID (اصلی) و کارت (فقط سازگاری با گروه‌های قدیمی) ──
    $lUid = food_ticket_code((string) ($data['l_uid'] ?? $data['lUid'] ?? ''));
    if ($lUid !== '' && preg_match('/^[0-9A-Za-z_-]{1,80}$/', $lUid) !== 1) {
        throw new RuntimeException('L_UID نماینده معتبر نیست (حرف/عدد، حداکثر ۸۰ نویسه).');
    }
    // ⚠️ ۱.۳۴: شمارهٔ کارت (C_CARD) از فرم گروه غذا حذف شده و از ورودی نادیده گرفته می‌شود.
    $card = '';
    if ($lUid === '') {
        throw new RuntimeException('L_UID نمایندهٔ گروه الزامی است (شمارهٔ کارت دیگر معیار شناسایی نیست).');
    }
    if ($card !== '' && preg_match('/^[0-9A-Z]{4,120}$/', $card) !== 1) {
        throw new RuntimeException('شمارهٔ کارت RFID معتبر نیست (حداقل ۴ نویسهٔ حرف/عدد).');
    }

    if (array_key_exists('active', $data)) {
        $status = !empty($data['active']) ? 'active' : 'inactive';
    } else {
        $status = ((string) ($data['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';
    }

    // L_UID نباید برای گروه دیگر ثبت شده باشد (قاعده: هر L_UID فقط یک گروه)
    if ($lUid !== '') {
        $dupe = db()->prepare("SELECT id, title, l_uid FROM food_ticket_groups WHERE id <> ? AND l_uid IS NOT NULL AND l_uid <> ''");
        $dupe->execute([$id]);
        foreach ($dupe->fetchAll() as $o) {
            if (food_ticket_code((string) $o['l_uid']) === $lUid) {
                throw new RuntimeException('این L_UID قبلاً برای گروه «' . $o['title'] . '» ثبت شده است.');
            }
        }
        // اگر همین L_UID کد پرسنلی کاربری باشد که عضو این گروه نیست، تردد او به‌عنوان گروه تفسیر می‌شود.
        $memberIds = [];
        if ($id > 0) {
            $mq = db()->prepare('SELECT user_id FROM food_ticket_group_members WHERE group_id = ?');
            $mq->execute([$id]);
            $memberIds = array_map('intval', $mq->fetchAll(PDO::FETCH_COLUMN));
        }
        $conflict = db()->prepare('SELECT id, full_name, employee_number, username FROM users WHERE is_active = 1');
        $conflict->execute();
        foreach ($conflict->fetchAll() as $u) {
            $uidCandidates = [food_ticket_code((string) ($u['employee_number'] ?? '')), food_ticket_code((string) ($u['username'] ?? ''))];
            if (in_array($lUid, $uidCandidates, true) && !in_array((int) $u['id'], $memberIds, true)) {
                throw new RuntimeException('L_UID ' . $lUid . ' متعلق به کاربر «' . $u['full_name'] . '» است که عضو این گروه نیست؛ ابتدا او را عضو کنید یا L_UID دیگری بدهید.');
            }
        }
    }

    // کارت نباید برای گروه دیگر، کارت مهمان یا کاربری ثبت شده باشد
    if ($card !== '') {
        $others = db()->prepare('SELECT title, rfid_card FROM food_ticket_groups WHERE id <> ? AND rfid_card IS NOT NULL');
        $others->execute([$id]);
        foreach ($others->fetchAll() as $o) {
            if (food_ticket_card_equals($card, (string) $o['rfid_card'])) {
                throw new RuntimeException('این کارت قبلاً برای گروه «' . $o['title'] . '» ثبت شده است.');
            }
        }
        try {
            foreach (db()->query('SELECT card_uid, guest_name FROM food_ticket_guest_cards')->fetchAll() as $g) {
                if (food_ticket_card_equals($card, (string) $g['card_uid'])) {
                    throw new RuntimeException('این کارت قبلاً به‌عنوان کارت مهمان ثبت شده است.');
                }
            }
        } catch (PDOException) {
            // جدول کارت مهمان وجود ندارد: تداخلی نیست
        }
        if (function_exists('food_ticket_card_map_load')) {
            foreach (food_ticket_card_map_load(true) as $key => $mappedUser) {
                if (food_ticket_card_equals($card, (string) $key)) {
                    $name = (string) (food_ticket_user_index()['id'][(int) $mappedUser]['full_name'] ?? ('کاربر ' . $mappedUser));
                    throw new RuntimeException('این کارت قبلاً برای کاربر «' . $name . '» ثبت شده است.');
                }
            }
        }
    }

    // اعضا
    $ids = [];
    foreach ((array) ($data['members'] ?? []) as $v) {
        $n = (int) $v;
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
    $ids = array_values($ids);
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $exist = db()->prepare("SELECT id FROM users WHERE id IN ($in) AND employee_number IS NOT NULL AND TRIM(employee_number) <> ''");
        $exist->execute($ids);
        if (count($exist->fetchAll()) !== count($ids)) {
            throw new RuntimeException('یکی از کاربران انتخاب‌شده در فهرست کارکنان غذا پیدا نشد.');
        }
        $conf = db()->prepare(
            "SELECT u.full_name, g.title FROM food_ticket_group_members m JOIN users u ON u.id = m.user_id JOIN food_ticket_groups g ON g.id = m.group_id WHERE m.user_id IN ($in) AND m.group_id <> ? LIMIT 1"
        );
        $conf->execute(array_merge($ids, [$id]));
        $c = $conf->fetch();
        if ($c) {
            throw new RuntimeException('کاربر «' . $c['full_name'] . '» قبلاً عضو گروه «' . $c['title'] . '» است؛ هر کاربر فقط در یک گروه می‌تواند باشد.');
        }
    }

    // وضعیت قبلی برای Audit
    $before = null;
    if ($id > 0) {
        $bq = db()->prepare('SELECT title, l_uid, rfid_card, status FROM food_ticket_groups WHERE id = ?');
        $bq->execute([$id]);
        $before = $bq->fetch() ?: null;
        if ($before === null) {
            throw new RuntimeException('گروه پیدا نشد.');
        }
    }
    $beforeMembers = [];
    if ($id > 0) {
        $mq = db()->prepare('SELECT user_id FROM food_ticket_group_members WHERE group_id = ?');
        $mq->execute([$id]);
        $beforeMembers = array_map('intval', $mq->fetchAll(PDO::FETCH_COLUMN));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            // ⚠️ ۱.۳۴: ستون rfid_card عمداً دست‌نخورده می‌ماند (فقط دادهٔ قدیمی؛ دیگر در هیچ فرمی ثبت/ویرایش نمی‌شود)
            $pdo->prepare('UPDATE food_ticket_groups SET title = ?, l_uid = ?, description = ?, status = ? WHERE id = ?')
                ->execute([$title, $lUid !== '' ? $lUid : null, $description !== '' ? $description : null, $status, $id]);
        } else {
            $pdo->prepare('INSERT INTO food_ticket_groups (title, l_uid, rfid_card, description, status, created_by) VALUES (?, ?, NULL, ?, ?, ?)')
                ->execute([$title, $lUid !== '' ? $lUid : null, $description !== '' ? $description : null, $status, $actorId > 0 ? $actorId : null]);
            $id = (int) $pdo->lastInsertId();
        }
        $cur = $pdo->prepare('SELECT user_id FROM food_ticket_group_members WHERE group_id = ?');
        $cur->execute([$id]);
        $current = array_map('intval', $cur->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_diff($current, $ids) as $remove) {
            $pdo->prepare('DELETE FROM food_ticket_group_members WHERE group_id = ? AND user_id = ?')->execute([$id, $remove]);
        }
        foreach (array_diff($ids, $current) as $add) {
            $pdo->prepare('INSERT INTO food_ticket_group_members (group_id, user_id) VALUES (?, ?)')->execute([$id, $add]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (int) $e->getCode() === 23000) {
            // پیغام دقیق بر اساس کلید یکتای نقض‌شده (به‌جای پیغام خام پایگاه‌داده)
            $msg = $e->getMessage();
            if (str_contains($msg, 'uq_food_group_uid')) {
                throw new RuntimeException('L_UID «' . $lUid . '» قبلاً برای گروه دیگری ثبت شده است. یک L_UID دیگر انتخاب کنید.');
            }
            if (str_contains($msg, 'uq_food_group_card')) {
                throw new RuntimeException('این شمارهٔ کارت قبلاً برای گروه دیگری ثبت شده است.');
            }
            if (str_contains($msg, 'uq_food_group_member_user')) {
                throw new RuntimeException('یکی از اعضا قبلاً در گروه دیگری است؛ هر کارمند فقط در یک گروه می‌تواند باشد.');
            }
            throw new RuntimeException('ذخیره نشد: L_UID یا یکی از اعضا قبلاً ثبت شده است.');
        }
        throw $e;
    }
    food_ticket_groups_load(true);

    $code = $before === null ? 'food_group_created' : 'food_group_updated';
    food_ticket_audit($code, [
        'user_id' => $actorId > 0 ? $actorId : null,
        'target_type' => 'food_group',
        'target_id' => $id,
        'target_label' => $title,
        'old' => $before ? ['l_uid' => (string) ($before['l_uid'] ?? ''), 'card' => (string) ($before['rfid_card'] ?? ''), 'status' => (string) $before['status'], 'members' => count($beforeMembers)] : null,
        'new' => ['l_uid' => $lUid, 'card' => $card, 'status' => $status, 'members' => count($ids)],
        'meta' => ['group_id' => $id],
    ]);
    $uidBefore = food_ticket_code((string) ($before['l_uid'] ?? ''));
    if ($lUid !== '' && $uidBefore !== $lUid) {
        food_ticket_group_uid_history_record($lUid, $id, $uidBefore === '' ? 'set' : 'changed', $actorId);
    }
    if ($uidBefore !== '' && $uidBefore !== $lUid) {
        food_ticket_group_uid_history_record($uidBefore, $id, 'removed', $actorId);
    }
    if ($before !== null && $uidBefore !== $lUid) {
        food_ticket_audit('food_group_uid_changed', [
            'user_id' => $actorId > 0 ? $actorId : null,
            'target_type' => 'food_group',
            'target_id' => $id,
            'target_label' => $title,
            'old' => $uidBefore,
            'new' => $lUid,
            'meta' => ['group_id' => $id],
        ]);
    }
    $addedMembers = array_values(array_diff($ids, $beforeMembers));
    $removedMembers = array_values(array_diff($beforeMembers, $ids));
    if ($addedMembers !== []) {
        food_ticket_audit('food_group_member_added', [
            'user_id' => $actorId > 0 ? $actorId : null,
            'target_type' => 'food_group', 'target_id' => $id, 'target_label' => $title,
            'new' => implode(',', $addedMembers), 'meta' => ['group_id' => $id, 'user_ids' => $addedMembers],
        ]);
    }
    if ($removedMembers !== []) {
        food_ticket_audit('food_group_member_removed', [
            'user_id' => $actorId > 0 ? $actorId : null,
            'target_type' => 'food_group', 'target_id' => $id, 'target_label' => $title,
            'old' => implode(',', $removedMembers), 'meta' => ['group_id' => $id, 'user_ids' => $removedMembers],
        ]);
    }
    return $id;
}

/**
 * خواندن وضعیت غیبت یک گروه در یک تاریخ (برای فرم مدیریت گروه/کارکنان).
 * @return array<string,mixed>
 */
/**
 * برچسب تاریخ شمسی (۱۴۰۵/۰۷/۱۴) از یک تاریخ میلادی Y-m-d — برای نمایش در پنل.
 * اگر تابع تبدیل سامانه موجود نباشد، همان مقدار میلادی برگردانده می‌شود.
 */
function food_ticket_jalali_label(string $isoDate): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m) !== 1) {
        return $isoDate;
    }
    if (!function_exists('gregorian_to_jalali')) {
        return $isoDate;
    }
    try {
        [$jy, $jm, $jd] = gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    } catch (Throwable) {
        return $isoDate;
    }
}

function food_ticket_api_absence_status(int $groupId, string $date, array $actor = []): array
{
    // ورودی ممکن است شمسی (۱۴۰۵/۰۷/۱۴) یا میلادی باشد؛ همیشه به Y-m-d میلادی نرمال می‌شود.
    if (function_exists('food_ticket_iso_date_input')) {
        $date = (string) (food_ticket_iso_date_input($date) ?? $date);
    }
    if (!food_ticket_groups_ensure() || !food_ticket_absence_ensure()) {
        throw new RuntimeException('جدول‌های غیبت روزانه ساخته نشد.');
    }
    if ($groupId <= 0) {
        throw new RuntimeException('گروه معتبر نیست.');
    }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    $gq = db()->prepare('SELECT id, title, l_uid, status FROM food_ticket_groups WHERE id = ?');
    $gq->execute([$groupId]);
    $group = $gq->fetch();
    if (!$group) {
        throw new RuntimeException('گروه پیدا نشد.');
    }
    $members = food_ticket_group_members($groupId);
    $absences = food_ticket_absence_load($date, $groupId);
    $locked = food_ticket_group_absence_locked($groupId, $date);
    $canOverride = food_ticket_can_override_absence($actor);
    $membersOut = [];
    foreach ($members as $m) {
        $uid = (int) $m['id'];
        $membersOut[] = [
            'id' => $uid,
            'pc' => (string) ($m['employee_number'] ?? ''),
            'name' => (string) ($m['full_name'] ?? ''),
            'national_code' => food_ticket_digits($m['national_code'] ?? ''),
            'active' => (bool) ($m['is_active'] ?? false),
            'absent' => isset($absences[$uid]),
            'reason' => (string) ($absences[$uid]['reason'] ?? ''),
        ];
    }
    $absentCount = count($absences);
    return [
        // group_id هم سطح بالا برمی‌گردد؛ بدون آن، پنل در «ذخیرهٔ غیبت» مقدار گروه را از دست می‌داد.
        'group_id' => (int) $group['id'],
        'group' => [
            'id' => (int) $group['id'],
            'title' => (string) $group['title'],
            'l_uid' => food_ticket_code((string) ($group['l_uid'] ?? '')),
            'active' => (string) $group['status'] === 'active',
        ],
        'date' => $date,
        'date_jalali' => function_exists('food_ticket_jalali_label') ? food_ticket_jalali_label($date) : $date,
        'held_tickets' => food_ticket_absence_held_count($groupId, $date),
        'locked' => $locked,
        'can_edit' => !$locked,
        'can_override' => $canOverride,
        'editable' => !$locked || $canOverride,
        'lock_label' => $locked ? ($canOverride ? '🔒 قفل شده (اصلاح با دسترسی سطح بالاتر)' : '🔒 قفل شده') : 'قابل ویرایش',
        'members' => $membersOut,
        'summary' => [
            'date' => $date,
            'total' => count($membersOut),
            'absent' => $absentCount,
            'present' => max(0, count($membersOut) - $absentCount),
        ],
    ];
}

/**
 * ثبت تغییرات غیبت یک گروه در یک تاریخ.
 * ورودی: absent[] = کاربرانی که باید غایب شوند، present[] = کاربرانی که باید حاضر شوند.
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function food_ticket_api_absence_save(array $data, array $actor = []): array
{
    $groupId = (int) ($data['group_id'] ?? $data['groupId'] ?? 0);
    $date = trim((string) ($data['date'] ?? ''));
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    }
    if (function_exists('food_ticket_iso_date_input')) {
        // تاریخ شمسی (۱۴۰۵/۰۷/۱۴) هم پذیرفته می‌شود؛ خروجی همیشه میلادی Y-m-d است.
        $parsed = food_ticket_iso_date_input($date);
        if ($parsed !== null) {
            $date = $parsed;
        }
    }
    if ($groupId <= 0) {
        throw new RuntimeException('گروه انتخاب نشده است؛ ابتدا گروه را از فهرست انتخاب کنید.');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('تاریخ معتبر نیست؛ نمونهٔ درست: ۱۴۰۵/۰۷/۱۴');
    }
    if (food_ticket_groups_ensure()) {
        $gq = db()->prepare('SELECT id FROM food_ticket_groups WHERE id = ? LIMIT 1');
        $gq->execute([$groupId]);
        if (!$gq->fetchColumn()) {
            throw new RuntimeException('گروه انتخاب‌شده پیدا نشد.');
        }
    }
    $absent = [];
    foreach ((array) ($data['absent'] ?? []) as $v) {
        if ((int) $v > 0) {
            $absent[(int) $v] = true;
        }
    }
    $present = [];
    foreach ((array) ($data['present'] ?? []) as $v) {
        if ((int) $v > 0) {
            $present[(int) $v] = true;
        }
    }
    $changed = 0;
    $held = 0;
    $released = 0;
    foreach (array_keys($absent) as $userId) {
        $r = food_ticket_absence_set($groupId, (int) $userId, $date, true, $reason, $actor);
        $changed++;
        if (($r['ticket_action'] ?? '') === 'held') {
            $held++;
        }
    }
    foreach (array_keys($present) as $userId) {
        $r = food_ticket_absence_set($groupId, (int) $userId, $date, false, $reason, $actor);
        $changed++;
        if (($r['ticket_action'] ?? '') === 'released') {
            $released++;
        }
    }
    $status = food_ticket_api_absence_status($groupId, $date, $actor);
    return ['ok' => true, 'changed' => $changed, 'tickets_held' => $held, 'tickets_released' => $released] + $status;
}

/** حذف همهٔ غیبت‌های یک گروه در یک تاریخ (همه حاضر). */
function food_ticket_api_absence_clear(int $groupId, string $date, string $reason, array $actor = []): array
{
    if (function_exists('food_ticket_iso_date_input')) {
        $date = (string) (food_ticket_iso_date_input($date) ?? $date);
    }
    if ($groupId <= 0) {
        throw new RuntimeException('گروه انتخاب نشده است.');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('تاریخ معتبر نیست؛ نمونهٔ درست: ۱۴۰۵/۰۷/۱۴');
    }
    $heldBefore = food_ticket_absence_held_count($groupId, $date);
    $removed = food_ticket_absence_clear_group($groupId, $date, $reason, $actor);
    $heldAfter = food_ticket_absence_held_count($groupId, $date);
    return ['ok' => true, 'removed' => $removed, 'tickets_released' => max(0, $heldBefore - $heldAfter)] + food_ticket_api_absence_status($groupId, $date, $actor);
}

/** وضعیت چاپ/تحویل یک رویداد و تغییر تحویل (تحویل ≠ چاپ). */
function food_ticket_api_delivery(int $eventId, string $action, string $reason, array $actor = []): array
{
    if ($eventId <= 0) {
        throw new RuntimeException('فیش معتبر نیست.');
    }
    if (!food_ticket_events_ensure_delivery()) {
        throw new RuntimeException('ستون وضعیت تحویل (delivery_status) در جدول food_ticket_events ساخته نشد. '
            . 'علت: کاربر دیتابیس اجازهٔ ALTER ندارد یا اجرای Migration ناموفق بوده است. '
            . 'راه‌حل: فایل upgrade-1.32-food-group-representative-absence.sql را با کاربر دارای دسترسی روی دیتابیس اجرا کنید.');
    }
    $q = db()->prepare('SELECT id, ticket_key, print_status, delivery_status FROM food_ticket_events WHERE id = ?');
    $q->execute([$eventId]);
    $event = $q->fetch();
    if (!$event) {
        throw new RuntimeException('فیش پیدا نشد.');
    }
    if ($action === 'undeliver') {
        if ($reason === '') {
            throw new RuntimeException('برای لغو تحویل، ثبت دلیل الزامی است.');
        }
        $changed = food_ticket_undeliver_event($eventId, $actor, $reason);
    } else {
        $changed = food_ticket_deliver_event($eventId, $actor, $reason);
    }
    return ['ok' => true, 'changed' => $changed, 'id' => $eventId, 'delivery_status' => $action === 'undeliver' ? 'pending' : 'delivered'];
}

function food_ticket_api_delete_group(int $id, int $actorId = 0): void
{
    if (!food_ticket_groups_ensure() || $id <= 0) {
        throw new RuntimeException('گروه پیدا نشد.');
    }
    $q = db()->prepare('SELECT title, l_uid FROM food_ticket_groups WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch() ?: null;
    if ($row !== null && food_ticket_code((string) ($row['l_uid'] ?? '')) !== '') {
        food_ticket_group_uid_history_record((string) $row['l_uid'], $id, 'removed', $actorId);
    }
    db()->prepare('DELETE FROM food_ticket_group_members WHERE group_id = ?')->execute([$id]);
    if (food_ticket_absence_ensure()) {
        db()->prepare('DELETE FROM food_ticket_daily_absence WHERE group_id = ?')->execute([$id]);
    }
    $stmt = db()->prepare('DELETE FROM food_ticket_groups WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('گروه پیدا نشد.');
    }
    food_ticket_groups_load(true);
    food_ticket_audit('food_group_deleted', [
        'user_id' => $actorId > 0 ? $actorId : null,
        'target_type' => 'food_group',
        'target_id' => $id,
        'target_label' => (string) ($row['title'] ?? ''),
        'old' => (string) ($row['l_uid'] ?? ''),
        'meta' => ['group_id' => $id],
    ]);
}
