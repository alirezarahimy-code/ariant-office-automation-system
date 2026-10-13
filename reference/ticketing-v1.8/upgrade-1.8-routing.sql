/* Run once after upgrade-1.8-final.sql on an existing installation. */
CREATE TABLE IF NOT EXISTS handling_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code ENUM('it','support') NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    manager_user_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_handling_manager (manager_user_id),
    CONSTRAINT fk_handling_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN handling_unit_id INT UNSIGNED NULL AFTER department_id;
ALTER TABLE users ADD CONSTRAINT fk_users_handling_unit FOREIGN KEY (handling_unit_id) REFERENCES handling_units(id) ON DELETE SET NULL;

ALTER TABLE categories ADD COLUMN service_group ENUM('it','support') NOT NULL DEFAULT 'it' AFTER name;
ALTER TABLE service_catalog ADD COLUMN service_group ENUM('it','support') NOT NULL DEFAULT 'it' AFTER description;
ALTER TABLE service_catalog ADD COLUMN handling_unit_id INT UNSIGNED NULL AFTER department_id;
ALTER TABLE service_catalog ADD COLUMN requires_asset TINYINT(1) NOT NULL DEFAULT 1 AFTER handling_unit_id;
ALTER TABLE service_catalog ADD COLUMN default_ticket_type ENUM('incident','request','problem','change') NOT NULL DEFAULT 'incident' AFTER requires_asset;
ALTER TABLE service_catalog ADD CONSTRAINT fk_services_handling FOREIGN KEY (handling_unit_id) REFERENCES handling_units(id) ON DELETE SET NULL;
ALTER TABLE tickets ADD COLUMN service_group ENUM('it','support') NOT NULL DEFAULT 'it' AFTER custom_fields;
ALTER TABLE tickets ADD COLUMN handling_unit_id INT UNSIGNED NULL AFTER asset_id;
ALTER TABLE tickets ADD COLUMN support_location VARCHAR(255) NULL AFTER handling_unit_id;
ALTER TABLE tickets ADD COLUMN support_equipment TEXT NULL AFTER support_location;
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_handling FOREIGN KEY (handling_unit_id) REFERENCES handling_units(id) ON DELETE SET NULL;

INSERT INTO handling_units (code, name) VALUES ('it', 'واحد فناوری اطلاعات'), ('support', 'واحد پشتیبانی')
    ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1;
UPDATE users u JOIN handling_units h ON h.code = CASE WHEN u.is_it_agent = 1 OR u.role = 'agent' THEN 'it' ELSE 'support' END SET u.handling_unit_id = h.id WHERE u.role IN ('agent', 'manager');
UPDATE service_catalog SET service_group = 'it', handling_unit_id = (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1), requires_asset = 1 WHERE handling_unit_id IS NULL OR service_group IS NULL OR service_group = '';
UPDATE tickets SET service_group = 'it', handling_unit_id = (SELECT id FROM handling_units WHERE code = 'it' LIMIT 1) WHERE handling_unit_id IS NULL OR service_group IS NULL OR service_group = '';

INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
SELECT 'خرابی یا کندی رایانه', 'IT-COMPUTER', 'ثبت خرابی، کندی یا خطای سیستم کاربر.', 'it', 'urgent', h.id, 1, 'incident' FROM handling_units h WHERE h.code = 'it' AND NOT EXISTS (SELECT 1 FROM service_catalog WHERE code = 'IT-COMPUTER');
INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
SELECT 'نصب نرم‌افزار سازمانی', 'IT-SOFTWARE', 'درخواست نصب نرم‌افزارهای مورد تأیید سازمان.', 'it', 'normal', h.id, 1, 'request' FROM handling_units h WHERE h.code = 'it' AND NOT EXISTS (SELECT 1 FROM service_catalog WHERE code = 'IT-SOFTWARE');
INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
SELECT 'دسترسی به سامانه داخلی', 'IT-ACCESS', 'درخواست دسترسی به سامانه‌های داخلی سازمان.', 'it', 'normal', h.id, 1, 'request' FROM handling_units h WHERE h.code = 'it' AND NOT EXISTS (SELECT 1 FROM service_catalog WHERE code = 'IT-ACCESS');
INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
SELECT 'ثبت یا اصلاح شناسنامه فنی', 'IT-INVENTORY', 'ثبت مشخصات فنی و تجهیزات جانبی یک سیستم سازمانی.', 'it', 'normal', h.id, 1, 'request' FROM handling_units h WHERE h.code = 'it' AND NOT EXISTS (SELECT 1 FROM service_catalog WHERE code = 'IT-INVENTORY');
INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
SELECT 'تجهیزات و فضای اداری', 'SUP-OFFICE', 'درخواست رسیدگی به تجهیزات عمومی یا فضای محل کار.', 'support', 'normal', h.id, 0, 'request' FROM handling_units h WHERE h.code = 'support' AND NOT EXISTS (SELECT 1 FROM service_catalog WHERE code = 'SUP-OFFICE');
INSERT INTO service_catalog (name, code, description, service_group, default_priority, handling_unit_id, requires_asset, default_ticket_type)
SELECT 'خدمات عمومی سازمان', 'SUP-GENERAL', 'درخواست خدمت عمومی داخلی سازمان، بدون وابستگی به اینترنت.', 'support', 'normal', h.id, 0, 'request' FROM handling_units h WHERE h.code = 'support' AND NOT EXISTS (SELECT 1 FROM service_catalog WHERE code = 'SUP-GENERAL');
