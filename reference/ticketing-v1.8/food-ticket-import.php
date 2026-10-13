<?php
declare(strict_types=1);

/**
 * ایمپورت لیست کارکنان از فایل Excel (xlsx) یا CSV — نسخهٔ ۱.۳۷.۴
 * ============================================================================
 * ستون‌های پذیرفته‌شده (به همین ترتیب در نمونه فایل):
 *   کد پرسنلی | نام | نام خانوادگی | کد ملی
 *   (معادل لاتین: personnel_code/employee_number | first_name | last_name | national_code)
 *
 * ⚠️ قاعدهٔ سامانه: «کد پرسنلی» همان `employee_number` و همان **L_UID** جدول تردد است.
 *    اگر کد پرسنلی با L_UID یکی نباشد، فیش صادر نمی‌شود.
 *
 * این فایل هیچ وابستگی بیرونی ندارد: xlsx با ZipArchive + SimpleXML خوانده می‌شود و
 * CSV با تشخیص جداکننده/کدگذاری (UTF-8 / Windows-1256 / CP1252).
 */

/** نام‌های معادل هر ستون (پس از نرمال‌سازی حروف و حذف فاصله/نویسه‌های جداکننده). */
function food_ticket_import_aliases(): array
{
    return [
        'pc' => ['کدپرسنلی', 'کدپرسنلى', 'کدکارمندی', 'کدکارمند', 'کدlid', 'پرسنلی', 'کد', 'شمارهپرسنلی',
                 'personnelcode', 'personnelno', 'employeenumber', 'employeecode', 'empcode', 'luid', 'l_uid', 'code', 'id'],
        'first' => ['نام', 'نامکوچک', 'اسم', 'firstname', 'first', 'givenname', 'name'],
        'last' => ['نامخانوادگی', 'فامیلی', 'خانوادگی', 'شهرت', 'lastname', 'last', 'family', 'surname'],
        'nat' => ['کدملی', 'ملی', 'شمارهملی', 'کدملیشهروندی', 'nationalcode', 'nationalid', 'nationalno', 'meli', 'codemeli', 'nat', 'ssn'],
    ];
}

/** نرمال‌سازی متن: ی/ك عربی → فارسی، ارقام فارسی/عربی → لاتین، ZWNJ، فاصله‌های اضافه. */
function food_ticket_import_normalize(string $value): string
{
    $value = str_replace(["\xE2\x80\x8C", "\xE2\x80\x8B"], ' ', $value); // ZWNJ / ZWSP
    $value = str_replace(['ي', 'ك', 'ﻰ', 'ى'], ['ی', 'ک', 'ی', 'ی'], $value);
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $value = str_replace($fa, $en, $value);
    $value = str_replace($ar, $en, $value);
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

/** کلید مقایسه‌ای سرستون: فقط حروف و ارقام. */
function food_ticket_import_key(string $value): string
{
    $value = food_ticket_import_normalize($value);
    $value = str_replace(['‌', '_', '-', '.', '/', '\\', '(', ')', ':', '|'], '', $value);
    return (string) preg_replace('/[^\p{L}\p{N}]/u', '', $value);
}

/** فقط ارقام (برای کد ملی). */
function food_ticket_import_digits(string $value): string
{
    return (string) preg_replace('/\D+/', '', food_ticket_import_normalize($value));
}

/** ستون حرفی اکسل (A، B، AA) → شمارهٔ ستون از صفر. */
function food_ticket_import_column_index(string $ref): int
{
    $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
    if ($letters === '') {
        return 0;
    }
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

/** خواندن رشته‌های مشترک xlsx. */
function food_ticket_import_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') {
        return [];
    }
    $strings = [];
    $doc = @simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }
    foreach ($doc->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text .= (string) $si->t;
        }
        if (isset($si->r)) {
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
        }
        $strings[] = $text;
    }
    return $strings;
}

/**
 * خواندن ردیف‌های اولین شیت xlsx به‌صورت آرایهٔ رشته‌ها.
 * @return array{rows:list<list<string>>,error:string}
 */
function food_ticket_import_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return ['rows' => [], 'error' => 'افزونهٔ zip در PHP فعال نیست؛ فایل xlsx خوانده نمی‌شود. آن را با فرمت CSV ذخیره و بارگذاری کنید.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['rows' => [], 'error' => 'فایل اکسل باز نشد (احتمالاً خراب است یا پسوند اشتباه دارد).'];
    }
    $shared = food_ticket_import_shared_strings($zip);
    $sheetName = '';
    for ($i = 1; $i <= 12; $i++) {
        $candidate = 'xl/worksheets/sheet' . $i . '.xml';
        if ($zip->locateName($candidate) !== false) {
            $sheetName = $candidate;
            break;
        }
    }
    if ($sheetName === '') {
        $zip->close();
        return ['rows' => [], 'error' => 'در فایل اکسل هیچ کاربرگی (Sheet) پیدا نشد.'];
    }
    $xml = (string) $zip->getFromName($sheetName);
    $zip->close();
    $doc = @simplexml_load_string($xml);
    if ($doc === false) {
        return ['rows' => [], 'error' => 'محتوای کاربرگ اکسل خوانده نشد.'];
    }
    $rows = [];
    foreach ($doc->sheetData->row as $row) {
        $cells = [];
        $max = -1;
        foreach ($row->c as $cell) {
            $attributes = $cell->attributes();
            $ref = (string) ($attributes['r'] ?? '');
            $index = food_ticket_import_column_index($ref);
            $type = (string) ($attributes['t'] ?? '');
            $value = '';
            if ($type === 's') {
                $pointer = (int) ((string) $cell->v);
                $value = $shared[$pointer] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } elseif ($type === 'str') {
                $value = (string) $cell->v;
            } else {
                $value = (string) $cell->v;
            }
            $cells[$index] = food_ticket_import_normalize($value);
            $max = max($max, $index);
        }
        if ($max < 0) {
            $rows[] = [];
            continue;
        }
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[$i] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }
    return ['rows' => $rows, 'error' => ''];
}

/** خواندن CSV با تشخیص کدگذاری و جداکننده. */
function food_ticket_import_read_csv(string $path): array
{
    $raw = (string) @file_get_contents($path);
    if ($raw === '') {
        return ['rows' => [], 'error' => 'فایل خالی است.'];
    }
    $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM
    if (!mb_check_encoding($raw, 'UTF-8')) {
        foreach (['Windows-1256', 'CP1256', 'ISO-8859-6', 'Windows-1252'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $raw);
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                $raw = $converted;
                break;
            }
        }
    }
    $sample = substr($raw, 0, 4000);
    $delimiters = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t"), '|' => substr_count($sample, '|')];
    arsort($delimiters);
    $delimiter = (string) array_key_first($delimiters);
    if (($delimiters[$delimiter] ?? 0) === 0) {
        $delimiter = ',';
    }
    $rows = [];
    $handle = fopen('php://memory', 'r+');
    if ($handle === false) {
        return ['rows' => [], 'error' => 'پردازش فایل ممکن نشد.'];
    }
    fwrite($handle, $raw);
    rewind($handle);
    while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $rows[] = array_map(static fn($cell) => food_ticket_import_normalize((string) $cell), $line);
    }
    fclose($handle);
    return ['rows' => $rows, 'error' => ''];
}

/**
 * نگاشت ستون‌ها از ردیف سرستون؛ اگر سرستون شناسایی نشد، ترتیب پیش‌فرض.
 * @return array{map:array<int,string>,headerRow:int,detected:bool}
 */
function food_ticket_import_map_columns(array $rows): array
{
    $aliases = [];
    foreach (food_ticket_import_aliases() as $key => $list) {
        foreach ($list as $alias) {
            $aliases[food_ticket_import_key($alias)] = $key;
        }
    }
    foreach ($rows as $index => $row) {
        $found = 0;
        $map = [];
        foreach ($row as $column => $cell) {
            $key = food_ticket_import_key($cell);
            if ($key === '' || !isset($aliases[$key])) {
                continue;
            }
            $target = $aliases[$key];
            if (in_array($target, $map, true)) {
                continue;
            }
            $map[$column] = $target;
            $found++;
        }
        if ($found >= 2) {
            return ['map' => $map, 'headerRow' => (int) $index, 'detected' => true];
        }
    }
    return ['map' => [0 => 'pc', 1 => 'first', 2 => 'last', 3 => 'nat'], 'headerRow' => -1, 'detected' => false];
}

/**
 * تحلیل فایل: ردیف‌ها + اعتبارسنجی. هیچ تغییری در دیتابیس نمی‌دهد.
 * @return array{ok:bool,error:string,format:string,detected:bool,rows:list<array>,total:int,warnings:int}
 */
function food_ticket_import_parse_file(string $path, string $originalName = ''): array
{
    $extension = strtolower((string) pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
    $format = in_array($extension, ['xlsx', 'xlsm'], true) ? 'xlsx' : 'csv';
    $read = $format === 'xlsx' ? food_ticket_import_read_xlsx($path) : food_ticket_import_read_csv($path);
    if ($read['error'] !== '') {
        return ['ok' => false, 'error' => $read['error'], 'format' => $format, 'detected' => false, 'rows' => [], 'total' => 0, 'warnings' => 0];
    }
    $mapping = food_ticket_import_map_columns($read['rows']);
    $rows = [];
    $warnings = 0;
    foreach ($read['rows'] as $index => $raw) {
        if ($index <= $mapping['headerRow']) {
            continue;
        }
        if (count(array_filter($raw, static fn($cell) => trim((string) $cell) !== '')) === 0) {
            continue;
        }
        $item = ['row' => $index + 1, 'pc' => '', 'first' => '', 'last' => '', 'nat' => '', 'messages' => [], 'status' => 'pending'];
        foreach ($mapping['map'] as $column => $key) {
            $item[$key] = trim((string) ($raw[$column] ?? ''));
        }
        $check = food_ticket_import_validate_row($item);
        $item['messages'] = $check['messages'];
        $item['status'] = $check['ok'] ? 'pending' : 'skipped';
        if ($check['warnings'] > 0) {
            $warnings++;
        }
        $rows[] = $item;
    }
    return [
        'ok' => true,
        'error' => '',
        'format' => $format,
        'detected' => $mapping['detected'],
        'rows' => $rows,
        'total' => count($rows),
        'warnings' => $warnings,
    ];
}

/**
 * اعتبارسنجی یک ردیف.
 * @return array{ok:bool,warnings:int,messages:list<string>}
 */
function food_ticket_import_validate_row(array $row): array
{
    $messages = [];
    $ok = true;
    $warnings = 0;
    if (trim((string) $row['pc']) === '') {
        $messages[] = 'کد پرسنلی خالی است (این ستون اجباری است).';
        $ok = false;
    } else {
        $row['pc'] = food_ticket_import_normalize((string) $row['pc']);
    }
    if (trim((string) $row['first']) === '' || trim((string) $row['last']) === '') {
        $messages[] = 'نام یا نام خانوادگی خالی است (هر دو اجباری‌اند).';
        $ok = false;
    }
    $nat = food_ticket_import_digits((string) $row['nat']);
    if ($nat === '') {
        $messages[] = 'کد ملی خالی است؛ ردیف ثبت می‌شود ولی تطبیق سفارش‌های غذا برای این نفر ممکن است انجام نشود.';
        $warnings++;
    } elseif (strlen($nat) < 10) {
        $messages[] = 'کد ملی کمتر از ۱۰ رقم است (' . $nat . ').';
        $warnings++;
    } elseif (strlen($nat) > 30) {
        $messages[] = 'کد ملی بیش از ۳۰ رقم است.';
        $warnings++;
    }
    return ['ok' => $ok, 'warnings' => $warnings, 'messages' => $messages];
}

/**
 * اعمال ردیف‌ها روی جدول users: کلید یکتایی = employee_number (کد پرسنلی = L_UID).
 * @return array{created:int,updated:int,skipped:int,failed:int,rows:list<array>,duplicates:int}
 */
function food_ticket_import_apply(array $parsed, bool $updateExisting = true): array
{
    $created = 0;
    $updated = 0;
    $failed = 0;
    $duplicates = 0;
    $seen = [];
    $out = [];
    $find = db()->prepare('SELECT id, first_name, last_name, national_code, full_name FROM users WHERE employee_number = ? LIMIT 1');
    $insert = db()->prepare('INSERT INTO users (username, password_hash, full_name, first_name, last_name, employee_number, national_code, role, auth_source, is_active) VALUES (?, NULL, ?, ?, ?, ?, ?, \'user\', \'food\', 1)');
    $update = db()->prepare('UPDATE users SET full_name = ?, first_name = ?, last_name = ?, national_code = ? WHERE id = ?');

    foreach ($parsed['rows'] as $row) {
        $pc = (string) (normalize_employee_number($row['pc']) ?? '');
        if ($row['status'] === 'skipped' || $pc === '') {
            $failed++;
            $out[] = ['row' => $row['row'], 'pc' => $pc, 'name' => trim($row['first'] . ' ' . $row['last']), 'status' => 'skipped', 'messages' => $row['messages']];
            continue;
        }
        if (isset($seen[$pc])) {
            $duplicates++;
            $out[] = ['row' => $row['row'], 'pc' => $pc, 'name' => trim($row['first'] . ' ' . $row['last']), 'status' => 'duplicate', 'messages' => ['این کد پرسنلی در همین فایل تکرار شده است (ردیف ' . $seen[$pc] . ').']];
            continue;
        }
        $seen[$pc] = $row['row'];
        $first = trim((string) $row['first']);
        $last = trim((string) $row['last']);
        $fullName = trim($first . ' ' . $last);
        $national = food_ticket_import_digits((string) $row['nat']);
        try {
            $find->execute([$pc]);
            $existing = $find->fetch() ?: null;
            if ($existing) {
                // کد ملی خالی در فایل، مقدار سالمِ موجود را پاک نمی‌کند.
                $national = $national !== '' ? $national : (string) ($existing['national_code'] ?? '');
                if ($updateExisting) {
                    $update->execute([$fullName, $first, $last, $national, (int) $existing['id']]);
                    $updated++;
                    $out[] = ['row' => $row['row'], 'pc' => $pc, 'name' => $fullName, 'status' => 'updated', 'messages' => $row['messages']];
                } else {
                    $out[] = ['row' => $row['row'], 'pc' => $pc, 'name' => $fullName, 'status' => 'exists', 'messages' => array_merge(['این کد پرسنلی از قبل ثبت شده بود و دست‌نخورده ماند.'], $row['messages'])];
                }
                continue;
            }
            $insert->execute(['food_employee_' . substr(hash('sha256', $pc), 0, 40), $fullName, $first, $last, $pc, $national]);
            $created++;
            $out[] = ['row' => $row['row'], 'pc' => $pc, 'name' => $fullName, 'status' => 'created', 'messages' => $row['messages']];
        } catch (Throwable $exception) {
            $failed++;
            $out[] = ['row' => $row['row'], 'pc' => $pc, 'name' => $fullName, 'status' => 'error', 'messages' => array_merge([$exception->getMessage()], $row['messages'])];
        }
    }
    return ['created' => $created, 'updated' => $updated, 'skipped' => $failed, 'failed' => $failed, 'duplicates' => $duplicates, 'rows' => $out];
}

/** نتیجهٔ آماده برای نمایش در پنل. */
function food_ticket_import_summary(array $parsed, array $applied, string $fileName): array
{
    $messages = [];
    $messages[] = 'فایل ' . $fileName . ' (' . ($parsed['format'] === 'xlsx' ? 'اکسل' : 'CSV') . ') خوانده شد: ' . $parsed['total'] . ' ردیف.';
    $messages[] = $applied['created'] . ' کارمند تازه اضافه شد، ' . $applied['updated'] . ' رکورد به‌روزرسانی شد'
        . ($applied['duplicates'] > 0 ? '، ' . $applied['duplicates'] . ' ردیف تکراری داخل فایل بود' : '')
        . ($applied['failed'] > 0 ? '، ' . $applied['failed'] . ' ردیف رد شد' : '') . '.';
    if (!$parsed['detected']) {
        $messages[] = 'سرستون‌های فایل شناسایی نشد؛ ستون‌ها بر اساس ترتیب پیش‌فرض خوانده شدند (کد پرسنلی، نام، نام خانوادگی، کد ملی).';
    }
    if ($parsed['warnings'] > 0) {
        $messages[] = $parsed['warnings'] . ' ردیف هشدار دارد (کد ملی خالی یا ناقص) — در جدول زیر با رنگ زرد مشخص شده‌اند.';
    }
    return [
        'ok' => true,
        'file' => $fileName,
        'format' => $parsed['format'],
        'total' => $parsed['total'],
        'created' => $applied['created'],
        'updated' => $applied['updated'],
        'skipped' => $applied['skipped'],
        'duplicates' => $applied['duplicates'],
        'rows' => array_slice($applied['rows'], 0, 300),
        'truncated' => count($applied['rows']) > 300,
        'message' => implode(' ', $messages),
    ];
}

/**
 * هندلر مسیر POST /api/employees-import  (بخش «بارگذاری از Excel» در فرم کارکنان)
 */
function food_ticket_employees_import_handle(array $user): never
{
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        food_ticket_api_json(['ok' => false, 'error' => 'فایلی انتخاب نشده است. با دکمهٔ «بارگذاری از Excel» فایل را انتخاب کنید.'], 422);
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => 'حجم فایل از سقف upload_max_filesize سرور بیشتر است.',
            UPLOAD_ERR_FORM_SIZE => 'حجم فایل از سقف مجاز بیشتر است.',
            UPLOAD_ERR_PARTIAL => 'فایل ناقص بارگذاری شد؛ دوباره تلاش کنید.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت بارگذاری روی سرور وجود ندارد.',
            UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک سرور ممکن نشد.',
            UPLOAD_ERR_EXTENSION => 'یکی از افزونه‌های PHP بارگذاری را متوقف کرد.',
        ];
        food_ticket_api_json(['ok' => false, 'error' => $map[(int) $file['error']] ?? 'بارگذاری فایل ناموفق بود.'], 422);
    }
    $name = (string) ($file['name'] ?? 'employees.xlsx');
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xlsm', 'csv', 'txt'], true)) {
        food_ticket_api_json(['ok' => false, 'error' => 'فقط فایل Excel (xlsx) یا CSV پذیرفته می‌شود. پسوند فایل شما: ' . $extension], 422);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size > 5 * 1024 * 1024) {
        food_ticket_api_json(['ok' => false, 'error' => 'حجم فایل بیش از ۵ مگابایت است.'], 422);
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)) {
        food_ticket_api_json(['ok' => false, 'error' => 'فایل موقت بارگذاری پیدا نشد؛ صفحه را تازه کنید و دوباره تلاش کنید.'], 422);
    }
    $parsed = food_ticket_import_parse_file($temporary, $name);
    if (!$parsed['ok']) {
        food_ticket_api_json(['ok' => false, 'error' => $parsed['error'], 'format' => $parsed['format']], 422);
    }
    if ($parsed['total'] === 0) {
        food_ticket_api_json(['ok' => false, 'error' => 'در فایل هیچ ردیف داده‌ای پیدا نشد. نمونهٔ فایل را ببینید: سرستون‌ها «کد پرسنلی، نام، نام خانوادگی، کد ملی».', 'format' => $parsed['format']], 422);
    }
    $updateExisting = !isset($_POST['update']) || (string) $_POST['update'] !== '0';
    $applied = food_ticket_import_apply($parsed, $updateExisting);
    $summary = food_ticket_import_summary($parsed, $applied, $name);
    if (function_exists('system_log')) {
        system_log('info', 'food-employees', 'ایمپورت لیست کارکنان از فایل', [
            'file' => $name,
            'rows' => $parsed['total'],
            'created' => $applied['created'],
            'updated' => $applied['updated'],
            'skipped' => $applied['skipped'],
            'by' => (int) ($user['id'] ?? 0),
        ]);
    }
    if (function_exists('save_audit')) {
        save_audit((int) ($user['id'] ?? 0), 'food_employees_imported', null, ['file' => $name, 'created' => $applied['created'], 'updated' => $applied['updated']]);
    }
    food_ticket_api_json(['ok' => true, 'items' => function_exists('food_ticket_api_employees') ? food_ticket_api_employees() : []] + $summary);
}
