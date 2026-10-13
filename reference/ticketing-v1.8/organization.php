<?php
declare(strict_types=1);

function org_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        'CREATE TABLE IF NOT EXISTS org_units (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            code VARCHAR(60) NULL,
            parent_id INT UNSIGNED NULL,
            unit_type VARCHAR(20) NOT NULL DEFAULT "department",
            manager_user_id INT UNSIGNED NULL,
            is_primary_admin TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_org_parent (parent_id),
            INDEX idx_org_type (unit_type),
            INDEX idx_org_manager (manager_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS org_unit_managers (
            unit_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (unit_id, user_id),
            INDEX idx_org_mgr_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    foreach (['org_unit_id INT UNSIGNED NULL', 'manager_user_id INT UNSIGNED NULL'] as $column) {
        try {
            db()->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS ' . $column);
        } catch (Throwable $ignored) {
            try {
                db()->exec('ALTER TABLE users ADD COLUMN ' . $column);
            } catch (Throwable $alsoIgnored) {
            }
        }
    }
    $hasCeo = (int) db()->query('SELECT COUNT(*) FROM org_units WHERE unit_type = "ceo"')->fetchColumn();
    if ($hasCeo === 0) {
        db()->prepare('INSERT INTO org_units (name, code, parent_id, unit_type, is_active) VALUES (?, ?, NULL, "ceo", 1)')
            ->execute(['مدیرعامل', 'CEO']);
    }
}

/**
 * ستون مستقل «معاونت درخواست‌کننده» روی تیکت‌ها (جدا از department_id که مسیر رسیدگی است).
 */
function org_ticket_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $columns = db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('requesting_unit_id', array_map('strtolower', $columns), true)) {
            db()->exec('ALTER TABLE tickets ADD COLUMN requesting_unit_id INT UNSIGNED NULL');
            db()->exec('ALTER TABLE tickets ADD INDEX idx_tickets_requesting_unit (requesting_unit_id)');
        }
    } catch (Throwable $exception) {
        if (function_exists('system_log')) {
            system_log('error', 'org_ticket_schema', $exception->getMessage());
        }
    }
}

function org_units_all(bool $activeOnly = true): array
{
    $sql = 'SELECT ou.*, m.full_name AS manager_name FROM org_units ou LEFT JOIN users m ON m.id = ou.manager_user_id';
    if ($activeOnly) {
        $sql .= ' WHERE ou.is_active = 1';
    }
    $sql .= ' ORDER BY ou.unit_type, ou.name';
    return db()->query($sql)->fetchAll();
}

/**
 * معاونت‌های فعال چارت سازمانی برای فهرست «معاونت درخواست‌کننده» در فرم تیکت.
 * همهٔ نقش‌ها این فهرست را می‌بینند تا تیکت به همان معاونت مسیردهی شود.
 */
function org_deputy_units(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    org_ensure_schema();
    $query = db()->query(
        'SELECT ou.id, ou.name, ou.code, ou.parent_id, m.full_name AS manager_name
         FROM org_units ou
         LEFT JOIN users m ON m.id = ou.manager_user_id
         WHERE ou.is_active = 1 AND ou.unit_type = "deputy"
         ORDER BY ou.name'
    );
    return $cache = $query->fetchAll();
}

/**
 * تبدیل معاونت سازمانی انتخاب‌شده به معاونتِ مسیر رسیدگی (departments).
 * اگر department هم‌نام وجود داشته باشد همان برگردانده می‌شود، در غیر این صورت ساخته می‌شود.
 */
function org_unit_routing_department_id(int $unitId): int
{
    if ($unitId <= 0) {
        return 0;
    }
    $unit = org_unit_by_id($unitId);
    if ($unit === null || (string) ($unit['unit_type'] ?? '') !== 'deputy') {
        return 0;
    }
    $name = trim((string) $unit['name']);
    if ($name === '') {
        return 0;
    }
    $find = db()->prepare('SELECT id FROM departments WHERE name = ? LIMIT 1');
    $find->execute([$name]);
    $found = $find->fetchColumn();
    if ($found !== false) {
        return (int) $found;
    }
    $code = trim((string) ($unit['code'] ?? ''));
    try {
        $insert = db()->prepare('INSERT INTO departments (name, code, is_active) VALUES (?, ?, 1)');
        $insert->execute([$name, $code !== '' ? $code : null]);
        return (int) db()->lastInsertId();
    } catch (Throwable $exception) {
        $retry = db()->prepare('SELECT id FROM departments WHERE name = ? LIMIT 1');
        $retry->execute([$name]);
        return (int) ($retry->fetchColumn() ?: 0);
    }
}

function org_ceo_unit_id(): int
{
    $id = (int) db()->query('SELECT id FROM org_units WHERE unit_type = "ceo" ORDER BY id LIMIT 1')->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    db()->prepare('INSERT INTO org_units (name, code, parent_id, unit_type, is_active) VALUES (?, ?, NULL, "ceo", 1)')->execute(['مدیرعامل', 'CEO']);
    return (int) db()->lastInsertId();
}

function org_unit_by_id(int $id): ?array
{
    $query = db()->prepare('SELECT * FROM org_units WHERE id = ? LIMIT 1');
    $query->execute([$id]);
    $unit = $query->fetch();
    return $unit ?: null;
}

function org_unit_children(int $parentId): array
{
    $query = db()->prepare('SELECT * FROM org_units WHERE parent_id = ? AND is_active = 1 ORDER BY name');
    $query->execute([$parentId]);
    return $query->fetchAll();
}

function org_unit_subtree_ids(int $rootId): array
{
    $ids = [$rootId];
    $stack = [$rootId];
    while ($stack) {
        $current = array_pop($stack);
        foreach (org_unit_children((int) $current) as $child) {
            $childId = (int) $child['id'];
            if (!in_array($childId, $ids, true)) {
                $ids[] = $childId;
                $stack[] = $childId;
            }
        }
    }
    return $ids;
}

function org_unit_path_names(int $unitId): string
{
    $names = [];
    $guard = 0;
    while ($unitId > 0 && $guard < 50) {
        $guard++;
        $unit = org_unit_by_id($unitId);
        if (!$unit) {
            break;
        }
        array_unshift($names, (string) $unit['name']);
        $unitId = (int) ($unit['parent_id'] ?? 0);
    }
    return implode(' / ', $names);
}

function org_unit_manager_ids(int $unitId): array
{
    $query = db()->prepare('SELECT user_id FROM org_unit_managers WHERE unit_id = ? ORDER BY is_primary DESC, user_id');
    $query->execute([$unitId]);
    return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

function org_unit_is_primary_admin(int $unitId): bool
{
    $unit = org_unit_by_id($unitId);
    return $unit !== null && (int) $unit['is_primary_admin'] === 1;
}

function user_org_unit_id(array $user): int
{
    return (int) ($user['org_unit_id'] ?? 0);
}

function user_is_unit_manager(array $user): bool
{
    $query = db()->prepare('SELECT COUNT(*) FROM org_unit_managers WHERE user_id = ?');
    $query->execute([(int) ($user['id'] ?? 0)]);
    return (int) $query->fetchColumn() > 0;
}

function user_managed_unit_ids(array $user): array
{
    static $cache = [];
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if ((int) ($user['is_primary_admin'] ?? 0) === 1) {
        return $cache[$userId] = array_map('intval', db()->query('SELECT id FROM org_units WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
    }
    $query = db()->prepare('SELECT unit_id FROM org_unit_managers WHERE user_id = ?');
    $query->execute([$userId]);
    return $cache[$userId] = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
}

function user_org_scope_unit_ids(array $user): array
{
    $scope = [];
    foreach (user_managed_unit_ids($user) as $unitId) {
        foreach (org_unit_subtree_ids((int) $unitId) as $childId) {
            $scope[$childId] = $childId;
        }
    }
    return array_values($scope);
}

function user_is_org_manager_of(array $user, int $targetUserId): bool
{
    $query = db()->prepare('SELECT org_unit_id FROM users WHERE id = ? LIMIT 1');
    $query->execute([$targetUserId]);
    $targetUnit = (int) $query->fetchColumn();
    return $targetUnit > 0 && in_array($targetUnit, user_org_scope_unit_ids($user), true);
}

function user_can_manage_organization(array $user): bool
{
    if ((int) ($user['is_primary_admin'] ?? 0) === 1) {
        return true;
    }
    return function_exists('is_admin_role') ? is_admin_role((string) ($user['role'] ?? '')) : (string) ($user['role'] ?? '') === 'admin';
}

function org_snapshot(): array
{
    return [
        'units' => db()->query('SELECT id, name, code, parent_id, unit_type, manager_user_id, is_primary_admin, is_active FROM org_units ORDER BY id')->fetchAll(),
        'unit_managers' => db()->query('SELECT unit_id, user_id, is_primary FROM org_unit_managers ORDER BY unit_id, user_id')->fetchAll(),
        'users' => db()->query('SELECT id, org_unit_id, manager_user_id, role, is_primary_admin, is_active FROM users ORDER BY id')->fetchAll(),
    ];
}

function org_record_undo(string $label, array $snapshot): void
{
    $_SESSION['org_undo'] = ['label' => $label, 'snapshot' => $snapshot];
}

function org_undo_state(): ?array
{
    $state = $_SESSION['org_undo'] ?? null;
    return is_array($state) && is_array($state['snapshot'] ?? null) ? $state : null;
}

function org_restore_snapshot(array $snapshot): void
{
    db()->beginTransaction();
    $unitRows = is_array($snapshot['units'] ?? null) ? $snapshot['units'] : [];
    $unitIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $unitRows);
    if ($unitIds !== []) {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        db()->prepare('UPDATE org_units SET is_active = 0 WHERE id NOT IN (' . $placeholders . ')')->execute($unitIds);
    }
    $updateUnit = db()->prepare('UPDATE org_units SET name = ?, code = ?, parent_id = ?, unit_type = ?, manager_user_id = ?, is_primary_admin = ?, is_active = ? WHERE id = ?');
    foreach ($unitRows as $row) {
        $updateUnit->execute([
            (string) ($row['name'] ?? ''),
            ($row['code'] ?? null) !== '' ? $row['code'] : null,
            (int) ($row['parent_id'] ?? 0) > 0 ? (int) $row['parent_id'] : null,
            (string) ($row['unit_type'] ?? 'department'),
            (int) ($row['manager_user_id'] ?? 0) > 0 ? (int) $row['manager_user_id'] : null,
            (int) ($row['is_primary_admin'] ?? 0),
            (int) ($row['is_active'] ?? 0),
            (int) ($row['id'] ?? 0),
        ]);
    }
    db()->exec('DELETE FROM org_unit_managers');
    $insertManager = db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, ?)');
    foreach ((array) ($snapshot['unit_managers'] ?? []) as $row) {
        $insertManager->execute([(int) $row['unit_id'], (int) $row['user_id'], (int) $row['is_primary']]);
    }
    $updateUser = db()->prepare('UPDATE users SET org_unit_id = ?, manager_user_id = ?, role = ?, is_primary_admin = ?, is_active = ? WHERE id = ?');
    foreach ((array) ($snapshot['users'] ?? []) as $row) {
        $updateUser->execute([
            (int) ($row['org_unit_id'] ?? 0) > 0 ? (int) $row['org_unit_id'] : null,
            (int) ($row['manager_user_id'] ?? 0) > 0 ? (int) $row['manager_user_id'] : null,
            (string) ($row['role'] ?? 'user'),
            (int) ($row['is_primary_admin'] ?? 0),
            (int) ($row['is_active'] ?? 0),
            (int) ($row['id'] ?? 0),
        ]);
    }
    db()->commit();
}

/**
 * ۱.۳۷.۸ — نقش خودکار بر اساس سمت در چارت سازمانی.
 * هر کاربری که مدیر (یا عضو مدیریت) یک معاونت/مدیریت/واحد فعال باشد، اگر نقشش «کاربر» یا «کارشناس» باشد
 * به «مدیر» تبدیل می‌شود. وقتی سمت برداشته شود و نقش را همین سازوکار تغییر داده باشد، به نقش قبلی برمی‌گردد.
 * نقش‌های استثنا (بازرسی، سوپروایزر، مدیر پشتیبانی، ادمین اصلی) هرگز توسط این سازوکار تغییر نمی‌کنند.
 */
function org_position_unit_types(): array
{
    return ['ceo', 'deputy', 'department'];
}

function org_exempt_roles(): array
{
    return ['supervisor', 'support_manager', 'inspector', 'primary_admin'];
}

function org_ensure_role_sync_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $columns = ['org_auto_role TINYINT(1) NOT NULL DEFAULT 0', 'org_prev_role VARCHAR(20) NULL'];
    foreach ($columns as $column) {
        try {
            db()->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS ' . $column);
        } catch (Throwable $ignored) {
            try {
                db()->exec('ALTER TABLE users ADD COLUMN ' . $column);
            } catch (Throwable $alsoIgnored) {
            }
        }
    }
}

/** @return int[] شناسهٔ کاربرانی که در حال حاضر سمت مدیریتی فعال دارند. */
function org_position_user_ids(): array
{
    $types = "'" . implode("','", org_position_unit_types()) . "'";
    $ids = [];
    foreach (db()->query("SELECT manager_user_id FROM org_units WHERE is_active = 1 AND manager_user_id > 0 AND unit_type IN ($types)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int) $id;
    }
    try {
        $rows = db()->query("SELECT m.user_id FROM org_unit_managers m JOIN org_units u ON u.id = m.unit_id WHERE u.is_active = 1 AND u.unit_type IN ($types)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }
    } catch (Throwable $ignored) {
    }
    return array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
}

/** همگام‌سازی نقش مدیر با سمت‌های چارت سازمانی. */
function org_sync_position_roles(): void
{
    if (db()->inTransaction()) {
        return;
    }
    org_ensure_schema();
    org_ensure_role_sync_columns();
    $positions = org_position_user_ids();
    $exempt = "'" . implode("','", org_exempt_roles()) . "'";
    $placeholders = $positions ? implode(',', array_fill(0, count($positions), '?')) : '';

    // ۱) ارتقا: کاربر/کارشناس با سمت مدیریتی → مدیر
    if ($positions) {
        $promote = db()->prepare("UPDATE users SET org_prev_role = role, org_auto_role = 1, role = 'manager' WHERE id IN ($placeholders) AND role IN ('user','agent') AND role NOT IN ($exempt)");
        $promote->execute($positions);
    }

    // ۲) بازگردانی: کسانی که همین سازوکار مدیر کرده و دیگر سمت ندارند
    $sql = "SELECT id, org_prev_role FROM users WHERE org_auto_role = 1 AND role = 'manager'";
    if ($positions) {
        $sql .= " AND id NOT IN ($placeholders)";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($positions);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $previous = in_array((string) $row['org_prev_role'], ['user', 'agent'], true) ? (string) $row['org_prev_role'] : 'user';
        db()->prepare('UPDATE users SET role = ?, org_auto_role = 0, org_prev_role = NULL WHERE id = ?')->execute([$previous, (int) $row['id']]);
    }
}
