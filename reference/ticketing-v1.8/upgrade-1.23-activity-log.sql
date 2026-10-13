-- Upgrade 1.23: گزارش فعالیت کاربران (Activity Log)
-- یک جدول واحد برای همهٔ عملیات کاربران؛ جایگزین audit_logs.
-- سامانه تیکتینگ پارسی

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    username VARCHAR(190) NULL,
    full_name VARCHAR(190) NULL,
    role VARCHAR(40) NULL,
    action_code VARCHAR(120) NOT NULL,
    action_label VARCHAR(190) NULL,
    module VARCHAR(80) NULL,
    target_type VARCHAR(40) NULL,
    target_id BIGINT UNSIGNED NULL,
    target_label VARCHAR(255) NULL,
    meta_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    computer_name VARCHAR(190) NULL,
    hostname VARCHAR(190) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_action (action_code),
    INDEX idx_activity_module (module),
    INDEX idx_activity_ip (ip_address),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- انتقال ردیف‌های موجود audit_logs (اگر جدول قدیمی وجود داشته باشد).
-- meta_json از meta قدیمی و action_label از کد عملیات پر می‌شود.
INSERT INTO activity_logs (user_id, action_code, action_label, target_type, target_id, meta_json, created_at)
SELECT a.user_id,
       a.action,
       a.action,
       CASE WHEN a.ticket_id IS NOT NULL THEN 'ticket' ELSE NULL END,
       a.ticket_id,
       a.meta,
       a.created_at
FROM audit_logs a
WHERE NOT EXISTS (SELECT 1 FROM activity_logs l WHERE l.action_code = a.action AND l.created_at = a.created_at AND l.user_id <=> a.user_id);

-- حذف جدول قدیمی پس از مهاجرت.
DROP TABLE IF EXISTS audit_logs;

-- تنظیم بازهٔ نگهداری (۲ ماه = ۶۰ روز)
INSERT INTO settings (`key`, `value`)
VALUES ('activity_log_retention_days', '60')
ON DUPLICATE KEY UPDATE `value` = `value`;

INSERT INTO settings (`key`, `value`)
VALUES ('activity_log_version', '2026-09-29-activity1')
ON DUPLICATE KEY UPDATE `value` = '2026-09-29-activity1';
