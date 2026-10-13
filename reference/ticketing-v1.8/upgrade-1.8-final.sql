/* Run once after upgrade-1.7-governance.sql for existing installations. */
ALTER TABLE tickets
    ADD COLUMN ola_policy_id INT UNSIGNED NULL AFTER sla_minutes,
    ADD COLUMN ola_response_due_at DATETIME NULL AFTER ola_policy_id,
    ADD COLUMN ola_due_at DATETIME NULL AFTER ola_response_due_at,
    ADD COLUMN ola_escalation_1_at DATETIME NULL AFTER ola_due_at,
    ADD COLUMN ola_escalation_2_at DATETIME NULL AFTER ola_escalation_1_at;

ALTER TABLE assets
    ADD COLUMN lifecycle_status ENUM('planned','in_stock','assigned','in_repair','retired','disposed') NOT NULL DEFAULT 'assigned' AFTER source,
    ADD COLUMN purchase_date DATE NULL AFTER lifecycle_status,
    ADD COLUMN warranty_until DATE NULL AFTER purchase_date,
    ADD COLUMN vendor VARCHAR(190) NULL AFTER warranty_until,
    ADD COLUMN acquisition_cost DECIMAL(15,2) NULL AFTER vendor,
    ADD COLUMN disposal_note TEXT NULL AFTER acquisition_cost,
    ADD COLUMN retired_at DATETIME NULL AFTER disposal_note;

CREATE TABLE IF NOT EXISTS ola_policies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    department_id INT UNSIGNED NULL,
    priority ENUM('normal','urgent','critical') NOT NULL,
    response_minutes INT UNSIGNED NOT NULL,
    resolution_minutes INT UNSIGNED NOT NULL,
    escalation_1_minutes INT UNSIGNED NOT NULL,
    escalation_2_minutes INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ola_lookup (department_id, priority, is_active),
    CONSTRAINT fk_ola_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_ola_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_ola_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    alert_type ENUM('response_overdue','resolution_overdue','escalation_1','escalation_2') NOT NULL,
    due_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_ola_alert (ticket_id, alert_type, due_at),
    CONSTRAINT fk_ola_alert_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets ADD CONSTRAINT fk_tickets_ola_policy FOREIGN KEY (ola_policy_id) REFERENCES ola_policies(id) ON DELETE SET NULL;
