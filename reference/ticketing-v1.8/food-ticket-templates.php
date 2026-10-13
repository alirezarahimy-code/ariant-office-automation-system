<?php
declare(strict_types=1);

/**
 * موتور قالب فیش غذا (Template → Renderer → Windows Queue / Network Printer)
 *
 * معماری:
 *
 *   Designer (food-ticket-web/assets/food-designer.js)
 *        ↓  POST index.php?page=food-ticket&food_api=templates
 *   جدول food_ticket_templates (template_json، paper_width، paper_height، is_active)
 *        ↓  هنگام چاپ هر فیش، قالب فعال از دیتابیس خوانده می‌شود (هیچ کَشی در Worker نیست)
 *   food_ticket_tpl_model($event)      ← «مدل چاپ» مستقل از نوع چاپگر (همهٔ اندازه‌ها میلی‌متر، فونت‌ها pt)
 *        ├─ food_ticket_tpl_print_network()  → رسم GD + ESC/POS raster → TCP/IP (RAW 9100)
 *        └─ food_ticket_tpl_print_windows()  → GDI+ (PowerShell) → PaperSize درایور → صف چاپ ویندوز
 *
 * هر دو مسیر دقیقاً از یک مدل (همان آیتم‌ها، مختصات، فونت، لوگو) استفاده می‌کنند.
 * اگر هیچ قالبی ذخیره نشده باشد، «قالب پیش‌فرض» (معادل ظاهر قدیمی) ساخته و استفاده می‌شود.
 */

if (!defined('FOOD_TICKET_TPL_MAX')) {
    define('FOOD_TICKET_TPL_MAX', 4);
}

// ───────────────────────────── کاتالوگ فیلدها و فونت‌ها ─────────────────────────────

/**
 * فیلدهای قابل چاپ. type: text | image | qr | line | free
 * @return list<array<string,mixed>>
 */
function food_ticket_tpl_catalog(): array
{
    return [
        ['key' => 'logo', 'label' => 'لوگو', 'type' => 'image', 'label_text' => '', 'sample' => ''],
        ['key' => 'system_name', 'label' => 'نام سامانه', 'type' => 'text', 'label_text' => '', 'sample' => 'سامانه چاپ فیش غذا'],
        ['key' => 'title', 'label' => 'عنوان فیش', 'type' => 'free', 'label_text' => '', 'sample' => 'فیش غذای پرسنل'],
        ['key' => 'employee_name', 'label' => 'نام کارمند', 'type' => 'text', 'label_text' => 'نام و نام خانوادگی', 'sample' => 'علی رضایی'],
        ['key' => 'personnel_code', 'label' => 'کد پرسنلی', 'type' => 'text', 'label_text' => 'کد پرسنلی', 'sample' => '۱۲۳۴'],
        ['key' => 'national_code', 'label' => 'کد ملی', 'type' => 'text', 'label_text' => 'کد ملی', 'sample' => '۰۰۱۲۳۴۵۶۷۸'],
        ['key' => 'department', 'label' => 'واحد سازمانی', 'type' => 'text', 'label_text' => 'واحد', 'sample' => 'فناوری اطلاعات'],
        ['key' => 'date', 'label' => 'تاریخ', 'type' => 'text', 'label_text' => 'تاریخ', 'sample' => '۱۴۰۵/۰۷/۱۳'],
        ['key' => 'punch_time', 'label' => 'ساعت تردد', 'type' => 'text', 'label_text' => 'ساعت تردد', 'sample' => '۱۲:۴۱'],
        ['key' => 'print_time', 'label' => 'ساعت چاپ', 'type' => 'text', 'label_text' => 'ساعت چاپ', 'sample' => '۱۲:۴۲'],
        ['key' => 'meal', 'label' => 'وعده غذا', 'type' => 'text', 'label_text' => 'وعده', 'sample' => 'ناهار'],
        ['key' => 'food_name', 'label' => 'نام غذا', 'type' => 'text', 'label_text' => '', 'sample' => 'چلوکباب'],
        ['key' => 'ticket_number', 'label' => 'شماره فیش', 'type' => 'text', 'label_text' => 'شماره فیش', 'sample' => '۱۰۲۴'],
        ['key' => 'qrcode', 'label' => 'QR Code', 'type' => 'qr', 'label_text' => '', 'sample' => ''],
        ['key' => 'free_text', 'label' => 'متن آزاد', 'type' => 'free', 'label_text' => '', 'sample' => 'نوش جان'],
        ['key' => 'line', 'label' => 'خط جداکننده', 'type' => 'line', 'label_text' => '', 'sample' => ''],
    ];
}

/** @return array<string,array{reg:list<string>,bold:list<string>}> نام خانوادهٔ فونت → فایل‌های TTF (برای رسم GD) */
function food_ticket_tpl_font_files(): array
{
    return [
        'Tahoma' => ['reg' => ['tahoma.ttf'], 'bold' => ['tahomabd.ttf', 'tahoma.ttf']],
        'Arial' => ['reg' => ['arial.ttf'], 'bold' => ['arialbd.ttf', 'arial.ttf']],
        'Segoe UI' => ['reg' => ['segoeui.ttf'], 'bold' => ['segoeuib.ttf', 'segoeui.ttf']],
        'B Nazanin' => ['reg' => ['BNazanin.ttf', 'Nazanin.ttf'], 'bold' => ['BNazaninBd.ttf', 'BNazanin-Bold.ttf', 'BNazanin.ttf']],
        'B Titr' => ['reg' => ['BTitrBd.ttf', 'BTitr.ttf'], 'bold' => ['BTitrBd.ttf', 'BTitr.ttf']],
        'B Yekan' => ['reg' => ['BYekan.ttf', 'Yekan.ttf'], 'bold' => ['BYekan.ttf', 'Yekan.ttf']],
        'B Mitra' => ['reg' => ['BMitra.ttf'], 'bold' => ['BMitraBd.ttf', 'BMitra.ttf']],
        'Vazirmatn' => ['reg' => ['Vazirmatn-Regular.ttf', 'Vazirmatn[wght].ttf', 'Vazir.ttf'], 'bold' => ['Vazirmatn-Bold.ttf', 'Vazir-Bold.ttf', 'Vazirmatn-Regular.ttf']],
    ];
}

/**
 * مسیر فایل TTF برای رسم GD. ترتیب جستجو: storage/fonts، assets/fonts، پوشهٔ فونت ویندوز، سپس Tahoma/Arial/DejaVu.
 * @return array{0:string,1:bool,2:string} [مسیر، faux-bold لازم است؟، نام خانوادهٔ واقعاً استفاده‌شده]
 */
function food_ticket_tpl_find_font(string $family, bool $bold): array
{
    static $cache = [];
    $key = $family . '|' . ($bold ? 'b' : 'r');
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
    $win = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\Fonts\\';
    $dirs = [$root . '/storage/fonts/', $root . '/assets/fonts/', $win, '/usr/share/fonts/truetype/dejavu/'];
    $files = food_ticket_tpl_font_files();
    $tryFamilies = [$family, 'Tahoma', 'Arial'];
    foreach ($tryFamilies as $fam) {
        $spec = $files[$fam] ?? null;
        if ($spec === null) {
            continue;
        }
        $list = $bold ? $spec['bold'] : $spec['reg'];
        foreach ($list as $f) {
            foreach ($dirs as $d) {
                if (is_file($d . $f)) {
                    $isBoldFile = $bold && ($list[0] === $f);
                    $faux = $bold && !$isBoldFile;
                    return $cache[$key] = [$d . $f, $faux, $fam];
                }
            }
        }
    }
    $dj = $bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf';
    foreach (['/usr/share/fonts/truetype/dejavu/', '/usr/share/fonts/dejavu/'] as $d) {
        if (is_file($d . $dj)) {
            return $cache[$key] = [$d . $dj, false, 'DejaVu Sans'];
        }
    }
    throw new RuntimeException('فونت فارسی برای چاپ شبکه پیدا نشد (tahoma.ttf). فایل TTF را در storage/fonts کپی کنید.');
}

// ───────────────────────────── دیتابیس ─────────────────────────────

function food_ticket_tpl_ensure(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        try {
            db()->query('SELECT 1 FROM food_ticket_templates LIMIT 1')->fetchAll();
            return $ok = true;
        } catch (Throwable) {
            // جدول وجود ندارد → ساخته می‌شود
        }
        db()->exec('CREATE TABLE IF NOT EXISTS food_ticket_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            paper_width DECIMAL(6,1) NOT NULL DEFAULT 80.0,
            paper_height DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            template_json LONGTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_food_tpl_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        return $ok = true;
    } catch (Throwable $e) {
        error_log('[food-template] table unavailable: ' . $e->getMessage());
        return $ok = false;
    }
}

function food_ticket_tpl_num(mixed $v, float $min, float $max, float $def): float
{
    if (!is_numeric($v)) {
        return $def;
    }
    return round(max($min, min($max, (float) $v)) * 10) / 10;
}

/**
 * ورودی قالب را به ساختار مجاز تبدیل می‌کند (محدودهٔ اعداد، فونت مجاز، حداکثر ۴۰ المان).
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function food_ticket_tpl_normalize(array $in): array
{
    $catalog = [];
    foreach (food_ticket_tpl_catalog() as $c) {
        $catalog[(string) $c['key']] = $c;
    }
    $fonts = array_keys(food_ticket_tpl_font_files());
    $out = [
        'version' => 2,
        'cut_paper' => !array_key_exists('cut_paper', $in) || !empty($in['cut_paper']),
        'border' => !empty($in['border']),
        'border_inset' => food_ticket_tpl_num($in['border_inset'] ?? 4, 0, 20, 4.0),
        'logo_source' => (($in['logo_source'] ?? 'system') === 'ticket') ? 'ticket' : 'system',
        'name_source' => (($in['name_source'] ?? 'system') === 'custom') ? 'custom' : 'system',
        'custom_name' => mb_substr(trim((string) ($in['custom_name'] ?? '')), 0, 120),
        'margin_bottom' => food_ticket_tpl_num($in['margin_bottom'] ?? 3, 0, 30, 3.0),
        // keep_height = قالب برای «برچسب آماده» طراحی شده؛ ارتفاع کاغذ دست‌نخورده بماند
        'keep_height' => !empty($in['keep_height']),
        'elements' => [],
    ];
    $used = [];
    $list = is_array($in['elements'] ?? null) ? array_values($in['elements']) : [];
    foreach (array_slice($list, 0, 40) as $i => $e) {
        if (!is_array($e)) {
            continue;
        }
        $field = (string) ($e['field'] ?? '');
        if (!isset($catalog[$field])) {
            continue;
        }
        $id = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($e['id'] ?? '')) ?? '';
        if ($id === '' || isset($used[$id])) {
            $id = 'e' . ($i + 1) . '_' . substr(bin2hex(random_bytes(2)), 0, 3);
        }
        $used[$id] = true;
        $family = (string) ($e['font_family'] ?? 'Tahoma');
        if (!in_array($family, $fonts, true)) {
            $family = 'Tahoma';
        }
        $align = (string) ($e['align'] ?? 'right');
        if (!in_array($align, ['right', 'center', 'left'], true)) {
            $align = 'right';
        }
        $type = (string) $catalog[$field]['type'];
        $out['elements'][] = [
            'id' => $id,
            'field' => $field,
            'enabled' => !array_key_exists('enabled', $e) || !empty($e['enabled']),
            'x' => food_ticket_tpl_num($e['x'] ?? 0, 0, 200, 0.0),
            'y' => food_ticket_tpl_num($e['y'] ?? 0, 0, 1000, 0.0),
            'width' => food_ticket_tpl_num($e['width'] ?? 30, 1, 200, 30.0),
            'height' => food_ticket_tpl_num($e['height'] ?? 6, 0.5, 300, 6.0),
            'font_family' => $family,
            'font_size' => food_ticket_tpl_num($e['font_size'] ?? 11, 4, 72, 11.0),
            'bold' => !empty($e['bold']),
            'align' => $align,
            'line_height' => food_ticket_tpl_num($e['line_height'] ?? 1.2, 0.8, 3, 1.2),
            'show_label' => !empty($e['show_label']) && $type === 'text',
            'label' => mb_substr(trim((string) ($e['label'] ?? '')), 0, 60),
            'text' => mb_substr(str_replace("\r", '', (string) ($e['text'] ?? '')), 0, 500),
            'border' => !empty($e['border']),
        ];
    }
    return $out;
}

/** قالب پیش‌فرض (معادل ظاهر قدیمی فیش ۸۰ میلی‌متری) — بر پایهٔ تنظیمات قدیمی قالب (شرکت/عنوان/پاورقی). */
function food_ticket_tpl_default_template(): array
{
    $legacy = function_exists('food_ticket_template') ? food_ticket_template() : [];
    $company = trim((string) ($legacy['company'] ?? ''));
    $title = trim((string) ($legacy['title'] ?? ''));
    $footer = trim((string) ($legacy['footer'] ?? ''));
    $showLogo = !empty($legacy['show_logo']);
    $enabled = [];
    foreach ((array) ($legacy['fields'] ?? []) as $f) {
        $enabled[(string) ($f['key'] ?? '')] = !empty($f['enabled']);
    }
    $on = static fn (string $legacyKey, bool $def): bool => array_key_exists($legacyKey, $enabled) ? $enabled[$legacyKey] : $def;
    $dy = $showLogo ? 16.0 : 0.0;
    $mk = static function (string $id, string $field, bool $en, float $x, float $y, float $w, float $h, float $pt, bool $bold, string $align, array $extra = []): array {
        return array_merge([
            'id' => $id, 'field' => $field, 'enabled' => $en, 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
            'font_family' => 'Tahoma', 'font_size' => $pt, 'bold' => $bold, 'align' => $align, 'line_height' => 1.2,
            'show_label' => false, 'label' => '', 'text' => '', 'border' => false,
        ], $extra);
    };
    $els = [
        $mk('logo', 'logo', $showLogo, 28.0, 6.0, 24.0, 14.0, 11, false, 'center'),
        $mk('system_name', 'system_name', true, 6.0, 6.0 + $dy, 68.0, 8.0, 15, true, 'center'),
        $mk('title', 'title', true, 6.0, 14.0 + $dy, 68.0, 6.0, 11, false, 'center', ['text' => $title !== '' ? $title : 'فیش غذای پرسنل']),
        $mk('line1', 'line', true, 6.0, 21.5 + $dy, 68.0, 0.5, 8, false, 'center'),
        $mk('food_name', 'food_name', $on('food_type', true), 6.0, 24.0 + $dy, 68.0, 11.0, 17, true, 'center', ['border' => true]),
        $mk('employee_name', 'employee_name', $on('full_name', true), 6.0, 38.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'نام و نام خانوادگی']),
        $mk('personnel_code', 'personnel_code', $on('personnel_code', false), 6.0, 45.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'کد پرسنلی']),
        $mk('national_code', 'national_code', $on('national_code', false), 6.0, 52.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'کد ملی']),
        $mk('date', 'date', $on('food_date', true), 6.0, 59.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'تاریخ غذا']),
        $mk('punch_time', 'punch_time', $on('attendance_time', true), 6.0, 66.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'ساعت تردد']),
        $mk('department', 'department', false, 6.0, 73.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'واحد']),
        $mk('meal', 'meal', false, 6.0, 80.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'وعده']),
        $mk('ticket_number', 'ticket_number', false, 6.0, 87.0 + $dy, 68.0, 6.0, 11, false, 'right', ['show_label' => true, 'label' => 'شماره فیش']),
        $mk('qrcode', 'qrcode', false, 28.0, 94.0 + $dy, 24.0, 24.0, 11, false, 'center'),
        $mk('footer', 'free_text', $footer !== '', 6.0, 120.0 + $dy, 68.0, 6.0, 10, false, 'center', ['text' => $footer]),
    ];
    return food_ticket_tpl_normalize([
        'cut_paper' => !array_key_exists('cut_paper', $legacy) || !empty($legacy['cut_paper']),
        'border' => true,
        'border_inset' => 4,
        'logo_source' => 'system',
        'name_source' => $company !== '' ? 'custom' : 'system',
        'custom_name' => $company,
        'elements' => $els,
    ]);
}

/** @return array<string,mixed> */
function food_ticket_tpl_default_row(): array
{
    return [
        'id' => 0,
        'name' => 'قالب پیش‌فرض',
        'paper_width' => 80.0,
        'paper_height' => 0.0,
        'is_active' => true,
        'template' => food_ticket_tpl_default_template(),
        'updated_at' => '',
    ];
}

/** @param array<string,mixed> $r @return array<string,mixed> */
function food_ticket_tpl_row_decode(array $r): array
{
    $j = json_decode((string) ($r['template_json'] ?? ''), true);
    return [
        'id' => (int) $r['id'],
        'name' => (string) $r['name'],
        'paper_width' => (float) $r['paper_width'],
        'paper_height' => (float) $r['paper_height'],
        'is_active' => (int) $r['is_active'] === 1,
        'template' => food_ticket_tpl_normalize(is_array($j) ? $j : []),
        'updated_at' => (string) ($r['updated_at'] ?? ''),
    ];
}

/** @return list<array<string,mixed>> */
function food_ticket_tpl_list(): array
{
    if (!food_ticket_tpl_ensure()) {
        return [];
    }
    $rows = db()->query('SELECT * FROM food_ticket_templates ORDER BY id ASC')->fetchAll();
    return array_map('food_ticket_tpl_row_decode', $rows ?: []);
}

/** @return array<string,mixed>|null */
function food_ticket_tpl_get(int $id): ?array
{
    if (!food_ticket_tpl_ensure()) {
        return null;
    }
    $q = db()->prepare('SELECT * FROM food_ticket_templates WHERE id = ? LIMIT 1');
    $q->execute([$id]);
    $r = $q->fetch();
    return $r ? food_ticket_tpl_row_decode($r) : null;
}

/** شناسهٔ قالبی که فقط برای «چاپ آزمایشی» اجبار می‌شود (null = قالب فعال). */
function food_ticket_tpl_force(?int $id = null, bool $set = true): ?int
{
    static $forced = null;
    if ($set && func_num_args() >= 1) {
        $forced = $id;
    }
    return $forced;
}

/**
 * قالب فعال — همیشه از دیتابیس خوانده می‌شود (Worker هیچ نسخهٔ قدیمی را نگه نمی‌دارد).
 * اگر هیچ قالبی وجود نداشته باشد، قالب پیش‌فرض برمی‌گردد.
 * @return array<string,mixed>
 */
function food_ticket_tpl_active(): array
{
    $forced = food_ticket_tpl_force();
    if ($forced !== null && $forced > 0) {
        $row = food_ticket_tpl_get($forced);
        if ($row !== null) {
            return $row;
        }
    }
    try {
        if (food_ticket_tpl_ensure()) {
            $r = db()->query('SELECT * FROM food_ticket_templates WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch();
            if (!$r) {
                $r = db()->query('SELECT * FROM food_ticket_templates ORDER BY id ASC LIMIT 1')->fetch();
            }
            if ($r) {
                return food_ticket_tpl_row_decode($r);
            }
        }
    } catch (Throwable $e) {
        error_log('[food-template] active template read failed: ' . $e->getMessage());
    }
    return food_ticket_tpl_default_row();
}

/** اگر جدول خالی است، قالب پیش‌فرض را به‌عنوان قالب ۱ ذخیره می‌کند (فقط از رابط طراحی صدا زده می‌شود). */
function food_ticket_tpl_seed_if_empty(): void
{
    if (!food_ticket_tpl_ensure()) {
        return;
    }
    $n = (int) db()->query('SELECT COUNT(*) FROM food_ticket_templates')->fetchColumn();
    if ($n === 0) {
        $d = food_ticket_tpl_default_row();
        food_ticket_tpl_save(null, 'قالب ۱ (پیش‌فرض)', (float) $d['paper_width'], (float) $d['paper_height'], $d['template'], true);
    }
}

/**
 * ایجاد/ویرایش قالب. حداکثر FOOD_TICKET_TPL_MAX قالب مجاز است.
 * @param array<string,mixed> $template
 */
function food_ticket_tpl_save(?int $id, string $name, float $paperW, float $paperH, array $template, bool $activate = false): int
{
    if (!food_ticket_tpl_ensure()) {
        throw new RuntimeException('جدول قالب‌های فیش ساخته نشد؛ دسترسی دیتابیس را بررسی کنید.');
    }
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') {
        $name = 'قالب بدون نام';
    }
    $paperW = food_ticket_tpl_num($paperW, 20, 120, 80.0);
    $paperH = $paperH <= 0 ? 0.0 : food_ticket_tpl_num($paperH, 15, 600, 50.0);
    $json = json_encode(food_ticket_tpl_normalize($template), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('ساختار قالب قابل ذخیره نیست.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id !== null && $id > 0) {
            $q = $pdo->prepare('SELECT id FROM food_ticket_templates WHERE id = ? FOR UPDATE');
            $q->execute([$id]);
            if (!$q->fetchColumn()) {
                throw new RuntimeException('قالب موردنظر پیدا نشد.');
            }
            $pdo->prepare('UPDATE food_ticket_templates SET name = ?, paper_width = ?, paper_height = ?, template_json = ? WHERE id = ?')
                ->execute([$name, $paperW, $paperH, $json, $id]);
            $savedId = $id;
        } else {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM food_ticket_templates FOR UPDATE')->fetchColumn();
            if ($count >= FOOD_TICKET_TPL_MAX) {
                throw new RuntimeException('حداکثر ' . FOOD_TICKET_TPL_MAX . ' قالب فیش قابل ذخیره است؛ ابتدا یک قالب را حذف کنید.');
            }
            $pdo->prepare('INSERT INTO food_ticket_templates (name, paper_width, paper_height, template_json, is_active) VALUES (?,?,?,?,?)')
                ->execute([$name, $paperW, $paperH, $json, $count === 0 ? 1 : 0]);
            $savedId = (int) $pdo->lastInsertId();
        }
        if ($activate) {
            $pdo->exec('UPDATE food_ticket_templates SET is_active = 0');
            $pdo->prepare('UPDATE food_ticket_templates SET is_active = 1 WHERE id = ?')->execute([$savedId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $savedId;
}

function food_ticket_tpl_activate(int $id): void
{
    if (!food_ticket_tpl_ensure() || food_ticket_tpl_get($id) === null) {
        throw new RuntimeException('قالب موردنظر پیدا نشد.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE food_ticket_templates SET is_active = 0');
        $pdo->prepare('UPDATE food_ticket_templates SET is_active = 1 WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function food_ticket_tpl_delete(int $id): void
{
    if (!food_ticket_tpl_ensure()) {
        return;
    }
    $row = food_ticket_tpl_get($id);
    if ($row === null) {
        throw new RuntimeException('قالب موردنظر پیدا نشد.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM food_ticket_templates WHERE id = ?')->execute([$id]);
        if ($row['is_active']) {
            $next = $pdo->query('SELECT id FROM food_ticket_templates ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($next) {
                $pdo->prepare('UPDATE food_ticket_templates SET is_active = 1 WHERE id = ?')->execute([(int) $next]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ───────────────────────────── نام و لوگوی سامانه ─────────────────────────────

/** خواندن مستقیم از جدول settings (بدون کش درون‌پردازشی؛ Worker طولانی‌مدت تغییرات را می‌بیند). */
function food_ticket_tpl_setting(string $key, string $default = ''): string
{
    try {
        $q = db()->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $q->execute([$key]);
        $v = $q->fetchColumn();
        return ($v === false || $v === null) ? $default : (string) $v;
    } catch (Throwable) {
        return $default;
    }
}

function food_ticket_tpl_system_name(): string
{
    $n = trim(food_ticket_tpl_setting('app_name'));
    if ($n === '' && function_exists('cfg')) {
        $n = trim((string) cfg('app.name', ''));
    }
    return $n !== '' ? $n : 'سامانه چاپ فیش غذا';
}

/** مسیر ذخیره‌شدهٔ لوگو در تنظیمات؛ $source = system | ticket */
function food_ticket_tpl_logo_setting(string $source): string
{
    if ($source === 'ticket') {
        return trim(food_ticket_tpl_setting('food_ticket_logo'));
    }
    $logo = trim(food_ticket_tpl_setting('app_logo'));
    if ($logo === '') {
        $logo = trim(food_ticket_tpl_setting('food_ticket_brand_logo'));
    }
    if ($logo === '' && function_exists('cfg')) {
        $logo = trim((string) cfg('app.logo', ''));
    }
    return $logo;
}

/** فایل لوگو را به‌صورت امن (فقط داخل APP_ROOT، PNG/JPG/GIF/BMP، حداکثر ۲MB) برمی‌گرداند. */
function food_ticket_tpl_logo_bytes(string $source): string
{
    $logo = food_ticket_tpl_logo_setting($source);
    if ($logo === '') {
        return '';
    }
    $path = $logo;
    if (preg_match('#^https?://#i', $logo)) {
        $urlPath = (string) (parse_url($logo, PHP_URL_PATH) ?? '');
        if (function_exists('food_ticket_main_url')) {
            $mainPath = (string) (parse_url(food_ticket_main_url(), PHP_URL_PATH) ?? '');
            $baseDir = rtrim(str_replace('\\', '/', dirname($mainPath)), '/.');
            if ($baseDir !== '' && str_starts_with($urlPath, $baseDir . '/')) {
                $urlPath = substr($urlPath, strlen($baseDir) + 1);
            }
        }
        $path = ltrim($urlPath, '/');
    }
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) !== 1) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    }
    $rootReal = realpath($root);
    $file = realpath($path);
    if (!$rootReal || !$file || !str_starts_with($file, $rootReal . DIRECTORY_SEPARATOR) || !is_file($file) || filesize($file) > 2097152) {
        return '';
    }
    $info = @getimagesize($file);
    if (!is_array($info) || !in_array((string) ($info['mime'] ?? ''), ['image/png', 'image/jpeg', 'image/bmp', 'image/gif'], true)) {
        return '';
    }
    $bytes = @file_get_contents($file);
    return is_string($bytes) ? $bytes : '';
}

// ───────────────────────────── مقدار فیلدها ─────────────────────────────

function food_ticket_tpl_fa(string $s): string
{
    return function_exists('food_ticket_fa_digits') ? food_ticket_fa_digits($s) : $s;
}

function food_ticket_tpl_department(array $event): string
{
    if (!empty($event['department'])) {
        return trim((string) $event['department']);
    }
    $uid = (int) ($event['user_id'] ?? 0);
    if ($uid <= 0) {
        return '';
    }
    $queries = [
        'SELECT ou.name FROM users u JOIN org_units ou ON ou.id = u.org_unit_id WHERE u.id = ? LIMIT 1',
        'SELECT d.name FROM users u JOIN departments d ON d.id = u.department_id WHERE u.id = ? LIMIT 1',
        'SELECT u.department FROM users u WHERE u.id = ? LIMIT 1',
    ];
    foreach ($queries as $sql) {
        try {
            $q = db()->prepare($sql);
            $q->execute([$uid]);
            $v = trim((string) $q->fetchColumn());
            if ($v !== '') {
                return $v;
            }
        } catch (Throwable) {
            // ستون/جدول در این نصب وجود ندارد
        }
    }
    return '';
}

/**
 * مقدار همهٔ فیلدهای متنی برای یک رویداد.
 * @param array<string,mixed> $event
 * @param array<string,mixed> $tpl
 * @return array<string,string>
 */
function food_ticket_tpl_values(array $event, array $tpl): array
{
    $dateRaw = trim((string) ($event['food_date'] ?? $event['punch_date'] ?? ''));
    $date = $dateRaw;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateRaw, $m) === 1 && (int) $m[1] >= 1700 && function_exists('gregorian_to_jalali')) {
        [$jy, $jm, $jd] = gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
        $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
    $timeRaw = trim((string) ($event['attendance_time'] ?? $event['punch_time'] ?? ''));
    $punch = preg_match('/^(\d{1,2}):(\d{2})/', $timeRaw, $m2) === 1 ? sprintf('%02d:%02d', (int) $m2[1], (int) $m2[2]) : $timeRaw;
    $meal = '';
    if (function_exists('food_ticket_active_meal')) {
        try {
            $mealInfo = food_ticket_active_meal(($event['punch_time'] ?? '') !== '' ? (string) $event['punch_time'] : null);
            $meal = (string) ($mealInfo['label'] ?? '');
        } catch (Throwable) {
            $meal = '';
        }
    }
    $id = (int) ($event['id'] ?? 0);
    $system = (($tpl['name_source'] ?? 'system') === 'custom' && trim((string) ($tpl['custom_name'] ?? '')) !== '')
        ? (string) $tpl['custom_name']
        : food_ticket_tpl_system_name();
    $v = [
        'system_name' => $system,
        'employee_name' => trim((string) ($event['full_name'] ?? '')),
        'personnel_code' => trim((string) ($event['personnel_code'] ?? '')),
        'national_code' => trim((string) ($event['national_code'] ?? '')),
        'department' => food_ticket_tpl_department($event),
        'date' => $date,
        'punch_time' => $punch,
        'print_time' => date('H:i'),
        'meal' => $meal,
        'food_name' => trim((string) ($event['food_type'] ?? '')),
        'ticket_number' => $id > 0 ? (string) $id : '',
    ];
    foreach ($v as $k => $val) {
        $v[$k] = food_ticket_tpl_fa($val);
    }
    return $v;
}

function food_ticket_tpl_qr_payload(array $event): string
{
    $key = trim((string) ($event['ticket_key'] ?? ''));
    if ($key === '') {
        $key = 'FT-' . (int) ($event['id'] ?? 0);
    }
    return mb_strcut($key, 0, 120, 'UTF-8');
}

// ───────────────────────────── QR Code (بایت‌مد، سطح M، نسخهٔ ۱ تا ۱۰) ─────────────────────────────

/**
 * @return list<string> ماتریس QR؛ هر ردیف رشته‌ای از '0' و '1'
 */
function food_ticket_tpl_qr_matrix(string $text): array
{
    $ecTable = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
    $alignPos = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];
    // جدول‌های میدان گالوا GF(256)
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if (($x & 0x100) !== 0) {
            $x ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    $gmul = static fn (int $a, int $b): int => ($a === 0 || $b === 0) ? 0 : $exp[$log[$a] + $log[$b]];
    $rsEc = static function (array $data, int $n) use ($exp, $gmul): array {
        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $ng = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $c) {
                $ng[$j] ^= $c;
                $ng[$j + 1] ^= $gmul($c, $exp[$i]);
            }
            $g = $ng;
        }
        $res = array_fill(0, $n, 0);
        foreach ($data as $b) {
            $f = $b ^ $res[0];
            array_shift($res);
            $res[] = 0;
            if ($f !== 0) {
                for ($i = 0; $i < $n; $i++) {
                    $res[$i] ^= $gmul($g[$i + 1], $f);
                }
            }
        }
        return $res;
    };
    $bch = static function (int $v, int $gen, int $dataBits, int $total): int {
        $r = $v << ($total - $dataBits);
        $glen = strlen(decbin($gen));
        while (strlen(decbin($r)) >= $glen && $r > 0) {
            $r ^= $gen << (strlen(decbin($r)) - $glen);
        }
        return ($v << ($total - $dataBits)) | $r;
    };

    $data = array_values(unpack('C*', $text) ?: []);
    $ver = 0;
    foreach ($ecTable as $v => [$ecPer, $blocks]) {
        $cap = 0;
        foreach ($blocks as [$cnt, $dpb]) {
            $cap += $cnt * $dpb;
        }
        $cci = $v < 10 ? 8 : 16;
        if (4 + $cci + 8 * count($data) <= $cap * 8) {
            $ver = $v;
            break;
        }
    }
    if ($ver === 0 || count($data) === 0) {
        throw new RuntimeException('متن QR خالی یا بیش از حد طولانی است.');
    }
    [$ec, $blocks] = $ecTable[$ver];
    $cap = 0;
    foreach ($blocks as [$cnt, $dpb]) {
        $cap += $cnt * $dpb;
    }
    $cci = $ver < 10 ? 8 : 16;

    $bits = [];
    $put = static function (int $val, int $n) use (&$bits): void {
        for ($i = $n - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    };
    $put(4, 4);
    $put(count($data), $cci);
    foreach ($data as $b) {
        $put((int) $b, 8);
    }
    $put(0, min(4, $cap * 8 - count($bits)));
    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }
    $cw = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($k = 0; $k < 8; $k++) {
            $byte = ($byte << 1) | $bits[$i + $k];
        }
        $cw[] = $byte;
    }
    $pads = [0xEC, 0x11];
    $pi = 0;
    while (count($cw) < $cap) {
        $cw[] = $pads[$pi % 2];
        $pi++;
    }
    $dblocks = [];
    $pos = 0;
    foreach ($blocks as [$cnt, $dpb]) {
        for ($i = 0; $i < $cnt; $i++) {
            $dblocks[] = array_slice($cw, $pos, $dpb);
            $pos += $dpb;
        }
    }
    $eblocks = [];
    foreach ($dblocks as $b) {
        $eblocks[] = $rsEc($b, $ec);
    }
    $final = [];
    $maxD = 0;
    foreach ($dblocks as $b) {
        $maxD = max($maxD, count($b));
    }
    for ($i = 0; $i < $maxD; $i++) {
        foreach ($dblocks as $b) {
            if ($i < count($b)) {
                $final[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $ec; $i++) {
        foreach ($eblocks as $b) {
            $final[] = $b[$i];
        }
    }

    $n = 17 + 4 * $ver;
    $M = array_fill(0, $n, array_fill(0, $n, 0));
    $fn = array_fill(0, $n, array_fill(0, $n, false));
    $setf = static function (int $r, int $c, int $v) use (&$M, &$fn): void {
        $M[$r][$c] = $v;
        $fn[$r][$c] = true;
    };
    $finder = static function (int $r0, int $c0) use ($n, $setf): void {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $r = $r0 + $dr;
                $c = $c0 + $dc;
                if ($r < 0 || $r >= $n || $c < 0 || $c >= $n) {
                    continue;
                }
                $dark = $dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6
                    && ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6 || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4));
                $setf($r, $c, $dark ? 1 : 0);
            }
        }
    };
    $finder(0, 0);
    $finder(0, $n - 7);
    $finder($n - 7, 0);
    for ($i = 8; $i < $n - 8; $i++) {
        $setf(6, $i, $i % 2 === 0 ? 1 : 0);
        $setf($i, 6, $i % 2 === 0 ? 1 : 0);
    }
    $ap = $alignPos[$ver];
    $apLast = count($ap) > 0 ? $ap[count($ap) - 1] : -1;
    foreach ($ap as $r) {
        foreach ($ap as $c) {
            if (($r === 6 && $c === 6) || ($r === 6 && $c === $apLast) || ($r === $apLast && $c === 6)) {
                continue;
            }
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $setf($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1 ? 1 : 0);
                }
            }
        }
    }
    for ($i = 0; $i < 9; $i++) {
        if (!$fn[8][$i]) {
            $setf(8, $i, 0);
        }
        if (!$fn[$i][8]) {
            $setf($i, 8, 0);
        }
    }
    for ($i = 0; $i < 8; $i++) {
        if (!$fn[8][$n - 1 - $i]) {
            $setf(8, $n - 1 - $i, 0);
        }
        if (!$fn[$n - 1 - $i][8]) {
            $setf($n - 1 - $i, 8, 0);
        }
    }
    $setf($n - 8, 8, 1);
    if ($ver >= 7) {
        $vi = $bch($ver, 0x1F25, 6, 18);
        for ($i = 0; $i < 18; $i++) {
            $b = ($vi >> $i) & 1;
            $setf(intdiv($i, 3), $n - 11 + $i % 3, $b);
            $setf($n - 11 + $i % 3, intdiv($i, 3), $b);
        }
    }
    $bitsf = [];
    foreach ($final as $b) {
        for ($i = 7; $i >= 0; $i--) {
            $bitsf[] = ($b >> $i) & 1;
        }
    }
    if ($ver >= 2 && $ver <= 6) {
        for ($i = 0; $i < 7; $i++) {
            $bitsf[] = 0;
        }
    }
    $coords = [];
    $up = true;
    $c = $n - 1;
    while ($c > 0) {
        if ($c === 6) {
            $c--;
        }
        for ($k = 0; $k < $n; $k++) {
            $r = $up ? $n - 1 - $k : $k;
            foreach ([$c, $c - 1] as $cc) {
                if (!$fn[$r][$cc]) {
                    $coords[] = [$r, $cc];
                }
            }
        }
        $up = !$up;
        $c -= 2;
    }
    $maskFn = static function (int $m, int $r, int $c): bool {
        return match ($m) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
            6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
            default => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
        };
    };
    $build = static function (int $mask) use ($M, $coords, $bitsf, $maskFn, $n, $bch): array {
        $B = $M;
        foreach ($coords as $i => [$r, $cc]) {
            $bit = $bitsf[$i] ?? 0;
            if ($maskFn($mask, $r, $cc)) {
                $bit ^= 1;
            }
            $B[$r][$cc] = $bit;
        }
        $fmt = $bch($mask, 0x537, 5, 15) ^ 0x5412; // سطح تصحیح M = 00
        for ($i = 0; $i < 15; $i++) {
            $b = ($fmt >> $i) & 1;
            if ($i < 6) {
                $B[$i][8] = $b;
            } elseif ($i < 8) {
                $B[$i + 1][8] = $b;
            } else {
                $B[$n - 15 + $i][8] = $b;
            }
            if ($i < 8) {
                $B[8][$n - 1 - $i] = $b;
            } elseif ($i === 8) {
                $B[8][7] = $b;
            } else {
                $B[8][14 - $i] = $b;
            }
        }
        $B[$n - 8][8] = 1;
        return $B;
    };
    $penalty = static function (array $B) use ($n): int {
        $p = 0;
        $cols = [];
        for ($c = 0; $c < $n; $c++) {
            $col = [];
            for ($r = 0; $r < $n; $r++) {
                $col[] = $B[$r][$c];
            }
            $cols[] = $col;
        }
        $pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pat2 = array_reverse($pat1);
        foreach ([$B, $cols] as $lines) {
            foreach ($lines as $row) {
                $run = 1;
                for ($i = 1; $i < $n; $i++) {
                    if ($row[$i] === $row[$i - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $p += 3 + $run - 5;
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $p += 3 + $run - 5;
                }
                for ($i = 0; $i <= $n - 11; $i++) {
                    $seg = array_slice($row, $i, 11);
                    if ($seg === $pat1 || $seg === $pat2) {
                        $p += 40;
                    }
                }
            }
        }
        for ($r = 0; $r < $n - 1; $r++) {
            for ($c = 0; $c < $n - 1; $c++) {
                if ($B[$r][$c] === $B[$r][$c + 1] && $B[$r][$c] === $B[$r + 1][$c] && $B[$r][$c] === $B[$r + 1][$c + 1]) {
                    $p += 3;
                }
            }
        }
        $dark = 0;
        foreach ($B as $row) {
            $dark += array_sum($row);
        }
        $p += 10 * intdiv(abs(intdiv($dark * 100, $n * $n) - 50), 5);
        return $p;
    };
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($m = 0; $m < 8; $m++) {
        $B = $build($m);
        $s = $penalty($B);
        if ($s < $bestScore) {
            $bestScore = $s;
            $best = $B;
        }
    }
    $rows = [];
    foreach ((array) $best as $row) {
        $rows[] = implode('', $row);
    }
    return $rows;
}

// ───────────────────────────── مدل چاپ (مستقل از نوع چاپگر) ─────────────────────────────

/**
 * تخمین ارتفاع (میلی‌متر) یک متن چندخطی؛ فقط برای محاسبهٔ طول فیش در حالت «طول متغیر».
 */
function food_ticket_tpl_text_height_mm(array $it): float
{
    $lineMm = (float) $it['pt'] * 25.4 / 72.0 * (float) $it['lh'];
    $lines = 0;
    $maxW = max(1.0, (float) $it['w']);
    $emMm = (float) $it['pt'] * 25.4 / 72.0;
    foreach (explode("\n", (string) $it['text']) as $para) {
        $len = mb_strlen($para);
        $perLine = max(1, (int) floor($maxW / max(0.5, $emMm * 0.52)));
        $lines += max(1, (int) ceil($len / $perLine));
    }
    return max((float) $it['h'], $lines * $lineMm);
}

/**
 * مدل چاپ یک فیش: همهٔ آیتم‌ها با مختصات میلی‌متر. هر دو رندرر (ویندوز/شبکه) فقط همین مدل را رسم می‌کنند.
 * @param array<string,mixed> $event
 * @param array<string,mixed>|null $row قالب صریح (برای پیش‌نمایش قالب ذخیره‌نشده)؛ null = قالب فعال از دیتابیس
 * @return array<string,mixed>
 */
function food_ticket_tpl_model(array $event, ?array $row = null): array
{
    $row = $row ?? food_ticket_tpl_active();
    $tpl = $row['template'];
    $values = food_ticket_tpl_values($event, $tpl);
    $items = [];
    foreach ((array) $tpl['elements'] as $el) {
        if (empty($el['enabled'])) {
            continue;
        }
        $field = (string) $el['field'];
        $x = (float) $el['x'];
        $y = (float) $el['y'];
        $w = (float) $el['width'];
        $h = (float) $el['height'];
        $border = !empty($el['border']);
        if ($field === 'line') {
            $items[] = ['type' => 'line', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => 0.0];
            continue;
        }
        if ($field === 'logo') {
            $bytes = food_ticket_tpl_logo_bytes((string) $tpl['logo_source']);
            $dim = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
            if (!is_array($dim) || (int) $dim[0] <= 0 || (int) $dim[1] <= 0) {
                continue;
            }
            $ar = (float) $dim[0] / (float) $dim[1];
            if ($h <= 0 || $w / $h > $ar) {
                $dh = $h;
                $dw = $h * $ar;
            } else {
                $dw = $w;
                $dh = $w / $ar;
            }
            $dx = $el['align'] === 'right' ? $x + $w - $dw : ($el['align'] === 'left' ? $x : $x + ($w - $dw) / 2);
            $items[] = ['type' => 'image', 'x' => $dx, 'y' => $y + ($h - $dh) / 2, 'w' => $dw, 'h' => $dh, 'data' => base64_encode($bytes)];
            continue;
        }
        if ($field === 'qrcode') {
            try {
                $matrix = food_ticket_tpl_qr_matrix(food_ticket_tpl_qr_payload($event));
            } catch (Throwable $e) {
                error_log('[food-template] QR skipped: ' . $e->getMessage());
                continue;
            }
            $side = min($w, $h);
            $dx = $el['align'] === 'right' ? $x + $w - $side : ($el['align'] === 'left' ? $x : $x + ($w - $side) / 2);
            $items[] = ['type' => 'qr', 'x' => $dx, 'y' => $y, 'w' => $side, 'h' => $side, 'matrix' => $matrix];
            continue;
        }
        if ($field === 'title' || $field === 'free_text') {
            $text = food_ticket_tpl_fa((string) $el['text']);
        } else {
            $value = (string) ($values[$field] ?? '');
            if ($value === '') {
                continue; // مقدار ندارد (مثلاً وعده خارج از بازه) → چیزی چاپ نمی‌شود
            }
            $text = (!empty($el['show_label']) && (string) $el['label'] !== '') ? ((string) $el['label'] . ': ' . $value) : $value;
        }
        if (trim($text) === '') {
            continue;
        }
        $items[] = [
            'type' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'text' => $text,
            'font' => (string) $el['font_family'], 'pt' => (float) $el['font_size'], 'bold' => !empty($el['bold']),
            'align' => (string) $el['align'], 'lh' => (float) $el['line_height'], 'border' => $border,
        ];
    }
    $paperW = (float) $row['paper_width'];
    $paperH = (float) $row['paper_height'];
    $variable = $paperH <= 0;
    if ($variable) {
        $bottom = 0.0;
        foreach ($items as $it) {
            $b = (float) $it['y'] + ($it['type'] === 'text' ? food_ticket_tpl_text_height_mm($it) : (float) $it['h']);
            $bottom = max($bottom, $b);
        }
        $paperH = max(10.0, $bottom + (float) $tpl['margin_bottom']);
        $paperH = ceil($paperH * 10) / 10;
    }
    $model = [
        'template_id' => (int) $row['id'],
        'template_name' => (string) $row['name'],
        'paper_w' => $paperW,
        'paper_h' => $paperH,
        'variable' => $variable,
        'cut' => !empty($tpl['cut_paper']),
        'border' => !empty($tpl['border']),
        'border_inset' => (float) $tpl['border_inset'],
        'items' => $items,
    ];
    // فاصلهٔ بالای فیش هرگز بیش از حد مجاز نمی‌ماند (لبِ کاغذ هدر نمی‌رود)
    $gap = max(0.0, min(20.0, (float) ($tpl['top_gap_mm'] ?? 4.0)));
    $winShift = 0.0;
    if (function_exists('food_ticket_tpl_net_cfg')) {
        $winCfg = food_ticket_tpl_net_cfg();
        $winShift = isset($winCfg['win_top_shift_mm']) ? max(-30.0, min(30.0, (float) $winCfg['win_top_shift_mm'])) : 0.0;
    } else {
        $winShift = 0.0;
    }
    $model['win_top_shift_mm'] = $winShift;
    [$model, $shift] = food_ticket_tpl_trim_top($model, $gap);
    $model['top_gap_mm'] = $gap;
    if ($shift > 0) {
        error_log(sprintf('[food-template] top gap trimmed by %.1fmm (template #%d)', $shift, (int) $row['id']));
    }

    // ── پایین فیش: ارتفاع صفحه = آخرین محتوا + حاشیهٔ پایین (هیچ کاغذی هدر نمی‌رود) ──
    $keepHeight = !empty($tpl['keep_height']);
    $model['keep_height'] = $keepHeight;
    $fitBottom = !$keepHeight;
    $tailMm = (float) ($tpl['margin_bottom'] ?? 3.0);
    if (function_exists('food_ticket_tpl_net_cfg')) {
        $netCfg = food_ticket_tpl_net_cfg();
        if (array_key_exists('fit_bottom', $netCfg)) {
            $fitBottom = !empty($netCfg['fit_bottom']) && !$keepHeight;
        }
        // ترتیب اولویت: مقدار صریح در فایل تنظیمات چاپ > حاشیهٔ پایینِ خودِ طرح (margin_bottom)
        // تا «تنظیم طراح فیش» بی‌دلیل بازنویسی نشود.
        $tailExplicit = false;
        try {
            $jsonFile = (defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__)) . '/storage/food_ticket_netprint.json';
            if (is_file($jsonFile)) {
                $jsonData = json_decode((string) @file_get_contents($jsonFile), true);
                $tailExplicit = is_array($jsonData) && array_key_exists('tail_mm', $jsonData) && is_numeric($jsonData['tail_mm']);
            }
        } catch (Throwable) {
        }
        if ($tailExplicit && is_numeric($netCfg['tail_mm'] ?? null)) {
            $tailMm = (float) $netCfg['tail_mm'];
        }
    }
    if ($fitBottom) {
        [$model, $tailTrim] = food_ticket_tpl_fit_bottom($model, $tailMm);
        if (abs($tailTrim) > 0.5) {
            error_log(sprintf('[food-template] bottom fit %.1fmm (template #%d) → paper %.1fmm', $tailTrim, (int) $row['id'], (float) $model['paper_h']));
        }
    }
    return $model;
}

/**
 * اولین خطِ محتوای واقعی (میلی‌متر) در فهرست عناصر قالب.
 * عنصری که هیچ چیزی چاپ نمی‌کند (متن خالی، QR بدون ماتریس) نادیده گرفته می‌شود.
 */
function food_ticket_tpl_first_ink_mm(array $items): float
{
    $first = null;
    foreach ($items as $it) {
        $type = (string) ($it['type'] ?? '');
        if ($type === 'text' && trim((string) ($it['text'] ?? '')) === '') {
            continue;
        }
        if ($type === 'qr' && count((array) ($it['matrix'] ?? [])) === 0) {
            continue;
        }
        $y = (float) ($it['y'] ?? 0);
        $first = $first === null ? $y : min($first, $y);
    }
    return $first ?? 0.0;
}

/**
 * آخرین خطِ محتوای واقعی (میلی‌متر) در فهرست عناصر قالب.
 * متن‌ها با ارتفاع واقعی چندخطی حساب می‌شوند تا فیش کوتاه‌تر از محتوا نشود.
 */
function food_ticket_tpl_last_ink_mm(array $items): float
{
    $last = 0.0;
    foreach ($items as $it) {
        $type = (string) ($it['type'] ?? '');
        if ($type === 'text' && trim((string) ($it['text'] ?? '')) === '') {
            continue;
        }
        if ($type === 'qr' && count((array) ($it['matrix'] ?? [])) === 0) {
            continue;
        }
        $y = (float) ($it['y'] ?? 0);
        $h = $type === 'text' ? food_ticket_tpl_text_height_mm($it) : (float) ($it['h'] ?? 0);
        $last = max($last, $y + $h);
    }
    return $last;
}

/**
 * کوتاه‌کردن فیش تا آخرین نقطهٔ چاپ‌شده (پایین فیش هم پرتِ کاغذ نشود).
 *
 * ⚠️ چرا لازم بود؟ تا پیش از این، ارتفاع کاغذ یا ثابت بود یا با margin_bottom حساب
 * می‌شد؛ اما اگر طرح جای خالیِ انتهایی داشت یا بالا تراز می‌شد، همان سفیدی به پایین
 * فیش منتقل می‌شد و چاپگر به‌اندازهٔ آن کاغذ تغذیه می‌کرد (شکایت «۲۰ میلی‌متر پایین
 * فیش هم خالی می‌افتد»). حالا ارتفاع صفحه = آخرین جوهر + tail_mm.
 *
 * @param array<string,mixed> $model
 * @param float $tailMm حاشیهٔ پایین (۰ = دقیقاً تا آخرین نقطه)
 * @return array{0:array<string,mixed>,1:float} [مدل اصلاح‌شده, میزان کوتاه‌سازی mm (منفی = بلندتر شد)]
 */
function food_ticket_tpl_fit_bottom(array $model, float $tailMm = 3.0): array
{
    $items = (array) ($model['items'] ?? []);
    if ($items === []) {
        return [$model, 0.0];
    }
    if (!empty($model['keep_height'])) {
        return [$model, 0.0]; // برچسب آماده: ارتفاع دست‌نخورده
    }
    $last = food_ticket_tpl_last_ink_mm($items);
    if ($last <= 0.0) {
        return [$model, 0.0];
    }
    $tail = max(0.0, min(20.0, $tailMm));
    $want = max(10.0, $last + $tail);
    $want = ceil($want * 10) / 10;
    $current = (float) ($model['paper_h'] ?? 0);
    if (abs($current - $want) < 0.15) {
        return [$model, 0.0];
    }
    $model['paper_h'] = $want;
    $model['paper_h_before_fit'] = $current;
    $model['tail_trim_mm'] = $current - $want; // مثبت = کوتاه شد
    $model['tail_mm'] = $tail;
    return [$model, $current - $want];
}

/**
 * جابه‌جایی عمودی عناصر قالب تا فاصلهٔ بالای فیش از «حد مجاز» بیشتر نشود.
 *
 * ⚠️ چرا لازم بود؟ در قالب پیش‌فرض، نوار لوگو ۱۶ میلی‌متر رزرو می‌شود و اولین متن در
 * y=22mm قرار می‌گیرد؛ اگر لوگویی برای چاپ موجود نباشد (عنصر تصویر حذف می‌شود) آن ۲۲
 * میلی‌متر کاملاً سفید چاپ می‌شد — همان شکایت «۲۲ میلی‌متر خالی بالای فیش».
 * همهٔ عناصر به یک اندازه بالا می‌آیند، پس چیدمان نسبی طرح دست‌نخورده می‌ماند و
 * کاغذ کمتری هدر می‌رود. با top_gap_mm (پیش‌فرض ۴) کنترل می‌شود.
 *
 * @param array<string,mixed> $model مدل قالب (items و paper_h)
 * @return array{0:array<string,mixed>,1:float} [مدل اصلاح‌شده, میزان جابه‌جایی mm]
 */
function food_ticket_tpl_trim_top(array $model, float $maxBlankMm = 4.0): array
{
    $items = (array) ($model['items'] ?? []);
    if ($items === []) {
        return [$model, 0.0];
    }
    $first = food_ticket_tpl_first_ink_mm($items);
    // کمتر از ~۵ میلی‌متر دست نمی‌زنیم (جابه‌جایی‌های خیلی کوچک بی‌دلیل نیست)
    if ($first <= $maxBlankMm + 1.0) {
        return [$model, 0.0];
    }
    $shift = $first - $maxBlankMm;
    foreach ($items as $i => $it) {
        $items[$i]['y'] = (float) ($it['y'] ?? 0) - $shift;
    }
    $model['items'] = $items;
    if (!empty($model['variable']) && isset($model['paper_h'])) {
        $model['paper_h'] = max(10.0, (float) $model['paper_h'] - $shift);
    }
    $model['top_trim_mm'] = $shift;
    $model['top_gap_mm'] = $maxBlankMm;
    return [$model, $shift];
}

/** توضیح کوتاه قالب استفاده‌شده در آخرین چاپ (برای لاگ چاپ). */
function food_ticket_tpl_last_info(?string $set = null): string
{
    static $info = '';
    if ($set !== null) {
        $info = $set;
    }
    return $info;
}

function food_ticket_tpl_log_suffix(): string
{
    $i = food_ticket_tpl_last_info();
    return $i !== '' ? ' | ' . $i : '';
}

// ───────────────────────────── مسیر ۲: چاپ مستقیم شبکه (GD + ESC/POS) ─────────────────────────────

/** @return array<string,mixed> */
function food_ticket_tpl_net_cfg(): array
{
    $cfg = function_exists('food_ticket_np_settings') ? food_ticket_np_settings() : ['threshold' => 170, 'feed_lines' => 4, 'chunk_rows' => 128, 'supersample' => 2, 'io_timeout' => 10, 'gd_native_bidi' => false, 'save_preview' => false, 'font_file' => '', 'cut' => true];
    // top_gap_mm = فاصلهٔ مجازِ بالای فیش (پیش‌فرض ۴ میلی‌متر — دستور کاربر: کاغذ هدر نمی‌رود)
    $extra = ['dots_per_mm' => 8.0, 'head_dots' => 0, 'top_gap_mm' => 4.0];
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
    $file = $root . '/storage/food_ticket_netprint.json';
    if (is_file($file)) {
        $j = json_decode((string) @file_get_contents($file), true);
        if (is_array($j)) {
            if (isset($j['dots_per_mm']) && is_numeric($j['dots_per_mm'])) {
                $extra['dots_per_mm'] = max(4.0, min(16.0, (float) $j['dots_per_mm']));
            }
            if (isset($j['head_dots']) && is_numeric($j['head_dots'])) {
                $extra['head_dots'] = (int) $j['head_dots'];
            }
            if (isset($j['head_mm']) && is_numeric($j['head_mm']) && (float) $j['head_mm'] > 0) {
                $extra['head_dots'] = (int) round((float) $j['head_mm'] * $extra['dots_per_mm']);
            }
            if (isset($j['top_gap_mm']) && is_numeric($j['top_gap_mm'])) {
                $extra['top_gap_mm'] = max(0.0, min(20.0, (float) $j['top_gap_mm']));
            }
            if (isset($j['win_top_shift_mm']) && is_numeric($j['win_top_shift_mm'])) {
                $extra['win_top_shift_mm'] = max(-30.0, min(30.0, (float) $j['win_top_shift_mm']));
            }
        }
    }
    return $cfg + $extra;
}

/** عرض سرِ چاپ (نقطه) برای کاغذی با عرض مشخص؛ مضرب ۸. */
function food_ticket_tpl_head_dots(float $paperW, array $cfg): int
{
    $h = (int) ($cfg['head_dots'] ?? 0);
    if ($h <= 0) {
        $h = $paperW > 60 ? 576 : ($paperW >= 50 ? 384 : 0);
    }
    if ($h <= 0) {
        $h = (int) (ceil($paperW * (float) $cfg['dots_per_mm'] / 8) * 8);
    }
    $h = max(192, min(832, $h));
    return $h - ($h % 8);
}

/** @param list<array{0:int,1:int,2:int,3:int}> $rects */
function food_ticket_tpl_dither(GdImage $im, array $rects): void
{
    foreach ($rects as [$rx, $ry, $rw, $rh]) {
        $rx = max(0, $rx);
        $ry = max(0, $ry);
        $rw = min($rw, imagesx($im) - $rx);
        $rh = min($rh, imagesy($im) - $ry);
        if ($rw <= 0 || $rh <= 0) {
            continue;
        }
        $g = [];
        for ($y = 0; $y < $rh; $y++) {
            for ($x = 0; $x < $rw; $x++) {
                $p = imagecolorat($im, $rx + $x, $ry + $y);
                $g[$y][$x] = (((($p >> 16) & 255) * 299) + ((($p >> 8) & 255) * 587) + (($p & 255) * 114)) / 1000.0;
            }
        }
        $black = (int) imagecolorallocate($im, 0, 0, 0);
        $white = (int) imagecolorallocate($im, 255, 255, 255);
        for ($y = 0; $y < $rh; $y++) {
            for ($x = 0; $x < $rw; $x++) {
                $old = $g[$y][$x];
                $new = $old < 128 ? 0.0 : 255.0;
                $err = $old - $new;
                imagesetpixel($im, $rx + $x, $ry + $y, $new === 0.0 ? $black : $white);
                if ($x + 1 < $rw) {
                    $g[$y][$x + 1] += $err * 7 / 16;
                }
                if ($y + 1 < $rh) {
                    if ($x > 0) {
                        $g[$y + 1][$x - 1] += $err * 3 / 16;
                    }
                    $g[$y + 1][$x] += $err * 5 / 16;
                    if ($x + 1 < $rw) {
                        $g[$y + 1][$x + 1] += $err * 1 / 16;
                    }
                }
            }
        }
    }
}

/**
 * مدل چاپ → تصویر GD (به اندازهٔ واقعی کاغذ: هر میلی‌متر = dots_per_mm نقطه)، سپس برش/تراز وسط به عرض سرِ چاپ.
 * @param array<string,mixed> $model
 * @param array<string,mixed> $cfg
 * @return array{0:GdImage,1:array<string,mixed>} [تصویر نهایی، اطلاعات (فونت‌های واقعی، برش، هشدار)]
 */
function food_ticket_tpl_render_gd(array $model, array $cfg): array
{
    if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('افزونهٔ GD (با FreeType) در PHP فعال نیست؛ در php.ini خط extension=gd را فعال و سرویس را ری‌استارت کنید.');
    }
    $S = max(1, min(3, (int) ($cfg['supersample'] ?? 2)));
    $dpm = (float) $cfg['dots_per_mm'];
    $px = $dpm * $S; // پیکسل بر میلی‌متر در مقیاس رسم
    $mm = static fn (float $v): int => (int) round($v * $px);
    $W1 = (int) (ceil((float) $model['paper_w'] * $dpm / 8) * 8);
    $H1 = (int) max(8, round((float) $model['paper_h'] * $dpm));
    $W = $W1 * $S;
    $H = $H1 * $S;
    $native = !empty($cfg['gd_native_bidi']);
    $warnings = [];
    $fontsUsed = [];

    $im = imagecreatetruecolor($W, $H);
    $white = (int) imagecolorallocate($im, 255, 255, 255);
    $black = (int) imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $white);
    $thin = max(1, (int) round(0.25 * $px));
    $dither = [];

    foreach ((array) $model['items'] as $it) {
        $type = (string) $it['type'];
        if ($type === 'text') {
            [$fontPath, $faux, $famUsed] = food_ticket_tpl_find_font((string) $it['font'], !empty($it['bold']));
            $fontsUsed[(string) $it['font']] = $famUsed;
            $em = max(6, (int) round((float) $it['pt'] * 25.4 / 72.0 * $px));
            [$asc, $desc] = food_ticket_np_metrics($em, $fontPath);
            $pitch = max(4, (int) round($em * (float) $it['lh']));
            $left = $mm((float) $it['x']);
            $maxW = max(4, $mm((float) $it['w']));
            $top0 = $mm((float) $it['y']);
            $n = 0;
            foreach (explode("\n", (string) $it['text']) as $para) {
                foreach (food_ticket_np_wrap($para, $em, $fontPath, $maxW, $native) as $ln) {
                    $lw = (int) $ln['w'];
                    $bx0 = match ((string) $it['align']) {
                        'center' => $left + intdiv($maxW - $lw, 2),
                        'left' => $left,
                        default => $left + $maxW - $lw,
                    };
                    $box = imagettfbbox($em * 0.75, 0.0, $fontPath, (string) $ln['vis']);
                    $off = $box === false ? 0 : -min($box[0], $box[6]);
                    $bx = (int) ($bx0 + $off);
                    $by = (int) ($top0 + $n * $pitch + intdiv($pitch - ($asc + $desc), 2) + $asc);
                    imagettftext($im, $em * 0.75, 0.0, $bx, $by, $black, $fontPath, (string) $ln['vis']);
                    if ($faux) {
                        imagettftext($im, $em * 0.75, 0.0, $bx + $S, $by, $black, $fontPath, (string) $ln['vis']);
                    }
                    $n++;
                }
            }
            if (!empty($it['border'])) {
                imagesetthickness($im, $thin);
                imagerectangle($im, $left, $top0, $left + $mm((float) $it['w']), $top0 + $mm((float) $it['h']), $black);
            }
        } elseif ($type === 'line') {
            $ly = $mm((float) $it['y']);
            $x1 = $mm((float) $it['x']);
            $x2 = $mm((float) $it['x'] + (float) $it['w']);
            $dash = max(2, (int) round(1.2 * $px));
            $th = max(1, (int) round(0.3 * $px));
            for ($lx = $x1; $lx < $x2; $lx += $dash * 2) {
                imagefilledrectangle($im, $lx, $ly, min($lx + $dash, $x2), $ly + $th - 1, $black);
            }
        } elseif ($type === 'image') {
            $raw = base64_decode((string) $it['data'], true);
            $src = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($src) {
                $dx = $mm((float) $it['x']);
                $dy = $mm((float) $it['y']);
                $dw = max(2, $mm((float) $it['w']));
                $dh = max(2, $mm((float) $it['h']));
                imagealphablending($im, true);
                imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, imagesx($src), imagesy($src));
                $dither[] = [intdiv($dx, $S), intdiv($dy, $S), intdiv($dw, $S) + 1, intdiv($dh, $S) + 1];
            } else {
                $warnings[] = 'فایل لوگو خوانده نشد.';
            }
        } elseif ($type === 'qr') {
            $rows = (array) $it['matrix'];
            $qn = count($rows);
            if ($qn > 0) {
                $side1 = (int) floor((float) $it['w'] * $dpm);
                $m1 = max(1, intdiv($side1, $qn + 2));
                $ox1 = (int) round((float) $it['x'] * $dpm) + intdiv($side1 - $m1 * $qn, 2);
                $oy1 = (int) round((float) $it['y'] * $dpm) + intdiv($side1 - $m1 * $qn, 2);
                for ($r = 0; $r < $qn; $r++) {
                    $row = (string) $rows[$r];
                    for ($c = 0; $c < $qn; $c++) {
                        if ($row[$c] === '1') {
                            imagefilledrectangle(
                                $im,
                                ($ox1 + $c * $m1) * $S,
                                ($oy1 + $r * $m1) * $S,
                                ($ox1 + ($c + 1) * $m1) * $S - 1,
                                ($oy1 + ($r + 1) * $m1) * $S - 1,
                                $black
                            );
                        }
                    }
                }
            }
        }
    }
    if (!empty($model['border'])) {
        $inset = $mm((float) $model['border_inset']);
        imagesetthickness($im, $thin);
        imagerectangle($im, $inset, $inset, $W - 1 - $inset, $H - 1 - $inset, $black);
    }
    imagesetthickness($im, 1);

    $flat = $im;
    if ($S > 1) {
        $flat = imagecreatetruecolor($W1, $H1);
        imagecopyresampled($flat, $im, 0, 0, 0, 0, $W1, $H1, $W, $H);
    }
    if ($dither !== []) {
        food_ticket_tpl_dither($flat, $dither);
    }

    // برش/تراز وسط به عرض سرِ چاپ
    $head = food_ticket_tpl_head_dots((float) $model['paper_w'], $cfg);
    $out = imagecreatetruecolor($head, $H1);
    imagefilledrectangle($out, 0, 0, $head - 1, $H1 - 1, (int) imagecolorallocate($out, 255, 255, 255));
    $cropEach = 0;
    if ($W1 > $head) {
        $cropEach = intdiv($W1 - $head, 2);
        imagecopy($out, $flat, 0, 0, $cropEach, 0, $head, $H1);
        $warnings[] = sprintf(
            'عرض قابل چاپ چاپگر %.1f میلی‌متر است؛ %.1f میلی‌متر از هر لبهٔ فیش (کاغذ %.1f) چاپ نمی‌شود.',
            $head / $dpm,
            $cropEach / $dpm,
            (float) $model['paper_w']
        );
    } else {
        imagecopy($out, $flat, intdiv($head - $W1, 2), 0, 0, 0, $W1, $H1);
    }
    return [$out, [
        'fonts' => $fontsUsed,
        'warnings' => $warnings,
        'head_dots' => $head,
        'crop_each_side_dots' => $cropEach,
        'dots_per_mm' => $dpm,
        'width_px' => $head,
        'height_px' => $H1,
    ]];
}

function food_ticket_tpl_binarize(GdImage $im, int $threshold): GdImage
{
    $w = imagesx($im);
    $h = imagesy($im);
    $out = imagecreatetruecolor($w, $h);
    $black = (int) imagecolorallocate($out, 0, 0, 0);
    $white = (int) imagecolorallocate($out, 255, 255, 255);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            imagesetpixel($out, $x, $y, (imagecolorat($im, $x, $y) & 0xFF) < $threshold ? $black : $white);
        }
    }
    return $out;
}

/**
 * بایت‌های ESC/POS که دقیقاً به چاپگر شبکه فرستاده می‌شود (قالب فعال → مدل → رسم GD → raster).
 * @return array{0:string,1:array<string,mixed>} [payload, model]
 */
function food_ticket_tpl_network_payload(array $event, ?array $row = null): array
{
    $model = food_ticket_tpl_model($event, $row);
    $cfg = food_ticket_tpl_net_cfg();
    [$im, $info] = food_ticket_tpl_render_gd($model, $cfg);
    $cfg['cut'] = !empty($model['cut']);
    if (!$model['variable']) {
        $cfg['feed_lines'] = 0; // اندازهٔ کاغذ ثابت است؛ تغذیهٔ اضافه برچسب بعدی را جابه‌جا می‌کند
    }
    $payload = food_ticket_np_image_payload($im, $cfg);
    $trim = function_exists('food_ticket_print_trim_info') ? food_ticket_print_trim_info() : ['dots' => 0, 'mm' => 0.0, 'first_ink_dots' => 0];
    food_ticket_tpl_last_info(sprintf(
        'tpl=#%d "%s" net %.1fx%.1fmm img %dx%d%s',
        (int) $model['template_id'],
        (string) $model['template_name'],
        (float) $model['paper_w'],
        (float) $model['paper_h'],
        (int) $info['width_px'],
        (int) $info['height_px'],
        ((float) ($model['top_trim_mm'] ?? 0) > 0 || (int) $trim['dots'] > 0)
            ? sprintf(' | top-trim=%.1fmm+%.1fmm', (float) ($model['top_trim_mm'] ?? 0), (float) $trim['mm'])
            : ''
    ));
    foreach ((array) $info['warnings'] as $w) {
        error_log('[food-template] ' . $w);
    }
    return [$payload, $model];
}

function food_ticket_tpl_print_network(array $event, string $host, int $port): void
{
    [$payload] = food_ticket_tpl_network_payload($event);
    $cfg = food_ticket_tpl_net_cfg();
    food_ticket_np_send($host, $port, $payload, (int) $cfg['io_timeout']);
}

/**
 * پیش‌نمایش واقعی: همان تصویر ۱-بیتی که به چاپگر شبکه ارسال می‌شود.
 * @return array<string,mixed>
 */
function food_ticket_tpl_preview(array $event, array $row): array
{
    $model = food_ticket_tpl_model($event, $row);
    $cfg = food_ticket_tpl_net_cfg();
    [$im, $info] = food_ticket_tpl_render_gd($model, $cfg);
    $bin = food_ticket_tpl_binarize($im, max(1, min(254, (int) $cfg['threshold'])));
    ob_start();
    imagepng($bin);
    $png = (string) ob_get_clean();
    return [
        'image' => 'data:image/png;base64,' . base64_encode($png),
        'paper_mm' => ['w' => (float) $model['paper_w'], 'h' => (float) $model['paper_h']],
        'variable_height' => (bool) $model['variable'],
        'item_count' => count((array) $model['items']),
    ] + $info;
}

// ───────────────────────────── مسیر ۱: صف چاپ ویندوز (GDI+ / PrintDocument) ─────────────────────────────

/** متن اسکریپت PowerShell برای چاپ مدل روی درایور ویندوز. */
function food_ticket_tpl_windows_script(array $model, string $printer, string $previewPath = '', bool $probe = false): string
{
    $payload = base64_encode((string) json_encode([
        'printer' => $printer,
        'paper_w' => (float) $model['paper_w'],
        'paper_h' => (float) $model['paper_h'],
        'border' => !empty($model['border']),
        'border_inset' => (float) $model['border_inset'],
        'fallback_fonts' => ['Vazirmatn', 'Vazir', 'B Nazanin', 'Tahoma', 'Arial'],
        'preview_path' => $previewPath,
        // جبران عمودی اختیاری (میلی‌متر): اگر درایور/چاپگر بالای کاغذ فاصلهٔ سخت‌گیرانه‌ای
        // اعمال کرد، با کلید win_top_shift_mm در storage/food_ticket_netprint.json (مقدار منفی)
        // کل طرح به بالا منتقل می‌شود؛ تنظیمات درایور دست‌کاری نمی‌شود.
        'top_shift_mm' => (float) ($model['win_top_shift_mm'] ?? 0.0),
        // probe = فقط اندازه‌گیری و گزارش درایور (بدون چاپ) — برای ابزار تشخیص
        'probe' => $probe,
        'ink_first_mm' => round(food_ticket_tpl_first_ink_mm((array) $model['items']), 1),
        'ink_last_mm' => round(food_ticket_tpl_last_ink_mm((array) $model['items']), 1),
        'feed_lines' => 0,
        'items' => $model['items'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

    $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$data = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__FT_PAYLOAD__')) | ConvertFrom-Json
$installed = @((New-Object System.Drawing.Text.InstalledFontCollection).Families | ForEach-Object { $_.Name })
$fontCache = @{}
$imgCache = @{}
$fontUsed = @{}

function Pick-Font([string]$want) {
  if ($installed -contains $want) { return $want }
  foreach ($c in @($data.fallback_fonts)) { if ($installed -contains [string]$c) { return [string]$c } }
  return 'Tahoma'
}
function Get-Fnt([string]$family, [double]$pt, [bool]$bold) {
  $key = "$family|$pt|$bold"
  if (-not $fontCache.ContainsKey($key)) {
    $style = [System.Drawing.FontStyle]::Regular
    if ($bold) { $style = [System.Drawing.FontStyle]::Bold }
    $name = Pick-Font $family
    $fontUsed[$family] = $name
    $fontCache[$key] = New-Object System.Drawing.Font($name, [single]$pt, $style, [System.Drawing.GraphicsUnit]::Point)
  }
  return $fontCache[$key]
}
function New-Sf([string]$align) {
  $sf = New-Object System.Drawing.StringFormat
  $sf.FormatFlags = [System.Drawing.StringFormatFlags]'DirectionRightToLeft,NoWrap,NoClip'
  $sf.Trimming = [System.Drawing.StringTrimming]::None
  if ($align -eq 'left') { $sf.Alignment = [System.Drawing.StringAlignment]::Far }
  elseif ($align -eq 'center') { $sf.Alignment = [System.Drawing.StringAlignment]::Center }
  else { $sf.Alignment = [System.Drawing.StringAlignment]::Near }   # در حالت راست‌به‌چپ یعنی سمت راست
  $sf.LineAlignment = [System.Drawing.StringAlignment]::Near
  return $sf
}
function Measure-W($g, [string]$t, $font, $sf) {
  return [double]($g.MeasureString($t, $font, 100000, $sf).Width)
}
function Wrap-Text($g, [string]$text, $font, $sf, [double]$maxW) {
  $out = New-Object System.Collections.Generic.List[string]
  foreach ($para in ($text -split "`n")) {
    $cur = ''
    foreach ($word in @($para -split '\s+' | Where-Object { $_ -ne '' })) {
      if ($cur -eq '') { $try = $word } else { $try = $cur + ' ' + $word }
      if ($cur -ne '' -and (Measure-W $g $try $font $sf) -gt $maxW) { $out.Add($cur); $cur = $word } else { $cur = $try }
    }
    if ($cur -ne '') { $out.Add($cur) }
  }
  return ,@($out.ToArray())
}
function Get-Img([string]$b64) {
  $k = $b64.Substring(0, [Math]::Min(64, $b64.Length)) + $b64.Length
  if (-not $imgCache.ContainsKey($k)) {
    $bytes = [Convert]::FromBase64String($b64)
    $ms = New-Object System.IO.MemoryStream
    $ms.Write($bytes, 0, $bytes.Length)
    $ms.Position = 0
    $imgCache[$k] = [System.Drawing.Image]::FromStream($ms)
  }
  return $imgCache[$k]
}

function Draw-Model($g, [double]$ox, [double]$oy) {
  $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
  $g.TranslateTransform([single]$ox, [single]$oy)
  $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::None
  $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $black = [System.Drawing.Brushes]::Black
  $pen = New-Object System.Drawing.Pen ([System.Drawing.Color]::Black), 0.25
  $penDash = New-Object System.Drawing.Pen ([System.Drawing.Color]::Black), 0.3
  $penDash.DashStyle = [System.Drawing.Drawing2D.DashStyle]::Dash
  foreach ($it in @($data.items)) {
    $t = [string]$it.type
    if ($t -eq 'text') {
      $font = Get-Fnt ([string]$it.font) ([double]$it.pt) ([bool]$it.bold)
      $sf = New-Sf ([string]$it.align)
      $lines = @(Wrap-Text $g ([string]$it.text) $font $sf ([double]$it.w))
      $fh = [double]$font.GetHeight($g)
      $pitch = [double]$it.pt * 25.4 / 72.0 * [double]$it.lh
      $n = 0
      foreach ($ln in $lines) {
        $ly = [double]$it.y + $n * $pitch + ($pitch - $fh) / 2.0
        $rect = New-Object System.Drawing.RectangleF([single]$it.x, [single]$ly, [single]$it.w, [single]($fh + 1.0))
        $g.DrawString([string]$ln, $font, $black, $rect, $sf)
        $n++
      }
      if ($it.border) { $g.DrawRectangle($pen, [single]$it.x, [single]$it.y, [single]$it.w, [single]$it.h) }
    }
    elseif ($t -eq 'line') {
      $g.DrawLine($penDash, [single]$it.x, [single]$it.y, [single]([double]$it.x + [double]$it.w), [single]$it.y)
    }
    elseif ($t -eq 'image') {
      $img = Get-Img ([string]$it.data)
      $g.DrawImage($img, (New-Object System.Drawing.RectangleF([single]$it.x, [single]$it.y, [single]$it.w, [single]$it.h)))
    }
    elseif ($t -eq 'qr') {
      $rows = @($it.matrix)
      $qn = $rows.Count
      $ms = [double]$it.w / ($qn + 2)
      for ($r = 0; $r -lt $qn; $r++) {
        $row = [string]$rows[$r]
        for ($c = 0; $c -lt $qn; $c++) {
          if ($row[$c] -eq '1') {
            $g.FillRectangle($black, [single]([double]$it.x + ($c + 1) * $ms), [single]([double]$it.y + ($r + 1) * $ms), [single]($ms + 0.02), [single]($ms + 0.02))
          }
        }
      }
    }
  }
  if ($data.border) {
    $ins = [double]$data.border_inset
    $g.DrawRectangle($pen, [single]$ins, [single]$ins, [single]([double]$data.paper_w - 2 * $ins), [single]([double]$data.paper_h - 2 * $ins))
  }
}

$paperW = [double]$data.paper_w
$paperH = [double]$data.paper_h

if ([string]$data.preview_path -ne '') {
  $bmp = New-Object System.Drawing.Bitmap ([int][Math]::Ceiling($paperW * 8)), ([int][Math]::Ceiling($paperH * 8))
  $bmp.SetResolution(203.2, 203.2)
  $pg = [System.Drawing.Graphics]::FromImage($bmp)
  $pg.Clear([System.Drawing.Color]::White)
  Draw-Model $pg 0 0
  $pg.Dispose()
  $bmp.Save([string]$data.preview_path, [System.Drawing.Imaging.ImageFormat]::Png)
  $bmp.Dispose()
}

$pd = New-Object System.Drawing.Printing.PrintDocument
$pd.PrinterSettings.PrinterName = [string]$data.printer
if (-not $pd.PrinterSettings.IsValid) { [Console]::Error.WriteLine('PRINTER_NOT_FOUND: ' + [string]$data.printer); exit 3 }
$pd.DocumentName = 'FoodTicket'
$pd.PrintController = New-Object System.Drawing.Printing.StandardPrintController
$pd.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins 0, 0, 0, 0
$pd.DefaultPageSettings.Landscape = $false

# اندازهٔ واقعی کاغذ → درایور چاپگر (واحد PaperSize: صدم اینچ)
$wH = [int][Math]::Round($paperW / 25.4 * 100)
$hH = [int][Math]::Round($paperH / 25.4 * 100)
$chosen = $null
$matched = $false
foreach ($ps in $pd.PrinterSettings.PaperSizes) {
  if ([Math]::Abs($ps.Width - $wH) -le 4 -and [Math]::Abs($ps.Height - $hH) -le 4) { $chosen = $ps; $matched = $true; break }
}
if ($null -eq $chosen) { $chosen = New-Object System.Drawing.Printing.PaperSize 'FoodTicket', $wH, $hH }
$paperNote = ''
try { $pd.DefaultPageSettings.PaperSize = $chosen } catch { $paperNote = 'PaperSize rejected: ' + $_.Exception.Message }
$appliedW = [Math]::Round($pd.DefaultPageSettings.PaperSize.Width / 100 * 25.4, 1)
$appliedH = [Math]::Round($pd.DefaultPageSettings.PaperSize.Height / 100 * 25.4, 1)

$pd.add_PrintPage({ param($sender, $e)
  $hx = [double]$e.PageSettings.HardMarginX * 0.254
  $hy = [double]$e.PageSettings.HardMarginY * 0.254
  $script:hardX = $hx
  $script:hardY = $hy
  $shift = 0.0
  if ($data.top_shift_mm) { $shift = [double]$data.top_shift_mm }
  Draw-Model $e.Graphics (-$hx) (-$hy + $shift)
  $e.HasMorePages = $false
})
$script:hardX = 0.0
$script:hardY = 0.0

if ($data.probe) {
  # حالت اندازه‌گیری: هیچ کاغذی چاپ نمی‌شود؛ فقط اندازه‌های واقعی درایور گزارش می‌شود
  $forms = @()
  foreach ($ps in $pd.PrinterSettings.PaperSizes) {
    $forms += @{ name = [string]$ps.PaperName; w_mm = [Math]::Round($ps.Width / 100 * 25.4, 1); h_mm = [Math]::Round($ps.Height / 100 * 25.4, 1) }
  }
  @{ ok = $true; probe = $true; printer = [string]$data.printer; printer_valid = $true
     paper_w = $paperW; paper_h = $paperH; applied_w = $appliedW; applied_h = $appliedH
     driver_form_matched = $matched; note = $paperNote
     ink_first_mm = [double]$data.ink_first_mm; ink_last_mm = [double]$data.ink_last_mm
     margin_x_mm = [Math]::Round([double]$pd.DefaultPageSettings.Margins.Left / 100 * 25.4, 1)
     margin_y_mm = [Math]::Round([double]$pd.DefaultPageSettings.Margins.Top / 100 * 25.4, 1)
     printable_w_mm = [Math]::Round($pd.DefaultPageSettings.PrintableArea.Width / 100 * 25.4, 1)
     printable_h_mm = [Math]::Round($pd.DefaultPageSettings.PrintableArea.Height / 100 * 25.4, 1)
     forms = $forms; forms_count = @($forms).Count } | ConvertTo-Json -Compress -Depth 5
  $pd.Dispose()
  exit 0
}

$pd.Print()
$pd.Dispose()
@{ ok = $true; paper_w = $paperW; paper_h = $paperH; applied_w = $appliedW; applied_h = $appliedH; driver_form_matched = $matched; note = $paperNote; hard_margin_x_mm = [Math]::Round($script:hardX, 1); hard_margin_y_mm = [Math]::Round($script:hardY, 1); ink_first_mm = [double]$data.ink_first_mm; ink_last_mm = [double]$data.ink_last_mm; fonts = $fontUsed } | ConvertTo-Json -Compress
exit 0
PS;
    return str_replace('__FT_PAYLOAD__', $payload, $ps);
}

function food_ticket_tpl_print_windows(array $event, string $printer): void
{
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        throw new RuntimeException('چاپ از صف ویندوز فقط روی ویندوز ممکن است.');
    }
    $model = food_ticket_tpl_model($event);
    $ps = food_ticket_tpl_windows_script($model, $printer);
    $root = defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__);
    $spoolDir = $root . '/storage/food_ticket_spool';
    if (!is_dir($spoolDir)) {
        @mkdir($spoolDir, 0750, true);
    }
    $tmp = $spoolDir . DIRECTORY_SEPARATOR . 'ft_tpl_' . bin2hex(random_bytes(4)) . '.ps1';
    // BOM لازم است تا PowerShell 5 فایل UTF-8 را درست بخواند
    if (@file_put_contents($tmp, "\xEF\xBB\xBF" . $ps, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن اسکریپت چاپ ویندوز ممکن نشد (دسترسی پوشه storage/food_ticket_spool).');
    }
    try {
        $output = [];
        $code = 0;
        @exec('powershell -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('چاپ گرافیکی ویندوز ناموفق بود (کد ' . $code . '): ' . mb_substr(implode(' ', $output), 0, 400));
        }
        $info = '';
        $last = trim((string) end($output));
        $res = json_decode($last, true);
        if (is_array($res)) {
            $info = sprintf(
                'tpl=#%d "%s" win req %.1fx%.1fmm applied %.1fx%.1fmm%s',
                (int) $model['template_id'],
                (string) $model['template_name'],
                (float) ($res['paper_w'] ?? 0),
                (float) ($res['paper_h'] ?? 0),
                (float) ($res['applied_w'] ?? 0),
                (float) ($res['applied_h'] ?? 0),
                !empty($res['driver_form_matched']) ? ' (form matched)' : ' (custom size)'
            );
            $hDiff = abs((float) ($res['applied_h'] ?? 0) - (float) ($res['paper_h'] ?? 0));
            if ($hDiff > 1.5) {
                // ارتفاعی که درایور واقعاً چاپ می‌کند بیشتر از طرح است → همان مقدار، پرتِ پایین فیش می‌شود
                error_log(sprintf(
                    '[food-template] درایور ارتفاع %.1fmm را نپذیرفت و %.1fmm چاپ می‌کند (%.1fmm پرت در پایین فیش). در تنظیمات چاپگر یک Form با ارتفاع %.1f میلی‌متر بسازید.',
                    (float) ($res['paper_h'] ?? 0), (float) ($res['applied_h'] ?? 0), $hDiff, (float) ($res['paper_h'] ?? 0)
                ));
            } else {
                food_ticket_tpl_last_info($info . sprintf(' | page %.1fmm (ink %.1f..%.1f)', (float) ($res['applied_h'] ?? 0), (float) ($res['ink_first_mm'] ?? 0), (float) ($res['ink_last_mm'] ?? 0)));
            }
            if (abs((float) ($res['applied_w'] ?? 0) - (float) ($res['paper_w'] ?? 0)) > 1.5 || !empty($res['note'])) {
                error_log('[food-template] درایور اندازهٔ کاغذ ' . ($res['paper_w'] ?? '?') . 'x' . ($res['paper_h'] ?? '?') . ' را نپذیرفت؛ اندازهٔ اعمال‌شده: ' . ($res['applied_w'] ?? '?') . 'x' . ($res['applied_h'] ?? '?') . ' ' . ($res['note'] ?? '') . ' — در تنظیمات چاپگر یک Form/Paper با همین اندازه بسازید.');
            }
        } else {
            $info = sprintf('tpl=#%d "%s" win %.1fx%.1fmm', (int) $model['template_id'], (string) $model['template_name'], (float) $model['paper_w'], (float) $model['paper_h']);
        }
        food_ticket_tpl_last_info($info);
    } finally {
        @unlink($tmp);
    }
}

// ───────────────────────────── API طراح (food_api=templates*) ─────────────────────────────

function food_ticket_tpl_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    return preg_match('#^https?://#i', $path) === 1 ? $path : ltrim(str_replace('\\', '/', $path), '/');
}

/** @return array<string,mixed> */
function food_ticket_tpl_brand_payload(): array
{
    $sys = food_ticket_tpl_logo_setting('system');
    $tk = food_ticket_tpl_logo_setting('ticket');
    return [
        'system_name' => food_ticket_tpl_system_name(),
        'system_logo_url' => food_ticket_tpl_asset_url($sys),
        'has_system_logo' => food_ticket_tpl_logo_bytes('system') !== '',
        'ticket_logo_url' => food_ticket_tpl_asset_url($tk),
        'has_ticket_logo' => food_ticket_tpl_logo_bytes('ticket') !== '',
    ];
}

/** @return array<string,mixed> */
function food_ticket_tpl_list_payload(): array
{
    food_ticket_tpl_seed_if_empty();
    $cfg = food_ticket_tpl_net_cfg();
    return [
        'max' => FOOD_TICKET_TPL_MAX,
        'templates' => food_ticket_tpl_list(),
        'catalog' => food_ticket_tpl_catalog(),
        'fonts' => array_keys(food_ticket_tpl_font_files()),
        'brand' => food_ticket_tpl_brand_payload(),
        'net' => ['dots_per_mm' => (float) $cfg['dots_per_mm'], 'head_dots_80' => food_ticket_tpl_head_dots(80.0, $cfg), 'head_dots_58' => food_ticket_tpl_head_dots(58.0, $cfg)],
    ];
}

/** رویداد نمونه برای پیش‌نمایش/چاپ آزمایشی طراح. @return array<string,mixed> */
function food_ticket_tpl_sample_event(): array
{
    return [
        'id' => 1024,
        'full_name' => 'علی رضایی',
        'personnel_code' => '1234',
        'national_code' => '0012345678',
        'department' => 'فناوری اطلاعات',
        'food_type' => 'چلوکباب',
        'punch_date' => date('Y-m-d'),
        'punch_time' => date('H:i:s'),
        'ticket_key' => 'sample:' . date('Ymd'),
        'event_type' => 'printed',
    ];
}

function food_ticket_tpl_handle_logo_upload(): array
{
    $dir = (defined('APP_ROOT') ? (string) APP_ROOT : dirname(__DIR__)) . '/assets/uploads';
    $old = food_ticket_tpl_setting('food_ticket_logo');
    $removeOld = static function () use ($old, $dir): void {
        if ($old !== '' && str_contains($old, 'food-ticket-receipt-logo-')) {
            $f = $dir . '/' . basename($old);
            if (is_file($f)) {
                @unlink($f);
            }
        }
    };
    if (!empty($_POST['remove'])) {
        $removeOld();
        save_setting('food_ticket_logo', '');
        return food_ticket_tpl_brand_payload();
    }
    $f = $_FILES['logo'] ?? null;
    if (!is_array($f) || empty($f['tmp_name']) || !is_uploaded_file((string) $f['tmp_name'])) {
        throw new RuntimeException('فایل لوگو ارسال نشد.');
    }
    if ((int) ($f['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('حجم لوگو باید حداکثر ۲ مگابایت باشد.');
    }
    $info = @getimagesize((string) $f['tmp_name']);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('لوگوی فیش باید PNG یا JPG باشد.');
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = 'food-ticket-receipt-logo-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string) $f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('ذخیرهٔ لوگوی فیش انجام نشد.');
    }
    $removeOld();
    save_setting('food_ticket_logo', 'assets/uploads/' . $name);
    return food_ticket_tpl_brand_payload();
}

/**
 * مسیرهای templates، templates/delete، templates/activate، templates/preview، templates/logo
 */
function food_ticket_templates_api(string $route, array $user): never
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $uid = (int) ($user['id'] ?? 0);
    if ($route === 'templates' && $method === 'GET') {
        food_ticket_api_json(food_ticket_tpl_list_payload());
    }
    if ($method !== 'POST') {
        food_ticket_api_json(['error' => 'روش درخواست نامعتبر است.'], 405);
    }
    if ($route === 'templates/logo') {
        $brand = food_ticket_tpl_handle_logo_upload();
        food_ticket_api_json(['ok' => true, 'brand' => $brand]);
    }
    $body = food_ticket_api_body();
    if ($route === 'templates') {
        $id = (int) ($body['id'] ?? 0);
        $savedId = food_ticket_tpl_save(
            $id > 0 ? $id : null,
            (string) ($body['name'] ?? ''),
            (float) ($body['paper_width'] ?? 80),
            (float) ($body['paper_height'] ?? 0),
            is_array($body['template'] ?? null) ? $body['template'] : [],
            !empty($body['activate'])
        );
        food_ticket_print_log(null, null, null, null, null, 'template_saved', 0, 'template ' . $savedId . ' by user ' . $uid);
        food_ticket_api_json(['ok' => true, 'saved_id' => $savedId] + food_ticket_tpl_list_payload());
    }
    if ($route === 'templates/delete') {
        food_ticket_tpl_delete((int) ($body['id'] ?? 0));
        food_ticket_print_log(null, null, null, null, null, 'template_deleted', 0, 'template ' . (int) ($body['id'] ?? 0) . ' by user ' . $uid);
        food_ticket_api_json(['ok' => true] + food_ticket_tpl_list_payload());
    }
    if ($route === 'templates/activate') {
        food_ticket_tpl_activate((int) ($body['id'] ?? 0));
        food_ticket_print_log(null, null, null, null, null, 'template_activated', 0, 'template ' . (int) ($body['id'] ?? 0) . ' by user ' . $uid);
        food_ticket_api_json(['ok' => true] + food_ticket_tpl_list_payload());
    }
    if ($route === 'templates/preview') {
        $row = [
            'id' => (int) ($body['id'] ?? 0),
            'name' => (string) ($body['name'] ?? 'پیش‌نمایش'),
            'paper_width' => food_ticket_tpl_num($body['paper_width'] ?? 80, 20, 120, 80.0),
            'paper_height' => ((float) ($body['paper_height'] ?? 0)) <= 0 ? 0.0 : food_ticket_tpl_num($body['paper_height'], 15, 600, 50.0),
            'is_active' => false,
            'template' => food_ticket_tpl_normalize(is_array($body['template'] ?? null) ? $body['template'] : []),
            'updated_at' => '',
        ];
        food_ticket_api_json(['ok' => true] + food_ticket_tpl_preview(food_ticket_tpl_sample_event(), $row));
    }
    food_ticket_api_json(['error' => 'مسیر API قالب فیش پیدا نشد.'], 404);
}
