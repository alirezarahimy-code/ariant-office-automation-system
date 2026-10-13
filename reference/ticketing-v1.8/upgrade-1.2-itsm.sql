/* Run after upgrade-1.1-departments.sql on an existing installation. Fresh installs already include these changes. */
ALTER TABLE users ADD COLUMN is_it_agent TINYINT(1) NOT NULL DEFAULT 0 AFTER department_id;
ALTER TABLE tickets MODIFY status ENUM('new','manager_review','assigned','in_progress','waiting_user','pending','resolved','closed') NOT NULL DEFAULT 'manager_review';
UPDATE tickets SET status = 'in_progress' WHERE status = 'open';
UPDATE tickets SET status = 'waiting_user' WHERE status = 'pending';
ALTER TABLE tickets ADD COLUMN assigned_at DATETIME NULL AFTER assigned_to;
ALTER TABLE tickets ADD COLUMN first_response_at DATETIME NULL AFTER assigned_at;
ALTER TABLE tickets ADD COLUMN resolved_at DATETIME NULL AFTER first_response_at;
ALTER TABLE tickets ADD COLUMN closed_at DATETIME NULL AFTER resolved_at;
ALTER TABLE tickets ADD COLUMN due_at DATETIME NULL AFTER closed_at;
ALTER TABLE tickets ADD COLUMN sla_minutes INT UNSIGNED NULL AFTER due_at;

CREATE TABLE IF NOT EXISTS ticket_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_ticket (ticket_id),
    INDEX idx_events_created (created_at),
    CONSTRAINT fk_events_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(100) NOT NULL UNIQUE,
    hostname VARCHAR(190) NULL,
    serial_number VARCHAR(190) NULL,
    owner_user_id INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    operating_system VARCHAR(255) NULL,
    ip_address VARCHAR(100) NULL,
    mac_address VARCHAR(100) NULL,
    cpu VARCHAR(255) NULL,
    memory_mb INT UNSIGNED NULL,
    disks_json JSON NULL,
    software_json JSON NULL,
    hardware_json JSON NULL,
    source ENUM('manual','server_inventory','agent') NOT NULL DEFAULT 'manual',
    last_inventory_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assets_department (department_id),
    INDEX idx_assets_hostname (hostname),
    CONSTRAINT fk_assets_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_inventory_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    snapshot_json JSON NOT NULL,
    collected_by INT UNSIGNED NULL,
    collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset_history_asset (asset_id),
    CONSTRAINT fk_asset_history_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_history_user FOREIGN KEY (collected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
