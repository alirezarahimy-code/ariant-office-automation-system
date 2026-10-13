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
