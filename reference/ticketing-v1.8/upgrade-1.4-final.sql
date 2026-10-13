/* Run after upgrade-1.3-supervisor-inventory.sql on an existing installation. Fresh installs already include these changes. */
ALTER TABLE users ADD COLUMN is_primary_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_it_agent;
ALTER TABLE users ADD COLUMN employee_number VARCHAR(100) NULL AFTER email;
ALTER TABLE users ADD COLUMN phone VARCHAR(80) NULL AFTER employee_number;
UPDATE users SET is_primary_admin = 1 WHERE role = 'admin' AND id = (SELECT first_admin.id FROM (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1) AS first_admin);
ALTER TABLE departments ADD COLUMN manager_user_id INT UNSIGNED NULL AFTER code;
ALTER TABLE departments ADD INDEX idx_departments_manager (manager_user_id);
ALTER TABLE departments ADD CONSTRAINT fk_departments_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE tickets ADD COLUMN asset_id INT UNSIGNED NULL AFTER department_id;
ALTER TABLE tickets ADD INDEX idx_tickets_asset (asset_id);
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL;
ALTER TABLE assets ADD COLUMN computer_type VARCHAR(100) NULL AFTER serial_number;
ALTER TABLE assets ADD COLUMN manufacturer VARCHAR(190) NULL AFTER computer_type;
ALTER TABLE assets ADD COLUMN model VARCHAR(190) NULL AFTER manufacturer;
ALTER TABLE assets ADD COLUMN asset_number VARCHAR(100) NULL AFTER model;
ALTER TABLE assets ADD COLUMN seal_number VARCHAR(100) NULL AFTER asset_number;
ALTER TABLE assets ADD COLUMN employee_number VARCHAR(100) NULL AFTER seal_number;
ALTER TABLE assets ADD COLUMN phone VARCHAR(80) NULL AFTER employee_number;
ALTER TABLE assets ADD COLUMN sound_card VARCHAR(255) NULL AFTER antivirus;
ALTER TABLE assets ADD COLUMN network_card VARCHAR(255) NULL AFTER sound_card;
ALTER TABLE assets ADD COLUMN os_architecture VARCHAR(40) NULL AFTER network_card;
ALTER TABLE assets ADD COLUMN os_serial VARCHAR(190) NULL AFTER os_architecture;
ALTER TABLE assets ADD COLUMN os_install_date DATETIME NULL AFTER os_serial;
ALTER TABLE assets ADD COLUMN registered_users INT UNSIGNED NULL AFTER os_install_date;
CREATE TABLE IF NOT EXISTS ticket_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL UNIQUE,
    agent_id INT UNSIGNED NULL,
    requester_id INT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_ticket_rating_score CHECK (score BETWEEN 1 AND 5),
    CONSTRAINT fk_rating_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rating_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asset_memory_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    slot_no SMALLINT UNSIGNED NOT NULL,
    manufacturer VARCHAR(190) NULL,
    model VARCHAR(190) NULL,
    capacity_mb INT UNSIGNED NULL,
    speed_mhz INT UNSIGNED NULL,
    serial_number VARCHAR(190) NULL,
    collected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_memory_slot (asset_id, slot_no),
    CONSTRAINT fk_memory_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asset_storage_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    slot_no SMALLINT UNSIGNED NOT NULL,
    device_type VARCHAR(60) NULL,
    model VARCHAR(190) NULL,
    capacity_bytes BIGINT UNSIGNED NULL,
    serial_number VARCHAR(190) NULL,
    media_type VARCHAR(100) NULL,
    interface_type VARCHAR(100) NULL,
    collected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_storage_slot (asset_id, slot_no),
    CONSTRAINT fk_storage_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asset_graphics_adapters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    slot_no SMALLINT UNSIGNED NOT NULL,
    model VARCHAR(255) NULL,
    vram_mb INT UNSIGNED NULL,
    driver_version VARCHAR(100) NULL,
    collected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_graphics_slot (asset_id, slot_no),
    CONSTRAINT fk_asset_graphics_slot FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asset_peripherals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    peripheral_type ENUM('monitor','printer','scanner','modem','network_card','sound_card','case','mouse','keyboard','other') NOT NULL,
    slot_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    model VARCHAR(255) NULL,
    manufacturer VARCHAR(190) NULL,
    serial_number VARCHAR(190) NULL,
    asset_number VARCHAR(100) NULL,
    seal_number VARCHAR(100) NULL,
    details_json JSON NULL,
    source ENUM('manual','agent','server_inventory') NOT NULL DEFAULT 'manual',
    collected_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_peripheral_slot (asset_id, peripheral_type, slot_no),
    CONSTRAINT fk_peripheral_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asset_history_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset_events_asset (asset_id),
    INDEX idx_asset_events_ticket (ticket_id),
    CONSTRAINT fk_asset_event_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_event_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
