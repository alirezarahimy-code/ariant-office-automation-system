-- upgrade-1.26-ticket-requesting-unit.sql
-- ستون مستقل «معاونت سازمانی درخواست‌کننده» روی تیکت‌ها.
-- این ستون به چارت سازمانی (org_units) وصل است و از department_id (مسیر رسیدگی/SLA) جداست.
-- قابل اجرای چندباره است.

DROP PROCEDURE IF EXISTS itsm_ensure_req_unit;

DELIMITER //
CREATE PROCEDURE itsm_ensure_req_unit()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'requesting_unit_id'
    ) THEN
        ALTER TABLE tickets ADD COLUMN requesting_unit_id INT UNSIGNED NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND INDEX_NAME = 'idx_tickets_requesting_unit'
    ) THEN
        ALTER TABLE tickets ADD INDEX idx_tickets_requesting_unit (requesting_unit_id);
    END IF;
END //
DELIMITER ;

CALL itsm_ensure_req_unit();
DROP PROCEDURE IF EXISTS itsm_ensure_req_unit;
