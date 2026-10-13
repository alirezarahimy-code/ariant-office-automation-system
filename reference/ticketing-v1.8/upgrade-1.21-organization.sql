-- upgrade 1.21: organizational tree (units + managers + direct manager per user)
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

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS org_unit_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS manager_user_id INT UNSIGNED NULL;

-- root: CEO unit
INSERT INTO org_units (name, code, parent_id, unit_type, is_active)
SELECT 'مدیرعامل', 'CEO', NULL, 'ceo', 1
WHERE NOT EXISTS (SELECT 1 FROM org_units WHERE unit_type = 'ceo');

-- existing departments become deputy units under the CEO root
INSERT INTO org_units (name, code, parent_id, unit_type, is_active)
SELECT d.name, d.code, (SELECT id FROM org_units WHERE unit_type = 'ceo' ORDER BY id LIMIT 1), 'deputy', 1
FROM departments d
WHERE NOT EXISTS (
    SELECT 1 FROM org_units ou WHERE ou.name = d.name AND ou.unit_type IN ('deputy', 'department')
);

-- map existing user department membership to org units
UPDATE users u
JOIN departments d ON d.id = u.department_id
SET u.org_unit_id = (
    SELECT ou.id FROM org_units ou WHERE ou.name = d.name AND ou.unit_type IN ('deputy', 'department') ORDER BY ou.id LIMIT 1
)
WHERE u.org_unit_id IS NULL;
