/* Run once only when upgrade-1.8-cd-dvd.sql was already applied before user-linked incoming media. */
ALTER TABLE cd_dvd_records
    ADD COLUMN brought_by_user_id INT UNSIGNED NULL AFTER brought_by,
    ADD INDEX idx_cd_dvd_brought_by_user (brought_by_user_id),
    ADD CONSTRAINT fk_cd_dvd_brought_by_user FOREIGN KEY (brought_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
