-- Food Ticket module (food ticket printing). Run once after the existing 1.8 migrations.

SET @food_db = DATABASE();
SET @food_has_national = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @food_db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'national_code');
SET @food_sql = IF(@food_has_national = 0, 'ALTER TABLE users ADD COLUMN national_code VARCHAR(30) NULL AFTER employee_number', 'SELECT 1');
PREPARE food_stmt FROM @food_sql;
EXECUTE food_stmt;
DEALLOCATE PREPARE food_stmt;
SET @food_has_national_index = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @food_db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_national_code');
SET @food_sql = IF(@food_has_national_index = 0, 'CREATE INDEX idx_users_national_code ON users (national_code)', 'SELECT 1');
PREPARE food_stmt FROM @food_sql;
EXECUTE food_stmt;
DEALLOCATE PREPARE food_stmt;

CREATE TABLE IF NOT EXISTS food_ticket_config (
    id TINYINT UNSIGNED PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    attendance_path VARCHAR(500) NOT NULL DEFAULT '',
    attendance_password_enc TEXT NULL,
    orders_path VARCHAR(500) NOT NULL DEFAULT '',
    orders_table VARCHAR(128) NOT NULL DEFAULT 'food_fish',
    printer_mode ENUM('tcp_raw','windows_share') NOT NULL DEFAULT 'tcp_raw',
    printer_host VARCHAR(255) NOT NULL DEFAULT '',
    printer_port SMALLINT UNSIGNED NOT NULL DEFAULT 9100,
    printer_share VARCHAR(255) NOT NULL DEFAULT '',
    poll_seconds TINYINT UNSIGNED NOT NULL DEFAULT 2,
    max_batch SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    guest_card_uids TEXT NULL,
    max_guest_daily SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    cut_source_rows TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_food_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO food_ticket_config (id) VALUES (1);

CREATE TABLE IF NOT EXISTS food_ticket_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key CHAR(64) NOT NULL UNIQUE,
    source_uid VARCHAR(80) NULL,
    source_card VARCHAR(120) NULL,
    punch_date DATE NULL,
    punch_time TIME NULL,
    personnel_code VARCHAR(100) NULL,
    user_id INT UNSIGNED NULL,
    national_code VARCHAR(30) NULL,
    full_name VARCHAR(190) NULL,
    food_type VARCHAR(190) NULL,
    ticket_key VARCHAR(190) NULL UNIQUE,
    event_type ENUM('printed','print_error','no_food','unknown','inactive','repeat','guest','guest_limit','config_error') NOT NULL,
    print_status ENUM('not_printed','printing','printed','print_error') NOT NULL DEFAULT 'not_printed',
    print_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    source_payload LONGTEXT NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_food_events_date (punch_date, created_at),
    INDEX idx_food_events_status (print_status, event_type),
    INDEX idx_food_events_user (user_id),
    CONSTRAINT fk_food_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_worker_status (
    id TINYINT UNSIGNED PRIMARY KEY,
    host_name VARCHAR(190) NOT NULL DEFAULT '',
    process_id INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen DATETIME NULL,
    status ENUM('ok','warning','error') NOT NULL DEFAULT 'ok',
    last_message TEXT NULL,
    last_error TEXT NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    printed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO food_ticket_worker_status (id) VALUES (1);
