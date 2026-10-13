-- 1.31 قالب‌های فیش غذا (حداکثر ۴ قالب؛ قالب فعال هنگام چاپ از همین جدول خوانده می‌شود)
CREATE TABLE IF NOT EXISTS food_ticket_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    paper_width DECIMAL(6,1) NOT NULL DEFAULT 80.0,      -- میلی‌متر
    paper_height DECIMAL(6,1) NOT NULL DEFAULT 0.0,      -- میلی‌متر؛ 0 = طول متغیر
    template_json LONGTEXT NOT NULL,                     -- المان‌ها: field,x,y,width,height,font_family,font_size,bold,align,line_height,...
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_food_tpl_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- لوگوی اختصاصی فیش در جدول settings با کلید food_ticket_logo ذخیره می‌شود (نیازی به ستون جدید نیست).
