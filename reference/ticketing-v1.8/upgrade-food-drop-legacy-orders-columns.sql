-- یک‌بار اجرا روی نصب‌های قدیمی (اختیاری ولی توصیه‌شده).
-- ستون‌های مسیر/جدول سفارش Access از food_ticket_config حذف می‌شوند؛ سفارش فقط از food_orders داخلی خوانده می‌شود.
-- اگر ستون‌ها وجود نداشته باشند، خطای «Unknown column» را نادیده بگیرید.
ALTER TABLE food_ticket_config DROP COLUMN orders_path;
ALTER TABLE food_ticket_config DROP COLUMN orders_table;
