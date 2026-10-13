/* Run once after upgrade-1.4-final.sql. Fresh installs already include these changes. */
CREATE TABLE IF NOT EXISTS service_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(60) NOT NULL UNIQUE,
    description TEXT NULL,
    default_priority ENUM('normal','urgent','instant','critical') NOT NULL DEFAULT 'normal',
    department_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_services_department (department_id),
    CONSTRAINT fk_services_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_services_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_catalog_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    label VARCHAR(190) NOT NULL,
    field_type ENUM('text','textarea','number','select','date') NOT NULL DEFAULT 'text',
    options_json JSON NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_service_field_key (service_id, field_key),
    CONSTRAINT fk_service_field_service FOREIGN KEY (service_id) REFERENCES service_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets ADD COLUMN service_id INT UNSIGNED NULL AFTER category_id;
ALTER TABLE tickets ADD COLUMN ticket_type ENUM('incident','request','problem','change') NOT NULL DEFAULT 'incident' AFTER service_id;
ALTER TABLE tickets ADD COLUMN parent_ticket_id INT UNSIGNED NULL AFTER ticket_type;
ALTER TABLE tickets ADD COLUMN custom_fields JSON NULL AFTER parent_ticket_id;
ALTER TABLE tickets ADD COLUMN sla_paused_at DATETIME NULL AFTER sla_minutes;
ALTER TABLE tickets ADD COLUMN sla_pause_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER sla_paused_at;
ALTER TABLE tickets ADD INDEX idx_tickets_service (service_id);
ALTER TABLE tickets ADD INDEX idx_tickets_type (ticket_type);
ALTER TABLE tickets ADD INDEX idx_tickets_parent (parent_ticket_id);
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_service FOREIGN KEY (service_id) REFERENCES service_catalog(id) ON DELETE SET NULL;
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_parent FOREIGN KEY (parent_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    INDEX idx_notifications_user (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_key CHAR(64) PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started DATETIME NOT NULL,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_sla_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    alert_type ENUM('due_soon','overdue') NOT NULL,
    due_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_sla_alert (ticket_id, alert_type, due_at),
    CONSTRAINT fk_sla_alert_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    body MEDIUMTEXT NOT NULL,
    category VARCHAR(150) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY ft_knowledge (title, body),
    CONSTRAINT fk_knowledge_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_holiday_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_relations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_asset_id INT UNSIGNED NOT NULL,
    target_asset_id INT UNSIGNED NOT NULL,
    relation_type ENUM('depends_on','connected_to','replaces','located_with','related_to') NOT NULL DEFAULT 'related_to',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_relation (source_asset_id, target_asset_id, relation_type),
    CONSTRAINT fk_relation_source FOREIGN KEY (source_asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_relation_target FOREIGN KEY (target_asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_relation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
