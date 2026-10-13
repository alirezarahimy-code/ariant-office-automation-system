-- 1.27 Food ticket fixes: align printer_mode with ENUM and clean invalid values.
-- Safe to run more than once.

-- Normalize legacy windows_queue (and any other invalid value) to allowed ENUM values.
UPDATE food_ticket_config
SET printer_mode = 'windows_share'
WHERE printer_mode IS NULL
   OR printer_mode = ''
   OR printer_mode NOT IN ('tcp_raw', 'windows_share');

-- Prefer tcp_raw when host is set and share is empty (typical network receipt printer).
UPDATE food_ticket_config
SET printer_mode = 'tcp_raw'
WHERE printer_mode = 'windows_share'
  AND TRIM(IFNULL(printer_host, '')) <> ''
  AND TRIM(IFNULL(printer_share, '')) = '';
