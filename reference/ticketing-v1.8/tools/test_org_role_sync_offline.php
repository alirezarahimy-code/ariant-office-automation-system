<?php
// آزمون همگام‌سازی نقش با سمت‌های چارت سازمانی (روی دیتابیس تست). اجرا: php tools/test_org_role_sync_offline.php <config.php>
declare(strict_types=1);

$cfgPath = $argv[1] ?? '';
if ($cfgPath === '' || !is_file($cfgPath)) {
    fwrite(STDERR, "usage: php test.php <config.php with database test settings>\n");
    exit(2);
}
$cfg = include $cfgPath;
$d = $cfg['database'];
$pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function db(): PDO { global $pdo; return $pdo; }
require __DIR__ . '/../organization.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . "\n"; }
function roleOf(int $id): string { return (string) db()->query("SELECT role FROM users WHERE id = $id")->fetchColumn(); }

org_ensure_schema();
org_ensure_role_sync_columns();
db()->exec('DELETE FROM org_unit_managers');
db()->exec("DELETE FROM org_units WHERE unit_type IN ('deputy','department')");
db()->exec("DELETE FROM users WHERE username LIKE 'orgtest_%'");
$ids = [];
foreach (['user', 'agent', 'inspector', 'supervisor', 'user', 'user'] as $i => $role) {
    db()->prepare("INSERT INTO users (username, full_name, role, is_active, auth_source) VALUES (?, ?, ?, 1, 'local')")
        ->execute(["orgtest_$i", "تست $i", $role]);
    $ids[$i] = (int) db()->lastInsertId();
}
[$uUser, $uAgent, $uInsp, $uSup, $uDeptMgr, $uMulti] = $ids;

// ۱) کاربر «کاربر» معاون می‌شود → مدیر
db()->prepare("INSERT INTO org_units (name, code, parent_id, unit_type, manager_user_id, is_active) VALUES ('معاونت تست','T1',NULL,'deputy',?,1)")->execute([$uUser]);
$deputyId = (int) db()->lastInsertId();
org_sync_position_roles();
check('کاربر با سمت معاونت به مدیر تبدیل می‌شود', roleOf($uUser) === 'manager');

// ۲) کارشناس هم با سمت معاونت به مدیر تبدیل می‌شود (نقش قبلی ذخیره می‌شود)
db()->prepare('UPDATE org_units SET manager_user_id = ? WHERE id = ?')->execute([$uAgent, $deputyId]);
org_sync_position_roles();
check('کارشناس با سمت معاونت به مدیر تبدیل و سپس برداشته شد', roleOf($uAgent) === 'manager' && roleOf($uUser) === 'user');

// ۳) برداشتن سمت → بازگشت به نقش قبلی
db()->prepare('UPDATE org_units SET manager_user_id = NULL WHERE id = ?')->execute([$deputyId]);
org_sync_position_roles();
check('بعد از برداشتن سمت، کارشناس به نقش قبلی برمی‌گردد', roleOf($uAgent) === 'agent');

// ۴) بازرس و سوپروایزر با سمت مدیریتی تغییر نمی‌کنند
db()->prepare("INSERT INTO org_units (name, code, parent_id, unit_type, manager_user_id, is_active) VALUES ('مدیریت تست','T2',NULL,'department',?,1)")->execute([$uInsp]);
$deptId = (int) db()->lastInsertId();
db()->prepare('UPDATE org_units SET manager_user_id = ? WHERE id = ?')->execute([$uSup, $deptId]);
org_sync_position_roles();
check('بازرس با سمت مدیریت نقشش تغییر نمی‌کند', roleOf($uInsp) === 'inspector');
check('سوپروایزر با سمت مدیریت نقشش تغییر نمی‌کند', roleOf($uSup) === 'supervisor');
db()->prepare('UPDATE org_units SET manager_user_id = NULL WHERE id = ?')->execute([$deptId]);

// ۵) عضو مدیریت (جدول چند مدیر) هم مدیر می‌شود
db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, 0)')->execute([$deptId, $uMulti]);
org_sync_position_roles();
check('عضو مدیریت از جدول چند مدیر، مدیر می‌شود', roleOf($uMulti) === 'manager');
db()->exec('DELETE FROM org_unit_managers');
org_sync_position_roles();
check('حذف از جدول چند مدیر، نقش را برمی‌گرداند', roleOf($uMulti) === 'user');

// ۶) مدیر دستی (غیر خودکار) با برداشتن سمت‌ها دست‌نخورده می‌ماند
db()->prepare("UPDATE users SET role = 'manager', org_auto_role = 0 WHERE id = ?")->execute([$uDeptMgr]);
org_sync_position_roles();
check('مدیر دستی بدون سمت حذف نمی‌شود', roleOf($uDeptMgr) === 'manager');

// پاکسازی
db()->exec("DELETE FROM org_unit_managers WHERE unit_id IN (SELECT id FROM org_units WHERE code IN ('T1','T2'))");
db()->exec("DELETE FROM org_units WHERE code IN ('T1','T2')");
db()->exec("DELETE FROM users WHERE username LIKE 'orgtest_%'");
echo "\nPASS: $pass  FAIL: $fail\n";
exit($fail ? 1 : 0);
