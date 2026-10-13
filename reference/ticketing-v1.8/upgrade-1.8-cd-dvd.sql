/* Run once after upgrade-1.8-routing.sql on an existing installation. */
CREATE TABLE IF NOT EXISTS cd_dvd_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    is_default TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cd_dvd_types (name, is_default) VALUES ('عادی', 1), ('محرمانه', 1), ('خیلی محرمانه', 1);

CREATE TABLE IF NOT EXISTS cd_dvd_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media ENUM('CD','DVD') NOT NULL,
    direction ENUM('IN','OUT') NOT NULL,
    serial VARCHAR(20) NOT NULL,
    info_type VARCHAR(80) NOT NULL,
    info_desc TEXT NOT NULL,
    jy SMALLINT UNSIGNED NOT NULL,
    jm TINYINT UNSIGNED NOT NULL,
    jd TINYINT UNSIGNED NOT NULL,
    date_str VARCHAR(20) NOT NULL,
    sort_key INT UNSIGNED NOT NULL,
    sender VARCHAR(190) NULL,
    sender_unit VARCHAR(190) NULL,
    receiver VARCHAR(190) NULL,
    receiver_unit VARCHAR(190) NULL,
    brought_by VARCHAR(190) NULL,
    brought_by_user_id INT UNSIGNED NULL,
    exit_sheet VARCHAR(100) NULL,
    note TEXT NULL,
    department_id INT UNSIGNED NULL,
    recorder_id INT UNSIGNED NOT NULL,
    recorder_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cd_dvd_serial (serial, media, sort_key, id),
    INDEX idx_cd_dvd_department (department_id),
    INDEX idx_cd_dvd_recorder (recorder_id),
    INDEX idx_cd_dvd_brought_by_user (brought_by_user_id),
    INDEX idx_cd_dvd_direction (direction),
    CONSTRAINT fk_cd_dvd_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_cd_dvd_recorder FOREIGN KEY (recorder_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cd_dvd_brought_by_user FOREIGN KEY (brought_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
