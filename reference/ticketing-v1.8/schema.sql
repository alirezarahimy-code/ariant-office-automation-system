CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    code VARCHAR(40) NULL UNIQUE,
    manager_user_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_departments_manager (manager_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(60) NULL,
    parent_id INT UNSIGNED NULL,
    unit_type VARCHAR(20) NOT NULL DEFAULT 'department',
    manager_user_id INT UNSIGNED NULL,
    is_primary_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_parent (parent_id),
    INDEX idx_org_type (unit_type),
    INDEX idx_org_manager (manager_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_unit_managers (
    unit_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (unit_id, user_id),
    INDEX idx_org_mgr_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    full_name VARCHAR(190) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    profile_photo VARCHAR(255) NULL,
    email VARCHAR(190) NULL,
    employee_number VARCHAR(100) NULL,
    UNIQUE KEY uq_users_employee_number (employee_number),
    national_code VARCHAR(30) NULL,
    phone VARCHAR(80) NULL,
    department VARCHAR(190) NULL,
    department_id INT UNSIGNED NULL,
    requesting_unit_id INT UNSIGNED NULL,
    org_unit_id INT UNSIGNED NULL,
    manager_user_id INT UNSIGNED NULL,
    handling_unit_id INT UNSIGNED NULL,
    is_it_agent TINYINT(1) NOT NULL DEFAULT 0,
    is_primary_admin TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('user','agent','manager','supervisor','admin','primary_admin','support_manager','inspector') NOT NULL DEFAULT 'user',
    auth_source ENUM('local','ldap','food') NOT NULL DEFAULT 'local',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_department (department),
    INDEX idx_users_department_id (department_id),
    INDEX idx_users_national_code (national_code),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE departments ADD CONSTRAINT fk_departments_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS handling_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code ENUM('it','support') NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    manager_user_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_handling_manager (manager_user_id),
    CONSTRAINT fk_handling_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD CONSTRAINT fk_users_handling_unit FOREIGN KEY (handling_unit_id) REFERENCES handling_units(id) ON DELETE SET NULL;

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

CREATE TABLE IF NOT EXISTS traffic_destinations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_traffic_destination (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS traffic_visits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    serial_no VARCHAR(30) NOT NULL,
    visit_date DATE NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    national_code VARCHAR(10) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    company VARCHAR(190) DEFAULT NULL,
    entry_time TIME DEFAULT NULL,
    exit_time TIME DEFAULT NULL,
    no_visit TINYINT(1) NOT NULL DEFAULT 0,
    with_car TINYINT(1) NOT NULL DEFAULT 0,
    with_mobile TINYINT(1) NOT NULL DEFAULT 0,
    meeting_with VARCHAR(190) DEFAULT NULL,
    approved_by VARCHAR(190) DEFAULT NULL,
    description TEXT,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_traffic_national_code (national_code),
    KEY idx_traffic_visit_date (visit_date),
    KEY idx_traffic_serial (serial_no),
    KEY idx_traffic_created_by (created_by),
    CONSTRAINT fk_traffic_visits_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO traffic_destinations (title)
SELECT * FROM (SELECT 'مدیرعامل' AS title
    UNION ALL SELECT 'مدیریت اداری'
    UNION ALL SELECT 'فاوا'
    UNION ALL SELECT 'معاونت طرح و برنامه'
    UNION ALL SELECT 'دفتر مدیرعامل'
    UNION ALL SELECT 'مدیریت پشتیبانی'
    UNION ALL SELECT 'معاونت الکترونیک') AS seed
WHERE NOT EXISTS (SELECT 1 FROM traffic_destinations);

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(60) NULL UNIQUE,
    service_group ENUM('it','support') NOT NULL DEFAULT 'it',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(60) NOT NULL UNIQUE,
    description TEXT NULL,
    service_group ENUM('it','support') NOT NULL DEFAULT 'it',
    default_priority ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal',
    department_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    handling_unit_id INT UNSIGNED NULL,
    requires_asset TINYINT(1) NOT NULL DEFAULT 1,
    default_ticket_type ENUM('incident','request','problem','change') NOT NULL DEFAULT 'incident',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_services_department (department_id),
    INDEX idx_services_group (service_group, is_active),
    INDEX idx_services_handling (handling_unit_id),
    INDEX idx_services_category (category_id),
    CONSTRAINT fk_services_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_services_handling FOREIGN KEY (handling_unit_id) REFERENCES handling_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
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

CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_no VARCHAR(40) NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category_id INT UNSIGNED NULL,
    service_id INT UNSIGNED NULL,
    ticket_type ENUM('incident','request','problem','change') NOT NULL DEFAULT 'incident',
    parent_ticket_id INT UNSIGNED NULL,
    custom_fields JSON NULL,
    service_group ENUM('it','support') NOT NULL DEFAULT 'it',
    department_id INT UNSIGNED NULL,
    requesting_unit_id INT UNSIGNED NULL,
    asset_id INT UNSIGNED NULL,
    handling_unit_id INT UNSIGNED NULL,
    support_location VARCHAR(255) NULL,
    support_equipment TEXT NULL,
    priority ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal',
    status ENUM('new','manager_review','assigned','in_progress','waiting_user','pending','resolved','closed') NOT NULL DEFAULT 'manager_review',
    requester_id INT UNSIGNED NOT NULL,
    assigned_to INT UNSIGNED NULL,
    assigned_at DATETIME NULL,
    first_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    closed_at DATETIME NULL,
    due_at DATETIME NULL,
    sla_minutes INT UNSIGNED NULL,
    ola_policy_id INT UNSIGNED NULL,
    ola_response_due_at DATETIME NULL,
    ola_due_at DATETIME NULL,
    ola_escalation_1_at DATETIME NULL,
    ola_escalation_2_at DATETIME NULL,
    sla_paused_at DATETIME NULL,
    sla_pause_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    supervisor_id INT UNSIGNED NULL,
    supervisor_approved_at DATETIME NULL,
    supervisor_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tickets_status (status),
    INDEX idx_tickets_requester (requester_id),
    UNIQUE KEY uq_tickets_no (ticket_no),
    INDEX idx_tickets_department (department_id),
    INDEX idx_tickets_service_group (service_group),
    INDEX idx_tickets_handling (handling_unit_id),
    INDEX idx_tickets_asset (asset_id),
    INDEX idx_tickets_service (service_id),
    INDEX idx_tickets_type (ticket_type),
    INDEX idx_tickets_parent (parent_ticket_id),
    INDEX idx_tickets_created (created_at),
    INDEX idx_tickets_status_created (status, created_at),
    INDEX idx_tickets_assignee_status (assigned_to, status),
    INDEX idx_tickets_requester_created (requester_id, created_at),
    INDEX idx_tickets_requesting_unit (requesting_unit_id),
    CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_service FOREIGN KEY (service_id) REFERENCES service_catalog(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_parent FOREIGN KEY (parent_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_handling FOREIGN KEY (handling_unit_id) REFERENCES handling_units(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_ticket (ticket_id),
    CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS ticket_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    mime VARCHAR(150) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    username VARCHAR(190) NULL,
    full_name VARCHAR(190) NULL,
    role VARCHAR(40) NULL,
    action_code VARCHAR(120) NOT NULL,
    action_label VARCHAR(190) NULL,
    module VARCHAR(80) NULL,
    target_type VARCHAR(40) NULL,
    target_id BIGINT UNSIGNED NULL,
    target_label VARCHAR(255) NULL,
    meta_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    computer_name VARCHAR(190) NULL,
    hostname VARCHAR(190) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_action (action_code),
    INDEX idx_activity_module (module),
    INDEX idx_activity_ip (ip_address),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(40) NOT NULL,
    permission VARCHAR(80) NOT NULL,
    PRIMARY KEY (role, permission),
    INDEX idx_role_perm_permission (permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(100) NOT NULL UNIQUE,
    record_number VARCHAR(100) NULL,
    hostname VARCHAR(190) NULL,
    serial_number VARCHAR(190) NULL,
    computer_type VARCHAR(100) NULL,
    manufacturer VARCHAR(190) NULL,
    model VARCHAR(190) NULL,
    asset_number VARCHAR(100) NULL,
    seal_number VARCHAR(100) NULL,
    plaque_number VARCHAR(100) NULL,
    employee_number VARCHAR(100) NULL,
    person_number VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    phone VARCHAR(80) NULL,
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
    peripherals_json JSON NULL,
    domain_username VARCHAR(190) NULL,
    antivirus VARCHAR(255) NULL,
    sound_card VARCHAR(255) NULL,
    network_card VARCHAR(255) NULL,
    os_architecture VARCHAR(40) NULL,
    os_serial VARCHAR(190) NULL,
    os_install_date DATETIME NULL,
    registered_users INT UNSIGNED NULL,
    source ENUM('manual','server_inventory','agent') NOT NULL DEFAULT 'manual',
    lifecycle_status ENUM('planned','in_stock','assigned','in_repair','retired','disposed') NOT NULL DEFAULT 'in_stock',
    purchase_date DATE NULL,
    warranty_until DATE NULL,
    vendor VARCHAR(190) NULL,
    acquisition_cost DECIMAL(15,2) NULL,
    disposal_note TEXT NULL,
    retired_at DATETIME NULL,
    last_inventory_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assets_department (department_id),
    INDEX idx_assets_hostname (hostname),
    CONSTRAINT fk_assets_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_assets_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets ADD CONSTRAINT fk_tickets_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL;

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
    CONSTRAINT fk_graphics_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS asset_profiles (
    asset_id INT UNSIGNED NOT NULL PRIMARY KEY,
    record_number VARCHAR(100) NULL,
    registrar_name VARCHAR(190) NULL,
    registry_date DATETIME NULL,
    unit_name VARCHAR(190) NULL,
    computer_type VARCHAR(100) NULL,
    user_login VARCHAR(190) NULL,
    owner_full_name VARCHAR(190) NULL,
    education VARCHAR(190) NULL,
    phone VARCHAR(80) NULL,
    degree VARCHAR(190) NULL,
    skill_level VARCHAR(190) NULL,
    personnel_number VARCHAR(100) NULL,
    training_skills TEXT NULL,
    secure_info TEXT NULL,
    unit_software TEXT NULL,
    os_name VARCHAR(255) NULL,
    hostname VARCHAR(190) NULL,
    windows_serial VARCHAR(190) NULL,
    os_version VARCHAR(100) NULL,
    os_install_path VARCHAR(255) NULL,
    user_count VARCHAR(40) NULL,
    os_install_date DATETIME NULL,
    disk1_model VARCHAR(190) NULL,
    disk1_capacity VARCHAR(60) NULL,
    disk1_serial VARCHAR(190) NULL,
    disk2_model VARCHAR(190) NULL,
    disk2_capacity VARCHAR(60) NULL,
    disk2_serial VARCHAR(190) NULL,
    motherboard_model VARCHAR(190) NULL,
    motherboard_product VARCHAR(190) NULL,
    motherboard_serial VARCHAR(190) NULL,
    cpu_model VARCHAR(190) NULL,
    cpu_serial VARCHAR(190) NULL,
    cpu_cores VARCHAR(40) NULL,
    ram1 VARCHAR(190) NULL,
    ram2 VARCHAR(190) NULL,
    ram_total VARCHAR(60) NULL,
    gpu1_model VARCHAR(190) NULL,
    gpu1_memory VARCHAR(60) NULL,
    gpu2_model VARCHAR(190) NULL,
    gpu2_memory VARCHAR(60) NULL,
    audio VARCHAR(190) NULL,
    ip_address VARCHAR(100) NULL,
    mac_address VARCHAR(100) NULL,
    netcard VARCHAR(190) NULL,
    net_node VARCHAR(100) NULL,
    connected_internet TINYINT(1) NOT NULL DEFAULT 0,
    connected_network TINYINT(1) NOT NULL DEFAULT 0,
    lock_usb TINYINT(1) NOT NULL DEFAULT 0,
    lock_case TINYINT(1) NOT NULL DEFAULT 0,
    lock_cd_dvd TINYINT(1) NOT NULL DEFAULT 0,
    monitor_model VARCHAR(190) NULL,
    printer_model VARCHAR(190) NULL,
    scanner_model VARCHAR(190) NULL,
    keyboard_model VARCHAR(190) NULL,
    mouse_model VARCHAR(190) NULL,
    barcode_reader VARCHAR(190) NULL,
    antivirus VARCHAR(255) NULL,
    power_model VARCHAR(190) NULL,
    case_model VARCHAR(190) NULL,
    plaque_number VARCHAR(100) NULL,
    plaque_monitor VARCHAR(100) NULL,
    plaque_printer VARCHAR(100) NULL,
    plaque_printer2 VARCHAR(100) NULL,
    plaque_scanner VARCHAR(100) NULL,
    case_seal TEXT NULL,
    ad_dn VARCHAR(255) NULL,
    ad_os VARCHAR(255) NULL,
    ad_os_version VARCHAR(255) NULL,
    ad_last_logon DATETIME NULL,
    ad_last_seen DATETIME NULL,
    ad_scanned_at DATETIME NULL,
    ad_online TINYINT(1) NOT NULL DEFAULT 0,
    ad_description VARCHAR(255) NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_asset_profile_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_profile_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS change_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_number VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    justification TEXT NULL,
    risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    impact TEXT NULL,
    planned_start DATETIME NULL,
    planned_end DATETIME NULL,
    status ENUM('draft','submitted','cab_review','approved','rejected','scheduled','implemented','rolled_back','closed') NOT NULL DEFAULT 'draft',
    department_id INT UNSIGNED NULL,
    requester_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NULL,
    cab_decided_by INT UNSIGNED NULL,
    cab_decided_at DATETIME NULL,
    cab_note TEXT NULL,
    implementation_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_changes_status (status),
    INDEX idx_changes_department (department_id),
    INDEX idx_changes_owner (owner_id),
    CONSTRAINT fk_changes_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_changes_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_changes_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_changes_cab_user FOREIGN KEY (cab_decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_id INT UNSIGNED NOT NULL,
    approver_id INT UNSIGNED NULL,
    decision ENUM('submitted','approved','rejected','scheduled','implemented','rolled_back','closed') NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_change_approvals_change (change_id),
    CONSTRAINT fk_change_approval_change FOREIGN KEY (change_id) REFERENCES change_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_approval_user FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_ticket_links (
    change_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (change_id, ticket_id),
    CONSTRAINT fk_change_link_change FOREIGN KEY (change_id) REFERENCES change_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_link_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_change_link_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS problem_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    problem_number VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    root_cause TEXT NULL,
    workaround TEXT NULL,
    known_error TINYINT(1) NOT NULL DEFAULT 0,
    priority ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal',
    status ENUM('open','investigation','known_error','resolved','closed') NOT NULL DEFAULT 'open',
    department_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_problems_status (status),
    INDEX idx_problems_department (department_id),
    INDEX idx_problems_owner (owner_id),
    CONSTRAINT fk_problems_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_problems_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_problems_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS problem_ticket_links (
    problem_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (problem_id, ticket_id),
    CONSTRAINT fk_problem_link_problem FOREIGN KEY (problem_id) REFERENCES problem_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_problem_link_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_problem_link_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_config (
    id TINYINT UNSIGNED PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    attendance_path VARCHAR(500) NOT NULL DEFAULT '',
    attendance_password_enc TEXT NULL,
    printer_mode ENUM('tcp_raw','windows_share') NOT NULL DEFAULT 'tcp_raw',
    printer_host VARCHAR(255) NOT NULL DEFAULT '',
    printer_port SMALLINT UNSIGNED NOT NULL DEFAULT 9100,
    printer_share VARCHAR(255) NOT NULL DEFAULT '',
    poll_seconds TINYINT UNSIGNED NOT NULL DEFAULT 2,
    max_batch SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    guest_card_uids TEXT NULL,
    max_guest_daily SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    cut_source_rows TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_food_config_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO food_ticket_config (id) VALUES (1);

CREATE TABLE IF NOT EXISTS food_ticket_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key CHAR(64) NOT NULL UNIQUE,
    source_uid VARCHAR(80) NULL,
    source_card VARCHAR(120) NULL,
    punch_date DATE NULL,
    punch_time TIME NULL,
    personnel_code VARCHAR(100) NULL,
    user_id INT UNSIGNED NULL,
    national_code VARCHAR(30) NULL,
    full_name VARCHAR(190) NULL,
    food_type VARCHAR(190) NULL,
    ticket_key VARCHAR(190) NULL UNIQUE,
    print_job_id VARCHAR(64) NULL,
    event_type ENUM('printed','print_error','no_food','unknown','inactive','repeat','guest','guest_limit','config_error') NOT NULL,
    print_status ENUM('not_printed','pending','printing','printed','print_error','failed','held_absent') NOT NULL DEFAULT 'not_printed',
    delivery_status ENUM('pending','delivered') NOT NULL DEFAULT 'pending',
    delivered_at DATETIME NULL,
    delivered_by INT UNSIGNED NULL,
    -- وضعیت واقعی حذف ردیف منبع از SOURCE_TABLE (۱.۳۳): ۱ = ردیف با اطمینان یکتا از SOURCE_TABLE پاک شده است.
    source_deleted TINYINT(1) NOT NULL DEFAULT 0,
    source_deleted_at DATETIME NULL,
    source_delete_note VARCHAR(255) NULL,
    print_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retry_count INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    printer_name VARCHAR(190) NULL,
    source_payload LONGTEXT NULL,
    processed_at DATETIME NULL,
    next_retry_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_food_events_date (punch_date, created_at),
    INDEX idx_food_events_status (print_status, event_type),
    INDEX idx_food_events_user (user_id),
    CONSTRAINT fk_food_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_worker_status (
    id TINYINT UNSIGNED PRIMARY KEY,
    host_name VARCHAR(190) NOT NULL DEFAULT '',
    process_id INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen DATETIME NULL,
    status ENUM('ok','warning','error') NOT NULL DEFAULT 'ok',
    last_message TEXT NULL,
    last_error TEXT NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    printed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO food_ticket_worker_status (id) VALUES (1);

CREATE TABLE IF NOT EXISTS food_ticket_guest_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_uid VARCHAR(120) NOT NULL,
    guest_name VARCHAR(190) NOT NULL DEFAULT 'مهمان',
    daily_limit SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_guest_card_uid (card_uid),
    INDEX idx_food_guest_card_active (is_active),
    CONSTRAINT fk_food_guest_card_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    -- شناسهٔ نمایندهٔ گروه: L_UID دستگاه حضور و غیاب (شناسهٔ اصلی شناسایی)
    l_uid VARCHAR(80) NULL,
    -- کارت RFID فقط برای سازگاری با گروه‌های قدیمی؛ مبنای شناسایی نماینده نیست
    rfid_card VARCHAR(120) NULL,
    description VARCHAR(500) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_group_uid (l_uid),
    UNIQUE KEY uq_food_group_card (rfid_card),
    INDEX idx_food_group_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_group_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_group_member_user (user_id),
    INDEX idx_food_group_member_group (group_id),
    CONSTRAINT fk_food_group_member_group FOREIGN KEY (group_id) REFERENCES food_ticket_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_food_group_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ثبت اجرای کارت گروهی: فقط گزارش و تشخیص تکرارِ خودِ کارت گروهی (جایگزین کنترل «یک فیش در روز» هر کاربر نیست)
CREATE TABLE IF NOT EXISTS food_ticket_group_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NULL,
    group_title VARCHAR(190) NULL,
    rfid_card VARCHAR(120) NOT NULL DEFAULT '',
    trigger_kind ENUM('uid','card','legacy_card') NOT NULL DEFAULT 'card',
    trigger_uid VARCHAR(80) NULL,
    source_key CHAR(64) NOT NULL,
    punch_date DATE NOT NULL,
    punch_time TIME NULL,
    run_kind ENUM('first','repeat','inactive') NOT NULL,
    status ENUM('processing','done') NOT NULL DEFAULT 'done',
    members_total INT UNSIGNED NOT NULL DEFAULT 0,
    printed_count INT UNSIGNED NOT NULL DEFAULT 0,
    repeat_count INT UNSIGNED NOT NULL DEFAULT 0,
    no_food_count INT UNSIGNED NOT NULL DEFAULT 0,
    absent_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_group_run_source (source_key),
    INDEX idx_food_group_run_day (group_id, punch_date, run_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- غیبت روزانه: «غیبت» به‌صورت Exception ذخیره می‌شود (نبودِ رکورد = حاضر).
-- برای هر تاریخ مستقل است؛ هیچ Reset روزانه/Cron لازم نیست.
CREATE TABLE IF NOT EXISTS food_ticket_daily_absence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    absence_date DATE NOT NULL,
    group_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    reason VARCHAR(300) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_absence_day_user (absence_date, user_id),
    INDEX idx_food_absence_group_day (group_id, absence_date),
    CONSTRAINT fk_food_absence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_food_absence_group FOREIGN KEY (group_id) REFERENCES food_ticket_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تاریخچهٔ L_UID نماینده‌ها (تشخیص تردد با شناسهٔ ناشناخته + Audit)
CREATE TABLE IF NOT EXISTS food_ticket_group_uid_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(80) NOT NULL,
    group_id INT UNSIGNED NULL,
    action ENUM('set','changed','removed') NOT NULL,
    actor_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_food_uid_history_uid (uid),
    INDEX idx_food_uid_history_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_print_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_id BIGINT UNSIGNED NULL,
  print_job_id VARCHAR(64) NULL,
  source_key CHAR(64) NULL,
  ticket_key VARCHAR(190) NULL,
  printer VARCHAR(190) NULL,
  status VARCHAR(40) NOT NULL,
  retry_count INT NOT NULL DEFAULT 0,
  message VARCHAR(500) NULL,
  INDEX idx_ftpl_event (event_id),
  INDEX idx_ftpl_job (print_job_id),
  INDEX idx_ftpl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL DEFAULT 'error',
    context VARCHAR(120) NULL,
    message TEXT NULL,
    meta_json JSON NULL,
    user_id INT UNSIGNED NULL,
    request_uri VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_system_logs_created (created_at),
    INDEX idx_system_logs_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_number_seq (
    prefix VARCHAR(20) NOT NULL PRIMARY KEY,
    seq INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Rate Limiting
CREATE TABLE IF NOT EXISTS api_rate_limits (
    rate_key CHAR(64) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_rate_window (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domain scan (Active Directory computers)
CREATE TABLE IF NOT EXISTS domain_scan_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_by INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    total INT NOT NULL DEFAULT 0,
    done INT NOT NULL DEFAULT 0,
    online INT NOT NULL DEFAULT 0,
    offline INT NOT NULL DEFAULT 0,
    failed INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domain_scan_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NULL,
    hostname VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    online TINYINT(1) NOT NULL DEFAULT 0,
    message VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY run_status (run_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional query indexes not already covered by a key or foreign-key index.
CREATE INDEX idx_tickets_status_priority ON tickets(status, priority);
CREATE INDEX idx_users_is_active ON users(is_active);
CREATE INDEX idx_assets_lifecycle_status ON assets(lifecycle_status);
CREATE INDEX idx_ola_priority_active ON ola_policies(priority, is_active);
CREATE INDEX idx_food_ticket_events_event_type ON food_ticket_events(event_type);
CREATE INDEX idx_login_attempts_locked_until ON login_attempts(locked_until);
CREATE INDEX idx_ticket_sla_alerts_alert_type ON ticket_sla_alerts(alert_type);
CREATE INDEX idx_ticket_ola_alerts_alert_type ON ticket_ola_alerts(alert_type);
CREATE INDEX idx_ticket_events_event_type ON ticket_events(event_type);
CREATE INDEX idx_knowledge_articles_is_published ON knowledge_articles(is_published);
CREATE INDEX idx_holidays_is_active ON holidays(is_active);
CREATE INDEX idx_departments_is_active ON departments(is_active);
CREATE INDEX idx_handling_units_is_active ON handling_units(is_active);
CREATE INDEX idx_categories_group_active ON categories(service_group, is_active);
CREATE INDEX idx_service_catalog_group_active ON service_catalog(service_group, is_active);
CREATE INDEX idx_cd_dvd_records_media ON cd_dvd_records(media);
CREATE INDEX idx_traffic_destinations_is_active ON traffic_destinations(is_active);
CREATE INDEX idx_domain_scan_runs_status ON domain_scan_runs(status);
CREATE INDEX idx_domain_scan_queue_status ON domain_scan_queue(status);

-- نگاشت شمارهٔ کارت RFID به کاربر (یادگیری خودکار از ردیف‌های دارای L_UID و C_Card)
CREATE TABLE IF NOT EXISTS food_ticket_card_map (
    card_key VARCHAR(120) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    personnel_code VARCHAR(100) NULL,
    hits INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_food_card_map_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_ticket_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    paper_width DECIMAL(6,1) NOT NULL DEFAULT 80.0,
    paper_height DECIMAL(6,1) NOT NULL DEFAULT 0.0,
    template_json LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_food_tpl_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- برنامه غذایی و سفارش غذای داخلی (پنج جدول جدید، نسخه 1.38)
-- ============================================================================
-- برنامه غذایی و سفارش غذای داخلی — upgrade از 1.37.9
-- فقط پنج جدول جدید ایجاد می‌شوند. هیچ جدول/ستون/دادهٔ موجودی تغییر نمی‌کند.
-- Access فایل/جدول سفارش و Access حضور و غیاب (TENTER) دست‌نخورده باقی می‌مانند.

CREATE TABLE IF NOT EXISTS food_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    food_name VARCHAR(190) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_catalog_name (food_name),
    INDEX idx_food_catalog_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_calendar (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    food_date DATE NOT NULL,
    order_status ENUM('open','closed') NOT NULL DEFAULT 'open',
    note VARCHAR(255) NULL,
    closed_by INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_calendar_date (food_date),
    INDEX idx_food_calendar_status_date (order_status, food_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_calendar_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT UNSIGNED NOT NULL,
    food_id INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_calendar_item (calendar_id, food_id),
    INDEX idx_food_calendar_items_food (food_id),
    INDEX idx_food_calendar_items_active (calendar_id, active),
    CONSTRAINT fk_food_calendar_item_calendar FOREIGN KEY (calendar_id) REFERENCES food_calendar(id) ON DELETE CASCADE,
    CONSTRAINT fk_food_calendar_item_food FOREIGN KEY (food_id) REFERENCES food_catalog(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    calendar_item_id INT UNSIGNED NOT NULL,
    food_date DATE NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_order_employee_date (employee_id, food_date),
    INDEX idx_food_orders_date_status (food_date, status),
    INDEX idx_food_orders_item_status (calendar_item_id, status),
    CONSTRAINT fk_food_order_calendar_item FOREIGN KEY (calendar_item_id) REFERENCES food_calendar_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS food_order_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NULL,
    calendar_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    user_id INT UNSIGNED NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_food_order_logs_order (order_id),
    INDEX idx_food_order_logs_calendar (calendar_id),
    INDEX idx_food_order_logs_created (created_at),
    CONSTRAINT fk_food_order_log_order FOREIGN KEY (order_id) REFERENCES food_orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_food_order_log_calendar FOREIGN KEY (calendar_id) REFERENCES food_calendar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
