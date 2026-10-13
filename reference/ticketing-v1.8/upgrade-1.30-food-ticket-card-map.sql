-- نگاشت کارت RFID به کاربر؛ موتور فیش در صورت نبود جدول آن را خودش می‌سازد (اجرای این فایل اختیاری است)
CREATE TABLE IF NOT EXISTS food_ticket_card_map (
    card_key VARCHAR(120) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    personnel_code VARCHAR(100) NULL,
    hits INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_food_card_map_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
