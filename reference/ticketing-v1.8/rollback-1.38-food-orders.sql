-- Rollback برنامه غذایی و سفارش داخلی
-- ابتدا Worker PHP و دسترسی کاربران را متوقف کنید و در صورت نیاز از سفارش‌های داخلی خروجی بگیرید.
-- فقط پنج جدول جدید این ماژول حذف می‌شوند؛ settings، جداول قبلی، Access سفارش و TENTER تغییر نمی‌کنند.

DROP TABLE IF EXISTS food_order_logs;
DROP TABLE IF EXISTS food_orders;
DROP TABLE IF EXISTS food_calendar_items;
DROP TABLE IF EXISTS food_calendar;
DROP TABLE IF EXISTS food_catalog;
