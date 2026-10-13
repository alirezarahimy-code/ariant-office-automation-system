-- Upgrade 1.24 (نسخهٔ بدون کلید خارجی — راه‌حل قطعی خطای errno 150)
-- ماژول کنترل تردد مراجعین و میهمانان
--
-- چرا بدون FOREIGN KEY؟
-- اگر نوع ستون users.id با created_by دقیقاً یکی نباشد (مثلاً INT بدون UNSIGNED)،
-- MySQL خطای 150 می‌دهد. چون ماژول با LEFT JOIN روی users کار می‌کند و صحت ارجاع
-- در کد PHP کنترل می‌شود، نبودِ قید FOREIGN KEY هیچ مشکلی ایجاد نمی‌کند.
-- (در نصب تازه با schema.sql نسخهٔ دارای FK استفاده می‌شود.)

DROP TABLE IF EXISTS traffic_visits;
DROP TABLE IF EXISTS traffic_destinations;

CREATE TABLE traffic_destinations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_traffic_destination (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE traffic_visits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    serial_no VARCHAR(30) NOT NULL,
    visit_date DATE NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    national_code VARCHAR(10) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    company VARCHAR(190) DEFAULT NULL,
    entry_time TIME DEFAULT NULL,
    exit_time TIME DEFAULT NULL,
    no_visit TINYINT(1) NOT NULL DEFAULT 0,
    with_car TINYINT(1) NOT NULL DEFAULT 0,
    with_mobile TINYINT(1) NOT NULL DEFAULT 0,
    meeting_with VARCHAR(190) DEFAULT NULL,
    approved_by VARCHAR(190) DEFAULT NULL,
    description TEXT,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_traffic_national_code (national_code),
    KEY idx_traffic_visit_date (visit_date),
    KEY idx_traffic_serial (serial_no),
    KEY idx_traffic_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لیست مقصدهای ملاقات، برگرفته از شیت Staff فایل اکسل
INSERT INTO traffic_destinations (title)
SELECT * FROM (SELECT 'مدیرعامل' AS title
    UNION ALL SELECT 'مدیریت اداری'
    UNION ALL SELECT 'فاوا'
    UNION ALL SELECT 'معاونت طرح و برنامه'
    UNION ALL SELECT 'دفتر مدیرعامل'
    UNION ALL SELECT 'مدیریت پشتیبانی'
    UNION ALL SELECT 'معاونت الکترونیک') AS seed;
