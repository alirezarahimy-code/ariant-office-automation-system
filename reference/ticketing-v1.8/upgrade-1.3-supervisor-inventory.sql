/* Run after upgrade-1.2-itsm.sql on an existing installation. Fresh installs already include these changes. */
ALTER TABLE users MODIFY role ENUM('user','agent','manager','supervisor','admin') NOT NULL DEFAULT 'user';
UPDATE tickets SET priority = 'normal' WHERE priority = 'low';
UPDATE tickets SET priority = 'instant' WHERE priority = 'high';
UPDATE tickets SET priority = 'critical' WHERE priority = 'urgent';
ALTER TABLE tickets MODIFY priority ENUM('normal','urgent','instant','critical') NOT NULL DEFAULT 'normal';
ALTER TABLE tickets ADD COLUMN supervisor_id INT UNSIGNED NULL AFTER sla_minutes;
ALTER TABLE tickets ADD COLUMN supervisor_approved_at DATETIME NULL AFTER supervisor_id;
ALTER TABLE tickets ADD COLUMN supervisor_note TEXT NULL AFTER supervisor_approved_at;
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE assets ADD COLUMN peripherals_json JSON NULL AFTER hardware_json;
ALTER TABLE assets ADD COLUMN domain_username VARCHAR(190) NULL AFTER peripherals_json;
ALTER TABLE assets ADD COLUMN antivirus VARCHAR(255) NULL AFTER domain_username;
