/* Run once on an installation created by version 1.0. Fresh installs already include these changes. */
CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    code VARCHAR(40) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN department_id INT UNSIGNED NULL AFTER department;
ALTER TABLE users ADD INDEX idx_users_department_id (department_id);
ALTER TABLE users ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
ALTER TABLE tickets ADD COLUMN department_id INT UNSIGNED NULL AFTER category_id;
ALTER TABLE tickets ADD INDEX idx_tickets_department (department_id);
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
