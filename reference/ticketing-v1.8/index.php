<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inventory.php';
require __DIR__ . '/asset-profile.php';
require __DIR__ . '/domain-scan.php';
require __DIR__ . '/asset-names.php';
require __DIR__ . '/asset-inventory-page.php';
require __DIR__ . '/organization.php';
require __DIR__ . '/global-search.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/cd-dvd.php';
require __DIR__ . '/food-ticket.php';
require __DIR__ . '/traffic-control.php';

ticket_number_ensure_schema(); // پیش از هر تراکنش: DDL اینجا بی‌خطر است و داخل تراکنش دیگر اجرا نمی‌شود
ticket_number_autofill();
role_permissions_seed();

if (isset($_GET['installed'])) {
    flash('success', 'سامانه با موفقیت نصب شد.');
}

function post_value(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function csv_cell(mixed $value): string
{
    $value = (string) $value;
    return preg_match('/^[=+\-@]/', ltrim($value)) ? "'" . $value : $value;
}

function excel_cell(mixed $value): string
{
    return htmlspecialchars(csv_cell($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function excel_download(string $filename, string $title, array $headers, array $rows, array $dateRange = []): never
{
    $from = trim((string) ($dateRange['from'] ?? $dateRange['start'] ?? ''));
    $to = trim((string) ($dateRange['to'] ?? $dateRange['end'] ?? ''));
    $singleDate = trim((string) ($dateRange['date'] ?? ''));
    if ($singleDate !== '') {
        $from = $singleDate;
        $to = $singleDate;
    }
    if ($from === '' && $to === '') {
        $from = $to = 'بدون فیلتر تاریخی';
    } elseif ($from === '') {
        $from = 'بدون محدودیت شروع';
    } elseif ($to === '') {
        $to = 'بدون محدودیت پایان';
    }
    $jalaliLabel = static function (string $value): string {
        $value = trim($value);
        $digits = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $digits, $parts) === 1) {
            $year = (int) $parts[1];
            $month = (int) $parts[2];
            $day = (int) $parts[3];
            if ($year >= 1300 && $year <= 1600) {
                return sprintf('%04d/%02d/%02d', $year, $month, $day);
            }
            if ($year >= 1900 && $year <= 2200 && function_exists('gregorian_to_jalali')) {
                try {
                    [$jy, $jm, $jd] = gregorian_to_jalali($year, $month, $day);
                    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
                } catch (Throwable) {
                    // Preserve the original label if the supplied date is invalid.
                }
            }
        }
        return $value;
    };
    $from = $jalaliLabel($from);
    $to = $jalaliLabel($to);
    $mergeAcross = max(0, count($headers) - 1);
    $columnCount = max(2, count($headers));
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<?mso-application progid="Excel.Sheet"?>' .
        '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
        '<Styles><Style ss:ID="Title"><Font ss:FontName="Tahoma" ss:Bold="1" ss:Size="15"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style><Style ss:ID="Header"><Font ss:FontName="Tahoma" ss:Bold="1"/><Interior ss:Color="#EAF2FF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style><Style ss:ID="Body"><Font ss:FontName="Tahoma"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style><Style ss:ID="PeriodLabel"><Font ss:FontName="Tahoma" ss:Bold="1"/><Interior ss:Color="#F3F6FA" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style><Style ss:ID="PeriodValue"><Font ss:FontName="Tahoma"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style></Styles>' .
        '<Worksheet ss:Name="Report"><Table ss:ExpandedColumnCount="' . $columnCount . '"><Row ss:StyleID="Title"><Cell ss:MergeAcross="' . $mergeAcross . '"><Data ss:Type="String">' . excel_cell($title) . '</Data></Cell></Row>' .
        '<Row><Cell ss:StyleID="PeriodLabel"><Data ss:Type="String">تاریخ شروع</Data></Cell><Cell ss:StyleID="PeriodValue"><Data ss:Type="String">' . excel_cell($from) . '</Data></Cell></Row>' .
        '<Row><Cell ss:StyleID="PeriodLabel"><Data ss:Type="String">تاریخ پایان</Data></Cell><Cell ss:StyleID="PeriodValue"><Data ss:Type="String">' . excel_cell($to) . '</Data></Cell></Row><Row>';
    foreach ($headers as $header) {
        $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . excel_cell($header) . '</Data></Cell>';
    }
    $xml .= '</Row>';
    foreach ($rows as $row) {
        $xml .= '<Row ss:StyleID="Body">';
        foreach ($row as $value) {
            $xml .= '<Cell><Data ss:Type="String">' . excel_cell($value) . '</Data></Cell>';
        }
        $xml .= '</Row>';
    }
    $xml .= '</Table></Worksheet></Workbook>';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    echo "\xEF\xBB\xBF" . $xml;
    exit;
}

function valid_choice(string $value, array $allowed, string $fallback): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function activity_log_filters(): array
{
    $conditions = [];
    $params = [];
    $userId = (int) ($_GET['user_id'] ?? 0);
    if ($userId > 0) {
        $conditions[] = 'l.user_id = ?';
        $params[] = $userId;
    }
    $actionCode = trim((string) ($_GET['action_code'] ?? ''));
    if ($actionCode !== '') {
        $conditions[] = 'l.action_code = ?';
        $params[] = $actionCode;
    }
    $module = trim((string) ($_GET['module'] ?? ''));
    if ($module !== '') {
        $conditions[] = 'l.module = ?';
        $params[] = $module;
    }
    $ip = trim((string) ($_GET['ip'] ?? ''));
    if ($ip !== '') {
        $conditions[] = 'l.ip_address LIKE ?';
        $params[] = '%' . $ip . '%';
    }
    $from = trim((string) ($_GET['from'] ?? ''));
    if ($from !== '') {
        $date = jalali_input_to_gregorian($from);
        if ($date) {
            $conditions[] = 'l.created_at >= ?';
            $params[] = $date;
        }
    }
    $to = trim((string) ($_GET['to'] ?? ''));
    if ($to !== '') {
        $date = jalali_input_to_gregorian($to, true);
        if ($date) {
            $conditions[] = 'l.created_at <= ?';
            $params[] = $date;
        }
    }
    $search = trim((string) ($_GET['q'] ?? ''));
    if ($search !== '') {
        $conditions[] = '(l.full_name LIKE ? OR l.username LIKE ? OR l.action_label LIKE ? OR l.action_code LIKE ? OR l.computer_name LIKE ? OR l.ip_address LIKE ? OR l.meta_json LIKE ?)';
        $params = array_merge($params, array_fill(0, 7, '%' . $search . '%'));
    }
    $category = valid_choice((string) ($_GET['category'] ?? 'all'), ['all', 'auth', 'admin', 'errors'], 'all');
    if ($category === 'auth') {
        $conditions[] = '(l.module = ? OR l.action_code LIKE ? OR l.action_code = ?)';
        array_push($params, 'auth', 'login_%', 'logout');
    } elseif ($category === 'admin') {
        $conditions[] = '(l.module IN (?, ?, ?) OR l.action_code LIKE ? OR l.action_code LIKE ? OR (l.module IS NULL AND l.action_code NOT LIKE ? AND l.action_code <> ? AND l.action_code <> ?))';
        array_push($params, 'settings', 'permissions', 'audit', 'settings_%', 'role_%', 'login_%', 'logout', 'page_view');
    } elseif ($category === 'errors') {
        $conditions[] = '(l.action_code LIKE ? OR l.action_code LIKE ? OR l.action_code LIKE ? OR l.module = ?)';
        array_push($params, '%failed%', '%error%', '%locked%', 'error');
    }
    return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
}

/** تبدیل جزئیات فنی لاگ به جفت‌های عنوان/مقدار قابل‌خواندن، با حذف رازها. */
function activity_log_meta_lines(?string $json): array
{
    if (!$json) return [];
    $data = json_decode($json, true);
    if (!is_array($data)) return [['جزئیات', $json]];
    $labels = ['before'=>'قبل','after'=>'بعد','old'=>'مقدار قبلی','new'=>'مقدار جدید','old_status'=>'وضعیت قبلی','new_status'=>'وضعیت جدید','status'=>'وضعیت','role'=>'نقش','theme'=>'تم','reason'=>'علت','rows'=>'تعداد ردیف','deleted'=>'حذف‌شده','days'=>'بازه (روز)','ip'=>'آی‌پی','module'=>'بخش','page'=>'صفحه','target'=>'مورد','field'=>'فیلد','value'=>'مقدار','from'=>'از','to'=>'به','ok'=>'نتیجه','file'=>'فایل','line'=>'خط','uri'=>'نشانی درخواست','request_uri'=>'نشانی درخواست','attempts'=>'تعداد تلاش'];
    $lines=[]; $walk=function(array $items,string $prefix='') use (&$walk,&$lines,$labels): void {
        foreach($items as $key=>$value) {
            $key=(string)$key; if (preg_match('/pass(word)?|token|secret|credential|private.?key/i',$key)) {$lines[]=[($labels[$key]??$key),'••••••'];continue;}
            $label=$labels[$key]??str_replace('_',' ',$key); $label=$prefix!==''?$prefix.' · '.$label:$label;
            if(is_array($value)){ if($value===[]) {$lines[]=[$label,'—'];} else {$walk($value,$label);} }
            elseif(is_bool($value)) {$lines[]=[$label,$value?'بله':'خیر'];}
            elseif($value===null || $value==='') {$lines[]=[$label,'—'];}
            else {$lines[]=[$label,mb_substr((string)$value,0,500,'UTF-8')];}
            if(count($lines)>=20) return;
        }
    };
    $walk($data);
    return $lines;
}

function imported_calendar_date(string $value): ?string
{
    $value = trim(str_replace(['-', '.'], '/', strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])));
    if (preg_match('/^(13|14)\d{2}\/\d{1,2}\/\d{1,2}$/', $value)) {
        return jalali_input_to_gregorian($value);
    }
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $matches)) {
        $date = DateTimeImmutable::createFromFormat('!Y/n/j', $matches[1] . '/' . $matches[2] . '/' . $matches[3]);
        return $date && $date->format('Y/n/j') === $matches[1] . '/' . (int) $matches[2] . '/' . (int) $matches[3] ? $date->format('Y-m-d 00:00:00') : null;
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $matches)) {
        return imported_calendar_date($matches[1] . '/' . $matches[2] . '/' . $matches[3]);
    }
    return null;
}

function calendar_unescape(string $value): string
{
    return str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], trim($value));
}

function parse_calendar_import(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('فایل تقویم باید سالم و حداکثر ۲ مگابایت باشد.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'ics', 'txt'], true)) {
        throw new RuntimeException('فرمت تقویم باید CSV یا iCalendar با پسوند ICS باشد.');
    }
    $content = file_get_contents((string) $file['tmp_name']);
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('فایل تقویم خالی است.');
    }
    $events = [];
    if ($extension === 'ics') {
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/\r\n[ \t]/', '', $content) ?? $content) ?: [];
        $event = [];
        foreach ($lines as $line) {
            if (trim($line) === 'BEGIN:VEVENT') {
                $event = [];
            } elseif (trim($line) === 'END:VEVENT') {
                $date = imported_calendar_date((string) ($event['DTSTART'] ?? ''));
                $title = calendar_unescape((string) ($event['SUMMARY'] ?? 'تعطیلی رسمی'));
                if ($date && $title !== '') {
                    $events[] = ['date' => $date, 'title' => $title];
                }
                $event = [];
            } elseif (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key = strtoupper(trim(explode(';', $key, 2)[0]));
                if (in_array($key, ['DTSTART', 'SUMMARY'], true)) {
                    $event[$key] = trim($value);
                }
            }
        }
    } else {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $delimiter = str_contains((string) ($lines[0] ?? ''), ';') ? ';' : (str_contains((string) ($lines[0] ?? ''), "\t") ? "\t" : ',');
        $firstLine = (string) array_shift($lines);
        $header = array_map(static fn (string $value): string => strtolower(trim($value)), str_getcsv($firstLine, $delimiter));
        $dateIndex = null;
        $titleIndex = null;
        foreach ($header as $index => $name) {
            if ($dateIndex === null && in_array($name, ['date', 'holiday_date', 'dtstart', 'تاریخ', 'تاریخ تعطیلی'], true)) {
                $dateIndex = $index;
            }
            if ($titleIndex === null && in_array($name, ['title', 'summary', 'name', 'description', 'عنوان', 'مناسبت'], true)) {
                $titleIndex = $index;
            }
        }
        if ($dateIndex === null && $titleIndex === null) {
            array_unshift($lines, $firstLine);
        }
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $columns = str_getcsv($line, $delimiter);
            $rawDate = $dateIndex === null ? (string) ($columns[0] ?? '') : (string) ($columns[$dateIndex] ?? '');
            $rawTitle = $titleIndex === null ? (string) ($columns[1] ?? 'تعطیلی رسمی') : (string) ($columns[$titleIndex] ?? 'تعطیلی رسمی');
            $date = imported_calendar_date($rawDate);
            if ($date) {
                $events[] = ['date' => $date, 'title' => calendar_unescape($rawTitle) ?: 'تعطیلی رسمی'];
            }
        }
    }
    if (!$events) {
        throw new RuntimeException('هیچ رویداد قابل استفاده‌ای در فایل پیدا نشد.');
    }
    return $events;
}

function store_attachment(int $messageId, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('بارگذاری فایل انجام نشد.');
    }
    $max = (int) cfg('security.max_upload_mb', 8) * 1024 * 1024;
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ((int) $file['size'] > $max || !in_array($extension, (array) cfg('security.allowed_uploads', []), true)) {
        throw new RuntimeException('نوع یا حجم فایل مجاز نیست.');
    }
    $mime = 'application/octet-stream';
    $mimeChecked = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) (finfo_file($finfo, (string) $file['tmp_name']) ?: $mime);
            $mimeChecked = true;
            finfo_close($finfo);
        }
    }
    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'txt' => ['text/plain', 'text/csv', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
    if ($mimeChecked && isset($allowedMimes[$extension]) && !in_array($mime, $allowedMimes[$extension], true)) {
        throw new RuntimeException('محتوای فایل با پسوند آن مطابقت ندارد.');
    }
    $directory = APP_ROOT . '/storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('پوشه ذخیره فایل قابل ایجاد نیست.');
    }
    $stored = bin2hex(random_bytes(18)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $stored)) {
        throw new RuntimeException('ذخیره فایل انجام نشد.');
    }
    try {
        $query = db()->prepare('INSERT INTO ticket_attachments (message_id, original_name, stored_name, mime, size_bytes) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$messageId, basename((string) $file['name']), $stored, $mime, (int) $file['size']]);
    } catch (Throwable $exception) {
        @unlink($directory . '/' . $stored);
        throw $exception;
    }
}

function store_profile_photo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? '')) || (int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('عکس پروفایل باید سالم و حداکثر ۲ مگابایت باشد.');
    }
    $image = @getimagesize((string) ($file['tmp_name'] ?? ''));
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
    $extension = $types[(int) ($image[2] ?? 0)] ?? null;
    if ($extension === null || (int) ($image[0] ?? 0) > 5000 || (int) ($image[1] ?? 0) > 5000) {
        throw new RuntimeException('عکس پروفایل باید PNG یا JPG باشد.');
    }
    $directory = APP_ROOT . '/storage/profile';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('پوشه ذخیره عکس پروفایل قابل ایجاد نیست.');
    }
    $stored = 'profile-' . bin2hex(random_bytes(18)) . '.' . $extension;
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $stored)) {
        throw new RuntimeException('ذخیره عکس پروفایل انجام نشد.');
    }
    return $stored;
}

function valid_iranian_national_code(string $value): bool
{
    if (!preg_match('/^\d{10}$/', $value) || preg_match('/^(\d)\1{9}$/', $value)) {
        return false;
    }
    $check = (int) $value[9];
    $sum = 0;
    for ($index = 0; $index < 9; $index++) {
        $sum += (int) $value[$index] * (10 - $index);
    }
    $remainder = $sum % 11;
    return $check === ($remainder < 2 ? $remainder : 11 - $remainder);
}

function fetch_ticket(int $id): ?array
{
    $query = db()->prepare('SELECT t.*, c.name AS category_name, sc.name AS service_name, sc.code AS service_code, d.name AS department_name, ou.name AS requesting_unit_name, r.full_name AS requester_name, r.username AS requester_username, a.full_name AS assignee_name, ass.asset_tag, ass.hostname, tr.score AS rating_score, tr.comment AS rating_comment, tr.created_at AS rating_at FROM tickets t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN service_catalog sc ON sc.id = t.service_id LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN org_units ou ON ou.id = t.requesting_unit_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assigned_to LEFT JOIN assets ass ON ass.id = t.asset_id LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id WHERE t.id = ? LIMIT 1');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function can_view_ticket(array $ticket, array $user): bool
{
    // ۱.۳۷.۸ — دسترسی‌های مشاهدهٔ تیکت از سطوح دسترسی نقش‌ها خوانده می‌شود:
    // ticket.view_own (تیکت‌های خودم)، ticket.view_unit (واحد/زیرمجموعه)، ticket.view_all (همهٔ تیکت‌ها).
    if ((int) $ticket['requester_id'] === (int) $user['id'] && user_can($user, 'ticket.view_own')) {
        return true;
    }
    if (is_global_ticket_role($user['role']) && user_can($user, 'ticket.view_all')) {
        return true;
    }
    if (!user_can($user, 'ticket.view_unit')) {
        return false;
    }
    if ($user['role'] === 'agent') {
        return (string) ($ticket['service_group'] ?? '') === user_service_group($user);
    }
    return (is_department_scoped_role($user['role']) || $user['role'] === 'agent')
        && (string) ($ticket['service_group'] ?? '') === user_service_group($user)
        && (int) ($user['department_id'] ?? 0) > 0
        && (int) $ticket['department_id'] === (int) $user['department_id'];
}

function choose_handling_agent(string $serviceGroup): ?int
{
    $sql = 'SELECT u.id FROM users u LEFT JOIN tickets t ON t.assigned_to = u.id AND t.status <> "closed" WHERE u.is_active = 1 AND (u.role = "agent" OR u.is_it_agent = 1) AND (SELECT hu.code FROM handling_units hu WHERE hu.id = u.handling_unit_id LIMIT 1) = ?';
    $sql .= ' GROUP BY u.id ORDER BY COUNT(t.id), u.id LIMIT 1';
    $query = db()->prepare($sql);
    $query->execute([$serviceGroup]);
    $id = $query->fetchColumn();
    if ($id === false) {
        $fallback = db()->query('SELECT u.id FROM users u LEFT JOIN tickets t ON t.assigned_to = u.id AND t.status <> "closed" WHERE u.is_active = 1 AND (u.role = "agent" OR u.role = "admin" OR u.is_it_agent = 1) GROUP BY u.id ORDER BY COUNT(t.id), u.id LIMIT 1');
        $id = $fallback->fetchColumn();
    }
    return $id === false ? null : (int) $id;
}

/**
 * مسیر مجاز تغییر وضعیت توسط کارکنان (مدل رسمی گردش تیکت).
 * new/manager_review -> assigned -> in_progress <-> waiting_user -> resolved -> (امتیاز کاربر) -> closed توسط سوپروایزر
 */
function ticket_allowed_transitions(string $from): array
{
    $map = [
        'new' => ['manager_review', 'assigned', 'in_progress'],
        'manager_review' => ['assigned', 'in_progress'],
        'assigned' => ['in_progress', 'waiting_user', 'resolved', 'manager_review'],
        'in_progress' => ['waiting_user', 'resolved', 'assigned', 'manager_review'],
        'waiting_user' => ['in_progress', 'resolved', 'assigned'],
        'pending' => ['in_progress', 'waiting_user', 'resolved'],
        'resolved' => ['in_progress', 'assigned'],
        'closed' => [],
    ];
    return $map[$from] ?? [];
}

/** امتیازی معتبر است که ثبت شده باشد و قدیمی‌تر از آخرین «حل‌شدن» تیکت نباشد. */
function ticket_rating_is_current(array $ticket): bool
{
    if (empty($ticket['rating_score'])) {
        return false;
    }
    if (empty($ticket['resolved_at']) || empty($ticket['rating_at'])) {
        return true;
    }
    return strtotime((string) $ticket['rating_at']) >= strtotime((string) $ticket['resolved_at']);
}

/**
 * سطح اختیار کاربر روی تیکت: manager (ارجاع/تغییر کامل)، assignee (کارشناس مسئول)، یا رشتهٔ خالی.
 */
function ticket_handler_level(array $ticket, array $user): string
{
    if (!can_view_ticket($ticket, $user)) {
        return '';
    }
    if (user_can($user, 'ticket.assign')) {
        return 'manager';
    }
    if ((int) ($ticket['assigned_to'] ?? 0) === (int) $user['id'] && is_assignable_role((string) ($user['role'] ?? ''))) {
        return 'assignee';
    }
    return '';
}

/** اعلان «حل شد» به کاربر (برای ثبت امتیاز یا بازگشایی) و به مسئولان (برای پیگیری تأیید نهایی). */
function notify_ticket_resolved(int $ticketId, int $actorId): void
{
    $resolvedTicket = fetch_ticket($ticketId);
    if (!$resolvedTicket) {
        return;
    }
    notify_user((int) $resolvedTicket['requester_id'], 'ticket_resolved', 'درخواست شما انجام شد', 'تیکت ' . ticket_number($ticketId) . ' حل شد. لطفاً نتیجه را بررسی و امتیاز رضایت خود را ثبت کنید؛ اگر مشکل برطرف نشده است تیکت را بازگشایی کنید.', $ticketId);
    notify_ticket_staff($ticketId, 'ticket_resolved', 'تیکت حل شد و منتظر امتیاز کاربر است', 'تیکت ' . ticket_number($ticketId) . ' حل‌شده است و پس از ثبت امتیاز کاربر قابل تأیید نهایی است.', $actorId);
}
function report_query_filters(array $user): array
{
    [$scope, $params] = ticket_scope($user);
    $conditions = $scope !== '' ? [substr($scope, 6)] : [];
    $ticketColumns = db_table_columns('tickets');
    $status = valid_choice((string) ($_GET['status'] ?? ''), ['new', 'manager_review', 'assigned', 'in_progress', 'waiting_user', 'resolved', 'closed', 'active', 'all'], '');
    if ($status === 'active') {
        // «در حال پیگیری» = همهٔ وضعیت‌های باز (کارت داشبورد)
        $conditions[] = 't.status IN ("new", "manager_review", "assigned", "in_progress", "waiting_user")';
    } elseif ($status === 'resolved') {
        // «حل‌شده» در داشبورد شامل تیکت‌های بسته‌شده هم می‌شود
        $conditions[] = 't.status IN ("resolved", "closed")';
    } elseif ($status !== '' && $status !== 'all') {
        $conditions[] = 't.status = ?';
        $params[] = $status;
    }
    $priority = valid_choice((string) ($_GET['priority'] ?? ''), ['normal', 'urgent', 'critical'], '');
    if ($priority !== '') {
        $conditions[] = 't.priority = ?';
        $params[] = $priority;
    }
    $ticketType = valid_choice((string) ($_GET['ticket_type'] ?? ''), ['incident', 'request', 'problem', 'change'], '');
    if ($ticketType !== '' && isset($ticketColumns['ticket_type'])) {
        $conditions[] = 't.ticket_type = ?';
        $params[] = $ticketType;
    }
    $serviceGroup = valid_choice((string) ($_GET['service_group'] ?? ''), ['it', 'support'], '');
    if ($serviceGroup !== '' && isset($ticketColumns['service_group'])) {
        $conditions[] = 't.service_group = ?';
        $params[] = $serviceGroup;
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
        $value = (string) ($_GET[$key] ?? '');
        $converted = jalali_input_to_gregorian($value, $key === 'to');
        if ($converted !== null) {
            $conditions[] = 't.created_at ' . $operator . ' ?';
            $params[] = $converted;
        }
    }
    foreach (['department_id', 'assigned_to'] as $key) {
        $value = (int) ($_GET[$key] ?? 0);
        if ($value > 0) {
            $conditions[] = 't.' . $key . ' = ?';
            $params[] = $value;
        }
    }
    $search = trim((string) ($_GET['q'] ?? ''));
    if ($search !== '') {
        $conditions[] = '(t.subject LIKE ? OR t.description LIKE ? OR r.full_name LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
}

function render_header(string $title, ?array $user = null): void
{
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    $logo = setting('app_logo', (string) cfg('app.logo', ''));
    $flashes = take_flash();
    $theme = $user ? valid_choice((string) setting('theme_user_' . (int) $user['id'], 'current'), ['current', 'ruby', 'indigo', 'copper'], 'current') : 'current';
    $notificationSummary = $user ? notification_header_summary((int) $user['id']) : ['unread' => 0, 'latest' => 0];
    $notificationCount = $notificationSummary['unread'];
    echo '<!doctype html><html lang="fa" dir="rtl" data-theme="' . e($theme) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#102a43"><title>' . e($title . ' | ' . $appName) . '</title><link rel="stylesheet" href="assets/style.css?v=3.4.26"></head><body><div class="app-shell"><header class="topbar"><div class="topbar-inner"><a class="brand" href="index.php"><span class="brand-mark">' . ($logo ? '<img src="' . e($logo) . '" alt="' . e($appName) . '">' : '<span class="brand-glyph">پ</span>') . '</span><span><strong>' . e($appName) . '</strong><small>مرکز خدمات و پشتیبانی</small></span></a>';
    if ($user) {
        $menuIcons = [
            'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'ticket' => '<path d="M3 9a3 3 0 0 0 0 6v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a3 3 0 0 1 0-6V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"/>',
            'disc' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>',
            'traffic' => '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/>',
            'food' => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/>',
            'support' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
            'reports' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        ];
        $menuIcon = static function (string $name) use ($menuIcons): string {
            return '<svg class="icon-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($menuIcons[$name] ?? '') . '</svg>';
        };
        $menuLink = static function (string $href, string $label, string $icon) use ($menuIcon): string {
            return '<a href="' . e($href) . '">' . $menuIcon($icon) . '<span>' . e($label) . '</span></a>';
        };
        $submenuLink = static function (string $href, string $label): string {
            return '<li><a href="' . e($href) . '">' . e($label) . '</a></li>';
        };
        $submenu = static function (string $id, string $label, string $icon, array $links) use ($menuIcon): string {
            if (!$links) {
                return '';
            }
            return '<li class="has-submenu"><details class="nav-dropdown"><summary aria-controls="' . e($id) . '">' . $menuIcon($icon) . '<span>' . e($label) . '</span><span class="arrow" aria-hidden="true"></span></summary><ul class="submenu" id="' . e($id) . '">' . implode('', $links) . '</ul></details></li>';
        };
        $supportLinks = [
            $submenuLink('index.php?page=services', 'خدمات'),
            $submenuLink('index.php?page=knowledge', 'دانش‌نامه'),
        ];
        if (user_can($user, 'queue.view')) {
            $supportLinks[] = $submenuLink('index.php?page=queue', 'صف کاری');
        }
        if (user_can($user, 'assets.view')) {
            $supportLinks[] = $submenuLink('index.php?page=assets', 'شناسنامه‌های فنی');
        }
        // ۱.۳۷.۷ — «تغییر و مشکل» فقط برای کارمندان: پیش‌تر این آیتم در منوی کاربران عادی هم
        // دیده می‌شد، ولی خود صفحه با require_staff() رد می‌کرد.
        if (user_can($user, 'governance.view') && is_staff_role((string) $user['role'])) {
            $supportLinks[] = $submenuLink('governance.php', 'تغییر و مشکل');
        }
        if (user_can($user, 'supervisor.panel')) {
            $supportLinks[] = $submenuLink('index.php?page=supervisor', 'پنل سوپروایزر');
        }
        if (user_can($user, 'ola.view')) {
            $supportLinks[] = $submenuLink('ola.php', 'OLA');
        }
        $reportLinks = [];
        if (user_can($user, 'reports.view')) {
            $reportLinks[] = $submenuLink('index.php?page=reports', 'گزارش تیکت‌ها');
        }
        if (user_can($user, 'analytics.view')) {
            $reportLinks[] = $submenuLink('index.php?page=analytics', 'چارت‌ها');
        }
        if (user_can_any($user, ['logs.activity', 'audit.view', 'logs.view'])) {
            $reportLinks[] = $submenuLink('index.php?page=activity-log', 'لاگ سامانه');
        }
        $adminLinks = [];
        if (user_can($user, 'org.view')) {
            $adminLinks[] = $submenuLink('index.php?page=organization', 'سازمان');
        }
        if (user_can_any($user, ['settings.general', 'settings.domain', 'settings.users'])) {
            $adminLinks[] = $submenuLink('index.php?page=settings', 'تنظیمات');
        }
        if (user_can($user, 'backup.view')) {
            $adminLinks[] = $submenuLink('index.php?page=backup', 'پشتیبان‌گیری');
        }
        $menuItems = [
            '<li>' . $menuLink('index.php', 'داشبورد', 'home') . '</li>',
        ];
        if (user_can($user, 'ticket.create')) {
            $menuItems[] = '<li>' . $menuLink('index.php?page=new-ticket', 'تیکت', 'ticket') . '</li>';
        }
        if (user_can($user, 'cddvd.view')) {
            $menuItems[] = '<li>' . $menuLink('index.php?page=cd-dvd', 'کنترل CD/DVD', 'disc') . '</li>';
        }
        if (user_can($user, 'traffic.view')) {
            $menuItems[] = '<li>' . $menuLink('index.php?page=traffic-control', 'کنترل تردد', 'traffic') . '</li>';
        }
        if (user_can($user, 'foodorder.self')) {
            $menuItems[] = '<li>' . $menuLink('index.php?page=food-order', 'سفارش غذا', 'food') . '</li>';
        }
        if (food_ticket_is_allowed($user)) {
            $menuItems[] = '<li>' . $menuLink('index.php?page=food-ticket', 'چاپ فیش غذا', 'food') . '</li>';
        }
        $menuItems[] = $submenu('menu-support', 'پشتیبانی', 'support', $supportLinks);
        $menuItems[] = $submenu('menu-reports', 'گزارش‌ها', 'reports', $reportLinks);
        $menuItems[] = $submenu('menu-management', 'مدیریت', 'settings', $adminLinks);
        $menuItems[] = '<li>' . $menuLink('index.php?page=help', 'راهنما', 'support') . '</li>';
        if (user_can($user, 'notif.view')) {
    $menuItems[] = '<li class="notification-item"><a class="notification-link" href="index.php?page=notifications">' . $menuIcon('bell') . '<span>اعلان‌ها</span>' . ($notificationCount > 0 ? '<b>' . (int) $notificationCount . '</b>' : '') . '</a></li>';
        }
        $menuItems[] = '<li class="logout"><form method="post" class="logout-form">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="logout-link" type="submit">' . $menuIcon('logout') . '<span>خروج</span></button></form></li>';
        echo '<div class="header-tools"><form class="global-search header-search" method="get"><input type="hidden" name="page" value="search"><input name="q" placeholder="جست‌وجو در صفحه‌ها، تیکت، دارایی یا دانش‌نامه"></form><a class="profile-shortcut" href="index.php?page=profile#appearance">🎨 انتخاب تم</a><a class="profile-shortcut" href="index.php?page=profile">پروفایل: ' . e((string) $user['username']) . '</a></div>';
        echo '<nav class="navbar" aria-label="ناوبری اصلی"><ul class="menu">' . implode('', $menuItems) . '</ul></nav>';
    }
      echo '</div></header><div class="content-wrap">';
      echo '<div class="ambient-orbits" aria-hidden="true"><span class="orbit orbit-reusable orbit-reusable--top orbit-reusable--lg"></span><span class="orbit orbit-reusable orbit-reusable--ring orbit-reusable--md"></span><span class="orbit orbit-reusable orbit-reusable--bottom orbit-reusable--xl"></span><span class="orbit orbit-reusable orbit-reusable--side orbit-reusable--sm"></span><span class="orbit orbit-reusable orbit-reusable--corner orbit-reusable--md"></span></div>';
     if ($user) {
         echo '<div id="notification-toasts" class="notification-toasts" data-cursor="' . (int) $notificationSummary['latest'] . '" aria-live="polite"></div>';
     }
     foreach ($flashes as $flash) {
         echo '<div class="alert ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
     }
     // توکن CSRF را پیش از بستن session بساز تا فرم‌های همین صفحه معتبر بمانند.
     csrf_token();
     // آزادسازی قفل فایل session پس از رندر هدر تا درخواست‌های موازی (پولینگ اعلان‌ها و
     // بارگذاری تصاویر/فونت) پشت سر هم بلاک نشوند. فقط خواندن session بعد از این ممکن است.
     if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
         @session_write_close();
     }
 }

function render_footer(): void
{
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    echo '</div><footer class="footer">' . e($appName) . ' • نسخه سازمانی فارسی</footer></div><script>window.ITSM_HOLIDAYS = ';
    try {
        $__h = db()->query("SELECT holiday_date, title FROM holidays WHERE is_active = 1")->fetchAll();
        $__out = [];
        foreach ($__h as $__row) {
            $g = (string) $__row["holiday_date"];
            $parts = explode("-", substr($g, 0, 10));
            if (count($parts) === 3) {
                [$gy, $gm, $gd] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
                [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
                $__out[] = ["date" => $g, "jalali" => sprintf("%04d/%02d/%02d", $jy, $jm, $jd), "title" => (string) $__row["title"]];
            }
        }
        echo json_encode($__out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        echo "[]";
    }
    $pageParam = (string) ($_GET['page'] ?? '');
    $withParticles = !empty($GLOBALS['ui_particles']) || $pageParam === '' || $pageParam === 'dashboard';
    echo ';</script><script src="assets/jalali-calendar.js?v=3.4.7"></script><script src="assets/app.js?v=3.4.21"></script>' . ($withParticles ? '<script src="assets/particle-network.js?v=1" data-color="0,0,0"></script>' : '') . '</body></html>';
}

if (($_GET['action'] ?? '') === 'logout') {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    require_csrf();
    if (function_exists('activity_log')) {
        activity_log(['action_code' => 'logout', 'module' => 'auth']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    // توکن CSRF و کوکی آن را هم پاک کن تا پس از خروج، توکن قدیمی معتبر نماند.
    setcookie('itsm_csrf', '', time() - 42000, '/');
    unset($_COOKIE['itsm_csrf']);
    session_destroy();
    redirect('index.php?page=login');
}

$user = current_user();
$page = (string) ($_GET['page'] ?? ($user ? 'dashboard' : 'login'));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($page, ['organization', 'settings'], true)) {
    register_shutdown_function(static function (): void {
        try {
            org_sync_position_roles();
        } catch (Throwable $ignored) {
        }
    });
}

if ($user && function_exists('activity_log_view')) {
    activity_log_view($page);
}

if ($page === 'food-ticket' && isset($_GET['food_api'])) {
    // Rate limit جدا برای خواندن و نوشتن: پنل بازخوانی خودکار دارد (پایش، سلامت، سفارش‌ها) و نباید
    // ذخیرهٔ تنظیمات یا «آزمون کامل» را مسدود کند. خواندن‌ها: ۳۰۰ در دقیقه، نوشتن/آزمون‌ها: ۶۰ در دقیقه.
    if ($user) {
        $foodIsWrite = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
        check_api_rate_limit(
            'food_ticket_api_' . ($foodIsWrite ? 'w_' : 'r_') . $user['id'],
            $foodIsWrite ? 60 : 300,
            60
        );
    }
    food_ticket_api_handle((string) $_GET['food_api'], $user ?? []);
}

if ($page === 'food-order' && isset($_GET['food_api'])) {
    if ($user) {
        $foodOrderIsWrite = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
        check_api_rate_limit(
            'food_order_api_' . ($foodOrderIsWrite ? 'w_' : 'r_') . (int) $user['id'],
            $foodOrderIsWrite ? 60 : 300,
            60
        );
    }
    food_order_api_handle((string) $_GET['food_api'], $user ?? []);
}

if ($page === 'traffic-control' && isset($_GET['traffic_api'])) {
    // خواندن‌ها: ۳۰۰ در دقیقه، نوشتن‌ها: ۶۰ در دقیقه (برای هر کاربر)
    if ($user) {
        $trafficIsWrite = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
        check_api_rate_limit(
            'traffic_api_' . ($trafficIsWrite ? 'w_' : 'r_') . $user['id'],
            $trafficIsWrite ? 60 : 300,
            60
        );
    }
    traffic_api_handle((string) $_GET['traffic_api'], $user ?? []);
}

if (($_GET['action'] ?? '') === 'profile_photo') {
    $photoUser = require_login();
    $stored = basename((string) ($photoUser['profile_photo'] ?? ''));
    if ($stored === '' || !preg_match('/^profile-[a-f0-9]{36}\.(png|jpg)$/', $stored)) {
        http_response_code(404);
        exit('عکس پروفایل پیدا نشد.');
    }
    $candidates = [
        APP_ROOT . '/storage/profile/' . $stored,
    ];
    $photoPath = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $photoPath = $candidate;
            break;
        }
    }
    if ($photoPath === null) {
        http_response_code(404);
        exit('عکس پروفایل روی سرور موجود نیست.');
    }
    $mime = (string) (mime_content_type($photoPath) ?: 'application/octet-stream');
    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
        http_response_code(404);
        exit('نوع عکس پروفایل مجاز نیست.');
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($photoPath);
    exit;
}

if (($_GET['action'] ?? '') === 'notification_feed') {
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Rate limit: 60 requests per minute per user
    check_api_rate_limit('notification_feed_' . $user['id'], 60, 60);
    // این اپیندپوینت هر ۳۰ ثانیه صدا زده می‌شود؛ قفل session را فوراً آزاد می‌کنیم
    // تا بقیهٔ درخواست‌های کاربر پشت آن بلاک نشوند.
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    $afterId = max(0, (int) ($_GET['after'] ?? 0));
    $feedQuery = db()->prepare('SELECT n.id, n.ticket_id, n.title, n.body, n.created_at FROM notifications n WHERE n.user_id = ? AND n.id > ? ORDER BY n.id ASC LIMIT 20');
    $feedQuery->execute([(int) $user['id'], $afterId]);
    $items = array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'ticket_id' => $item['ticket_id'] !== null ? (int) $item['ticket_id'] : null,
            'title' => (string) $item['title'],
            'body' => (string) $item['body'],
        ];
    }, $feedQuery->fetchAll());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['action'] ?? '') === 'download_backup') {
    require_primary_admin();
    $filePath = backup_download_path((string) ($_GET['file'] ?? ''));
    if (!$filePath) {
        http_response_code(404);
        exit('فایل پشتیبان پیدا نشد.');
    }
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($extension === 'sql' ? 'application/sql' : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($filePath));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($filePath)));
    readfile($filePath);
    exit;
}

if (($_GET['action'] ?? '') === 'export_activity_logs') {
    require_permission('logs.activity_export');
    activity_logs_ensure();
    [$where, $params] = activity_log_filters();
    $query = db()->prepare('SELECT * FROM activity_logs l' . $where . ' ORDER BY l.created_at DESC, l.id DESC LIMIT 20000');
    $query->execute($params);
    $rows = [];
    foreach ($query->fetchAll() as $row) {
        $rows[] = [
            persian_date($row['created_at']),
            $row['full_name'] !== '' ? $row['full_name'] : ($row['username'] ?: '-'),
            $row['username'] ?: '-',
            permission_roles()[(string) ($row['role'] ?? '')] ?? (string) ($row['role'] ?? '-'),
            $row['action_label'] ?: $row['action_code'],
            $row['action_code'],
            $row['module'] ?: '-',
            $row['target_type'] ? $row['target_type'] . ($row['target_id'] ? ' #' . $row['target_id'] : '') : '-',
            $row['ip_address'] ?: '-',
            $row['computer_name'] ?: '-',
            $row['meta_json'] ?: '',
        ];
    }
    activity_log(['action_code' => 'activity_logs_exported', 'action_label' => 'خروجی Excel فعالیت کاربران', 'module' => 'activity-log', 'meta' => ['rows' => count($rows)]]);
    $excelFrom = jalali_input_to_gregorian((string) ($_GET['from'] ?? ''));
    $excelTo = jalali_input_to_gregorian((string) ($_GET['to'] ?? ''), true);
    excel_download('activity-log-' . date('Y-m-d') . '.xls', 'گزارش فعالیت کاربران', ['زمان', 'کاربر', 'نام کاربری', 'نقش', 'عملیات', 'کد عملیات', 'صفحه', 'هدف', 'آی‌پی', 'نام کامپیوتر', 'جزئیات'], $rows, ['from' => $excelFrom !== null ? substr($excelFrom, 0, 10) : '', 'to' => $excelTo !== null ? substr($excelTo, 0, 10) : '']);
}

if (($_GET['action'] ?? '') === 'export_report') {
    $reportUser = require_permission('reports.view');
    $reportMissingColumns = db_missing_columns('tickets', ['subject', 'description', 'status', 'priority', 'requester_id', 'department_id', 'service_group', 'created_at', 'updated_at', 'assigned_to', 'ticket_type', 'assigned_at', 'first_response_at', 'resolved_at', 'closed_at', 'due_at', 'sla_minutes']);
    if ($reportMissingColumns) {
        http_response_code(503);
        exit('ساختار پایگاه‌داده کامل نیست. ابتدا upgrade-1.2-itsm.sql و upgrade-1.8-routing.sql را اجرا کنید. ستون‌های ناقص: ' . e(implode(', ', $reportMissingColumns)));
    }
    [$where, $params] = report_query_filters($reportUser);
    $query = db()->prepare('SELECT t.id, t.subject, t.ticket_type, t.status, t.priority, t.created_at, t.updated_at, t.assigned_at, t.first_response_at, t.resolved_at, t.closed_at, t.due_at, t.sla_minutes, d.name AS department_name, r.full_name AS requester_name, a.full_name AS assignee_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assigned_to ' . $where . ' ORDER BY t.created_at DESC');
    $query->execute($params);
    $rows = [];
    while ($row = $query->fetch()) {
        $rows[] = [ticket_number((int) $row['id']), $row['subject'], ticket_type_label($row['ticket_type']), $row['department_name'], $row['requester_name'], $row['assignee_name'], status_label($row['status']), priority_label($row['priority']), persian_date($row['created_at']), persian_date($row['updated_at']), $row['assigned_at'] ? persian_date($row['assigned_at']) : '', $row['first_response_at'] ? persian_date($row['first_response_at']) : '', $row['resolved_at'] ? persian_date($row['resolved_at']) : '', $row['closed_at'] ? persian_date($row['closed_at']) : '', $row['due_at'] ? persian_date($row['due_at']) : '', $row['sla_minutes']];
    }
    $excelFrom = jalali_input_to_gregorian((string) ($_GET['from'] ?? ''));
    $excelTo = jalali_input_to_gregorian((string) ($_GET['to'] ?? ''), true);
    excel_download('ticket-report-' . date('Y-m-d') . '.xls', 'گزارش کامل تیکت‌ها', ['شماره', 'عنوان', 'نوع', 'واحد', 'ثبت‌کننده', 'کارشناس', 'وضعیت', 'اولویت', 'ثبت', 'آخرین تغییر', 'ارجاع', 'اولین پاسخ', 'حل', 'بسته‌شدن', 'مهلت SLA', 'دقیقه SLA'], $rows, ['from' => $excelFrom !== null ? substr($excelFrom, 0, 10) : '', 'to' => $excelTo !== null ? substr($excelTo, 0, 10) : '']);
}

if (($_GET['action'] ?? '') === 'export_assets') {
    $assetUser = require_permission('assets.view');
    asset_profile_ensure_schema();
    [$where, $params] = asset_scope($assetUser);
    $query = db()->prepare('SELECT a.*, d.name AS department_name, u.full_name AS owner_name FROM assets a LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.owner_user_id ' . $where . ' ORDER BY a.hostname, a.asset_tag');
    $query->execute($params);
    $profileColumns = asset_profile_export_columns();
    $headers = ['شناسه سیستم', 'نام سیستم', 'شماره سریال', 'نوع رایانه', 'معاونت', 'مالک', 'منبع استخراج', 'وضعیت شبکه', 'آخرین برداشت'];
    foreach ($profileColumns as $column) {
        $headers[] = $column['label'] . ' (' . $column['section'] . ')';
    }
    $rows = [];
    $assetRows = $query->fetchAll();
    $profileMap = asset_profile_map(array_column($assetRows, 'id'));
    foreach ($assetRows as $row) {
        $profile = $profileMap[(int) $row['id']] ?? [];
        $online = (int) ($profile['ad_online'] ?? 0) === 1;
        $wasScanned = (string) ($profile['ad_scanned_at'] ?? '') !== '';
        $netStatus = $online ? 'آنلاین' : ($wasScanned ? 'آفلاین' : 'پینگ نشده');
        $sourceLabels = ['server_inventory' => 'استخراج سرور پنل', 'agent' => 'Agent کلاینت', 'domain' => 'اسکن/دامنه', 'manual' => 'دستی'];
        $sourceLabel = $sourceLabels[(string) $row['source']] ?? (string) $row['source'];
        $exportRow = [
            asset_tag_label((string) $row['asset_tag'], (string) $row['hostname']),
            asset_display_name((string) $row['hostname']),
            (string) $row['serial_number'],
            (string) $row['computer_type'],
            (string) ($row['department_name'] ?? ''),
            (string) ($row['owner_name'] ?? ''),
            $sourceLabel,
            $netStatus,
            $row['last_inventory_at'] ? persian_date((string) $row['last_inventory_at']) : '',
        ];
        foreach ($profileColumns as $column) {
            $exportRow[] = asset_profile_export_value($column, $profile[$column['key']] ?? null);
        }
        $rows[] = $exportRow;
    }
    excel_download('asset-full-inventory-' . date('Y-m-d') . '.xls', 'گزارش کامل شناسنامه‌های فنی سیستم‌ها', $headers, $rows, ['from' => 'بدون فیلتر تاریخی', 'to' => 'بدون فیلتر تاریخی']);
}

if (($_GET['action'] ?? '') === 'export_cd_dvd') {
    cd_dvd_export(require_permission('cddvd.export'));
}

if (($_GET['action'] ?? '') === 'export_traffic_report') {
    require_permission('traffic.view');
    traffic_export_report();
}

if (($_GET['action'] ?? '') === 'download_attachment') {
    $downloadUser = require_login();
    $attachmentId = (int) ($_GET['id'] ?? 0);
    $attachmentQuery = db()->prepare('SELECT a.*, m.ticket_id, m.is_internal FROM ticket_attachments a JOIN ticket_messages m ON m.id = a.message_id WHERE a.id = ? LIMIT 1');
    $attachmentQuery->execute([$attachmentId]);
    $attachment = $attachmentQuery->fetch();
    $attachmentTicket = $attachment ? fetch_ticket((int) $attachment['ticket_id']) : null;
    $canDownloadInternal = is_staff_role($downloadUser['role']);
    if (!$attachment || !$attachmentTicket || !can_view_ticket($attachmentTicket, $downloadUser) || ((int) $attachment['is_internal'] === 1 && !$canDownloadInternal)) {
        http_response_code(404);
        exit('فایل پیدا نشد.');
    }
    $filePath = APP_ROOT . '/storage/uploads/' . basename((string) $attachment['stored_name']);
    if (!is_file($filePath)) {
        http_response_code(404);
        exit('فایل روی سرور موجود نیست.');
    }
    header('Content-Type: ' . ((string) $attachment['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($filePath));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string) $attachment['original_name']));
    readfile($filePath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $GLOBALS['activity_post'] = ['action' => $action, 'page' => (string) ($_GET['page'] ?? ''), 'failed' => false];
    register_shutdown_function(static function (): void {
        $pending = $GLOBALS['activity_post'] ?? null;
        if (!$pending || $pending['action'] === '' || $pending['failed'] || !function_exists('activity_log')) {
            return;
        }
        if (!empty($GLOBALS['activity_audited'])) {
            return;
        }
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($isAjax || str_starts_with((string) ($pending['page'] ?? ''), 'api')) {
            return;
        }
        static $skip = ['login', 'logout'];
        if (in_array($pending['action'], $skip, true)) {
            return;
        }
        activity_log([
            'action_code' => $pending['action'],
            'module' => $pending['page'] !== '' ? $pending['page'] : 'action',
        ]);
    });
    try {
        if ($action === 'login') {
            $username = post_value('username');
            $password = (string) ($_POST['password'] ?? '');
            if (!login_rate_allowed($username)) {
                flash('danger', 'تلاش‌های ورود بیش از حد مجاز است. ۱۵ دقیقه بعد دوباره تلاش کنید.');
                redirect('index.php?page=login');
            }
            $query = db()->prepare('SELECT * FROM users WHERE username = ? AND auth_source = "local" AND is_active = 1 LIMIT 1');
            $query->execute([$username]);
            $local = $query->fetch();
            if ($local && password_verify($password, (string) $local['password_hash'])) {
                db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$local['id']]);
                clear_login_failures($username);
                login_user($local);
                $initialAssetId = inventory_collect_client_asset($local);
                if ($initialAssetId) {
                    record_asset_history($initialAssetId, 'inventory_collected', 'شناسایی خودکار سیستم کلاینت در اولین ورود', (int) $local['id'], null, 'نام سیستم و IP کلاینت از درخواست ورود ثبت شد.');
                }
                inventory_auto_extract_server((int) $local['id']);
                redirect('index.php');
            }
            $identity = ldap_authenticate($username, $password);
            if ($identity) {
                $domainUser = upsert_domain_user($identity);
                if ((int) ($domainUser['is_active'] ?? 0) !== 1) {
                    throw new RuntimeException('حساب کاربری شبکه غیرفعال است. ادمین اصلی باید آن را از تنظیمات سامانه فعال کند.');
                }
                db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$domainUser['id']]);
                clear_login_failures($username);
                login_user($domainUser);
                $initialAssetId = inventory_collect_client_asset($domainUser);
                if ($initialAssetId) {
                    record_asset_history($initialAssetId, 'inventory_collected', 'شناسایی خودکار سیستم کلاینت در اولین ورود', (int) $domainUser['id'], null, 'نام سیستم و IP کلاینت از درخواست ورود ثبت شد.');
                }
                inventory_auto_extract_server((int) $domainUser['id']);
                redirect('index.php');
            }
            record_login_failure($username);
            flash('danger', 'نام کاربری یا رمز عبور صحیح نیست.');
            redirect('index.php?page=login');
        }

        $user = require_login();
        if ($action === 'save_theme') {
            $themeChoice = valid_choice(post_value('theme'), ['current', 'ruby', 'indigo', 'copper'], 'current');
            save_setting('theme_user_' . (int) $user['id'], $themeChoice);
            $GLOBALS['activity_audited'] = true;
            activity_log(['action_code' => 'user_theme_changed', 'action_label' => 'تغییر تم شخصی', 'module' => 'profile', 'meta' => ['theme' => ['current'=>'تم فعلی سبز و کرم','ruby'=>'یاقوتی رسمی','indigo'=>'لاجوردی مدیریتی','copper'=>'آجری روشن و کرم'][$themeChoice]]]);
            flash('success', 'تم انتخابی شما ذخیره و اعمال شد.');
            redirect('index.php?page=profile#appearance');
        }
        if ($action === 'save_profile') {
            $firstName = post_value('first_name');
            $lastName = post_value('last_name');
            $nationalCode = strtr(post_value('national_code'), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
            if ($firstName === '' || $lastName === '') {
                throw new RuntimeException('نام و نام خانوادگی را وارد کنید.');
            }
            if ($nationalCode !== '' && !valid_iranian_national_code($nationalCode)) {
                throw new RuntimeException('کد ملی معتبر نیست. کد ملی باید ۱۰ رقم و دارای رقم کنترل صحیح باشد.');
            }
            $photo = store_profile_photo((array) ($_FILES['profile_photo'] ?? []));
            $query = db()->prepare('UPDATE users SET first_name = ?, last_name = ?, full_name = ?, national_code = ?' . ($photo !== '' ? ', profile_photo = ?' : '') . ' WHERE id = ?');
            $values = [$firstName, $lastName, trim($firstName . ' ' . $lastName), $nationalCode !== '' ? $nationalCode : null];
            if ($photo !== '') {
                $values[] = $photo;
            }
            $values[] = (int) $user['id'];
            $query->execute($values);
            save_audit((int) $user['id'], 'profile_updated');
            flash('success', 'پروفایل کاربری شما ذخیره شد.');
            redirect('index.php?page=profile');
        }
        if ($action === 'save_food_ticket_brand') {
            $settingsAdmin = require_permission('settings.users_edit');
            $brandName = post_value('food_ticket_brand_name');
            if ($brandName === '') {
                throw new RuntimeException('نام سامانه چاپ فیش نمی‌تواند خالی باشد.');
            }
            save_setting('food_ticket_brand_name', $brandName);
            if (!empty($_FILES['food_ticket_logo']['tmp_name']) && is_uploaded_file($_FILES['food_ticket_logo']['tmp_name'])) {
                $mime = mime_content_type($_FILES['food_ticket_logo']['tmp_name']) ?: '';
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
                if (!isset($allowed[$mime]) || (int) $_FILES['food_ticket_logo']['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException('لوگوی پنل فیش باید PNG یا JPG و حداکثر ۲ مگابایت باشد.');
                }
                @mkdir(APP_ROOT . '/assets/uploads', 0755, true);
                $filename = 'food-ticket-logo-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($_FILES['food_ticket_logo']['tmp_name'], APP_ROOT . '/assets/uploads/' . $filename)) {
                    throw new RuntimeException('ذخیره لوگوی پنل فیش انجام نشد.');
                }
                save_setting('food_ticket_brand_logo', 'assets/uploads/' . $filename);
            }
            save_audit((int) $settingsAdmin['id'], 'food_ticket_brand_updated');
            flash('success', 'هویت بصری پنل چاپ فیش ذخیره شد.');
            redirect('index.php?page=settings#food-ticket-brand');
        }
        food_ticket_handle_post($action, $user);
        traffic_handle_post($action, $user);
        if ($action === 'cd_dvd_create') {
            $id = cd_dvd_create_record($user);
            flash('success', 'گردش رسانه با شماره ثبت ' . $id . ' ذخیره شد.');
            redirect('index.php?page=cd-dvd');
        }
        if ($action === 'cd_dvd_update') {
            cd_dvd_update_record($user);
            flash('success', 'ثبت رسانه به‌روزرسانی شد.');
            redirect('index.php?page=cd-dvd');
        }
        if ($action === 'cd_dvd_delete') {
            cd_dvd_delete_record($user);
            flash('success', 'ثبت رسانه حذف شد.');
            redirect('index.php?page=cd-dvd');
        }
        if ($action === 'create_backup') {
            $backupAdmin = require_permission('backup.manage');
            $bundle = create_backup_bundle();
            save_audit((int) $backupAdmin['id'], 'backup_created', null, ['name' => $bundle['name'], 'uploads' => $bundle['uploads']]);
            flash('success', 'نسخه پشتیبان ' . $bundle['name'] . ' ساخته شد.');
            redirect('index.php?page=backup');
        }
        if ($action === 'restore_backup') {
            $backupAdmin = require_permission('backup.manage');
            if (post_value('restore_confirm') !== '1') {
                throw new RuntimeException('برای بازیابی باید تأیید نهایی را فعال کنید.');
            }
            $safetyBundle = create_backup_bundle();
            restore_sql_dump($_FILES['sql_dump'] ?? []);
            $restoredUploads = 0;
            if (($_FILES['uploads_archive']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $restoredUploads = restore_upload_archive($_FILES['uploads_archive']);
            }
            save_audit((int) $backupAdmin['id'], 'backup_restored', null, ['safety_backup' => $safetyBundle['name'], 'uploads' => $restoredUploads]);
            flash('success', 'بازیابی انجام شد. نسخه ایمنی قبل از بازیابی: ' . $safetyBundle['name']);
            redirect('index.php?page=backup');
        }
        if ($action === 'mark_all_notifications') {
            db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')->execute([(int) $user['id']]);
            flash('success', 'همه اعلان‌ها خوانده‌شده علامت‌گذاری شدند.');
            redirect('index.php?page=notifications');
        }
        if ($action === 'mark_notification') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?')->execute([$notificationId, (int) $user['id']]);
            redirect('index.php?page=notifications');
        }
        if ($action === 'supervisor_decision') {
            $supervisor = require_permission('supervisor.panel');
            if (!user_can($supervisor, 'supervisor.decide')) {
                throw new RuntimeException('دسترسی تأیید یا برگشت تیکت برای نقش شما فعال نیست.');
            }
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $decision = valid_choice(post_value('decision'), ['approve', 'rework'], 'rework');
            $ticket = fetch_ticket($ticketId);
            if (!$ticket || $ticket['status'] !== 'resolved') {
                throw new RuntimeException('فقط تیکت حل‌شده و بسته‌نشده قابل تأیید یا برگشت برای اصلاح است.');
            }
            $note = post_value('supervisor_note');
            if ($decision === 'approve' && !ticket_rating_is_current($ticket)) {
                throw new RuntimeException('بستن نهایی پس از ثبت امتیاز رضایت کاربر امکان‌پذیر است.');
            }
            if ($decision === 'rework' && $note === '') {
                throw new RuntimeException('دلیل برگشت تیکت برای اصلاح را بنویسید.');
            }
            db()->beginTransaction();
            if ($decision === 'approve') {
                $closeQuery = db()->prepare('UPDATE tickets SET status = "closed", closed_at = COALESCE(closed_at, NOW()), supervisor_id = ?, supervisor_approved_at = NOW(), supervisor_note = ?, updated_at = NOW() WHERE id = ? AND status = "resolved"');
                $closeQuery->execute([$supervisor['id'], $note, $ticketId]);
                if ($closeQuery->rowCount() < 1) {
                    throw new RuntimeException('وضعیت تیکت هم‌زمان تغییر کرد. صفحه را تازه کنید.');
                }
                log_ticket_event($ticketId, (int) $supervisor['id'], 'supervisor_approved', $ticket['status'], 'closed', ['note' => $note]);
                notify_ticket_parties($ticketId, 'ticket_closed', 'تیکت بسته شد', 'تیکت ' . ticket_number($ticketId) . ' توسط سوپروایزر تایید و بسته شد.', (int) $supervisor['id']);
                record_asset_history((int) ($ticket['asset_id'] ?? 0), 'ticket_closed', 'تأیید و بستن تیکت ' . ticket_number($ticketId), (int) $supervisor['id'], $ticketId, $note);
                flash('success', 'انجام کار تأیید شد و تیکت بسته شد.');
            } else {
                $reworkStatus = !empty($ticket['assigned_to']) ? 'in_progress' : 'manager_review';
                $reworkDue = business_due_at(priority_sla_minutes((string) $ticket['priority']))->format('Y-m-d H:i:s');
                $reworkQuery = db()->prepare('UPDATE tickets SET status = ?, resolved_at = NULL, due_at = ?, sla_paused_at = NULL, sla_pause_minutes = 0, supervisor_id = ?, supervisor_note = ?, updated_at = NOW() WHERE id = ? AND status = "resolved"');
                $reworkQuery->execute([$reworkStatus, $reworkDue, $supervisor['id'], $note, $ticketId]);
                if ($reworkQuery->rowCount() < 1) {
                    throw new RuntimeException('وضعیت تیکت هم‌زمان تغییر کرد. صفحه را تازه کنید.');
                }
                db()->prepare('INSERT INTO ticket_messages (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, 0)')->execute([$ticketId, $supervisor['id'], 'برگشت برای اصلاح توسط سوپروایزر: ' . $note]);
                log_ticket_event($ticketId, (int) $supervisor['id'], 'supervisor_rework', $ticket['status'], $reworkStatus, ['note' => $note]);
                notify_ticket_parties($ticketId, 'supervisor_rework', 'تیکت برای اصلاح برگشت خورد', 'تیکت ' . ticket_number($ticketId) . ' توسط سوپروایزر برای اصلاح برگشت داده شد: ' . $note, (int) $supervisor['id']);
                flash('info', 'تیکت برای اصلاح و ادامه کار برگشت داده شد.');
            }
            db_commit('index.php');
            redirect('index.php?page=supervisor');
        }

        if ($action === 'supervisor_reopen') {
            $supervisor = require_permission('supervisor.panel');
            if (!user_can($supervisor, 'supervisor.reopen')) {
                throw new RuntimeException('دسترسی بازگشایی تیکت برای نقش شما فعال نیست.');
            }
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = fetch_ticket($ticketId);
            if (!$ticket || $ticket['status'] !== 'closed') {
                throw new RuntimeException('فقط تیکت بسته‌شده قابل بازگشایی رسمی است.');
            }
            $note = post_value('supervisor_note', 'بازگشایی رسمی برای ادامه رسیدگی');
            $reopenStatus = !empty($ticket['assigned_to']) ? 'in_progress' : 'manager_review';
            $dueAt = business_due_at(priority_sla_minutes((string) $ticket['priority']))->format('Y-m-d H:i:s');
            db()->beginTransaction();
            $reopenQuery = db()->prepare('UPDATE tickets SET status = ?, closed_at = NULL, resolved_at = NULL, supervisor_approved_at = NULL, supervisor_note = ?, due_at = ?, sla_paused_at = NULL, sla_pause_minutes = 0, updated_at = NOW() WHERE id = ? AND status = "closed"');
            $reopenQuery->execute([$reopenStatus, $note, $dueAt, $ticketId]);
            if ($reopenQuery->rowCount() < 1) {
                throw new RuntimeException('وضعیت تیکت هم‌زمان تغییر کرد. صفحه را تازه کنید.');
            }
            log_ticket_event($ticketId, (int) $supervisor['id'], 'supervisor_reopened', 'closed', $reopenStatus, ['note' => $note]);
            save_audit((int) $supervisor['id'], 'ticket_reopened', $ticketId, ['note' => $note]);
            notify_ticket_parties($ticketId, 'ticket_reopened', 'تیکت بازگشایی شد', 'تیکت ' . ticket_number($ticketId) . ' با تصمیم سوپروایزر بازگشایی شد.', (int) $supervisor['id']);
            db_commit('index.php');
            flash('info', 'تیکت با تصمیم سوپروایزر بازگشایی شد.');
            redirect('index.php?page=supervisor');
        }

        if ($action === 'rate_ticket') {
            if (!user_can($user, 'ticket.rate')) {
                throw new RuntimeException('دسترسی امتیازدهی به تیکت برای نقش شما مجاز نیست.');
            }
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = fetch_ticket($ticketId);
            $score = (int) ($_POST['score'] ?? 0);
            if (!$ticket || (int) $ticket['requester_id'] !== (int) $user['id']) {
                throw new RuntimeException('فقط ثبت‌کننده تیکت می‌تواند امتیاز ثبت کند.');
            }
            if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
                throw new RuntimeException('امتیاز فقط پس از حل‌شدن تیکت قابل ثبت است.');
            }
            if (ticket_rating_is_current($ticket)) {
                throw new RuntimeException('برای این تیکت قبلاً امتیاز ثبت شده است.');
            }
            if ($score < 1 || $score > 5) {
                throw new RuntimeException('امتیاز باید بین ۱ تا ۵ باشد.');
            }
            $comment = post_value('rating_comment');
            db()->beginTransaction();
            // اگر تیکت پس از امتیاز قبلی دوباره باز و حل شده باشد، امتیاز جدید جایگزین می‌شود.
            db()->prepare('INSERT INTO ticket_ratings (ticket_id, agent_id, requester_id, score, comment) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE agent_id = VALUES(agent_id), score = VALUES(score), comment = VALUES(comment), created_at = NOW()')->execute([$ticketId, $ticket['assigned_to'] ?: null, $user['id'], $score, $comment !== '' ? $comment : null]);
            save_audit((int) $user['id'], 'ticket_rated', $ticketId, ['score' => $score]);
            log_ticket_event($ticketId, (int) $user['id'], 'rated', (string) $ticket['status'], (string) $ticket['status'], ['score' => $score]);
            notify_ticket_staff($ticketId, 'ticket_rated', 'رضایت کاربر ثبت شد', 'برای تیکت ' . ticket_number($ticketId) . ' امتیاز ' . $score . ' از ۵ ثبت شد.' . ($ticket['status'] === 'resolved' ? ' تیکت آمادهٔ تأیید نهایی است.' : ''), (int) $user['id']);
            record_asset_history((int) ($ticket['asset_id'] ?? 0), 'ticket_rated', 'ثبت رضایت برای تیکت ' . ticket_number($ticketId), (int) $user['id'], $ticketId, 'امتیاز: ' . $score . ' از ۵');
            db_commit('index.php');
            flash('success', 'امتیاز رضایت شما با موفقیت ثبت شد.');
            redirect('index.php?page=ticket&id=' . $ticketId);
        }

         if ($action === 'collect_inventory') {
              $collectUser = require_permission('asset.extract');
              if (!is_global_ticket_role($collectUser['role']) && user_service_group($collectUser) !== 'it') {
                  throw new RuntimeException('دسترسی به استخراج اطلاعات این سیستم مجاز نیست.');
              }
              $overwrite = post_value('overwrite') === '1';
              if (function_exists('session_write_close')) {
                  @session_write_close();
              }
              $serverInventory = inventory_collect_server_reliable();
              $autoValues = asset_profile_auto_values($serverInventory);
              $assetId = inventory_upsert_server_asset($serverInventory, (int) $user['id']);
              $filled = asset_profile_apply_auto($assetId, $autoValues, (int) $user['id'], $overwrite);
              $collectError = inventory_last_error();
              $hardwareScore = inventory_hardware_score($serverInventory);
              record_asset_history($assetId, 'inventory_collected', 'استخراج اطلاعات این سیستم (سرور پنل)', (int) $user['id'], null, 'اطلاعات سخت‌افزاری و نرم‌افزاری این سیستم به‌روزرسانی شد.');
              save_audit((int) $user['id'], 'server_inventory_collected', null, ['asset_id' => $assetId, 'overwrite' => $overwrite, 'hardware_score' => $hardwareScore]);
              if ($collectError !== '' && $hardwareScore === 0) {
                  flash('danger', 'استخراج اطلاعات این سیستم ناموفق بود: ' . $collectError);
              } elseif ($hardwareScore === 0) {
                  flash('danger', 'سخت‌افزار این سیستم خوانده نشد. دسترسی حساب سرویس وب به PowerShell/WMI را بررسی کنید و از صفحهٔ «تست جمع‌آوری اطلاعات» (index.php?page=inventory-diagnostics) دکمهٔ «استخراج آزمایشی» را بزنید تا علت دقیق را ببینید.');
              } else {
                  flash('success', 'اطلاعات سخت‌افزاری و نرم‌افزاری این سیستم استخراج شد' . ($filled > 0 ? ' و ' . $filled . ' فیلد شناسنامه پر شد.' : '؛ فیلدهای خودکار از قبل پر بودند.'));
              }
              redirect('index.php?page=inventory&id=' . $assetId);
         }

        if ($action === 'asset_profile_selftest') {
            // استخراج آزمایشی: چه چیزی خوانده شد، چه فیلدی پر می‌شود و چه فیلدی چرا نه.
            $diagUser = require_permission('inventory.diagnostics');
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            if (function_exists('session_write_close')) {
                @session_write_close();
            }
            $overwrite = post_value('overwrite') === '1';
            $inventory = inventory_collect_server_reliable();
            $values = asset_profile_auto_values($inventory);
            $report = [
                'at' => date('Y-m-d H:i:s'),
                'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
                'hardware_score' => inventory_hardware_score($inventory),
                'error' => inventory_last_error(),
                'hostname' => (string) ($inventory['hostname'] ?? ''),
                'keys' => [],
                'filled' => 0,
                'empty' => 0,
                'asset_id' => 0,
                'applied' => 0,
                'rows' => [],
            ];
            foreach (['computer', 'bios', 'processor', 'os', 'motherboard', 'memory_modules', 'physical_disks', 'graphics', 'network', 'printers', 'software'] as $key) {
                $report['rows'][] = [$key, (string) count(inventory_rows($inventory['hardware'][$key] ?? $inventory[$key] ?? []))];
            }
            foreach ($values as $key => $value) {
                $text = trim((string) $value);
                if ($text === '') {
                    $report['empty']++;
                    continue;
                }
                $report['filled']++;
                if (count($report['keys']) < 60) {
                    $report['keys'][] = [$key, mb_substr($text, 0, 120)];
                }
            }
            if (inventory_hardware_score($inventory) > 0) {
                $report['asset_id'] = inventory_upsert_server_asset($inventory, (int) $diagUser['id']);
                if ($report['asset_id'] > 0) {
                    $report['applied'] = asset_profile_apply_auto($report['asset_id'], $values, (int) $diagUser['id'], $overwrite);
                }
            }
            $_SESSION['asset_selftest'] = $report;
            system_log('info', 'inventory', 'اجرای تست استخراج شناسنامه', ['filled' => $report['filled'], 'score' => $report['hardware_score'], 'asset_id' => $report['asset_id']]);
            flash($report['filled'] > 0 ? 'success' : 'danger', $report['filled'] > 0
                ? 'استخراج تست موفق بود: ' . $report['filled'] . ' فیلد شناسنامه به‌دست آمد' . ($report['applied'] > 0 ? ' و ' . $report['applied'] . ' فیلد ذخیره شد.' : '.')
                : 'استخراج تست هیچ فیلدی برنگرداند' . ($report['error'] !== '' ? ': ' . $report['error'] : '. جزئیات در همان صفحه آمده است.'));
            redirect('index.php?page=inventory-diagnostics' . ((int) ($_POST['asset_id'] ?? 0) > 0 ? '&id=' . (int) $_POST['asset_id'] : ''));
        }

        if ($action === 'import_domain_assets') {
            $importUser = require_permission('domain.import');
            $importResult = domain_scan_import((int) $importUser['id']);
            save_audit((int) $importUser['id'], 'domain_assets_imported', null, $importResult);
            flash('success', 'کامپیوترهای دامنه خوانده شد: ' . $importResult['created'] . ' سیستم جدید ثبت و ' . $importResult['updated'] . ' سیستم به‌روزرسانی شد (مجموع ' . $importResult['total'] . ').');
            redirect('index.php?page=assets');
        }

        if ($action === 'domain_scan_start') {
            $scanUser = require_permission('asset.remote_extract');
            $scanProblem = domain_scan_preflight();
            if ($scanProblem !== null) {
                throw new RuntimeException($scanProblem);
            }
            $scanResult = domain_scan_start((int) $scanUser['id']);
            flash('success', 'اسکن دامنه آغاز شد: ' . $scanResult['total'] . ' کامپیوتر در صف قرار گرفت.');
            redirect('index.php?page=domain-scan&run=' . $scanResult['run_id']);
        }

        if ($action === 'domain_scan_batch') {
            $scanUser = require_permission('asset.remote_extract');
            $runId = (int) ($_POST['run_id'] ?? 0);
            if ($runId <= 0 || domain_scan_run($runId) === null) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'اسکن پیدا نشد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $batchResult = domain_scan_process($runId, (int) ($_POST['limit'] ?? 3), (int) $scanUser['id']);
            $runRow = domain_scan_run($runId);
            header('Content-Type: application/json; charset=utf-8');
            $batchRows = [];
            foreach (domain_scan_queue_rows($runId, 1000) as $queueRow) {
                $batchRows[] = [
                    'hostname' => (string) $queueRow['hostname'],
                    'name' => asset_display_name((string) $queueRow['hostname']),
                    'status' => (string) $queueRow['status'],
                    'online' => (int) $queueRow['online'],
                    'message' => (string) ($queueRow['message'] ?? ''),
                ];
            }
            echo json_encode(['ok' => true, 'pending' => $batchResult['pending'], 'processed' => $batchResult['processed'], 'run' => $runRow, 'rows' => $batchRows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        if ($action === 'ping_assets') {
            $pingUser = require_permission('asset.ping');
            $pingAjax = post_value('ajax') === '1';
            $pingFail = static function (string $message) use ($pingAjax): void {
                if ($pingAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                flash('danger', $message);
                redirect('index.php?page=assets');
            };
            if (!is_global_ticket_role($pingUser['role']) && user_service_group($pingUser) !== 'it') {
                throw new RuntimeException('اجازهٔ پینگ سیستم‌ها را ندارید.');
            }
            if ($pingAjax && function_exists('session_write_close')) {
                @session_write_close();
            }
            $pingIds = $_POST['asset_ids'] ?? [];
            if (!is_array($pingIds)) {
                $pingIds = [];
            }
            $pingIds = array_values(array_unique(array_filter(array_map('intval', $pingIds), static fn (int $id): bool => $id > 0)));
            if ($pingIds === []) {
                $pingFail('هیچ سیستمی برای پینگ انتخاب نشد.');
            }
            if (count($pingIds) > 300) {
                $pingFail('برای هر نوبت حداکثر ۳۰۰ سیستم را پینگ کنید.');
            }
            [$pingScope, $pingScopeParams] = asset_scope($pingUser);
            $placeholders = implode(', ', array_fill(0, count($pingIds), '?'));
            $pingWhere = ($pingScope !== '' ? $pingScope . ' AND ' : 'WHERE ') . 'a.id IN (' . $placeholders . ')';
            $pingQuery = db()->prepare('SELECT a.id, a.hostname, a.ip_address FROM assets a ' . $pingWhere);
            $pingQuery->execute(array_merge($pingScopeParams, $pingIds));
            $targets = [];
            $pingFallbackIps = [];
            foreach ($pingQuery->fetchAll() as $row) {
                $host = trim((string) $row['hostname']);
                $storedIp = trim((string) $row['ip_address']);
                if ($host === '') {
                    $host = $storedIp;
                }
                if ($host !== '') {
                    $targets[(int) $row['id']] = $host;
                    if ($storedIp !== '') {
                        $pingFallbackIps[strtolower($host)] = $storedIp;
                    }
                }
            }
            if ($targets === []) {
                $pingFail('سیستم‌های انتخاب‌شده نام کامپیوتر یا IP ندارند.');
            }
            $pingResults = domain_scan_ping_hosts(array_values($targets), $pingFallbackIps);
            $onlineCount = 0;
            $checkedCount = 0;
            $pingStates = [];
            foreach ($targets as $targetId => $targetHost) {
                $isOnline = (bool) ($pingResults[strtolower($targetHost)] ?? false);
                domain_scan_mark_reachability($targetId, $isOnline, (int) $pingUser['id']);
                $pingStates[$targetId] = $isOnline;
                $checkedCount++;
                if ($isOnline) {
                    $onlineCount++;
                }
            }
            system_log('info', 'domain_scan', 'پینگ دستی سیستم‌ها', ['checked' => $checkedCount, 'online' => $onlineCount]);
            save_audit((int) $pingUser['id'], 'assets_pinged', null, ['checked' => $checkedCount, 'online' => $onlineCount]);
            if ($pingAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'checked' => $checkedCount, 'online' => $onlineCount, 'states' => $pingStates], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('success', 'پینگ انجام شد: ' . $checkedCount . ' سیستم بررسی شد؛ ' . $onlineCount . ' آنلاین و ' . ($checkedCount - $onlineCount) . ' آفلاین.');
            redirect('index.php?page=assets');
        }

        if ($action === 'domain_scan_retry') {
            $retryUser = require_permission('asset.remote_extract');
            $retryRunId = (int) ($_POST['run_id'] ?? 0);
            $retry = domain_scan_retry_failed($retryRunId, (int) $retryUser['id']);
            if ($retry['total'] === 0) {
                flash('info', 'در این نوبت سیستم خطاداری نبود؛ چیزی برای تلاش دوباره وجود ندارد.');
                redirect('index.php?page=domain-scan&run=' . $retryRunId);
            }
            save_audit((int) $retryUser['id'], 'domain_inventory_retry', null, ['from_run' => $retryRunId, 'run_id' => $retry['run_id'], 'total' => $retry['total']]);
            flash('success', $retry['total'] . ' سیستم ناموفق در نوبت تازه #' . $retry['run_id'] . ' دوباره در صف استخراج قرار گرفت.');
            redirect('index.php?page=domain-scan&run=' . $retry['run_id']);
        }

        if ($action === 'domain_scan_selected') {
            $scanUser = require_permission('asset.remote_extract');
            $scanProblem = domain_scan_preflight();
            if ($scanProblem !== null) {
                flash('danger', $scanProblem);
                redirect('index.php?page=assets');
            }
            $scanResult = domain_scan_start_selected((array) ($_POST['asset_ids'] ?? []), $scanUser);
            save_audit((int) $scanUser['id'], 'domain_inventory_selected', null, ['run_id' => $scanResult['run_id'], 'total' => $scanResult['total']]);
            flash('success', $scanResult['total'] . ' سیستم وارد صف استخراج شبکه‌ای شد. سیستم‌های خاموش یا غیرقابل‌دسترسی با وضعیت جداگانه گزارش می‌شوند.');
            redirect('index.php?page=domain-scan&run=' . $scanResult['run_id']);
        }

        if ($action === 'save_inventory_form') {
            $inventoryUser = require_permission('inventory.view');
            if (!user_can($inventoryUser, 'asset.edit')) {
                throw new RuntimeException('ویرایش شناسنامهٔ دارایی برای نقش شما فعال نیست.');
            }
            $assetId = (int) ($_POST['asset_id'] ?? 0);
            [$assetScope, $assetParams] = asset_scope($inventoryUser);
            $assetQuery = db()->prepare('SELECT * FROM assets a ' . ($assetScope ? $assetScope . ' AND a.id = ?' : 'WHERE a.id = ?') . ' LIMIT 1');
            $assetQuery->execute(array_merge($assetParams, [$assetId]));
            $asset = $assetQuery->fetch();
            if (!$asset) {
                throw new RuntimeException('سیستم پیدا نشد یا دسترسی ندارید.');
            }
            $assetTag = (string) $asset['asset_tag'];
            $hostname = post_value('hostname');
            if ($hostname === '' && is_array($_POST['profile'] ?? null)) {
                $hostname = trim((string) ($_POST['profile']['hostname'] ?? ''));
            }
            if ($assetTag === '' || $hostname === '') {
                throw new RuntimeException('نام کامپیوتر الزامی است و شناسه سیستم باید از Inventory حفظ شود.');
            }
            $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
            if (is_department_scoped_role($inventoryUser['role'])) {
                $departmentId = (int) ($inventoryUser['department_id'] ?? 0) ?: null;
            }
            $fields = [
                'hostname' => $hostname,
                'computer_type' => post_value('computer_type'),
                'manufacturer' => post_value('manufacturer'),
                'model' => post_value('model'),
                'asset_number' => post_value('asset_number'),
                'seal_number' => post_value('seal_number'),
                'employee_number' => post_value('employee_number'),
                'phone' => post_value('phone'),
                'department_id' => $departmentId,
                'operating_system' => post_value('operating_system'),
                'os_architecture' => post_value('os_architecture'),
                'os_serial' => post_value('os_serial'),
                'os_install_date' => post_value('os_install_date') !== '' ? jalali_input_to_gregorian(post_value('os_install_date')) : null,
                'registered_users' => (int) ($_POST['registered_users'] ?? 0) ?: null,
                'ip_address' => post_value('ip_address'),
                'mac_address' => post_value('mac_address'),
                'cpu' => post_value('cpu'),
                'memory_mb' => (int) ($_POST['memory_mb'] ?? 0) ?: null,
                'domain_username' => post_value('domain_username'),
                'antivirus' => post_value('antivirus'),
                'sound_card' => post_value('sound_card'),
                'network_card' => post_value('network_card'),
            ];
            $fields = array_filter($fields, static fn (mixed $value, string $key): bool => array_key_exists($key, $_POST), ARRAY_FILTER_USE_BOTH);
            $fields['hostname'] = $hostname;
            asset_profile_ensure_schema();
            db()->beginTransaction();
            $set = implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($fields)));
            try {
                db()->prepare('UPDATE assets SET ' . $set . ', updated_at = NOW() WHERE id = ?')->execute([...array_values($fields), $assetId]);
                asset_profile_save($assetId, $_POST, (int) $inventoryUser['id']);
                if (db()->inTransaction()) {
                    db_commit('index.php');
                }
            } catch (Throwable $saveException) {
                if (db()->inTransaction()) {
                    db_rollback('index.php');
                }
                throw $saveException;
            }
            record_asset_history($assetId, 'inventory_manual_update', 'ویرایش شناسنامه سیستم', (int) $inventoryUser['id'], null, 'فرم شناسنامه سیستم ذخیره شد.', $asset, $fields);
            flash('success', 'شناسنامه سیستم و اطلاعات سخت‌افزاری ذخیره شد.');
             redirect('index.php?page=inventory&id=' . $assetId);
         }

        if ($action === 'create_ticket') {
            ticket_service_ensure_schema();
            org_ticket_schema_ensure();
            $subject = post_value('subject');
            $description = post_value('description');
            $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
            $serviceId = (int) ($_POST['service_id'] ?? 0) ?: null;
            $ticketType = valid_choice(post_value('ticket_type', 'incident'), ['incident', 'request', 'problem', 'change'], 'incident');
            if (!is_staff_role($user['role']) && in_array($ticketType, ['problem', 'change'], true)) {
                $ticketType = 'request';
            }
             $serviceGroup = valid_choice(post_value('service_group', user_service_group($user)), ['it', 'support'], 'support');
             $handlingUnitId = null;
             $service = null;
             $customFields = [];
             $cdDvdRequestId = (int) ($_POST['cd_dvd_record_id'] ?? 0);
             $cdDvdRequestRecord = null;
             if ($cdDvdRequestId > 0) {
                 $cdDvdRequestRecord = cd_dvd_fetch_record($cdDvdRequestId);
                 if (!$cdDvdRequestRecord || cd_dvd_can_edit_records($user) || !cd_dvd_can_view_record($cdDvdRequestRecord, $user)) {
                     throw new RuntimeException('رکورد CD/DVD برای درخواست اصلاح معتبر نیست.');
                 }
                 $customFields['_cd_dvd_request'] = 'edit';
                 $customFields['_cd_dvd_record_id'] = (string) $cdDvdRequestId;
                 $serviceGroup = 'support';
                 $serviceId = null;
                 $categoryId = null;
             }
            if ($serviceId !== null) {
                $serviceQuery = db()->prepare('SELECT * FROM service_catalog WHERE id = ? AND is_active = 1 LIMIT 1');
                $serviceQuery->execute([$serviceId]);
                $service = $serviceQuery->fetch();
                if (!$service) {
                    throw new RuntimeException('خدمت انتخاب‌شده فعال نیست.');
                }
                $serviceGroup = (string) $service['service_group'];
                if ($categoryId === null && (int) ($service['category_id'] ?? 0) > 0) {
                    $categoryId = (int) $service['category_id'];
                }
                $handlingUnitId = (int) ($service['handling_unit_id'] ?? 0) ?: null;
                $ticketType = valid_choice((string) ($service['default_ticket_type'] ?? $ticketType), ['incident', 'request', 'problem', 'change'], $ticketType);
                $fieldQuery = db()->prepare('SELECT * FROM service_catalog_fields WHERE service_id = ? ORDER BY sort_order, id');
                $fieldQuery->execute([$serviceId]);
                foreach ($fieldQuery->fetchAll() as $field) {
                    $fieldValue = trim((string) ($_POST['custom'][$field['field_key']] ?? ''));
                    if ((int) $field['is_required'] === 1 && $fieldValue === '') {
                        throw new RuntimeException('فیلد «' . $field['label'] . '» الزامی است.');
                    }
                    if ($field['field_type'] === 'select' && $fieldValue !== '') {
                        $options = json_decode((string) $field['options_json'], true) ?: [];
                        if (!in_array($fieldValue, $options, true)) {
                            throw new RuntimeException('مقدار فیلد «' . $field['label'] . '» معتبر نیست.');
                        }
                    }
                    if ($field['field_type'] === 'date' && $fieldValue !== '' && jalali_input_to_gregorian($fieldValue) === null) {
                        throw new RuntimeException('تاریخ فیلد «' . $field['label'] . '» باید به‌صورت جلالی وارد شود.');
                    }
                    $customFields[$field['field_key']] = $fieldValue;
                 }
             }
             if ($cdDvdRequestRecord) {
                 $serviceGroup = 'support';
                 $ticketType = 'request';
             }
             if ($serviceId === null && !$cdDvdRequestRecord) {
                 throw new RuntimeException('نوع خدمت را از فهرست انتخاب کنید.');
             }
             if ($categoryId !== null) {
                $categoryCheck = db()->prepare('SELECT service_group FROM categories WHERE id = ? AND is_active = 1 LIMIT 1');
                $categoryCheck->execute([$categoryId]);
                if ((string) $categoryCheck->fetchColumn() !== $serviceGroup) {
                    throw new RuntimeException('دسته‌بندی انتخاب‌شده با حوزه خدمت سازگار نیست.');
                }
            }
            if ($handlingUnitId === null) {
                $unitQuery = db()->prepare('SELECT id FROM handling_units WHERE code = ? AND is_active = 1 LIMIT 1');
                $unitQuery->execute([$serviceGroup]);
                $handlingUnitId = (int) ($unitQuery->fetchColumn() ?: 0) ?: null;
            }
            $departmentId = (int) ($_POST['department_id'] ?? 0) ?: (int) ($user['department_id'] ?? 0) ?: null;
            if (!is_global_ticket_role($user['role']) && !is_it_agent($user)) {
                $departmentId = (int) ($user['department_id'] ?? 0) ?: null;
            }
            // معاونت سازمانی درخواست‌کننده از چارت سازمانی (org_units) جدا از مسیر رسیدگی
            // در ستون مستقل ذخیره می‌شود و تیکت به همان معاونت مسیردهی می‌شود.
            $requestingUnitId = (int) ($_POST['requesting_unit_id'] ?? 0) ?: null;
            if ($requestingUnitId !== null && function_exists('org_unit_by_id') && !org_unit_by_id($requestingUnitId)) {
                $requestingUnitId = null;
            }
            if ($requestingUnitId !== null && function_exists('org_unit_routing_department_id')) {
                $routingDepartmentId = org_unit_routing_department_id($requestingUnitId);
                if ($routingDepartmentId > 0) {
                    $departmentId = $routingDepartmentId;
                }
            }
            $assetId = (int) ($_POST['asset_id'] ?? 0) ?: null;
            // ۱.۳۷.۲: قاعدهٔ روشن و یکسان با پنل —
            //   حوزهٔ «خدمات کامپیوتری و IT» ⇒ انتخاب «سیستم مرتبط» الزامی است.
            //   حوزهٔ «خدمات پشتیبانی» ⇒ انتخاب سیستم لازم نیست (فیلد در فرم هم پنهان است).
            $requiresAsset = $serviceGroup === 'it' ? true : ($service ? (int) $service['requires_asset'] === 1 : false);
            if ($requiresAsset && $assetId === null) {
                throw new RuntimeException('در حوزهٔ خدمات کامپیوتری، انتخاب «سیستم مرتبط» الزامی است. نام رایانه را در فیلد «سیستم مرتبط» جست‌وجو کنید و یکی از نتایج را انتخاب کنید؛ اگر سیستم شناسنامه ندارد، ابتدا شناسنامه‌اش را ثبت کنید.');
            }
            if ($assetId !== null) {
                $assetCheck = db()->prepare('SELECT id, owner_user_id, department_id, ip_address FROM assets WHERE id = ? LIMIT 1');
                $assetCheck->execute([$assetId]);
                $assetRow = $assetCheck->fetch();
                $sameClient = $assetRow && inventory_client_ip() !== '' && (string) $assetRow['ip_address'] === inventory_client_ip();
                if (!$assetRow || ($user['role'] === 'user' && (int) $assetRow['owner_user_id'] !== (int) $user['id'] && !$sameClient) || (is_department_scoped_role($user['role']) && !is_it_agent($user) && (int) $assetRow['department_id'] !== (int) ($user['department_id'] ?? 0))) {
                    throw new RuntimeException('سیستم انتخاب‌شده برای این حساب قابل استفاده نیست.');
                }
            }
             $supportLocation = post_value('support_location');
             $supportEquipment = post_value('support_equipment');
             if ($cdDvdRequestRecord && $supportLocation === '') {
                 $supportLocation = 'کنترل CD/DVD';
             }
            if ($serviceGroup === 'support' && $supportLocation === '') {
                throw new RuntimeException('برای تیکت پشتیبانی، محل خدمت یا اتاق را وارد کنید.');
            }
            $priority = valid_choice(post_value('priority') ?: (string) ($service['default_priority'] ?? 'normal'), ['normal', 'urgent', 'critical'], 'normal');
            $parentTicketId = (int) ($_POST['parent_ticket_id'] ?? 0) ?: null;
            $canReferenceTicket = is_staff_role($user['role']) || is_it_agent($user);
            if ($parentTicketId !== null) {
                $parentTicket = $canReferenceTicket ? fetch_ticket($parentTicketId) : null;
                if (!$parentTicket || !can_view_ticket($parentTicket, $user)) {
                    $parentTicketId = null;
                    flash('warning', 'تیکت مرجع نامعتبر بود و نادیده گرفته شد.');
                }
            }
            $slaMinutes = priority_sla_minutes($priority);
            $createdAt = new DateTimeImmutable('now', new DateTimeZone((string) cfg('app.timezone', 'Asia/Tehran')));
            $dueAt = business_due_at($slaMinutes, $createdAt)->format('Y-m-d H:i:s');
            $ola = ola_deadlines($departmentId, $priority, $createdAt->format('Y-m-d H:i:s'));
            if ($subject === '' || $description === '') {
                throw new RuntimeException('عنوان و شرح مشکل الزامی است.');
            }
            db()->beginTransaction();
            $agentId = choose_handling_agent($serviceGroup);
            $initialStatus = $agentId ? 'assigned' : 'manager_review';
            $ticketNo = ticket_number_next(ticket_number_prefix($serviceGroup));
            $query = db()->prepare('INSERT INTO tickets (ticket_no, subject, description, category_id, service_id, requesting_unit_id, ticket_type, parent_ticket_id, custom_fields, service_group, department_id, asset_id, handling_unit_id, support_location, support_equipment, priority, requester_id, assigned_to, assigned_at, status, sla_minutes, due_at, ola_policy_id, ola_response_due_at, ola_due_at, ola_escalation_1_at, ola_escalation_2_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($agentId ? 'NOW()' : 'NULL') . ', "' . $initialStatus . '", ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([$ticketNo, $subject, $description, $categoryId, $serviceId, $requestingUnitId, $ticketType, $parentTicketId, json_encode($customFields, JSON_UNESCAPED_UNICODE), $serviceGroup, $departmentId, $assetId, $handlingUnitId, $supportLocation ?: null, $supportEquipment ?: null, $priority, $user['id'], $agentId, $slaMinutes, $dueAt, $ola['policy_id'], $ola['response_due_at'], $ola['due_at'], $ola['escalation_1_at'], $ola['escalation_2_at']]);
            $ticketId = (int) db()->lastInsertId();
            if ($ticketId > 0) {
                ticket_number_cache_set($ticketId, $ticketNo);
            }
            $message = db()->prepare('INSERT INTO ticket_messages (ticket_id, user_id, body) VALUES (?, ?, ?)');
            $message->execute([$ticketId, $user['id'], $description]);
            store_attachment((int) db()->lastInsertId(), $_FILES['attachment'] ?? []);
             $ticketAuditMeta = ['subject' => $subject];
             $ticketEventDetails = ['department_id' => $departmentId, 'sla_minutes' => $slaMinutes, 'ticket_type' => $ticketType, 'service_id' => $serviceId];
             if ($cdDvdRequestRecord) {
                 $ticketAuditMeta['cd_dvd_record_id'] = $cdDvdRequestId;
                 $ticketEventDetails['cd_dvd_record_id'] = $cdDvdRequestId;
             }
             save_audit((int) $user['id'], 'ticket_created', $ticketId, $ticketAuditMeta);
             log_ticket_event($ticketId, (int) $user['id'], 'created', null, $initialStatus, $ticketEventDetails);
            if ($agentId) {
                log_ticket_event($ticketId, (int) $user['id'], 'auto_assigned', 'manager_review', 'assigned', ['assigned_to' => $agentId]);
            }
            notify_ticket_parties($ticketId, 'ticket_created', 'تیکت جدید ثبت شد', 'تیکت ' . ticket_number($ticketId) . ' برای رسیدگی ثبت شد.', (int) $user['id']);
            if ($assetId) {
                record_asset_history((int) $assetId, 'ticket_created', 'ثبت تیکت ' . ticket_number($ticketId), (int) $user['id'], $ticketId, $subject);
            }
            db_commit('index.php');
            flash('success', 'تیکت ' . ticket_number($ticketId) . ' ثبت شد.');
            redirect('index.php?page=ticket&id=' . $ticketId);
        }

        if ($action === 'claim_ticket') {
            $claimUser = require_login();
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = fetch_ticket($ticketId);
            if (!$ticket || !can_view_ticket($ticket, $claimUser)) {
                throw new RuntimeException('تیکت پیدا نشد یا دسترسی ندارید.');
            }
            if (!is_assignable_role((string) $claimUser['role'])) {
                throw new RuntimeException('فقط کارشناس یا مدیر واحد می‌تواند تیکت را بردارد.');
            }
            if (!empty($ticket['assigned_to']) || !in_array($ticket['status'], ['new', 'manager_review'], true)) {
                throw new RuntimeException('این تیکت قبلاً به کارشناس ارجاع شده است.');
            }
            db()->beginTransaction();
            $claimQuery = db()->prepare('UPDATE tickets SET assigned_to = ?, assigned_at = NOW(), status = "assigned", updated_at = NOW() WHERE id = ? AND assigned_to IS NULL AND status IN ("new", "manager_review")');
            $claimQuery->execute([(int) $claimUser['id'], $ticketId]);
            if ($claimQuery->rowCount() < 1) {
                throw new RuntimeException('این تیکت هم‌زمان توسط شخص دیگری برداشته شد.');
            }
            save_audit((int) $claimUser['id'], 'ticket_claimed', $ticketId);
            log_ticket_event($ticketId, (int) $claimUser['id'], 'claimed', (string) $ticket['status'], 'assigned', ['assigned_to' => (int) $claimUser['id']]);
            notify_ticket_parties($ticketId, 'ticket_updated', 'تیکت به کارشناس ارجاع شد', 'تیکت ' . ticket_number($ticketId) . ' توسط ' . ($claimUser['full_name'] ?? 'کارشناس') . ' برای رسیدگی برداشته شد.', (int) $claimUser['id']);
            db_commit('index.php');
            flash('success', 'تیکت به شما ارجاع شد.');
            redirect('index.php?page=ticket&id=' . $ticketId);
        }

        if ($action === 'reopen_ticket') {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = fetch_ticket($ticketId);
            if (!$ticket || (int) $ticket['requester_id'] !== (int) $user['id']) {
                throw new RuntimeException('فقط ثبت‌کننده تیکت می‌تواند آن را بازگشایی کند.');
            }
            if ($ticket['status'] !== 'resolved') {
                throw new RuntimeException('فقط تیکت حل‌شده و هنوز بسته‌نشده قابل بازگشایی است.');
            }
            $reason = post_value('reopen_reason');
            if ($reason === '') {
                throw new RuntimeException('توضیح دهید چه چیزی هنوز برطرف نشده است.');
            }
            $reopenStatus = !empty($ticket['assigned_to']) ? 'in_progress' : 'manager_review';
            $reopenDue = business_due_at(priority_sla_minutes((string) $ticket['priority']))->format('Y-m-d H:i:s');
            db()->beginTransaction();
            $reopenQuery = db()->prepare('UPDATE tickets SET status = ?, resolved_at = NULL, due_at = ?, sla_paused_at = NULL, sla_pause_minutes = 0, updated_at = NOW() WHERE id = ? AND status = "resolved"');
            $reopenQuery->execute([$reopenStatus, $reopenDue, $ticketId]);
            if ($reopenQuery->rowCount() < 1) {
                throw new RuntimeException('وضعیت تیکت هم‌زمان تغییر کرد. صفحه را تازه کنید.');
            }
            db()->prepare('INSERT INTO ticket_messages (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, 0)')->execute([$ticketId, $user['id'], 'مشکل حل نشده است (بازگشایی توسط کاربر): ' . $reason]);
            save_audit((int) $user['id'], 'ticket_reopened_by_user', $ticketId, ['reason' => $reason]);
            log_ticket_event($ticketId, (int) $user['id'], 'reopened_by_user', 'resolved', $reopenStatus, ['reason' => $reason]);
            notify_ticket_staff($ticketId, 'ticket_reopened', 'کاربر اعلام کرد مشکل حل نشده است', 'تیکت ' . ticket_number($ticketId) . ' توسط ثبت‌کننده بازگشایی شد: ' . $reason, (int) $user['id']);
            db_commit('index.php');
            flash('info', 'تیکت دوباره برای رسیدگی ارسال شد.');
            redirect('index.php?page=ticket&id=' . $ticketId);
        }

        if ($action === 'reply') {
            if (!user_can($user, 'ticket.reply')) {
                throw new RuntimeException('دسترسی پاسخ به تیکت برای نقش شما مجاز نیست.');
            }
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = fetch_ticket($ticketId);
            if (!$ticket || !can_view_ticket($ticket, $user)) {
                throw new RuntimeException('تیکت پیدا نشد یا دسترسی ندارید.');
            }
            $isStaffUser = is_staff_role((string) $user['role']);
            $asRequester = (int) $ticket['requester_id'] === (int) $user['id'] && !$isStaffUser;
            if ((int) $ticket['requester_id'] === (int) $user['id'] && $ticket['status'] === 'closed' && !ticket_rating_is_current($ticket)) {
                throw new RuntimeException('ثبت امتیاز رضایت برای این تیکت الزامی است.');
            }
            if ($ticket['status'] === 'closed') {
                throw new RuntimeException('تیکت بسته‌شده قابل ویرایش نیست.');
            }
            if ($asRequester && $ticket['status'] === 'resolved') {
                throw new RuntimeException('این تیکت حل‌شده است. امتیاز خود را ثبت کنید، یا اگر مشکل برطرف نشده «مشکل حل نشده» را بزنید.');
            }
            $body = post_value('body');
            if ($body === '') {
                throw new RuntimeException('متن پاسخ را وارد کنید.');
            }
            $internal = !empty($_POST['is_internal']) && $isStaffUser ? 1 : 0;
            $oldStatus = (string) $ticket['status'];
            $newStatus = $oldStatus;
            if (!$internal) {
                if ($asRequester) {
                    // پاسخ کاربر فقط وقتی وضعیت را عوض می‌کند که تیکت منتظر او بوده است.
                    if ($oldStatus === 'waiting_user') {
                        $newStatus = !empty($ticket['assigned_to']) ? 'in_progress' : 'manager_review';
                    }
                } else {
                    if ($oldStatus === 'assigned') {
                        $newStatus = 'in_progress';
                    }
                    $afterStatus = valid_choice(post_value('after_status'), ['', 'waiting_user', 'resolved'], '');
                    if ($afterStatus !== '') {
                        if (ticket_handler_level($ticket, $user) === '') {
                            throw new RuntimeException('فقط کارشناس مسئول یا مدیر واحد می‌تواند وضعیت تیکت را تغییر دهد.');
                        }
                        if (empty($ticket['assigned_to'])) {
                            throw new RuntimeException('برای تغییر وضعیت، ابتدا تیکت باید به کارشناس ارجاع شود.');
                        }
                        if (!in_array($afterStatus, ticket_allowed_transitions($oldStatus), true)) {
                            throw new RuntimeException('تغییر وضعیت از «' . status_label($oldStatus) . '» به «' . status_label($afterStatus) . '» مجاز نیست.');
                        }
                        $newStatus = $afterStatus;
                    }
                }
            }
            $firstResponseSql = ($isStaffUser && !$internal) ? ', first_response_at = COALESCE(first_response_at, NOW())' : '';
            $resolvedSql = ($newStatus === 'resolved' && $oldStatus !== 'resolved') ? ', resolved_at = NOW()' : '';
            [$replyDueAt, $replyPauseAt, $replyPauseMinutes] = ticket_sla_transition($ticket, $newStatus, (string) $ticket['priority']);
            db()->beginTransaction();
            $query = db()->prepare('INSERT INTO ticket_messages (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, ?)');
            $query->execute([$ticketId, $user['id'], $body, $internal]);
            $messageId = (int) db()->lastInsertId();
            store_attachment($messageId, $_FILES['attachment'] ?? []);
            db()->prepare('UPDATE tickets SET status = ?, due_at = ?, sla_paused_at = ?, sla_pause_minutes = ?, updated_at = NOW()' . $firstResponseSql . $resolvedSql . ' WHERE id = ?')->execute([$newStatus, $replyDueAt, $replyPauseAt, $replyPauseMinutes, $ticketId]);
            save_audit((int) $user['id'], 'ticket_replied', $ticketId);
            if ($newStatus !== $oldStatus) {
                log_ticket_event($ticketId, (int) $user['id'], 'status_changed', $oldStatus, $newStatus);
            }
            log_ticket_event($ticketId, (int) $user['id'], $internal ? 'internal_note' : 'reply');
            if ($newStatus === 'resolved' && $oldStatus !== 'resolved') {
                notify_ticket_resolved($ticketId, (int) $user['id']);
                record_asset_history((int) ($ticket['asset_id'] ?? 0), 'ticket_resolved', 'حل مشکل تیکت ' . ticket_number($ticketId), (int) $user['id'], $ticketId, 'وضعیت به حل‌شده تغییر کرد.');
            } elseif ($internal) {
                notify_ticket_staff($ticketId, 'internal_note', 'یادداشت داخلی جدید', 'در تیکت ' . ticket_number($ticketId) . ' یادداشت داخلی ثبت شد.', (int) $user['id']);
            } elseif ($isStaffUser) {
                notify_user((int) $ticket['requester_id'], 'ticket_reply', 'پاسخ جدید به تیکت', 'برای تیکت ' . ticket_number($ticketId) . ' پاسخ جدید ثبت شد.', (int) $ticketId);
            } else {
                notify_ticket_staff($ticketId, 'ticket_reply', 'پاسخ جدید کاربر', 'کاربر در تیکت ' . ticket_number($ticketId) . ' پاسخ جدید ثبت کرد.', (int) $user['id']);
            }
            record_asset_history((int) ($ticket['asset_id'] ?? 0), $internal ? 'ticket_internal_note' : 'ticket_reply', $internal ? 'یادداشت داخلی تیکت ' . ticket_number($ticketId) : 'پاسخ جدید در تیکت ' . ticket_number($ticketId), (int) $user['id'], $ticketId, $body);
            db_commit('index.php');
            flash('success', 'پاسخ شما ثبت شد.');
            redirect('index.php?page=ticket&id=' . $ticketId);
        }

        if ($action === 'update_ticket') {
            $staffUser = require_login();
            if (!user_can($staffUser, 'ticket.edit')) {
                throw new RuntimeException('دسترسی ویرایش تیکت برای نقش شما مجاز نیست.');
            }
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $ticket = fetch_ticket($ticketId);
            if (!$ticket || !can_view_ticket($ticket, $staffUser)) {
                throw new RuntimeException('تیکت پیدا نشد یا به واحد شما تعلق ندارد.');
            }
            $handlerLevel = ticket_handler_level($ticket, $staffUser);
            if ($handlerLevel === '') {
                throw new RuntimeException('فقط مدیر واحد یا کارشناس مسئول همین تیکت می‌تواند آن را تغییر دهد.');
            }
            if ($ticket['status'] === 'closed') {
                throw new RuntimeException('تیکت بسته‌شده فقط از مسیر بازگشایی رسمی سوپروایزر قابل تغییر است.');
            }
            $oldStatus = (string) $ticket['status'];
            $status = valid_choice(post_value('status'), ['new','manager_review','assigned','in_progress','waiting_user','pending','resolved','closed'], $oldStatus);
            if ($status === 'closed') {
                throw new RuntimeException('بستن نهایی فقط از طریق تأیید سوپروایزر انجام می‌شود.');
            }
            $canAssign = $handlerLevel === 'manager';
            // کارشناس مسئول فقط وضعیت کار خودش را عوض می‌کند؛ ارجاع و اولویت با مدیر واحد است.
            $priority = $canAssign ? valid_choice(post_value('priority'), ['normal','urgent','critical'], (string) $ticket['priority']) : (string) $ticket['priority'];
            $assignee = $canAssign ? ((int) ($_POST['assigned_to'] ?? 0) ?: null) : ((int) ($ticket['assigned_to'] ?? 0) ?: null);
            if (!$canAssign && !in_array($status, ['in_progress', 'waiting_user', 'resolved', $oldStatus], true)) {
                throw new RuntimeException('این وضعیت فقط توسط مدیر واحد قابل تنظیم است.');
            }
            $assigneeChanged = (int) $assignee !== (int) ($ticket['assigned_to'] ?? 0);
            if ($assignee && $assigneeChanged) {
                 $assigneeQuery = db()->prepare('SELECT u.id, u.department_id, u.role, u.is_it_agent, hu.code AS handling_unit_code FROM users u LEFT JOIN handling_units hu ON hu.id = u.handling_unit_id WHERE u.id = ? AND u.is_active = 1 LIMIT 1');
                 $assigneeQuery->execute([$assignee]);
                 $assigneeRow = $assigneeQuery->fetch();
                 if (!$assigneeRow || ((int) $assigneeRow['is_it_agent'] !== 1 && !is_assignable_role($assigneeRow['role'])) || !is_assignable_role($assigneeRow['role']) || ($assigneeRow['role'] !== 'admin' && (string) ($assigneeRow['handling_unit_code'] ?? '') !== (string) ($ticket['service_group'] ?? 'it')) || (is_department_scoped_role($staffUser['role']) && user_service_group($staffUser) !== (string) ($ticket['service_group'] ?? 'it')) || (is_department_scoped_role($staffUser['role']) && user_service_group($staffUser) === 'support' && (int) $assigneeRow['department_id'] !== (int) $staffUser['department_id']) || (is_department_scoped_role($staffUser['role']) && user_service_group($staffUser) === 'support' && (int) $ticket['department_id'] !== (int) $staffUser['department_id'])) {
                    throw new RuntimeException('کارشناس انتخاب‌شده به این واحد تعلق ندارد.');
                }
            }
            if ($assignee && in_array($status, ['new', 'manager_review'], true)) {
                $status = 'assigned';
            }
            if (!$assignee) {
                if (in_array($status, ['assigned', 'in_progress', 'waiting_user'], true)) {
                    $status = 'manager_review';
                } elseif ($status === 'resolved') {
                    throw new RuntimeException('برای «حل‌شده» تیکت باید کارشناس مسئول داشته باشد.');
                }
            }
            if ($status !== $oldStatus && !in_array($status, ticket_allowed_transitions($oldStatus), true)) {
                throw new RuntimeException('تغییر وضعیت از «' . status_label($oldStatus) . '» به «' . status_label($status) . '» مجاز نیست.');
            }
            $fields = 'status = ?, priority = ?, assigned_to = ?, updated_at = NOW()';
            $params = [$status, $priority, $assignee];
            if ($assignee) {
                $fields .= $assigneeChanged ? ', assigned_at = NOW()' : ', assigned_at = COALESCE(assigned_at, NOW())';
            } else {
                $fields .= ', assigned_at = NULL';
            }
            if ($status === 'resolved') {
                $fields .= ', resolved_at = COALESCE(resolved_at, NOW())';
            } else {
                $fields .= ', resolved_at = NULL';
            }
             $slaMinutes = priority_sla_minutes($priority);
             [$newDueAt, $newPauseAt, $newPauseMinutes] = ticket_sla_transition($ticket, $status, $priority);
             $ola = ola_deadlines((int) ($ticket['department_id'] ?? 0), $priority, (string) $ticket['created_at']);
             $fields .= ', sla_minutes = ?, due_at = ?, ola_policy_id = ?, ola_response_due_at = ?, ola_due_at = ?, ola_escalation_1_at = ?, ola_escalation_2_at = ?, sla_paused_at = ?, sla_pause_minutes = ?';
             array_push($params, $slaMinutes, $newDueAt, $ola['policy_id'], $ola['response_due_at'], $ola['due_at'], $ola['escalation_1_at'], $ola['escalation_2_at'], $newPauseAt, $newPauseMinutes);
            $params[] = $ticketId;
            db()->beginTransaction();
            db()->prepare('UPDATE tickets SET ' . $fields . ' WHERE id = ?')->execute($params);
            save_audit((int) $staffUser['id'], 'ticket_updated', $ticketId, ['status' => $status, 'priority' => $priority]);
            if ($status !== $oldStatus) {
                log_ticket_event($ticketId, (int) $staffUser['id'], $assignee && $assigneeChanged ? 'assigned' : 'status_changed', $oldStatus, $status, ['assigned_to' => $assignee]);
                if ($status === 'resolved') {
                    record_asset_history((int) ($ticket['asset_id'] ?? 0), 'ticket_resolved', 'حل مشکل تیکت ' . ticket_number($ticketId), (int) $staffUser['id'], $ticketId, 'وضعیت به ' . status_label($status) . ' تغییر کرد.');
                }
            } elseif ($assigneeChanged) {
                log_ticket_event($ticketId, (int) $staffUser['id'], 'assigned', $oldStatus, $status, ['assigned_to' => $assignee]);
            }
            if ((string) $ticket['priority'] !== $priority) {
                log_ticket_event($ticketId, (int) $staffUser['id'], 'priority_changed', $status, $status, ['from' => (string) $ticket['priority'], 'to' => $priority]);
            }
            if ($status === 'resolved' && $oldStatus !== 'resolved') {
                notify_ticket_resolved($ticketId, (int) $staffUser['id']);
            } elseif ($status !== $oldStatus || $assigneeChanged) {
                notify_ticket_parties($ticketId, 'ticket_updated', 'تیکت به‌روزرسانی شد', 'وضعیت تیکت ' . ticket_number($ticketId) . ' به «' . status_label($status) . '» تغییر کرد.', (int) $staffUser['id']);
            }
            db_commit('index.php');
            flash('success', 'تغییرات تیکت ذخیره شد.');
            redirect('index.php?page=ticket&id=' . $ticketId);
        }

        if ($action === 'add_service') {
            $serviceAdmin = require_permission('services.manage');
            $name = post_value('service_name');
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', post_value('service_code')));
            $serviceGroup = valid_choice(post_value('service_group', 'it'), ['it', 'support'], 'it');
            $priority = valid_choice(post_value('default_priority', 'normal'), ['normal', 'urgent', 'critical'], 'normal');
            $defaultType = valid_choice(post_value('default_ticket_type', 'incident'), ['incident', 'request', 'problem', 'change'], 'incident');
            $handlingUnitId = (int) ($_POST['handling_unit_id'] ?? 0) ?: null;
            if ($handlingUnitId === null) {
                $unitQuery = db()->prepare('SELECT id FROM handling_units WHERE code = ? LIMIT 1');
                $unitQuery->execute([$serviceGroup]);
                $handlingUnitId = (int) ($unitQuery->fetchColumn() ?: 0) ?: null;
            } else {
                $unitCheck = db()->prepare('SELECT code FROM handling_units WHERE id = ? AND is_active = 1 LIMIT 1');
                $unitCheck->execute([$handlingUnitId]);
                if ((string) $unitCheck->fetchColumn() !== $serviceGroup) {
                    throw new RuntimeException('واحد رسیدگی باید با حوزه خدمت یکسان باشد.');
                }
            }
            if ($name === '' || $code === '') {
                throw new RuntimeException('نام و کد خدمت الزامی است.');
            }
            if ($serviceGroup === 'support') {
                $defaultType = in_array($defaultType, ['incident', 'request'], true) ? $defaultType : 'request';
            }
            db()->prepare('INSERT INTO service_catalog (name, code, description, service_group, default_priority, department_id, category_id, handling_unit_id, requires_asset, default_ticket_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$name, $code, post_value('service_description'), $serviceGroup, $priority, (int) ($_POST['department_id'] ?? 0) ?: null, (int) ($_POST['category_id'] ?? 0) ?: null, $handlingUnitId, $serviceGroup === 'it' ? (isset($_POST['requires_asset']) ? 1 : 0) : 0, $defaultType, $serviceAdmin['id']]);
            flash('success', 'خدمت جدید به کاتالوگ اضافه شد.');
            redirect('index.php?page=services#tab-list');
        }

        if ($action === 'add_service_field') {
            require_permission('service_field.manage');
            $serviceId = (int) ($_POST['service_id'] ?? 0);
            $fieldKey = strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '_', post_value('field_key')));
            $label = post_value('field_label');
            $fieldType = valid_choice(post_value('field_type', 'text'), ['text', 'textarea', 'number', 'select', 'date'], 'text');
            $options = array_values(array_filter(array_map('trim', preg_split('/\r?\n|,/', post_value('field_options')) ?: [])));
            if ($serviceId <= 0 || !preg_match('/^[a-z][a-z0-9_]{1,79}$/', $fieldKey) || $label === '') {
                throw new RuntimeException('خدمت، کلید انگلیسی و عنوان فیلد را کامل کنید.');
            }
            db()->prepare('INSERT INTO service_catalog_fields (service_id, field_key, label, field_type, options_json, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$serviceId, $fieldKey, $label, $fieldType, json_encode($options, JSON_UNESCAPED_UNICODE), !empty($_POST['field_required']) ? 1 : 0, max(0, (int) ($_POST['sort_order'] ?? 0))]);
            flash('success', 'فیلد خدمت اضافه شد.');
            redirect('index.php?page=services&tab=tab-list#tab-list');
        }

        if ($action === 'edit_service') {
            $serviceAdmin = require_permission('services.manage');
            $serviceId = (int) post_value('service_id', '0');
            if ($serviceId <= 0) {
                throw new RuntimeException('خدمت انتخابی معتبر نیست.');
            }
            $name = post_value('service_name');
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', post_value('service_code')));
            $serviceGroup = valid_choice(post_value('service_group', 'it'), ['it', 'support'], 'it');
            $priority = valid_choice(post_value('default_priority', 'normal'), ['normal', 'urgent', 'critical'], 'normal');
            $defaultType = valid_choice(post_value('default_ticket_type', 'incident'), ['incident', 'request', 'problem', 'change'], 'incident');
            if ($name === '' || $code === '') {
                throw new RuntimeException('نام و کد خدمت الزامی است.');
            }
            if ($serviceGroup === 'support') {
                $defaultType = in_array($defaultType, ['incident', 'request'], true) ? $defaultType : 'request';
            }
            $categoryId = (int) post_value('category_id', '0') ?: null;
            $handlingUnitId = (int) post_value('handling_unit_id', '0') ?: null;
            if ($handlingUnitId === null) {
                $unitQuery = db()->prepare('SELECT id FROM handling_units WHERE code = ? LIMIT 1');
                $unitQuery->execute([$serviceGroup]);
                $handlingUnitId = (int) ($unitQuery->fetchColumn() ?: 0) ?: null;
            }
            db()->prepare('UPDATE service_catalog SET name = ?, code = ?, description = ?, service_group = ?, default_priority = ?, department_id = ?, category_id = ?, handling_unit_id = ?, default_ticket_type = ?, requires_asset = ?, is_active = ? WHERE id = ?')
                ->execute([$name, $code, post_value('service_description'), $serviceGroup, $priority, (int) post_value('department_id', '0') ?: null, $categoryId, $handlingUnitId, $defaultType, post_value('requires_asset') === '1' ? 1 : 0, post_value('is_active') === '1' ? 1 : 0, $serviceId]);
            save_audit((int) $serviceAdmin['id'], 'service_updated', null, ['service_id' => $serviceId, 'name' => $name]);
            flash('success', 'خدمت ویرایش شد.');
            redirect('index.php?page=services#tab-list');
        }

        if ($action === 'delete_service') {
            $serviceAdmin = require_permission('services.manage');
            $serviceId = (int) post_value('service_id', '0');
            $existsQuery = db()->prepare('SELECT id FROM service_catalog WHERE id = ? LIMIT 1');
            $existsQuery->execute([$serviceId]);
            if ($serviceId <= 0 || !$existsQuery->fetchColumn()) {
                throw new RuntimeException('خدمت انتخابی معتبر نیست.');
            }
            db()->prepare('UPDATE service_catalog SET is_active = 0 WHERE id = ?')->execute([$serviceId]);
            save_audit((int) $serviceAdmin['id'], 'service_deleted', null, ['service_id' => $serviceId]);
            flash('success', 'خدمت از کاتالوگ حذف شد.');
            redirect('index.php?page=services#tab-list');
        }

        if ($action === 'save_service_category') {
            $serviceAdmin = require_permission('services.manage');
            $categoryId = (int) post_value('category_id', '0');
            $name = post_value('category_name');
            $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', post_value('category_code')));
            $serviceGroup = valid_choice(post_value('category_group', 'it'), ['it', 'support'], 'it');
            if ($name === '') {
                throw new RuntimeException('نام دسته‌بندی الزامی است.');
            }
            $isActive = isset($_POST['category_active']) ? 1 : 0;
            if ($categoryId > 0) {
                db()->prepare('UPDATE categories SET name = ?, code = ?, service_group = ?, is_active = ? WHERE id = ?')
                    ->execute([$name, $code !== '' ? $code : null, $serviceGroup, $isActive, $categoryId]);
            } else {
                db()->prepare('INSERT INTO categories (name, code, service_group, is_active) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $code !== '' ? $code : null, $serviceGroup, $isActive]);
            }
            save_audit((int) $serviceAdmin['id'], 'service_category_saved', null, ['category_id' => $categoryId, 'name' => $name]);
            flash('success', 'دسته‌بندی ذخیره شد.');
            redirect('index.php?page=services#tab-categories');
        }

        if ($action === 'delete_service_category') {
            $serviceAdmin = require_permission('services.manage');
            $categoryId = (int) post_value('category_id', '0');
            if ($categoryId <= 0) {
                throw new RuntimeException('دسته‌بندی انتخابی معتبر نیست.');
            }
            db()->prepare('UPDATE categories SET is_active = 0 WHERE id = ?')->execute([$categoryId]);
            save_audit((int) $serviceAdmin['id'], 'service_category_deleted', null, ['category_id' => $categoryId]);
            flash('success', 'دسته‌بندی غیرفعال شد.');
            redirect('index.php?page=services#tab-categories');
        }

        if ($action === 'add_knowledge') {
            $knowledgeUser = require_permission('knowledge.manage');
            $title = post_value('article_title');
            $body = post_value('article_body');
            if ($title === '' || $body === '') {
                throw new RuntimeException('عنوان و متن مقاله دانش‌نامه الزامی است.');
            }
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title));
            $slug = trim($slug, '-') . '-' . bin2hex(random_bytes(3));
            db()->prepare('INSERT INTO knowledge_articles (title, slug, body, category, is_published, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$title, $slug, $body, post_value('article_category'), !empty($_POST['is_published']) ? 1 : 0, $knowledgeUser['id']]);
            flash('success', 'مقاله دانش‌نامه ذخیره شد.');
            redirect('index.php?page=knowledge');
        }

        
        if ($action === 'toggle_holiday') {
            $holidayAdmin = require_permission('holidays.manage');
            $holidayDate = jalali_input_to_gregorian(post_value('holiday_date'));
            if ($holidayDate === null) {
                throw new RuntimeException('تاریخ تعطیل معتبر نیست.');
            }
            $day = substr($holidayDate, 0, 10);
            $dow = (int) (new DateTimeImmutable($day, new DateTimeZone((string) cfg('app.timezone', 'Asia/Tehran'))))->format('N');
            if (in_array($dow, [4, 5], true)) {
                flash('warning', 'پنج‌شنبه و جمعه به‌صورت ثابت تعطیل هستند.');
                redirect('index.php?page=holidays');
            }
            $exists = db()->prepare('SELECT id, is_active FROM holidays WHERE holiday_date = ? LIMIT 1');
            $exists->execute([$day]);
            $row = $exists->fetch();
            if ($row && (int) $row['is_active'] === 1) {
                db()->prepare('UPDATE holidays SET is_active = 0 WHERE id = ?')->execute([(int) $row['id']]);
                flash('success', 'تعطیلی حذف شد.');
            } elseif ($row) {
                db()->prepare('UPDATE holidays SET is_active = 1, title = ?, created_by = ? WHERE id = ?')->execute([post_value('holiday_title') ?: 'تعطیل رسمی', $holidayAdmin['id'], (int) $row['id']]);
                flash('success', 'تعطیلی فعال شد.');
            } else {
                db()->prepare('INSERT INTO holidays (holiday_date, title, created_by, is_active) VALUES (?, ?, ?, 1)')->execute([$day, post_value('holiday_title') ?: 'تعطیل رسمی', $holidayAdmin['id']]);
                flash('success', 'تعطیلی ثبت شد.');
            }
            redirect('index.php?page=holidays');
        }

        if ($action === 'add_holiday') {
            $holidayAdmin = require_permission('holidays.manage');
            $holidayDate = jalali_input_to_gregorian(post_value('holiday_date'));
            if ($holidayDate === null || post_value('holiday_title') === '') {
                throw new RuntimeException('تاریخ جلالی و عنوان تعطیلی را وارد کنید.');
            }
            $holidayDayOfWeek = (int) (new DateTimeImmutable(substr($holidayDate, 0, 10), new DateTimeZone((string) cfg('app.timezone', 'Asia/Tehran'))))->format('N');
            if (in_array($holidayDayOfWeek, [4, 5], true)) {
                throw new RuntimeException('پنج‌شنبه و جمعه به‌صورت خودکار تعطیل هستند و نیازی به ثبت جداگانه ندارند.');
            }
            db()->prepare('INSERT INTO holidays (holiday_date, title, created_by) VALUES (?, ?, ?)')->execute([substr($holidayDate, 0, 10), post_value('holiday_title'), $holidayAdmin['id']]);
            flash('success', 'تعطیلی به تقویم SLA اضافه شد.');
            redirect('index.php?page=holidays');
        }

        if ($action === 'import_holidays') {
            $holidayAdmin = require_permission('holidays.manage');
            $events = parse_calendar_import($_FILES['calendar_file'] ?? []);
            $insert = db()->prepare('INSERT INTO holidays (holiday_date, title, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), is_active = 1, created_by = VALUES(created_by)');
            db()->beginTransaction();
            $importedCount = 0;
            foreach ($events as $event) {
                $eventDayOfWeek = (int) (new DateTimeImmutable(substr($event['date'], 0, 10), new DateTimeZone((string) cfg('app.timezone', 'Asia/Tehran'))))->format('N');
                if (in_array($eventDayOfWeek, [4, 5], true)) {
                    continue;
                }
                $insert->execute([substr($event['date'], 0, 10), $event['title'], $holidayAdmin['id']]);
                $importedCount++;
            }
            db_commit('index.php');
            flash('success', $importedCount . ' تعطیلی از فایل تقویم وارد شد؛ پنج‌شنبه و جمعه نادیده گرفته شدند.');
            redirect('index.php?page=holidays');
        }

        if ($action === 'delete_holiday') {
            require_permission('holidays.manage');
            db()->prepare('DELETE FROM holidays WHERE id = ?')->execute([(int) ($_POST['holiday_id'] ?? 0)]);
            flash('success', 'تعطیلی حذف شد.');
            redirect('index.php?page=holidays');
        }

        if ($action === 'add_asset_relation') {
            $relationUser = require_permission('ticket.attach_asset');
            $sourceId = (int) ($_POST['source_asset_id'] ?? 0);
            $targetId = (int) ($_POST['target_asset_id'] ?? 0);
            $relationType = valid_choice(post_value('relation_type', 'related_to'), ['depends_on', 'connected_to', 'replaces', 'located_with', 'related_to'], 'related_to');
            [$relationScope, $relationParams] = asset_scope($relationUser);
            if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
                throw new RuntimeException('دو دارایی متفاوت را انتخاب کنید.');
            }
            $assetCheck = db()->prepare('SELECT COUNT(*) FROM assets a ' . ($relationScope ? $relationScope . ' AND a.id IN (?, ?)' : 'WHERE a.id IN (?, ?)'));
            $assetCheck->execute(array_merge($relationParams, [$sourceId, $targetId]));
            if ((int) $assetCheck->fetchColumn() !== 2) {
                throw new RuntimeException('به یکی از دارایی‌ها دسترسی ندارید.');
            }
            db()->prepare('INSERT INTO asset_relations (source_asset_id, target_asset_id, relation_type, created_by) VALUES (?, ?, ?, ?)')->execute([$sourceId, $targetId, $relationType, $relationUser['id']]);
            record_asset_history($sourceId, 'asset_relation_added', 'ارتباط CMDB اضافه شد', (int) $relationUser['id'], null, $relationType . ' -> asset #' . $targetId);
            flash('success', 'ارتباط بین دو دارایی ثبت شد.');
            redirect('index.php?page=inventory&id=' . $sourceId);
        }

        if ($action === 'bulk_update_tickets') {
            $queueUser = require_permission('queue.view');
            if (!user_can($queueUser, 'ticket.bulk')) {
                throw new RuntimeException('عملیات گروهی روی تیکت‌ها برای نقش شما فعال نیست.');
            }
            $status = valid_choice(post_value('bulk_status'), ['in_progress', 'waiting_user', 'resolved'], 'in_progress');
            $ticketIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ticket_ids'] ?? [])))));
            if (!$ticketIds) {
                throw new RuntimeException('حداقل یک تیکت را انتخاب کنید.');
            }
            $bulkChanged = 0;
            $bulkSkipped = 0;
            db()->beginTransaction();
            foreach ($ticketIds as $ticketId) {
                $bulkTicket = fetch_ticket($ticketId);
                if (!$bulkTicket || !can_view_ticket($bulkTicket, $queueUser)) {
                    $bulkSkipped++;
                    continue;
                }
                $bulkOld = (string) $bulkTicket['status'];
                if ($bulkOld === $status) {
                    continue;
                }
                // فقط کارشناس مسئول یا مدیر واحد، فقط روی تیکت دارای کارشناس و فقط در مسیر مجاز وضعیت.
                if (ticket_handler_level($bulkTicket, $queueUser) === '' || empty($bulkTicket['assigned_to']) || !in_array($status, ticket_allowed_transitions($bulkOld), true)) {
                    $bulkSkipped++;
                    continue;
                }
                [$bulkDue, $bulkPauseAt, $bulkPauseMinutes] = ticket_sla_transition($bulkTicket, $status, (string) $bulkTicket['priority']);
                $resolvedAt = $status === 'resolved' ? 'COALESCE(resolved_at, NOW())' : 'NULL';
                db()->prepare('UPDATE tickets SET status = ?, due_at = ?, sla_paused_at = ?, sla_pause_minutes = ?, resolved_at = ' . $resolvedAt . ', updated_at = NOW() WHERE id = ?')->execute([$status, $bulkDue, $bulkPauseAt, $bulkPauseMinutes, $ticketId]);
                log_ticket_event($ticketId, (int) $queueUser['id'], 'bulk_status_changed', $bulkOld, $status);
                save_audit((int) $queueUser['id'], 'ticket_updated', $ticketId, ['status' => $status, 'bulk' => true]);
                if ($status === 'resolved') {
                    notify_ticket_resolved($ticketId, (int) $queueUser['id']);
                    record_asset_history((int) ($bulkTicket['asset_id'] ?? 0), 'ticket_resolved', 'حل مشکل تیکت ' . ticket_number($ticketId), (int) $queueUser['id'], $ticketId, 'وضعیت به حل‌شده تغییر کرد.');
                } else {
                    notify_ticket_parties($ticketId, 'bulk_status_changed', 'تغییر وضعیت تیکت', 'وضعیت تیکت ' . ticket_number($ticketId) . ' در عملیات گروهی به «' . status_label($status) . '» تغییر کرد.', (int) $queueUser['id']);
                }
                $bulkChanged++;
            }
            db_commit('index.php');
            $bulkMessage = $bulkChanged . ' تیکت به‌روزرسانی شد.';
            if ($bulkSkipped > 0) {
                $bulkMessage .= ' ' . $bulkSkipped . ' تیکت (بدون کارشناس مسئول، خارج از اختیار شما یا مسیر وضعیت نامعتبر) تغییر نکرد.';
            }
            flash($bulkChanged > 0 ? 'success' : 'warning', $bulkMessage);
            redirect('index.php?page=queue');
        }

        if ($action === 'add_category') {
            require_permission('service_cat.manage');
            $name = post_value('category_name');
            $serviceGroup = valid_choice(post_value('service_group', 'it'), ['it', 'support'], 'it');
            if ($name !== '') {
                db()->prepare('INSERT INTO categories (name, service_group) VALUES (?, ?)')->execute([$name, $serviceGroup]);
                flash('success', 'دسته‌بندی اضافه شد.');
            }
            redirect('index.php?page=settings');
        }

        if ($action === 'add_department') {
            require_primary_admin();
            $name = post_value('department_name');
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', post_value('department_code')) ?? '');
            if ($name === '') {
                throw new RuntimeException('نام واحد را وارد کنید.');
            }
            db()->prepare('INSERT INTO departments (name, code) VALUES (?, ?)')->execute([$name, $code !== '' ? $code : null]);
            flash('success', 'واحد سازمانی اضافه شد.');
            redirect('index.php?page=' . valid_choice(post_value('return_page'), ['settings', 'organization'], 'settings'));
        }

        if ($action === 'update_user_role') {
            $admin = require_primary_admin();
            $userId = (int) ($_POST['user_id'] ?? 0);
            $role = valid_choice(post_value('role'), array_keys(permission_roles()), 'user');
            $isActive = null;
            $employeeNumber = post_value('employee_number');
            if (strlen($employeeNumber) > 100) {
                throw new RuntimeException('کد پرسنلی بیش از حد طولانی است.');
            }
            $nationalCode = post_value('national_code');
            if ($nationalCode !== '' && !preg_match('/^\d{6,20}$/', $nationalCode)) {
                throw new RuntimeException('کد ملی باید فقط شامل ۶ تا ۲۰ رقم باشد.');
            }
            $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
            $handlingUnitId = (int) ($_POST['handling_unit_id'] ?? 0) ?: null;
            // فقط نقش‌هایی که واقعاً به تیکت رسیدگی می‌کنند نیاز به واحد رسیدگی/سازمانی دارند.
            // نقش‌هایی مثل بازرس (inspector) یا سوپروایزر نباید ذخیره‌شان به این اجبار وابسته باشد.
            $needsHandlingUnit = in_array($role, ['agent', 'manager'], true);
            $handlingUnitCode = '';
            if (!$handlingUnitId && $needsHandlingUnit) {
                $defaultUnitQuery = db()->prepare('SELECT id, code FROM handling_units WHERE code = ? LIMIT 1');
                $defaultUnitQuery->execute([!empty($_POST['is_it_agent']) ? 'it' : 'support']);
                $defaultUnit = $defaultUnitQuery->fetch() ?: null;
                $handlingUnitId = $defaultUnit ? (int) $defaultUnit['id'] : null;
                $handlingUnitCode = $defaultUnit['code'] ?? '';
            }
            if ($handlingUnitId) {
                $unitQuery = db()->prepare('SELECT code FROM handling_units WHERE id = ? AND is_active = 1 LIMIT 1');
                $unitQuery->execute([$handlingUnitId]);
                $handlingUnitCode = (string) ($unitQuery->fetchColumn() ?: '');
            }
            $isItAgent = $handlingUnitCode === 'it' ? 1 : 0;
            if ($needsHandlingUnit && !$handlingUnitId) {
                throw new RuntimeException('برای کارشناس یا مدیر، واحد رسیدگی IT یا پشتیبانی را انتخاب کنید.');
            }
            $isPrimaryAdmin = $role === 'primary_admin' ? 1 : ($role === 'admin' && !empty($_POST['is_primary_admin']) ? 1 : 0);
            if ($userId === (int) $admin['id'] && !in_array($role, ['primary_admin'], true)) {
                throw new RuntimeException('ادمین اصلی فعلی نمی‌تواند خودش را از مدیریت اصلی خارج کند.');
            }
            if ($needsHandlingUnit && !$departmentId) {
                throw new RuntimeException('برای کارشناس یا مدیر، واحد سازمانی را انتخاب کنید.');
            }
            if (!in_array($role, ['primary_admin'], true) && $isPrimaryAdmin) {
                throw new RuntimeException('فقط مدیر سامانه می‌تواند ادمین اصلی باشد.');
            }
            $targetQuery = db()->prepare('SELECT role, is_primary_admin, is_active, department_id FROM users WHERE id = ? LIMIT 1');
            $targetQuery->execute([$userId]);
            $target = $targetQuery->fetch();
            if (!$target) {
                throw new RuntimeException('کاربر پیدا نشد.');
            }
            $isActive = array_key_exists('is_active', $_POST) ? (!empty($_POST['is_active']) ? 1 : 0) : (int) $target['is_active'];
            if ((int) $target['is_primary_admin'] === 1 && (!$isPrimaryAdmin || !in_array($role, ['primary_admin'], true))) {
                $primaryCount = (int) db()->query('SELECT COUNT(*) FROM users WHERE (role = "admin" OR role = "primary_admin") AND is_primary_admin = 1 AND is_active = 1')->fetchColumn();
                if ($primaryCount <= 1) {
                    throw new RuntimeException('حداقل یک ادمین اصلی باید باقی بماند.');
                }
            }
            if ($userId === (int) $admin['id'] && !$isActive) {
                throw new RuntimeException('ادمین اصلی فعلی نمی‌تواند غیرفعال شود.');
            }
            if ((int) $target['is_primary_admin'] === 1 && !$isActive) {
                $primaryCount = (int) db()->query('SELECT COUNT(*) FROM users WHERE (role = "admin" OR role = "primary_admin") AND is_primary_admin = 1 AND is_active = 1')->fetchColumn();
                if ($primaryCount <= 1) {
                    throw new RuntimeException('حداقل یک ادمین اصلی فعال باید باقی بماند.');
                }
            }
            $employeeNumberValue = normalize_employee_number($employeeNumber);
            if ($employeeNumberValue !== null && employee_number_owner($employeeNumberValue, $userId) > 0) {
                throw new RuntimeException('این کد پرسنلی قبلاً به کاربر دیگری داده شده است. کد تکراری مجاز نیست.');
            }
            db()->prepare('UPDATE users SET role = ?, employee_number = ?, national_code = ?, department_id = ?, handling_unit_id = ?, is_it_agent = ?, is_primary_admin = ?, is_active = ? WHERE id = ?')->execute([$role, $employeeNumberValue, $nationalCode !== '' ? $nationalCode : null, $departmentId, $handlingUnitId, $isItAgent, $isPrimaryAdmin, $isActive, $userId]);
            if ($role === 'manager' && $departmentId) {
                db()->prepare('UPDATE departments SET manager_user_id = NULL WHERE manager_user_id = ? AND id <> ?')->execute([$userId, $departmentId]);
                db()->prepare('UPDATE departments SET manager_user_id = ? WHERE id = ?')->execute([$userId, $departmentId]);
            } else {
                db()->prepare('UPDATE departments SET manager_user_id = NULL WHERE manager_user_id = ?')->execute([$userId]);
            }
            if ($role === 'manager' && $handlingUnitId) {
                db()->prepare('UPDATE handling_units SET manager_user_id = NULL WHERE manager_user_id = ? AND id <> ?')->execute([$userId, $handlingUnitId]);
                db()->prepare('UPDATE handling_units SET manager_user_id = ? WHERE id = ?')->execute([$userId, $handlingUnitId]);
            } else {
                db()->prepare('UPDATE handling_units SET manager_user_id = NULL WHERE manager_user_id = ?')->execute([$userId]);
            }
            flash('success', 'نقش و دسترسی کاربر به‌روزرسانی شد.');
            redirect('index.php?page=' . (post_value('return_page') === 'organization' ? 'organization' : 'settings#users'));
        }

        if ($action === 'save_department_members') {
            require_primary_admin();
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $managerId = (int) ($_POST['manager_user_id'] ?? 0);
            $memberIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['member_ids'] ?? [])))));
            if ($departmentId <= 0 || $managerId <= 0) {
                throw new RuntimeException('معاونت و مدیر معاونت را انتخاب کنید.');
            }
            if (!in_array($managerId, $memberIds, true)) {
                $memberIds[] = $managerId;
            }
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $memberQuery = db()->prepare('SELECT id, role FROM users WHERE id IN (' . $placeholders . ') AND is_active = 1');
            $memberQuery->execute($memberIds);
            $members = $memberQuery->fetchAll();
            if (count($members) !== count($memberIds)) {
                throw new RuntimeException('یکی از کاربران انتخاب‌شده فعال نیست.');
            }
            db()->beginTransaction();
            $clear = db()->prepare('UPDATE users SET department_id = NULL WHERE department_id = ? AND id NOT IN (' . $placeholders . ')');
            $clear->execute(array_merge([$departmentId], $memberIds));
            $assign = db()->prepare('UPDATE users SET department_id = ? WHERE id IN (' . $placeholders . ')');
            $assign->execute(array_merge([$departmentId], $memberIds));
            $clearOldManagers = db()->prepare('UPDATE departments SET manager_user_id = NULL WHERE manager_user_id = ? AND id <> ?');
            foreach ($memberIds as $memberId) {
                $clearOldManagers->execute([$memberId, $departmentId]);
            }
            db()->prepare('UPDATE users SET role = "manager" WHERE id = ? AND role NOT IN ("admin", "supervisor")')->execute([$managerId]);
            db()->prepare('UPDATE departments SET manager_user_id = ? WHERE id = ?')->execute([$managerId, $departmentId]);
            db_commit('index.php');
            flash('success', 'مدیر و اعضای معاونت ذخیره شدند.');
            redirect('index.php?page=' . valid_choice(post_value('return_page'), ['settings#departments', 'organization'], 'settings#departments'));
        }

        if ($action === 'save_cd_dvd_settings') {
            $settingsAdmin = require_permission('settings.users_edit');
            $inspectionUserId = (int) ($_POST['cd_dvd_inspection_user_id'] ?? 0);
            if ($inspectionUserId > 0) {
                $inspectionQuery = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $inspectionQuery->execute([$inspectionUserId]);
                if (!$inspectionQuery->fetchColumn()) {
                    throw new RuntimeException('کاربر بازرسی انتخاب‌شده فعال نیست.');
                }
            }
            save_setting('cd_dvd_inspection_user_id', (string) $inspectionUserId);
            save_audit((int) $settingsAdmin['id'], 'cd_dvd_inspection_user_changed', null, ['user_id' => $inspectionUserId]);
            flash('success', $inspectionUserId > 0 ? 'کاربر بازرسی CD/DVD تعیین شد.' : 'کاربر بازرسی CD/DVD حذف شد.');
            redirect('index.php?page=settings#cd-dvd-settings');
        }

        if ($action === 'save_org_node') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند ساختار سازمانی را تغییر دهد.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $nodeType = valid_choice(post_value('node_type'), ['ceo', 'deputy', 'department', 'member'], 'department');
            $ceoId = org_ceo_unit_id();

            if ($nodeType === 'ceo') {
                $ceoUserId = max(0, (int) post_value('ceo_user_id', '0'));
                if ($ceoUserId > 0) {
                    $check = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                    $check->execute([$ceoUserId]);
                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('کاربر مدیرعامل معتبر نیست.');
                    }
                }
                db()->prepare('UPDATE org_units SET manager_user_id = ? WHERE id = ?')->execute([$ceoUserId > 0 ? $ceoUserId : null, $ceoId]);
                db()->prepare('DELETE FROM org_unit_managers WHERE unit_id = ?')->execute([$ceoId]);
                if ($ceoUserId > 0) {
                    db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, 1)')->execute([$ceoId, $ceoUserId]);
                    db()->prepare('UPDATE users SET role = "manager" WHERE id = ? AND role IN ("user", "agent")')->execute([$ceoUserId]);
                }
                org_record_undo('تغییر مدیرعامل', $orgUndoSnapshot);
                save_audit((int) $orgAdmin['id'], 'org_ceo_saved', null, ['user_id' => $ceoUserId]);
                flash('success', 'مدیرعامل ذخیره شد.');
                redirect('index.php?page=organization#unit-' . $ceoId);
            }

            if ($nodeType === 'member') {
                $targetUserId = max(0, (int) post_value('user_id', '0'));
                $unitId = max(0, (int) post_value('member_unit_id', '0'));
                $managerId = max(0, (int) post_value('member_manager_id', '0'));
                $target = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                $target->execute([$targetUserId]);
                if (!$target->fetchColumn()) {
                    throw new RuntimeException('کاربر یافت نشد.');
                }
                if ($unitId > 0 && org_unit_by_id($unitId) === null) {
                    throw new RuntimeException('اداره انتخابی یافت نشد.');
                }
                if ($managerId === 0 && $unitId > 0) {
                    $memberUnit = org_unit_by_id($unitId);
                    $managerId = (int) ($memberUnit['manager_user_id'] ?? 0);
                }
                if ($managerId === $targetUserId) {
                    $managerId = 0;
                }
                db()->prepare('UPDATE users SET org_unit_id = ?, manager_user_id = ? WHERE id = ?')
                    ->execute([$unitId > 0 ? $unitId : null, $managerId > 0 ? $managerId : null, $targetUserId]);
                org_record_undo('تغییر عضویت سازمانی کاربر', $orgUndoSnapshot);
                save_audit((int) $orgAdmin['id'], 'user_org_updated', null, ['user_id' => $targetUserId, 'unit_id' => $unitId, 'manager_user_id' => $managerId]);
                flash('success', $unitId > 0 ? 'کارشناس به اداره وصل شد.' : 'کارشناس از اداره جدا شد.');
                redirect('index.php?page=organization#user-' . $targetUserId);
            }

            $unitId = max(0, (int) post_value('unit_id', '0'));
            $name = trim((string) post_value('unit_name', ''));
            $code = trim((string) post_value('unit_code', ''));
            if ($name === '') {
                throw new RuntimeException('نام واحد الزامی است.');
            }
            if ($nodeType === 'deputy') {
                $parentId = $ceoId;
            } else {
                $parentId = max(0, (int) post_value('parent_id', '0'));
                $parentUnit = $parentId > 0 ? org_unit_by_id($parentId) : null;
                if ($parentUnit === null || !in_array((string) $parentUnit['unit_type'], ['deputy', 'ceo'], true)) {
                    throw new RuntimeException('اداره باید زیر یک معاونت تعریف شود.');
                }
            }
            $managerId = max(0, (int) post_value('unit_manager_id', '0'));
            if ($managerId > 0) {
                $check = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $check->execute([$managerId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('مدیر انتخابی معتبر نیست.');
                }
            }
            $isPrimary = isset($_POST['is_primary_admin']) ? 1 : 0;
            if ($unitId > 0) {
                $existing = org_unit_by_id($unitId);
                if ($existing === null) {
                    throw new RuntimeException('واحد سازمانی یافت نشد.');
                }
                if ((string) $existing['unit_type'] === 'ceo') {
                    throw new RuntimeException('مدیرعامل از طریق نوع «مدیرعامل» تغییر می‌کند.');
                }
                if ($parentId === $unitId) {
                    throw new RuntimeException('واحد نمی‌تواند والد خودش باشد.');
                }
                db()->prepare('UPDATE org_units SET name = ?, code = ?, parent_id = ?, unit_type = ?, manager_user_id = ?, is_primary_admin = ? WHERE id = ?')
                    ->execute([$name, $code !== '' ? $code : null, $parentId > 0 ? $parentId : null, $nodeType, $managerId > 0 ? $managerId : null, $isPrimary, $unitId]);
            } else {
                db()->prepare('INSERT INTO org_units (name, code, parent_id, unit_type, manager_user_id, is_primary_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
                    ->execute([$name, $code !== '' ? $code : null, $parentId > 0 ? $parentId : null, $nodeType, $managerId > 0 ? $managerId : null, $isPrimary]);
                $unitId = (int) db()->lastInsertId();
            }
            db()->prepare('DELETE FROM org_unit_managers WHERE unit_id = ?')->execute([$unitId]);
            if ($managerId > 0) {
                db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, 1)')->execute([$unitId, $managerId]);
                db()->prepare('UPDATE users SET role = "manager", org_unit_id = ? WHERE id = ? AND role IN ("user", "agent")')->execute([$unitId, $managerId]);
            }
            if ($isPrimary === 1 && $managerId > 0) {
                db()->prepare('UPDATE users SET role = "primary_admin", is_primary_admin = 1, is_active = 1 WHERE id = ?')->execute([$managerId]);
            }
            org_record_undo($unitId > 0 ? 'ویرایش واحد سازمانی' : 'افزودن واحد سازمانی', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_unit_saved', null, ['unit_id' => $unitId, 'name' => $name, 'type' => $nodeType, 'manager' => $managerId]);
            flash('success', $nodeType === 'deputy' ? 'معاونت ذخیره شد.' : 'اداره ذخیره شد.');
            redirect('index.php?page=organization#unit-' . $unitId);
        }

        if ($action === 'undo_org_action') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند عملیات سازمان را بازگرداند.');
            }
            org_ensure_schema();
            $undo = org_undo_state();
            if ($undo === null) {
                throw new RuntimeException('عملیاتی برای بازگشت وجود ندارد.');
            }
            org_restore_snapshot($undo['snapshot']);
            unset($_SESSION['org_undo']);
            save_audit((int) $orgAdmin['id'], 'org_action_undone', null, ['label' => (string) ($undo['label'] ?? '')]);
            flash('success', 'آخرین عملیات سازمان بازگردانده شد.');
            redirect('index.php?page=organization');
        }

        if ($action === 'assign_org_manager') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند مدیران سازمان را تغییر دهد.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $targetType = valid_choice(post_value('target_type'), ['ceo', 'deputy', 'department'], 'department');
            $targetId = max(0, (int) post_value('unit_id', '0'));
            $target = $targetType === 'ceo' ? org_unit_by_id(org_ceo_unit_id()) : org_unit_by_id($targetId);
            if ($target === null || (int) $target['is_active'] !== 1 || (string) $target['unit_type'] !== $targetType) {
                throw new RuntimeException('موقعیت سازمانی انتخاب‌شده معتبر نیست.');
            }
            $userId = max(0, (int) post_value('user_id', '0'));
            $userQuery = db()->prepare('SELECT id, full_name FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $userQuery->execute([$userId]);
            $managerUser = $userQuery->fetch();
            if (!$managerUser) {
                throw new RuntimeException('کاربر انتخاب‌شده فعال نیست.');
            }
            $oldTargetManagerId = (int) ($target['manager_user_id'] ?? 0);
            db()->beginTransaction();
            if ($oldTargetManagerId > 0 && $oldTargetManagerId !== $userId) {
                db()->prepare('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL WHERE id = ? AND org_unit_id = ?')->execute([$oldTargetManagerId, (int) $target['id']]);
            }
            db()->prepare('UPDATE org_units SET manager_user_id = NULL WHERE manager_user_id = ?')->execute([$userId]);
            db()->prepare('DELETE FROM org_unit_managers WHERE user_id = ?')->execute([$userId]);
            db()->prepare('UPDATE org_units SET manager_user_id = ? WHERE id = ?')->execute([$userId, (int) $target['id']]);
            db()->prepare('DELETE FROM org_unit_managers WHERE unit_id = ?')->execute([(int) $target['id']]);
            db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, 1)')->execute([(int) $target['id'], $userId]);
            if ($targetType === 'ceo') {
                db()->prepare('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL, role = CASE WHEN role IN ("user", "agent") THEN "manager" ELSE role END WHERE id = ?')->execute([$userId]);
            } else {
                db()->prepare('UPDATE users SET org_unit_id = ?, manager_user_id = NULL, role = CASE WHEN role IN ("user", "agent") THEN "manager" ELSE role END WHERE id = ?')->execute([(int) $target['id'], $userId]);
            }
            if ((int) ($target['is_primary_admin'] ?? 0) === 1) {
                db()->prepare('UPDATE users SET role = "primary_admin", is_primary_admin = 1, is_active = 1 WHERE id = ?')->execute([$userId]);
            }
            db_commit('index.php');
            org_record_undo('انتصاب مدیر سازمان', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_manager_assigned', null, ['unit_id' => (int) $target['id'], 'target_type' => $targetType, 'user_id' => $userId]);
            flash('success', '«' . (string) $managerUser['full_name'] . '» برای «' . (string) $target['name'] . '» منصوب شد.');
            redirect('index.php?page=organization#unit-' . (int) $target['id']);
        }

        if ($action === 'remove_org_manager') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند مدیران سازمان را عزل کند.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $targetType = valid_choice(post_value('target_type'), ['ceo', 'deputy', 'department'], 'department');
            $targetId = max(0, (int) post_value('unit_id', '0'));
            $target = $targetType === 'ceo' ? org_unit_by_id(org_ceo_unit_id()) : org_unit_by_id($targetId);
            if ($target === null || (int) $target['is_active'] !== 1 || (string) $target['unit_type'] !== $targetType) {
                throw new RuntimeException('موقعیت سازمانی انتخاب‌شده معتبر نیست.');
            }
            $managerId = (int) ($target['manager_user_id'] ?? 0);
            db()->beginTransaction();
            db()->prepare('UPDATE org_units SET manager_user_id = NULL WHERE id = ?')->execute([(int) $target['id']]);
            db()->prepare('DELETE FROM org_unit_managers WHERE unit_id = ?')->execute([(int) $target['id']]);
            if ($managerId > 0) {
                db()->prepare('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL WHERE id = ? AND org_unit_id = ?')->execute([$managerId, (int) $target['id']]);
            }
            db_commit('index.php');
            org_record_undo('عزل مدیر سازمان', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_manager_removed', null, ['unit_id' => (int) $target['id'], 'target_type' => $targetType, 'user_id' => $managerId]);
            flash('success', 'مدیر «' . (string) $target['name'] . '» عزل شد.');
            redirect('index.php?page=organization#unit-' . (int) $target['id']);
        }

        if ($action === 'remove_org_experts') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند کارشناسان را عزل کند.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $unitId = max(0, (int) post_value('unit_id', '0'));
            $unit = $unitId > 0 ? org_unit_by_id($unitId) : null;
            if ($unit === null || (string) $unit['unit_type'] !== 'department' || (int) $unit['is_active'] !== 1) {
                throw new RuntimeException('اداره انتخابی معتبر نیست.');
            }
            $userIds = $_POST['user_ids'] ?? [];
            $userIds = is_array($userIds) ? array_values(array_unique(array_filter(array_map('intval', $userIds)))) : [];
            if ($userIds === []) {
                throw new RuntimeException('کارشناسی برای عزل انتخاب نشده است.');
            }
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $params = array_merge([$unitId], $userIds);
            $query = db()->prepare('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL WHERE org_unit_id = ? AND id IN (' . $placeholders . ')');
            $query->execute($params);
            org_record_undo('عزل کارشناسان', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_experts_removed', null, ['unit_id' => $unitId, 'user_ids' => $userIds]);
            flash('success', 'کارشناسان انتخاب‌شده از اداره عزل شدند.');
            redirect('index.php?page=organization#unit-' . $unitId);
        }

        if ($action === 'save_org_assign_users') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند اعضای سازمان را تغییر دهد.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $unitId = max(0, (int) post_value('member_unit_id', '0'));
            $unit = $unitId > 0 ? org_unit_by_id($unitId) : null;
            if ($unit === null || (string) $unit['unit_type'] !== 'department' || (int) $unit['is_active'] !== 1) {
                throw new RuntimeException('اداره انتخابی معتبر نیست.');
            }
            $userIds = $_POST['user_ids'] ?? [];
            $userIds = is_array($userIds) ? array_values(array_unique(array_filter(array_map('intval', $userIds)))) : [];
            if ($userIds === []) {
                throw new RuntimeException('حداقل یک کارشناس انتخاب کنید.');
            }
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $validUsers = db()->prepare('SELECT id FROM users WHERE id IN (' . $placeholders . ') AND is_active = 1');
            $validUsers->execute($userIds);
            $validIds = array_map('intval', $validUsers->fetchAll(PDO::FETCH_COLUMN));
            if (count($validIds) !== count($userIds)) {
                throw new RuntimeException('یکی از کاربران انتخاب‌شده فعال نیست.');
            }
            $managerId = (int) ($unit['manager_user_id'] ?? 0);
            db()->beginTransaction();
            $assign = db()->prepare('UPDATE users SET org_unit_id = ?, manager_user_id = ? WHERE id = ?');
            foreach ($validIds as $userId) {
                $assign->execute([$unitId, $managerId > 0 && $managerId !== $userId ? $managerId : null, $userId]);
            }
            db_commit('index.php');
            org_record_undo('افزودن کارشناسان به اداره', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_users_assigned', null, ['unit_id' => $unitId, 'user_ids' => $validIds]);
            flash('success', count($validIds) . ' کارشناس به «' . (string) $unit['name'] . '» اضافه شد.');
            redirect('index.php?page=organization#unit-' . $unitId);
        }

        if ($action === 'reset_org_structure') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند ساختار سازمانی را پاک کند.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $ceoId = org_ceo_unit_id();
            db()->beginTransaction();
            db()->exec('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL');
            db()->exec('DELETE FROM org_unit_managers');
            db()->prepare('UPDATE org_units SET manager_user_id = NULL, is_active = 1 WHERE id = ?')->execute([$ceoId]);
            db()->prepare('UPDATE org_units SET is_active = 0 WHERE id <> ?')->execute([$ceoId]);
            db_commit('index.php');
            org_record_undo('ریست ساختار سازمانی', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_structure_reset');
            flash('success', 'ساختار سازمانی پاک شد.');
            redirect('index.php?page=organization');
        }

        if ($action === 'save_org_ceo') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند مدیرعامل را تعیین کند.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $ceoUserId = max(0, (int) post_value('ceo_user_id', '0'));
            if ($ceoUserId > 0) {
                $ceoCheck = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $ceoCheck->execute([$ceoUserId]);
                if (!$ceoCheck->fetchColumn()) {
                    throw new RuntimeException('کاربر مدیرعامل معتبر نیست.');
                }
            }
            $ceoId = org_ceo_unit_id();
            db()->prepare('UPDATE org_units SET manager_user_id = ? WHERE id = ?')->execute([$ceoUserId > 0 ? $ceoUserId : null, $ceoId]);
            db()->prepare('DELETE FROM org_unit_managers WHERE unit_id = ?')->execute([$ceoId]);
            if ($ceoUserId > 0) {
                db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, 1)')->execute([$ceoId, $ceoUserId]);
                db()->prepare('UPDATE users SET role = "manager" WHERE id = ? AND role IN ("user", "agent")')->execute([$ceoUserId]);
            }
            org_record_undo('تغییر مدیرعامل', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_ceo_saved', null, ['user_id' => $ceoUserId]);
            flash('success', $ceoUserId > 0 ? 'مدیرعامل تعیین شد.' : 'مدیرعامل حذف شد.');
            redirect('index.php?page=organization#unit-' . $ceoId);
        }

        if ($action === 'save_org_unit') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند ساختار سازمانی را تغییر دهد.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $unitId = max(0, (int) post_value('unit_id', '0'));
            $name = trim((string) post_value('unit_name', ''));
            $code = trim((string) post_value('unit_code', ''));
            $unitType = valid_choice(post_value('unit_type'), ['deputy', 'department'], 'department');
            $parentId = max(0, (int) post_value('parent_id', '0'));
            if ($name === '') {
                throw new RuntimeException('نام واحد الزامی است.');
            }
            $ceoId = org_ceo_unit_id();
            if ($unitType === 'deputy') {
                $parentId = $ceoId;
            } else {
                $parentUnit = $parentId > 0 ? org_unit_by_id($parentId) : null;
                if ($parentUnit === null || !in_array((string) $parentUnit['unit_type'], ['deputy', 'ceo'], true)) {
                    throw new RuntimeException('اداره باید زیر یک معاونت (یا مدیرعامل) تعریف شود.');
                }
            }
            if ($unitId > 0) {
                $existing = org_unit_by_id($unitId);
                if ($existing === null) {
                    throw new RuntimeException('واحد سازمانی یافت نشد.');
                }
                if ((string) $existing['unit_type'] === 'ceo') {
                    throw new RuntimeException('مدیرعامل از طریق بخش مدیرعامل تغییر می‌کند.');
                }
                if ($parentId === $unitId) {
                    throw new RuntimeException('واحد نمی‌تواند والد خودش باشد.');
                }
            }
            $managerIds = $_POST['manager_ids'] ?? null;
            if ($managerIds === null && post_value('manager_user_id', '0') !== '') {
                $managerIds = [(int) post_value('manager_user_id', '0')];
            }
            $managerIds = is_array($managerIds) ? array_values(array_unique(array_filter(array_map('intval', $managerIds)))) : [];
            if ($managerIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($managerIds), '?'));
                $valid = db()->prepare('SELECT id FROM users WHERE id IN (' . $placeholders . ') AND is_active = 1');
                $valid->execute($managerIds);
                $managerIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));
            }
            $isPrimary = post_value('is_primary_admin') === '1' ? 1 : 0;
            if ($unitId > 0) {
                db()->prepare('UPDATE org_units SET name = ?, code = ?, parent_id = ?, unit_type = ?, manager_user_id = ?, is_primary_admin = ? WHERE id = ?')
                    ->execute([$name, $code !== '' ? $code : null, $parentId > 0 ? $parentId : null, $unitType, $managerIds[0] ?? null, $isPrimary, $unitId]);
            } else {
                db()->prepare('INSERT INTO org_units (name, code, parent_id, unit_type, manager_user_id, is_primary_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
                    ->execute([$name, $code !== '' ? $code : null, $parentId > 0 ? $parentId : null, $unitType, $managerIds[0] ?? null, $isPrimary]);
                $unitId = (int) db()->lastInsertId();
            }
            db()->prepare('DELETE FROM org_unit_managers WHERE unit_id = ?')->execute([$unitId]);
            $insertManager = db()->prepare('INSERT INTO org_unit_managers (unit_id, user_id, is_primary) VALUES (?, ?, ?)');
            foreach ($managerIds as $index => $managerId) {
                $insertManager->execute([$unitId, $managerId, $index === 0 ? 1 : 0]);
                db()->prepare('UPDATE users SET role = "manager", org_unit_id = ? WHERE id = ? AND role IN ("user", "agent")')->execute([$unitId, $managerId]);
            }
            if ($isPrimary === 1 && $managerIds !== []) {
                db()->prepare('UPDATE users SET role = "primary_admin", is_primary_admin = 1, is_active = 1 WHERE id = ?')->execute([$managerIds[0]]);
            }
            org_record_undo($unitId > 0 ? 'ویرایش واحد سازمانی' : 'افزودن واحد سازمانی', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_unit_saved', null, ['unit_id' => $unitId, 'name' => $name, 'managers' => $managerIds]);
            flash('success', 'واحد سازمانی ذخیره شد.');
            redirect('index.php?page=organization#unit-' . $unitId);
        }

        if ($action === 'delete_org_unit') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند ساختار سازمانی را تغییر دهد.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $unitId = max(0, (int) post_value('unit_id', '0'));
            $unit = org_unit_by_id($unitId);
            if ($unit === null) {
                throw new RuntimeException('واحد سازمانی یافت نشد.');
            }
            if ((string) $unit['unit_type'] === 'ceo') {
                throw new RuntimeException('واحد مدیرعامل قابل حذف نیست.');
            }
            $unitIds = org_unit_subtree_ids($unitId);
            $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
            $managerIds = db()->prepare('SELECT manager_user_id FROM org_units WHERE id IN (' . $placeholders . ') AND manager_user_id IS NOT NULL');
            $managerIds->execute($unitIds);
            $managerIds = array_map('intval', $managerIds->fetchAll(PDO::FETCH_COLUMN));
            db()->beginTransaction();
            if ($managerIds !== []) {
                $managerPlaceholders = implode(',', array_fill(0, count($managerIds), '?'));
                db()->prepare('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL WHERE org_unit_id IN (' . $placeholders . ') OR manager_user_id IN (' . $managerPlaceholders . ')')->execute(array_merge($unitIds, $managerIds));
            } else {
                db()->prepare('UPDATE users SET org_unit_id = NULL, manager_user_id = NULL WHERE org_unit_id IN (' . $placeholders . ')')->execute($unitIds);
            }
            db()->prepare('DELETE FROM org_unit_managers WHERE unit_id IN (' . $placeholders . ')')->execute($unitIds);
            db()->prepare('UPDATE org_units SET is_active = 0, manager_user_id = NULL WHERE id IN (' . $placeholders . ')')->execute($unitIds);
            db_commit('index.php');
            org_record_undo('حذف واحد سازمانی', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'org_unit_deleted', null, ['unit_id' => $unitId, 'subtree_ids' => $unitIds]);
            flash('success', 'واحد و زیرمجموعه‌های آن غیرفعال شدند.');
            redirect('index.php?page=organization');
        }

        if ($action === 'save_user_org') {
            $orgAdmin = require_permission('org.manage');
            if (!user_can_manage_organization($orgAdmin)) {
                throw new RuntimeException('فقط ادمین اصلی می‌تواند عضویت سازمانی را تغییر دهد.');
            }
            org_ensure_schema();
            $orgUndoSnapshot = org_snapshot();
            $targetUserId = max(0, (int) post_value('user_id', '0'));
            $unitId = max(0, (int) post_value('org_unit_id', '0'));
            $managerId = max(0, (int) post_value('manager_user_id', '0'));
            $target = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $target->execute([$targetUserId]);
            if (!$target->fetchColumn()) {
                throw new RuntimeException('کاربر یافت نشد.');
            }
            if ($unitId > 0 && org_unit_by_id($unitId) === null) {
                throw new RuntimeException('واحد سازمانی یافت نشد.');
            }
            if ($managerId === 0 && $unitId > 0) {
                $memberUnit = org_unit_by_id($unitId);
                $managerId = (int) ($memberUnit['manager_user_id'] ?? 0);
            }
            if ($managerId === $targetUserId) {
                $managerId = 0;
            }
            db()->prepare('UPDATE users SET org_unit_id = ?, manager_user_id = ? WHERE id = ?')
                ->execute([$unitId > 0 ? $unitId : null, $managerId > 0 ? $managerId : null, $targetUserId]);
            org_record_undo('تغییر عضویت سازمانی کاربر', $orgUndoSnapshot);
            save_audit((int) $orgAdmin['id'], 'user_org_updated', null, ['user_id' => $targetUserId, 'unit_id' => $unitId, 'manager_user_id' => $managerId]);
            flash('success', 'عضویت سازمانی کاربر ذخیره شد.');
            redirect('index.php?page=organization#user-' . $targetUserId);
        }


        if ($action === 'save_key_roles') {
            $rolesAdmin = require_permission('settings.key_roles_edit');
            $collectRoleIds = static function (string $field): array {
                $raw = $_POST[$field] ?? [];
                if (!is_array($raw)) {
                    $raw = [];
                }
                $ids = [];
                foreach ($raw as $value) {
                    $id = (int) $value;
                    if ($id > 0 && !in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
                return array_slice($ids, 0, 2);
            };
            $supervisors = $collectRoleIds('supervisor_ids');
            $inspectors = $collectRoleIds('inspection_ids');
            $itExperts = $collectRoleIds('it_expert_ids');
            $allIds = array_values(array_unique(array_merge($supervisors, $inspectors, $itExperts)));
            if ($allIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($allIds), '?'));
                $validQuery = db()->prepare('SELECT id FROM users WHERE id IN (' . $placeholders . ') AND is_active = 1');
                $validQuery->execute($allIds);
                $validIds = array_map('intval', $validQuery->fetchAll(PDO::FETCH_COLUMN));
                $filterValid = static fn (array $list): array => array_values(array_intersect($list, $validIds));
                $supervisors = $filterValid($supervisors);
                $inspectors = $filterValid($inspectors);
                $itExperts = $filterValid($itExperts);
            }
            $oldSupervisors = key_role_user_ids('supervisor');
            $oldExperts = key_role_user_ids('it_expert');
            db()->beginTransaction();
            try {
                foreach (array_diff($supervisors, $oldSupervisors) as $id) {
                    db()->prepare('UPDATE users SET role = "supervisor" WHERE id = ? AND role NOT IN ("admin", "primary_admin")')->execute([$id]);
                }
                foreach (array_diff($oldSupervisors, $supervisors) as $id) {
                    db()->prepare('UPDATE users SET role = "user" WHERE id = ? AND role = "supervisor"')->execute([$id]);
                }
                foreach (array_diff($itExperts, $oldExperts) as $id) {
                    db()->prepare('UPDATE users SET role = "primary_admin", is_primary_admin = 1, is_active = 1 WHERE id = ?')->execute([$id]);
                }
                foreach (array_diff($oldExperts, $itExperts) as $id) {
                    db()->prepare('UPDATE users SET is_primary_admin = 0 WHERE id = ?')->execute([$id]);
                    db()->prepare('UPDATE users SET role = "user" WHERE id = ? AND role IN ("admin", "primary_admin")')->execute([$id]);
                }
                $primaryCount = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "primary_admin" AND is_primary_admin = 1 AND is_active = 1')->fetchColumn();
                if ($primaryCount < 1) {
                    throw new RuntimeException('حداقل یک ادمین اصلی باید باقی بماند.');
                }
                if (db()->inTransaction()) {
                    db_commit('index.php');
                }
            } catch (Throwable $roleException) {
                if (db()->inTransaction()) {
                    db_rollback('index.php');
                }
                throw $roleException;
            }
            save_setting('role_supervisor_ids', implode(',', $supervisors));
            save_setting('role_inspection_ids', implode(',', $inspectors));
            save_setting('role_it_expert_ids', implode(',', $itExperts));
            save_setting('cd_dvd_inspection_user_id', (string) ($inspectors[0] ?? 0));
            save_audit((int) $rolesAdmin['id'], 'key_roles_updated', null, ['supervisors' => $supervisors, 'inspectors' => $inspectors, 'it_experts' => $itExperts]);
            flash('success', 'نقش‌های کلیدی ذخیره شد (سوپروایز: ' . count($supervisors) . '، بازرسی: ' . count($inspectors) . '، کارشناس IT: ' . count($itExperts) . ').');
            redirect('index.php?page=settings#key-roles');
        }

        if ($action === 'save_role_permissions') {
            $permAdmin = require_primary_admin();
            $targetRole = post_value('role');
            if (!isset(permission_roles()[$targetRole])) {
                flash('error', 'نقش نامعتبر است.');
                redirect('index.php?page=settings#roles');
            }
            $codes = $_POST['permissions'] ?? [];
            if (!is_array($codes)) {
                $codes = [];
            }
            role_permissions_save($targetRole, $codes);
            save_audit((int) $permAdmin['id'], 'role_permissions_updated', null, ['role' => $targetRole, 'permissions' => array_values($codes)]);
            flash('success', 'دسترسی‌های نقش «' . permission_roles()[$targetRole] . '» ذخیره شد.');
            redirect('index.php?page=settings#roles');
        }

        if ($action === 'reset_role_defaults') {
            $permAdmin = require_primary_admin();
            $targetRole = post_value('role');
            if (!isset(permission_roles()[$targetRole])) {
                flash('error', 'نقش نامعتبر است.');
                redirect('index.php?page=settings#roles');
            }
            role_permissions_reset($targetRole);
            save_audit((int) $permAdmin['id'], 'reset_role_defaults', null, ['role' => $targetRole]);
            flash('success', 'دسترسی‌های نقش «' . permission_roles()[$targetRole] . '» به پیش‌فرض داخل کد برگشت.');
            redirect('index.php?page=settings#roles');
        }

        if ($action === 'reset_role_permissions') {
            $permAdmin = require_primary_admin();
            // ۱.۳۷.۷ — به‌جای ریختن دوبارهٔ پیش‌فرض‌ها در جدول (که نقش را «ویرایش‌شده» علامت می‌زد)،
            // همهٔ نقش‌ها به حالت «دست‌نخورده» برمی‌گردند تا از این پس پیش‌فرض‌های کد اعمال شوند.
            foreach (array_keys(permission_roles()) as $resetRole) {
                role_permissions_reset($resetRole);
            }
            save_audit((int) $permAdmin['id'], 'role_permissions_reset', null, ['roles' => array_keys(permission_roles())]);
            flash('success', 'همهٔ نقش‌ها به پیش‌فرض داخل کد بازگشتند.');
            redirect('index.php?page=settings#roles');
        }

        if ($action === 'prune_activity_logs') {
            $pruneAdmin = require_primary_admin();
            $days = (int) post_value('retention_days', (string) activity_log_retention_days());
            if ($days > 0) {
                save_setting('activity_log_retention_days', (string) $days);
            } else {
                $days = activity_log_retention_days();
            }
            $deleted = activity_logs_prune($days);
            save_audit((int) $pruneAdmin['id'], 'activity_logs_pruned', null, ['days' => $days, 'deleted' => $deleted]);
            flash('success', 'لاگ‌های قدیمی‌تر از ' . $days . ' روز پاک شد. تعداد حذف‌شده: ' . $deleted . '.');
            redirect('index.php?page=settings#general');
        }

        if ($action === 'sync_ldap_users') {
            $settingsAdmin = require_permission('settings.users_edit');
            $syncResult = ldap_sync_all_users();
            save_audit((int) $settingsAdmin['id'], 'ldap_bulk_sync', null, $syncResult);
            flash('success', 'همگام‌سازی کاربران دامین انجام شد. جدید: ' . $syncResult['created'] . '، به‌روزشده: ' . $syncResult['updated'] . '، غیرفعال: ' . $syncResult['disabled'] . '.');
            redirect('index.php?page=settings#users');
        }

        if ($action === 'diagnose_ldap') {
            $diagnoseAdmin = require_permission('settings.domain_edit');
            $diagnoseResult = ldap_diagnose();
            save_audit((int) $diagnoseAdmin['id'], 'ldap_diagnosed', null, ['ok' => $diagnoseResult['ok']]);
            if ($diagnoseResult['ok']) {
                flash('success', 'اتصال دامنه سالم است. ' . $diagnoseResult['summary']);
            } else {
                flash('danger', 'اشکال در اتصال دامنه: ' . $diagnoseResult['summary']);
            }
            redirect('index.php?page=settings#ldap-sync');
        }

        if ($action === 'save_settings') {
            $settingsUser = require_login();
            $sectionPermission = [
                'general' => 'settings.general_edit',
                'ldap' => 'settings.domain_edit',
                'domain_scan' => 'settings.domain_edit',
                'login_branding' => 'settings.general_edit',
            ][post_value('settings_section')] ?? 'settings.general_edit';
            if (!user_can($settingsUser, $sectionPermission)) {
                http_response_code(403);
                exit('دسترسی به این بخش مجاز نیست.');
            }
            $appName = post_value('app_name');
            if ($appName === '') {
                throw new RuntimeException('نام سامانه نمی‌تواند خالی باشد.');
            }
            save_setting('app_name', $appName);
            if (post_value('settings_section') === 'general' && array_key_exists('asset_hostname_style', $_POST)) {
                // ۱.۳۷.۵ — حالت نمایش نام کامپیوترها: adminit2 (پیش‌فرض) | ADMINIT2 | ADMINIT2.DOMAIN.LOCAL
                save_setting('asset_hostname_style', valid_choice(post_value('asset_hostname_style'), ['short_lower', 'short', 'full'], 'short_lower'));
            }
            if (post_value('settings_section') === 'ldap') {
                $groupMap = post_value('ldap_group_map');
                if (array_key_exists('ldap_group_map', $_POST) && $groupMap !== '' && !is_array(json_decode($groupMap, true))) {
                    throw new RuntimeException('نگاشت گروه‌های AD باید JSON معتبر باشد.');
                }
                $ldapKeys = ['ldap_enabled','ldap_host','ldap_port','ldap_ssl','ldap_base_dn','ldap_bind_dn','ldap_domain_suffix'];
                if (array_key_exists('ldap_group_map', $_POST)) {
                    $ldapKeys[] = 'ldap_group_map';
                }
                foreach ($ldapKeys as $key) {
                    save_setting($key, post_value($key));
                }
                if (post_value('ldap_bind_password') !== '') {
                    save_setting('ldap_bind_password', post_value('ldap_bind_password'));
                }
            }
            if (post_value('settings_section') === 'domain_scan') {
                foreach (['domain_scan_domain', 'domain_scan_username', 'domain_scan_timeout'] as $key) {
                    save_setting($key, post_value($key));
                }
                // ترتیب پروتکل اتصال برای استخراج از راه دور (۱.۳۷): برای شبکه‌هایی که DCOM بسته است.
                save_setting('domain_scan_protocols', valid_choice(post_value('domain_scan_protocols'), ['', 'Dcom,Wsman', 'Wsman,Dcom', 'Dcom', 'Wsman'], ''));
                if (post_value('domain_scan_password') !== '') {
                    save_setting('domain_scan_password', post_value('domain_scan_password'));
                }
            }
            if (post_value('settings_section') === 'login_branding') {
                foreach (['login_title' => 120, 'login_intro' => 500, 'login_art_title' => 100, 'login_art_text' => 300, 'login_note_title' => 100, 'login_note_text' => 300, 'login_announcement' => 240] as $loginKey => $maxLength) {
                    $value = post_value($loginKey);
                    if ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > $maxLength) {
                        throw new RuntimeException('طول یکی از متن‌های صفحه ورود بیش از حد مجاز است.');
                    }
                    save_setting($loginKey, $value);
                }
                if (!empty($_FILES['login_logo']['tmp_name']) && is_uploaded_file($_FILES['login_logo']['tmp_name'])) {
                    $mime = mime_content_type($_FILES['login_logo']['tmp_name']) ?: '';
                    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
                    if (!isset($allowed[$mime]) || (int) $_FILES['login_logo']['size'] > 2 * 1024 * 1024) {
                        throw new RuntimeException('لوگوی صفحه ورود باید PNG یا JPG و حداکثر ۲ مگابایت باشد.');
                    }
                    @mkdir(APP_ROOT . '/assets/uploads', 0755, true);
                    $filename = 'login-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                    if (!move_uploaded_file($_FILES['login_logo']['tmp_name'], APP_ROOT . '/assets/uploads/' . $filename)) {
                        throw new RuntimeException('ذخیره لوگوی صفحه ورود انجام نشد.');
                    }
                    save_setting('app_login_logo', 'assets/uploads/' . $filename);
                }
            }
            if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $mime = mime_content_type($_FILES['logo']['tmp_name']) ?: '';
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
                if (isset($allowed[$mime]) && (int) $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                    @mkdir(APP_ROOT . '/assets/uploads', 0755, true);
                    $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                    move_uploaded_file($_FILES['logo']['tmp_name'], APP_ROOT . '/assets/uploads/' . $filename);
                    save_setting('app_logo', 'assets/uploads/' . $filename);
                }
            }
            flash('success', 'تنظیمات سامانه ذخیره شد.');
            redirect('index.php?page=settings');
        }
    } catch (Throwable $exception) {
        if (isset($GLOBALS['activity_post'])) {
            $GLOBALS['activity_post']['failed'] = true;
        }
        if (db()->inTransaction()) {
            db_rollback('index.php');
        }
        error_log('ITSM request failed: ' . $exception->getMessage() . ' | ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        system_log('error', 'request', $exception->getMessage(), ['uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''), 'file' => $exception->getFile(), 'line' => $exception->getLine()]);
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'عملیات انجام نشد. گزارش خطا در لاگ سامانه ثبت شد.');
        redirect('index.php?page=' . urlencode($page));
    }
}

if (!$user) {
    $loginTitle = setting('login_title', 'به سامانه پشتیبانی خوش آمدید');
    $loginIntro = setting('login_intro', 'برای ثبت و پیگیری درخواست‌های خود وارد شوید.');
    $loginNoteTitle = setting('login_note_title', 'ورود یکپارچه سازمانی');
    $loginNoteText = setting('login_note_text', 'کاربران شبکه با حساب Active Directory خود وارد می‌شوند.');
    $loginAnnouncement = setting('login_announcement', '');
    $loginLogo = setting('app_login_logo', setting('app_logo', (string) cfg('app.logo', '')));
    $GLOBALS['ui_particles'] = true;
    render_header('ورود به سامانه');
    echo '<main class="auth-layout"><section class="auth-panel">' . ($loginLogo ? '<div class="login-brand-logo"><img src="' . e($loginLogo) . '" alt="لوگوی سامانه"></div>' : '') . '<div class="auth-kicker">' . e(setting('app_name', (string) cfg('app.name', 'مرکز خدمات سازمان'))) . '</div><h1>' . e($loginTitle) . '</h1><p class="muted">' . e($loginIntro) . '</p>' . ($loginAnnouncement !== '' ? '<div class="login-announcement"><span aria-hidden="true">✦</span><div>' . e($loginAnnouncement) . '</div></div>' : '') . '<form method="post" class="auth-form">' . csrf_field() . '<input type="hidden" name="action" value="login"><label>نام کاربری شبکه یا سامانه<input name="username" required autocomplete="username" autofocus placeholder="نام کاربری شما"></label><label>رمز عبور<input type="password" name="password" required autocomplete="current-password" placeholder="رمز عبور"></label><button class="button wide" type="submit">ورود امن</button></form><div class="login-note"><span>◆</span><div><strong>' . e($loginNoteTitle) . '</strong><small>' . e($loginNoteText) . '</small></div></div></section><aside class="auth-art"><div class="art-orb orb-one"></div><div class="art-orb orb-two"></div><div class="art-card"><span class="art-icon">ت</span><strong>' . e(setting('login_art_title', 'پشتیبانی، ساده و شفاف')) . '</strong><p>' . e(setting('login_art_text', 'هر درخواست یک شماره پیگیری دارد و مسیر رسیدگی آن برای شما روشن است.')) . '</p></div></aside></main>';
    render_footer();
    exit;
}

$pagePermissions = [
    'profile' => 'profile.edit',
    'queue' => 'queue.view',
    'reports' => 'reports.view',
    'analytics' => 'analytics.view',
    'supervisor' => 'supervisor.panel',
    'inventory' => 'inventory.view',
    'inventory-diagnostics' => 'inventory.diagnostics',
    'domain-scan' => 'domain.scan',
    'dashboard' => 'dash.view',
    'assets' => 'assets.view',
    'asset' => 'asset.view',
    'asset-history' => 'asset.history',
    'cd-dvd' => 'cddvd.view',
    'cd-dvd-history' => 'cddvd.history',
    'traffic-control' => 'traffic.view',
    'traffic-control-print' => 'traffic.view',
    'knowledge' => 'knowledge.view',
    'backup' => 'backup.view',
    'organization' => 'org.view',
    'holidays' => 'holidays.view',
    'services' => 'services.view',
    // 'food-ticket' — به‌جای یک کد تکی، با food_ticket_is_allowed() سنجیده می‌شود (پایین‌تر).
];
// ۱.۳۷.۷ — دروازهٔ «چاپ فیش غذا» با فهرست کدهای food.* یکی شد: پیش‌تر منو با
// food_ticket_is_allowed() (هر کد food.*) نمایش داده می‌شد ولی صفحه فقط با food.monitor باز
// می‌شد؛ یعنی نقشی که مثلاً فقط food.orders داشت، لینک را می‌دید و به ۴۰۳ می‌خورد.
if ($page === 'food-ticket' && !(function_exists('food_ticket_is_allowed') ? food_ticket_is_allowed($user) : user_can($user, 'food.monitor'))) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به این بخش مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه هماهنگ کنید.</p></div><a class="button" href="index.php">بازگشت به داشبورد</a></section>';
    render_footer();
    exit;
}
if (isset($pagePermissions[$page]) && !user_can($user, $pagePermissions[$page])) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به این بخش مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه هماهنگ کنید.</p></div><a class="button" href="index.php">بازگشت به داشبورد</a></section>';
    render_footer();
    exit;
}
if ($page === 'settings' && !user_can_any($user, ['settings.general', 'settings.domain', 'settings.users', 'settings.key_roles'])) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به تنظیمات مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه هماهنگ کنید.</p></div><a class="button" href="index.php">بازگشت به داشبورد</a></section>';
    render_footer();
    exit;
}

if ($page === 'profile') {
    $profileUser = require_login();
    $profileTheme = valid_choice((string) setting('theme_user_' . (int) $profileUser['id'], 'current'), ['current', 'ruby', 'indigo', 'copper'], 'current');
    $profileFirst = trim((string) ($profileUser['first_name'] ?? ''));
    $profileLast = trim((string) ($profileUser['last_name'] ?? ''));
    if ($profileFirst === '' && $profileLast === '') {
        $parts = preg_split('/\s+/u', trim((string) ($profileUser['full_name'] ?? '')), 2) ?: [];
        $profileFirst = (string) ($parts[0] ?? '');
        $profileLast = (string) ($parts[1] ?? '');
    }
    render_header('پروفایل کاربری', $profileUser);
    $profileInitial = $profileFirst !== '' ? $profileFirst : (string) $profileUser['full_name'];
    $profileInitial = function_exists('mb_substr') ? mb_substr($profileInitial, 0, 1) : substr($profileInitial, 0, 1);
    echo '<section class="page-heading"><div><span class="eyebrow">حساب کاربری</span><h1>پروفایل من</h1><p>اطلاعات تکمیلی شما برای شناسایی و خدمات سازمانی در این بخش نگهداری می‌شود.</p></div><a class="button secondary" href="index.php">بازگشت به داشبورد</a></section><section class="card form-card theme-preference" id="appearance"><h2>ظاهر دلخواه سامانه</h2><p class="muted">انتخاب شما فقط برای حساب خودتان ذخیره می‌شود و در ورودهای بعدی هم فعال می‌ماند؛ چیدمان صفحه‌ها تغییر نمی‌کند.</p><form method="post" class="theme-preference-form">' . csrf_field() . '<input type="hidden" name="action" value="save_theme"><label>تم پیش‌فرض من<select name="theme"><option value="current"' . ($profileTheme === 'current' ? ' selected' : '') . '>تم فعلی · سبز و کرم</option><option value="ruby"' . ($profileTheme === 'ruby' ? ' selected' : '') . '>یاقوتی رسمی</option><option value="indigo"' . ($profileTheme === 'indigo' ? ' selected' : '') . '>لاجوردی مدیریتی</option><option value="copper"' . ($profileTheme === 'copper' ? ' selected' : '') . '>آجری روشن و کرم</option></select></label><button class="button" type="submit">ذخیره و اعمال تم</button></form></section><section class="profile-layout"><div class="card profile-card"><div class="profile-avatar">' . (!empty($profileUser['profile_photo']) ? '<img src="index.php?action=profile_photo" alt="عکس پروفایل">' : '<span>' . e($profileInitial) . '</span>') . '</div><h2>' . e($profileUser['full_name']) . '</h2><p class="muted">' . e($profileUser['username']) . '</p><span class="status in_progress">اطلاعات شخصی</span></div><div class="card form-card profile-form-card"><h2>اطلاعات تکمیلی</h2><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="save_profile"><div class="form-grid"><label>نام<input name="first_name" value="' . e($profileFirst) . '" required></label><label>نام خانوادگی<input name="last_name" value="' . e($profileLast) . '" required></label><label>کد ملی<input name="national_code" value="' . e($profileUser['national_code'] ?? '') . '" inputmode="numeric" maxlength="10" pattern="[0-9۰-۹]{10}" placeholder="۱۰ رقم معتبر"></label><label>عکس پروفایل<input type="file" name="profile_photo" accept="image/png,image/jpeg"><small>PNG یا JPG، حداکثر ۲ مگابایت؛ عکس در فضای محافظت‌شده ذخیره می‌شود.</small></label></div><div class="actions"><button class="button" type="submit">ذخیره پروفایل</button></div></form></div></section>';
    render_footer();
    exit;
}

if ($page === 'governance') {
    redirect('governance.php');
}

if ($page === 'food-ticket') {
    food_ticket_render_page($user ?? []);
    exit;
}

if ($page === 'food-order' && $user && !user_can($user, 'foodorder.self')) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به این بخش مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه تماس بگیرید.</p></div></section>';
    render_footer();
    exit;
}
if ($page === 'food-order') {
    food_order_render_page($user ?? []);
}

if ($page === 'cd-dvd') {
    cd_dvd_render_page($user);
}

if ($page === 'cd-dvd-history') {
    cd_dvd_render_history($user);
}

if ($page === 'traffic-control') {
    traffic_render_page($user);
}
if ($page === 'traffic-control-print') {
    traffic_render_print($user, (int) ($_GET['id'] ?? 0));
}

if ($page === 'services') {
    $serviceUser = require_login();
    $isServiceAdmin = is_admin_role((string) $serviceUser['role']) || (int) ($serviceUser['is_primary_admin'] ?? 0) === 1;
    $serviceGroupFilter = valid_choice((string) ($_GET['group'] ?? ''), ['', 'it', 'support'], '');
    $listSql = 'SELECT s.*, d.name AS department_name, c.name AS category_name, hu.name AS handling_unit_name, COUNT(f.id) AS field_count FROM service_catalog s LEFT JOIN departments d ON d.id = s.department_id LEFT JOIN categories c ON c.id = s.category_id LEFT JOIN handling_units hu ON hu.id = s.handling_unit_id LEFT JOIN service_catalog_fields f ON f.service_id = s.id WHERE s.is_active = 1';
    $listParams = [];
    if ($serviceGroupFilter !== '') {
        $listSql .= ' AND s.service_group = ?';
        $listParams[] = $serviceGroupFilter;
    }
    $listSql .= ' GROUP BY s.id, d.name, c.name, hu.name ORDER BY s.service_group, s.name';
    $listQuery = db()->prepare($listSql);
    $listQuery->execute($listParams);
    $services = $listQuery->fetchAll();
    $serviceFieldsById = [];
    if ($services) {
        $serviceIds = array_map(static fn (array $service): int => (int) $service['id'], $services);
        $fieldQuery = db()->prepare('SELECT * FROM service_catalog_fields WHERE service_id IN (' . implode(',', array_fill(0, count($serviceIds), '?')) . ') ORDER BY service_id, sort_order, id');
        $fieldQuery->execute($serviceIds);
        foreach ($fieldQuery->fetchAll() as $field) {
            $serviceFieldsById[(int) $field['service_id']][] = $field;
        }
    }
    $categories = db()->query('SELECT * FROM categories ORDER BY is_active DESC, service_group, name')->fetchAll();
    $handlingUnits = db()->query('SELECT id, code, name FROM handling_units WHERE is_active = 1 ORDER BY code')->fetchAll();
    $serviceDepartments = db()->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
    $categoryOptions = static function (int $selected) use ($categories): string {
        $html = '<option value="">بدون دسته</option>';
        foreach ($categories as $category) {
            if ((int) $category['is_active'] !== 1) {
                continue;
            }
            $html .= '<option value="' . (int) $category['id'] . '" ' . ($selected === (int) $category['id'] ? 'selected' : '') . '>' . e($category['name']) . '</option>';
        }
        return $html;
    };
    $handlingOptions = static function (int $selected) use ($handlingUnits): string {
        $html = '';
        foreach ($handlingUnits as $unit) {
            $html .= '<option value="' . (int) $unit['id'] . '" ' . ($selected === (int) $unit['id'] ? 'selected' : '') . '>' . e($unit['name']) . '</option>';
        }
        return $html;
    };
    $departmentOptions = static function (int $selected) use ($serviceDepartments): string {
        $html = '<option value="">سراسری</option>';
        foreach ($serviceDepartments as $department) {
            $html .= '<option value="' . (int) $department['id'] . '" ' . ($selected === (int) $department['id'] ? 'selected' : '') . '>' . e($department['name']) . '</option>';
        }
        return $html;
    };
    $ticketTypeOptions = static function (string $selected): string {
        $types = ['incident' => 'رخداد', 'request' => 'درخواست خدمت', 'problem' => 'مشکل ریشه‌ای', 'change' => 'تغییر'];
        $html = '';
        foreach ($types as $value => $label) {
            $html .= '<option value="' . $value . '" ' . ($selected === $value ? 'selected' : '') . '>' . $label . '</option>';
        }
        return $html;
    };
    $priorityOptions = static function (string $selected): string {
        $items = ['normal' => 'عادی', 'urgent' => 'فوری', 'critical' => 'حیاتی'];
        $html = '';
        foreach ($items as $value => $label) {
            $html .= '<option value="' . $value . '" ' . ($selected === $value ? 'selected' : '') . '>' . $label . '</option>';
        }
        return $html;
    };
    // فرم مشترک ساخت/ویرایش خدمت (بدون تگ form، تا در مودال و فرم ایجاد استفاده شود)
    $serviceFormFields = static function (array $service) use ($categoryOptions, $handlingOptions, $departmentOptions, $ticketTypeOptions, $priorityOptions): string {
        $group = (string) ($service['service_group'] ?? 'it');
        return '<div class="form-grid">'
            . '<label>نام خدمت<input name="service_name" required value="' . e((string) ($service['name'] ?? '')) . '" placeholder="مثلاً دسترسی به سامانه داخلی"></label>'
            . '<label>کد خدمت انگلیسی<input name="service_code" required value="' . e((string) ($service['code'] ?? '')) . '" placeholder="IT-ACCESS"></label>'
            . '<label>حوزه خدمت<select name="service_group"><option value="it" ' . ($group === 'it' ? 'selected' : '') . '>IT</option><option value="support" ' . ($group === 'support' ? 'selected' : '') . '>پشتیبانی</option></select></label>'
            . '<label>دسته‌بندی<select name="category_id">' . $categoryOptions((int) ($service['category_id'] ?? 0)) . '</select></label>'
            . '<label>واحد رسیدگی<select name="handling_unit_id">' . $handlingOptions((int) ($service['handling_unit_id'] ?? 0)) . '</select></label>'
            . '<label>معاونت مالک<select name="department_id">' . $departmentOptions((int) ($service['department_id'] ?? 0)) . '</select></label>'
            . '<label>نوع پیش‌فرض<select name="default_ticket_type">' . $ticketTypeOptions((string) ($service['default_ticket_type'] ?? 'incident')) . '</select></label>'
            . '<label>اولویت پیش‌فرض<select name="default_priority">' . $priorityOptions((string) ($service['default_priority'] ?? 'normal')) . '</select></label>'
            . '<label class="full">توضیح خدمت<textarea name="service_description" rows="3">' . e((string) ($service['description'] ?? '')) . '</textarea></label>'
            . '<label class="check-label"><input type="checkbox" name="requires_asset" value="1" ' . ((int) ($service['requires_asset'] ?? 1) === 1 ? 'checked' : '') . '> نیازمند شناسنامه فنی</label>'
            . '<label class="check-label"><input type="checkbox" name="is_active" value="1" ' . ((int) ($service['is_active'] ?? 1) === 1 ? 'checked' : '') . '> فعال</label>'
            . '</div>';
    };
    render_header('کاتالوگ خدمات', $serviceUser);
    echo '<section class="page-heading"><div><span class="eyebrow">Service Catalog</span><h1>کاتالوگ خدمات سازمان</h1><p>هر خدمت فرم تکمیلی، اولویت پیش‌فرض و مسیر رسیدگی مستقل دارد. با سربرگ‌ها بین بخش‌ها جابه‌جا شوید.</p></div><a class="button" href="index.php?page=new-ticket">ثبت تیکت</a></section>';

    // --- سربرگ‌ها (تب‌ها) ---
    echo '<div class="settings-tabs service-tabs" data-service-tabs>';
    echo '<a href="#tab-create" data-service-tab="tab-create">تعریف خدمت</a>';
    echo '<a href="#tab-list" data-service-tab="tab-list">لیست خدمات</a>';
    if ($isServiceAdmin) {
        echo '<a href="#tab-categories" data-service-tab="tab-categories">دسته‌بندی‌ها</a>';
    }
    echo '</div>';

    // --- تب ۱: تعریف خدمت (فقط فرم ایجاد خدمت) ---
    echo '<div class="service-tab-panels">';
    echo '<section class="card form-card service-tab-panel" id="tab-create">';
    if ($isServiceAdmin) {
        echo '<h2>تعریف خدمت جدید</h2><p class="muted">حوزه، دسته‌بندی، واحد رسیدگی و الزام شناسنامه فنی را از همین‌جا تعیین کنید. فیلدهای تکمیلی هر خدمت را بعد از ساخت، از دکمهٔ «فیلدها» در فهرست خدمات مدیریت کنید.</p><form method="post"><input type="hidden" name="action" value="add_service">' . csrf_field() . $serviceFormFields([]) . '<div class="actions"><button class="button" type="submit">ایجاد خدمت</button></div></form>';
    } else {
        echo '<h2>تعریف خدمت</h2><p class="muted">فقط ادمین‌ها می‌توانند خدمت جدید تعریف کنند.</p>';
    }
    echo '</section>';

    // --- تب ۲: لیست خدمات + فیلتر ---
    echo '<section class="card form-card service-tab-panel" id="tab-list" hidden>';
    echo '<h2>لیست خدمات</h2><p class="muted">با انتخاب حوزه، فهرست سرویس‌ها فیلتر می‌شود.</p>';
     echo '<form method="get" class="service-filter-bar"><input type="hidden" name="page" value="services"><input type="hidden" name="tab" value="tab-list"><label>فیلتر حوزه خدمت<select name="group" data-service-filter><option value="" ' . ($serviceGroupFilter === '' ? 'selected' : '') . '>همه حوزه‌ها</option><option value="it" ' . ($serviceGroupFilter === 'it' ? 'selected' : '') . '>خدمات IT</option><option value="support" ' . ($serviceGroupFilter === 'support' ? 'selected' : '') . '>خدمات پشتیبانی</option></select></label><button class="button secondary service-filter-submit" type="submit">اعمال فیلتر</button></form>';
    echo '<div class="service-list"><div class="service-row service-row-head"><span>خدمت</span><span>حوزه</span><span>دسته</span><span>واحد رسیدگی</span><span>اولویت</span>' . ($isServiceAdmin ? '<span>عملیات</span>' : '') . '</div>';
    foreach ($services as $service) {
        $sid = (int) $service['id'];
        echo '<div class="service-row"><span class="service-name"><strong>' . e($service['name']) . '</strong><small>' . e($service['code']) . ' • ' . (int) $service['field_count'] . ' فیلد' . ((int) $service['requires_asset'] === 1 ? ' • نیازمند شناسنامه' : '') . '</small></span>';
        echo '<span>' . ($service['service_group'] === 'it' ? 'IT' : 'پشتیبانی') . '</span>';
        echo '<span>' . e($service['category_name'] ?: '—') . '</span>';
        echo '<span>' . e($service['handling_unit_name'] ?: 'تعیین‌نشده') . '</span>';
        echo '<span><i class="priority-dot ' . e($service['default_priority']) . '"></i>' . e(priority_label($service['default_priority'])) . '</span>';
        if ($isServiceAdmin) {
            echo '<span class="service-actions"><button type="button" class="mini-button" data-modal-open="service-fields-' . $sid . '">فیلدها</button>';
            echo '<button type="button" class="mini-button" data-modal-open="service-edit-' . $sid . '">ویرایش</button>';
            echo '<form method="post" class="service-delete" data-confirm="خدمت از فهرست فعال خارج شود؟"><input type="hidden" name="action" value="delete_service"><input type="hidden" name="service_id" value="' . $sid . '">' . csrf_field() . '<button class="mini-button danger" type="submit">حذف</button></form></span>';
        }
        echo '</div>';
    }
    if (!$services) {
        echo '<div class="service-row"><span class="muted">با این فیلتر خدمتی پیدا نشد.</span></div>';
    }
    echo '</div></section>';

    // --- تب ۳: دسته‌بندی‌ها ---
    if ($isServiceAdmin) {
        echo '<section class="card form-card service-tab-panel" id="tab-categories" hidden>';
        echo '<h2>دسته‌بندی خدمات</h2><p class="muted">دسته‌بندی لایهٔ میانی بین حوزه و خدمت است؛ هر خدمت به یک دسته وصل می‌شود.</p>';
        echo '<form method="post" class="inline-form"><input type="hidden" name="action" value="save_service_category">' . csrf_field() . '<label>نام دسته<input name="category_name" required placeholder="مثلاً شبکه و اینترنت"></label><label>کد<input name="category_code" placeholder="IT-CAT-NET"></label><label>حوزه<select name="category_group"><option value="it">IT</option><option value="support">پشتیبانی</option></select></label><button class="button" type="submit">افزودن دسته</button></form>';
        echo '<div class="category-list">';
        foreach ($categories as $category) {
            $cid = (int) $category['id'];
            echo '<div><span><strong>' . e($category['name']) . '</strong><small>' . e($category['code'] ?: 'بدون کد') . ' • ' . ($category['service_group'] === 'it' ? 'IT' : 'پشتیبانی') . ' • ' . ((int) $category['is_active'] === 1 ? 'فعال' : 'غیرفعال') . '</small></span><span class="service-actions"><button type="button" class="mini-button" data-modal-open="category-edit-' . $cid . '">ویرایش</button><form method="post" class="service-delete" data-confirm="دسته غیرفعال شود؟ خدمت‌های وابسته حذف نمی‌شوند."><input type="hidden" name="action" value="delete_service_category"><input type="hidden" name="category_id" value="' . $cid . '">' . csrf_field() . '<button class="mini-button danger" type="submit">حذف</button></form></span></div>';
        }
        echo '</div></section>';
    }
    echo '</div>';

    // --- پنجره‌های ویرایش/فیلد (rendered outside the tab panels, fixed overlay) ---
    $serviceModals = '';
    if ($isServiceAdmin) {
        foreach ($services as $service) {
            $sid = (int) $service['id'];
            $serviceFields = $serviceFieldsById[$sid] ?? [];
            $serviceModals .= '<div class="service-modal" role="dialog" aria-modal="true" aria-labelledby="service-edit-title-' . $sid . '" data-modal="service-edit-' . $sid . '" hidden><div class="service-modal-card"><div class="service-modal-head"><h3 id="service-edit-title-' . $sid . '">ویرایش خدمت</h3><p class="muted">' . e($service['name']) . ' • ' . e($service['code']) . '</p><button type="button" class="service-modal-close" aria-label="بستن" data-modal-close>&times;</button></div><div class="service-modal-body"><form method="post" id="service-edit-form-' . $sid . '" class="service-edit-form"><input type="hidden" name="action" value="edit_service"><input type="hidden" name="service_id" value="' . $sid . '">' . csrf_field() . $serviceFormFields($service) . '</form></div><div class="service-modal-foot"><button type="button" class="button secondary" data-modal-close>انصراف</button><button type="submit" class="button" form="service-edit-form-' . $sid . '">ذخیره</button></div></div></div>';
            $serviceModals .= '<div class="service-modal" role="dialog" aria-modal="true" aria-labelledby="service-fields-title-' . $sid . '" data-modal="service-fields-' . $sid . '" hidden><div class="service-modal-card"><div class="service-modal-head"><h3 id="service-fields-title-' . $sid . '">فیلدهای تکمیلی خدمت</h3><p class="muted">خدمت: ' . e($service['name']) . '</p><button type="button" class="service-modal-close" aria-label="بستن" data-modal-close>&times;</button></div><div class="service-modal-body"><p class="muted">فیلد پویا یعنی پرسش اضافی که هنگام ثبت تیکت برای همین خدمت از درخواست‌کننده پرسیده می‌شود؛ در فرم ثبت خدمت نمایش داده نمی‌شود.</p><div class="category-list service-field-list">';
            foreach ($serviceFields as $field) {
                $serviceModals .= '<div><span><strong>' . e($field['label']) . '</strong><small>' . e($field['field_key']) . ' • ' . e($field['field_type']) . '</small></span><span>' . ((int) $field['is_required'] === 1 ? 'اجباری' : 'اختیاری') . '</span></div>';
            }
            if (!$serviceFields) {
                $serviceModals .= '<div><span class="muted">هنوز فیلد تکمیلی برای این خدمت تعریف نشده است.</span></div>';
            }
            $serviceModals .= '</div><form method="post" id="service-fields-form-' . $sid . '" class="service-field-form">' . csrf_field() . '<input type="hidden" name="action" value="add_service_field"><input type="hidden" name="service_id" value="' . $sid . '"><h4>افزودن فیلد برای فرم ثبت تیکت</h4><div class="form-grid"><label>کلید انگلیسی<input name="field_key" pattern="[a-z][a-z0-9_]{1,79}" required placeholder="device_model"></label><label>عنوان نمایشی<input name="field_label" required placeholder="مثلاً مدل دستگاه"></label><label>نوع فیلد<select name="field_type"><option value="text">متن</option><option value="textarea">متن چندخطی</option><option value="number">عدد</option><option value="select">انتخابی</option><option value="date">تاریخ</option></select></label><label>ترتیب نمایش<input type="number" name="sort_order" value="0"></label><label class="full">گزینه‌های فیلد انتخابی<small>هر گزینه در یک خط یا با ویرگول جدا شود</small><textarea name="field_options" rows="3"></textarea></label><label class="check-label"><input type="checkbox" name="field_required" value="1"> اجباری</label></div></form></div><div class="service-modal-foot"><button type="button" class="button secondary" data-modal-close>بستن</button><button type="submit" class="button" form="service-fields-form-' . $sid . '">افزودن فیلد</button></div></div></div>';
        }
        foreach ($categories as $category) {
            $cid = (int) $category['id'];
            $serviceModals .= '<div class="service-modal" role="dialog" aria-modal="true" aria-labelledby="category-edit-title-' . $cid . '" data-modal="category-edit-' . $cid . '" hidden><div class="service-modal-card"><div class="service-modal-head"><h3 id="category-edit-title-' . $cid . '">ویرایش دسته‌بندی</h3><p class="muted">' . e($category['name']) . '</p><button type="button" class="service-modal-close" aria-label="بستن" data-modal-close>&times;</button></div><div class="service-modal-body"><form method="post" id="category-edit-form-' . $cid . '" class="service-edit-form"><input type="hidden" name="action" value="save_service_category"><input type="hidden" name="category_id" value="' . $cid . '">' . csrf_field() . '<div class="form-grid"><label>نام<input name="category_name" required value="' . e($category['name']) . '"></label><label>کد<input name="category_code" value="' . e($category['code'] ?? '') . '"></label><label>حوزه<select name="category_group"><option value="it" ' . ($category['service_group'] === 'it' ? 'selected' : '') . '>IT</option><option value="support" ' . ($category['service_group'] === 'support' ? 'selected' : '') . '>پشتیبانی</option></select></label></div><label class="check-label"><input type="checkbox" name="category_active" value="1" ' . ((int) $category['is_active'] === 1 ? 'checked' : '') . '> فعال</label></form></div><div class="service-modal-foot"><button type="button" class="button secondary" data-modal-close>انصراف</button><button type="submit" class="button" form="category-edit-form-' . $cid . '">ذخیره</button></div></div></div>';
        }
    }
    if ($serviceModals !== '') {
        echo '<div class="service-modal-layer" data-service-modal-layer hidden>' . $serviceModals . '</div>';
    }
    render_footer();
    exit;
}

if ($page === 'help') {
    $helpUser = require_login();
    render_header('راهنمای سامانه', $helpUser);
    require __DIR__ . '/help-guide.php';
    render_footer();
    exit;
}

if ($page === 'knowledge') {
    $knowledgeUser = require_login();
    $articleId = (int) ($_GET['id'] ?? 0);
    $article = null;
    if ($articleId > 0) {
        $articleQuery = db()->prepare('SELECT k.*, u.full_name AS author_name FROM knowledge_articles k LEFT JOIN users u ON u.id = k.created_by WHERE k.id = ? AND (k.is_published = 1 OR ? = 1) LIMIT 1');
        $articleQuery->execute([$articleId, is_staff_role($knowledgeUser['role']) ? 1 : 0]);
        $article = $articleQuery->fetch();
    }
    $knowledgeSearch = trim((string) ($_GET['q'] ?? ''));
    $knowledgeSql = 'SELECT id, title, category, updated_at FROM knowledge_articles WHERE ' . (is_staff_role($knowledgeUser['role']) ? '1 = 1' : 'is_published = 1');
    $knowledgeParams = [];
    if ($knowledgeSearch !== '') {
        $knowledgeSql .= ' AND (title LIKE ? OR body LIKE ? OR category LIKE ?)';
        $knowledgeParams = array_fill(0, 3, '%' . $knowledgeSearch . '%');
    }
    $knowledgeSql .= ' ORDER BY updated_at DESC LIMIT 100';
    $knowledgeQuery = db()->prepare($knowledgeSql);
    $knowledgeQuery->execute($knowledgeParams);
    $articles = $knowledgeQuery->fetchAll();
    render_header('دانش‌نامه', $knowledgeUser);
    echo '<section class="page-heading"><div><span class="eyebrow">Knowledge Base</span><h1>دانش‌نامه و راهنمای حل مشکل</h1><p>قبل از ثبت تیکت، راه‌حل‌های تاییدشده سازمان را جست‌وجو کنید.</p></div><form class="inline-search" method="get"><input type="hidden" name="page" value="knowledge"><input name="q" value="' . e($knowledgeSearch) . '" placeholder="جست‌وجو در دانش‌نامه"><button class="button" type="submit">جست‌وجو</button></form></section>';
    if ($article) {
        echo '<article class="card knowledge-article"><span class="eyebrow">' . e($article['category'] ?: 'راهنما') . '</span><h2>' . e($article['title']) . '</h2><p class="muted">نویسنده: ' . e($article['author_name'] ?: 'سامانه') . ' • ' . e(jalali_date($article['updated_at'])) . '</p><div class="article-body">' . nl2br(e($article['body'])) . '</div><a class="button secondary" href="index.php?page=knowledge">بازگشت به دانش‌نامه</a></article>';
    } else {
        echo '<section class="knowledge-grid">';
        foreach ($articles as $item) { echo '<a class="card knowledge-card" href="index.php?page=knowledge&id=' . (int) $item['id'] . '"><span class="eyebrow">' . e($item['category'] ?: 'راهنما') . '</span><h2>' . e($item['title']) . '</h2><small>به‌روزرسانی: ' . e(jalali_date($item['updated_at'], false)) . '</small></a>'; }
        if (!$articles) { echo '<div class="card empty-state"><h2>مقاله‌ای پیدا نشد.</h2><p>عبارت دیگری جست‌وجو کنید یا از کارشناس انفورماتیک کمک بگیرید.</p></div>'; }
        echo '</section>';
    }
    if (is_staff_role($knowledgeUser['role'])) {
        echo '<section class="card form-card"><h2>افزودن مقاله دانش‌نامه</h2><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="add_knowledge"><div class="form-grid"><label>عنوان مقاله<input name="article_title" required></label><label>دسته‌بندی<input name="article_category" placeholder="شبکه، پرینتر، نرم‌افزار"></label><label class="full">متن راهنما<textarea name="article_body" rows="7" required></textarea></label><label class="check-label"><input type="checkbox" name="is_published" value="1" checked> منتشر شود</label></div><button class="button" type="submit">ذخیره مقاله</button></form></section>';
    }
    render_footer();
    exit;
}

if ($page === 'notifications' && $user && !user_can($user, 'notif.view')) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به این بخش مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه تماس بگیرید.</p></div></section>';
    render_footer();
    exit;
}
if ($page === 'notifications') {
    $notificationUser = require_login();
    $notificationQuery = db()->prepare('SELECT n.*, t.subject FROM notifications n LEFT JOIN tickets t ON t.id = n.ticket_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 100');
    $notificationQuery->execute([(int) $notificationUser['id']]);
    render_header('اعلان‌های داخلی', $notificationUser);
    echo '<section class="page-heading"><div><span class="eyebrow">اعلان داخلی آفلاین</span><h1>اعلان‌های شما</h1><p>تغییرات تیکت‌ها بدون نیاز به ایمیل یا پیامک در همین سامانه اعلام می‌شوند.</p></div><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="mark_all_notifications"><button class="button secondary" type="submit">خوانده‌شدن همه</button></form></section><section class="card notification-list">';
    foreach ($notificationQuery->fetchAll() as $notification) {
        $target = $notification['ticket_id'] ? 'index.php?page=ticket&id=' . (int) $notification['ticket_id'] : 'index.php?page=notifications';
        echo '<div class="notification-item ' . ((int) $notification['is_read'] === 0 ? 'unread' : '') . '"><a href="' . $target . '"><strong>' . e($notification['title']) . '</strong><span>' . e($notification['body']) . '</span><small>' . e(jalali_date($notification['created_at'])) . ($notification['subject'] ? ' • ' . e($notification['subject']) : '') . '</small></a>';
        if ((int) $notification['is_read'] === 0) { echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="mark_notification"><input type="hidden" name="notification_id" value="' . (int) $notification['id'] . '"><button class="mini-button" type="submit">خوانده شد</button></form>'; }
        echo '</div>';
    }
    echo '</section>';
    render_footer();
    exit;
}

if ($page === 'audit') {
    $legacyAuditUser = require_login();
    if (!user_can_any($legacyAuditUser, ['logs.activity', 'audit.view', 'logs.view'])) {
        http_response_code(403); exit('دسترسی به لاگ سامانه مجاز نیست.');
    }
    redirect('index.php?page=activity-log');
}

if ($page === 'logs') {
    $legacyLogUser = require_login();
    if (!user_can_any($legacyLogUser, ['logs.view', 'logs.activity', 'audit.view'])) { http_response_code(403); exit('دسترسی به لاگ سامانه مجاز نیست.'); }
    redirect('index.php?page=activity-log&category=errors');
}

if ($page === 'activity-log') {
    $logUser = require_login();
    $canViewActivityLogs = user_can_any($logUser, ['logs.activity', 'audit.view']);
    $canViewSystemLogs = user_can($logUser, 'logs.view');
    if (!$canViewActivityLogs && !$canViewSystemLogs) {
        http_response_code(403); exit('دسترسی به لاگ سامانه مجاز نیست.');
    }
    if (!$canViewActivityLogs && $canViewSystemLogs && (string) ($_GET['category'] ?? 'errors') !== 'errors') {
        redirect('index.php?page=activity-log&category=errors');
    }
    activity_logs_ensure();
    $category = valid_choice((string) ($_GET['category'] ?? 'all'), ['all', 'auth', 'admin', 'errors'], 'all');
    if ($canViewActivityLogs) {
        [$logWhere, $logParams] = activity_log_filters();
    } else {
        $logWhere = ' WHERE 1 = 0';
        $logParams = [];
    }
    $perPage = (in_array($category, ['all', 'errors'], true) && $canViewSystemLogs) ? 50 : 100;
    $logPage = max(1, (int) ($_GET['p'] ?? 1));
    $countQuery = db()->prepare('SELECT COUNT(*) FROM activity_logs l' . $logWhere);
    $countQuery->execute($logParams);
    $activityFilteredTotal = (int) $countQuery->fetchColumn();
    $systemRows = []; $systemFilteredTotal = 0; $systemTotal = 0; $systemWhere = ''; $systemParams = [];
    if (in_array($category, ['all', 'errors'], true) && user_can($logUser, 'logs.view')) {
        system_logs_ensure();
        $systemConditions = [];
        $systemLevel = valid_choice((string) ($_GET['level'] ?? ''), ['debug', 'info', 'warning', 'error'], '');
        if ($systemLevel !== '') { $systemConditions[] = 's.level = ?'; $systemParams[] = $systemLevel; }
        $systemSearch = trim((string) ($_GET['q'] ?? ''));
        if ($systemSearch !== '') {
            $systemConditions[] = '(s.context LIKE ? OR s.message LIKE ? OR s.meta_json LIKE ? OR s.request_uri LIKE ?)';
            foreach (range(1, 4) as $_) $systemParams[] = '%' . $systemSearch . '%';
        }
        $systemUserId = (int) ($_GET['user_id'] ?? 0);
        if ($systemUserId > 0) { $systemConditions[] = 's.user_id = ?'; $systemParams[] = $systemUserId; }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $dateText = trim((string) ($_GET[$key] ?? ''));
            $dateValue = $dateText !== '' ? jalali_input_to_gregorian($dateText, $key === 'to') : null;
            if ($dateValue !== null) { $systemConditions[] = 's.created_at ' . $operator . ' ?'; $systemParams[] = $dateValue; }
        }
        $systemWhere = $systemConditions ? ' WHERE ' . implode(' AND ', $systemConditions) : '';
        $systemCount = db()->prepare('SELECT COUNT(*) FROM system_logs s' . $systemWhere);
        $systemCount->execute($systemParams);
        $systemFilteredTotal = (int) $systemCount->fetchColumn();
        $systemTotal = (int) db()->query('SELECT COUNT(*) FROM system_logs')->fetchColumn();
    }
    $filteredTotal = $activityFilteredTotal + $systemFilteredTotal;
    $pages = max(1, (int) ceil(max($activityFilteredTotal, $systemFilteredTotal) / $perPage));
    $logPage = min($logPage, $pages);
    $offset = ($logPage - 1) * $perPage;
    $activityQuery = db()->prepare('SELECT * FROM activity_logs l' . $logWhere . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $activityQuery->execute($logParams);
    $activityRows = $activityQuery->fetchAll();
    if (in_array($category, ['all', 'errors'], true) && user_can($logUser, 'logs.view')) {
        $systemQuery = db()->prepare('SELECT s.*, u.username, u.full_name FROM system_logs s LEFT JOIN users u ON u.id = s.user_id' . $systemWhere . ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $systemQuery->execute($systemParams);
        $systemRows = $systemQuery->fetchAll();
    }
    $activityTotal = $canViewActivityLogs ? (int) db()->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() : 0;
    $activityUsers = $canViewActivityLogs ? db()->query('SELECT id, full_name, username FROM users ORDER BY full_name')->fetchAll() : [];
    $activityActions = $canViewActivityLogs ? db()->query('SELECT action_code, MAX(action_label) AS label, COUNT(*) AS total FROM activity_logs GROUP BY action_code ORDER BY total DESC LIMIT 200')->fetchAll() : [];
    $activityModules = $canViewActivityLogs ? db()->query("SELECT module, COUNT(*) AS total FROM activity_logs WHERE module IS NOT NULL AND module <> '' GROUP BY module ORDER BY total DESC")->fetchAll() : [];
    $retentionDays = activity_log_retention_days();
    $activityExported = user_can($logUser, 'logs.activity_export');
    $queryArgs = $_GET; unset($queryArgs['page'], $queryArgs['p']); $queryArgs['page']='activity-log';
    $filterUrl = 'index.php?' . http_build_query($queryArgs);
    $exportArgs = $queryArgs; unset($exportArgs['page']);
    $tabNames = ['all'=>'همه رویدادها','auth'=>'ورود و خروج','admin'=>'مدیریتی و تنظیمات','errors'=>'خطاها و تلاش‌های ناموفق'];
    render_header('لاگ سامانه', $logUser);
    echo '<section class="page-heading"><div><span class="eyebrow">ردیابی و ممیزی یکپارچه</span><h1>لاگ سامانه</h1><p>یک گزارش واحد از ورود و خروج، فعالیت کاربران، تغییرات مدیریتی و خطاها؛ ' . number_format($filteredTotal) . ' رکورد در این نما؛ ' . number_format($activityTotal + $systemTotal) . ' رویداد و خطای ثبت‌شده در مجموع.</p></div><div class="actions">' . ($activityExported ? '<a class="button secondary" href="index.php?action=export_activity_logs' . ($exportArgs ? '&' . e(http_build_query($exportArgs)) : '') . '">خروجی فعالیت کاربران (Excel)</a>' : '') . '<a class="button secondary" href="index.php?page=settings#activity-log-settings">تنظیم نگهداری</a></div></section>';
    echo '<nav class="log-tabs" aria-label="دسته‌بندی لاگ‌ها">';
    foreach($tabNames as $tab=>$label){ echo '<a class="' . ($category===$tab?'active':'') . '" href="index.php?page=activity-log&category=' . e($tab) . '">' . e($label) . '</a>'; }
    echo '</nav><section class="card filter-card"><form method="get"><input type="hidden" name="page" value="activity-log"><input type="hidden" name="category" value="' . e($category) . '"><div class="filter-grid">';
    if ($canViewSystemLogs && in_array($category, ['all', 'errors'], true)) {
        $selectedLevel = valid_choice((string) ($_GET['level'] ?? ''), ['debug', 'info', 'warning', 'error'], '');
        echo '<label>شدت خطا<select name="level"><option value="">همه سطح‌ها</option><option value="error"' . ($selectedLevel === 'error' ? ' selected' : '') . '>خطا</option><option value="warning"' . ($selectedLevel === 'warning' ? ' selected' : '') . '>هشدار</option><option value="info"' . ($selectedLevel === 'info' ? ' selected' : '') . '>اطلاعات</option><option value="debug"' . ($selectedLevel === 'debug' ? ' selected' : '') . '>اشکال‌زدایی</option></select></label>';
    }
    if ($canViewActivityLogs) {
        echo '<label>کاربر<select name="user_id"><option value="">همه کاربران</option>';
        foreach ($activityUsers as $option) echo '<option value="' . (int)$option['id'] . '"' . ((int)($_GET['user_id']??0)===(int)$option['id']?' selected':'') . '>' . e($option['full_name'].' ('.$option['username'].')') . '</option>';
        echo '</select></label><label>نوع عملیات<select name="action_code"><option value="">همه عملیات</option>';
        foreach($activityActions as $option) echo '<option value="'.e($option['action_code']).'"'.((string)($_GET['action_code']??'')===(string)$option['action_code']?' selected':'').'>'.e($option['label']?:$option['action_code']).' ('.(int)$option['total'].')</option>';
        echo '</select></label><label>بخش سامانه<select name="module"><option value="">همه بخش‌ها</option>';
        foreach($activityModules as $option) echo '<option value="'.e($option['module']).'"'.((string)($_GET['module']??'')===(string)$option['module']?' selected':'').'>'.e($option['module']).' ('.(int)$option['total'].')</option>';
        echo '</select></label>';
    }
    echo '<label>از تاریخ<input name="from" placeholder="۱۴۰۵/۰۷/۰۱" value="' . e($_GET['from'] ?? '') . '"></label><label>تا تاریخ<input name="to" placeholder="۱۴۰۵/۰۷/۳۰" value="' . e($_GET['to'] ?? '') . '"></label>' . ($canViewActivityLogs ? '<label>آی‌پی<input name="ip" placeholder="مثلاً 192.168.1." value="' . e($_GET['ip'] ?? '') . '"></label>' : '') . '<label class="full">جست‌وجوی متن<input type="search" name="q" placeholder="کاربر، عملیات، نام رایانه یا جزئیات" value="' . e($_GET['q'] ?? '') . '"></label></div><div class="actions"><button class="button" type="submit">اعمال فیلتر</button><a class="button secondary" href="index.php?page=activity-log&category=' . e($category) . '">پاک‌کردن فیلترها</a></div></form></section>';
    if (in_array($category, ['all', 'errors'], true) && user_can($logUser, 'logs.view')) {
        echo '<section class="card ticket-table audit-table unified-log-table system-error-table"><div class="log-section-heading"><h2>خطاها و هشدارهای فنی</h2><span class="muted">' . number_format($systemFilteredTotal) . ' مورد</span></div><div class="table-head"><span>زمان</span><span>کاربر</span><span>شدت و بخش</span><span>درخواست</span><span>پیام</span><span>جزئیات</span></div>';
        if ($systemRows) { foreach ($systemRows as $systemRow) {
            $systemMeta = activity_log_meta_lines(isset($systemRow['meta_json']) ? (string) $systemRow['meta_json'] : null);
            $systemDetails = $systemMeta ? '<details class="log-details"><summary>جزئیات فنی (' . count($systemMeta) . ')</summary><dl>' . implode('', array_map(static fn (array $line): string => '<div><dt>' . e((string) $line[0]) . '</dt><dd>' . e((string) $line[1]) . '</dd></div>', $systemMeta)) . '</dl></details>' : '<span class="muted">—</span>';
            echo '<div class="table-row"><span class="date-cell">' . e(persian_date($systemRow['created_at'])) . '</span><span>' . e($systemRow['full_name'] ?: $systemRow['username'] ?: 'کاربر ناشناس') . '</span><span><em class="log-level ' . e($systemRow['level']) . '">' . e(strtoupper((string) $systemRow['level'])) . '</em><br><small>' . e($systemRow['context'] ?: 'سامانه') . '</small></span><span class="muted">' . e($systemRow['request_uri'] ?: '—') . '</span><span>' . e($systemRow['message'] ?: '—') . '</span><span>' . $systemDetails . '</span></div>';
        }} else { echo '<div class="empty-state"><p>خطای فنی با فیلترهای انتخاب‌شده پیدا نشد.</p></div>'; }
        echo '</section>';
    }
    echo '<section class="card ticket-table audit-table unified-log-table"><div class="log-section-heading"><h2>' . ($category === 'errors' ? 'ورودهای ناموفق و تلاش‌های مسدودشده' : 'فعالیت کاربران و ممیزی') . '</h2><span class="muted">' . number_format($activityFilteredTotal) . ' مورد</span></div><div class="table-head"><span>زمان</span><span>کاربر</span><span>رویداد</span><span>مورد/بخش</span><span>نشانی کاربر</span><span>جزئیات قابل‌خواندن</span></div>';
    if($activityRows){ foreach($activityRows as $row){
        $metaLines=activity_log_meta_lines(isset($row['meta_json'])?(string)$row['meta_json']:null);
        $details=$metaLines?'<details class="log-details"><summary>جزئیات (' . count($metaLines) . ')</summary><dl>'.implode('',array_map(static fn(array $line):string=>'<div><dt>'.e((string)$line[0]).'</dt><dd>'.e((string)$line[1]).'</dd></div>',$metaLines)).'</dl></details>':'<span class="muted">بدون جزئیات افزوده</span>';
        $target=$row['target_label']?:($row['target_type']?($row['target_type'].($row['target_id']?' #'.$row['target_id']:'')):'—');
        $module=(string)($row['module']??'');
        echo '<div class="table-row"><span class="date-cell">'.e(persian_date($row['created_at'])).'</span><span><strong>'.e($row['full_name']?:$row['username']?:'سامانه/API').'</strong><br><small class="muted">'.e($row['username']?:'کاربر ناشناس').'</small><br><small class="muted">'.e(permission_roles()[(string)($row['role']??'')]??($row['role']?:'—')).'</small></span><span><strong>'.e($row['action_label']?:activity_action_label((string)$row['action_code'])).'</strong><br><small class="muted">'.e($row['action_code']).'</small></span><span>'.e($target).'<br><small class="muted">'.e($module!==''?$module:'—').'</small></span><span class="muted">'.e($row['ip_address']?:'—').'<br>'.e($row['computer_name']?:'نام رایانه نامشخص').'</span><span>'.$details.'</span></div>';
    }} else { echo '<div class="empty-state"><p>' . ($category === 'errors' && $systemRows ? 'تلاش ناموفق کاربری با این فیلتر ثبت نشده است.' : 'رکوردی با این فیلتر پیدا نشد؛ فیلترها را پاک کنید یا عبارت دیگری را جست‌وجو کنید.') . '</p></div>'; }
    echo '</section>';
    if($pages>1){ echo '<nav class="pagination" aria-label="صفحه‌بندی لاگ">'; for($i=max(1,$logPage-3);$i<=min($pages,$logPage+3);$i++){ $args=$queryArgs;$args['p']=$i; echo '<a class="'.($i===$logPage?'active':'').'" href="index.php?'.e(http_build_query($args)).'">'.number_format($i).'</a>'; } echo '</nav>'; }
    render_footer(); exit;
}

if ($page === 'backup') {
    $backupUser = require_permission('backup.view');
    // ساخت و بازیابی فقط با backup.manage؛ بقیه فهرست نسخه‌ها را می‌بینند.
    $backupInert = user_can($backupUser, 'backup.manage') ? '' : ' inert';
    $runs = backup_runs();
    $databaseName = (string) cfg('database.name', 'persian_ticketing');
    $dumpAvailable = backup_mysql_binary('mysqldump') !== null;
    $mysqlAvailable = backup_mysql_binary('mysql') !== null;
    render_header('پشتیبان‌گیری و بازیابی', $backupUser);
    echo '<section class="page-heading"><div><span class="eyebrow">Backup & Restore</span><h1>پشتیبان‌گیری و بازیابی</h1><p>این بخش فقط برای ادمین اصلی است و از اطلاعات دیتابیس و پیوست‌ها محافظت می‌کند.</p></div><span class="admin-badge">ادمین اصلی</span></section>';
    echo '<section class="settings-grid backup-grid"' . $backupInert . '><div class="card form-card"><h2>ساخت نسخه پشتیبان</h2><p class="muted">یک نسخه از دیتابیس و پوشه پیوست‌ها می‌سازد. قبل از هر بازیابی نیز یک نسخه ایمنی خودکار ساخته می‌شود.</p><p class="' . ($dumpAvailable ? 'alert success' : 'alert danger') . '">' . ($dumpAvailable ? 'ابزار mysqldump پیدا شد و پشتیبان‌گیری آماده است.' : 'mysqldump پیدا نشد؛ مسیر ITSM_MYSQLDUMP_PATH یا مسیر XAMPP را تنظیم کنید.') . '</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create_backup"><button class="button" type="submit" ' . ($dumpAvailable ? '' : 'disabled') . '>ساخت نسخه پشتیبان اکنون</button></form><small class="muted">مسیر ذخیره: ' . e(backup_root_path()) . '</small></div>';
    echo '<div class="card form-card"><h2>بازیابی نسخه پشتیبان</h2><p class="muted">فقط فایل SQL را انتخاب کنید؛ فایل ZIP پیوست‌ها اختیاری است. پیش از بازیابی، سامانه نسخه ایمنی می‌سازد.</p><p class="alert danger">بازیابی می‌تواند اطلاعات فعلی را جایگزین کند. ابتدا روی staging آزمایش کنید.</p><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="restore_backup"><input type="hidden" name="MAX_FILE_SIZE" value="268435456"><label class="file-input">فایل SQL<input type="file" name="sql_dump" accept=".sql,text/plain" required><small>حداکثر ۲۵۶ مگابایت</small></label><label class="file-input">آرشیو پیوست‌ها، اختیاری<input type="file" name="uploads_archive" accept=".zip,application/zip"><small>فقط ZIP ساخته‌شده توسط سامانه</small></label><label class="check-label"><input type="checkbox" name="restore_confirm" value="1" required> خطر جایگزینی اطلاعات فعلی را می‌پذیرم</label><button class="button danger-button" type="submit" ' . ($dumpAvailable && $mysqlAvailable ? '' : 'disabled') . '>بازیابی اطلاعات</button></form></div></section>';
    echo '<section class="card form-card"><h2>نسخه‌های موجود</h2><p class="muted">دانلود نسخه پشتیبان فقط برای ادمین اصلی فعال است. فایل تنظیمات شامل رمزها عمداً داخل این بسته‌ها قرار نمی‌گیرد.</p><div class="category-list backup-list">';
    foreach ($runs as $run) {
        $base = $run['name'] . '/';
        $sqlFile = backup_download_path($base . $databaseName . '.sql');
        $zipFile = backup_download_path($base . 'uploads.zip');
        $sqlAction = $sqlFile ? '<a class="button secondary" href="index.php?action=download_backup&file=' . rawurlencode($base . $databaseName . '.sql') . '">دریافت SQL</a>' : '<span class="muted">SQL ناقص</span>';
        $zipAction = $zipFile ? '<a class="button secondary" href="index.php?action=download_backup&file=' . rawurlencode($base . 'uploads.zip') . '">دریافت پیوست‌ها</a>' : '<span class="muted">ZIP موجود نیست</span>';
        echo '<div><span><strong>' . e($run['name']) . '</strong><small>' . e($run['created_at']) . ' • ' . e(number_format((float) $run['size'] / 1048576, 1)) . ' MB</small></span><span class="actions">' . $sqlAction . $zipAction . '</span></div>';
    }
    if (!$runs) {
        echo '<div class="empty-state"><h2>هنوز نسخه‌ای ثبت نشده است.</h2><p>اولین نسخه را از فرم بالا بسازید.</p></div>';
    }
    echo '</div></section>';
    render_footer();
    exit;
}

if ($page === 'search' && $user && !user_can($user, 'search.global')) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به این بخش مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه تماس بگیرید.</p></div></section>';
    render_footer();
    exit;
}
if ($page === 'search') {
    $searchUser = require_login();
    $searchTerm = trim((string) ($_GET['q'] ?? ''));
    // ۱.۳۷.۷ — جست‌وجو در «بخش‌ها و صفحه‌های سامانه» هم انجام می‌شود؛ مثلاً نوشتن «تردد» صفحه‌های
    // مرتبط با تردد را نشان می‌دهد. فقط صفحه‌هایی که کاربر اجازهٔ دیدنشان را دارد فهرست می‌شوند.
    $searchSections = global_search_sections(
        global_search_catalog(),
        $searchTerm,
        static function (array $section) use ($searchUser): bool {
            $special = (string) ($section['special'] ?? '');
            if ($special === 'food') {
                return function_exists('food_ticket_is_allowed') ? food_ticket_is_allowed($searchUser) : false;
            }
            if ($special === 'governance') {
                return is_staff_role((string) ($searchUser['role'] ?? '')) && user_can($searchUser, 'governance.manage');
            }
            $codes = [];
            if (isset($section['perm']) && (string) $section['perm'] !== '') {
                $codes[] = (string) $section['perm'];
            }
            foreach ((array) ($section['perms'] ?? []) as $code) {
                $codes[] = (string) $code;
            }
            return $codes === [] || user_can_any($searchUser, $codes);
        }
    );
    render_header('جست‌وجوی سراسری', $searchUser);
    echo '<section class="page-heading"><div><span class="eyebrow">جست‌وجوی سراسری</span><h1>نتایج جست‌وجو</h1><p>در صفحه‌ها، تیکت‌ها، شناسنامه‌ها، تردد، رسانه‌ها و دانش‌نامه جست‌وجو کنید.</p></div></section><form class="card filter-card global-search-page" method="get"><input type="hidden" name="page" value="search"><input name="q" required value="' . e($searchTerm) . '" placeholder="مثلاً تردد، فیش غذا، پشتیبان‌گیری ..."><button class="button" type="submit">جست‌وجو</button></form>';
    if ($searchTerm !== '') {
        $needles = array_values(array_unique(array_filter([$searchTerm, global_search_normalize($searchTerm)], static fn(string $v): bool => $v !== '')));
        [$searchScope, $searchParams] = ticket_scope($searchUser);
        $ticketGroups=[]; $ticketTerms=[];
        foreach($needles as $needle){$like='%'.$needle.'%';$ticketGroups[]='(t.subject LIKE ? OR t.description LIKE ? OR r.full_name LIKE ?)';array_push($ticketTerms,$like,$like,$like);}
        $ticketSql = 'SELECT t.id, t.subject, t.status, t.ticket_type, d.name AS department_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id ' . ($searchScope ?: 'WHERE 1 = 1') . ' AND (' . implode(' OR ', $ticketGroups) . ') ORDER BY t.updated_at DESC LIMIT 30';
        $ticketQuery = db()->prepare($ticketSql); $ticketQuery->execute(array_merge($searchParams,$ticketTerms)); $ticketRows=$ticketQuery->fetchAll();
        [$assetSearchScope, $assetSearchParams] = asset_scope($searchUser);
        $assetProfileColumns = db_table_columns('asset_profiles');
        $assetProfileJoin = $assetProfileColumns ? ' LEFT JOIN asset_profiles p ON p.asset_id = a.id ' : ' ';
        $assetSearchFields = ['a.asset_tag','a.hostname','a.serial_number'];
        if ($assetProfileColumns) {
            foreach (['record_number','owner_full_name','registrar_name','unit_name','personnel_number','user_login','computer_type'] as $profileField) {
                if (isset($assetProfileColumns[$profileField])) $assetSearchFields[] = 'p.' . $profileField;
            }
        }
        $assetGroups=[];$assetTerms=[];
        foreach($needles as $needle){$like='%'.$needle.'%';$assetGroups[]='('.implode(' OR ',array_map(static fn(string $field):string=>$field.' LIKE ?',$assetSearchFields)).')';foreach($assetSearchFields as $_field)$assetTerms[]=$like;}
        $ownerSelect = isset($assetProfileColumns['owner_full_name']) ? 'p.owner_full_name' : 'NULL';
        $recordSelect = isset($assetProfileColumns['record_number']) ? 'p.record_number' : 'NULL';
        $assetSql = 'SELECT a.id, a.asset_tag, a.hostname, a.serial_number, d.name AS department_name, ' . $ownerSelect . ' AS profile_owner, ' . $recordSelect . ' AS profile_record FROM assets a LEFT JOIN departments d ON d.id = a.department_id ' . $assetProfileJoin . ($assetSearchScope ?: 'WHERE 1 = 1') . ' AND (' . implode(' OR ', $assetGroups) . ') ORDER BY a.updated_at DESC LIMIT 30';
        $assetQuery = db()->prepare($assetSql); $assetQuery->execute(array_merge($assetSearchParams,$assetTerms)); $assetRows=$assetQuery->fetchAll();
        $articleGroups=[];$articleTerms=[];
        foreach($needles as $needle){$like='%'.$needle.'%';$articleGroups[]='(title LIKE ? OR body LIKE ? OR category LIKE ?)';array_push($articleTerms,$like,$like,$like);}
        $articleQuery = db()->prepare('SELECT id, title, category, updated_at FROM knowledge_articles WHERE is_published = 1 AND (' . implode(' OR ', $articleGroups) . ') ORDER BY updated_at DESC LIMIT 30');
        $articleQuery->execute($articleTerms); $articleRows=$articleQuery->fetchAll();
        $trafficRows = []; $mediaRows = [];
        if (user_can($searchUser, 'traffic.view')) {
            try {
                $trafficGroups=[]; $trafficTerms=[];
                foreach($needles as $needle){$like='%'.$needle.'%';$trafficGroups[]='(v.serial_no LIKE ? OR v.full_name LIKE ? OR v.national_code LIKE ? OR v.phone LIKE ? OR v.company LIKE ? OR v.meeting_with LIKE ? OR v.description LIKE ?)';array_push($trafficTerms,$like,$like,$like,$like,$like,$like,$like);}
                $trafficQuery=db()->prepare('SELECT v.id,v.serial_no,v.visit_date,v.full_name,v.company,v.meeting_with,v.entry_time FROM traffic_visits v WHERE '.implode(' OR ',$trafficGroups).' ORDER BY v.visit_date DESC,v.id DESC LIMIT 20');
                $trafficQuery->execute($trafficTerms);$trafficRows=$trafficQuery->fetchAll();
            } catch (Throwable) { $trafficRows=[]; }
        }
        if (user_can($searchUser, 'cddvd.view')) {
            try {
                [$mediaScope,$mediaScopeParams]=cd_dvd_scope($searchUser,'r');
                $mediaGroups=[];$mediaTerms=[];
                foreach($needles as $needle){$like='%'.$needle.'%';$mediaGroups[]='(r.serial LIKE ? OR r.info_type LIKE ? OR r.info_desc LIKE ? OR r.receiver LIKE ? OR r.receiver_unit LIKE ? OR r.brought_by LIKE ? OR r.exit_sheet LIKE ? OR r.note LIKE ?)';array_push($mediaTerms,$like,$like,$like,$like,$like,$like,$like,$like);}
                $mediaSql='SELECT r.id,r.serial,r.media,r.direction,r.info_type,r.info_desc,r.date_str,r.receiver,r.brought_by FROM cd_dvd_records r '.($mediaScope!==''?' '.$mediaScope:'WHERE 1=1').' AND ('.implode(' OR ',$mediaGroups).') ORDER BY r.sort_key DESC,r.id DESC LIMIT 20';
                $mediaQuery=db()->prepare($mediaSql);$mediaQuery->execute(array_merge($mediaScopeParams,$mediaTerms));$mediaRows=$mediaQuery->fetchAll();
            } catch (Throwable) { $mediaRows=[]; }
        }
        $foundAny = (bool) ($searchSections || $ticketRows || $assetRows || $articleRows || $trafficRows || $mediaRows);
        if (!$foundAny) {
            echo '<section class="card search-not-found"><span class="search-empty-icon">⌕</span><h2>یافت نشد</h2><p>برای «' . e($searchTerm) . '» در صفحه‌ها و اطلاعات قابل‌دسترسی سامانه نتیجه‌ای پیدا نشد.</p><small>املای واژه را بررسی کنید یا عبارت دیگری جست‌وجو کنید.</small></section>';
        } else {
            // کارت «بخش‌ها و صفحه‌ها» اکنون فقط هنگام وجود نتیجه نمایش داده می‌شود.
            echo '<section class="search-results">';
            if($searchSections){echo '<div class="card"><h2>صفحه‌ها و بخش‌های مرتبط <span class="muted">('.count($searchSections).')</span></h2><div class="category-list">';foreach($searchSections as $result){echo '<a class="search-result" href="'.e((string)$result['url']).'"><strong>'.e((string)$result['title']).'</strong><small class="muted">باز کردن این بخش</small></a>'; }echo '</div></div>';}
            if($ticketRows){echo '<div class="card"><h2>تیکت‌ها <span class="muted">('.count($ticketRows).')</span></h2><div class="category-list">';foreach($ticketRows as $result){echo '<a class="search-result" href="index.php?page=ticket&id='.(int)$result['id'].'"><strong>'.e(ticket_number((int)$result['id'])).' • '.e($result['subject']).'</strong><small>'.e($result['department_name']?:'بدون معاونت').' • '.e(ticket_type_label($result['ticket_type'])).' • '.e(status_label($result['status'])).'</small></a>'; }echo '</div></div>';}
            if($assetRows){echo '<div class="card"><h2>شناسنامه‌ها و دارایی‌ها <span class="muted">('.count($assetRows).')</span></h2><div class="category-list">';foreach($assetRows as $result){echo '<a class="search-result" href="index.php?page=inventory&id='.(int)$result['id'].'"><strong>'.asset_tag_label($result['asset_tag'],$result['hostname']).' • '.e(asset_display_name($result['hostname'])).'</strong><small>'.e($result['profile_record']?:$result['serial_number']?:'شناسه ثبت نشده').' • '.e($result['profile_owner']?:$result['department_name']?:'بدون مالک ثبت‌شده').'</small></a>'; }echo '</div></div>';}
            if($articleRows){echo '<div class="card"><h2>دانش‌نامه <span class="muted">('.count($articleRows).')</span></h2><div class="category-list">';foreach($articleRows as $result){echo '<a class="search-result" href="index.php?page=knowledge&id='.(int)$result['id'].'"><strong>'.e($result['title']).'</strong><small>'.e($result['category']?:'راهنما').' • '.e(jalali_date($result['updated_at'],false)).'</small></a>'; }echo '</div></div>';}
            if($trafficRows){echo '<div class="card"><h2>سوابق تردد <span class="muted">('.count($trafficRows).')</span></h2><div class="category-list">';foreach($trafficRows as $result){echo '<a class="search-result" href="index.php?page=traffic-control&tab=history&search='.rawurlencode($searchTerm).'"><strong>'.e($result['full_name']).' • '.e($result['serial_no']).'</strong><small>'.e($result['visit_date']).' • '.e($result['company']?:'بدون شرکت').' • '.e($result['meeting_with']?:'بدون مقصد').'</small></a>'; }echo '</div></div>';}
            if($mediaRows){echo '<div class="card"><h2>سوابق CD/DVD <span class="muted">('.count($mediaRows).')</span></h2><div class="category-list">';foreach($mediaRows as $result){echo '<a class="search-result" href="index.php?page=cd-dvd&tab=history&search='.rawurlencode($searchTerm).'"><strong>'.e($result['media']).' • '.e($result['serial']).' • '.e($result['info_type']).'</strong><small>'.e($result['date_str']).' • '.e($result['direction']==='IN'?'ورود':'خروج').' • '.e($result['receiver']?:$result['brought_by']?:'').'</small></a>'; }echo '</div></div>';}
            echo '</section>';
        }
    }
    render_footer();
    exit;
}

if ($page === 'queue') {
    $queueUser = require_permission('queue.view');
    $queueName = valid_choice((string) ($_GET['queue'] ?? 'all'), ['all', 'unassigned', 'overdue', 'mine', 'waiting_user', 'resolved'], 'all');
    [$queueScope, $queueParams] = ticket_scope($queueUser);
    $queueConditions = $queueScope !== '' ? [substr($queueScope, 6)] : [];
    if ($queueName === 'unassigned') { $queueConditions[] = 't.assigned_to IS NULL'; }
    if ($queueName === 'overdue') { $queueConditions[] = 't.due_at IS NOT NULL AND t.due_at < NOW() AND t.status NOT IN ("resolved", "closed")'; }
    if ($queueName === 'mine') { $queueConditions[] = 't.assigned_to = ?'; $queueParams[] = (int) $queueUser['id']; }
    if ($queueName === 'waiting_user') { $queueConditions[] = 't.status = "waiting_user"'; }
    if ($queueName === 'resolved') { $queueConditions[] = 't.status = "resolved"'; }
    $queueWhere = $queueConditions ? 'WHERE ' . implode(' AND ', $queueConditions) : '';
        $queueQuery = db()->prepare('SELECT t.*, d.name AS department_name, r.full_name AS requester_name, a.full_name AS assignee_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assigned_to ' . $queueWhere . ' ORDER BY CASE WHEN (t.status NOT IN ("resolved", "closed") AND (t.due_at < NOW() OR t.ola_response_due_at < NOW() OR t.ola_due_at < NOW())) THEN 0 ELSE 1 END, FIELD(t.priority, "critical", "urgent", "normal"), t.created_at ASC LIMIT 300');
    $queueQuery->execute($queueParams);
    render_header('صف کاری', $queueUser);
     echo '<section class="page-heading"><div><span class="eyebrow">صف مرکزی خدمات</span><h1>صف کار واحد رسیدگی</h1><p>تیکت‌های هر حوزه جدا هستند؛ موارد خارج از SLA/OLA و اولویت‌های حیاتی بالاتر قرار می‌گیرند.</p></div><a class="button secondary" href="index.php?page=reports">گزارش کامل</a></section><div class="queue-tabs"><a class="' . ($queueName === 'all' ? 'active' : '') . '" href="index.php?page=queue&queue=all">همه</a><a class="' . ($queueName === 'unassigned' ? 'active' : '') . '" href="index.php?page=queue&queue=unassigned">بدون مسئول</a><a class="' . ($queueName === 'overdue' ? 'active' : '') . '" href="index.php?page=queue&queue=overdue">خارج از SLA/OLA</a><a class="' . ($queueName === 'mine' ? 'active' : '') . '" href="index.php?page=queue&queue=mine">مسئول من</a><a class="' . ($queueName === 'waiting_user' ? 'active' : '') . '" href="index.php?page=queue&queue=waiting_user">انتظار کاربر</a><a class="' . ($queueName === 'resolved' ? 'active' : '') . '" href="index.php?page=queue&queue=resolved">حل‌شده</a></div><form method="post"><section class="card ticket-table queue-table"><div class="table-head"><span>انتخاب / تیکت</span><span>معاونت</span><span>ثبت‌کننده</span><span>مسئول</span><span>وضعیت</span></div>';
    foreach ($queueQuery->fetchAll() as $queueTicket) {
        echo '<label class="table-row queue-row"><span class="ticket-title"><input type="checkbox" name="ticket_ids[]" value="' . (int) $queueTicket['id'] . '"><b>' . e(ticket_number((int) $queueTicket['id'])) . '</b><a href="index.php?page=ticket&id=' . (int) $queueTicket['id'] . '"><strong>' . e($queueTicket['subject']) . '</strong></a><small>' . e(ticket_type_label($queueTicket['ticket_type'])) . ' • ' . e(priority_label($queueTicket['priority'])) . '</small></span><span>' . e($queueTicket['department_name'] ?: 'بدون معاونت') . '</span><span>' . e($queueTicket['requester_name']) . '</span><span>' . e($queueTicket['assignee_name'] ?: 'بدون مسئول') . '</span><span><em class="status ' . e($queueTicket['status']) . '">' . e(status_label($queueTicket['status'])) . '</em></span></label>';
        }
    echo '</section><div class="bulk-actions"><input type="hidden" name="action" value="bulk_update_tickets">' . csrf_field() . '<select name="bulk_status"><option value="in_progress">در حال انجام</option><option value="waiting_user">در انتظار پاسخ کاربر</option><option value="resolved">حل‌شده</option></select><button class="button" type="submit">اعمال روی انتخاب‌ها</button></div></form>';
    // اسکریپت درون‌صفحه: تضمین کارکرد سربرگ‌ها و مودال‌ها مستقل از کش app.js

    render_footer();
    exit;
}

if ($page === 'holidays') {
    // فقط کاربر با holidays.manage می‌تواند تعطیلی ثبت/حذف کند؛ بقیه فهرست را فقط می‌بینند.
    $holidayInert = user_can($user, 'holidays.manage') ? '' : ' inert';
    $holidayAdmin = require_permission('holidays.view');
    $holidayQuery = db()->query('SELECT h.*, u.full_name FROM holidays h LEFT JOIN users u ON u.id = h.created_by ORDER BY h.holiday_date');
    render_header('تقویم SLA', $holidayAdmin);
     echo '<section class="page-heading"><div><span class="eyebrow">تقویم کاری</span><h1>تعطیلات رسمی و توقف SLA</h1><p>پنج‌شنبه و جمعه تعطیل ثابت هستند؛ سایر تعطیلات را با تقویم شمسی انتخاب کنید.</p></div></section><div class="holiday-guard"' . $holidayInert . '><section class="card form-card"><h2>افزودن تعطیلی</h2><form method="post"><input type="hidden" name="action" value="add_holiday">' . csrf_field() . '<div class="form-grid"><label class="date-picker-field">تاریخ جلالی<div class="jalali-picker" data-jalali-picker><input id="holiday-date" name="holiday_date" required autocomplete="off" placeholder="۱۴۰۵/۰۱/۰۱"><button type="button" class="calendar-trigger" aria-label="بازکردن تقویم">▦</button><div class="jalali-calendar" hidden></div></div><small class="field-help">پنج‌شنبه و جمعه در تقویم غیرفعال هستند و خودکار تعطیل محسوب می‌شوند.</small></label><label>عنوان تعطیلی<input name="holiday_title" required placeholder="نوروز"></label></div><button class="button" type="submit">افزودن به تقویم</button></form><div class="fixed-holidays"><span class="tag">تعطیلی ثابت</span><b>پنج‌شنبه و جمعه</b><small>در محاسبه SLA به‌صورت خودکار از روزهای کاری حذف می‌شوند.</small></div><hr><h2>تقویم سالانه — تیک روز تعطیل</h2>
<p class="muted">روی هر روز کلیک کنید تا به‌عنوان تعطیل ثبت یا حذف شود. رنگ <strong style="color:#dc2626">قرمز</strong> = تعطیل. پنج‌شنبه و جمعه ثابت‌اند.</p>
<div class="year-holiday-board" data-year-holiday-board>
<div class="board-head"><label>سال جلالی <select data-year-board-year">' .
implode('', array_map(static fn ($y) => '<option value="' . $y . '"' . ($y === (int) (gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'))[0]) ? ' selected' : '') . '>' . $y . '</option>', range(1400, 1415))) .
'</select></label></div>
<div class="year-grid" data-year-board-grid></div>
<form method="post" data-toggle-holiday class="hidden-toggle" style="position:absolute;left:-9999px">' . csrf_field() . '<input type="hidden" name="action" value="toggle_holiday"><input type="hidden" name="holiday_date" value=""><input type="hidden" name="holiday_title" value="تعطیل رسمی"></form>
</div>
<hr><h2>ورود تقویم سالانه</h2><p class="muted">فرمت‌های استاندارد CSV و ICS از منابع تقویم قابل استفاده‌اند. در CSV ستون‌های date و title یا ردیف‌های تاریخ و عنوان را وارد کنید؛ تاریخ جلالی ۱۴۰۵/۰۱/۰۱ و میلادی 2026-03-21 هر دو پذیرفته می‌شوند. پنج‌شنبه و جمعه از ورود فایل نیز حذف می‌شوند.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import_holidays">' . csrf_field() . '<label class="file-input">فایل تقویم<input type="file" name="calendar_file" accept=".csv,.ics,.txt,text/csv,text/calendar" required><small>حداکثر ۲ مگابایت؛ تاریخ‌های تکراری به‌روزرسانی می‌شوند.</small></label><button class="button secondary" type="submit">واردکردن تقویم</button></form></section><section class="card category-list holiday-list">';
    foreach ($holidayQuery->fetchAll() as $holiday) {
        echo '<div><span><strong>' . e(jalali_date($holiday['holiday_date'], false)) . ' • ' . e($holiday['title']) . '</strong><small>ثبت‌کننده: ' . e($holiday['full_name'] ?: 'سامانه') . '</small></span><form method="post" data-confirm="این روز تعطیل حذف شود؟">' . csrf_field() . '<input type="hidden" name="action" value="delete_holiday"><input type="hidden" name="holiday_id" value="' . (int) $holiday['id'] . '"><button class="mini-button danger" type="submit">حذف</button></form></div>';
    }
    echo '</div></section>';
    render_footer();
    exit;
}

if ($page === 'new-ticket' && $user && !user_can($user, 'ticket.create')) {
    http_response_code(403);
    render_header('عدم دسترسی', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">عدم دسترسی</span><h1>دسترسی به این بخش مجاز نیست</h1><p>برای دریافت دسترسی با ادمین اصلی سامانه تماس بگیرید.</p></div></section>';
    render_footer();
    exit;
}
if ($page === 'new-ticket') {
    $cdDvdRequestRecord = null;
    $subjectDefault = '';
    $descriptionDefault = '';
    if (($_GET['request'] ?? '') === 'cd_dvd_edit') {
        $candidate = cd_dvd_fetch_record((int) ($_GET['record_id'] ?? 0));
        if ($candidate && !cd_dvd_can_edit_records($user) && cd_dvd_can_view_record($candidate, $user)) {
            $cdDvdRequestRecord = $candidate;
            $subjectDefault = 'درخواست اصلاح رکورد CD/DVD ' . $candidate['media'] . ' ' . $candidate['serial'];
            $descriptionDefault = "لطفاً رکورد CD/DVD زیر را بررسی و در صورت تأیید اصلاح کنید.\n\n"
                . 'شناسه رکورد: ' . $candidate['id'] . "\n"
                . 'رسانه: ' . $candidate['media'] . "\n"
                . 'شماره: ' . $candidate['serial'] . "\n"
                . 'جهت: ' . ($candidate['direction'] === 'IN' ? 'ورود' : 'خروج') . "\n"
                . 'تاریخ: ' . $candidate['date_str'] . "\n"
                . 'شرح فعلی: ' . $candidate['info_desc'] . "\n\n"
                . 'شرح اصلاح موردنیاز: ';
        }
    }
    ticket_service_ensure_schema();
    $categories = db()->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY service_group, name')->fetchAll();
    $allAssets = db()->query('SELECT id, asset_tag, hostname, owner_user_id, ip_address FROM assets ORDER BY hostname')->fetchAll();
    $clientIp = inventory_client_ip();
    $clientAssetId = 0;
    $ownAssets = [];
    $otherAssets = [];
    foreach ($allAssets as $assetRow) {
        $isOwner = (int) $assetRow['owner_user_id'] === (int) $user['id'];
        $isHere = $clientIp !== '' && trim((string) $assetRow['ip_address']) === $clientIp;
        if ($isHere && !$clientAssetId) { $clientAssetId = (int) $assetRow['id']; }
        if ($isOwner || $isHere) { $ownAssets[] = $assetRow; } else { $otherAssets[] = $assetRow; }
    }
    $userAssets = array_merge($ownAssets, $otherAssets);
    $autoAssetId = $clientAssetId ?: (count($userAssets) === 1 ? (int) $userAssets[0]['id'] : 0);
    $departments = [];
    // معاونت‌های سازمانی از چارت سازمانی (org_units) خوانده می‌شوند و برای همهٔ نقش‌ها
    // نمایش داده می‌شوند تا تیکت به معاونت انتخاب‌شده مسیردهی شود.
    org_ensure_schema();
    org_ticket_schema_ensure();
    $departments = org_deputy_units();
    ticket_service_ensure_schema();
    $services = db()->query('SELECT id, name, code, service_group, requires_asset, default_priority, default_ticket_type, category_id FROM service_catalog WHERE is_active = 1 ORDER BY service_group, name')->fetchAll();
    $cdDvdServiceId = 0;
    if ($cdDvdRequestRecord) {
        $cdDvdServiceQuery = db()->prepare("SELECT id FROM service_catalog WHERE code = 'SUP-CDDVD' AND is_active = 1 LIMIT 1");
        $cdDvdServiceQuery->execute();
        $cdDvdServiceId = (int) ($cdDvdServiceQuery->fetchColumn() ?: 0);
    }
    $serviceFields = [];
    if ($services) {
        $serviceFieldQuery = db()->prepare('SELECT * FROM service_catalog_fields WHERE service_id = ? ORDER BY sort_order, id');
        foreach ($services as $service) {
            $serviceFieldQuery->execute([(int) $service['id']]);
            $serviceFields[(int) $service['id']] = $serviceFieldQuery->fetchAll();
        }
    }
    // ۱.۳۷.۲ (درخواست کاربر): فرم ثبت تیکت همیشه با حوزهٔ «خدمات کامپیوتری و IT» باز می‌شود.
    // تنها مسیر CD/DVD (که رسیدگی‌اش در پشتیبانی است) روی «پشتیبانی» می‌ماند.
    $ticketDefaultGroup = $cdDvdRequestRecord ? 'support' : 'it';
    render_header('ثبت تیکت', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">درخواست جدید</span><h1>ثبت تیکت</h1><p>ابتدا حوزه خدمت را انتخاب کنید تا فرم و مسیر رسیدگی درست نمایش داده شود.</p></div><a class="button secondary" href="index.php">بازگشت به داشبورد</a></section><section class="card form-card"><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="create_ticket">' . ($cdDvdRequestRecord ? '<input type="hidden" name="cd_dvd_record_id" value="' . (int) $cdDvdRequestRecord['id'] . '">' : '');
    echo '<div class="ticket-number-box" data-next-it="' . e(ticket_number_peek('IT')) . '" data-next-support="' . e(ticket_number_peek('SUP')) . '"><span>شماره تیکت:</span><strong id="ticket-number-preview">' . e($ticketDefaultGroup === 'support' ? ticket_number_peek('SUP') : ticket_number_peek('IT')) . '</strong></div>';
    echo '<div class="form-grid"><label class="full">موضوع درخواست<input name="subject" required maxlength="255" value="' . e($subjectDefault) . '" placeholder="مثلاً: اختلال در اتصال به پرینتر واحد مالی"></label><label>حوزه خدمت<div class="group-combo"><label class="group-option"><input type="radio" name="service_group" value="it"' . ($ticketDefaultGroup === 'it' ? ' checked' : '') . '><span>خدمات کامپیوتری و IT</span></label><label class="group-option"><input type="radio" name="service_group" value="support"' . ($ticketDefaultGroup === 'support' ? ' checked' : '') . '><span>خدمات پشتیبانی</span></label></div></label><input type="hidden" name="category_id" id="category-selector" value=""><label>خدمت از کاتالوگ<select name="service_id" id="service-selector" required><option value="">انتخاب کنید…</option>';
    foreach ($services as $service) {
        $selected = ($cdDvdServiceId > 0 && (int) $service['id'] === $cdDvdServiceId) ? ' selected' : '';
        echo '<option value="' . (int) $service['id'] . '" data-service-group="' . e($service['service_group']) . '" data-category-id="' . (int) ($service['category_id'] ?? 0) . '" data-requires-asset="' . (int) $service['requires_asset'] . '"' . $selected . '>' . e($service['name']) . '</option>';
    }
    echo '</select><small class="field-help">ابتدا حوزه و سپس خدمت را انتخاب کنید؛ دسته‌بندی، اولویت و مسیر رسیدگی خودکار تنظیم می‌شود.</small></label>';
    if ($departments) {
        $myUnitId = function_exists('user_org_unit_id') ? user_org_unit_id($user) : 0;
        $myUnitIsDeputy = false;
        foreach ($departments as $department) {
            if ((int) $department['id'] === $myUnitId) {
                $myUnitIsDeputy = true;
                break;
            }
        }
        echo '<label>معاونت سازمانی درخواست‌کننده<select name="requesting_unit_id" required><option value="">انتخاب کنید…</option>';
        foreach ($departments as $department) {
            echo '<option value="' . (int) $department['id'] . '" ' . ((int) $department['id'] === $myUnitId ? 'selected' : '') . '>' . e($department['name']) . '</option>';
        }
        echo '</select><small class="field-help">' . ($myUnitIsDeputy ? 'معاونت شما به‌صورت پیش‌فرض انتخاب شده است؛ ' : '') . 'تیکت به معاونت انتخاب‌شده ارجاع داده می‌شود.</small></label>';
    } else {
        echo '<label>معاونت سازمانی درخواست‌کننده<input value="هیچ معاونتی در چارت سازمانی تعریف نشده است" readonly></label>';
    }
     echo '<label>اولویت<select name="priority"><option value="">پیش‌فرض خدمت</option><option value="normal">عادی</option><option value="urgent">فوری</option><option value="critical">حیاتی</option></select></label><label class="ticket-asset-field" data-asset-combo data-asset-required-group="it">سیستم مرتبط <span class="auto-label" data-asset-label>برای خدمات کامپیوتری الزامی</span><input type="text" id="asset-search" class="asset-search" placeholder="جستجوی سیستم (نام رایانه، شماره اموال یا پلاک)" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="asset-results" aria-required="true"><select name="asset_id" id="asset-selector" class="asset-native" tabindex="-1" aria-hidden="true"><option value="">بدون سیستم خاص / انتخاب نشده</option>';
     foreach ($userAssets as $asset) {
          $isCurrent = (int) $asset['id'] === $clientAssetId;
          $tagText = asset_tag_label($asset['asset_tag'], $asset['hostname']);
          if (asset_is_auto_domain_tag($asset['asset_tag'])) { $tagText = ''; }
          echo '<option value="' . (int) $asset['id'] . '" ' . ((int) $asset['id'] === $autoAssetId ? 'selected' : '') . '>' . e(asset_display_name($asset['hostname']) . ($tagText !== '' ? ' - ' . $tagText : '') . ($isCurrent ? ' (سیستم فعلی)' : '')) . '</option>';
     }
       echo '</select><div class="asset-results" id="asset-results" role="listbox" hidden></div><small class="field-help">برای خدمات کامپیوتری، انتخاب سیستم <b>الزامی</b> است. نام رایانه، شماره اموال یا پلاک را بنویسید و از فهرست زیر انتخاب کنید (اگر فقط یک نتیجه بماند، با Enter همان انتخاب می‌شود). اگر مشکل مربوط به رایانهٔ دیگری است، همان سیستم را جست‌وجو کنید.</small><small class="field-error" data-asset-error hidden></small></label><label class="support-field">محل خدمت / اتاق<span class="auto-label">برای پشتیبانی الزامی</span><input name="support_location" placeholder="ساختمان، طبقه، اتاق یا محل دقیق"></label><label class="support-field">تجهیز یا مورد درخواست<textarea name="support_equipment" rows="2" placeholder="مثلاً صندلی، میز، پرینتر عمومی یا تجهیزات اتاق"></textarea></label>' . ((is_staff_role($user['role']) || is_it_agent($user)) ? '<label>ارجاع به تیکت قبلی (اختیاری، کارشناسان)<input type="number" name="parent_ticket_id" placeholder="شناسه داخلی تیکت"></label>' : '') . '<label class="full">شرح کامل مشکل<textarea name="description" required rows="8" placeholder="چه اتفاقی افتاده؟ از چه زمانی؟ چه پیامی می‌بینید؟">' . e($descriptionDefault) . '</textarea></label>';
      foreach ($serviceFields as $serviceId => $fields) {
          if (!$fields) { continue; }
          echo '<fieldset class="service-fields" data-service-fields="' . $serviceId . '"><legend>اطلاعات تکمیلی خدمت</legend><div class="form-grid">';
          foreach ($fields as $field) {
              $required = (int) $field['is_required'] === 1 ? ' required' : '';
              $name = 'custom[' . e($field['field_key']) . ']';
              $options = json_decode((string) $field['options_json'], true) ?: [];
              if ($field['field_type'] === 'textarea') {
                  echo '<label class="full">' . e($field['label']) . '<textarea name="' . $name . '" rows="3"' . $required . '></textarea></label>';
              } elseif ($field['field_type'] === 'select') {
                  echo '<label>' . e($field['label']) . '<select name="' . $name . '"' . $required . '><option value="">انتخاب کنید</option>';
                  foreach ($options as $option) { echo '<option value="' . e($option) . '">' . e($option) . '</option>'; }
                  echo '</select></label>';
              } else {
                   $inputType = $field['field_type'] === 'number' ? 'number' : 'text';
                   $placeholder = $field['field_type'] === 'date' ? '۱۴۰۵/۰۱/۰۱' : '';
                   echo '<label>' . e($field['label']) . '<input type="' . $inputType . '" name="' . $name . '" placeholder="' . $placeholder . '"' . $required . '></label>';
              }
          }
          echo '</div></fieldset>';
      }
      echo '<label class="full file-input">پیوست اختیاری<input type="file" name="attachment"><small>حداکثر ۸ مگابایت؛ PDF، تصویر، Word، Excel، TXT و ZIP</small></label></div><div class="actions"><button class="button" type="submit">ثبت درخواست</button></div></form></section>';
    render_footer();
    exit;
}

if ($page === 'ticket') {
    $ticket = fetch_ticket((int) ($_GET['id'] ?? 0));
    if (!$ticket || !can_view_ticket($ticket, $user)) {
        http_response_code(404);
        render_header('تیکت پیدا نشد', $user);
        echo '<section class="empty-state"><div class="empty-icon">؟</div><h2>تیکت پیدا نشد</h2><a class="button" href="index.php">بازگشت</a></section>';
        render_footer();
        exit;
    }
    $messagesQuery = db()->prepare('SELECT m.*, u.full_name, u.role FROM ticket_messages m JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.created_at');
    $messagesQuery->execute([$ticket['id']]);
    $messages = $messagesQuery->fetchAll();
    $attachments = [];
    if ($messages) {
        $ids = array_column($messages, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $attachmentQuery = db()->prepare('SELECT * FROM ticket_attachments WHERE message_id IN (' . $marks . ') ORDER BY id');
        $attachmentQuery->execute($ids);
        foreach ($attachmentQuery->fetchAll() as $attachment) {
            $attachments[(int) $attachment['message_id']][] = $attachment;
        }
    }
    $staff = is_staff_role($user['role']);
    $canManageTicket = $staff;
    $isRequester = (int) $ticket['requester_id'] === (int) $user['id'];
    $requesterView = $isRequester && !$staff;
    $handlerLevel = ticket_handler_level($ticket, $user);
    $canAssignTicket = $handlerLevel === 'manager';
    $requiresRating = $isRequester && in_array($ticket['status'], ['resolved', 'closed'], true) && !ticket_rating_is_current($ticket);
    render_header('جزئیات ' . ticket_number((int) $ticket['id']), $user);
     echo '<section class="page-heading"><div><a class="back-link" href="index.php">← بازگشت به فهرست</a><h1>' . e($ticket['subject']) . '</h1><div class="ticket-meta"><span>' . ticket_number((int) $ticket['id']) . '</span><span>' . persian_date($ticket['created_at']) . '</span><span class="tag">' . e(ticket_type_label((string) $ticket['ticket_type'])) . '</span>' . ($ticket['service_name'] ? '<span class="tag">' . e($ticket['service_name']) . '</span>' : '') . '<span class="status ' . e($ticket['status']) . '">' . e(status_label($ticket['status'])) . '</span><span class="priority ' . e($ticket['priority']) . '">' . e(priority_label($ticket['priority'])) . '</span></div></div></section><div class="ticket-layout"><section class="conversation">';
    foreach ($messages as $message) {
        if ((int) $message['is_internal'] === 1 && !$staff) {
            continue;
        }
         $messageRole = ((int) $message['is_internal'] === 1) ? 'internal' : (is_staff_role($message['role']) ? 'agent' : ($message['role'] === 'supervisor' ? 'supervisor' : 'user'));
         echo '<article class="message ' . $messageRole . ' ' . ((int) $message['user_id'] === (int) $user['id'] ? 'mine' : '') . '"><div class="message-head"><strong>' . e($message['full_name']) . '</strong><span>' . persian_date($message['created_at']) . '</span></div><div class="message-body">' . nl2br(e($message['body'])) . '</div>';
        if (!empty($attachments[(int) $message['id']])) {
            echo '<div class="attachments">';
            foreach ($attachments[(int) $message['id']] as $attachment) {
                 echo '<a class="attachment" href="index.php?action=download_attachment&id=' . (int) $attachment['id'] . '">📎 ' . e($attachment['original_name']) . '</a>';
            }
            echo '</div>';
        }
        echo '</article>';
    }
     if ($requiresRating) {
         echo '<form class="card rating-card required-rating" method="post">' . csrf_field() . '<input type="hidden" name="action" value="rate_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><h3>ثبت امتیاز رضایت الزامی است</h3><p class="muted">برای نهایی‌شدن این تیکت، میزان رضایت خود از واحد رسیدگی را ثبت کنید.</p><div class="rating-options">';
         for ($score = 1; $score <= 5; $score++) {
             echo '<label><input type="radio" name="score" value="' . $score . '" required><span>' . $score . '★</span></label>';
         }
         echo '</div><label>نظر اختیاری<textarea name="rating_comment" rows="3" placeholder="اگر توضیحی دارید بنویسید..."></textarea></label><button class="button" type="submit">ثبت امتیاز</button></form>';
     }
     if ($requesterView && $ticket['status'] === 'resolved') {
         if (!$requiresRating) {
             echo '<div class="card"><p class="muted">امتیاز شما ثبت شد. تیکت در انتظار تأیید نهایی سوپروایزر است.</p></div>';
         }
         echo '<form class="card reply-box" method="post">' . csrf_field() . '<input type="hidden" name="action" value="reopen_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><h3>مشکل برطرف نشده است؟</h3><label>توضیح دهید چه چیزی هنوز برطرف نشده<textarea name="reopen_reason" required rows="3" placeholder="مثلاً: همچنان خطا می‌دهد..."></textarea></label><button class="button secondary" type="submit">مشکل حل نشده، بازگشایی تیکت</button></form>';
     } elseif ($ticket['status'] === 'closed') {
         if (!$requiresRating) {
             echo '<div class="card"><p class="muted">این تیکت بسته شده است. برای ادامه، درخواست جدید ثبت کنید.</p></div>';
         }
     } elseif (!$requiresRating) {
         $afterOptions = ($handlerLevel !== '' && !empty($ticket['assigned_to'])) ? array_values(array_intersect(['waiting_user', 'resolved'], ticket_allowed_transitions((string) $ticket['status']))) : [];
         $afterSelect = '';
         if ($afterOptions) {
             $afterSelect = '<label>پس از ارسال<select name="after_status"><option value="">بدون تغییر وضعیت</option>';
             foreach ($afterOptions as $afterOption) {
                 $afterSelect .= '<option value="' . $afterOption . '">' . ($afterOption === 'resolved' ? 'حل شد (منتظر امتیاز کاربر)' : e(status_label($afterOption))) . '</option>';
             }
             $afterSelect .= '</select></label>';
         }
         echo '<form class="card reply-box" method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><label>پاسخ شما<textarea name="body" required rows="5" placeholder="پیام خود را بنویسید..."></textarea></label><div class="reply-actions"><label class="file-input compact">پیوست<input type="file" name="attachment"></label>' . ($staff ? '<label class="check-label"><input type="checkbox" name="is_internal" value="1"> یادداشت داخلی</label>' : '') . $afterSelect . '<button class="button" type="submit">ارسال پاسخ</button></div></form>';
     }
     echo '</section>';
        echo '<aside class="ticket-sidebar"><div class="card detail-card"><h3>اطلاعات درخواست</h3><dl><dt>ثبت‌کننده</dt><dd>' . e($ticket['requester_name']) . '</dd><dt>حوزه</dt><dd>' . (($ticket['service_group'] ?? 'it') === 'support' ? 'پشتیبانی' : 'IT') . '</dd><dt>نوع</dt><dd>' . e(ticket_type_label((string) $ticket['ticket_type'])) . '</dd><dt>خدمت</dt><dd>' . e($ticket['service_name'] ?: 'بدون خدمت') . '</dd><dt>واحد رسیدگی</dt><dd>' . e(($ticket['service_group'] ?? 'it') === 'support' ? 'واحد پشتیبانی' : 'واحد فناوری اطلاعات') . '</dd><dt>معاونت سازمانی</dt><dd>' . e($ticket['requesting_unit_name'] ?: ($ticket['department_name'] ?: 'بدون معاونت')) . '</dd><dt>دسته‌بندی</dt><dd>' . e($ticket['category_name'] ?: 'بدون دسته‌بندی') . '</dd><dt>کارشناس</dt><dd>' . e($ticket['assignee_name'] ?: 'تخصیص داده نشده') . '</dd>' . (($ticket['service_group'] ?? 'it') === 'support' ? '<dt>محل خدمت</dt><dd>' . e($ticket['support_location'] ?: 'ثبت نشده') . '</dd><dt>تجهیز / مورد</dt><dd>' . e($ticket['support_equipment'] ?: 'ثبت نشده') . '</dd>' : '<dt>سیستم</dt><dd>' . e($ticket['hostname'] ? asset_display_name($ticket['hostname']) . (asset_is_auto_domain_tag($ticket['asset_tag']) ? '' : ' (' . $ticket['asset_tag'] . ')') : 'بدون سیستم مرتبط') . '</dd>') . '<dt>مهلت SLA</dt><dd>' . e($ticket['due_at'] ? persian_date($ticket['due_at']) : 'ثبت نشده') . '</dd><dt>مهلت پاسخ OLA</dt><dd>' . e($ticket['ola_response_due_at'] ? persian_date($ticket['ola_response_due_at']) : 'ثبت نشده') . '</dd><dt>مهلت حل OLA</dt><dd>' . e($ticket['ola_due_at'] ? persian_date($ticket['ola_due_at']) : 'ثبت نشده') . '</dd>' . (ticket_rating_is_current($ticket) ? '<dt>رضایت کاربر</dt><dd>' . e((string) $ticket['rating_score']) . ' از ۵</dd>' : '') . '</dl></div>';
    if ($staff && empty($ticket['assigned_to']) && in_array($ticket['status'], ['new', 'manager_review'], true) && is_assignable_role((string) $user['role'])) {
        echo '<div class="card detail-card"><h3>برداشتن تیکت</h3><p class="muted">این تیکت هنوز کارشناس مسئول ندارد.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="claim_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><button class="button wide" type="submit">برداشتن و شروع رسیدگی</button></form></div>';
    }
    if ($canManageTicket && $handlerLevel !== '' && $ticket['status'] !== 'closed') {
         if ($canAssignTicket && is_department_scoped_role($user['role'])) {
              $agentSql = 'SELECT u.id, u.full_name, u.username FROM users u WHERE (u.is_it_agent = 1 OR u.role = "agent") AND u.role IN ("agent", "manager", "admin") AND u.is_active = 1 AND u.handling_unit_id = ?';
              $agentParams = [(int) ($user['handling_unit_id'] ?? 0)];
              if (user_service_group($user) === 'support') {
                  $agentSql .= ' AND u.department_id = ?';
                  $agentParams[] = (int) $ticket['department_id'];
              }
              $agentSql .= ' ORDER BY u.full_name';
              $agentQuery = db()->prepare($agentSql);
              $agentQuery->execute($agentParams);
              $agents = $agentQuery->fetchAll();
        } elseif ($canAssignTicket) {
             $agents = db()->query('SELECT id, full_name, username FROM users WHERE (is_it_agent = 1 OR role = "agent") AND role IN ("agent", "manager", "admin") AND is_active = 1 ORDER BY full_name')->fetchAll();
        }
        echo '<div class="card detail-card"><h3>مدیریت تیکت</h3><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="update_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><label>وضعیت<select name="status">';
        $statusChoices = array_values(array_unique(array_merge([(string) $ticket['status']], array_filter(ticket_allowed_transitions((string) $ticket['status']), fn ($allowedStatus) => $canAssignTicket || in_array($allowedStatus, ['in_progress', 'waiting_user', 'resolved'], true)))));
        foreach ($statusChoices as $status) {
            echo '<option value="' . $status . '" ' . ($ticket['status'] === $status ? 'selected' : '') . '>' . status_label($status) . '</option>';
        }
        echo '</select></label>';
        if ($canAssignTicket) {
            echo '<label>اولویت<select name="priority">';
            foreach (['normal','urgent','critical'] as $priority) {
                echo '<option value="' . $priority . '" ' . ($ticket['priority'] === $priority ? 'selected' : '') . '>' . priority_label($priority) . '</option>';
            }
            echo '</select></label>';
        } else {
            echo '<input type="hidden" name="priority" value="' . e((string) $ticket['priority']) . '">';
        }
        if ($canAssignTicket) {
            echo '<label>ارجاع به<select name="assigned_to"><option value="">بدون تخصیص</option>';
            if (!empty($ticket['assigned_to']) && !in_array((int) $ticket['assigned_to'], array_map('intval', array_column($agents, 'id')), true)) {
                echo '<option value="' . (int) $ticket['assigned_to'] . '" selected>' . e((string) ($ticket['assignee_name'] ?? '')) . '</option>';
            }
            foreach ($agents as $agent) {
                echo '<option value="' . (int) $agent['id'] . '" ' . ((int) $ticket['assigned_to'] === (int) $agent['id'] ? 'selected' : '') . '>' . e($agent['full_name']) . '</option>';
            }
            echo '</select></label>';
        } else {
            echo '<input type="hidden" name="assigned_to" value="' . (int) $ticket['assigned_to'] . '"><p class="muted">ارجاع تیکت فقط توسط مدیر واحد انجام می‌شود.</p>';
        }
        echo '<button class="button wide" type="submit">ذخیره تغییرات</button></form></div>';
    }
    echo '</aside></div>';
    $eventsQuery = db()->prepare('SELECT e.*, u.full_name FROM ticket_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.ticket_id = ? ORDER BY e.created_at DESC');
    $eventsQuery->execute([$ticket['id']]);
    echo '<section class="card event-log"><h3>ردگیری و سوابق انجام کار</h3><div class="event-list">';
    foreach ($eventsQuery->fetchAll() as $event) {
        $eventText = ['created' => 'تیکت ثبت شد', 'assigned' => 'تیکت به کارشناس ارجاع شد', 'status_changed' => 'وضعیت تیکت تغییر کرد', 'reply' => 'پاسخ ثبت شد', 'internal_note' => 'یادداشت داخلی ثبت شد', 'claimed' => 'تیکت توسط کارشناس برداشته شد', 'rated' => 'امتیاز رضایت کاربر ثبت شد', 'reopened_by_user' => 'کاربر اعلام کرد مشکل حل نشده است', 'supervisor_approved' => 'تأیید نهایی و بسته‌شدن', 'supervisor_rework' => 'برگشت برای اصلاح توسط سوپروایزر', 'supervisor_reopened' => 'بازگشایی رسمی توسط سوپروایزر', 'auto_assigned' => 'ارجاع خودکار به کارشناس', 'auto_closed' => 'بسته‌شدن خودکار (بدون امتیاز کاربر)', 'bulk_status_changed' => 'تغییر وضعیت گروهی', 'priority_changed' => 'اولویت تغییر کرد'][$event['event_type']] ?? $event['event_type'];
        echo '<div class="event-item"><span class="event-dot"></span><div><strong>' . e($eventText) . '</strong><small>' . e($event['full_name'] ?: 'سامانه') . ' • ' . persian_date($event['created_at']) . '</small></div><em>' . e(status_label((string) $event['to_status'])) . '</em></div>';
    }
    echo '</div></section>';
    render_footer();
    exit;
}

if ($page === 'analytics') {
    $analyticsUser = require_permission('analytics.view');
    [$analyticsScope, $analyticsParams] = ticket_scope($analyticsUser);
    $statusQuery = db()->prepare('SELECT t.status, COUNT(*) AS total FROM tickets t ' . $analyticsScope . ' GROUP BY t.status ORDER BY total DESC');
    $statusQuery->execute($analyticsParams);
    $statusRows = $statusQuery->fetchAll();
    $priorityQuery = db()->prepare('SELECT t.priority, COUNT(*) AS total FROM tickets t ' . $analyticsScope . ' GROUP BY t.priority ORDER BY total DESC');
    $priorityQuery->execute($analyticsParams);
    $priorityRows = $priorityQuery->fetchAll();
    $departmentQuery = db()->prepare('SELECT COALESCE(d.name, "بدون معاونت") AS label, COUNT(*) AS total FROM tickets t LEFT JOIN departments d ON d.id = t.department_id ' . $analyticsScope . ' GROUP BY t.department_id, d.name ORDER BY total DESC LIMIT 10');
    $departmentQuery->execute($analyticsParams);
    $departmentRows = $departmentQuery->fetchAll();
    $ratingConditions = ['(u.is_it_agent = 1 OR u.role = "agent")', 'u.is_active = 1'];
    $ratingParams = [];
    if (!is_global_ticket_role($analyticsUser['role'])) {
        $ratingConditions[] = 'hu.code = ?';
        $ratingParams[] = user_service_group($analyticsUser);
        if (is_department_scoped_role($analyticsUser['role'])) {
            $ratingConditions[] = 'rt.department_id = ?';
            $ratingParams[] = (int) ($analyticsUser['department_id'] ?? 0);
        }
    }
    $agentQuery = db()->prepare('SELECT u.full_name AS label, COUNT(tr.id) AS ratings, COALESCE(AVG(tr.score), 0) AS average FROM users u LEFT JOIN handling_units hu ON hu.id = u.handling_unit_id LEFT JOIN ticket_ratings tr ON tr.agent_id = u.id LEFT JOIN tickets rt ON rt.id = tr.ticket_id WHERE ' . implode(' AND ', $ratingConditions) . ' GROUP BY u.id ORDER BY average DESC, ratings DESC LIMIT 10');
    $agentQuery->execute($ratingParams);
    $agentRows = $agentQuery->fetchAll();
    $chart = static function (string $title, array $rows, callable $label, callable $value, string $suffix = ''): string {
        $max = 1;
        foreach ($rows as $row) { $max = max($max, (float) $value($row)); }
        $html = '<section class="card chart-card"><div class="chart-title"><h2>' . e($title) . '</h2><span>نمای تحلیلی</span></div>';
        if (!$rows) { return $html . '<p class="muted">داده‌ای برای نمایش وجود ندارد.</p></section>'; }
        foreach ($rows as $row) { $amount = (float) $value($row); $width = max(3, (int) round(($amount / $max) * 100)); $html .= '<div class="chart-row"><div class="chart-label"><span>' . e((string) $label($row)) . '</span><b>' . e(rtrim(rtrim(number_format($amount, 1, '.', ''), '0'), '.') . $suffix) . '</b></div><div class="chart-track"><i style="width:' . $width . '%"></i></div></div>'; }
        return $html . '</section>';
    };
    render_header('تحلیل و چارت‌ها', $analyticsUser);
     echo '<section class="page-heading"><div><span class="eyebrow">گزارش‌ساز مدیریتی</span><h1>تحلیل عملکرد سامانه</h1><p>توزیع وضعیت، اولویت، معاونت و رضایت از کارشناسان هر واحد.</p></div><a class="button secondary" href="index.php?page=reports">گزارش فیلترپذیر</a></section><section class="charts-grid">';
    echo $chart('توزیع وضعیت تیکت‌ها', $statusRows, static fn (array $row): string => status_label((string) $row['status']), static fn (array $row): float => (float) $row['total']);
    echo $chart('توزیع اولویت‌ها', $priorityRows, static fn (array $row): string => priority_label((string) $row['priority']), static fn (array $row): float => (float) $row['total']);
    echo $chart('تیکت بر اساس معاونت', $departmentRows, static fn (array $row): string => (string) $row['label'], static fn (array $row): float => (float) $row['total']);
    echo $chart('میانگین رضایت کارشناسان', $agentRows, static fn (array $row): string => (string) $row['label'] . ' (' . (int) $row['ratings'] . ' نظر)', static fn (array $row): float => (float) $row['average'], ' از ۵');
    echo '</section><section class="card analytics-note"><strong>راهنمای تحلیل</strong><span>امتیاز رضایت فقط پس از حل تیکت و توسط ثبت‌کننده ثبت می‌شود. گزارش‌های این صفحه بر اساس سطح دسترسی معاونت فیلتر می‌شوند.</span></section>';
    render_footer();
    exit;
}

if ($page === 'reports') {
    $reportUser = require_permission('reports.view');
    $reportMissingColumns = db_missing_columns('tickets', ['subject', 'description', 'status', 'priority', 'requester_id', 'department_id', 'service_group', 'created_at', 'updated_at', 'assigned_to', 'ticket_type', 'assigned_at', 'first_response_at', 'resolved_at', 'closed_at', 'due_at', 'sla_minutes']);
    if ($reportMissingColumns) {
        render_header('گزارش‌ها', $reportUser);
        echo '<section class="card empty-state"><h2>ساختار گزارش‌ها کامل نیست.</h2><p>این نسخه به ستون‌های جدید تیکت نیاز دارد. فایل‌های Migration را به‌ترتیب روی همان دیتابیس اجرا کنید، سپس صفحه را تازه‌سازی کنید.</p><p><code>upgrade-1.2-itsm.sql</code> و در صورت نیاز <code>upgrade-1.8-routing.sql</code></p><p class="muted">ستون‌های ناقص: ' . e(implode(', ', $reportMissingColumns)) . '</p><a class="button" href="index.php">بازگشت به داشبورد</a></section>';
        render_footer();
        exit;
    }
    [$where, $params] = report_query_filters($reportUser);
    $summaryQuery = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(t.status IN ("new", "manager_review", "assigned", "in_progress", "waiting_user")), 0) AS active, COALESCE(SUM(t.status = "resolved"), 0) AS resolved, COALESCE(SUM(t.status = "closed"), 0) AS closed, COALESCE(SUM(t.status NOT IN ("resolved", "closed") AND t.due_at IS NOT NULL AND t.due_at < NOW()), 0) AS overdue, AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) AS avg_resolution FROM tickets t LEFT JOIN users r ON r.id = t.requester_id ' . $where);
    $summaryQuery->execute($params);
    $summary = $summaryQuery->fetch() ?: ['total' => 0, 'active' => 0, 'resolved' => 0, 'closed' => 0, 'overdue' => 0, 'avg_resolution' => 0];
     $rowsQuery = db()->prepare('SELECT t.*, d.name AS department_name, r.full_name AS requester_name, a.full_name AS assignee_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assigned_to ' . $where . ' ORDER BY t.created_at DESC LIMIT 1000');
    $rowsQuery->execute($params);
    $departments = is_global_ticket_role($reportUser['role']) ? db()->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll() : [];
    $queryString = http_build_query(array_filter($_GET, static fn ($value, $key): bool => $key !== 'page' && $key !== 'action' && $value !== '', ARRAY_FILTER_USE_BOTH));
    render_header('گزارش‌ها', $reportUser);
     echo '<section class="page-heading"><div><span class="eyebrow">کنترل عملکرد پشتیبانی</span><h1>گزارش و پیگیری</h1><p>گزارش‌ها را فیلتر کنید و خروجی قابل استفاده در Excel بگیرید.</p></div><a class="button secondary" href="index.php?action=export_report' . ($queryString ? '&' . e($queryString) : '') . '">خروجی Excel</a></section><section class="card filter-card"><form method="get"><input type="hidden" name="page" value="reports"><div class="filter-grid"><label>از تاریخ جلالی<input type="text" name="from" placeholder="۱۴۰۵/۰۱/۰۱" value="' . e($_GET['from'] ?? '') . '"></label><label>تا تاریخ جلالی<input type="text" name="to" placeholder="۱۴۰۵/۰۱/۳۱" value="' . e($_GET['to'] ?? '') . '"></label><label>وضعیت<select name="status"><option value="">همه وضعیت‌ها</option>';
    // گزینه‌های گروهی هم اضافه شدند تا کارت‌های داشبورد (?status=active/resolved/all) در فرم دیده شوند
    foreach (['all' => 'همهٔ تیکت‌ها', 'active' => 'باز و در حال پیگیری', 'new' => 'جدید', 'manager_review' => null, 'assigned' => null, 'in_progress' => null, 'waiting_user' => null, 'resolved' => null, 'closed' => null] as $status => $customLabel) {
        echo '<option value="' . $status . '" ' . ((string) ($_GET['status'] ?? '') === $status ? 'selected' : '') . '>' . e($customLabel ?? status_label($status)) . '</option>';
    }
    echo '</select></label>';
    if ($departments) {
        echo '<label>واحد<select name="department_id"><option value="">همه واحدها</option>';
        foreach ($departments as $department) {
            echo '<option value="' . (int) $department['id'] . '" ' . ((int) ($_GET['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '') . '>' . e($department['name']) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<label>اولویت<select name="priority"><option value="">همه اولویت‌ها</option>';
     foreach (['normal','urgent','critical'] as $priority) {
        echo '<option value="' . $priority . '" ' . ((string) ($_GET['priority'] ?? '') === $priority ? 'selected' : '') . '>' . priority_label($priority) . '</option>';
    }
     echo '</select></label><label>حوزه خدمت<select name="service_group"><option value="">همه حوزه‌ها</option><option value="it" ' . ((string) ($_GET['service_group'] ?? '') === 'it' ? 'selected' : '') . '>IT</option><option value="support" ' . ((string) ($_GET['service_group'] ?? '') === 'support' ? 'selected' : '') . '>پشتیبانی</option></select></label><label>نوع تیکت<select name="ticket_type"><option value="">همه انواع</option>';
     foreach (['incident','request','problem','change'] as $ticketType) { echo '<option value="' . $ticketType . '" ' . ((string) ($_GET['ticket_type'] ?? '') === $ticketType ? 'selected' : '') . '>' . ticket_type_label($ticketType) . '</option>'; }
     echo '</select></label><label class="filter-search">جست‌وجو<input name="q" value="' . e($_GET['q'] ?? '') . '" placeholder="عنوان، شرح یا ثبت‌کننده"></label><button class="button" type="submit">اعمال فیلتر</button></div></form></section><section class="stat-grid report-stats"><div class="stat-card accent"><span class="stat-icon">▣</span><div><strong>' . (int) $summary['total'] . '</strong><small>کل تیکت‌ها</small></div></div><div class="stat-card"><span class="stat-icon blue">◈</span><div><strong>' . (int) $summary['active'] . '</strong><small>باز و در حال انجام</small></div></div><div class="stat-card"><span class="stat-icon green">✓</span><div><strong>' . ((int) $summary['resolved'] + (int) $summary['closed']) . '</strong><small>حل‌شده یا بسته</small></div></div><div class="stat-card"><span class="stat-icon gray">◷</span><div><strong>' . ($summary['avg_resolution'] ? round((float) $summary['avg_resolution'] / 60, 1) . ' ساعت' : '-') . '</strong><small>میانگین زمان حل</small></div></div><div class="stat-card"><span class="stat-icon" style="background:#fef3f2;color:#b42318">!</span><div><strong>' . (int) $summary['overdue'] . '</strong><small>عبور از SLA</small></div></div></section><section class="card ticket-table report-table"><div class="table-head"><span>تیکت</span><span>واحد</span><span>ثبت‌کننده</span><span>وضعیت</span><span>زمان ثبت</span></div>';
    while ($row = $rowsQuery->fetch()) {
         echo '<a class="table-row" href="index.php?page=ticket&id=' . (int) $row['id'] . '"><span class="ticket-title"><b>' . e(ticket_number((int) $row['id'])) . '</b><strong>' . e($row['subject']) . '</strong><small>' . e(ticket_type_label($row['ticket_type'])) . ' • ' . e($row['assignee_name'] ?: 'بدون کارشناس') . '</small></span><span>' . e($row['department_name'] ?: 'بدون واحد') . '</span><span>' . e($row['requester_name']) . '</span><span><em class="status ' . e($row['status']) . '">' . e(status_label($row['status'])) . '</em></span><span class="date-cell">' . persian_date($row['created_at']) . '</span></a>';
    }
    echo '</section>';
    render_footer();
    exit;
}

if ($page === 'supervisor') {
    $supervisor = require_permission('supervisor.panel');
    [$where, $params] = ticket_scope($supervisor);
    $summaryQuery = db()->prepare('SELECT COUNT(*) AS total, SUM(status IN ("new", "manager_review", "assigned", "in_progress", "waiting_user")) AS active, SUM(status = "resolved") AS awaiting_approval, SUM(status = "closed") AS closed FROM tickets t ' . $where);
    $summaryQuery->execute($params);
    $summary = $summaryQuery->fetch() ?: ['total' => 0, 'active' => 0, 'awaiting_approval' => 0, 'closed' => 0];
     $ticketsQuery = db()->prepare('SELECT t.*, d.name AS department_name, r.full_name AS requester_name, a.full_name AS assignee_name, tr.score AS rating_score, tr.created_at AS rating_at FROM tickets t LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assigned_to LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id ' . $where . ' ORDER BY CASE WHEN t.status = "resolved" THEN 0 WHEN t.status IN ("new", "manager_review", "assigned", "in_progress", "waiting_user") THEN 1 ELSE 2 END, t.updated_at DESC LIMIT 1000');
    $ticketsQuery->execute($params);
    render_header('پنل سوپروایزر', $supervisor);
    echo '<section class="page-heading"><div><span class="eyebrow">نظارت سراسری</span><h1>پنل سوپروایزر</h1><p>تمام مراحل از ثبت تا انجام و تأیید نهایی را کنترل کنید.</p></div><a class="button secondary" href="index.php?page=reports">گزارش کامل</a></section><section class="stat-grid"><div class="stat-card accent"><span class="stat-icon">▣</span><div><strong>' . (int) $summary['total'] . '</strong><small>کل تیکت‌ها</small></div></div><div class="stat-card"><span class="stat-icon blue">◈</span><div><strong>' . (int) $summary['active'] . '</strong><small>در گردش</small></div></div><div class="stat-card"><span class="stat-icon" style="background:#fff8e7;color:#9a6700">✓</span><div><strong>' . (int) $summary['awaiting_approval'] . '</strong><small>منتظر تأیید شما</small></div></div><div class="stat-card"><span class="stat-icon green">✓</span><div><strong>' . (int) $summary['closed'] . '</strong><small>بسته‌شده</small></div></div></section><section class="card supervisor-list"><div class="table-head"><span>تیکت</span><span>واحد</span><span>کارشناس</span><span>وضعیت</span><span>عملیات</span></div>';
    while ($ticket = $ticketsQuery->fetch()) {
        echo '<div class="table-row supervisor-row"><span class="ticket-title"><b>' . e(ticket_number((int) $ticket['id'])) . '</b><a href="index.php?page=ticket&id=' . (int) $ticket['id'] . '"><strong>' . e($ticket['subject']) . '</strong></a><small>' . e($ticket['requester_name']) . '</small></span><span>' . e($ticket['department_name'] ?: 'بدون واحد') . '</span><span>' . e($ticket['assignee_name'] ?: 'بدون کارشناس') . '</span><span><em class="status ' . e($ticket['status']) . '">' . e(status_label($ticket['status'])) . '</em></span><span class="supervisor-actions">';
        if ($ticket['status'] === 'resolved') {
            if (ticket_rating_is_current($ticket)) {
                echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="supervisor_decision"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><input type="hidden" name="decision" value="approve"><button class="button" type="submit">تأیید و بستن</button></form>';
            } else {
                echo '<small class="muted">در انتظار امتیاز کاربر</small>';
            }
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="supervisor_decision"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><input type="hidden" name="decision" value="rework"><input type="text" name="supervisor_note" required maxlength="500" placeholder="دلیل برگشت برای اصلاح"><button class="button secondary" type="submit">برگشت برای اصلاح</button></form>';
         } elseif ($ticket['status'] === 'closed') {
             echo '<a class="button secondary" href="index.php?page=ticket&id=' . (int) $ticket['id'] . '">مشاهده</a><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="supervisor_reopen"><input type="hidden" name="ticket_id" value="' . (int) $ticket['id'] . '"><input type="hidden" name="supervisor_note" value="بازگشایی رسمی برای ادامه رسیدگی"><button class="button secondary" type="submit">بازگشایی رسمی</button></form>';
         } else {
             echo '<a class="button secondary" href="index.php?page=ticket&id=' . (int) $ticket['id'] . '">مشاهده</a>';
        }
        echo '</span></div>';
    }
    echo '</section>';
    render_footer();
    exit;
}

if ($page === 'inventory') {
    $inventoryUser = require_permission('inventory.view');
    if (!is_global_ticket_role($inventoryUser['role']) && user_service_group($inventoryUser) !== 'it') {
        http_response_code(403);
        exit('شناسنامه فنی سیستم‌ها فقط در حوزه IT قابل دسترسی است.');
    }
    [$inventoryScope, $inventoryParams] = asset_scope($inventoryUser);
    $inventoryId = (int) ($_GET['id'] ?? 0);
    if ($inventoryId <= 0 && user_can($inventoryUser, 'assets.view')) {
        redirect('index.php?page=assets');
    }
    if ($inventoryId <= 0) {
        asset_profile_ensure_schema();
        $inventoryQuery = db()->prepare('SELECT a.id, a.asset_tag, a.hostname, a.operating_system, a.last_inventory_at, d.name AS department_name, p.ad_online, p.ad_scanned_at FROM assets a LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN asset_profiles p ON p.asset_id = a.id ' . $inventoryScope . ' ORDER BY a.updated_at DESC LIMIT 200');
        $inventoryQuery->execute($inventoryParams);
        render_header('شناسنامه سیستم‌ها', $inventoryUser);
          echo '<section class="page-heading"><div><span class="eyebrow">جزئیات سیستم</span><h1>سیستم‌های شناسایی‌شده</h1><p>برای تکمیل اطلاعات دستی یا مشاهده تاریخچه، سیستم موردنظر را انتخاب کنید.</p></div><div class="actions">' . (user_can($inventoryUser, 'domain.import') ? '<form method="post" class="inline-action">' . csrf_field() . '<input type="hidden" name="action" value="import_domain_assets"><button class="button secondary" type="submit">ورود کامپیوترهای دامنه</button></form>' : '') . '<a class="button secondary" href="index.php?page=assets">شناسنامه‌ها</a></div></section><section class="card ticket-table"><div class="table-head"><span>سیستم</span><span>معاونت</span><span>سیستم‌عامل</span><span>آخرین برداشت</span></div>';
         while ($asset = $inventoryQuery->fetch()) {
             $isOnline = (int) ($asset['ad_online'] ?? 0) === 1;
             $wasScanned = (string) ($asset['ad_scanned_at'] ?? '') !== '';
             if ($isOnline) {
                 $netBadge = '<small class="net-badge online">آنلاین</small>';
             } elseif ($wasScanned) {
                 $netBadge = '<small class="net-badge offline">آفلاین</small>';
             } else {
                 $netBadge = '<small class="net-badge unknown">پینگ نشده</small>';
             }
             echo '<a class="table-row" href="index.php?page=inventory&id=' . (int) $asset['id'] . '"><span class="ticket-title">' . (asset_is_auto_domain_tag($asset['asset_tag']) ? '' : '<b>' . e($asset['asset_tag']) . '</b>') . '<strong' . asset_name_attr($asset['hostname'], $asset['asset_tag']) . '>' . e(asset_display_name($asset['hostname'])) . '</strong>' . (asset_is_auto_domain_tag($asset['asset_tag']) ? '<small class="asset-tag-chip">دامنه</small>' : '') . $netBadge . '</span><span>' . e($asset['department_name'] ?: 'بدون معاونت') . '</span><span>' . e($asset['operating_system']) . '</span><span>' . e($asset['last_inventory_at'] ? persian_date($asset['last_inventory_at']) : 'ثبت نشده') . '</span></a>';
         }
        echo '</section>';
        render_footer();
        exit;
    }
    $inventoryQuery = db()->prepare('SELECT a.*, d.name AS department_name, u.full_name AS owner_name FROM assets a LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.owner_user_id ' . ($inventoryScope ? $inventoryScope . ' AND a.id = ?' : 'WHERE a.id = ?') . ' LIMIT 1');
    $inventoryQuery->execute(array_merge($inventoryParams, [$inventoryId]));
    $asset = $inventoryQuery->fetch();
    if (!$asset) {
        http_response_code(404);
        render_header('سیستم پیدا نشد', $inventoryUser);
        echo '<section class="empty-state"><h2>سیستم پیدا نشد</h2></section>';
        render_footer();
        exit;
    }
    $profile = asset_profile_fetch($inventoryId);
    if ((string) ($profile['record_number'] ?? '') === '') {
        $profile['record_number'] = (string) $asset['asset_tag'];
    }
    if ((string) ($profile['registry_date'] ?? '') === '' && !empty($asset['created_at'])) {
        $profile['registry_date'] = (string) $asset['created_at'];
    }
    if ((string) ($profile['hostname'] ?? '') === '') {
        $profile['hostname'] = (string) $asset['hostname'];
    }
    render_header('شناسنامه سیستم', $inventoryUser);
    $canExtractThisAsset = user_can($inventoryUser, 'domain.scan');
    $assetActions = '';
    if ($canExtractThisAsset) {
        $assetActions = '<form method="post" class="inline-action">' . csrf_field() . '<input type="hidden" name="asset_ids[]" value="' . $inventoryId . '"><button class="button" type="submit" name="action" value="domain_scan_selected">استخراج اطلاعات این سیستم</button></form>';
        if (user_can($inventoryUser, 'settings.domain')) {
            $assetActions .= '<a class="button secondary" href="index.php?page=settings#domain-scan">حساب استخراج شبکه</a>';
        }
    }
    echo '<section class="page-heading asset-heading"><div><a class="back-link" href="index.php?page=inventory">← بازگشت به سیستم‌ها</a><h1>شناسنامه رایانه ' . e(asset_display_name($asset['hostname'])) . '</h1><p>' . e(asset_name_title($asset['hostname'], $asset['asset_tag']) ?: $asset['asset_tag']) . ' • مالک: ' . e($asset['owner_name'] ?: 'بدون مالک') . '</p></div><div class="actions">' . $assetActions . '<a class="button secondary" href="index.php?page=asset-history&id=' . $inventoryId . '">تاریخچه</a></div></section>';
    echo '<form class="inventory-form" method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_inventory_form"><input type="hidden" name="asset_id" value="' . $inventoryId . '">';
    echo asset_profile_render_sections($profile);
    echo '<div class="form-actions-bar"><button class="button" type="submit">ذخیره شناسنامه</button></div></form>';
    render_footer();
    exit;
}

if ($page === 'inventory-diagnostics') {
    $diagUser = require_permission('inventory.diagnostics');
    if (!is_global_ticket_role($diagUser['role']) && user_service_group($diagUser) !== 'it') {
        http_response_code(403);
        exit('این بخش فقط در حوزه IT قابل دسترسی است.');
    }
    $diagAssetId = (int) ($_GET['id'] ?? 0);
    $checks = inventory_diagnostics();
    render_header('تست جمع‌آوری اطلاعات', $diagUser);
    $selfTest = $_SESSION['asset_selftest'] ?? null;
    unset($_SESSION['asset_selftest']);
    echo '<section class="page-heading"><div><a class="back-link" href="' . ($diagAssetId > 0 ? 'index.php?page=inventory&id=' . $diagAssetId : 'index.php?page=assets') . '">← بازگشت</a><h1>تست جمع‌آوری اطلاعات سیستم</h1><p>نتیجه اجرای دستورهای سیستمی از دید همین سرور. اگر ردیفی «ناموفق/صفر» بود، دکمهٔ استخراج هم چیزی پر نمی‌کند.</p></div><div class="actions"><form method="post" class="inline-action">' . csrf_field() . '<input type="hidden" name="action" value="asset_profile_selftest"><input type="hidden" name="asset_id" value="' . $diagAssetId . '"><button class="button" type="submit">استخراج آزمایشی و نمایش فیلدها</button></form></div></section>';
    if (is_array($selfTest)) {
        echo '<section class="card ticket-table"><div class="table-head"><span>فیلد شناسنامه</span><span>مقدار به‌دست‌آمده</span></div>';
        if ((int) $selfTest['filled'] === 0) {
            echo '<div class="table-row"><span class="muted">هیچ فیلدی به‌دست نیامد. علت: ' . e((string) ($selfTest['error'] !== '' ? $selfTest['error'] : 'خروجی خالی از PowerShell/WMI')) . '</span></div>';
        }
        foreach ((array) $selfTest['keys'] as $row) {
            echo '<div class="table-row"><span><b dir="ltr">' . e((string) $row[0]) . '</b></span><span>' . e((string) $row[1]) . '</span></div>';
        }
        echo '</div><p class="muted" style="margin-top:8px">مجموع: ' . (int) $selfTest['filled'] . ' فیلد پرشدنی، ' . (int) $selfTest['empty'] . ' فیلد خالی (دستی)، امتیاز سخت‌افزار ' . (int) $selfTest['hardware_score'] . (($selfTest['asset_id'] ?? 0) > 0 ? '، شناسنامهٔ سیستم #' . (int) $selfTest['asset_id'] . ' (' . (int) $selfTest['applied'] . ' فیلد ذخیره شد)' : '') . '. زمان: ' . e((string) $selfTest['at']) . ' — سرور: ' . e((string) $selfTest['os']) . '</p></section>';
    }
    echo '<section class="card ticket-table"><div class="table-head"><span>بررسی</span><span>نتیجه</span></div>';
    foreach ($checks as $check) {
        echo '<div class="table-row"><span class="ticket-title"><b>' . e((string) $check[0]) . '</b></span><span style="word-break:break-all">' . e((string) $check[1]) . '</span></div>';
    }
    echo '</section>';
    render_footer();
    exit;
}

if ($page === 'asset-history') {
    $historyUser = require_permission('asset.history');
    [$historyScope, $historyParams] = asset_scope($historyUser);
    $historyId = (int) ($_GET['id'] ?? 0);
    $assetQuery = db()->prepare('SELECT a.*, d.name AS department_name, u.full_name AS owner_name FROM assets a LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.owner_user_id ' . ($historyScope ? $historyScope . ' AND a.id = ?' : 'WHERE a.id = ?') . ' LIMIT 1');
    $assetQuery->execute(array_merge($historyParams, [$historyId]));
    $asset = $assetQuery->fetch();
    if (!$asset) { http_response_code(404); exit('سیستم پیدا نشد.'); }
    $historyQuery = db()->prepare('SELECT h.*, u.full_name, t.subject, t.id AS ticket_id FROM asset_history_events h LEFT JOIN users u ON u.id = h.user_id LEFT JOIN tickets t ON t.id = h.ticket_id WHERE h.asset_id = ? ORDER BY h.created_at DESC');
    $historyQuery->execute([$historyId]);
    render_header('تاریخچه سیستم', $historyUser);
    echo '<section class="page-heading"><div><a class="back-link" href="index.php?page=inventory&id=' . $historyId . '">← بازگشت به شناسنامه</a><h1>تاریخچه کامل ' . e(asset_display_name($asset['hostname'])) . '</h1><p>' . e(asset_name_title($asset['hostname'], $asset['asset_tag']) ?: $asset['asset_tag']) . ' • ' . e($asset['owner_name'] ?: 'بدون مالک') . '</p></div></section><section class="card event-log system-history"><h3>تمام تغییرات، برداشت‌ها و حل مشکلات</h3><div class="event-list">';
    foreach ($historyQuery->fetchAll() as $event) { echo '<div class="event-item"><span class="event-dot"></span><div><strong>' . e($event['title']) . '</strong><small>' . e($event['full_name'] ?: 'سامانه') . ' • ' . e(persian_date($event['created_at'])) . ($event['subject'] ? ' • تیکت: ' . e(ticket_number((int) $event['ticket_id'])) : '') . '</small><p>' . e($event['details'] ?: '') . '</p></div></div>'; }
    echo '</div></section>';
    render_footer();
    exit;
}

if ($page === 'domain-scan') {
    $scanPageUser = require_permission('domain.scan');
    domain_scan_ensure_schema();
    $scanRunId = (int) ($_GET['run'] ?? 0);
    $scanRun = $scanRunId > 0 ? domain_scan_run($scanRunId) : domain_scan_latest_run();
    $scanEnabled = domain_scan_preflight() === null;
    render_header('اسکن دامنه', $scanPageUser);
    echo '<section class="page-heading"><div><span class="eyebrow">Active Directory</span><h1>اسکن کامپیوترهای دامنه</h1><p>کامپیوترها از Active Directory خوانده می‌شوند، آنلاین‌بودنشان بررسی و اطلاعات سخت‌افزاری کامپیوترهای آنلاین دریافت می‌شود.</p></div><div class="actions"><a class="button secondary" href="index.php?page=settings">تنظیمات</a><a class="button secondary" href="index.php?page=assets">شناسنامه‌ها</a></div></section>';
    if (!$scanEnabled) {
        echo '<section class="card form-card"><p class="alert danger">' . e((string) domain_scan_preflight()) . '</p></section>';
    } else {
        echo '<section class="card form-card"><div class="form-section-head"><h2>شروع اسکن</h2><span class="muted">حساب اتصال: ' . e(domain_scan_account_label()) . '</span></div><form method="post" class="inline-action">' . csrf_field() . '<input type="hidden" name="action" value="domain_scan_start"><button class="button" type="submit">شروع اسکن دامنه</button></form><p class="muted">هر اسکن همهٔ کامپیوترهای دامنه را در صف می‌گذارد؛ کامپیوترهای خاموش در اسکن بعدی دوباره بررسی می‌شوند.</p></section>';
    }
    if ($scanRun) {
        $scanTotal = (int) $scanRun['total'];
        $scanDone = (int) $scanRun['done'];
        $scanPercent = $scanTotal > 0 ? min(100, (int) floor($scanDone * 100 / $scanTotal)) : 0;
        echo '<section class="card form-card"><div class="form-section-head"><h2>پیشرفت اسکن #' . (int) $scanRun['id'] . '</h2><span class="muted">وضعیت: ' . e((string) $scanRun['status']) . '</span></div>';
        echo '<div style="background:#1b2740;border-radius:10px;overflow:hidden;height:14px;margin:10px 0"><div id="scan-bar" style="height:14px;width:' . $scanPercent . '%;background:#14b8a6;transition:width .3s"></div></div>';
        echo '<div class="form-grid"><label>کل<input value="' . $scanTotal . '" readonly></label><label>انجام‌شده<input value="' . $scanDone . '" readonly></label><label>آنلاین<input value="' . (int) $scanRun['online'] . '" readonly></label><label>آفلاین<input value="' . (int) $scanRun['offline'] . '" readonly></label><label>خطا<input value="' . (int) $scanRun['failed'] . '" readonly></label></div>';
        echo '<p id="scan-status" class="muted"></p>';
        if ((string) $scanRun['status'] !== 'finished') {
            echo '<form id="scan-next" method="post" hidden>' . csrf_field() . '<input type="hidden" name="action" value="domain_scan_batch"><input type="hidden" name="run_id" value="' . (int) $scanRun['id'] . '"></form>';
        }
        echo '</section>';
        $scanRows = domain_scan_queue_rows((int) $scanRun['id'], 1000);
        // ۱.۳۷ — شکست‌ها بر اساس علت گروه‌بندی و راه‌حل گام‌به‌گام نشان داده می‌شوند تا
        // «RPC/WMI در دسترس نیست» و «دسترسی رد شد» بدون گشتن در لاگ‌ها قابل رفع باشند.
        $scanFailureGroups = domain_scan_failure_summary($scanRows);
        $scanErrorCount = 0;
        foreach ($scanRows as $scanRowForCount) {
            if ((string) ($scanRowForCount['status'] ?? '') === 'error') {
                $scanErrorCount++;
            }
        }
        if ($scanFailureGroups !== []) {
            echo '<section class="card form-card scan-failures"><div class="form-section-head"><h2>دلایل شکست استخراج و راه‌حل</h2><span class="muted">' . count($scanFailureGroups) . ' علت در این نوبت</span></div>';
            if ($scanErrorCount > 0) {
                echo '<form method="post" class="inline-action" style="margin:0 0 14px">' . csrf_field() . '<input type="hidden" name="action" value="domain_scan_retry"><input type="hidden" name="run_id" value="' . (int) $scanRun['id'] . '"><button class="button" type="submit">تلاش دوباره برای ناموفق‌ها (' . $scanErrorCount . ' سیستم)</button></form>';
            }
            echo '<p class="muted">بعد از انجام هر اصلاح روی سرور یا کلاینت‌ها، دکمهٔ «تلاش دوباره» فقط سیستم‌های خطادار را در نوبت تازه‌ای صف می‌کند؛ سیستم‌های موفق دوباره استخراج نمی‌شوند.</p><div class="scan-failure-grid">';
            foreach ($scanFailureGroups as $scanGroup) {
                echo '<article class="scan-failure"><h3>' . e((string) $scanGroup['title']) . ' <span class="scan-failure-count">' . (int) $scanGroup['count'] . ' سیستم</span></h3>';
                if ($scanGroup['hosts'] !== []) {
                    echo '<p class="scan-failure-hosts">' . e(implode('، ', (array) $scanGroup['hosts'])) . (count($scanGroup['hosts']) < (int) $scanGroup['count'] ? ' و …' : '') . '</p>';
                }
                if ($scanGroup['messages'] !== []) {
                    echo '<p class="muted" style="font-size:11px">پیام سیستم: ' . e(implode(' | ', (array) $scanGroup['messages'])) . '</p>';
                }
                echo '<ol>';
                foreach ((array) $scanGroup['remedy'] as $scanStep) {
                    echo '<li>' . e((string) $scanStep) . '</li>';
                }
                echo '</ol></article>';
            }
            echo '</div><p class="muted">ابزارهای کمک: <code>php tools\diag_connection.php --client=IP</code> برای آزمون دسترسی یک سیستم و <code>php tools\diag_connection.php --json</code> برای خلاصهٔ وضعیت. تنظیم حساب و ترتیب پروتکل اتصال: <a href="index.php?page=settings#domain-scan">تنظیمات ← اسکن دامنه</a>.</p></section>';
        }
        if ($scanRows !== []) {
            echo '<section class="card ticket-table" id="scan-rows"><div class="table-head"><span>کامپیوتر</span><span>وضعیت</span><span>آنلاین</span><span>پیام</span></div>';
            foreach ($scanRows as $scanRow) {
                $statusLabel = ['pending' => 'در انتظار', 'running' => 'در حال اجرا', 'done' => 'انجام شد', 'offline' => 'آفلاین', 'error' => 'خطا'][(string) $scanRow['status']] ?? (string) $scanRow['status'];
                echo '<div class="table-row"><span class="ticket-title"><b' . asset_name_attr((string) $scanRow['hostname']) . '>' . e(asset_display_name((string) $scanRow['hostname'])) . '</b></span><span>' . e($statusLabel) . '</span><span>' . ((int) $scanRow['online'] === 1 ? 'بله' : 'خیر') . '</span><span style="font-size:11px">' . e((string) ($scanRow['message'] ?? '')) . '</span></div>';
            }
            echo '</section>';
        }
        if ((string) $scanRun['status'] !== 'finished') {
            echo '<script nonce="' . e(csp_nonce()) . '">(function(){var f=document.getElementById("scan-next");if(!f){return;}var s=document.getElementById("scan-status");var busy=false;var stalled=0;function step(){if(busy){return;}busy=true;var d=new FormData(f);d.set("limit","4");fetch("index.php",{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){busy=false;if(!j||!j.ok){if(s){s.textContent="خطا در ادامهٔ اسکن.";}return;}if(j.run&&s){s.textContent="پردازش‌شده: "+j.run.done+" از "+j.run.total;}var rc=document.getElementById("scan-rows");if(rc&&j.rows){var lb={pending:"در انتظار",running:"در حال اجرا",done:"انجام شد",offline:"آفلاین",error:"خطا"};while(rc.children.length>1){rc.removeChild(rc.lastChild);}j.rows.forEach(function(x){var r=document.createElement("div");r.className="table-row";var h=document.createElement("span");h.className="ticket-title";var hb=document.createElement("b");hb.textContent=x.name||x.hostname;h.appendChild(hb);r.appendChild(h);[lb[x.status]||x.status,x.online?"بله":"خیر",x.message||""].forEach(function(t,i){var c=document.createElement("span");if(i===2){c.style.fontSize="11px";}c.textContent=t;r.appendChild(c);});rc.appendChild(r);});}var b=document.getElementById("scan-bar");if(b&&j.run&&j.run.total>0){b.style.width=Math.floor(j.run.done*100/j.run.total)+"%";}if(j.pending>0){if(j.processed>0){stalled=0;}else{stalled++;}if(stalled<4){setTimeout(step,350);}else if(s){s.textContent="اسکن متوقف شد؛ برای ادامه دکمهٔ شروع اسکن را دوباره بزنید.";}}else if(s){s.textContent="اسکن کامل شد؛ صفحه را تازه کنید تا نتایج به‌روز شود.";}}).catch(function(){busy=false;if(s){s.textContent="خطای ارتباط با سرور.";}});}setTimeout(step,500);})();</script>';
        }
    }
    render_footer();
    exit;
}

if ($page === 'assets') {
    $assetUser = require_permission('assets.view');
    if (!is_global_ticket_role($assetUser['role']) && user_service_group($assetUser) !== 'it') {
        http_response_code(403);
        exit('شناسنامه فنی سیستم‌ها فقط در حوزه IT قابل دسترسی است.');
    }
    [$where, $params] = asset_scope($assetUser);
    asset_profile_ensure_schema();
    $assetSearch = trim((string) ($_GET['q'] ?? ''));
    $networkFilter = valid_choice((string) ($_GET['network'] ?? 'all'), ['all', 'online', 'offline', 'unknown'], 'all');
    if ($assetSearch !== '') {
        $where .= ($where !== '' ? ' AND ' : 'WHERE ') . '(a.asset_tag LIKE ? OR a.hostname LIKE ? OR a.serial_number LIKE ? OR a.ip_address LIKE ?)';
        $like = '%' . $assetSearch . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($networkFilter === 'online') {
        $where .= ($where !== '' ? ' AND ' : 'WHERE ') . 'p.ad_online = 1';
    } elseif ($networkFilter === 'offline') {
        $where .= ($where !== '' ? ' AND ' : 'WHERE ') . 'p.ad_scanned_at IS NOT NULL AND p.ad_online = 0';
    } elseif ($networkFilter === 'unknown') {
        $where .= ($where !== '' ? ' AND ' : 'WHERE ') . 'p.ad_scanned_at IS NULL';
    }
    $assetsQuery = db()->prepare('SELECT a.*, d.name AS department_name, p.ad_online, p.ad_last_seen, p.ad_scanned_at FROM assets a LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN asset_profiles p ON p.asset_id = a.id ' . $where . ' ORDER BY a.hostname, a.asset_tag');
    $assetsQuery->execute($params);
    $assetRows = $assetsQuery->fetchAll();
    $canImportAssets = user_can($assetUser, 'domain.import');
    $canPingAssets = user_can($assetUser, 'asset.ping') && (is_global_ticket_role($assetUser['role']) || user_service_group($assetUser) === 'it');
    $canExtractAssets = user_can($assetUser, 'domain.scan');
    $canConfigureNetworkScan = user_can($assetUser, 'settings.domain');
    $networkScanEnabled = domain_scan_preflight() === null;
    $canSelectAssets = $canPingAssets || $canExtractAssets;
    $onlineCount = 0;
    $collectedCount = 0;
    foreach ($assetRows as $assetRow) {
        $onlineCount += (int) ($assetRow['ad_online'] ?? 0) === 1 ? 1 : 0;
        $collectedCount += !empty($assetRow['last_inventory_at']) ? 1 : 0;
    }
    render_header('دارایی‌ها', $assetUser);
    inventory_render_assets_content($assetRows, $assetSearch, $networkFilter, $canImportAssets, $canPingAssets, $canExtractAssets, $canConfigureNetworkScan, $networkScanEnabled, $canSelectAssets, $onlineCount, $collectedCount);
    render_footer();
    exit;
}

if ($page === 'asset') {
    $assetUser = require_permission('assets.view');
    [$scope, $scopeParams] = asset_scope($assetUser);
    $assetId = (int) ($_GET['id'] ?? 0);
    redirect('index.php?page=inventory&id=' . $assetId);
    $assetQuery = db()->prepare('SELECT a.*, d.name AS department_name, u.full_name AS owner_name FROM assets a LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.owner_user_id ' . ($scope ? $scope . ' AND a.id = ?' : 'WHERE a.id = ?') . ' LIMIT 1');
    $assetQuery->execute(array_merge($scopeParams, [$assetId]));
    $asset = $assetQuery->fetch();
    if (!$asset) {
        http_response_code(404);
        render_header('دارایی پیدا نشد', $assetUser);
        echo '<section class="empty-state"><h2>دارایی پیدا نشد</h2></section>';
        render_footer();
        exit;
    }
    $hardware = json_decode((string) $asset['hardware_json'], true) ?: [];
    $software = json_decode((string) $asset['software_json'], true) ?: [];
    $disks = json_decode((string) $asset['disks_json'], true) ?: [];
    $peripherals = json_decode((string) $asset['peripherals_json'], true) ?: [];
    render_header('شناسنامه دارایی', $assetUser);
    $manualPeripherals = is_array($peripherals['manual_entries'] ?? null) ? $peripherals['manual_entries'] : [];
     echo '<section class="page-heading"><div><a class="back-link" href="index.php?page=assets">← بازگشت به دارایی‌ها</a><h1>' . e(asset_display_name($asset['hostname'])) . '</h1><p>' . e(asset_name_title($asset['hostname'], $asset['asset_tag']) ?: $asset['asset_tag']) . ' • آخرین جمع‌آوری: ' . e($asset['last_inventory_at'] ? persian_date($asset['last_inventory_at']) : 'ثبت نشده') . '</p></div><div class="actions"><a class="button secondary" href="index.php?page=inventory&id=' . $assetId . '">ویرایش شناسنامه</a><a class="button secondary" href="index.php?page=asset-history&id=' . $assetId . '">تاریخچه سیستم</a></div></section><section class="asset-facts"><div class="card detail-card"><h3>مشخصات اصلی</h3><dl><dt>شناسه</dt><dd>' . e($asset['asset_tag']) . '</dd><dt>نام کامپیوتر</dt><dd>' . e($asset['hostname']) . '</dd><dt>سریال</dt><dd>' . e($asset['serial_number']) . '</dd><dt>واحد</dt><dd>' . e($asset['department_name'] ?: 'بدون واحد') . '</dd><dt>کاربر دامنه</dt><dd>' . e($asset['domain_username']) . '</dd><dt>سیستم‌عامل</dt><dd>' . e($asset['operating_system']) . '</dd><dt>آنتی‌ویروس</dt><dd>' . e($asset['antivirus']) . '</dd><dt>IP</dt><dd>' . e($asset['ip_address']) . '</dd><dt>MAC</dt><dd>' . e($asset['mac_address']) . '</dd><dt>پردازنده</dt><dd>' . e($asset['cpu']) . '</dd><dt>حافظه</dt><dd>' . e((string) $asset['memory_mb']) . ' MB</dd></dl></div><div class="card detail-card"><h3>سخت‌افزار تکمیلی</h3><pre class="json-view">' . e(json_encode(array_merge($hardware, ['disks' => $disks]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></div><div class="card detail-card"><h3>تجهیزات و اقلام دستی</h3><pre class="json-view">' . e(json_encode(array_merge($peripherals, ['printer_scanner_monitor_case' => $peripherals['case'] ?? ($manualPeripherals['case'] ?? [])]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></div><div class="card detail-card"><h3>نرم‌افزارهای نصب‌شده</h3><pre class="json-view">' . e(json_encode($software, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></div></section>';
    render_footer();
    exit;
}

if ($page === 'organization') {
    $organizationUser = require_permission('org.view');
    org_ensure_schema();
    $canManageOrg = user_can_manage_organization($organizationUser);
    $units = org_units_all(false);
    $users = db()->query('SELECT u.id, u.username, u.full_name, u.employee_number, u.national_code, u.role, u.auth_source, u.is_active, u.org_unit_id, u.manager_user_id FROM users u WHERE u.auth_source <> \'food\' ORDER BY u.full_name')->fetchAll();
    $activeUsers = array_values(array_filter($users, static fn (array $u): bool => (int) $u['is_active'] === 1));
    $ceoUnit = null;
    $deputies = [];
    $departmentsByParent = [];
    $membersByUnit = [];
    foreach ($units as $unit) {
        if ((int) $unit['is_active'] !== 1) {
            continue;
        }
        if ((string) $unit['unit_type'] === 'ceo') {
            $ceoUnit = $unit;
        } elseif ((string) $unit['unit_type'] === 'deputy') {
            $deputies[] = $unit;
        } else {
            $departmentsByParent[(int) ($unit['parent_id'] ?? 0)][] = $unit;
        }
    }
    foreach ($users as $member) {
        $membersByUnit[(int) ($member['org_unit_id'] ?? 0)][] = $member;
    }
    $orgChartUnits = [];
    foreach ($units as $unit) {
        if ((int) ($unit['is_active'] ?? 0) !== 1) {
            continue;
        }
        $orgChartUnits[] = [
            'id' => (int) $unit['id'],
            'name' => (string) $unit['name'],
            'code' => (string) ($unit['code'] ?? ''),
            'parentId' => (int) ($unit['parent_id'] ?? 0),
            'type' => (string) $unit['unit_type'],
            'managerId' => (int) ($unit['manager_user_id'] ?? 0),
            'managerName' => (string) ($unit['manager_name'] ?? ''),
            'primaryAdmin' => (int) ($unit['is_primary_admin'] ?? 0) === 1,
        ];
    }
    $orgChartUsers = [];
    foreach ($users as $chartUser) {
        $orgChartUsers[] = [
            'id' => (int) $chartUser['id'],
            'name' => (string) $chartUser['full_name'],
            'username' => (string) $chartUser['username'],
            'code' => (string) ($chartUser['national_code'] ?: ($chartUser['employee_number'] ?: $chartUser['username'])),
            'active' => (int) $chartUser['is_active'] === 1,
            'unitId' => (int) ($chartUser['org_unit_id'] ?? 0),
            'managerId' => (int) ($chartUser['manager_user_id'] ?? 0),
        ];
    }
    $orgUndo = org_undo_state();
    $orgChartData = json_encode([
        'canManage' => $canManageOrg,
        'undo' => $orgUndo !== null,
        'undoLabel' => (string) ($orgUndo['label'] ?? ''),
        'currentUser' => ['id' => (int) $organizationUser['id'], 'name' => (string) $organizationUser['full_name']],
        'units' => $orgChartUnits,
        'users' => $orgChartUsers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    render_header('مدیریت سازمان', $organizationUser);
    $orgTemplate = <<<'HTML'
<div class="organization-workspace" dir="rtl">
  <header class="org-header">
    <div class="org-brand"><div class="org-brand-icon" aria-hidden="true">▥</div><div><div class="org-brand-title">سامانه ساختار سازمانی</div><div class="org-brand-subtitle">راه‌اندازی و مدیریت چارت</div></div></div>
     <div class="org-divider"></div>
     <div class="org-header-actions"><a class="org-icon-button" href="index.php" title="بازگشت به داشبورد">↩</a><button class="org-icon-button" data-org-tool="undo" disabled title="بازگشت آخرین عملیات">↶ <span>بازگشت</span></button><button class="org-icon-button" data-org-tool="export" title="خروجی JSON">↓ <span>خروجی</span></button><button class="org-icon-button danger" data-org-tool="reset" title="شروع مجدد">↺ <span>ریست</span></button></div>
  </header>
  <div class="org-main-layout">
    <aside class="org-sidebar" id="org-sidebar"></aside>
    <main class="org-canvas" id="org-canvas">
      <div class="org-canvas-toolbar"><input class="org-search" id="org-chart-search" type="search" placeholder="جستجوی واحد یا کاربر..."><div class="org-zoom-controls"><button type="button" data-org-zoom="out">−</button><span id="org-zoom-level">100٪</span><button type="button" data-org-zoom="in">+</button><button type="button" data-org-zoom="reset">↺</button></div></div>
      <div class="org-chart-wrap" id="org-chart-wrap"></div>
    </main>
    <aside class="org-pool-sidebar" id="org-pool-sidebar"></aside>
  </div>
   <div class="org-modal-backdrop" id="org-modal-backdrop"><section class="org-modal" role="dialog" aria-modal="true" aria-labelledby="org-modal-title"><header><div class="org-modal-icon" id="org-modal-icon"></div><div><h2 id="org-modal-title">انتخاب کاربر</h2><p id="org-modal-subtitle">از فهرست زیر انتخاب کنید</p></div><button class="org-modal-close" type="button" data-org-modal="close">×</button></header><div class="org-modal-preview" id="org-modal-preview"></div><div class="org-modal-hint" id="org-modal-hint">روی کاربر موردنظر کلیک کنید تا منصوب شود.</div><div class="org-modal-search"><input id="org-modal-search" type="search" placeholder="جستجو بر اساس نام یا کد ملی..."></div><div class="org-modal-body" id="org-modal-body"></div><footer><span id="org-modal-info">موردی انتخاب نشده</span><button class="org-btn org-btn-ghost" type="button" data-org-modal="close">انصراف</button><button class="org-btn org-btn-success" type="button" id="org-modal-confirm" disabled>تأیید</button></footer></section></div>
   <div class="org-confirm-backdrop" id="org-confirm-backdrop"><section class="org-confirm-modal" role="dialog" aria-modal="true"><div class="org-confirm-icon" id="org-confirm-icon">!</div><h2 id="org-confirm-title">تأیید عملیات</h2><p id="org-confirm-message">آیا مطمئن هستید؟</p><div class="org-confirm-details" id="org-confirm-details"></div><footer><button class="org-btn org-btn-ghost" type="button" data-org-confirm="close">انصراف</button><button class="org-btn org-btn-danger" type="button" id="org-confirm-ok">تأیید</button></footer></section></div>
   <div class="org-undo-bar" id="org-undo-bar"><span>↶</span><b id="org-undo-text"></b><button type="button" data-org-tool="undo">بازگشت</button><button type="button" data-org-undo="close">×</button></div>
  <div class="org-toast" id="org-toast" role="status" aria-live="polite"></div>
  <form method="post" id="org-action-form" class="org-hidden-form">__CSRF__<input type="hidden" name="action" value=""><input type="hidden" name="unit_id" value=""><input type="hidden" name="unit_name" value=""><input type="hidden" name="unit_code" value=""><input type="hidden" name="node_type" value=""><input type="hidden" name="parent_id" value=""><input type="hidden" name="unit_manager_id" value=""><input type="hidden" name="is_primary_admin" value="0"><input type="hidden" name="ceo_user_id" value=""><input type="hidden" name="member_unit_id" value=""><input type="hidden" name="user_id" value=""><input type="hidden" name="manager_user_id" value=""></form>
</div>
HTML;
    echo str_replace(['__CSRF__'], [csrf_field()], $orgTemplate);
    echo '<script nonce="' . e(csp_nonce()) . '">window.PERSIAN_ORG_DATA = ' . $orgChartData . ';</script><script src="assets/organization.js?v=2"></script>';
    render_footer();
    exit;
}

if ($page === 'settings') {
    $user = require_login();
    $canSettingsGeneral = user_can($user, 'settings.general');
    $canSettingsDomain = user_can($user, 'settings.domain');
    $canSettingsUsers = user_can($user, 'settings.users');
    $canSettingsKeyRoles = user_can($user, 'settings.key_roles');
    // ویرایش هر بخش جدا از دیدن آن است؛ بدون کد ویرایش، فرم‌ها غیرفعال (inert) نمایش داده می‌شوند.
    $canSettingsGeneralEdit = user_can($user, 'settings.general_edit');
    $canSettingsDomainEdit = user_can($user, 'settings.domain_edit');
    $canSettingsUsersEdit = user_can($user, 'settings.users_edit');
    $canSettingsKeyRolesEdit = user_can($user, 'settings.key_roles_edit');
    $appName = setting('app_name', (string) cfg('app.name', 'سامانه پشتیبانی'));
    $logo = setting('app_logo', (string) cfg('app.logo', ''));
    $foodBrandName = setting('food_ticket_brand_name', $appName);
    $foodBrandLogo = setting('food_ticket_brand_logo', $logo);
    $departments = db()->query('SELECT id, name, code FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
    $users = db()->query('SELECT u.id, u.username, u.full_name, u.employee_number, u.national_code, u.role, u.auth_source, u.is_active, u.is_it_agent, u.is_primary_admin, u.department_id, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.auth_source <> \'food\' ORDER BY u.full_name')->fetchAll();
    $canManageSettingsUsers = user_is_primary_admin($user);
    $roleSelectableUsers = array_values(array_filter($users, static fn (array $roleUser): bool => (int) ($roleUser['is_active'] ?? 0) === 1));
    $roleCards = '';
    foreach ([
        'supervisor' => ['label' => 'سوپروایز', 'note' => 'دسترسی سوپروایزر: مشاهده و مدیریت تیکت‌ها، تأیید و بستن.'],
        'inspection' => ['label' => 'بازرسی', 'note' => 'مجوز ثبت ورود رسانه در کنترل CD/DVD.'],
        'it_expert' => ['label' => 'کارشناس IT', 'note' => 'دسترسی ادمین اصلی سامانه.'],
    ] as $roleKey => $roleMeta) {
        $roleIds = key_role_user_ids($roleKey);
        $roleCards .= '<div class="key-role-block"><h3>' . e($roleMeta['label']) . '</h3><p class="muted">' . e($roleMeta['note']) . '</p><div class="form-grid">';
        for ($slot = 0; $slot < 2; $slot++) {
            $selectedId = (int) ($roleIds[$slot] ?? 0);
            $roleCards .= '<label>کاربر ' . ($slot + 1) . '<select name="' . $roleKey . '_ids[]"><option value="0">— انتخاب نشده —</option>';
            foreach ($roleSelectableUsers as $roleUser) {
                $roleCards .= '<option value="' . (int) $roleUser['id'] . '" ' . ((int) $roleUser['id'] === $selectedId ? 'selected' : '') . '>' . e($roleUser['full_name'] . ' - ' . $roleUser['username']) . '</option>';
            }
            $roleCards .= '</select></label>';
        }
        $roleCards .= '</div></div>';
    }
    render_header('تنظیمات سامانه', $user);
    echo '<section class="page-heading"><div><span class="eyebrow">مدیریت سامانه</span><h1>تنظیمات</h1><p>مشخصات ظاهری، اتصال Active Directory و نقش کاربران را مدیریت کنید.</p></div></section>';
    $tabLinks = '';
    if ($canSettingsGeneral) {
        $tabLinks .= '<a class="' . ($canSettingsGeneral ? 'active' : '') . '" href="#general">عمومی</a>';
    }
    if ($canSettingsDomain) {
        $tabLinks .= '<a class="' . (!$canSettingsGeneral && $canSettingsDomain ? 'active' : '') . '" href="#domain">اتصال دامین</a>';
    }
    if ($canSettingsUsers) {
        $tabLinks .= '<a class="' . (!$canSettingsGeneral && !$canSettingsDomain && $canSettingsUsers ? 'active' : '') . '" href="#users">کاربران</a>';
    }
    if ($canManageSettingsUsers) {
        $tabLinks .= '<a href="#roles">نقش‌ها و دسترسی‌ها</a>';
    }
    echo '<div class="settings-tabs">' . $tabLinks . '</div>';
    echo '<section class="settings-stack">';

    if ($canSettingsGeneral) {
    echo '<div class="settings-panel settings-panel-grid" id="general"' . ($canSettingsGeneralEdit ? '' : ' inert') . '>';
    echo '<div class="card form-card"><h2>اطلاعات سامانه</h2><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="general"><label>نام سامانه<input name="app_name" required value="' . e($appName) . '"></label><label>لوگو<input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml"><small>لوگوی جدید اختیاری است؛ PNG، JPG یا SVG تا ۲ مگابایت.</small></label><label>ظاهر نام کامپیوترها<select name="asset_hostname_style"><option value="short_lower"' . (setting('asset_hostname_style', 'short_lower') === 'short_lower' ? ' selected' : '') . '>فقط نام، با حروف کوچک (adminit2) — پیش‌فرض</option><option value="short"' . (setting('asset_hostname_style', 'short_lower') === 'short' ? ' selected' : '') . '>فقط نام، با حروف اصلی (ADMINIT2)</option><option value="full"' . (setting('asset_hostname_style', 'short_lower') === 'full' ? ' selected' : '') . '>نام کامل دامنه (ADMINIT2.DOMAIN.LOCAL)</option></select><small>روی فهرست شناسنامه‌ها، فهرست اسکن دامنه، فهرست سیستم‌ها و کمبوی «سیستم مرتبط» اثر دارد. نام کامل همیشه به‌صورت راهنما (tooltip) روی نام می‌ماند.</small></label>' . ($logo ? '<div class="current-logo"><img src="' . e($logo) . '" alt="لوگوی فعلی"><span>لوگوی فعلی</span></div>' : '') . '<button class="button" type="submit">ذخیره اطلاعات عمومی</button></form></div>';
    echo '<div class="card form-card login-branding-settings" id="login-branding"><h2>مدیریت صفحه ورود</h2><p class="muted">عنوان‌ها، پیام متحرک و لوگوی مخصوص صفحه ورود را تنظیم کنید. لوگو PNG یا JPG و حداکثر ۲ مگابایت باشد.</p><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="login_branding"><input type="hidden" name="app_name" value="' . e($appName) . '"><label>عنوان اصلی<input name="login_title" maxlength="120" value="' . e(setting('login_title', 'به سامانه پشتیبانی خوش آمدید')) . '"></label><label>متن معرفی<textarea name="login_intro" rows="2" maxlength="500">' . e(setting('login_intro', 'برای ثبت و پیگیری درخواست‌های خود وارد شوید.')) . '</textarea></label><label>پیام متحرک ابتدای صفحه (اختیاری)<input name="login_announcement" maxlength="240" value="' . e(setting('login_announcement', '')) . '" placeholder="مثلاً اطلاعیه نگهداری سامانه در روز ..."></label><div class="form-grid"><label>عنوان کارت معرفی<input name="login_art_title" maxlength="100" value="' . e(setting('login_art_title', 'پشتیبانی، ساده و شفاف')) . '"></label><label>متن کارت معرفی<input name="login_art_text" maxlength="300" value="' . e(setting('login_art_text', 'هر درخواست یک شماره پیگیری دارد و مسیر رسیدگی آن برای شما روشن است.')) . '"></label><label>عنوان راهنمای ورود<input name="login_note_title" maxlength="100" value="' . e(setting('login_note_title', 'ورود یکپارچه سازمانی')) . '"></label><label>متن راهنمای ورود<input name="login_note_text" maxlength="300" value="' . e(setting('login_note_text', 'کاربران شبکه با حساب Active Directory خود وارد می‌شوند.')) . '"></label></div><label>لوگوی صفحه ورود<input type="file" name="login_logo" accept="image/png,image/jpeg"></label>' . (setting('app_login_logo', '') ? '<div class="current-logo"><img src="' . e((string) setting('app_login_logo', '')) . '" alt="لوگوی صفحه ورود"><span>لوگوی اختصاصی صفحه ورود</span></div>' : '') . '<button class="button" type="submit">ذخیره تنظیمات صفحه ورود</button></form></div>';
    echo '<div class="card form-card food-ticket-brand-settings" id="food-ticket-brand"><h2>هویت پنل چاپ فیش غذا</h2><p class="muted">این نام و لوگو هنگام ورود از سامانه اصلی به پنل چاپ فیش منتقل می‌شود و با تغییرات بعدی نیز همگام خواهد ماند.</p><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="save_food_ticket_brand"><label>نام سامانه چاپ فیش<input name="food_ticket_brand_name" required value="' . e($foodBrandName) . '"></label><label>لوگوی پنل چاپ فیش<input type="file" name="food_ticket_logo" accept="image/png,image/jpeg"><small>PNG یا JPG، حداکثر ۲ مگابایت.</small></label>' . ($foodBrandLogo ? '<div class="current-logo"><img src="' . e($foodBrandLogo) . '" alt="لوگوی پنل چاپ فیش"><span>لوگوی فعلی پنل فیش</span></div>' : '') . '<button class="button" type="submit">ذخیره هویت پنل چاپ فیش</button></form></div>';
    if (user_is_primary_admin($user)) {
        $activityRetention = activity_log_retention_days();
        $activityCount = 0;
        try {
            activity_logs_ensure();
            $activityCount = (int) db()->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
        } catch (Throwable) {
            $activityCount = 0;
        }
        echo '<div class="card form-card" id="activity-log-settings"><h2>گزارش فعالیت کاربران</h2><p class="muted">تمام عملیات کاربران ثبت می‌شود. رکوردهای قدیمی‌تر از بازهٔ نگهداری به‌صورت خودکار با کران پاک می‌شوند. رکوردهای فعلی: ' . number_format($activityCount) . '</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="prune_activity_logs"><label>بازهٔ نگهداری (روز)<input type="number" name="retention_days" min="7" max="3650" value="' . (int) $activityRetention . '"></label><small>پیش‌فرض ۶۰ روز (حدود دو ماه).</small><button class="button secondary" type="submit">ذخیره و پاک‌سازی لاگ‌های قدیمی</button></form></div>';
    }
    echo '</div>';
    }

    if ($canSettingsDomain) {
    echo '<div class="settings-panel settings-panel-grid" id="domain"' . ($canSettingsDomainEdit ? '' : ' inert') . '>';
    echo '<div class="card form-card settings-panel-tall"><h2>اتصال Active Directory</h2><p class="muted">رمز حساب سرویس در صورت خالی‌گذاشتن تغییر نمی‌کند.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="ldap"><input type="hidden" name="app_name" value="' . e($appName) . '"><label class="switch-row"><input type="checkbox" name="ldap_enabled" value="1" ' . (setting('ldap_enabled', cfg('ldap.enabled') ? '1' : '0') === '1' ? 'checked' : '') . '> ورود کاربران شبکه فعال باشد</label><div class="form-grid"><label>آدرس کنترلر<input name="ldap_host" value="' . e(setting('ldap_host', (string) cfg('ldap.host', ''))) . '"></label><label>پورت<input type="number" name="ldap_port" value="' . e(setting('ldap_port', (string) cfg('ldap.port', '389'))) . '"></label><label class="full">Base DN<input name="ldap_base_dn" value="' . e(setting('ldap_base_dn', (string) cfg('ldap.base_dn', ''))) . '"></label><label class="full">حساب سرویس<input name="ldap_bind_dn" value="' . e(setting('ldap_bind_dn', (string) cfg('ldap.bind_dn', ''))) . '"></label><label class="full">رمز حساب سرویس<input type="password" name="ldap_bind_password" placeholder="بدون تغییر"></label><label>Domain suffix<input name="ldap_domain_suffix" value="' . e(setting('ldap_domain_suffix', (string) cfg('ldap.domain_suffix', ''))) . '"></label></div><label class="switch-row"><input type="checkbox" name="ldap_ssl" value="1" ' . (setting('ldap_ssl', cfg('ldap.ssl') ? '1' : '0') === '1' ? 'checked' : '') . '> استفاده از LDAPS</label><button class="button" type="submit">ذخیره تنظیمات دامین</button></form></div>';
    echo '<div class="card form-card" id="domain-scan"' . (user_can($user, 'asset.remote_extract') ? '' : ' inert') . '><h2>اسکن دامنه و استخراج از شبکه</h2><p class="muted">همهٔ کامپیوترهای دامنه از Active Directory خوانده می‌شوند، سپس در صورت آنلاین بودن، اطلاعات سخت‌افزاری آن‌ها از راه دور دریافت و در شناسنامه ثبت می‌شود. برای دریافت سخت‌افزار، حساب وارد‌شده باید روی کلاینت‌ها دسترسی ادمین محلی داشته باشد.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="domain_scan"><input type="hidden" name="app_name" value="' . e($appName) . '"><p class="muted">استخراج اطلاعات سیستم‌های تیک‌خورده همیشه فعال است و نیازی به کلید فعال‌سازی ندارد. اگر نام کاربری را خالی بگذارید، از حساب سرویس Windows همین پنل استفاده می‌شود (باید ادمین محلی کلاینت‌ها باشد).</p><div class="form-grid"><label>دامنه (NetBIOS)<input name="domain_scan_domain" value="' . e(setting('domain_scan_domain', '')) . '" placeholder="COMPANY"></label><label>حساب کاربری ادمین<input name="domain_scan_username" value="' . e(setting('domain_scan_username', '')) . '" placeholder="administrator"></label><label>رمز حساب<input type="password" name="domain_scan_password" placeholder="بدون تغییر"></label><label>زمان انتظار پینگ (میلی‌ثانیه)<input type="number" name="domain_scan_timeout" min="500" max="30000" value="' . e(setting('domain_scan_timeout', '2500')) . '"></label><label>ترتیب پروتکل اتصال (۱.۳۷)<select name="domain_scan_protocols"><option value=""' . (setting('domain_scan_protocols', '') === '' ? ' selected' : '') . '>خودکار (اول DCOM، بعد WinRM)</option><option value="Wsman,Dcom"' . (setting('domain_scan_protocols', '') === 'Wsman,Dcom' ? ' selected' : '') . '>اول WinRM، بعد DCOM (اگر DCOM بسته است)</option><option value="Dcom"' . (setting('domain_scan_protocols', '') === 'Dcom' ? ' selected' : '') . '>فقط DCOM</option><option value="Wsman"' . (setting('domain_scan_protocols', '') === 'Wsman' ? ' selected' : '') . '>فقط WinRM (نیازمند Enable-PSRemoting روی سرور)</option></select></label></div><p class="muted">خطاهای رایج استخراج («RPC/WMI در دسترس نیست» و «دسترسی رد شد») در صفحهٔ «اسکن دامنه» با راه‌حل گام‌به‌گام نمایش داده می‌شوند؛ پس از هر اصلاح، از همان صفحه «تلاش دوباره برای ناموفق‌ها» را بزنید.</p><button class="button" type="submit">ذخیره تنظیمات اسکن دامنه</button></form></div>';
    echo '<div class="card form-card" id="ldap-sync"><h2>معرفی کاربران دامین</h2><p class="muted">همه کاربران قابل مشاهده در Base DN را بدون نیاز به اولین ورود به سامانه معرفی می‌کند. حساب‌هایی که دیگر در دامین پیدا نشوند یا غیرفعال باشند، غیرفعال می‌شوند.</p><div class="actions" style="margin-top:0"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="sync_ldap_users"><button class="button" type="submit">همگام‌سازی همه کاربران دامین</button></form><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="diagnose_ldap"><button class="button secondary" type="submit">تشخیص اتصال دامنه</button></form></div></div>';
    echo '<div class="card form-card settings-panel-full" id="ldap-role-map"><h2>نقش‌دهی گروه‌های AD</h2><p class="muted">هر خط یک JSON معتبر برای نقش باشد؛ مثال: {"agent":["CN=IT-Helpdesk,OU=Groups,DC=example,DC=local"]}. کاربران جدید دامینی بر اساس عضویت گروه نقش می‌گیرند و نقش کاربران فعلی دستی حفظ می‌شود.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_settings"><input type="hidden" name="settings_section" value="ldap"><input type="hidden" name="app_name" value="' . e($appName) . '"><label>تنظیم JSON نگاشت گروه به نقش<textarea name="ldap_group_map" rows="2" class="compact-textarea" placeholder="{&quot;agent&quot;:[&quot;CN=IT-Helpdesk,...&quot;]}">' . e(setting('ldap_group_map', '{}')) . '</textarea></label><button class="button secondary" type="submit">ذخیره نگاشت گروه‌ها</button></form></div>';
    echo '</div>';
    }

    if ($canSettingsUsers) {
    echo '<div class="card form-card settings-panel" id="users"' . ($canSettingsUsersEdit ? '' : ' inert') . '><h2>کاربران</h2><p class="muted">کاربران دامینی با همگام‌سازی دامین یا در اولین ورود معرفی می‌شوند. ادمین اصلی می‌تواند از همین بخش نقش و وضعیت کاربران را تغییر دهد.</p><div class="category-list">';
    foreach ($users as $managedUser) {
        echo '<div><span><strong>' . e($managedUser['full_name']) . '</strong><small>' . e($managedUser['username']) . ' • ' . ($managedUser['auth_source'] === 'ldap' ? 'دامینی' : 'محلی') . ' • ' . ((int) $managedUser['is_active'] === 1 ? 'فعال' : 'غیرفعال') . '</small></span>';
        if ($canManageSettingsUsers) {
            $roleOptionsHtml = '';
            foreach (permission_roles() as $roleCode => $roleLabel) {
                $roleOptionsHtml .= '<option value="' . e($roleCode) . '" ' . ($managedUser['role'] === $roleCode ? 'selected' : '') . '>' . e($roleLabel) . '</option>';
            }
            echo '<form method="post" class="role-form">' . csrf_field() . '<input type="hidden" name="action" value="update_user_role"><input type="hidden" name="return_page" value="settings"><input type="hidden" name="user_id" value="' . (int) $managedUser['id'] . '"><input type="hidden" name="is_it_agent" value="' . ((int) $managedUser['is_it_agent'] === 1 ? '1' : '0') . '"><label>کد پرسنلی<input name="employee_number" value="' . e($managedUser['employee_number'] ?? '') . '" placeholder="کد دستگاه دستگاه حضور و غیاب"></label><label>کد ملی<input name="national_code" inputmode="numeric" value="' . e($managedUser['national_code'] ?? '') . '" placeholder="برای چاپ غذا"></label><select name="role">' . $roleOptionsHtml . '</select><select name="department_id"><option value="">بدون واحد</option>';
            foreach ($departments as $department) {
                echo '<option value="' . (int) $department['id'] . '" ' . ((int) $managedUser['department_id'] === (int) $department['id'] ? 'selected' : '') . '>' . e($department['name']) . '</option>';
            }
            echo '</select><label class="check-label"><input type="checkbox" name="is_active" value="1" ' . ((int) ($managedUser['is_active'] ?? 0) === 1 ? 'checked' : '') . '> فعال</label><label class="check-label"><input type="checkbox" name="is_primary_admin" value="1" ' . ((int) ($managedUser['is_primary_admin'] ?? 0) === 1 ? 'checked' : '') . '> ادمین اصلی</label><button class="button secondary" type="submit">ذخیره</button></form>';
        } else {
            echo '<span class="status admin-readonly">' . (user_is_primary_admin($managedUser) ? 'ادمین اصلی' : e(permission_roles()[$managedUser['role']] ?? $managedUser['role'])) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '<div id="key-roles" class="settings-subform"' . ($canSettingsKeyRolesEdit ? '' : ' inert') . '><h3>نقش‌های کلیدی</h3><p class="muted">برای هر نقش حداکثر دو کاربر انتخاب کنید. «کارشناس IT» دسترسی ادمین اصلی می‌گیرد، «سوپروایز» نقش سوپروایزر را می‌گیرد و «بازرسی» مجوز ثبت ورود رسانه در CD/DVD را دارد.</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_key_roles">' . $roleCards . '<button class="button" type="submit">ذخیره نقش‌های کلیدی</button></form></div>';
    echo '</div>';
    }

    if ($canManageSettingsUsers) {
        $roleMeta = [
            'primary_admin' => ['icon' => 'owner', 'desc' => 'دسترسی کامل و غیرقابل‌تغییر', 'locked' => true],
            'supervisor' => ['icon' => 'supervisor', 'desc' => 'نظارت و مدیریت تیکت‌ها', 'locked' => false],
            'support_manager' => ['icon' => 'manager', 'desc' => 'مدیریت تیم پشتیبانی', 'locked' => false],
            'inspector' => ['icon' => 'inspector', 'desc' => 'بازرسی و کنترل CD/DVD', 'locked' => false],
            'manager' => ['icon' => 'manager', 'desc' => 'مدیریت واحد و اداره', 'locked' => false],
            'agent' => ['icon' => 'expert', 'desc' => 'رسیدگی به تیکت‌ها', 'locked' => false],
            'user' => ['icon' => 'user', 'desc' => 'کاربر عادی سامانه', 'locked' => false],
        ];
        $allCodes = permission_all_codes();
        $catalog = permission_catalog();
        $totalAll = count($allCodes);
        echo '<div class="card form-card settings-panel roles-shell" id="roles">';
        echo '<div class="roles-shell-head"><div><h2>نقش‌ها و سطوح دسترسی</h2><p class="muted">یک نقش را انتخاب کنید و منوها/بخش‌هایی که اجازه دارد را تیک بزنید. هر چیزی تیک نخورد، برای آن نقش پنهان می‌ماند.</p><p class="muted">تا وقتی دسترسی‌های یک نقش را <strong>ذخیره نکنید</strong>، پیش‌فرض‌های داخل کد اعمال می‌شود؛ به‌محض ذخیره، همان مقادیر شما اولویت می‌گیرد (حتی اگر چیزی تیک نخورده باشد) و هر وقت خواستید با «بازگردانی به پیش‌فرض کد» نقش دوباره به پیش‌فرض برمی‌گردد.</p></div></div>';
        echo '<div class="roles-layout"><aside class="roles-sidebar"><div class="roles-sidebar-head"><strong>نقش‌های سامانه</strong><small>برای ویرایش انتخاب کنید</small></div><div class="roles-list">';
        foreach (permission_roles() as $roleCode => $roleLabel) {
            $meta = $roleMeta[$roleCode] ?? ['icon' => 'user', 'desc' => '', 'locked' => false];
            $roleCount = $roleCode === 'primary_admin' ? $totalAll : count(array_intersect(role_permission_codes($roleCode), $allCodes));
            $pct = $totalAll > 0 ? (int) round($roleCount / $totalAll * 100) : 0;
            echo '<button type="button" class="role-item' . ($roleCode === 'primary_admin' ? ' locked' : '') . ($roleCode === 'supervisor' ? ' active' : '') . '" data-role-tab="' . e($roleCode) . '"><span class="role-avatar">' . permission_icon($meta['icon']) . '</span><span class="role-info"><span class="role-name">' . e($roleLabel) . ($meta['locked'] ? '<span class="lock-badge">قفل</span>' : '') . '</span><span class="role-stats"><span class="role-count">' . $roleCount . ' از ' . $totalAll . '</span><span class="mini-bar"><span class="mini-bar-fill" style="width:' . $pct . '%"></span></span></span></span></button>';
        }
        echo '</div></aside><section class="roles-content">';
        $firstRole = true;
        foreach (permission_roles() as $roleCode => $roleLabel) {
            $isPrimary = $roleCode === 'primary_admin';
            $active = $isPrimary ? $allCodes : array_values(array_intersect(role_permission_codes($roleCode), $allCodes));
            // ۱.۳۷.۷ — وضعیت این نقش: «پیش‌فرض کد» (دست‌نخورده) یا «ویرایش‌شده» (از فرم ذخیره شده).
            $roleIsCustom = !$isPrimary && role_permissions_custom($roleCode);
            $roleStateBadge = $isPrimary
                ? ''
                : '<span class="status ' . ($roleIsCustom ? 'warning' : 'done') . '" title="' . ($roleIsCustom
                    ? 'دسترسی‌های این نقش از فرم ذخیره شده و همین‌ها اعمال می‌شود؛ پیش‌فرض‌های کد روی آن اثر ندارند.'
                    : 'این نقش دست‌نخورده است؛ پیش‌فرض‌های داخل کد اعمال می‌شود و تغییرهای آیندهٔ کد خودکار می‌رسد.') . '">'
                . ($roleIsCustom ? 'ویرایش‌شده' : 'پیش‌فرض کد') . '</span>';
            echo '<form method="post" class="role-panel' . ($firstRole ? ' active' : '') . '" data-role-panel="' . e($roleCode) . '">' . csrf_field() . '<input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role" value="' . e($roleCode) . '">';
            echo '<div class="role-panel-head"><div class="role-panel-title"><span class="role-icon-lg">' . permission_icon($roleMeta[$roleCode]['icon'] ?? 'user') . '</span><div><h3>' . e($roleLabel) . '</h3><p class="muted">' . ($isPrimary ? 'این نقش به همهٔ بخش‌ها دسترسی کامل دارد و قابل محدودکردن نیست.' : e($roleMeta[$roleCode]['desc'] ?? '')) . '</p></div>' . $roleStateBadge . '</div><div class="role-active-count"><small>دسترسی‌های فعال</small><strong data-role-count="' . e($roleCode) . '">' . count($active) . '<span>/' . $totalAll . '</span></strong></div></div>';
            if ($isPrimary) {
                echo '<div class="perm-locked-note"><span>قفل</span> ادمین اصلی همیشه به همهٔ بخش‌ها دسترسی دارد.</div>';
            } else {
                echo '<div class="perm-toolbar"><button type="button" class="toolbar-btn" data-perm-all="1">انتخاب همه</button><button type="button" class="toolbar-btn" data-perm-none="1">حذف همه</button><div class="perm-toolbar-spacer"></div><span class="perm-changes" data-role-changes hidden></span><button type="button" class="toolbar-btn warn" data-perm-reset hidden>بازگشت به حالت اولیه</button></div>';
                echo '<div class="perm-search"><input type="search" class="perm-search-input" data-perm-search placeholder="جست‌وجوی دسترسی یا گروه..."></div>';
                echo '<div class="groups-container">';
                foreach ($catalog as $group) {
                    // ماژول چاپ فیش فقط برای نقش‌های مجاز نمایش داده می‌شود.
                    $groupAllFood = $group['items'] !== [] && array_reduce($group['items'], static fn (bool $carry, array $item): bool => $carry && str_starts_with((string) $item['code'], 'food.'), true);
                    if ($groupAllFood && !in_array($roleCode, print_fish_allowed_roles(), true)) {
                        continue;
                    }
                    $groupCodes = array_map(static fn (array $item): string => $item['code'], $group['items']);
                    $checkedCount = count(array_intersect($groupCodes, $active));
                    $isEmpty = $checkedCount === 0;
                    $isFull = $checkedCount === count($groupCodes);
                    $countClass = $isFull ? 'full' : ($isEmpty ? 'empty' : 'partial');
                    $pct = count($groupCodes) > 0 ? (int) round($checkedCount / count($groupCodes) * 100) : 0;
                    echo '<div class="group-card" data-perm-group-card="' . e($group['key']) . '" data-group-label="' . e($group['label']) . '"><div class="group-header" data-group-toggle="' . e($group['key']) . '"><span class="group-toggle">◀</span><span class="group-icon">' . permission_icon('g-' . $group['key']) . '</span><span class="group-info"><span class="group-name">' . e($group['label']) . '</span></span><span class="group-progress"><span class="group-count ' . $countClass . '" data-group-count>' . $checkedCount . ' از ' . count($groupCodes) . '</span><span class="group-bar"><span class="group-bar-fill" style="width:' . $pct . '%"></span></span></span><button type="button" class="group-select-all" data-group-all="' . e($group['key']) . '">' . ($isFull ? 'حذف همه' : 'انتخاب همه') . '</button></div><div class="group-body">';
                    // ویرایش (فرزند بخش) زیر والدش با تورفتگی نمایش داده می‌شود و خودش جدا از فهرست اصلی نیست.
                    $editMap = permission_edit_map();
                    $editChildCodes = array_values($editMap);
                    $itemsByCode = [];
                    foreach ($group['items'] as $gItem) {
                        $itemsByCode[$gItem['code']] = $gItem;
                    }
                    $renderPerm = static function (array $pItem, bool $isChild) use ($active, $group): void {
                        $pChecked = in_array($pItem['code'], $active, true);
                        echo '<label class="perm-item' . ($isChild ? ' perm-child' : '') . ($pChecked ? ' checked' : '') . '" data-perm-item' . ($isChild ? ' data-perm-edit="1"' : '') . ' data-perm-label="' . e($pItem['label']) . '" data-perm-group="' . e($group['key']) . '"><input type="checkbox" name="permissions[]" value="' . e($pItem['code']) . '"' . ($pChecked ? ' checked' : '') . '><span class="perm-check">✓</span><span class="perm-label">' . e($pItem['label']) . '</span></label>';
                    };
                    foreach ($group['items'] as $item) {
                        if (in_array($item['code'], $editChildCodes, true)) {
                            continue;
                        }
                        $renderPerm($item, false);
                        if (isset($editMap[$item['code']], $itemsByCode[$editMap[$item['code']]])) {
                            $renderPerm($itemsByCode[$editMap[$item['code']]], true);
                        }
                    }
                    echo '</div></div>';
                }
                echo '<div class="perm-empty" data-perm-empty hidden>دسترسی‌ای با این عبارت یافت نشد.</div>';
                echo '</div>';
                echo '<div class="role-actions"><button class="button" type="submit">ذخیره دسترسی‌های ' . e($roleLabel) . '</button>'
                    . '<button class="button secondary" type="submit" name="action" value="reset_role_defaults" data-confirm="دسترسی‌های این نقش به پیش‌فرض داخل کد برگردد؟ تنظیم دستی این نقش پاک می‌شود.">بازگردانی به پیش‌فرض کد</button>'
                    . '</div>';
            }
            echo '</form>';
            $firstRole = false;
        }
        echo '</section></div>';
        echo '<div class="roles-shell-foot"><form method="post" class="inline-action">' . csrf_field() . '<input type="hidden" name="action" value="reset_role_permissions"><button class="button secondary" type="submit">بازگرداندن همهٔ دسترسی‌ها به پیش‌فرض</button></form><button type="button" class="button secondary" data-copy-role>کپی از نقش دیگر</button></div>';
        echo '</div>';

        echo '<div class="copy-modal" data-copy-modal hidden><div class="copy-modal-card"><div class="copy-modal-head"><h3>کپی دسترسی‌ها</h3><p class="muted">دسترسی‌های کدام نقش روی نقش فعلی کپی شود؟</p></div><div class="copy-modal-body">';
        foreach (permission_roles() as $srcCode => $srcLabel) {
            echo '<button type="button" class="copy-option" data-copy-source="' . e($srcCode) . '"><span class="copy-avatar">' . permission_icon($roleMeta[$srcCode]['icon'] ?? 'user') . '</span><span class="copy-info"><strong>' . e($srcLabel) . '</strong><small>' . ($srcCode === 'primary_admin' ? $totalAll : count(array_intersect(role_permission_codes($srcCode), $allCodes))) . ' از ' . $totalAll . ' دسترسی فعال</small></span></button>';
        }
        echo '</div><div class="copy-modal-foot"><button type="button" class="button secondary" data-copy-cancel>انصراف</button><button type="button" class="button" data-copy-apply disabled>کپی کن</button></div></div></div>';
    }

    echo '</section>';
    render_footer();
    exit;
}

if ($page === 'logs') {
    $logUser = require_permission('logs.view');
    $levelFilter = valid_choice((string) ($_GET['level'] ?? ''), ['', 'error', 'warning', 'info', 'debug'], '');
    $logRows = system_logs_recent(200, $levelFilter);
    render_header('گزارش خطا', $logUser);
    echo '<section class="page-heading"><div><span class="eyebrow">System Logs</span><h1>گزارش خطا و رویدادهای سامانه</h1><p>خطاهای برنامه، استخراج شناسنامه و رویدادهای مهم اینجا ثبت می‌شوند. فایل خام: storage/logs/app.log</p></div><div class="actions"><a class="button secondary" href="index.php?page=logs">همه</a><a class="button secondary" href="index.php?page=logs&level=error">فقط خطاها</a><a class="button secondary" href="index.php?page=logs&level=info">اطلاعات</a></div></section>';
    echo '<section class="card ticket-table audit-table"><div class="table-head"><span>زمان</span><span>سطح</span><span>بخش</span><span>پیام</span><span>آدرس</span></div>';
    if ($logRows) {
        foreach ($logRows as $logRow) {
            $levelClass = $logRow['level'] === 'error' ? 'critical' : ($logRow['level'] === 'warning' ? 'urgent' : 'normal');
            echo '<div class="table-row"><span class="date-cell">' . e(persian_date($logRow['created_at'])) . '</span><span><em class="status ' . $levelClass . '">' . e($logRow['level']) . '</em></span><span>' . e($logRow['context']) . '</span><span class="muted">' . e($logRow['message']) . ($logRow['meta_json'] ? '<br><small>' . e((string) $logRow['meta_json']) . '</small>' : '') . '</span><span class="muted">' . e($logRow['request_uri']) . '</span></div>';
        }
    } else {
        echo '<div class="table-row"><span class="muted">گزارشی ثبت نشده است.</span></div>';
    }
    echo '</section>';
    render_footer();
    exit;
}

if ($page !== 'dashboard') {
    redirect('index.php');
}

    $isStaff = is_staff_role($user['role']);
[$ticketWhere, $ticketParams] = ticket_scope($user);
$countQuery = db()->prepare('SELECT status, COUNT(*) AS total FROM tickets t ' . $ticketWhere . ' GROUP BY status');
$countQuery->execute($ticketParams);
$counts = ['new' => 0, 'manager_review' => 0, 'assigned' => 0, 'in_progress' => 0, 'waiting_user' => 0, 'open' => 0, 'pending' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($countQuery->fetchAll() as $count) {
    $counts[$count['status']] = (int) $count['total'];
}
$listQuery = db()->prepare('SELECT t.*, c.name AS category_name, d.name AS department_name, r.full_name AS requester_name, a.full_name AS assignee_name FROM tickets t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN departments d ON d.id = t.department_id JOIN users r ON r.id = t.requester_id LEFT JOIN users a ON a.id = t.assigned_to ' . $ticketWhere . ' ORDER BY t.updated_at DESC LIMIT 50');
$listQuery->execute($ticketParams);
$tickets = $listQuery->fetchAll();
render_header('داشبورد', $user);
$activeCount = $counts['new'] + $counts['manager_review'] + $counts['assigned'] + $counts['in_progress'] + $counts['waiting_user'];
$cdDvdReady = cd_dvd_available();
echo '<section class="dashboard-hero"><div><span class="eyebrow">' . ($isStaff ? 'مرکز کنترل پشتیبانی' : 'فضای درخواست‌های شما') . '</span><h1>سلام ' . e($user['full_name']) . '،<br><strong>چه کمکی از ما می‌خواهید؟</strong></h1><p>درخواست جدید ثبت کنید یا وضعیت تیکت‌های قبلی را دنبال کنید.</p></div><a class="button hero-button" href="index.php?page=new-ticket"><span>＋</span> ثبت تیکت جدید</a></section><section class="stat-grid"><a class="stat-card accent" href="index.php?page=reports&amp;status=active" title="نمایش تیکت‌های در حال پیگیری"><span class="stat-icon">◈</span><div><strong>' . $activeCount . '</strong><small>در حال پیگیری</small></div></a><a class="stat-card" href="index.php?page=reports&amp;status=waiting_user" title="نمایش تیکت‌های در انتظار پاسخ کاربر"><span class="stat-icon blue">◌</span><div><strong>' . $counts['waiting_user'] . '</strong><small>در انتظار پاسخ کاربر</small></div></a><a class="stat-card" href="index.php?page=reports&amp;status=resolved" title="نمایش تیکت‌های حل‌شده و بسته‌شده"><span class="stat-icon green">✓</span><div><strong>' . $counts['resolved'] . '</strong><small>حل‌شده</small></div></a><a class="stat-card" href="index.php?page=reports&amp;status=all" title="نمایش همهٔ تیکت‌های من در گزارش‌ها"><span class="stat-icon gray">▣</span><div><strong>' . count($tickets) . '</strong><small>نمایش داده‌شده</small></div></a></section>';
if ($cdDvdReady) {
    echo cd_dvd_render_surface($user, 'index.php');
     echo '<script src="assets/cd-dvd.js?v=3.4.8"></script>';
}
echo '<section class="list-section"><div class="section-title"><div><h2>' . ($isStaff ? 'آخرین درخواست‌ها' : 'تیکت‌های من') . '</h2><p>آخرین تغییرات در ابتدا نمایش داده می‌شود.</p></div><a href="index.php?page=new-ticket">ثبت درخواست ←</a></div>';
if (!$tickets) {
    echo '<div class="card empty-state"><div class="empty-icon">✦</div><h3>هنوز تیکتی ثبت نشده است</h3><p>برای شروع، اولین درخواست خود را ثبت کنید.</p><a class="button" href="index.php?page=new-ticket">ثبت اولین تیکت</a></div>';
} else {
    echo '<div class="card ticket-table"><div class="table-head"><span>عنوان درخواست</span><span>ثبت‌کننده</span><span>اولویت</span><span>وضعیت</span><span>آخرین تغییر</span></div>';
    foreach ($tickets as $ticket) {
        echo '<a class="table-row" href="index.php?page=ticket&id=' . (int) $ticket['id'] . '"><span class="ticket-title"><b>' . e(ticket_number((int) $ticket['id'])) . '</b><strong>' . e($ticket['subject']) . '</strong><small>' . e($ticket['category_name'] ?: 'بدون دسته‌بندی') . '</small></span><span>' . e($isStaff ? $ticket['requester_name'] : ($ticket['assignee_name'] ?: 'در انتظار تخصیص')) . '</span><span><i class="priority-dot ' . e($ticket['priority']) . '"></i>' . e(priority_label($ticket['priority'])) . '</span><span><em class="status ' . e($ticket['status']) . '">' . e(status_label($ticket['status'])) . '</em></span><span class="date-cell">' . persian_date($ticket['updated_at']) . ' ←</span></a>';
    }
    echo '</div>';
}
echo '</section>';
render_footer();
