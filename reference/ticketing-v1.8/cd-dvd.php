<?php
declare(strict_types=1);

function cd_dvd_media_bounds(string $media): array
{
    return strtoupper($media) === 'DVD' ? [1001, 2000] : [1, 1000];
}

function cd_dvd_normalize_serial(string $serial): string
{
    $serial = strtoupper(trim($serial));
    return (string) preg_replace('/\s+/', '', $serial);
}

function cd_dvd_internal_number(string $serial): ?int
{
    return preg_match('/^P86-(\d{4})$/', $serial, $matches) ? (int) $matches[1] : null;
}

function cd_dvd_serial_valid(string $serial): bool
{
    return $serial !== '' && strlen($serial) <= 20 && preg_match('/^[A-Z0-9][A-Z0-9._\/-]*$/', $serial) === 1;
}

function cd_dvd_serial_matches_media(string $serial, string $media): bool
{
    $number = cd_dvd_internal_number($serial);
    if ($number === null) {
        return true;
    }
    [$min, $max] = cd_dvd_media_bounds($media);
    return $number >= $min && $number <= $max;
}

function cd_dvd_is_primary_admin(array $user): bool
{
    return function_exists('user_is_primary_admin') ? user_is_primary_admin($user) : ($user['role'] === 'admin' && (int) ($user['is_primary_admin'] ?? 0) === 1);
}

function cd_dvd_inspection_user_ids(): array
{
    $ids = function_exists('key_role_user_ids') ? key_role_user_ids('inspection') : [];
    if ($ids === []) {
        $legacy = max(0, (int) setting('cd_dvd_inspection_user_id', '0'));
        if ($legacy > 0) {
            $ids = [$legacy];
        }
    }
    return $ids;
}

function cd_dvd_inspection_user_id(): int
{
    $ids = cd_dvd_inspection_user_ids();
    return (int) ($ids[0] ?? 0);
}

function cd_dvd_is_inspection_user(array $user): bool
{
    return in_array((int) ($user['id'] ?? 0), cd_dvd_inspection_user_ids(), true);
}

function cd_dvd_can_see_all(array $user): bool
{
    if (function_exists('user_can')) {
        return user_can($user, 'cddvd.view_all');
    }
    return cd_dvd_is_primary_admin($user) || in_array((string) $user['role'], ['supervisor', 'support_manager', 'inspector'], true) || cd_dvd_is_inspection_user($user);
}

function cd_dvd_can_edit_records(array $user): bool
{
    if (function_exists('user_can')) {
        return user_can($user, 'cddvd.edit');
    }
    return cd_dvd_is_primary_admin($user) || in_array((string) ($user['role'] ?? ''), ['supervisor', 'support_manager', 'inspector'], true);
}

function cd_dvd_can_export(array $user): bool
{
    if (function_exists('user_can')) {
        return user_can($user, 'cddvd.export');
    }
    return cd_dvd_can_see_all($user);
}

function cd_dvd_can_view_record(array $record, array $user): bool
{
    if (cd_dvd_can_see_all($user)) {
        return true;
    }
    if (in_array((string) ($user['role'] ?? ''), ['manager', 'support_manager'], true) && (int) ($user['department_id'] ?? 0) > 0) {
        return (int) ($record['department_id'] ?? 0) === (int) $user['department_id']
            || (int) ($record['brought_by_user_id'] ?? 0) === (int) $user['id'];
    }
    return (int) ($record['recorder_id'] ?? 0) === (int) $user['id']
        || (int) ($record['brought_by_user_id'] ?? 0) === (int) $user['id'];
}

function cd_dvd_audit_snapshot(array $record): array
{
    $keys = ['id', 'media', 'direction', 'serial', 'info_type', 'info_desc', 'jy', 'jm', 'jd', 'date_str', 'sort_key', 'receiver', 'receiver_unit', 'brought_by', 'brought_by_user_id', 'exit_sheet', 'note', 'department_id', 'recorder_id', 'recorder_name', 'created_at', 'updated_at'];
    $snapshot = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $record)) {
            $snapshot[$key] = $record[$key];
        }
    }
    return $snapshot;
}

function cd_dvd_can_register_in(array $user): bool
{
    if (function_exists('user_can')) {
        return user_can($user, 'cddvd.submit_in');
    }
    return cd_dvd_is_inspection_user($user);
}

function cd_dvd_can_submit_out(array $user): bool
{
    if (function_exists('user_can')) {
        return user_can($user, 'cddvd.submit_out');
    }
    return true;
}

function cd_dvd_available(): bool
{
    static $available;
    if ($available !== null) {
        return $available;
    }
    try {
        if (db()->query('SELECT 1 FROM cd_dvd_records LIMIT 1') === false) {
            throw new RuntimeException('CD/DVD tables are not available.');
        }
        $available = true;
    } catch (Throwable) {
        $available = false;
    }
    return $available;
}

function cd_dvd_scope(array $user, string $alias = 'r'): array
{
    if (cd_dvd_can_see_all($user)) {
        return ['', []];
    }
    if (in_array((string) $user['role'], ['manager', 'support_manager'], true) && (int) ($user['department_id'] ?? 0) > 0) {
        return ["WHERE ({$alias}.department_id = ? OR {$alias}.brought_by_user_id = ?)", [(int) $user['department_id'], (int) $user['id']]];
    }
    return ["WHERE ({$alias}.recorder_id = ? OR {$alias}.brought_by_user_id = ?)", [(int) $user['id'], (int) $user['id']]];
}

function cd_dvd_can_manage_record(array $record, array $user): bool
{
    return cd_dvd_can_edit_records($user);
}

function cd_dvd_types(): array
{
    return db()->query('SELECT name FROM cd_dvd_types ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

function cd_dvd_active_users(): array
{
    return db()->query('SELECT u.id, u.username, u.full_name, u.department_id, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.is_active = 1 ORDER BY u.full_name, u.username')->fetchAll();
}

/** معاونت‌های فعال چارت سازمانی؛ فهرست قدیمی departments منبع این فیلد نیست. */
function cd_dvd_deputy_options(): array
{
    if (!function_exists('org_deputy_units')) {
        return [];
    }
    return array_map(
        static fn (array $unit): array => ['id' => (int) $unit['id'], 'name' => (string) $unit['name']],
        org_deputy_units()
    );
}

/**
 * تبدیل معاونت سازمانی انتخاب‌شده به معاونت مسیر رسیدگی (departments).
 */
function cd_dvd_routing_department_id(int $unitId): int
{
    if ($unitId > 0 && function_exists('org_unit_routing_department_id')) {
        return org_unit_routing_department_id($unitId);
    }
    return $unitId;
}

function cd_dvd_next_serial(string $media): ?string
{
    [$min, $max] = cd_dvd_media_bounds($media);
    $query = db()->prepare("SELECT serial FROM cd_dvd_records WHERE media = ? AND serial REGEXP '^P86-[0-9]{4}$'");
    $query->execute([strtoupper($media)]);
    $used = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $serial) {
        $number = cd_dvd_internal_number((string) $serial);
        if ($number !== null) {
            $used[$number] = true;
        }
    }
    for ($number = $min; $number <= $max; $number++) {
        if (!isset($used[$number])) {
            return 'P86-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        }
    }
    return null;
}

function cd_dvd_last_record(string $serial, string $media, int $excludeId = 0): ?array
{
    $sql = 'SELECT * FROM cd_dvd_records WHERE serial = ? AND media = ?';
    $params = [$serial, strtoupper($media)];
    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY sort_key DESC, id DESC LIMIT 1';
    $query = db()->prepare($sql);
    $query->execute($params);
    return $query->fetch() ?: null;
}

function cd_dvd_fetch_record(int $id): ?array
{
    $query = db()->prepare('SELECT r.*, u.full_name AS recorder_display_name, d.name AS department_name, bu.full_name AS brought_by_user_name, bu.username AS brought_by_user_username FROM cd_dvd_records r LEFT JOIN users u ON u.id = r.recorder_id LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN users bu ON bu.id = r.brought_by_user_id WHERE r.id = ? LIMIT 1');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function cd_dvd_parse_date(string $value): array
{
    $value = strtr(trim($value), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    if ($value === '') {
        throw new RuntimeException('فیلد «تاریخ» خالی است و باید پر شود.');
    }
    $gregorian = jalali_input_to_gregorian(str_replace('-', '/', $value));
    if ($gregorian === null) {
        throw new RuntimeException('تاریخ باید به‌صورت جلالی معتبر وارد شود.');
    }
    $date = substr($gregorian, 0, 10);
    [$gy, $gm, $gd] = array_map('intval', explode('-', $date));
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    return [$jy, $jm, $jd, $date];
}

function cd_dvd_default_date(): string
{
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y'), (int) date('m'), (int) date('d'));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

function cd_dvd_validate_payload(array $data, array $user, int $excludeId = 0): array
{
    $media = valid_choice((string) ($data['media'] ?? ''), ['CD', 'DVD'], 'CD');
    $direction = valid_choice((string) ($data['direction'] ?? ''), ['IN', 'OUT'], 'OUT');
    if ($direction === 'IN' && !cd_dvd_can_register_in($user)) {
        throw new RuntimeException('فقط کاربر بازرسی می‌تواند ورود رسانه را ثبت کند.');
    }
    // ۱.۳۷.۷ — کد دسترسی cddvd.submit_out پیش‌تر فقط نمایشی بود (تب فرم)، ولی خود ثبت خروج
    // برای هر کاربر واردشده‌ای ممکن بود. اکنون مثل مسیر ورود، سمت سرور هم بررسی می‌شود.
    if ($direction === 'OUT' && !cd_dvd_can_submit_out($user)) {
        throw new RuntimeException('ثبت خروج رسانه برای نقش شما مجاز نیست.');
    }
    $serial = cd_dvd_normalize_serial((string) ($data['serial'] ?? ''));
    if ($direction === 'IN') {
        // رسانه ورودی از بیرون سازمان معمولاً شماره‌گذاری داخلی ندارد؛ مقدار دستی مقدم است.
        $manualSerial = cd_dvd_normalize_serial((string) ($data['serial_manual'] ?? ''));
        if ($manualSerial !== '') {
            $serial = $manualSerial;
        }
    } elseif ($serial === '') {
        $serial = cd_dvd_next_serial($media) ?? '';
    }
    if ($serial === '') {
        throw new RuntimeException('فیلد «شماره CD/DVD» خالی است و باید پر شود.');
    }
    if (!cd_dvd_serial_valid($serial)) {
        throw new RuntimeException('شماره رسانه معتبر نیست.');
    }
    if ($direction === 'OUT' && (cd_dvd_internal_number($serial) === null || !cd_dvd_serial_matches_media($serial, $media))) {
        throw new RuntimeException('شماره خروج باید در بازه شماره‌گذاری همین نوع رسانه باشد.');
    }
    if ($direction === 'IN' && !cd_dvd_serial_matches_media($serial, $media)) {
        throw new RuntimeException('شماره داخلی رسانه با نوع انتخاب‌شده سازگار نیست.');
    }
    $last = cd_dvd_last_record($serial, $media, $excludeId);
    if ($last && $direction === (string) $last['direction']) {
        throw new RuntimeException($direction === 'OUT' ? 'این شماره از سازمان خارج شده است.' : 'این رسانه در حال حاضر داخل سازمان ثبت شده است.');
    }
    $types = cd_dvd_types();
    $infoType = trim((string) ($data['info_type'] ?? ''));
    if ($infoType === '') {
        throw new RuntimeException('فیلد «نوع اطلاعات» خالی است و باید پر شود.');
    }
    if (!in_array($infoType, $types, true)) {
        throw new RuntimeException('نوع اطلاعات را انتخاب کنید.');
    }
    $description = trim((string) ($data['info_desc'] ?? ''));
    if ($description === '') {
        throw new RuntimeException('فیلد «اطلاعات ارسالی» خالی است و باید پر شود.');
    }
    $broughtBy = trim((string) ($data['brought_by'] ?? ''));
    $broughtByUserId = (int) ($data['brought_by_user_id'] ?? 0);
    if ($direction === 'IN') {
        if ($broughtByUserId > 0) {
            $broughtUserQuery = db()->prepare('SELECT id, full_name FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $broughtUserQuery->execute([$broughtByUserId]);
            $broughtUser = $broughtUserQuery->fetch();
            if (!$broughtUser) {
                throw new RuntimeException('کاربر واردکننده انتخاب‌شده فعال یا معتبر نیست.');
            }
            $broughtBy = (string) $broughtUser['full_name'];
        } elseif ($broughtByUserId !== -1 || $broughtBy === '') {
            throw new RuntimeException('فیلد «شخص واردکننده» خالی است و باید پر شود؛ شخص را از فهرست انتخاب کنید.');
        }
    } else {
        $broughtBy = '';
        $broughtByUserId = 0;
    }
    [$jy, $jm, $jd, $date] = cd_dvd_parse_date((string) ($data['event_date'] ?? ''));
    $departmentUnitId = (int) ($data['department_id'] ?? 0);
    if ($departmentUnitId <= 0) {
        throw new RuntimeException('فیلد «معاونت مربوط» خالی است و باید پر شود.');
    }
    $departmentUnit = function_exists('org_unit_by_id') ? org_unit_by_id($departmentUnitId) : null;
    if (!$departmentUnit || (string) ($departmentUnit['unit_type'] ?? '') !== 'deputy' || (int) ($departmentUnit['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('فیلد «معاونت مربوط» باید از معاونت‌های فعالِ ثبت‌شده در بخش سازمان انتخاب شود.');
    }
    // معاونت انتخاب‌شده یک واحد سازمانی است و به معاونت مسیر رسیدگی (departments) تبدیل می‌شود.
    $departmentId = cd_dvd_routing_department_id($departmentUnitId);
    if ($departmentId <= 0) {
        throw new RuntimeException('معاونت انتخاب‌شده به واحد گزارش‌دهی متصل نیست.');
    }
    $departmentCheck = db()->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1 LIMIT 1');
    $departmentCheck->execute([$departmentId]);
    if (!$departmentCheck->fetchColumn()) {
        throw new RuntimeException('معاونت انتخاب‌شده معتبر نیست.');
    }
    $exitSheet = strtr(trim((string) ($data['exit_sheet'] ?? '')), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    if ($exitSheet !== '' && !preg_match('/^\d+$/', $exitSheet)) {
        throw new RuntimeException('شماره برگه خروج فقط باید عدد باشد.');
    }
    return [
        'media' => $media, 'direction' => $direction, 'serial' => $serial,
        'info_type' => $infoType, 'info_desc' => $description,
        'jy' => $jy, 'jm' => $jm, 'jd' => $jd, 'date_str' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd),
        'sort_key' => $jy * 10000 + $jm * 100 + $jd, 'event_date' => $date,
        'receiver' => trim((string) ($data['receiver'] ?? '')), 'receiver_unit' => trim((string) ($data['receiver_unit'] ?? '')),
        'brought_by' => $direction === 'IN' ? $broughtBy : '', 'brought_by_user_id' => $direction === 'IN' && $broughtByUserId > 0 ? $broughtByUserId : null, 'exit_sheet' => $exitSheet,
        'note' => trim((string) ($data['note'] ?? '')), 'department_id' => $departmentId > 0 ? $departmentId : null,
    ];
}

function cd_dvd_create_record(array $user): int
{
    $payload = cd_dvd_validate_payload($_POST, $user);
    $query = db()->prepare('INSERT INTO cd_dvd_records (media, direction, serial, info_type, info_desc, jy, jm, jd, date_str, sort_key, receiver, receiver_unit, brought_by, brought_by_user_id, exit_sheet, note, department_id, recorder_id, recorder_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$payload['media'], $payload['direction'], $payload['serial'], $payload['info_type'], $payload['info_desc'], $payload['jy'], $payload['jm'], $payload['jd'], $payload['date_str'], $payload['sort_key'], $payload['receiver'], $payload['receiver_unit'], $payload['brought_by'], $payload['brought_by_user_id'], $payload['exit_sheet'], $payload['note'], $payload['department_id'], $user['id'], $user['full_name']]);
    $id = (int) db()->lastInsertId();
    save_audit((int) $user['id'], 'cd_dvd_' . strtolower($payload['direction']), null, ['record_id' => $id, 'serial' => $payload['serial'], 'media' => $payload['media']]);
    return $id;
}

function cd_dvd_update_record(array $user): void
{
    $id = (int) ($_POST['record_id'] ?? 0);
    $record = cd_dvd_fetch_record($id);
    if (!$record || !cd_dvd_can_edit_records($user)) {
        throw new RuntimeException('ویرایش مستقیم CD/DVD فقط برای ادمین اصلی و سوپروایزر مجاز است؛ برای اصلاح این رکورد تیکت ثبت کنید.');
    }
    $payload = cd_dvd_validate_payload($_POST, $user, $id);
    db()->beginTransaction();
    try {
        $query = db()->prepare('UPDATE cd_dvd_records SET media = ?, direction = ?, serial = ?, info_type = ?, info_desc = ?, jy = ?, jm = ?, jd = ?, date_str = ?, sort_key = ?, receiver = ?, receiver_unit = ?, brought_by = ?, brought_by_user_id = ?, exit_sheet = ?, note = ?, department_id = ? WHERE id = ?');
        $query->execute([$payload['media'], $payload['direction'], $payload['serial'], $payload['info_type'], $payload['info_desc'], $payload['jy'], $payload['jm'], $payload['jd'], $payload['date_str'], $payload['sort_key'], $payload['receiver'], $payload['receiver_unit'], $payload['brought_by'], $payload['brought_by_user_id'], $payload['exit_sheet'], $payload['note'], $payload['department_id'], $id]);
        $after = cd_dvd_fetch_record($id) ?: [];
        save_audit((int) $user['id'], 'cd_dvd_updated', null, ['record_id' => $id, 'serial' => $payload['serial'], 'before' => cd_dvd_audit_snapshot($record), 'after' => cd_dvd_audit_snapshot($after)]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
}

function cd_dvd_delete_record(array $user): void
{
    $id = (int) ($_POST['record_id'] ?? 0);
    $record = cd_dvd_fetch_record($id);
    if (!$record || !cd_dvd_can_edit_records($user)) {
        throw new RuntimeException('حذف مستقیم CD/DVD فقط برای ادمین اصلی و سوپروایزر مجاز است؛ برای اصلاح این رکورد تیکت ثبت کنید.');
    }
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM cd_dvd_records WHERE id = ?')->execute([$id]);
        save_audit((int) $user['id'], 'cd_dvd_deleted', null, ['record_id' => $id, 'serial' => $record['serial'], 'before' => cd_dvd_audit_snapshot($record), 'after' => null]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $exception;
    }
}

function cd_dvd_query_records(array $user, array $filters = [], ?string $serial = null): array
{
    [$where, $params] = cd_dvd_scope($user, 'r');
    $conditions = $where !== '' ? [substr($where, 6)] : [];
    if (($filters['direction'] ?? '') !== '') {
        $conditions[] = 'r.direction = ?';
        $params[] = valid_choice((string) $filters['direction'], ['IN', 'OUT'], '');
    }
    if (($filters['media'] ?? '') !== '') {
        $conditions[] = 'r.media = ?';
        $params[] = valid_choice((string) $filters['media'], ['CD', 'DVD'], '');
    }
    if (($filters['info_type'] ?? '') !== '') {
        $conditions[] = 'r.info_type = ?';
        $params[] = trim((string) $filters['info_type']);
    }
    if ($serial !== null && trim($serial) !== '') {
        $conditions[] = 'r.serial = ?';
        $params[] = cd_dvd_normalize_serial($serial);
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $conditions[] = '(r.serial LIKE ? OR r.info_desc LIKE ? OR r.receiver LIKE ? OR r.brought_by LIKE ? OR bu.full_name LIKE ? OR bu.username LIKE ? OR r.note LIKE ?)';
        for ($i = 0; $i < 7; $i++) {
            $params[] = '%' . $search . '%';
        }
    }
    $sql = 'SELECT r.*, u.full_name AS recorder_display_name, d.name AS department_name, bu.full_name AS brought_by_user_name, bu.username AS brought_by_user_username FROM cd_dvd_records r LEFT JOIN users u ON u.id = r.recorder_id LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN users bu ON bu.id = r.brought_by_user_id';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY r.sort_key DESC, r.id DESC LIMIT 500';
    $query = db()->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
}

function cd_dvd_personal_incoming(array $user): array
{
    $query = db()->prepare('SELECT r.*, u.full_name AS recorder_display_name, d.name AS department_name, bu.full_name AS brought_by_user_name, bu.username AS brought_by_user_username FROM cd_dvd_records r LEFT JOIN users u ON u.id = r.recorder_id LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN users bu ON bu.id = r.brought_by_user_id WHERE r.direction = "IN" AND r.brought_by_user_id = ? ORDER BY r.sort_key DESC, r.id DESC LIMIT 20');
    $query->execute([(int) $user['id']]);
    return $query->fetchAll();
}

function cd_dvd_latest_records(array $records): array
{
    $latest = [];
    foreach ($records as $record) {
        $key = $record['media'] . '|' . $record['serial'];
        if (!isset($latest[$key])) {
            $latest[$key] = $record;
        }
    }
    return array_values($latest);
}

function cd_dvd_stats(array $user): array
{
    [$where, $params] = cd_dvd_scope($user, 'r');
    $query = db()->prepare('SELECT COUNT(*) AS total, SUM(r.media = "CD") AS cd, SUM(r.media = "DVD") AS dvd, SUM(r.direction = "IN") AS incoming, SUM(r.direction = "OUT") AS outgoing FROM cd_dvd_records r ' . $where);
    $query->execute($params);
    $stats = $query->fetch() ?: [];
    $records = cd_dvd_query_records($user);
    $inside = 0;
    $outside = 0;
    foreach (cd_dvd_latest_records($records) as $record) {
        if ($record['direction'] === 'IN') {
            $inside++;
        } else {
            $outside++;
        }
    }
    $personalIncomingQuery = db()->prepare('SELECT COUNT(*) FROM cd_dvd_records WHERE direction = "IN" AND brought_by_user_id = ?');
    $personalIncomingQuery->execute([(int) $user['id']]);
    return ['total' => (int) ($stats['total'] ?? 0), 'cd' => (int) ($stats['cd'] ?? 0), 'dvd' => (int) ($stats['dvd'] ?? 0), 'incoming' => (int) ($stats['incoming'] ?? 0), 'outgoing' => (int) ($stats['outgoing'] ?? 0), 'inside' => $inside, 'outside' => $outside, 'personal_incoming' => (int) $personalIncomingQuery->fetchColumn()];
}

function cd_dvd_export(array $user): never
{
    $records = cd_dvd_query_records($user, $_GET);
    $rows = [];
    foreach ($records as $index => $record) {
        $rows[] = [$index + 1, $record['direction'] === 'IN' ? 'ورود' : 'خروج', $record['media'], $record['serial'], $record['info_type'], $record['info_desc'], $record['date_str'], $record['receiver'], $record['receiver_unit'], $record['brought_by_user_name'] ?: $record['brought_by'], $record['department_name'], $record['recorder_display_name'] ?: $record['recorder_name'], $record['exit_sheet'], $record['note']];
    }
    $reportDates = array_values(array_filter(array_map(static fn (array $record): string => (string) ($record['date_str'] ?? ''), $records), static fn (string $date): bool => $date !== ''));
    sort($reportDates, SORT_STRING);
    $period = $reportDates
        ? ['from' => $reportDates[0], 'to' => $reportDates[count($reportDates) - 1]]
        : ['from' => 'بدون دادهٔ تاریخی', 'to' => 'بدون دادهٔ تاریخی'];
    excel_download('cd-dvd-report-' . date('Y-m-d') . '.xls', 'گزارش کنترل CD/DVD', ['ردیف', 'جهت', 'رسانه', 'شماره رسانه', 'نوع اطلاعات', 'شرح', 'تاریخ', 'تحویل‌گیرنده', 'واحد مقصد', 'شخص واردکننده', 'معاونت', 'ثبت‌کننده', 'برگه خروج', 'یادداشت'], $rows, $period);
}

function cd_dvd_render_surface(array $user, string $baseUrl): string
{
    $quick = valid_choice((string) ($_GET['cd_filter'] ?? 'all'), ['all', 'CD', 'DVD', 'IN', 'OUT', 'outside'], 'all');
    $search = trim((string) ($_GET['cd_search'] ?? ''));
    $filters = ['search' => $search];
    if ($quick === 'CD' || $quick === 'DVD') {
        $filters['media'] = $quick;
    } elseif ($quick === 'IN' || $quick === 'OUT') {
        $filters['direction'] = $quick;
    }
    $records = $quick === 'outside'
        ? array_values(array_filter(cd_dvd_latest_records(cd_dvd_query_records($user, $filters)), static fn (array $record): bool => $record['direction'] === 'OUT'))
        : cd_dvd_query_records($user, $filters);
    $stats = cd_dvd_stats($user);
    $filterUrl = static function (string $value) use ($baseUrl): string {
        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query(['cd_filter' => $value]);
    };
    $items = '';
    foreach ($records as $record) {
        $items .= '<div class="cd-dvd-surface-row"><span><em class="media-direction ' . ($record['direction'] === 'IN' ? 'in' : 'out') . '">' . ($record['direction'] === 'IN' ? 'ورودی' : 'خروجی') . '</em></span><span><b class="media-tag ' . strtolower((string) $record['media']) . '">' . e($record['media']) . '</b></span><strong>' . e($record['serial']) . '</strong><span>' . e($record['info_type']) . '</span><time>' . e($record['date_str']) . '</time><span>' . e($record['recorder_display_name'] ?: $record['recorder_name']) . '</span></div>';
    }
    $filtersHtml = '<nav class="cd-dvd-surface-filters" aria-label="فیلتر رسانه"><a data-cd-dvd-filter-link class="' . ($quick === 'all' ? 'active' : '') . '" href="' . e($filterUrl('all')) . '"><strong>' . (int) $stats['total'] . '</strong><span>کل رسانه‌های ثبت‌شده</span></a><a data-cd-dvd-filter-link class="' . ($quick === 'CD' ? 'active' : '') . '" href="' . e($filterUrl('CD')) . '"><strong>' . (int) $stats['cd'] . '</strong><span>CD</span></a><a data-cd-dvd-filter-link class="' . ($quick === 'DVD' ? 'active' : '') . '" href="' . e($filterUrl('DVD')) . '"><strong>' . (int) $stats['dvd'] . '</strong><span>DVD</span></a><a data-cd-dvd-filter-link class="' . ($quick === 'IN' ? 'active' : '') . '" href="' . e($filterUrl('IN')) . '"><strong>' . (int) $stats['incoming'] . '</strong><span>ورودی</span></a><a data-cd-dvd-filter-link class="' . ($quick === 'OUT' ? 'active' : '') . '" href="' . e($filterUrl('OUT')) . '"><strong>' . (int) $stats['outgoing'] . '</strong><span>خروجی</span></a><a data-cd-dvd-filter-link class="' . ($quick === 'outside' ? 'active' : '') . '" href="' . e($filterUrl('outside')) . '"><strong>' . (int) $stats['outside'] . '</strong><span>رسانه‌های خارج از سازمان</span></a></nav>';
    $searchUrl = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'cd_filter=' . rawurlencode($quick);
    return '<section class="card cd-dvd-surface" data-cd-dvd-surface><div class="cd-dvd-surface-heading"><div><span class="eyebrow">کنترل اموال سازمان</span><h2>سوابق CD/DVD</h2><p>با انتخاب هر گزینه، فهرست سوابق همان گروه نمایش داده می‌شود.</p></div><a class="button" href="index.php?page=cd-dvd&tab=new">ثبت رسانه جدید</a></div>' . $filtersHtml . '<form class="cd-dvd-surface-search" method="get" action="' . e($baseUrl) . '" data-cd-dvd-filter-form><input type="hidden" name="cd_filter" value="' . e($quick) . '"><label>جست‌وجوی سریع<input name="cd_search" value="' . e($search) . '" placeholder="شماره، نوع اطلاعات یا ثبت‌کننده را بنویسید" autocomplete="off"></label></form><div class="cd-dvd-surface-table"><div class="cd-dvd-surface-head"><span>جهت</span><span>رسانه</span><span>شماره</span><span>نوع رسانه</span><span>تاریخ</span><span>ثبت‌کننده</span></div>' . ($items !== '' ? $items : '<div class="empty-state compact-empty"><strong>رکوردی برای این فیلتر پیدا نشد.</strong></div>') . '</div></section>';
}

function cd_dvd_render_page_legacy(array $user): never
{
    $filters = [
        'direction' => valid_choice((string) ($_GET['direction'] ?? ''), ['IN', 'OUT'], ''),
        'media' => valid_choice((string) ($_GET['media'] ?? ''), ['CD', 'DVD'], ''),
        'info_type' => trim((string) ($_GET['info_type'] ?? '')),
        'search' => trim((string) ($_GET['search'] ?? '')),
    ];
    $records = cd_dvd_query_records($user, $filters);
    $stats = cd_dvd_stats($user);
    $types = cd_dvd_types();
    $broughtUsers = cd_dvd_active_users();
    $personalIncoming = cd_dvd_personal_incoming($user);
    $departments = cd_dvd_deputy_options();
    $editRecord = null;
    $editDeniedRecord = null;
    $editId = (int) ($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $candidate = cd_dvd_fetch_record($editId);
        if ($candidate && cd_dvd_can_manage_record($candidate, $user)) {
            $editRecord = $candidate;
        } elseif ($candidate && cd_dvd_can_view_record($candidate, $user)) {
            $editDeniedRecord = $candidate;
        }
    }
    $defaultSerial = cd_dvd_next_serial('CD') ?? '';
    $form = [
        'id' => $editRecord['id'] ?? '', 'media' => $editRecord['media'] ?? 'CD', 'direction' => $editRecord['direction'] ?? 'OUT',
        'serial' => $editRecord['serial'] ?? $defaultSerial, 'info_type' => $editRecord['info_type'] ?? ($types[0] ?? ''),
        'event_date' => $editRecord['date_str'] ?? cd_dvd_default_date(), 'info_desc' => $editRecord['info_desc'] ?? '',
        'receiver' => $editRecord['receiver'] ?? '', 'receiver_unit' => $editRecord['receiver_unit'] ?? '',
        'brought_by' => $editRecord['brought_by'] ?? '', 'brought_by_user_id' => $editRecord['brought_by_user_id'] ?? 0, 'exit_sheet' => $editRecord['exit_sheet'] ?? '',
        'note' => $editRecord['note'] ?? '', 'department_id' => 0,
    ];
    if ($editRecord && (int) ($editRecord['department_id'] ?? 0) > 0) {
        $storedNameQuery = db()->prepare('SELECT name FROM departments WHERE id = ? LIMIT 1');
        $storedNameQuery->execute([(int) $editRecord['department_id']]);
        $storedDepartmentName = (string) ($storedNameQuery->fetchColumn() ?: '');
        foreach ($departments as $unit) {
            if ((string) $unit['name'] === $storedDepartmentName) {
                $form['department_id'] = (int) $unit['id'];
                break;
            }
        }
    } elseif (function_exists('user_org_unit_id')) {
        $userUnitId = user_org_unit_id($user);
        foreach ($departments as $unit) {
            if ((int) $unit['id'] === $userUnitId) {
                $form['department_id'] = $userUnitId;
                break;
            }
        }
    }
    $selectedBroughtUser = null;
    foreach ($broughtUsers as $broughtUser) {
        if ((int) $broughtUser['id'] === (int) $form['brought_by_user_id']) {
            $selectedBroughtUser = $broughtUser;
            break;
        }
    }
    $broughtByDisplay = $selectedBroughtUser ? $selectedBroughtUser['full_name'] . ' - ' . $selectedBroughtUser['username'] : $form['brought_by'];
    $formAction = $editRecord ? 'cd_dvd_update' : 'cd_dvd_create';
    $queryString = http_build_query(array_filter($filters, static fn (mixed $value): bool => $value !== ''));
    $exportUrl = 'index.php?action=export_cd_dvd' . ($queryString ? '&' . e($queryString) : '');
    $canIn = cd_dvd_can_register_in($user);
    $canAll = cd_dvd_can_see_all($user);
    render_header('کنترل CD/DVD', $user);
    ?>
    <section class="page-heading">
        <div><span class="eyebrow">ثبت گردش رسانه</span><h1>کنترل ورود و خروج CD/DVD</h1><p>ثبت سازمانی رسانه‌ها و تاریخچه هر شماره با سطح دسترسی واحدی.</p></div>
        <div class="actions"><button class="button secondary" type="button" data-print-page>چاپ</button><?php if (cd_dvd_can_export($user)): ?><a class="button secondary" href="<?= $exportUrl ?>">خروجی Excel/CSV</a><?php endif; ?><a class="button secondary" href="index.php?page=cd-dvd-history">تاریخچه سریال</a></div>
    </section>
    <section class="stat-grid cd-dvd-stats">
        <div class="stat-card accent"><span class="stat-icon">▣</span><div><strong><?= $stats['total'] ?></strong><small>کل ثبت‌ها</small></div></div>
        <div class="stat-card"><span class="stat-icon blue">◉</span><div><strong><?= $stats['cd'] ?></strong><small>رکورد CD</small></div></div>
        <div class="stat-card"><span class="stat-icon">◉</span><div><strong><?= $stats['dvd'] ?></strong><small>رکورد DVD</small></div></div>
        <div class="stat-card"><span class="stat-icon green">↥</span><div><strong><?= $stats['incoming'] ?></strong><small>ورود</small></div></div>
        <div class="stat-card"><span class="stat-icon gray">↧</span><div><strong><?= $stats['outgoing'] ?></strong><small>خروج</small></div></div>
        <div class="stat-card"><span class="stat-icon accent-icon">●</span><div><strong><?= $stats['inside'] ?></strong><small>رسانه داخل سازمان</small></div></div>
        <div class="stat-card personal-stat"><span class="stat-icon green">♙</span><div><strong><?= $stats['personal_incoming'] ?></strong><small>ورودی‌های من</small></div></div>
        <div class="stat-card"><span class="stat-icon gray">●</span><div><strong><?= $stats['outside'] ?></strong><small>رسانه خارج سازمان</small></div></div>
    </section>
    <section class="card cd-dvd-personal-card">
        <div class="section-title"><div><h2>رسانه‌هایی که من وارد کرده‌ام</h2><p>این فهرست بر اساس حساب کاربری شما ثبت شده است.</p></div><span class="record-count"><?= count($personalIncoming) ?> مورد اخیر</span></div>
        <?php if ($personalIncoming): ?><div class="cd-dvd-personal-list"><?php foreach ($personalIncoming as $incoming): ?><a class="cd-dvd-personal-item" href="index.php?page=cd-dvd-history&serial=<?= rawurlencode($incoming['serial']) ?>&media=<?= rawurlencode($incoming['media']) ?>"><span class="media-pill <?= strtolower($incoming['media']) ?>"><?= e($incoming['media']) ?></span><span><strong><?= e($incoming['serial']) ?></strong><small><?= e($incoming['info_desc']) ?></small></span><time><?= e($incoming['date_str']) ?></time></a><?php endforeach; ?></div><?php else: ?><div class="empty-state compact-empty"><strong>هنوز رسانه‌ای با نام شما وارد نشده است.</strong><span>پس از ثبت ورود توسط کاربر بازرسی، اینجا نمایش داده می‌شود.</span></div><?php endif; ?>
    </section>
    <section class="card cd-dvd-form-card">
        <div class="section-title"><div><h2><?= $editRecord ? 'ویرایش ثبت رسانه' : 'ثبت گردش جدید' ?></h2><p>شماره خروج برای CD و DVD از بازه جداگانه سازمانی تولید می‌شود.</p></div><?php if ($editRecord): ?><a class="button secondary" href="index.php?page=cd-dvd">ثبت جدید</a><?php endif; ?></div>
        <?php if ($editDeniedRecord): ?><div class="alert info">ویرایش مستقیم این رکورد فقط برای ادمین اصلی و سوپروایزر مجاز است. <a href="index.php?page=new-ticket&amp;request=cd_dvd_edit&amp;record_id=<?= (int) $editDeniedRecord['id'] ?>">ثبت تیکت درخواست اصلاح</a></div><?php endif; ?>
        <?php if (!$canIn): ?><div class="alert info">ثبت ورود فقط برای کاربر بازرسی فعال است. شما فعلاً فقط می‌توانید خروج رسانه را ثبت کنید.</div><?php endif; ?>
        <form method="post" class="cd-dvd-form" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($formAction) ?>"><input type="hidden" name="record_id" value="<?= (int) $form['id'] ?>">
            <div class="form-grid">
                <label>رسانه<select name="media" id="cd-dvd-media"><option value="CD" <?= $form['media'] === 'CD' ? 'selected' : '' ?>>CD</option><option value="DVD" <?= $form['media'] === 'DVD' ? 'selected' : '' ?>>DVD</option></select></label>
                <label>جهت<select name="direction" id="cd-dvd-direction"><option value="OUT" <?= $form['direction'] === 'OUT' ? 'selected' : '' ?>>خروج از سازمان</option><?php if ($canIn || ($editRecord && $form['direction'] === 'IN')): ?><option value="IN" <?= $form['direction'] === 'IN' ? 'selected' : '' ?>>ورود به سازمان</option><?php endif; ?></select></label>
                <label>شماره رسانه<input name="serial" id="cd-dvd-serial" value="<?= e($form['serial']) ?>" maxlength="20" required data-required-label="شماره CD/DVD"><small id="cd-dvd-serial-help">CD: P86-0001 تا P86-1000</small></label>
                <label>نوع اطلاعات<select name="info_type" required data-required-label="نوع اطلاعات"><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= $form['info_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                <label>تاریخ شمسی<input name="event_date" value="<?= e($form['event_date']) ?>" placeholder="۱۴۰۵/۰۱/۰۱" required data-required-label="تاریخ"></label>
                <?php if ($departments): ?><label>معاونت مربوط<select name="department_id" required data-required-label="معاونت مربوط"><option value="">انتخاب معاونت</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $form['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></label><?php else: ?><label>معاونت مربوط<select name="department_id" required data-required-label="معاونت مربوط" disabled><option value="">ابتدا در بخش سازمان یک معاونت فعال ثبت کنید</option></select></label><?php endif; ?>
                <label class="full">شرح اطلاعات<textarea name="info_desc" rows="3" required data-required-label="اطلاعات ارسالی"><?= e($form['info_desc']) ?></textarea></label>
                <label>تحویل‌گیرنده<input name="receiver" value="<?= e($form['receiver']) ?>"></label><label>واحد مقصد<input name="receiver_unit" value="<?= e($form['receiver_unit']) ?>"></label>
                <label class="cd-dvd-incoming-field">شخص واردکننده رسانه *<div class="cd-dvd-user-picker" data-user-picker><input type="hidden" name="brought_by_user_id" id="cd-dvd-brought-by-id" value="<?= (int) $form['brought_by_user_id'] ?>"><input name="brought_by" id="cd-dvd-brought-by" value="<?= e($broughtByDisplay) ?>" autocomplete="off" placeholder="بخشی از نام، نام خانوادگی یا نام کاربری را بنویسید" data-required-label="شخص واردکننده"><div class="cd-dvd-user-options" role="listbox" hidden><button type="button" class="cd-dvd-user-option external" data-user-id="-1" data-label="">شخص خارج از سامانه</button><?php foreach ($broughtUsers as $broughtUser): ?><button type="button" class="cd-dvd-user-option" data-user-id="<?= (int) $broughtUser['id'] ?>" data-label="<?= e($broughtUser['full_name'] . ' - ' . $broughtUser['username']) ?>" data-search="<?= e($broughtUser['full_name'] . ' ' . $broughtUser['username'] . ' ' . ($broughtUser['department_name'] ?? '')) ?>"><?= e($broughtUser['full_name']) ?><small><?= e($broughtUser['username'] . ($broughtUser['department_name'] ? ' • ' . $broughtUser['department_name'] : '')) ?></small></button><?php endforeach; ?></div></div><small>کاربر داخلی را از فهرست انتخاب کنید تا ورود دقیقاً به حساب او منتسب شود.</small></label>
                <label>شماره برگه خروج<input name="exit_sheet" value="<?= e($form['exit_sheet']) ?>"></label><label class="full">یادداشت<textarea name="note" rows="2"><?= e($form['note']) ?></textarea></label>
            </div>
            <div class="actions"><button class="button" type="submit" <?= $departments ? '' : 'disabled' ?>><?= $editRecord ? 'ذخیره ویرایش' : 'ثبت گردش رسانه' ?></button></div>
        </form>
    </section>
    <section class="card filter-card cd-dvd-filter-card"><form method="get"><input type="hidden" name="page" value="cd-dvd"><div class="filter-grid"><label>جهت<select name="direction"><option value="">همه</option><option value="IN" <?= $filters['direction'] === 'IN' ? 'selected' : '' ?>>ورود</option><option value="OUT" <?= $filters['direction'] === 'OUT' ? 'selected' : '' ?>>خروج</option></select></label><label>رسانه<select name="media"><option value="">همه</option><option value="CD" <?= $filters['media'] === 'CD' ? 'selected' : '' ?>>CD</option><option value="DVD" <?= $filters['media'] === 'DVD' ? 'selected' : '' ?>>DVD</option></select></label><label>نوع اطلاعات<select name="info_type"><option value="">همه</option><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= $filters['info_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label><label class="filter-search">جست‌وجو<input name="search" value="<?= e($filters['search']) ?>" placeholder="شماره، شرح یا تحویل‌گیرنده"></label><button class="button" type="submit">اعمال فیلتر</button><a class="button secondary" href="index.php?page=cd-dvd">پاک‌کردن</a></div></form></section>
    <section class="card ticket-table cd-dvd-table"><div class="table-head"><span>رسانه و شماره</span><span>جهت</span><span>تاریخ</span><span>ثبت‌کننده</span><span>عملیات</span></div>
  <?php foreach ($records as $record): ?><div class="table-row"><span class="ticket-title"><b><?= e($record['media']) ?> • <?= e($record['department_name'] ?: 'بدون معاونت') ?></b><strong><?= e($record['serial']) ?></strong><small><?= e($record['info_desc']) ?></small></span><span><em class="status <?= $record['direction'] === 'IN' ? 'in_progress' : 'waiting_user' ?>"><?= $record['direction'] === 'IN' ? 'ورود' : 'خروج' ?></em></span><span class="date-cell"><?= e($record['date_str']) ?></span><span><?= e($record['recorder_display_name'] ?: $record['recorder_name']) ?><?php if ($record['direction'] === 'IN' && ($record['brought_by_user_name'] || $record['brought_by'])): ?><small class="muted block">واردکننده: <?= e($record['brought_by_user_name'] ?: $record['brought_by']) ?><?= $record['brought_by_user_username'] ? ' • ' . e($record['brought_by_user_username']) : '' ?></small><?php endif; ?></span><span class="actions"><a class="mini-button" href="index.php?page=cd-dvd-history&serial=<?= rawurlencode($record['serial']) ?>&media=<?= rawurlencode($record['media']) ?>">تاریخچه</a><?php if (cd_dvd_can_manage_record($record, $user)): ?><a class="mini-button" href="index.php?page=cd-dvd&edit=<?= (int) $record['id'] ?>">ویرایش</a><form method="post" data-confirm="این ثبت حذف شود؟"><input type="hidden" name="action" value="cd_dvd_delete"><?= csrf_field() ?><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="mini-button danger" type="submit">حذف</button></form><?php else: ?><a class="mini-button" href="index.php?page=new-ticket&amp;request=cd_dvd_edit&amp;record_id=<?= (int) $record['id'] ?>">درخواست اصلاح</a><?php endif; ?></span></div><?php endforeach; ?>
    <?php if (!$records): ?><div class="empty-state"><h3>ثبت رسانه‌ای پیدا نشد.</h3><p>با فیلتر دیگری جست‌وجو کنید یا یک گردش جدید ثبت کنید.</p></div><?php endif; ?></section>
    <script nonce="<?= e(csp_nonce()) ?>">window.CD_DVD_NEXT = <?= json_encode(['CD' => cd_dvd_next_serial('CD'), 'DVD' => cd_dvd_next_serial('DVD')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script><script src="assets/cd-dvd.js?v=3.4.8"></script>
    <?php
    render_footer();
    exit;
}

function cd_dvd_render_page(array $user): never
{
    $tab = valid_choice((string) ($_GET['tab'] ?? 'dashboard'), ['dashboard', 'new', 'history'], 'dashboard');
    $filters = [
        'direction' => valid_choice((string) ($_GET['direction'] ?? ''), ['IN', 'OUT'], ''),
        'media' => valid_choice((string) ($_GET['media'] ?? ''), ['CD', 'DVD'], ''),
        'info_type' => trim((string) ($_GET['info_type'] ?? '')),
        'search' => trim((string) ($_GET['search'] ?? '')),
    ];
    $records = cd_dvd_query_records($user, $filters);
    $stats = cd_dvd_stats($user);
    $types = cd_dvd_types();
    $broughtUsers = cd_dvd_active_users();
    if (function_exists('org_ensure_schema')) {
        org_ensure_schema();
    }
    // معاونت‌ها از چارت سازمانی خوانده می‌شوند تا با معاونت‌های تعریف‌شده یکسان باشند.
    $departments = cd_dvd_deputy_options();
    $editRecord = null;
    $editDeniedRecord = null;
    $editId = (int) ($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $candidate = cd_dvd_fetch_record($editId);
        if ($candidate && cd_dvd_can_manage_record($candidate, $user)) {
            $editRecord = $candidate;
            $tab = 'new';
        } elseif ($candidate && cd_dvd_can_view_record($candidate, $user)) {
            $editDeniedRecord = $candidate;
            $tab = 'history';
        }
    }
    $form = [
        'id' => $editRecord['id'] ?? '', 'media' => $editRecord['media'] ?? 'CD', 'direction' => $editRecord['direction'] ?? 'OUT',
        'serial' => $editRecord['serial'] ?? (cd_dvd_next_serial('CD') ?? ''), 'info_type' => $editRecord['info_type'] ?? ($types[0] ?? ''),
        'event_date' => $editRecord['date_str'] ?? cd_dvd_default_date(), 'info_desc' => $editRecord['info_desc'] ?? '',
        'receiver' => $editRecord['receiver'] ?? '', 'receiver_unit' => $editRecord['receiver_unit'] ?? '',
        'brought_by_user_id' => $editRecord['brought_by_user_id'] ?? 0, 'exit_sheet' => $editRecord['exit_sheet'] ?? '',
        'note' => $editRecord['note'] ?? '', 'department_id' => 0,
    ];
    if ($editRecord && (int) ($editRecord['department_id'] ?? 0) > 0) {
        $storedNameQuery = db()->prepare('SELECT name FROM departments WHERE id = ? LIMIT 1');
        $storedNameQuery->execute([(int) $editRecord['department_id']]);
        $storedDepartmentName = (string) ($storedNameQuery->fetchColumn() ?: '');
        foreach ($departments as $unit) {
            if ((string) $unit['name'] === $storedDepartmentName) {
                $form['department_id'] = (int) $unit['id'];
                break;
            }
        }
    } elseif (function_exists('user_org_unit_id')) {
        $userUnitId = user_org_unit_id($user);
        foreach ($departments as $unit) {
            if ((int) $unit['id'] === $userUnitId) {
                $form['department_id'] = $userUnitId;
                break;
            }
        }
    }
    $selectedBroughtUser = null;
    foreach ($broughtUsers as $broughtUser) {
        if ((int) $broughtUser['id'] === (int) $form['brought_by_user_id']) {
            $selectedBroughtUser = $broughtUser;
            break;
        }
    }
    $broughtByDisplay = $selectedBroughtUser ? $selectedBroughtUser['full_name'] . ' - ' . $selectedBroughtUser['username'] : '';
    $formAction = $editRecord ? 'cd_dvd_update' : 'cd_dvd_create';
    $canIn = cd_dvd_can_register_in($user);
    $tabUrl = static function (string $name, array $extra = []): string {
        return 'index.php?' . http_build_query(array_merge(['page' => 'cd-dvd', 'tab' => $name], $extra));
    };
    $exportParams = array_filter($filters, static fn (mixed $value): bool => $value !== '');
    $exportUrl = 'index.php?action=export_cd_dvd' . ($exportParams ? '&' . http_build_query($exportParams) : '');
    render_header('کنترل CD/DVD', $user);
    ?>
    <section class="cd-dvd-page">
        <header class="cd-dvd-banner">
            <div><span class="eyebrow">ثبت و پیگیری رسانه‌های اطلاعاتی</span><h1>کنترل گردش CD / DVD</h1><p>ثبت ورود و خروج، تاریخچه سریال و گزارش‌گیری یکپارچه</p></div>
            <a class="button" href="index.php">بازگشت به داشبورد</a>
        </header>
        <nav class="cd-dvd-tabs" aria-label="زیر‌فرم‌های کنترل رسانه">
            <a class="<?= $tab === 'dashboard' ? 'active' : '' ?>" data-cd-dvd-tab="dashboard" aria-controls="cd-dvd-dashboard" aria-selected="<?= $tab === 'dashboard' ? 'true' : 'false' ?>" href="#cd-dvd-dashboard">📊 داشبورد</a>
            <?php if (cd_dvd_can_submit_out($user) || cd_dvd_can_register_in($user)): ?><a class="<?= $tab === 'new' ? 'active' : '' ?>" data-cd-dvd-tab="new" aria-controls="cd-dvd-register" aria-selected="<?= $tab === 'new' ? 'true' : 'false' ?>" href="#cd-dvd-register">➕ ثبت رسانه جدید</a><?php endif; ?>
            <a class="<?= $tab === 'history' ? 'active' : '' ?>" data-cd-dvd-tab="history" aria-controls="cd-dvd-history" aria-selected="<?= $tab === 'history' ? 'true' : 'false' ?>" href="#cd-dvd-history">📋 سوابق</a>
        </nav>
        <section id="cd-dvd-dashboard" class="cd-dvd-subform cd-dvd-dashboard-section" data-cd-dvd-panel="dashboard" <?= $tab === 'dashboard' ? '' : 'hidden' ?>>
            <?= cd_dvd_render_surface($user, 'index.php?page=cd-dvd') ?>
        </section>
        <section id="cd-dvd-register" class="cd-dvd-subform" data-cd-dvd-panel="new" <?= $tab === 'new' ? '' : 'hidden' ?>>
            <section class="card cd-dvd-form-card cd-dvd-reference-form">
                <div class="section-title"><div><span class="eyebrow">فرم ثبت</span><h2><?= $editRecord ? 'ویرایش رسانه ثبت‌شده' : 'ثبت رسانه جدید' ?></h2><p>فیلد ثبت‌کننده به‌صورت خودکار از حساب فعلی سامانه ذخیره می‌شود.</p></div><a class="button secondary" href="<?= e($tabUrl('dashboard')) ?>">بازگشت به داشبورد</a></div>
                <?php if ($editDeniedRecord): ?><div class="alert info">ویرایش مستقیم این رکورد فقط برای ادمین اصلی و سوپروایزر مجاز است. <a href="index.php?page=new-ticket&amp;request=cd_dvd_edit&amp;record_id=<?= (int) $editDeniedRecord['id'] ?>">ثبت تیکت درخواست اصلاح</a></div><?php endif; ?>
                <?php if (!$canIn): ?><div class="alert info">ثبت رسانه ورودی فقط برای کاربر منتسب به بازرسی فعال است.</div><?php endif; ?>
                <form method="post" class="cd-dvd-form" novalidate>
                    <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($formAction) ?>"><input type="hidden" name="record_id" value="<?= (int) $form['id'] ?>">
                    <div class="cd-dvd-form-grid">
                        <label>جهت تردد<select name="direction" id="cd-dvd-direction"><option value="OUT" <?= $form['direction'] === 'OUT' ? 'selected' : '' ?>>خروج</option><?php if ($canIn || ($editRecord && $form['direction'] === 'IN')): ?><option value="IN" <?= $form['direction'] === 'IN' ? 'selected' : '' ?>>ورود</option><?php endif; ?></select></label>
                        <label>نوع رسانه<div class="media-choice"><label><input type="radio" name="media" value="CD" <?= $form['media'] === 'CD' ? 'checked' : '' ?>><span>CD</span></label><label><input type="radio" name="media" value="DVD" <?= $form['media'] === 'DVD' ? 'checked' : '' ?>><span>DVD</span></label></div></label>
                        <label>نوع اطلاعات<select name="info_type" required data-required-label="نوع اطلاعات"><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= $form['info_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
                        <label>تاریخ<div class="jalali-picker" data-jalali-picker><input data-jalali-input name="event_date" value="<?= e($form['event_date']) ?>" placeholder="۱۴۰۵/۰۱/۰۱" required data-required-label="تاریخ" autocomplete="off"><button type="button" class="calendar-trigger" aria-label="بازکردن تقویم">▦</button><div class="jalali-calendar" hidden></div></div></label>
                        <label>شماره CD/DVD
                            <div class="serial-input cd-dvd-serial-out" <?= $form['direction'] === 'IN' ? 'hidden' : '' ?>><span>P86-</span><input id="cd-dvd-serial" value="<?= e(preg_replace('/^P86-/', '', (string) $form['serial'])) ?>" maxlength="4" inputmode="numeric" autocomplete="off"><input type="hidden" name="serial" id="cd-dvd-serial-full" value="<?= e($form['serial']) ?>"></div>
                            <input type="text" id="cd-dvd-serial-manual" name="serial_manual" class="cd-dvd-serial-in" maxlength="20" autocomplete="off" placeholder="شماره روی رسانه را دستی وارد کنید" value="<?= $form['direction'] === 'IN' ? e((string) $form['serial']) : '' ?>" data-required-label="شماره CD/DVD" <?= $form['direction'] === 'IN' ? 'required' : 'hidden' ?>>
                            <small id="cd-dvd-serial-help"><?= $form['direction'] === 'IN' ? 'برای رسانه ورودی از بیرون سازمان، شماره را همان‌طور که روی رسانه درج شده دستی وارد کنید.' : 'CD: P86-0001 تا P86-1000' ?></small>
                        </label>
                        <?php if ($departments): ?><label>معاونت مربوط<select name="department_id" required data-required-label="معاونت مربوط"><option value="">انتخاب معاونت</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $form['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select><small class="field-help">فهرست مستقیماً از معاونت‌های فعالِ بخش سازمان بارگذاری می‌شود.</small></label><?php else: ?><label>معاونت مربوط<select name="department_id" required data-required-label="معاونت مربوط" disabled><option value="">ابتدا در بخش سازمان یک معاونت فعال ثبت کنید</option></select><small class="field-help">فهرست پیش‌فرض استفاده نمی‌شود؛ تا ثبت معاونت در بخش سازمان، امکان ذخیره وجود ندارد.</small></label><?php endif; ?>
                        <label>تحویل‌گیرنده<input name="receiver" value="<?= e($form['receiver']) ?>" placeholder="نام تحویل‌گیرنده"></label><label>واحد مقصد<input name="receiver_unit" value="<?= e($form['receiver_unit']) ?>" placeholder="واحد سازمانی"></label><label class="cd-dvd-exit-sheet-field" <?= $form['direction'] === 'IN' ? 'hidden' : '' ?>>شماره برگه خروج<input name="exit_sheet" value="<?= e($form['exit_sheet']) ?>" inputmode="numeric" pattern="[0-9۰-۹]*" data-digits-only autocomplete="off"></label>
                        <?php if ($canIn): ?><label class="cd-dvd-incoming-field" <?= $form['direction'] === 'IN' ? '' : 'hidden' ?>>شخص واردکننده<div class="cd-dvd-user-picker"><input type="hidden" name="brought_by_user_id" id="cd-dvd-brought-by-id" value="<?= (int) $form['brought_by_user_id'] ?>"><select name="brought_by" id="cd-dvd-brought-by" data-required-label="شخص واردکننده"><option value="">انتخاب کاربر منتسب به بازرسی</option><?php foreach ($broughtUsers as $broughtUser): ?><option value="<?= (int) $broughtUser['id'] ?>" <?= (int) $form['brought_by_user_id'] === (int) $broughtUser['id'] ? 'selected' : '' ?>><?= e($broughtUser['full_name'] . ' - ' . $broughtUser['username']) ?></option><?php endforeach; ?></select></div></label><?php endif; ?>
                        <label class="wide-half">اطلاعات ارسالی<textarea name="info_desc" rows="3" required data-required-label="اطلاعات ارسالی" placeholder="توضیح مختصر محتویات رسانه..."><?= e($form['info_desc']) ?></textarea></label><label class="wide-half">یادداشت / تذکر<textarea name="note" rows="3" placeholder="مثلاً رمزگذاری شده یا پیوست دارد..."><?= e($form['note']) ?></textarea></label>
                        <label class="full recorder-display">🔒 ثبت‌کننده: <strong><?= e($user['full_name']) ?></strong><small>این مقدار به‌صورت خودکار در رکورد ذخیره می‌شود.</small></label>
                    </div>
                    <div class="actions"><button class="button" type="submit" <?= $departments ? '' : 'disabled' ?>><?= $editRecord ? 'ذخیره ویرایش' : 'ذخیره ثبت' ?></button></div>
                </form>
             </section>
        </section>
        <section id="cd-dvd-history" class="cd-dvd-subform card cd-dvd-history-panel" data-cd-dvd-panel="history" data-cd-dvd-history-surface <?= $tab === 'history' ? '' : 'hidden' ?>>
                 <div class="section-title"><div><span class="eyebrow">ردگیری و گزارش</span><h2>سوابق ثبت رسانه</h2><p>با تغییر هر فیلتر، نتایج به‌صورت خودکار تازه می‌شوند.</p></div><div class="actions"><a class="button" href="<?= e($tabUrl('new')) ?>">ثبت جدید</a><button class="button secondary" type="button" data-print-records>چاپ سوابق</button><?php if (cd_dvd_can_export($user)): ?><a class="button secondary" href="<?= e($exportUrl) ?>">خروجی Excel</a><?php endif; ?></div></div>
                  <form method="get" class="cd-dvd-history-filters" data-cd-dvd-history-form><input type="hidden" name="page" value="cd-dvd"><input type="hidden" name="tab" value="history"><label>جست‌وجو<input name="search" value="<?= e($filters['search']) ?>" placeholder="شماره، شرح، تحویل‌گیرنده"></label><label>جهت<select name="direction"><option value="">همه جهت‌ها</option><option value="IN" <?= $filters['direction'] === 'IN' ? 'selected' : '' ?>>ورود</option><option value="OUT" <?= $filters['direction'] === 'OUT' ? 'selected' : '' ?>>خروج</option></select></label><label>رسانه<select name="media"><option value="">همه رسانه‌ها</option><option value="CD" <?= $filters['media'] === 'CD' ? 'selected' : '' ?>>CD</option><option value="DVD" <?= $filters['media'] === 'DVD' ? 'selected' : '' ?>>DVD</select></label><label>نوع اطلاعات<select name="info_type"><option value="">همه انواع</option><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= $filters['info_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label><a class="button secondary" href="<?= e($tabUrl('history')) ?>">پاک‌کردن</a></form>
                <div class="cd-dvd-record-table"><div class="cd-dvd-record-head"><span>ردیف</span><span>جهت</span><span>رسانه</span><span>شماره</span><span>وضعیت</span><span>نوع</span><span>اطلاعات</span><span>تاریخ</span><span>ثبت‌کننده</span><span>عملیات</span></div>
                 <?php foreach ($records as $index => $record): ?><div class="cd-dvd-record-row"><span><?= $index + 1 ?></span><span><em class="media-direction <?= $record['direction'] === 'IN' ? 'in' : 'out' ?>"><?= $record['direction'] === 'IN' ? 'ورود' : 'خروج' ?></em></span><span><b class="media-tag <?= strtolower($record['media']) ?>"><?= e($record['media']) ?></b></span><span><strong><?= e($record['serial']) ?></strong></span><span><em class="media-status <?= $record['direction'] === 'IN' ? 'inside' : 'outside' ?>"><?= $record['direction'] === 'IN' ? 'داخل' : 'خارج' ?></em></span><span><?= e($record['info_type']) ?></span><span class="record-description"><?= e($record['info_desc']) ?></span><span><?= e($record['date_str']) ?></span><span><?= e($record['recorder_display_name'] ?: $record['recorder_name']) ?></span><span class="actions"><a class="mini-button" href="index.php?page=cd-dvd-history&amp;serial=<?= rawurlencode($record['serial']) ?>&amp;media=<?= rawurlencode($record['media']) ?>">تاریخچه</a><?php if (cd_dvd_can_manage_record($record, $user)): ?><a class="mini-button" href="<?= e($tabUrl('new', ['edit' => (int) $record['id']])) ?>">ویرایش</a><form method="post" data-confirm="این ثبت حذف شود؟"><input type="hidden" name="action" value="cd_dvd_delete"><?= csrf_field() ?><input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>"><button class="mini-button danger" type="submit">حذف</button></form><?php else: ?><a class="mini-button" href="index.php?page=new-ticket&amp;request=cd_dvd_edit&amp;record_id=<?= (int) $record['id'] ?>">درخواست اصلاح</a><?php endif; ?></span></div><?php endforeach; ?>
                <?php if (!$records): ?><div class="empty-state"><h3>رکوردی پیدا نشد.</h3><p>فیلتر دیگری انتخاب کنید یا ثبت جدید را بزنید.</p></div><?php endif; ?></div>
            </section>
    </section>
    <script nonce="<?= e(csp_nonce()) ?>">window.CD_DVD_NEXT = <?= json_encode(['CD' => cd_dvd_next_serial('CD'), 'DVD' => cd_dvd_next_serial('DVD')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script><script src="assets/cd-dvd.js?v=3.4.8"></script>
    <?php
    render_footer();
    exit;
}

function cd_dvd_render_history(array $user): never
{
    $serial = cd_dvd_normalize_serial((string) ($_GET['serial'] ?? ''));
    $media = valid_choice((string) ($_GET['media'] ?? ''), ['CD', 'DVD'], '');
    $records = $serial !== '' ? cd_dvd_query_records($user, ['media' => $media], $serial) : [];
    $last = $records[0] ?? null;
    render_header('تاریخچه CD/DVD', $user);
    ?>
    <section class="page-heading"><div><span class="eyebrow">ردگیری رسانه</span><h1>تاریخچه گردش سریال</h1><p>ورودها و خروج‌های قابل مشاهده برای حساب شما نمایش داده می‌شود.</p></div><a class="button secondary" href="index.php?page=cd-dvd">بازگشت به کنترل CD/DVD</a></section>
    <section class="card form-card cd-dvd-history-search"><form method="get"><input type="hidden" name="page" value="cd-dvd-history"><div class="inline-form"><label>شماره سریال<input name="serial" value="<?= e($serial) ?>" placeholder="مثلاً P86-0020" required></label><label>رسانه<select name="media"><option value="">همه</option><option value="CD" <?= $media === 'CD' ? 'selected' : '' ?>>CD</option><option value="DVD" <?= $media === 'DVD' ? 'selected' : '' ?>>DVD</option></select></label><button class="button" type="submit">جست‌وجوی تاریخچه</button></div></form></section>
    <?php if ($serial !== ''): ?>
        <?php if ($last): ?><div class="alert <?= $last['direction'] === 'IN' ? 'success' : 'info' ?>">وضعیت فعلی <b><?= e($serial) ?></b>: <b><?= $last['direction'] === 'IN' ? 'داخل سازمان' : 'خارج از سازمان' ?></b>؛ آخرین ثبت در <?= e($last['date_str']) ?>.</div><?php endif; ?>
        <section class="card cd-dvd-history-list">
        <?php foreach (array_reverse($records) as $record): ?><article class="cd-dvd-history-item <?= $record['direction'] === 'IN' ? 'is-in' : 'is-out' ?>"><div class="cd-dvd-history-head"><span class="status <?= $record['direction'] === 'IN' ? 'in_progress' : 'waiting_user' ?>"><?= $record['direction'] === 'IN' ? 'ورود' : 'خروج' ?></span><b><?= e($record['media']) ?> • <?= e($record['serial']) ?></b><time><?= e($record['date_str']) ?></time></div><p><strong>شرح:</strong> <?= e($record['info_desc']) ?></p><div class="cd-dvd-history-meta"><span>ثبت‌کننده: <?= e($record['recorder_display_name'] ?: $record['recorder_name']) ?></span><?php if ($record['brought_by_user_name'] || $record['brought_by']): ?><span>شخص واردکننده: <?= e($record['brought_by_user_name'] ?: $record['brought_by']) ?><?= $record['brought_by_user_username'] ? ' • ' . e($record['brought_by_user_username']) : '' ?></span><?php endif; ?><?php if ($record['receiver']): ?><span>تحویل‌گیرنده: <?= e($record['receiver']) ?></span><?php endif; ?></div></article><?php endforeach; ?>
        </section>
        <?php if (!$records): ?><section class="card empty-state"><h3>تاریخچه‌ای برای این شماره در محدوده دسترسی شما پیدا نشد.</h3></section><?php endif; ?>
    <?php endif; ?>
    <?php
    render_footer();
    exit;
}
