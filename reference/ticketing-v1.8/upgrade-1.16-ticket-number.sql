-- Upgrade 1.16: شماره تیکت خودکار با الگوی گروه خدمت
-- تیکت آی‌تی: IT-00001   |   تیکت پشتیبانی: SUP-00001
-- برنامه ستون و شمارنده را خودکار هم می‌سازد و تیکت‌های قدیمی را در اولین اجرا شماره‌گذاری می‌کند؛
-- اجرای این فایل اختیاری است.

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS ticket_no VARCHAR(40) NULL AFTER id;

CREATE TABLE IF NOT EXISTS ticket_number_seq (
    prefix VARCHAR(20) NOT NULL PRIMARY KEY,
    seq INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
