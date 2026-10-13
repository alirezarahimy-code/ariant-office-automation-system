<?php
declare(strict_types=1);

// کنترل تردد مراجعین و میهمانان — پورت‌شده از کنترل_تردد.xlsm
// همه‌ی توابع با پیشوند traffic_ برای جلوگیری از تداخل نام با بقیه ماژول‌ها.

function traffic_can_manage(array $user): bool
{
    return function_exists('user_can') ? user_can($user, 'traffic.manage') : ($user['role'] ?? '') === 'admin';
}

function traffic_can_manage_destinations(array $user): bool
{
    return function_exists('user_can') ? user_can($user, 'traffic.destinations') : ($user['role'] ?? '') === 'admin';
}

function traffic_destinations(bool $activeOnly = true): array
{
    $sql = 'SELECT id, title FROM traffic_destinations' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY title';
    return db()->query($sql)->fetchAll();
}

function traffic_approvers(): array
{
    return db()->query("SELECT DISTINCT approved_by FROM traffic_visits WHERE approved_by IS NOT NULL AND approved_by <> '' ORDER BY approved_by")->fetchAll(PDO::FETCH_COLUMN);
}

// شماره برگ به‌صورت تاریخ‌شمسی+شماره‌ترتیبی روزانه، مثل ۱۴۰۴۰۷۱۵-۰۰۰۱
function traffic_next_serial(): string
{
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $prefix = sprintf('%04d%02d%02d', $jy, $jm, $jd);
    $query = db()->prepare('SELECT COUNT(*) FROM traffic_visits WHERE serial_no LIKE ?');
    $query->execute([$prefix . '-%']);
    $count = (int) $query->fetchColumn() + 1;
    return $prefix . '-' . sprintf('%04d', $count);
}

function traffic_find_last_visit(string $nationalCode, int $excludeId = 0): ?array
{
    $query = db()->prepare('SELECT * FROM traffic_visits WHERE national_code = ? AND id <> ? ORDER BY visit_date DESC, id DESC LIMIT 1');
    $query->execute([$nationalCode, $excludeId]);
    $row = $query->fetch();
    return $row ?: null;
}

function traffic_fetch_record(int $id): ?array
{
    $query = db()->prepare('SELECT * FROM traffic_visits WHERE id = ? LIMIT 1');
    $query->execute([$id]);
    $row = $query->fetch();
    return $row ?: null;
}

function traffic_query_records(array $filters, int $limit = 300): array
{
    $where = [];
    $params = [];
    if ($filters['search'] !== '') {
        $where[] = '(full_name LIKE ? OR national_code LIKE ? OR serial_no LIKE ? OR company LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($filters['range'] === 'today') {
        $where[] = 'visit_date = CURDATE()';
    } elseif ($filters['range'] === 'week') {
        $where[] = 'visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';
    }
    if ($filters['open_only']) {
        $where[] = 'exit_time IS NULL';
    }
    $sql = 'SELECT v.*, u.full_name AS recorder_name FROM traffic_visits v LEFT JOIN users u ON u.id = v.created_by';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY v.visit_date DESC, v.id DESC LIMIT ' . $limit;
    $query = db()->prepare($sql);
    $query->execute($params);
    return $query->fetchAll();
}

function traffic_stats(): array
{
    $stats = [];
    $stats['today'] = (int) db()->query("SELECT COUNT(*) FROM traffic_visits WHERE visit_date = CURDATE()")->fetchColumn();
    $stats['week'] = (int) db()->query("SELECT COUNT(*) FROM traffic_visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
    $stats['inside'] = (int) db()->query("SELECT COUNT(*) FROM traffic_visits WHERE visit_date = CURDATE() AND exit_time IS NULL")->fetchColumn();
    $stats['total'] = (int) db()->query('SELECT COUNT(*) FROM traffic_visits')->fetchColumn();

    $stats['by_destination'] = db()->query("SELECT COALESCE(NULLIF(meeting_with, ''), 'نامشخص') AS title, COUNT(*) AS cnt FROM traffic_visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY COALESCE(NULLIF(meeting_with, ''), 'نامشخص') ORDER BY cnt DESC LIMIT 6")->fetchAll();

    $daily = [];
    $rows = db()->query("SELECT visit_date, COUNT(*) AS cnt FROM traffic_visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY visit_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $daily[] = ['date' => $d, 'label' => jalali_date($d, false), 'count' => (int) ($rows[$d] ?? 0)];
    }
    $stats['daily'] = $daily;
    return $stats;
}

function traffic_empty_stats(): array
{
    $daily = [];
    for ($i = 13; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} day"));
        $daily[] = ['date' => $date, 'label' => jalali_date($date, false), 'count' => 0];
    }
    return ['today' => 0, 'week' => 0, 'inside' => 0, 'total' => 0, 'by_destination' => [], 'daily' => $daily];
}

function traffic_export_report(): never
{
    $fromInput = trim((string) ($_GET['from_date'] ?? ''));
    $toInput = trim((string) ($_GET['to_date'] ?? ''));
    $fromGregorian = $fromInput !== '' ? jalali_input_to_gregorian($fromInput) : null;
    $toGregorian = $toInput !== '' ? jalali_input_to_gregorian($toInput, true) : null;
    if ($fromGregorian === null || $toGregorian === null) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit('تاریخ شروع و پایان را با تقویم شمسی و به‌شکل معتبر انتخاب کنید.');
    }
    $fromDate = substr($fromGregorian, 0, 10);
    $toDate = substr($toGregorian, 0, 10);
    if ($fromDate > $toDate) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit('تاریخ «از» باید زودتر یا برابر با تاریخ «تا» باشد.');
    }

    $availableDestinations = array_values(array_unique(array_map(
        static fn (array $destination): string => (string) $destination['title'],
        traffic_destinations()
    )));
    $requestedDestinations = $_GET['destinations'] ?? [];
    if (!is_array($requestedDestinations)) {
        $requestedDestinations = [];
    }
    $requestedDestinations = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): string => is_string($value) ? trim($value) : '',
        $requestedDestinations
    ), static fn (string $value): bool => $value !== '')));
    $selectedDestinations = array_values(array_intersect($availableDestinations, $requestedDestinations));
    $allDestinations = !empty($_GET['all_destinations'])
        || ($availableDestinations !== [] && count($selectedDestinations) === count($availableDestinations));
    if (!$allDestinations && $selectedDestinations === []) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit('یک مقصد را انتخاب کنید یا گزینهٔ «همه» را علامت بزنید.');
    }

    $where = ['v.visit_date >= ?', 'v.visit_date <= ?'];
    $params = [$fromDate, $toDate];
    if (!$allDestinations) {
        $where[] = 'v.meeting_with IN (' . implode(',', array_fill(0, count($selectedDestinations), '?')) . ')';
        array_push($params, ...$selectedDestinations);
    }
    $query = db()->prepare(
        'SELECT v.*, u.full_name AS recorder_name FROM traffic_visits v LEFT JOIN users u ON u.id = v.created_by WHERE '
        . implode(' AND ', $where)
        . ' ORDER BY v.visit_date DESC, v.entry_time DESC, v.id DESC'
    );
    $query->execute($params);
    $rows = [];
    foreach ($query->fetchAll() as $record) {
        $rows[] = [
            (string) $record['serial_no'],
            jalali_date((string) $record['visit_date'], false),
            (string) $record['full_name'],
            (string) $record['national_code'],
            (string) ($record['phone'] ?? ''),
            (string) ($record['company'] ?? ''),
            (string) ($record['meeting_with'] ?? ''),
            (string) ($record['approved_by'] ?? ''),
            substr((string) $record['entry_time'], 0, 5),
            $record['exit_time'] ? substr((string) $record['exit_time'], 0, 5) : '',
            (int) $record['no_visit'] === 1 ? 'بله' : 'خیر',
            (int) $record['with_car'] === 1 ? 'بله' : 'خیر',
            (int) $record['with_mobile'] === 1 ? 'بله' : 'خیر',
            (string) ($record['description'] ?? ''),
            (string) ($record['recorder_name'] ?? ''),
        ];
    }
    $destinationLabel = $allDestinations ? 'همه مقصدها' : implode('، ', $selectedDestinations);
    $title = 'گزارش تردد مراجعین | ' . jalali_date($fromDate, false) . ' تا ' . jalali_date($toDate, false)
        . ' | ' . $destinationLabel . ' | تعداد: ' . count($rows);
    excel_download(
        'traffic-report-' . $fromDate . '-to-' . $toDate . '.xls',
        $title,
        ['شماره برگ', 'تاریخ', 'نام و نام خانوادگی', 'کد ملی', 'تلفن همراه', 'موسسه / شرکت', 'مقصد ملاقات', 'تأییدکننده', 'ساعت ورود', 'ساعت خروج', 'بدون بازدید', 'با خودرو', 'با تلفن همراه', 'هدف از تردد', 'ثبت‌کننده'],
        $rows,
        ['from' => $fromDate, 'to' => $toDate]
    );
}

/** خطای ورودی تردد همراه با نام فیلدی که باید فوکوس شود. */
class TrafficInputException extends RuntimeException
{
    public string $field;

    public function __construct(string $message, string $field = '')
    {
        parent::__construct($message);
        $this->field = $field;
    }
}

function traffic_validate_payload(array $data): array
{
    $name = trim((string) ($data['full_name'] ?? ''));
    $nationalCode = preg_replace('/\D/', '', strtr((string) ($data['national_code'] ?? ''), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    if ($name === '') {
        throw new TrafficInputException('نام و نام خانوادگی الزامی است.', 'full_name');
    }
    if (!valid_iranian_national_code((string) $nationalCode)) {
        throw new TrafficInputException('کد ملی وارد شده معتبر نیست.', 'national_code');
    }
    $phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
    return [
        'full_name' => $name,
        'national_code' => (string) $nationalCode,
        'phone' => $phone !== '' ? $phone : null,
        'company' => trim((string) ($data['company'] ?? '')) ?: null,
        'meeting_with' => trim((string) ($data['meeting_with'] ?? '')) ?: null,
        'approved_by' => trim((string) ($data['approved_by'] ?? '')) ?: null,
        'description' => trim((string) ($data['description'] ?? '')) ?: null,
        'no_visit' => !empty($data['no_visit']) ? 1 : 0,
        'with_car' => !empty($data['with_car']) ? 1 : 0,
        'with_mobile' => !empty($data['with_mobile']) ? 1 : 0,
    ];
}

function traffic_create_record(array $user): int
{
    $payload = traffic_validate_payload($_POST);
    $serial = traffic_next_serial();
    $stmt = db()->prepare('INSERT INTO traffic_visits (serial_no, visit_date, full_name, national_code, phone, company, entry_time, exit_time, no_visit, with_car, with_mobile, meeting_with, approved_by, description, created_by) VALUES (?, CURDATE(), ?, ?, ?, ?, CURTIME(), NULL, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$serial, $payload['full_name'], $payload['national_code'], $payload['phone'], $payload['company'], $payload['no_visit'], $payload['with_car'], $payload['with_mobile'], $payload['meeting_with'], $payload['approved_by'], $payload['description'], (int) $user['id']]);
    $id = (int) db()->lastInsertId();
    save_audit((int) $user['id'], 'traffic_visit_created', null, ['visit_id' => $id, 'serial' => $serial]);
    return $id;
}

function traffic_update_record(array $user): void
{
    $id = (int) ($_POST['record_id'] ?? 0);
    $record = traffic_fetch_record($id);
    if (!$record) {
        throw new RuntimeException('رکورد تردد پیدا نشد.');
    }
    $payload = traffic_validate_payload($_POST);
    $stmt = db()->prepare('UPDATE traffic_visits SET full_name = ?, national_code = ?, phone = ?, company = ?, no_visit = ?, with_car = ?, with_mobile = ?, meeting_with = ?, approved_by = ?, description = ? WHERE id = ?');
    $stmt->execute([$payload['full_name'], $payload['national_code'], $payload['phone'], $payload['company'], $payload['no_visit'], $payload['with_car'], $payload['with_mobile'], $payload['meeting_with'], $payload['approved_by'], $payload['description'], $id]);
    save_audit((int) $user['id'], 'traffic_visit_updated', null, ['visit_id' => $id]);
}

function traffic_delete_record(array $user): void
{
    if (!traffic_can_manage($user)) {
        throw new RuntimeException('حذف تردد فقط برای مدیر سامانه مجاز است.');
    }
    $id = (int) ($_POST['record_id'] ?? 0);
    db()->prepare('DELETE FROM traffic_visits WHERE id = ?')->execute([$id]);
    save_audit((int) $user['id'], 'traffic_visit_deleted', null, ['visit_id' => $id]);
}

function traffic_set_exit(array $user): void
{
    $id = (int) ($_POST['record_id'] ?? 0);
    $record = traffic_fetch_record($id);
    if (!$record) {
        throw new RuntimeException('رکورد تردد پیدا نشد.');
    }
    if (!empty($record['exit_time'])) {
        throw new TrafficInputException('ساعت خروج این تردد قبلاً ثبت شده است.');
    }
    $isToday = (string) $record['visit_date'] === (string) db()->query('SELECT CURDATE()')->fetchColumn();
    if ($isToday) {
        db()->prepare('UPDATE traffic_visits SET exit_time = CURTIME() WHERE id = ? AND exit_time IS NULL')->execute([$id]);
    } else {
        // تردد روز قبل: ساعت خروج واقعی باید دستی وارد شود، نه ساعت فعلی
        $raw = trim((string) ($_POST['exit_time'] ?? ''));
        if (!preg_match('/^\d{2}:\d{2}$/', $raw)) {
            throw new TrafficInputException('برای ترددهای روزهای قبل، ساعت خروج واقعی را وارد کنید.', 'exit_time');
        }
        $exitTime = $raw . ':00';
        if ($exitTime < (string) $record['entry_time']) {
            throw new TrafficInputException('ساعت خروج نمی‌تواند قبل از ساعت ورود باشد.', 'exit_time');
        }
        db()->prepare('UPDATE traffic_visits SET exit_time = ? WHERE id = ? AND exit_time IS NULL')->execute([$exitTime, $id]);
    }
    save_audit((int) $user['id'], 'traffic_visit_exit_set', null, ['visit_id' => $id]);
}

function traffic_save_destination(array $user): void
{
    if (!traffic_can_manage($user)) {
        throw new RuntimeException('مدیریت مقصدها فقط برای مدیر سامانه مجاز است.');
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('عنوان مقصد نمی‌تواند خالی باشد.');
    }
    db()->prepare('INSERT INTO traffic_destinations (title) VALUES (?)')->execute([$title]);
}

function traffic_delete_destination(array $user): void
{
    if (!traffic_can_manage($user)) {
        throw new RuntimeException('مدیریت مقصدها فقط برای مدیر سامانه مجاز است.');
    }
    $id = (int) ($_POST['destination_id'] ?? 0);
    db()->prepare('UPDATE traffic_destinations SET is_active = 0 WHERE id = ?')->execute([$id]);
}

/** مقدارهای فرم ثبت تردد برای پر کردن دوباره پس از خطا. */
function traffic_form_old_values(): array
{
    $keys = ['national_code', 'full_name', 'phone', 'company', 'approved_by', 'description'];
    $old = [];
    foreach ($keys as $key) {
        $old[$key] = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, 500);
    }
    return $old;
}

/** خطا را برای نمایش بالای فرم ذخیره می‌کند و کاربر را به همان زبانه برمی‌گرداند. */
function traffic_remember_form_error(RuntimeException $exception, string $tab): never
{
    $field = $exception instanceof TrafficInputException ? $exception->field : '';
    $_SESSION['traffic_form_error'] = [
        'message' => $exception->getMessage(),
        'field' => $field,
        'tab' => $tab,
        'old' => traffic_form_old_values(),
    ];
    redirect('index.php?page=traffic-control&tab=' . $tab);
}

/** همهٔ ترددهای بدون خروج (امروز و روزهای قبل). */
function traffic_open_visits(): array
{
    return db()->query('SELECT id, serial_no, visit_date, full_name, national_code, entry_time, meeting_with FROM traffic_visits WHERE exit_time IS NULL ORDER BY visit_date ASC, entry_time ASC, id ASC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
}

function traffic_handle_post(string $action, array $user): void
{
    if (!function_exists('user_can') || !user_can($user, 'traffic.manage')) {
        return;
    }
    if ($action === 'traffic_create') {
        try {
            $id = traffic_create_record($user);
        } catch (RuntimeException $exception) {
            traffic_remember_form_error($exception, 'new');
        }
        flash('success', 'تردد با شماره برگ ثبت شد.');
        redirect('index.php?page=traffic-control&tab=new&created=' . $id);
    }
    if ($action === 'traffic_update') {
        try {
            traffic_update_record($user);
        } catch (RuntimeException $exception) {
            traffic_remember_form_error($exception, 'history');
        }
        flash('success', 'اطلاعات تردد به‌روزرسانی شد.');
        redirect('index.php?page=traffic-control&tab=history');
    }
    if ($action === 'traffic_delete') {
        traffic_delete_record($user);
        flash('success', 'رکورد تردد حذف شد.');
        redirect('index.php?page=traffic-control&tab=history');
    }
    if ($action === 'traffic_set_exit') {
        $returnTab = valid_choice((string) ($_POST['return_tab'] ?? 'history'), ['dashboard', 'history'], 'history');
        try {
            traffic_set_exit($user);
        } catch (RuntimeException $exception) {
            traffic_remember_form_error($exception, $returnTab);
        }
        flash('success', 'ساعت خروج ثبت شد.');
        redirect('index.php?page=traffic-control&tab=' . $returnTab);
    }
    if ($action === 'traffic_add_destination') {
        if (!traffic_can_manage_destinations($user)) {
            return;
        }
        traffic_save_destination($user);
        flash('success', 'مقصد جدید اضافه شد.');
        redirect('index.php?page=traffic-control&tab=destinations');
    }
    if ($action === 'traffic_delete_destination') {
        if (!traffic_can_manage_destinations($user)) {
            return;
        }
        traffic_delete_destination($user);
        flash('success', 'مقصد غیرفعال شد.');
        redirect('index.php?page=traffic-control&tab=destinations');
    }
}

// ------------------------------------------------------------
// جست‌وجوی زنده با کد ملی (AJAX) — معادل txtNationalCode_BeforeUpdate در اکسل
// ------------------------------------------------------------
function traffic_api_handle(string $mode, array $user): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'نیاز به ورود'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('user_can') && !user_can($user, 'traffic.view')) {
        http_response_code(403);
        echo json_encode(['error' => 'دسترسی مجاز نیست'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($mode === 'lookup') {
        $code = preg_replace('/\D/', '', (string) ($_GET['code'] ?? ''));
        if (!valid_iranian_national_code((string) $code)) {
            echo json_encode(['valid' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $last = traffic_find_last_visit((string) $code);
        if (!$last) {
            echo json_encode(['valid' => true, 'found' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $isToday = $last['visit_date'] === date('Y-m-d');
        $needsExit = $isToday && $last['exit_time'] === null;
        echo json_encode([
            'valid' => true,
            'found' => true,
            'needs_exit_only' => $needsExit,
            'record_id' => (int) $last['id'],
            'full_name' => $last['full_name'],
            'phone' => $last['phone'],
            'company' => $last['company'],
            'meeting_with' => $last['meeting_with'],
            'approved_by' => $last['approved_by'],
            'last_visit_label' => jalali_date($last['visit_date'], false) . ' ساعت ' . substr((string) $last['entry_time'], 0, 5),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['error' => 'نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// صفحه‌ی اصلی: زیرفرم‌های داشبورد / ثبت تردد / سوابق / مقصدها
// ------------------------------------------------------------
function traffic_render_page(array $user): never
{
    if (function_exists('require_permission')) {
        $user = require_permission('traffic.view');
    }
    $tab = valid_choice((string) ($_GET['tab'] ?? 'dashboard'), ['dashboard', 'new', 'history', 'destinations'], 'dashboard');
    $filters = [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'range' => valid_choice((string) ($_GET['range'] ?? 'week'), ['today', 'week', 'all'], 'week'),
        'open_only' => !empty($_GET['open_only']),
    ];
    $formError = null;
    $formOld = [];
    if (isset($_SESSION['traffic_form_error']) && is_array($_SESSION['traffic_form_error']) && ($_SESSION['traffic_form_error']['tab'] ?? '') === $tab) {
        $formError = $_SESSION['traffic_form_error'];
        $formOld = is_array($formError['old'] ?? null) ? $formError['old'] : [];
        unset($_SESSION['traffic_form_error']);
    }
    $openVisits = [];
    $openError = '';
    $stats = traffic_empty_stats();
    $statsError = '';
    $dailyMax = 1;
    $destinationMax = 1;
    if ($tab === 'dashboard') {
        try {
            $openVisits = traffic_open_visits();
        } catch (Throwable $exception) {
            $openError = 'فهرست ترددهای بدون خروج دریافت نشد.';
        }
        try {
            $stats = array_replace($stats, traffic_stats());
            $dailyRows = [];
            foreach ((array) $stats['daily'] as $day) {
                if (!is_array($day)) {
                    continue;
                }
                $count = max(0, (int) ($day['count'] ?? 0));
                $dailyRows[] = [
                    'date' => (string) ($day['date'] ?? ''),
                    'label' => (string) ($day['label'] ?? ''),
                    'count' => $count,
                ];
                $dailyMax = max($dailyMax, $count);
            }
            $stats['daily'] = $dailyRows !== [] ? $dailyRows : traffic_empty_stats()['daily'];

            $destinationRows = [];
            foreach ((array) $stats['by_destination'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $count = max(0, (int) ($row['cnt'] ?? 0));
                $destinationRows[] = [
                    'title' => trim((string) ($row['title'] ?? '')) ?: 'نامشخص',
                    'cnt' => $count,
                ];
                $destinationMax = max($destinationMax, $count);
            }
            $stats['by_destination'] = $destinationRows;
            foreach (['today', 'week', 'inside', 'total'] as $key) {
                $stats[$key] = max(0, (int) ($stats[$key] ?? 0));
            }
        } catch (Throwable $exception) {
            $dashboardError = 'Traffic dashboard stats failed: ' . $exception->getMessage();
            if (function_exists('system_log')) {
                system_log('error', 'traffic-dashboard', $dashboardError, ['file' => basename($exception->getFile()), 'line' => $exception->getLine()]);
            } else {
                error_log($dashboardError);
            }
            $stats = traffic_empty_stats();
            $statsError = 'آمار داشبورد از پایگاه داده دریافت نشد؛ پیام خطا در گزارش خطای سرور ثبت شده است.';
        }
    }
    $destinations = in_array($tab, ['new', 'history'], true) ? traffic_destinations() : [];
    $approvers = $tab === 'new' ? traffic_approvers() : [];
    $createdId = (int) ($_GET['created'] ?? 0);
    $lastCreated = $createdId ? traffic_fetch_record($createdId) : null;

    render_header('کنترل تردد', $user);
    ?>
    <section class="traffic-page">
        <header class="cd-dvd-banner">
            <div><span class="eyebrow">ثبت و پیگیری مراجعین و میهمانان</span><h1>کنترل تردد</h1><p>ثبت ورود/خروج، جست‌وجوی سریع با کد ملی و چاپ برگ ملاقات</p></div>
            <a class="button" href="index.php">بازگشت به داشبورد</a>
        </header>
        <nav class="cd-dvd-tabs" aria-label="زیرفرم‌های کنترل تردد">
            <a class="<?= $tab === 'dashboard' ? 'active' : '' ?>" href="index.php?page=traffic-control&amp;tab=dashboard">📊 داشبورد</a>
            <a class="<?= $tab === 'new' ? 'active' : '' ?>" href="index.php?page=traffic-control&amp;tab=new">➕ ثبت تردد</a>
            <a class="<?= $tab === 'history' ? 'active' : '' ?>" href="index.php?page=traffic-control&amp;tab=history">📋 سوابق</a>
            <?php if (traffic_can_manage_destinations($user)): ?><a class="<?= $tab === 'destinations' ? 'active' : '' ?>" href="index.php?page=traffic-control&amp;tab=destinations">🏷️ مقصدهای ملاقات</a><?php endif; ?>
        </nav>
        <?php if ($formError): ?>
        <div class="alert danger" role="alert" tabindex="-1" id="traffic-form-error" data-focus-form="<?= $tab === 'new' ? 'traffic-new-form' : '' ?>" data-focus-field="<?= e((string) $formError['field']) ?>"><?= e((string) $formError['message']) ?></div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
        <section class="traffic-dashboard">
            <div class="traffic-dashboard-head">
                <div><span class="eyebrow">نمای کلی مراجعات</span><h2>امروز در ورودی سازمان چه می‌گذرد؟</h2><p>خلاصهٔ ترددها، وضعیت افراد داخل ساختمان و مقصدهای پرتردد</p></div>
                <a class="button traffic-dashboard-action" href="index.php?page=traffic-control&amp;tab=new">＋ ثبت تردد جدید</a>
            </div>
            <?php if ($statsError !== ''): ?><div class="alert danger traffic-dashboard-status" role="alert"><?= e($statsError) ?></div><?php elseif ($stats['total'] === 0): ?><div class="traffic-dashboard-empty"><span class="traffic-dashboard-empty-icon" aria-hidden="true">◌</span><div><strong>هنوز ترددی ثبت نشده است</strong><p>پس از ثبت اولین مراجعه، آمار روزانه و مقصدهای پرتردد در این صفحه نمایش داده می‌شوند.</p></div><a class="button traffic-dashboard-empty-action" href="index.php?page=traffic-control&amp;tab=new">ثبت اولین تردد</a></div><?php endif; ?>
            <div class="traffic-stat-grid">
                <article class="traffic-stat-card is-today"><div class="traffic-stat-top"><span class="traffic-stat-icon">◷</span><span class="traffic-stat-period">امروز</span></div><strong><?= (int) $stats['today'] ?></strong><small>تردد ثبت‌شده</small></article>
                <article class="traffic-stat-card is-week"><div class="traffic-stat-top"><span class="traffic-stat-icon">↗</span><span class="traffic-stat-period">۷ روز</span></div><strong><?= (int) $stats['week'] ?></strong><small>تردد در هفتهٔ اخیر</small></article>
                <article class="traffic-stat-card is-inside"><div class="traffic-stat-top"><span class="traffic-stat-icon">●</span><span class="traffic-stat-period">اکنون</span></div><strong><?= (int) $stats['inside'] ?></strong><small>افرادِ بدون ثبت خروج</small></article>
                <article class="traffic-stat-card is-total"><div class="traffic-stat-top"><span class="traffic-stat-icon">▤</span><span class="traffic-stat-period">همهٔ سوابق</span></div><strong><?= (int) $stats['total'] ?></strong><small>مجموع ترددهای ثبت‌شده</small></article>
            </div>
            <div class="traffic-dashboard-grid">
                <section class="card traffic-dashboard-panel traffic-trend-panel">
                    <div class="traffic-panel-heading"><div><span class="traffic-panel-kicker">روند مراجعه</span><h3>ترددهای ۱۴ روز اخیر</h3></div><span class="traffic-period-pill">دو هفته</span></div>
                    <div class="traffic-bars" role="img" aria-label="نمودار تعداد ترددهای روزانه در ۱۴ روز اخیر">
                        <?php foreach ($stats['daily'] as $day): ?>
                            <div class="traffic-bar" title="<?= e($day['label'] . ': ' . $day['count']) ?>">
                                <b><?= (int) $day['count'] ?></b>
                                <span style="height: <?= max(4, (int) round((int) $day['count'] / $dailyMax * 100)) ?>%"></span>
                                <small><?= e($day['label']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="card traffic-dashboard-panel traffic-destination-panel">
                    <div class="traffic-panel-heading"><div><span class="traffic-panel-kicker">۳۰ روز گذشته</span><h3>مقصدهای پرتردد</h3></div><span class="traffic-period-pill">پُرمراجعه‌ها</span></div>
                    <div class="traffic-destination-list">
                        <?php foreach ($stats['by_destination'] as $index => $row): ?>
                            <div class="traffic-destination-row">
                                <div class="traffic-destination-info"><span class="traffic-destination-rank"><?= sprintf('%02d', $index + 1) ?></span><span class="traffic-destination-name"><b><?= e($row['title']) ?></b><small><?= (int) $row['cnt'] ?> مراجعه</small></span><strong><?= (int) $row['cnt'] ?></strong></div>
                                <div class="traffic-destination-track"><span style="width: <?= max(4, (int) round((int) $row['cnt'] / $destinationMax * 100)) ?>%"></span></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$stats['by_destination']): ?><p class="muted traffic-destination-empty">هنوز داده‌ای ثبت نشده است.</p><?php endif; ?>
                    </div>
                </section>
            </div>
            <section class="card traffic-dashboard-panel" id="traffic-open-panel" data-focus-panel="1">
                <div class="traffic-panel-heading"><div><span class="traffic-panel-kicker">بدون خروج (همهٔ روزها)</span><h3>ترددهای بدون خروج (<?= (int) count($openVisits) ?>)</h3></div><span class="traffic-period-pill">ثبت سریع خروج</span></div>
                <?php if ($openError !== ''): ?><div class="alert danger"><?= e($openError) ?></div>
                <?php elseif (!$openVisits): ?><p class="muted">در حال حاضر فردی بدون خروج ثبت نشده است.</p>
                <?php else: ?>
                <div class="traffic-open-list">
                    <?php foreach ($openVisits as $visit): ?>
                    <div class="traffic-open-row" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #e5e7eb">
                        <span><strong><?= e($visit['full_name']) ?></strong> <small>(<?= e($visit['national_code']) ?>)</small><br><small>تاریخ <?= e(jalali_date($visit['visit_date'], false)) ?> · شماره برگ <?= e($visit['serial_no']) ?> · ورود <?= e(substr((string) $visit['entry_time'], 0, 5)) ?> · ملاقات با <?= e($visit['meeting_with'] ?? '-') ?></small></span>
                        <?php if (user_can($user, 'traffic.manage')): ?>
                        <form method="post" class="traffic-open-exit-form"><?= csrf_field() ?><input type="hidden" name="action" value="traffic_set_exit"><input type="hidden" name="record_id" value="<?= (int) $visit['id'] ?>"><input type="hidden" name="return_tab" value="dashboard"><?php if ((string) $visit['visit_date'] !== (string) date('Y-m-d')): ?><label style="font-size:12px">ساعت خروج واقعی <input type="time" name="exit_time" required style="margin-inline-start:6px"></label><?php endif; ?><button class="mini-button" type="submit">ثبت خروج</button></form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'new'): ?>
        <section class="card form-card traffic-form-card">
            <div class="section-title"><div><span class="eyebrow">فرم ثبت</span><h2>ثبت تردد جدید</h2><p>ابتدا کد ملی را وارد کنید؛ در صورت وجود سابقه، اطلاعات به‌صورت خودکار پیشنهاد می‌شود.</p></div></div>
            <?php if ($lastCreated): ?><div class="alert success">ثبت با موفقیت انجام شد. شماره برگ: <b><?= e($lastCreated['serial_no']) ?></b> — برای چاپ از دکمهٔ فعال در پایین فرم استفاده کنید.</div><?php endif; ?>
            <div id="traffic-lookup-hint" class="alert info" hidden></div>
            <form method="post" id="traffic-quick-exit-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="traffic_set_exit"><input type="hidden" name="record_id" id="traffic-quick-exit-id"></form>
            <form method="post" class="traffic-form" id="traffic-new-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="traffic_create">
                <div class="form-grid">
                    <label>کد ملی<input name="national_code" id="traffic-national-code" required maxlength="10" inputmode="numeric" pattern="[0-9۰-۹]*" data-digits-only autocomplete="off" value="<?= e((string) ($formOld['national_code'] ?? '')) ?>"></label>
                    <label>نام و نام خانوادگی<input name="full_name" id="traffic-full-name" required value="<?= e((string) ($formOld['full_name'] ?? '')) ?>"></label>
                    <label>تلفن همراه<input name="phone" id="traffic-phone" inputmode="numeric" pattern="[0-9۰-۹]*" data-digits-only autocomplete="off" value="<?= e((string) ($formOld['phone'] ?? '')) ?>"></label>
                    <label>موسسه / شرکت<input name="company" id="traffic-company" value="<?= e((string) ($formOld['company'] ?? '')) ?>"></label>
                    <label>ملاقات با<select name="meeting_with" id="traffic-meeting-with"><option value="">— انتخاب کنید —</option><?php foreach ($destinations as $d): ?><option value="<?= e($d['title']) ?>"><?= e($d['title']) ?></option><?php endforeach; ?></select></label>
                    <label>تایید کننده<input name="approved_by" id="traffic-approved-by" list="traffic-approvers-list"><datalist id="traffic-approvers-list"><?php foreach ($approvers as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist></label>
                    <label class="full">توضیحات / هدف از تردد<textarea name="description" rows="2"><?= e((string) ($formOld['description'] ?? '')) ?></textarea></label>
                    <div class="traffic-checks">
                        <label><input type="checkbox" name="no_visit" value="1"> بدون بازدید</label>
                        <label><input type="checkbox" name="with_car" value="1"> با خودرو</label>
                        <label><input type="checkbox" name="with_mobile" value="1"> با تلفن همراه</label>
                    </div>
                </div>
                <div class="actions"><button class="button" type="submit">ثبت تردد</button><button class="button secondary" type="reset">پاک‌کردن فرم</button><?php if ($lastCreated): ?><a class="button secondary" data-traffic-print-saved href="index.php?page=traffic-control-print&amp;id=<?= (int) $lastCreated['id'] ?>" target="_blank" rel="noopener">چاپ برگه ملاقات</a><?php else: ?><button class="button secondary" type="button" disabled aria-disabled="true" title="پس از ثبت تردد فعال می‌شود">چاپ برگه ملاقات</button><?php endif; ?></div>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'history'): ?>
        <section class="card cd-dvd-history-panel">
            <div class="section-title"><div><span class="eyebrow">ردگیری</span><h2>سوابق تردد</h2></div><a class="button traffic-history-new-button" href="index.php?page=traffic-control&amp;tab=new">ثبت جدید</a></div>
            <section class="traffic-report-card" aria-labelledby="traffic-report-title">
                <div class="traffic-report-heading"><span class="traffic-report-mark" aria-hidden="true">▤</span><div><span class="traffic-panel-kicker">خروجی مدیریتی</span><h3 id="traffic-report-title">استخراج گزارش تردد</h3><p>بازهٔ زمانی و یک یا چند مقصد ملاقات را انتخاب کنید.</p></div></div>
                <form method="get" action="index.php" class="traffic-report-form" id="traffic-report-form">
                    <input type="hidden" name="action" value="export_traffic_report">
                    <div class="traffic-report-date-grid">
                        <label>از تاریخ<input type="date" name="from_date" required></label>
                        <label>تا تاریخ<input type="date" name="to_date" required></label>
                    </div>
                    <fieldset class="traffic-report-destination-fieldset">
                        <legend>مقصد ملاقات</legend>
                        <div class="traffic-report-destinations">
                            <label class="traffic-report-destination traffic-report-all"><input type="checkbox" id="traffic-report-all" name="all_destinations" value="1" checked><span>همه</span></label>
                            <?php foreach ($destinations as $destination): ?><label class="traffic-report-destination"><input type="checkbox" name="destinations[]" value="<?= e($destination['title']) ?>" checked><span><?= e($destination['title']) ?></span></label><?php endforeach; ?>
                        </div>
                        <?php if (!$destinations): ?><p class="traffic-report-empty">مقصد فعالی تعریف نشده؛ گزارش با انتخاب «همه» شامل همهٔ رکوردها خواهد بود.</p><?php endif; ?>
                    </fieldset>
                    <div class="traffic-report-actions"><button class="button traffic-export-button" type="submit"><span aria-hidden="true">⇩</span>استخراج Excel</button><small>فایل اکسل شامل جزئیات مراجعه، ساعت‌ها و مشخصات مقصد خواهد بود.</small></div>
                </form>
            </section>
            <form method="get" class="cd-dvd-history-filters"><input type="hidden" name="page" value="traffic-control"><input type="hidden" name="tab" value="history">
                <label>جست‌وجو<input name="search" id="traffic-history-search" autocomplete="off" value="<?= e($filters['search']) ?>" placeholder="نام، کد ملی، شماره برگ، شرکت"></label>
                <label>بازه<select name="range" onchange="this.form.submit()"><option value="today" <?= $filters['range'] === 'today' ? 'selected' : '' ?>>امروز</option><option value="week" <?= $filters['range'] === 'week' ? 'selected' : '' ?>>۷ روز اخیر</option><option value="all" <?= $filters['range'] === 'all' ? 'selected' : '' ?>>همه</option></select></label>
                <label><input type="checkbox" name="open_only" value="1" onchange="this.form.submit()" <?= $filters['open_only'] ? 'checked' : '' ?>> فقط ترددهای بدون خروج</label>
            </form>
            <div class="cd-dvd-record-table traffic-record-table" id="traffic-history-table">
                <div class="cd-dvd-record-head"><span>شماره برگ</span><span>تاریخ</span><span>نام</span><span>کد ملی</span><span>ورود</span><span>خروج</span><span>ملاقات با</span><span>ثبت‌کننده</span><span>عملیات</span></div>
                <?php foreach (traffic_query_records($filters) as $r): ?>
                <div class="cd-dvd-record-row" data-traffic-row data-search="<?= e(implode(' ', [(string) $r['full_name'], (string) $r['national_code'], (string) $r['serial_no'], (string) ($r['company'] ?? '')])) ?>">
                    <span><strong><?= e($r['serial_no']) ?></strong></span>
                    <span><?= e(jalali_date($r['visit_date'], false)) ?></span>
                    <span><?= e($r['full_name']) ?></span>
                    <span><?= e($r['national_code']) ?></span>
                    <span><?= e(substr((string) $r['entry_time'], 0, 5)) ?></span>
                    <span><?= $r['exit_time'] ? e(substr((string) $r['exit_time'], 0, 5)) : '<em class="media-status inside">داخل</em>' ?></span>
                    <span><?= e($r['meeting_with'] ?? '-') ?></span>
                    <span><?= e($r['recorder_name'] ?? '-') ?></span>
                    <span class="actions">
                        <a class="mini-button" href="index.php?page=traffic-control-print&amp;id=<?= (int) $r['id'] ?>" target="_blank">چاپ</a>
                        <?php if (!$r['exit_time']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="traffic_set_exit"><input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>"><button class="mini-button" type="submit">ثبت خروج</button></form><?php endif; ?>
                        <?php if (traffic_can_manage($user)): ?><form method="post" data-confirm="این رکورد حذف شود؟"><?= csrf_field() ?><input type="hidden" name="action" value="traffic_delete"><input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>"><button class="mini-button danger" type="submit">حذف</button></form><?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <p class="traffic-history-empty" id="traffic-history-empty" hidden>موردی با این جست‌وجو یافت نشد.</p>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($tab === 'destinations' && traffic_can_manage_destinations($user)): ?>
        <section class="card traffic-destinations-card">
            <div class="section-title"><div><span class="eyebrow">تنظیمات</span><h2>مقصدهای ملاقات</h2><p>همان لیست شیت Staff در فایل اکسل — گزینه‌های کمبوباکس «ملاقات با».</p></div></div>
            <form method="post" class="inline-form traffic-destination-add-form"><?= csrf_field() ?><input type="hidden" name="action" value="traffic_add_destination"><input name="title" placeholder="عنوان مقصد جدید" required><button class="button" type="submit">افزودن</button></form>
            <div class="category-list traffic-destinations-list">
                <?php foreach (traffic_destinations(false) as $d): ?>
                <div><span><?= e($d['title']) ?></span><form method="post" data-confirm="این مقصد حذف شود؟"><?= csrf_field() ?><input type="hidden" name="action" value="traffic_delete_destination"><input type="hidden" name="destination_id" value="<?= (int) $d['id'] ?>"><button class="mini-button danger" type="submit">حذف</button></form></div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </section>
    <script nonce="<?= e(csp_nonce()) ?>" src="assets/traffic-control.js?v=5"></script>
    <?php
    render_footer();
    exit;
}

// ------------------------------------------------------------
// چاپ برگ ملاقات — معادل PrintTemplate + cmdPrintPass_Click
// ------------------------------------------------------------
function traffic_render_print(array $user, int $id): never
{
    if (function_exists('require_permission')) {
        $user = require_permission('traffic.view');
    }
    $record = traffic_fetch_record($id);
    if (!$record) {
        http_response_code(404);
        exit('برگ ملاقات پیدا نشد.');
    }
    ?><!doctype html>
    <html lang="fa" dir="rtl">
    <head>
    <meta charset="utf-8">
    <title>برگ ملاقات — <?= e($record['serial_no']) ?></title>
    <style>
        @font-face { font-family: 'ShabnamPrint'; src: url('assets/fonts/Shabnam-Regular.woff2') format('woff2'); font-weight: 400; font-style: normal; }
        @font-face { font-family: 'ShabnamPrint'; src: url('assets/fonts/Shabnam-Bold.woff2') format('woff2'); font-weight: 700; font-style: normal; }
        body { font-family: 'ShabnamPrint', 'VazirmatnLocal', Tahoma, sans-serif; padding: 30px; color: #263d38; background: #eef3ef; }
        .pass { max-width: 680px; margin: 0 auto; border: 1px solid #a7c7b4; border-top: 8px solid #19745c; border-radius: 14px; background: #fff; padding: 26px 30px; box-shadow: 0 12px 32px rgba(29, 74, 54, .12); }
        .pass h1 { text-align: center; color: #174e42; font-size: 23px; margin: 4px 0 12px; }
        .pass .bismillah { text-align: center; color: #258065; font-size: 13px; margin-bottom: 12px; }
        .pass table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .pass td { padding: 9px 11px; border: 1px solid #d3e0d8; font-size: 14px; line-height: 1.8; }
        .pass td.label { background: #f0f6f2; width: 35%; color: #35594b; font-weight: bold; }
        .pass .meta { display: flex; justify-content: space-between; margin-top: 8px; font-size: 13px; }
        .pass .meta span { padding: 5px 10px; border-radius: 7px; background: #f2f7f3; }
        .pass-signature { display: flex; align-items: flex-end; gap: 10px; width: min(310px, 72%); margin: 38px 0 0 auto; }
        .pass-signature span { white-space: nowrap; font-weight: bold; }
        .pass-signature i { display: block; flex: 1; min-width: 90px; height: 34px; border-bottom: 1px dotted #444; }
        @media print { .no-print { display: none; } body { padding: 0; background: #fff; } .pass { box-shadow: none; break-inside: avoid; } }
    </style>
    </head>
    <body>
    <div class="pass">
        <div class="bismillah">بسمه‌تعالی</div>
        <h1>برگ ملاقات مراجعین</h1>
        <div class="meta"><span>شماره: <?= e($record['serial_no']) ?></span><span>تاریخ: <?= e(jalali_date($record['visit_date'], false)) ?></span></div>
        <table>
            <tr><td class="label">نام و نام خانوادگی</td><td><?= e($record['full_name']) ?></td></tr>
            <tr><td class="label">کد ملی</td><td><?= e($record['national_code']) ?></td></tr>
            <tr><td class="label">موسسه / شرکت</td><td><?= e($record['company'] ?? '') ?></td></tr>
            <tr><td class="label">ملاقات با</td><td><?= e($record['meeting_with'] ?? '') ?></td></tr>
            <tr><td class="label">تایید کننده</td><td><?= e($record['approved_by'] ?? '') ?></td></tr>
            <tr><td class="label">ساعت ورود</td><td><?= e(substr((string) $record['entry_time'], 0, 5)) ?></td></tr>
            <tr><td class="label">هدف از تردد</td><td><?= e($record['description'] ?? '') ?></td></tr>
        </table>
        <div class="pass-signature"><span>امضای ملاقات‌شونده</span><i></i></div>
    </div>
    <p class="no-print" style="text-align:center"><button id="traffic-print-again">چاپ مجدد</button></p>
    <script nonce="<?= e(csp_nonce()) ?>">
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { window.print(); });
        } else {
            window.print();
        }
        document.getElementById('traffic-print-again').addEventListener('click', function () { window.print(); });
    </script>
    </body>
    </html>
    <?php
    exit;
}
