-- 1.19 Food ticket durable print queue + print log
ALTER TABLE food_ticket_events
  MODIFY print_status ENUM('not_printed','pending','printing','printed','print_error','failed') NOT NULL DEFAULT 'not_printed';

-- Columns (ignore error if already exist when re-run manually)
-- print_job_id, retry_count, next_retry_at, printer_name

SET @db := DATABASE();
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='food_ticket_events' AND COLUMN_NAME='print_job_id')=0,
    'ALTER TABLE food_ticket_events ADD COLUMN print_job_id VARCHAR(64) NULL AFTER ticket_key',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='food_ticket_events' AND COLUMN_NAME='retry_count')=0,
    'ALTER TABLE food_ticket_events ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER print_attempts',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='food_ticket_events' AND COLUMN_NAME='next_retry_at')=0,
    'ALTER TABLE food_ticket_events ADD COLUMN next_retry_at DATETIME NULL AFTER processed_at',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='food_ticket_events' AND COLUMN_NAME='printer_name')=0,
    'ALTER TABLE food_ticket_events ADD COLUMN printer_name VARCHAR(190) NULL AFTER last_error',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

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
