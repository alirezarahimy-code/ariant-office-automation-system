-- نسخه 1.39: درخواست غذای مهمان توسط مدیران برای روزهای آینده
-- این فایل فقط جدول جدید می‌سازد و هیچ داده‌ای را حذف یا تغییر نمی‌دهد (ایمن برای به‌روزرسانی محیط زنده).
CREATE TABLE IF NOT EXISTS food_guest_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_date DATE NOT NULL,
    organization VARCHAR(190) NOT NULL,
    guest_count SMALLINT UNSIGNED NOT NULL,
    food_id INT UNSIGNED NULL,
    requester_name VARCHAR(150) NOT NULL,
    note VARCHAR(500) NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    KEY idx_food_guest_requests_date_status (request_date, status),
    CONSTRAINT fk_food_guest_requests_food FOREIGN KEY (food_id) REFERENCES food_catalog (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
