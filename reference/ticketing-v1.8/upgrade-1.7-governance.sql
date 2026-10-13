/* Run once after upgrade-1.6-hardening.sql for existing installations. */
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
    priority ENUM('normal','urgent','instant','critical') NOT NULL DEFAULT 'normal',
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
