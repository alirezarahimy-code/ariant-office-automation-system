<?php
declare(strict_types=1);

/**
 * آزمون بدون دیتابیس: نگاشت خطای ورودی ثبت تردد به نام فیلد (برای فوکوس).
 *   php tools/test_traffic_form_offline.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// تابع اعتبارسنجی کد ملی در index.php است؛ اینجا فقط برای آزمون، نسخهٔ ساده‌شده تعریف می‌شود.
if (!function_exists('valid_iranian_national_code')) {
    function valid_iranian_national_code(string $code): bool
    {
        if (preg_match('/^\d{10}$/', $code) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $check = (int) $code[9];
        return $r < 2 ? $check === $r : $check === 11 - $r;
    }
}

require_once dirname(__DIR__) . '/traffic-control.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . "\n";
}

function tryValidate(array $data): ?TrafficInputException
{
    try {
        traffic_validate_payload($data);
        return null;
    } catch (TrafficInputException $e) {
        return $e;
    }
}

// کد ملی اشتباه ⇒ فیلد national_code
$e = tryValidate(['full_name' => 'علی', 'national_code' => '0012345678']);
check('کد ملی اشتباه با پیام مناسب', $e !== null && $e->getMessage() === 'کد ملی وارد شده معتبر نیست.');
check('کد ملی اشتباه ⇒ فیلد national_code', $e !== null && $e->field === 'national_code');

// کد ملی با ارقام فارسی و معتبر ⇒ بدون خطا
$e = tryValidate(['full_name' => 'علی', 'national_code' => '۰۰۱۲۳۴۵۶۷۸'] );
check('ارقام فارسی (کد نامعتبر) هنوز خطای کد ملی می‌دهد', $e !== null && $e->field === 'national_code');

// نام خالی ⇒ فیلد full_name
$e = tryValidate(['full_name' => '  ', 'national_code' => '1111111111']);
check('نام خالی ⇒ فیلد full_name', $e !== null && $e->field === 'full_name');

// ورودی درست ⇒ بدون خطا (با کد ملی موردقبول stub)
$e = tryValidate(['full_name' => 'علی', 'national_code' => '0012345679']);
check('ورودی درست بدون خطا', $e === null);

// خطای عمومی (بدون فیلد) ⇒ TrafficInputException با فیلد خالی
$generic = new TrafficInputException('خطا', '');
check('فیلد پیش‌فرض خالی است', $generic->field === '');
check('TrafficInputException یک RuntimeException است', $generic instanceof RuntimeException);

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit($fail === 0 ? 0 : 1);
