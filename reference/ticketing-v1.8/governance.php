<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function gov_post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function gov_choice(string $value, array $allowed, string $fallback): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function gov_change_number(int $id): string
{
    return 'CHG-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function gov_problem_number(int $id): string
{
    return 'PRB-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function gov_risk_label(string $risk): string
{
    return ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'بالا', 'critical' => 'بحرانی'][$risk] ?? $risk;
}

function gov_change_status_label(string $status): string
{
    return ['draft' => 'پیش‌نویس', 'submitted' => 'ارسال‌شده', 'cab_review' => 'در انتظار CAB', 'approved' => 'تأییدشده', 'rejected' => 'ردشده', 'scheduled' => 'زمان‌بندی‌شده', 'implemented' => 'اجراشده', 'rolled_back' => 'بازگشت‌داده‌شده', 'closed' => 'بسته‌شده'][$status] ?? $status;
}

function gov_problem_status_label(string $status): string
{
    return ['open' => 'باز', 'investigation' => 'در حال بررسی', 'known_error' => 'خطای شناخته‌شده', 'resolved' => 'حل‌شده', 'closed' => 'بسته‌شده'][$status] ?? $status;
}

function gov_parse_datetime(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new DateTimeZone((string) cfg('app.timezone', 'Asia/Tehran')));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\\TH:i') !== $value) {
        return null;
    }
    return $date->format('Y-m-d H:i:s');
}

function gov_scope(array $user, string $alias = 'r'): array
{
    if (in_array($user['role'], ['primary_admin', 'supervisor', 'support_manager', 'inspector'], true)) {
        return ['', []];
    }
    $departmentId = (int) ($user['department_id'] ?? 0);
    if (in_array((string) $user['role'], ['manager', 'support_manager'], true)) {
        return $departmentId > 0 ? ["WHERE {$alias}.department_id = ?", [$departmentId]] : ['WHERE 1 = 0', []];
    }
    if ($user['role'] === 'agent') {
        return $departmentId > 0 ? ["WHERE ({$alias}.owner_id = ? OR {$alias}.department_id = ?)", [(int) $user['id'], $departmentId]] : ["WHERE {$alias}.owner_id = ?", [(int) $user['id']]];
    }
    return ['WHERE 1 = 0', []];
}

function gov_fetch_ticket(int $ticketId): ?array
{
    if ($ticketId <= 0) {
        return null;
    }
    $query = db()->prepare('SELECT t.*, d.name AS department_name, r.full_name AS requester_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id WHERE t.id = ? LIMIT 1');
    $query->execute([$ticketId]);
    return $query->fetch() ?: null;
}

function gov_can_view_ticket(array $ticket, array $user): bool
{
    if (in_array($user['role'], ['primary_admin', 'supervisor', 'support_manager', 'inspector'], true) || (int) $ticket['requester_id'] === (int) $user['id']) {
        return true;
    }
    if ($user['role'] === 'agent') {
        return (string) ($ticket['service_group'] ?? '') === user_service_group($user);
    }
    return in_array((string) $user['role'], ['manager', 'support_manager'], true) && (string) ($ticket['service_group'] ?? '') === user_service_group($user) && (int) ($user['department_id'] ?? 0) > 0 && (int) $ticket['department_id'] > 0 && (int) $ticket['department_id'] === (int) $user['department_id'];
}

function gov_fetch_change(int $changeId): ?array
{
    $query = db()->prepare('SELECT c.*, d.name AS department_name, r.full_name AS requester_name, o.full_name AS owner_name, cab.full_name AS cab_name FROM change_records c LEFT JOIN departments d ON d.id = c.department_id JOIN users r ON r.id = c.requester_id LEFT JOIN users o ON o.id = c.owner_id LEFT JOIN users cab ON cab.id = c.cab_decided_by WHERE c.id = ? LIMIT 1');
    $query->execute([$changeId]);
    return $query->fetch() ?: null;
}

function gov_fetch_problem(int $problemId): ?array
{
    $query = db()->prepare('SELECT p.*, d.name AS department_name, c.full_name AS creator_name, o.full_name AS owner_name FROM problem_records p LEFT JOIN departments d ON d.id = p.department_id JOIN users c ON c.id = p.created_by LEFT JOIN users o ON o.id = p.owner_id WHERE p.id = ? LIMIT 1');
    $query->execute([$problemId]);
    return $query->fetch() ?: null;
}

function gov_can_view_record(array $record, array $user): bool
{
    if (in_array($user['role'], ['primary_admin', 'supervisor', 'support_manager', 'inspector'], true)) {
        return true;
    }
    if (in_array((string) $user['role'], ['manager', 'support_manager'], true)) {
        return (int) ($user['department_id'] ?? 0) > 0 && (int) $record['department_id'] > 0 && (int) $record['department_id'] === (int) $user['department_id'];
    }
    return $user['role'] === 'agent' && ((int) $record['owner_id'] === (int) $user['id'] || ((int) ($user['department_id'] ?? 0) > 0 && (int) $record['department_id'] > 0 && (int) $record['department_id'] === (int) $user['department_id']));
}

function gov_datetime_input(?string $value): string
{
    return $value ? str_replace(' ', 'T', substr($value, 0, 16)) : '';
}

function gov_ticket_options(array $user, int $selected = 0): string
{
    [$where, $params] = ticket_scope($user);
    $query = db()->prepare('SELECT t.id, t.subject, t.ticket_type FROM tickets t ' . ($where ?: 'WHERE 1 = 1') . ' ORDER BY t.created_at DESC LIMIT 300');
    $query->execute($params);
    $html = '<option value="">بدون تیکت مرجع</option>';
    foreach ($query->fetchAll() as $ticket) {
        $label = ticket_number((int) $ticket['id']) . ' • ' . $ticket['subject'] . ' • ' . ticket_type_label((string) $ticket['ticket_type']);
        $html .= '<option value="' . (int) $ticket['id'] . '" ' . ((int) $ticket['id'] === $selected ? 'selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function gov_notify_linked_tickets(string $table, string $column, int $recordId, string $type, string $title, string $body, int $excludeUserId): void
{
    if (!in_array($table, ['change_ticket_links', 'problem_ticket_links'], true) || !in_array($column, ['change_id', 'problem_id'], true)) {
        return;
    }
    $query = db()->prepare('SELECT ticket_id FROM ' . $table . ' WHERE ' . $column . ' = ?');
    $query->execute([$recordId]);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $ticketId) {
        notify_ticket_parties((int) $ticketId, $type, $title, $body, $excludeUserId);
    }
}

function gov_header(string $title, array $user): void
{
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    $logo = setting('app_logo', (string) cfg('app.logo', ''));
    $__notif = notification_header_summary((int) $user['id']);
    $notificationCount = $__notif['unread'];
    $staffLinks = '<a href="index.php?page=queue">صف کاری</a><a href="index.php?page=reports">گزارش‌ها</a><a href="index.php?page=assets">شناسنامه‌های فنی</a><a href="governance.php">تغییر و مشکل</a>';
    if (in_array($user['role'], ['supervisor', 'primary_admin', 'support_manager'], true)) {
        $staffLinks .= '<a href="index.php?page=supervisor">پنل سوپروایزر</a>';
    }
    if (in_array((string) $user['role'], ['primary_admin'], true)) {
        $staffLinks .= '<a href="index.php?page=organization">سازمان</a><a href="index.php?page=settings">تنظیمات</a><a href="ola.php">OLA</a>';
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title . ' | ' . $appName) . '</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><header class="topbar"><div class="topbar-inner"><a class="brand" href="index.php"><span class="brand-mark">' . ($logo ? '<img src="' . e($logo) . '" alt="' . e($appName) . '">' : '<span class="brand-glyph">پ</span>') . '</span><span><strong>' . e($appName) . '</strong><small>مرکز خدمات و پشتیبانی</small></span></a><nav class="topnav"><a href="index.php">داشبورد</a><a href="index.php?page=new-ticket">ثبت تیکت</a><a href="index.php?page=knowledge">دانش‌نامه</a>' . $staffLinks . '<a class="notification-link" href="index.php?page=notifications">اعلان‌ها' . ($notificationCount > 0 ? '<b>' . $notificationCount . '</b>' : '') . '</a><span class="user-chip">' . e($user['full_name']) . '</span><form class="logout-form" method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="logout-link" type="submit">خروج</button></form></nav></div></header><div class="content-wrap"><div id="notification-toasts" class="notification-toasts" data-cursor="' . (int) $__notif['latest'] . '" aria-live="polite"></div>';
    foreach (take_flash() as $flash) {
        echo '<div class="alert ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function gov_footer(): void
{
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    echo '</div><footer class="footer">' . e($appName) . ' • نسخه سازمانی فارسی</footer></div><script src="assets/app.js"></script></body></html>';
}

$user = require_staff();
// ۱.۳۷.۷ — هم‌راستا با منو و کاتالوگ: ورود به «تغییر و مشکل» گذشته از نقش کارمندی، کد دسترسی هم
// می‌خواهد. پیش‌فرض همهٔ نقش‌های کارمندی این کد را دارند؛ فقط اگر ادمین اصلی آن را از نقشی
// بگیرد اثر می‌کند.
if (function_exists('user_can') && !user_can($user, 'governance.view')) {
    http_response_code(403);
    exit('دسترسی به بخش تغییر و مشکل برای نقش شما مجاز نیست.');
}

$governanceTables = ['change_records', 'change_approvals', 'change_ticket_links', 'problem_records', 'problem_ticket_links'];
foreach ($governanceTables as $governanceTable) {
    if (!db_table_exists($governanceTable)) {
        gov_header('مدیریت تغییر و مشکل', $user);
        echo '<section class="card empty-state"><h2>ماژول حاکمیت ITSM هنوز نصب نشده است.</h2><p>فایل <code>upgrade-1.7-governance.sql</code> را یک‌بار روی پایگاه‌داده اجرا کنید.</p><a class="button" href="index.php">بازگشت به داشبورد</a></section>';
        gov_footer();
        exit;
    }
}

$governanceTicketColumns = db_missing_columns('tickets', ['subject', 'requester_id', 'department_id', 'ticket_type', 'service_group', 'created_at']);
if ($governanceTicketColumns) {
    gov_header('مدیریت تغییر و مشکل', $user);
    echo '<section class="card empty-state"><h2>ساختار تیکت برای این ماژول کامل نیست.</h2><p>ابتدا Migrationهای تیکت و مسیر‌دهی را اجرا کنید، سپس صفحه را تازه‌سازی کنید.</p><p><code>upgrade-1.2-itsm.sql</code> و <code>upgrade-1.8-routing.sql</code></p><p class="muted">ستون‌های ناقص: ' . e(implode(', ', $governanceTicketColumns)) . '</p><a class="button" href="index.php">بازگشت به داشبورد</a></section>';
    gov_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // هر کار ثبتی (ایجاد/ویرایش/ارسال/تصمیم/انتقال/ارتباط) فقط با کد ویرایش «تغییر و مشکل»
    if (function_exists('user_can') && !user_can($user, 'governance.manage')) {
        http_response_code(403);
        exit('دسترسی ویرایش تغییر و مشکل برای نقش شما مجاز نیست.');
    }
    require_csrf();
    $action = gov_post('action');
    try {
        if ($action === 'create_change') {
            $title = gov_post('title');
            $description = gov_post('description');
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = $ticketId > 0 ? gov_fetch_ticket($ticketId) : null;
            if ($title === '' || $description === '') {
                throw new RuntimeException('عنوان و شرح تغییر الزامی است.');
            }
            if ($ticketId > 0 && (!$ticket || !gov_can_view_ticket($ticket, $user))) {
                throw new RuntimeException('تیکت مرجع پیدا نشد یا دسترسی ندارید.');
            }
            $departmentId = $ticket ? (int) ($ticket['department_id'] ?: 0) : (int) ($user['department_id'] ?? 0);
            if (in_array((string) $user['role'], ['manager', 'support_manager'], true)) {
                if ((int) ($user['department_id'] ?? 0) <= 0 || $departmentId <= 0 || $departmentId !== (int) $user['department_id']) {
                    throw new RuntimeException('مدیر واحد بدون معاونت معتبر نمی‌تواند Change ثبت کند.');
                }
            }
            $start = gov_parse_datetime(gov_post('planned_start'));
            $end = gov_parse_datetime(gov_post('planned_end'));
            if (gov_post('planned_start') !== '' && $start === null || gov_post('planned_end') !== '' && $end === null) {
                throw new RuntimeException('تاریخ زمان‌بندی معتبر نیست.');
            }
            if ($start && $end && $end < $start) {
                throw new RuntimeException('پایان برنامه تغییر باید بعد از شروع آن باشد.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO change_records (change_number, title, description, justification, risk_level, impact, planned_start, planned_end, department_id, requester_id, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['TMP-' . bin2hex(random_bytes(8)), $title, $description, gov_post('justification') ?: null, gov_choice(gov_post('risk_level', 'medium'), ['low', 'medium', 'high', 'critical'], 'medium'), gov_post('impact') ?: null, $start, $end, $departmentId ?: null, $user['id'], $user['id']]);
            $changeId = (int) db()->lastInsertId();
            db()->prepare('UPDATE change_records SET change_number = ? WHERE id = ?')->execute([gov_change_number($changeId), $changeId]);
            if ($ticketId > 0) {
                db()->prepare('INSERT INTO change_ticket_links (change_id, ticket_id, created_by) VALUES (?, ?, ?)')->execute([$changeId, $ticketId, $user['id']]);
            }
            save_audit((int) $user['id'], 'change_created', $ticketId > 0 ? $ticketId : null, ['change_id' => $changeId, 'change_number' => gov_change_number($changeId)]);
            db()->commit();
            flash('success', 'درخواست تغییر ' . gov_change_number($changeId) . ' ساخته شد.');
            redirect('governance.php?change_id=' . $changeId);
        }

        if ($action === 'update_change') {
            $changeId = (int) ($_POST['change_id'] ?? 0);
            $change = gov_fetch_change($changeId);
            if (!$change || !gov_can_view_record($change, $user) || !in_array($change['status'], ['draft', 'rejected'], true)) {
                throw new RuntimeException('فقط پیش‌نویس یا تغییر ردشده قابل اصلاح است.');
            }
            $title = gov_post('title');
            $description = gov_post('description');
            $start = gov_parse_datetime(gov_post('planned_start'));
            $end = gov_parse_datetime(gov_post('planned_end'));
            if ($title === '' || $description === '') {
                throw new RuntimeException('عنوان و شرح تغییر الزامی است.');
            }
            if (gov_post('planned_start') !== '' && $start === null || gov_post('planned_end') !== '' && $end === null || ($start && $end && $end < $start)) {
                throw new RuntimeException('بازه زمان‌بندی تغییر معتبر نیست.');
            }
            db()->prepare('UPDATE change_records SET title = ?, description = ?, justification = ?, risk_level = ?, impact = ?, planned_start = ?, planned_end = ?, updated_at = NOW() WHERE id = ? AND status IN ("draft", "rejected")')->execute([$title, $description, gov_post('justification') ?: null, gov_choice(gov_post('risk_level', 'medium'), ['low', 'medium', 'high', 'critical'], 'medium'), gov_post('impact') ?: null, $start, $end, $changeId]);
            save_audit((int) $user['id'], 'change_updated', null, ['change_id' => $changeId, 'change_number' => $change['change_number']]);
            flash('success', 'اطلاعات تغییر اصلاح شد.');
            redirect('governance.php?change_id=' . $changeId);
        }

        if ($action === 'submit_change') {
            $changeId = (int) ($_POST['change_id'] ?? 0);
            $change = gov_fetch_change($changeId);
            if (!$change || !gov_can_view_record($change, $user) || !in_array($change['status'], ['draft', 'rejected'], true)) {
                throw new RuntimeException('این تغییر قابل ارسال برای CAB نیست.');
            }
            db()->beginTransaction();
            $submit = db()->prepare('UPDATE change_records SET status = "submitted", cab_decided_by = NULL, cab_decided_at = NULL, cab_note = NULL, updated_at = NOW() WHERE id = ? AND status IN ("draft", "rejected")');
            $submit->execute([$changeId]);
            if ($submit->rowCount() !== 1) {
                throw new RuntimeException('وضعیت تغییر هم‌زمان تغییر کرده است؛ صفحه را تازه‌سازی کنید.');
            }
            db()->prepare('INSERT INTO change_approvals (change_id, approver_id, decision, note) VALUES (?, ?, "submitted", ?)')->execute([$changeId, $user['id'], gov_post('note') ?: null]);
            save_audit((int) $user['id'], 'change_submitted', null, ['change_id' => $changeId, 'change_number' => $change['change_number']]);
            db()->commit();
            flash('success', 'تغییر برای بررسی CAB ارسال شد.');
            redirect('governance.php?change_id=' . $changeId);
        }

        if ($action === 'cab_decision') {
            $cabUser = require_supervisor();
            $changeId = (int) ($_POST['change_id'] ?? 0);
            $decision = gov_choice(gov_post('decision'), ['approved', 'rejected'], 'rejected');
            $change = gov_fetch_change($changeId);
            if (!$change || !gov_can_view_record($change, $cabUser) || !in_array($change['status'], ['submitted', 'cab_review'], true)) {
                throw new RuntimeException('این تغییر در صف تصمیم CAB نیست.');
            }
            $note = gov_post('note');
            db()->beginTransaction();
            $cabUpdate = db()->prepare('UPDATE change_records SET status = ?, cab_decided_by = ?, cab_decided_at = NOW(), cab_note = ?, updated_at = NOW() WHERE id = ? AND status IN ("submitted", "cab_review")');
            $cabUpdate->execute([$decision, $cabUser['id'], $note ?: null, $changeId]);
            if ($cabUpdate->rowCount() !== 1) {
                throw new RuntimeException('تصمیم CAB هم‌زمان ثبت شده است؛ صفحه را تازه‌سازی کنید.');
            }
            db()->prepare('INSERT INTO change_approvals (change_id, approver_id, decision, note) VALUES (?, ?, ?, ?)')->execute([$changeId, $cabUser['id'], $decision, $note ?: null]);
            save_audit((int) $cabUser['id'], 'change_cab_' . $decision, null, ['change_id' => $changeId, 'change_number' => $change['change_number']]);
            gov_notify_linked_tickets('change_ticket_links', 'change_id', $changeId, 'change_cab_' . $decision, 'تصمیم CAB برای تغییر', 'تغییر ' . $change['change_number'] . ' به وضعیت «' . gov_change_status_label($decision) . '» رسید.', (int) $cabUser['id']);
            db()->commit();
            flash($decision === 'approved' ? 'success' : 'info', $decision === 'approved' ? 'تغییر توسط CAB تأیید شد.' : 'تغییر توسط CAB رد شد.');
            redirect('governance.php?change_id=' . $changeId);
        }

        if ($action === 'change_transition') {
            $changeId = (int) ($_POST['change_id'] ?? 0);
            $transition = gov_choice(gov_post('transition'), ['scheduled', 'implemented', 'rolled_back', 'closed'], 'scheduled');
            $change = gov_fetch_change($changeId);
            $allowed = ['scheduled' => ['approved'], 'implemented' => ['scheduled'], 'rolled_back' => ['scheduled', 'implemented'], 'closed' => ['implemented', 'rolled_back']];
            if (!$change || !gov_can_view_record($change, $user) || !in_array($change['status'], $allowed[$transition], true)) {
                throw new RuntimeException('انتقال انتخاب‌شده برای وضعیت فعلی تغییر مجاز نیست.');
            }
            $note = gov_post('implementation_note');
            db()->beginTransaction();
            $allowedStatuses = implode(', ', array_map(static fn (string $status): string => '"' . $status . '"', $allowed[$transition]));
            $transitionUpdate = db()->prepare('UPDATE change_records SET status = ?, implementation_note = ?, updated_at = NOW() WHERE id = ? AND status IN (' . $allowedStatuses . ')');
            $transitionUpdate->execute([$transition, $note ?: null, $changeId]);
            if ($transitionUpdate->rowCount() !== 1) {
                throw new RuntimeException('وضعیت تغییر هم‌زمان عوض شده است؛ صفحه را تازه‌سازی کنید.');
            }
            db()->prepare('INSERT INTO change_approvals (change_id, approver_id, decision, note) VALUES (?, ?, ?, ?)')->execute([$changeId, $user['id'], $transition, $note ?: null]);
            save_audit((int) $user['id'], 'change_' . $transition, null, ['change_id' => $changeId, 'change_number' => $change['change_number']]);
            gov_notify_linked_tickets('change_ticket_links', 'change_id', $changeId, 'change_' . $transition, 'به‌روزرسانی تغییر', 'تغییر ' . $change['change_number'] . ' به وضعیت «' . gov_change_status_label($transition) . '» رسید.', (int) $user['id']);
            db()->commit();
            flash('success', 'وضعیت تغییر به‌روزرسانی شد.');
            redirect('governance.php?change_id=' . $changeId);
        }

        if ($action === 'create_problem') {
            $title = gov_post('title');
            $description = gov_post('description');
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = $ticketId > 0 ? gov_fetch_ticket($ticketId) : null;
            if ($title === '' || $description === '') {
                throw new RuntimeException('عنوان و شرح مشکل الزامی است.');
            }
            if ($ticketId > 0 && (!$ticket || !gov_can_view_ticket($ticket, $user))) {
                throw new RuntimeException('تیکت مرجع پیدا نشد یا دسترسی ندارید.');
            }
            $departmentId = $ticket ? (int) ($ticket['department_id'] ?: 0) : (int) ($user['department_id'] ?? 0);
            if (in_array((string) $user['role'], ['manager', 'support_manager'], true)) {
                if ((int) ($user['department_id'] ?? 0) <= 0 || $departmentId <= 0 || $departmentId !== (int) $user['department_id']) {
                    throw new RuntimeException('مدیر واحد بدون معاونت معتبر نمی‌تواند Problem ثبت کند.');
                }
            }
            $knownError = !empty($_POST['known_error']) ? 1 : 0;
            db()->beginTransaction();
            db()->prepare('INSERT INTO problem_records (problem_number, title, description, root_cause, workaround, known_error, priority, status, department_id, created_by, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(['TMP-' . bin2hex(random_bytes(8)), $title, $description, gov_post('root_cause') ?: null, gov_post('workaround') ?: null, $knownError, gov_choice(gov_post('priority', 'normal'), ['normal', 'urgent', 'critical'], 'normal'), $knownError ? 'known_error' : 'open', $departmentId ?: null, $user['id'], $user['id']]);
            $problemId = (int) db()->lastInsertId();
            db()->prepare('UPDATE problem_records SET problem_number = ? WHERE id = ?')->execute([gov_problem_number($problemId), $problemId]);
            if ($ticketId > 0) {
                db()->prepare('INSERT INTO problem_ticket_links (problem_id, ticket_id, created_by) VALUES (?, ?, ?)')->execute([$problemId, $ticketId, $user['id']]);
            }
            save_audit((int) $user['id'], 'problem_created', $ticketId > 0 ? $ticketId : null, ['problem_id' => $problemId, 'problem_number' => gov_problem_number($problemId)]);
            db()->commit();
            flash('success', 'رکورد مشکل ' . gov_problem_number($problemId) . ' ساخته شد.');
            redirect('governance.php?problem_id=' . $problemId);
        }

        if ($action === 'update_problem') {
            $problemId = (int) ($_POST['problem_id'] ?? 0);
            $problem = gov_fetch_problem($problemId);
            $status = gov_choice(gov_post('status'), ['open', 'investigation', 'known_error', 'resolved', 'closed'], 'open');
            if (!$problem || !gov_can_view_record($problem, $user)) {
                throw new RuntimeException('مشکل پیدا نشد یا دسترسی ندارید.');
            }
            if ($status === 'closed' && $problem['status'] !== 'resolved') {
                throw new RuntimeException('بستن مشکل فقط پس از حل‌شدن آن مجاز است.');
            }
            $knownError = $status === 'known_error' || !empty($_POST['known_error']) ? 1 : 0;
            if ($knownError === 1 && in_array($status, ['open', 'investigation'], true)) {
                $status = 'known_error';
            }
            $resolvedAt = in_array($status, ['resolved', 'closed'], true) ? ($problem['resolved_at'] ?: date('Y-m-d H:i:s')) : null;
            db()->prepare('UPDATE problem_records SET root_cause = ?, workaround = ?, known_error = ?, priority = ?, status = ?, owner_id = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?')->execute([gov_post('root_cause') ?: null, gov_post('workaround') ?: null, $knownError, gov_choice(gov_post('priority', 'normal'), ['normal', 'urgent', 'critical'], 'normal'), $status, $user['id'], $resolvedAt, $problemId]);
            save_audit((int) $user['id'], 'problem_updated', null, ['problem_id' => $problemId, 'problem_number' => $problem['problem_number'], 'status' => $status]);
            gov_notify_linked_tickets('problem_ticket_links', 'problem_id', $problemId, 'problem_updated', 'مشکل مرتبط به‌روزرسانی شد', 'رکورد مشکل ' . $problem['problem_number'] . ' به وضعیت «' . gov_problem_status_label($status) . '» رسید.', (int) $user['id']);
            flash('success', 'رکورد مشکل به‌روزرسانی شد.');
            redirect('governance.php?problem_id=' . $problemId);
        }

        if ($action === 'link_governance_ticket') {
            $kind = gov_choice(gov_post('kind'), ['change', 'problem'], 'problem');
            $recordId = (int) ($_POST['record_id'] ?? 0);
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $record = $kind === 'change' ? gov_fetch_change($recordId) : gov_fetch_problem($recordId);
            $ticket = gov_fetch_ticket($ticketId);
            if (!$record || !$ticket || !gov_can_view_record($record, $user) || !gov_can_view_ticket($ticket, $user)) {
                throw new RuntimeException('رکورد یا تیکت برای اتصال معتبر نیست.');
            }
            $table = $kind === 'change' ? 'change_ticket_links' : 'problem_ticket_links';
            $column = $kind === 'change' ? 'change_id' : 'problem_id';
            db()->prepare('INSERT IGNORE INTO ' . $table . ' (' . $column . ', ticket_id, created_by) VALUES (?, ?, ?)')->execute([$recordId, $ticketId, $user['id']]);
            save_audit((int) $user['id'], $kind . '_ticket_linked', $ticketId, [$column => $recordId, 'ticket_id' => $ticketId]);
            flash('success', 'تیکت به رکورد مدیریتی متصل شد.');
            redirect('governance.php?' . ($kind === 'change' ? 'change_id' : 'problem_id') . '=' . $recordId);
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('ITSM governance request failed: ' . $exception->getMessage());
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'عملیات انجام نشد. گزارش خطا در لاگ سامانه ثبت شد.');
        redirect('governance.php');
    }
}

$changeId = (int) ($_GET['change_id'] ?? 0);
$problemId = (int) ($_GET['problem_id'] ?? 0);
$selectedChange = $changeId > 0 ? gov_fetch_change($changeId) : null;
$selectedProblem = $problemId > 0 ? gov_fetch_problem($problemId) : null;
if (($selectedChange && !gov_can_view_record($selectedChange, $user)) || ($selectedProblem && !gov_can_view_record($selectedProblem, $user))) {
    http_response_code(403);
    exit('دسترسی به این رکورد مجاز نیست.');
}

[$changeWhere, $changeParams] = gov_scope($user, 'c');
$changeQuery = db()->prepare('SELECT c.*, d.name AS department_name, o.full_name AS owner_name FROM change_records c LEFT JOIN departments d ON d.id = c.department_id LEFT JOIN users o ON o.id = c.owner_id ' . ($changeWhere ?: 'WHERE 1 = 1') . ' ORDER BY CASE WHEN c.status IN ("submitted", "cab_review") THEN 0 ELSE 1 END, c.updated_at DESC LIMIT 100');
$changeQuery->execute($changeParams);
$changes = $changeQuery->fetchAll();
[$problemWhere, $problemParams] = gov_scope($user, 'p');
$problemQuery = db()->prepare('SELECT p.*, d.name AS department_name, o.full_name AS owner_name FROM problem_records p LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users o ON o.id = p.owner_id ' . ($problemWhere ?: 'WHERE 1 = 1') . ' ORDER BY CASE WHEN p.status IN ("open", "investigation", "known_error") THEN 0 ELSE 1 END, p.updated_at DESC LIMIT 100');
$problemQuery->execute($problemParams);
$problems = $problemQuery->fetchAll();
$preselectedTicket = (int) ($_GET['ticket_id'] ?? 0);

gov_header('مدیریت تغییر و مشکل', $user);
echo '<section class="page-heading"><div><span class="eyebrow">ITSM Governance</span><h1>مدیریت تغییر و مشکل</h1><p>درخواست‌های تغییر با تأیید CAB و مشکلات ریشه‌ای با Known Error و راه‌حل موقت ثبت و پیگیری می‌شوند.</p></div><a class="button secondary" href="index.php?page=reports">گزارش تیکت‌ها</a></section>';
echo '<section class="settings-grid"><div class="card form-card"><h2>ثبت درخواست تغییر</h2><p class="muted">تغییر پس از ارسال برای تصمیم سوپروایزر/CAB آماده می‌شود.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create_change"><div class="form-grid"><label class="full">عنوان تغییر<input name="title" required maxlength="255" placeholder="مثلاً ارتقای سرور مالی"></label><label>ریسک<select name="risk_level"><option value="low">کم</option><option value="medium" selected>متوسط</option><option value="high">بالا</option><option value="critical">بحرانی</option></select></label><label>تیکت مرجع<select name="ticket_id">' . gov_ticket_options($user, $preselectedTicket) . '</select></label><label>شروع برنامه<input type="datetime-local" name="planned_start"></label><label>پایان برنامه<input type="datetime-local" name="planned_end"></label><label class="full">شرح فنی<textarea name="description" required rows="4"></textarea></label><label class="full">دلیل و منفعت<textarea name="justification" rows="3"></textarea></label><label class="full">اثر و سامانه‌های متاثر<textarea name="impact" rows="3"></textarea></label></div><button class="button" type="submit">ساخت پیش‌نویس تغییر</button></form></div>';
echo '<div class="card form-card"><h2>ثبت مشکل ریشه‌ای</h2><p class="muted">چند رخداد مرتبط را می‌توان بعداً به یک Problem متصل کرد.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create_problem"><div class="form-grid"><label class="full">عنوان مشکل<input name="title" required maxlength="255" placeholder="مثلاً قطعی تکرارشونده چاپ در واحد مالی"></label><label>اولویت<select name="priority"><option value="normal">عادی</option><option value="urgent">فوری</option><option value="critical">حیاتی</option></select></label><label>تیکت مرجع<select name="ticket_id">' . gov_ticket_options($user, $preselectedTicket) . '</select></label><label class="full">شرح نشانه‌ها و دامنه اثر<textarea name="description" required rows="4"></textarea></label><label class="full">علت ریشه‌ای، در صورت مشخص‌بودن<textarea name="root_cause" rows="3"></textarea></label><label class="full">راه‌حل موقت / Workaround<textarea name="workaround" rows="3"></textarea></label><label class="check-label"><input type="checkbox" name="known_error" value="1"> خطای شناخته‌شده است</label></div><button class="button" type="submit">ساخت رکورد مشکل</button></form></div></section>';

echo '<section class="organization-grid"><section class="card form-card"><h2>درخواست‌های تغییر</h2><div class="category-list">';
foreach ($changes as $change) {
    echo '<div><span><a href="governance.php?change_id=' . (int) $change['id'] . '"><strong>' . e($change['change_number']) . ' • ' . e($change['title']) . '</strong></a><small>' . e($change['department_name'] ?: 'بدون معاونت') . ' • مالک: ' . e($change['owner_name'] ?: 'تعیین نشده') . '</small></span><span class="status ' . e($change['status']) . '">' . e(gov_change_status_label($change['status'])) . '</span></div>';
}
if (!$changes) {
    echo '<div class="empty-state"><h3>درخواستی ثبت نشده است.</h3></div>';
}
echo '</div></section><section class="card form-card"><h2>مشکلات و Known Error</h2><div class="category-list">';
foreach ($problems as $problem) {
    echo '<div><span><a href="governance.php?problem_id=' . (int) $problem['id'] . '"><strong>' . e($problem['problem_number']) . ' • ' . e($problem['title']) . '</strong></a><small>' . e($problem['department_name'] ?: 'بدون معاونت') . ' • ' . e(priority_label($problem['priority'])) . '</small></span><span class="status ' . e($problem['status']) . '">' . e(gov_problem_status_label($problem['status'])) . '</span></div>';
}
if (!$problems) {
    echo '<div class="empty-state"><h3>مشکلی ثبت نشده است.</h3></div>';
}
echo '</div></section></section>';

if ($selectedChange) {
    $linkQuery = db()->prepare('SELECT l.ticket_id, t.subject, t.ticket_type FROM change_ticket_links l JOIN tickets t ON t.id = l.ticket_id WHERE l.change_id = ? ORDER BY l.created_at DESC');
    $linkQuery->execute([$selectedChange['id']]);
    $linkedTickets = array_values(array_filter($linkQuery->fetchAll(), static function (array $linkedTicket) use ($user): bool {
        $ticket = gov_fetch_ticket((int) $linkedTicket['ticket_id']);
        return $ticket !== null && gov_can_view_ticket($ticket, $user);
    }));
    $approvalQuery = db()->prepare('SELECT a.*, u.full_name FROM change_approvals a LEFT JOIN users u ON u.id = a.approver_id WHERE a.change_id = ? ORDER BY a.created_at DESC');
    $approvalQuery->execute([$selectedChange['id']]);
    echo '<section class="card form-card governance-detail"><div class="page-heading"><div><span class="eyebrow">Change Record</span><h2>' . e($selectedChange['change_number']) . ' • ' . e($selectedChange['title']) . '</h2><p>' . e(gov_change_status_label($selectedChange['status'])) . ' • ریسک ' . e(gov_risk_label($selectedChange['risk_level'])) . '</p></div><a class="button secondary" href="governance.php">بستن جزئیات</a></div><div class="form-grid"><div><strong>شرح فنی</strong><p class="muted">' . nl2br(e($selectedChange['description'])) . '</p></div><div><strong>دلیل و منفعت</strong><p class="muted">' . nl2br(e($selectedChange['justification'] ?: 'ثبت نشده')) . '</p></div><div><strong>اثر و سامانه‌های متاثر</strong><p class="muted">' . nl2br(e($selectedChange['impact'] ?: 'ثبت نشده')) . '</p></div><div><strong>زمان برنامه</strong><p class="muted">' . e($selectedChange['planned_start'] ?: 'ثبت نشده') . ' تا ' . e($selectedChange['planned_end'] ?: 'ثبت نشده') . '</p></div></div><div class="actions">';
    if (in_array($selectedChange['status'], ['draft', 'rejected'], true)) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="submit_change"><input type="hidden" name="change_id" value="' . (int) $selectedChange['id'] . '"><button class="button" type="submit">ارسال برای CAB</button></form>';
    }
    if (in_array($user['role'], ['supervisor', 'primary_admin', 'support_manager'], true) && in_array($selectedChange['status'], ['submitted', 'cab_review'], true)) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="cab_decision"><input type="hidden" name="change_id" value="' . (int) $selectedChange['id'] . '"><input type="hidden" name="decision" value="approved"><input name="note" placeholder="یادداشت CAB اختیاری"><button class="button" type="submit">تأیید CAB</button></form><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="cab_decision"><input type="hidden" name="change_id" value="' . (int) $selectedChange['id'] . '"><input type="hidden" name="decision" value="rejected"><input name="note" placeholder="دلیل رد"><button class="button danger-button" type="submit">رد CAB</button></form>';
    }
    $transitions = ['approved' => 'زمان‌بندی', 'scheduled' => 'ثبت اجرای موفق', 'implemented' => 'بستن تغییر', 'rolled_back' => 'بستن پس از بازگشت'];
    foreach ($transitions as $from => $label) {
        if ($selectedChange['status'] === $from) {
            $to = $from === 'approved' ? 'scheduled' : ($from === 'scheduled' ? 'implemented' : 'closed');
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="change_transition"><input type="hidden" name="change_id" value="' . (int) $selectedChange['id'] . '"><input type="hidden" name="transition" value="' . $to . '"><input name="implementation_note" placeholder="یادداشت اجرا اختیاری"><button class="button secondary" type="submit">' . $label . '</button></form>';
        }
    }
    if (in_array($selectedChange['status'], ['scheduled', 'implemented'], true)) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="change_transition"><input type="hidden" name="change_id" value="' . (int) $selectedChange['id'] . '"><input type="hidden" name="transition" value="rolled_back"><input name="implementation_note" placeholder="دلیل بازگشت"><button class="button danger-button" type="submit">بازگشت تغییر</button></form>';
    }
    if (in_array($selectedChange['status'], ['draft', 'rejected'], true)) {
        echo '</div><h3>اصلاح پیش‌نویس تغییر</h3><form method="post" class="governance-edit">' . csrf_field() . '<input type="hidden" name="action" value="update_change"><input type="hidden" name="change_id" value="' . (int) $selectedChange['id'] . '"><div class="form-grid"><label class="full">عنوان تغییر<input name="title" required maxlength="255" value="' . e($selectedChange['title']) . '"></label><label>ریسک<select name="risk_level">';
        foreach (['low', 'medium', 'high', 'critical'] as $risk) {
            echo '<option value="' . $risk . '" ' . ($selectedChange['risk_level'] === $risk ? 'selected' : '') . '>' . e(gov_risk_label($risk)) . '</option>';
        }
        echo '</select></label><label>شروع برنامه<input type="datetime-local" name="planned_start" value="' . e(gov_datetime_input($selectedChange['planned_start'])) . '"></label><label>پایان برنامه<input type="datetime-local" name="planned_end" value="' . e(gov_datetime_input($selectedChange['planned_end'])) . '"></label><label class="full">شرح فنی<textarea name="description" required rows="4">' . e($selectedChange['description']) . '</textarea></label><label class="full">دلیل و منفعت<textarea name="justification" rows="3">' . e($selectedChange['justification'] ?: '') . '</textarea></label><label class="full">اثر و سامانه‌های متاثر<textarea name="impact" rows="3">' . e($selectedChange['impact'] ?: '') . '</textarea></label></div><button class="button secondary" type="submit">ذخیره اصلاحات</button></form>';
    }
    if (!in_array($selectedChange['status'], ['draft', 'rejected'], true)) {
        echo '</div>';
    }
    echo '<h3>تیکت‌های مرتبط</h3><div class="category-list">';
    foreach ($linkedTickets as $linkedTicket) {
        echo '<div><span><a href="index.php?page=ticket&id=' . (int) $linkedTicket['ticket_id'] . '"><strong>' . e(ticket_number((int) $linkedTicket['ticket_id'])) . ' • ' . e($linkedTicket['subject']) . '</strong></a><small>' . e(ticket_type_label($linkedTicket['ticket_type'])) . '</small></span></div>';
    }
    echo '</div><form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="link_governance_ticket"><input type="hidden" name="kind" value="change"><input type="hidden" name="record_id" value="' . (int) $selectedChange['id'] . '"><label>اتصال تیکت<select name="ticket_id" required>' . gov_ticket_options($user) . '</select></label><button class="button secondary" type="submit">اتصال</button></form><h3>تاریخچه CAB و اجرا</h3><div class="event-list">';
    foreach ($approvalQuery->fetchAll() as $approval) {
        echo '<div class="event-item"><span class="event-dot"></span><div><strong>' . e(gov_change_status_label($approval['decision'])) . '</strong><small>' . e($approval['full_name'] ?: 'سامانه') . ' • ' . e(jalali_date($approval['created_at'])) . '</small></div><em>' . e($approval['note'] ?: '') . '</em></div>';
    }
    echo '</div></section>';
}

if ($selectedProblem) {
    $linkQuery = db()->prepare('SELECT l.ticket_id, t.subject, t.ticket_type FROM problem_ticket_links l JOIN tickets t ON t.id = l.ticket_id WHERE l.problem_id = ? ORDER BY l.created_at DESC');
    $linkQuery->execute([$selectedProblem['id']]);
    $linkedTickets = array_values(array_filter($linkQuery->fetchAll(), static function (array $linkedTicket) use ($user): bool {
        $ticket = gov_fetch_ticket((int) $linkedTicket['ticket_id']);
        return $ticket !== null && gov_can_view_ticket($ticket, $user);
    }));
    echo '<section class="card form-card governance-detail"><div class="page-heading"><div><span class="eyebrow">Problem Record</span><h2>' . e($selectedProblem['problem_number']) . ' • ' . e($selectedProblem['title']) . '</h2><p>' . e(gov_problem_status_label($selectedProblem['status'])) . ' • ' . e(priority_label($selectedProblem['priority'])) . '</p></div><a class="button secondary" href="governance.php">بستن جزئیات</a></div><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="update_problem"><input type="hidden" name="problem_id" value="' . (int) $selectedProblem['id'] . '"><div class="form-grid"><label>وضعیت<select name="status">';
    foreach (['open', 'investigation', 'known_error', 'resolved', 'closed'] as $status) {
        echo '<option value="' . $status . '" ' . ($selectedProblem['status'] === $status ? 'selected' : '') . '>' . e(gov_problem_status_label($status)) . '</option>';
    }
    echo '</select></label><label>اولویت<select name="priority">';
    foreach (['normal', 'urgent', 'critical'] as $priority) {
        echo '<option value="' . $priority . '" ' . ($selectedProblem['priority'] === $priority ? 'selected' : '') . '>' . e(priority_label($priority)) . '</option>';
    }
    echo '</select></label><label class="full">علت ریشه‌ای<textarea name="root_cause" rows="4">' . e($selectedProblem['root_cause'] ?: '') . '</textarea></label><label class="full">راه‌حل موقت / Workaround<textarea name="workaround" rows="4">' . e($selectedProblem['workaround'] ?: '') . '</textarea></label><label class="check-label"><input type="checkbox" name="known_error" value="1" ' . ((int) $selectedProblem['known_error'] === 1 ? 'checked' : '') . '> خطای شناخته‌شده</label></div><button class="button" type="submit">ذخیره تحلیل مشکل</button></form><h3>تیکت‌های مرتبط</h3><div class="category-list">';
    foreach ($linkQuery->fetchAll() as $linkedTicket) {
        echo '<div><span><a href="index.php?page=ticket&id=' . (int) $linkedTicket['ticket_id'] . '"><strong>' . e(ticket_number((int) $linkedTicket['ticket_id'])) . ' • ' . e($linkedTicket['subject']) . '</strong></a><small>' . e(ticket_type_label($linkedTicket['ticket_type'])) . '</small></span></div>';
    }
    echo '</div><form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="link_governance_ticket"><input type="hidden" name="kind" value="problem"><input type="hidden" name="record_id" value="' . (int) $selectedProblem['id'] . '"><label>اتصال تیکت<select name="ticket_id" required>' . gov_ticket_options($user) . '</select></label><button class="button secondary" type="submit">اتصال</button></form></section>';
}

gov_footer();
