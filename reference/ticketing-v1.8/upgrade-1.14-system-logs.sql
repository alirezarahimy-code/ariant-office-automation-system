-- Upgrade 1.14: گزارش خطا و رویدادهای سامانه
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL DEFAULT 'error',
    context VARCHAR(120) NULL,
    message TEXT NULL,
    meta_json JSON NULL,
    user_id INT UNSIGNED NULL,
    request_uri VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_system_logs_created (created_at),
    INDEX idx_system_logs_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
