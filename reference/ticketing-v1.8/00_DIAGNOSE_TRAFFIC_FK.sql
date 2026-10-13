-- اسکریپت تشخیصی خطای کلید خارجی ماژول کنترل تردد
-- این فایل را در phpMyAdmin روی دیتابیس persian_ticketing اجرا کنید
-- و خروجی را برای پشتیبانی بفرستید. چیزی تغییر نمی‌دهد (فقط SELECT است).

-- ۱) نوع دقیق ستون id در جدول users
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id';

-- ۲) موتور و کالیشن جدول users
SELECT ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users';

-- ۳) نمونهٔ کلیدهای خارجی موجود که به users(id) اشاره می‌کنند (برای مقایسهٔ نوع)
SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users';

-- ۴) اگر جدول traffic_visits نیمه‌ساخته باقی مانده، این را نشان می‌دهد
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('traffic_visits','traffic_destinations');
