-- Upgrade 1.15: بخش «پلاک‌ها و پلمپ» و حذف فیلدهای زائد از فرم شناسنامه
-- توجه: برنامه این ستون‌ها را خودکار هم می‌سازد؛ اجرای این فایل اختیاری است.
-- ستون‌های حذف‌شده از فرم (org_name، part_name، user_name، work_group، city،
-- monitor_serial، printer_type، printer_serial، scanner_serial، plaque_type،
-- plaque_serial، asset_number) در دیتابیس باقی می‌مانند و دادهٔ قبلی حفظ می‌شود.

ALTER TABLE asset_profiles
    ADD COLUMN IF NOT EXISTS plaque_monitor VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS plaque_printer VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS plaque_printer2 VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS plaque_scanner VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS case_seal TEXT NULL;
