-- نسخه 1.40: معاونت درخواست‌کنندهٔ غذای مهمان (برای فیلتر گزارش)
-- فقط یک ستون اضافه و یک ایندکس می‌سازد؛ داده‌ای حذف یا تغییر نمی‌کند. فقط یک‌بار اجرا شود.
ALTER TABLE food_guest_requests
    ADD COLUMN deputy_unit_id INT UNSIGNED NULL AFTER requester_name,
    ADD KEY idx_food_guest_requests_deputy (deputy_unit_id, request_date);
