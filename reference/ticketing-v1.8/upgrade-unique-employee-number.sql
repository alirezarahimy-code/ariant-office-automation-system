-- کد پرسنلی یکتا: هر کد پرسنلی فقط به یک کاربر تعلق دارد.
-- ۱) ابتدا این پرس‌وجو را اجرا کنید؛ باید هیچ ردیفی برنگرداند.
--    اگر ردیف برگشت، تکراری‌ها را اصلاح کنید و بعد این فایل را اجرا کنید.
SELECT employee_number, COUNT(*) AS total
FROM users
WHERE employee_number IS NOT NULL AND TRIM(employee_number) <> ''
GROUP BY employee_number
HAVING COUNT(*) > 1;

-- ۲) کدهای خالی را NULL کنید تا چند ردیف بدون کد با هم تداخل نکنند.
UPDATE users SET employee_number = NULL WHERE employee_number IS NOT NULL AND TRIM(employee_number) = '';
UPDATE users SET employee_number = TRIM(employee_number) WHERE employee_number IS NOT NULL AND employee_number <> TRIM(employee_number);

-- ۳) قید یکتایی. اگر تکراری وجود داشته باشد، این دستور خطا می‌دهد و چیزی تغییر نمی‌کند.
ALTER TABLE users ADD UNIQUE KEY uq_users_employee_number (employee_number);
