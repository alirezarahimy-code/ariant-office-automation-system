-- Persisted employee and guest-card management for the food-ticket worker.

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
