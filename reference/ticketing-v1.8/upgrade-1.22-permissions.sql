-- upgrade 1.22: role-based permissions
-- new roles: primary_admin (replaces admin+is_primary_admin), support_manager, inspector

ALTER TABLE users
    MODIFY COLUMN role ENUM('user','agent','manager','supervisor','admin','primary_admin','support_manager','inspector') NOT NULL DEFAULT 'user';

-- migrate existing primary admins to the explicit role
UPDATE users SET role = 'primary_admin' WHERE role = 'admin' AND is_primary_admin = 1;

CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(40) NOT NULL,
    permission VARCHAR(80) NOT NULL,
    PRIMARY KEY (role, permission),
    INDEX idx_role_perm_permission (permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
