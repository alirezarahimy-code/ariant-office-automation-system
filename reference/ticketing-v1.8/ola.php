<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function ola_post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function ola_header(string $title, array $user): void
{
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    $logo = setting('app_logo', (string) cfg('app.logo', ''));
    $__notif = notification_header_summary((int) $user['id']);
    $notificationCount = $__notif['unread'];
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title . ' | ' . $appName) . '</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><header class="topbar"><div class="topbar-inner"><a class="brand" href="index.php"><span class="brand-mark">' . ($logo ? '<img src="' . e($logo) . '" alt="' . e($appName) . '">' : '<span class="brand-glyph">پ</span>') . '</span><span><strong>' . e($appName) . '</strong><small>مرکز خدمات و پشتیبانی</small></span></a><nav class="topnav"><a href="index.php">داشبورد</a><a href="index.php?page=queue">صف کاری</a><a href="governance.php">تغییر و مشکل</a><a href="ola.php">OLA</a><a class="notification-link" href="index.php?page=notifications">اعلان‌ها' . ($notificationCount > 0 ? '<b>' . $notificationCount . '</b>' : '') . '</a><span class="user-chip">' . e($user['full_name']) . '</span><form class="logout-form" method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="logout-link" type="submit">خروج</button></form></nav></div></header><div class="content-wrap"><div id="notification-toasts" class="notification-toasts" data-cursor="' . (int) $__notif['latest'] . '" aria-live="polite"></div>';
    foreach (take_flash() as $flash) {
        echo '<div class="alert ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function ola_footer(): void
{
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    echo '</div><footer class="footer">' . e($appName) . ' • نسخه سازمانی فارسی</footer></div><script src="assets/app.js"></script></body></html>';
}

// ۱.۳۷.۷ — این صفحه با require_admin() باز می‌شد، اما منوی برنامه و کاتالوگ دسترسی‌ها بر پایهٔ
// کد «ola.manage» بود؛ هر دو یکی شد (ادمین اصلی همهٔ کدها را دارد و کد از تنظیمات ← نقش‌ها
// قابل دادن/گرفتن است).
$user = require_permission('ola.view');
$tableCheck = db()->query("SHOW TABLES LIKE 'ola_policies'");
if (!$tableCheck->fetchColumn()) {
    ola_header('سیاست‌های OLA', $user);
    echo '<section class="card empty-state"><h2>ماژول OLA هنوز نصب نشده است.</h2><p>فایل <code>upgrade-1.8-final.sql</code> را یک‌بار روی پایگاه‌داده اجرا کنید.</p><a class="button" href="index.php">بازگشت</a></section>';
    ola_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ذخیره/تغییر وضعیت سیاست‌ها فقط با کد ویرایش OLA
    if (!user_can($user, 'ola.manage')) {
        http_response_code(403);
        exit('دسترسی ویرایش OLA برای نقش شما مجاز نیست.');
    }
    require_csrf();
    try {
        $action = ola_post('action');
        if ($action === 'save_ola_policy') {
            $policyId = (int) ($_POST['policy_id'] ?? 0);
            $name = ola_post('name');
            $priority = ola_post('priority', 'normal');
            $allowedPriorities = ['normal', 'urgent', 'critical'];
            $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
            $response = max(1, (int) ($_POST['response_minutes'] ?? 0));
            $resolution = max(1, (int) ($_POST['resolution_minutes'] ?? 0));
            $escalation1 = max(1, (int) ($_POST['escalation_1_minutes'] ?? 0));
            $escalation2 = max(1, (int) ($_POST['escalation_2_minutes'] ?? 0));
            if ($name === '' || !in_array($priority, $allowedPriorities, true) || $response > $resolution || $resolution > $escalation1 || $escalation1 > $escalation2) {
                throw new RuntimeException('نام و بازه‌های OLA را کامل کنید؛ ترتیب باید پاسخ ≤ حل ≤ Escalation ۱ ≤ Escalation ۲ باشد.');
            }
            if ($policyId > 0) {
                db()->prepare('UPDATE ola_policies SET name = ?, department_id = ?, priority = ?, response_minutes = ?, resolution_minutes = ?, escalation_1_minutes = ?, escalation_2_minutes = ?, is_active = 1, updated_at = NOW() WHERE id = ?')->execute([$name, $departmentId, $priority, $response, $resolution, $escalation1, $escalation2, $policyId]);
                flash('success', 'سیاست OLA ویرایش شد.');
            } else {
                db()->prepare('INSERT INTO ola_policies (name, department_id, priority, response_minutes, resolution_minutes, escalation_1_minutes, escalation_2_minutes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$name, $departmentId, $priority, $response, $resolution, $escalation1, $escalation2, $user['id']]);
                flash('success', 'سیاست OLA ساخته شد.');
            }
            redirect('ola.php');
        }
        if ($action === 'toggle_ola_policy') {
            $policyId = (int) ($_POST['policy_id'] ?? 0);
            db()->prepare('UPDATE ola_policies SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?')->execute([$policyId]);
            flash('success', 'وضعیت سیاست OLA تغییر کرد.');
            redirect('ola.php');
        }
    } catch (Throwable $exception) {
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'ذخیره سیاست OLA انجام نشد.');
        redirect('ola.php');
    }
}

ensure_default_ola_policies();
$editId = (int) ($_GET['edit'] ?? 0);
$editPolicy = null;
if ($editId > 0) {
    $editQuery = db()->prepare('SELECT * FROM ola_policies WHERE id = ? LIMIT 1');
    $editQuery->execute([$editId]);
    $editPolicy = $editQuery->fetch() ?: null;
}
$departments = db()->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
$policies = db()->query('SELECT o.*, d.name AS department_name FROM ola_policies o LEFT JOIN departments d ON d.id = o.department_id ORDER BY o.department_id IS NULL DESC, FIELD(o.priority, "critical", "urgent", "normal"), o.id')->fetchAll();
$value = static fn (string $key, mixed $default = ''): string => e((string) ($editPolicy[$key] ?? $default));

ola_header('سیاست‌های OLA', $user);
echo '<section class="page-heading"><div><span class="eyebrow">Operational Level Agreement</span><h1>سیاست‌های OLA و Escalation</h1><p>مهلت پاسخ داخلی، حل داخلی و دو سطح پیگیری مدیریتی بر اساس ساعات کاری سامانه محاسبه می‌شوند.</p></div><a class="button secondary" href="index.php?page=reports">گزارش‌ها</a></section><section class="settings-grid"><div class="card form-card"><h2>' . ($editPolicy ? 'ویرایش سیاست OLA' : 'سیاست پیش‌فرض جدید') . '</h2><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_ola_policy"><input type="hidden" name="policy_id" value="' . (int) ($editPolicy['id'] ?? 0) . '"><div class="form-grid"><label class="full">نام سیاست<input name="name" required value="' . $value('name', 'OLA سراسری') . '"></label><label>اولویت<select name="priority">';
foreach (['critical' => 'حیاتی', 'urgent' => 'فوری', 'normal' => 'عادی'] as $key => $label) {
    echo '<option value="' . $key . '" ' . (($editPolicy['priority'] ?? '') === $key ? 'selected' : '') . '>' . $label . '</option>';
}
echo '</select></label><label>معاونت<select name="department_id"><option value="">سراسری</option>';
foreach ($departments as $department) {
    echo '<option value="' . (int) $department['id'] . '" ' . ((int) ($editPolicy['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '') . '>' . e($department['name']) . '</option>';
}
echo '</select></label><label>پاسخ داخلی، دقیقه<input type="number" min="1" name="response_minutes" value="' . $value('response_minutes', 60) . '" required></label><label>حل داخلی، دقیقه<input type="number" min="1" name="resolution_minutes" value="' . $value('resolution_minutes', 480) . '" required></label><label>Escalation سطح ۱، دقیقه<input type="number" min="1" name="escalation_1_minutes" value="' . $value('escalation_1_minutes', 480) . '" required></label><label>Escalation سطح ۲، دقیقه<input type="number" min="1" name="escalation_2_minutes" value="' . $value('escalation_2_minutes', 960) . '" required></label></div><button class="button" type="submit">ذخیره سیاست</button></form></div><div class="card form-card"><h2>منطق اجرا</h2><p class="muted">هر تیکت جدید بر اساس معاونت و اولویت به دقیق‌ترین سیاست فعال متصل می‌شود. اگر سیاست معاونت وجود نداشته باشد، سیاست سراسری همان اولویت استفاده می‌شود.</p><div class="category-list"><div><span><strong>پاسخ داخلی</strong><small>اگر اولین پاسخ ثبت نشود، اعلان OLA ساخته می‌شود.</small></span></div><div><span><strong>حل داخلی</strong><small>عبور از زمان حل، اعلان جداگانه برای تیم رسیدگی می‌سازد.</small></span></div><div><span><strong>Escalation سطح ۱</strong><small>برای پیگیری مدیریتی اولیه اعلان ایجاد می‌شود.</small></span></div><div><span><strong>Escalation سطح ۲</strong><small>سوپروایزرها و ادمین‌ها نیز اعلان مستقیم می‌گیرند.</small></span></div></div></div></section><section class="card form-card"><h2>سیاست‌های فعال و غیرفعال</h2><div class="category-list">';
foreach ($policies as $policy) {
    echo '<div><span><strong>' . e($policy['name']) . '</strong><small>' . e(priority_label($policy['priority'])) . ' • ' . e($policy['department_name'] ?: 'سراسری') . ' • پاسخ ' . (int) $policy['response_minutes'] . ' دقیقه • حل ' . (int) $policy['resolution_minutes'] . ' دقیقه • E1 ' . (int) $policy['escalation_1_minutes'] . ' • E2 ' . (int) $policy['escalation_2_minutes'] . '</small></span><span class="actions"><em class="status ' . ((int) $policy['is_active'] === 1 ? 'open' : 'closed') . '">' . ((int) $policy['is_active'] === 1 ? 'فعال' : 'غیرفعال') . '</em><a class="mini-button" href="ola.php?edit=' . (int) $policy['id'] . '">ویرایش</a><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_ola_policy"><input type="hidden" name="policy_id" value="' . (int) $policy['id'] . '"><button class="mini-button" type="submit">تغییر وضعیت</button></form></span></div>';
}
echo '</div></section>';
ola_footer();
