-- upgrade-1.25-performance-indexes.sql
-- ایندکس‌های کارایی برای صفحات پربازدید (صف کاری، داشبورد، سوابق، اعلان‌ها، کنترل تردد).
-- این اسکریپت بی‌خطر و قابل‌اجرای چندباره است: فقط ایندکس‌های ناموجود را اضافه می‌کند.

DROP PROCEDURE IF EXISTS itsm_add_index;

DELIMITER //
CREATE PROCEDURE itsm_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL itsm_add_index('tickets', 'idx_tickets_created', 'created_at');
CALL itsm_add_index('tickets', 'idx_tickets_status_created', 'status, created_at');
CALL itsm_add_index('tickets', 'idx_tickets_assignee_status', 'assigned_to, status');
CALL itsm_add_index('tickets', 'idx_tickets_requester_created', 'requester_id, created_at');
CALL itsm_add_index('asset_profiles', 'idx_asset_profiles_asset', 'asset_id');
CALL itsm_add_index('traffic_visits', 'idx_traffic_visit_date', 'visit_date');
CALL itsm_add_index('traffic_visits', 'idx_traffic_open', 'exit_time');
CALL itsm_add_index('activity_logs', 'idx_activity_user_created', 'user_id, created_at');
CALL itsm_add_index('activity_logs', 'idx_activity_module_created', 'module, created_at');

DROP PROCEDURE IF EXISTS itsm_add_index;
