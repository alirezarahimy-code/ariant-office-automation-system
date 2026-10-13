-- کارت گروهی غذا (Food Ticket Groups)
-- این جدول‌ها هنگام اولین استفاده به‌صورت خودکار هم ساخته می‌شوند؛ اجرای دستی این فایل اختیاری است.

CREATE TABLE IF NOT EXISTS food_ticket_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    rfid_card VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_group_card (rfid_card),
    INDEX idx_food_group_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_group_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_group_member_user (user_id),
    INDEX idx_food_group_member_group (group_id),
    CONSTRAINT fk_food_group_member_group FOREIGN KEY (group_id) REFERENCES food_ticket_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_food_group_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ثبت اجرای کارت گروهی: فقط گزارش و تشخیص تکرارِ خودِ کارت گروهی (جایگزین کنترل «یک فیش در روز» هر کاربر نیست)
CREATE TABLE IF NOT EXISTS food_ticket_group_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NULL,
    group_title VARCHAR(190) NULL,
    rfid_card VARCHAR(120) NOT NULL,
    source_key CHAR(64) NOT NULL,
    punch_date DATE NOT NULL,
    punch_time TIME NULL,
    run_kind ENUM('first','repeat','inactive') NOT NULL,
    status ENUM('processing','done') NOT NULL DEFAULT 'done',
    members_total INT UNSIGNED NOT NULL DEFAULT 0,
    printed_count INT UNSIGNED NOT NULL DEFAULT 0,
    repeat_count INT UNSIGNED NOT NULL DEFAULT 0,
    no_food_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_group_run_source (source_key),
    INDEX idx_food_group_run_day (group_id, punch_date, run_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
