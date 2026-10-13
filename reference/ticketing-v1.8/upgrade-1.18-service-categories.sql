-- Upgrade 1.18: دسته‌بندی دقیق‌تر خدمات و اتصال خدمت به دسته (فرم تیکت آبشاری)
-- اجرای این فایل اختیاری است؛ نصب تازه از install.php پر می‌شود.

ALTER TABLE categories ADD COLUMN IF NOT EXISTS code VARCHAR(60) NULL UNIQUE;
ALTER TABLE service_catalog ADD COLUMN IF NOT EXISTS category_id INT UNSIGNED NULL AFTER department_id;
ALTER TABLE service_catalog ADD INDEX IF NOT EXISTS idx_services_category (category_id);

INSERT INTO categories (name, code, service_group)
VALUES
('سخت‌افزار', 'IT-CAT-HW', 'it'),
('نرم‌افزار', 'IT-CAT-SW', 'it'),
('شبکه و اینترنت', 'IT-CAT-NET', 'it'),
('حساب کاربری و دسترسی', 'IT-CAT-ACC', 'it'),
('چاپ و اسکن', 'IT-CAT-PRINT', 'it'),
('تجهیزات جانبی', 'IT-CAT-PERIPH', 'it'),
('پشتیبان‌گیری و بازیابی', 'IT-CAT-BACKUP', 'it'),
('نصب و راه‌اندازی', 'IT-CAT-SETUP', 'it'),
('سایر خدمات فناوری اطلاعات', 'IT-CAT-MISC', 'it'),
('تجهیزات و فضای اداری', 'SUP-CAT-OFFICE', 'support'),
('تأسیسات و انرژی', 'SUP-CAT-UTIL', 'support'),
('اثاثیه', 'SUP-CAT-FURN', 'support'),
('خدمات و نظافت', 'SUP-CAT-CLEAN', 'support'),
('ملزومات اداری', 'SUP-CAT-STAT', 'support'),
('خدمات عمومی', 'SUP-CAT-GEN', 'support'),
('سایر خدمات پشتیبانی', 'SUP-CAT-MISC', 'support')
ON DUPLICATE KEY UPDATE name = VALUES(name), service_group = VALUES(service_group), is_active = 1;

UPDATE service_catalog s JOIN categories c ON c.code = CASE s.code
    WHEN 'IT-COMPUTER' THEN 'IT-CAT-HW'
    WHEN 'IT-BOOT' THEN 'IT-CAT-HW'
    WHEN 'IT-BLUE' THEN 'IT-CAT-HW'
    WHEN 'IT-HARDWARE' THEN 'IT-CAT-HW'
    WHEN 'IT-PROFILE' THEN 'IT-CAT-SETUP'
    WHEN 'IT-DRIVER' THEN 'IT-CAT-SETUP'
    WHEN 'IT-SOFTWARE' THEN 'IT-CAT-SW'
    WHEN 'IT-SOFT-ERROR' THEN 'IT-CAT-SW'
    WHEN 'IT-PRINTER' THEN 'IT-CAT-PRINT'
    WHEN 'IT-PERIPHERAL' THEN 'IT-CAT-PERIPH'
    WHEN 'IT-NET' THEN 'IT-CAT-NET'
    WHEN 'IT-SHARE' THEN 'IT-CAT-NET'
    WHEN 'IT-ACCOUNT' THEN 'IT-CAT-ACC'
    WHEN 'IT-ACCESS' THEN 'IT-CAT-ACC'
    WHEN 'IT-EMAIL' THEN 'IT-CAT-ACC'
    WHEN 'IT-BACKUP' THEN 'IT-CAT-BACKUP'
    WHEN 'IT-INSTALL-PC' THEN 'IT-CAT-SETUP'
    WHEN 'IT-MOVE' THEN 'IT-CAT-PERIPH'
    WHEN 'IT-INVENTORY' THEN 'IT-CAT-SETUP'
    WHEN 'IT-OTHER' THEN 'IT-CAT-MISC'
    WHEN 'SUP-OFFICE' THEN 'SUP-CAT-OFFICE'
    WHEN 'SUP-ELEC' THEN 'SUP-CAT-UTIL'
    WHEN 'SUP-COOLING' THEN 'SUP-CAT-UTIL'
    WHEN 'SUP-FURN' THEN 'SUP-CAT-FURN'
    WHEN 'SUP-CLEAN' THEN 'SUP-CAT-CLEAN'
    WHEN 'SUP-STATIONERY' THEN 'SUP-CAT-STAT'
    WHEN 'SUP-CDDVD' THEN 'SUP-CAT-GEN'
    WHEN 'SUP-GENERAL' THEN 'SUP-CAT-GEN'
    WHEN 'SUP-OTHER' THEN 'SUP-CAT-MISC'
    ELSE NULL END
SET s.category_id = c.id
WHERE c.id IS NOT NULL;
