-- ============================================================================
--  ارتقا به ۱.۳۳ — «وضعیت واقعی حذف ردیف منبع (SOURCE_TABLE)»
--  سامانه چاپ فیش غذا — ۱۴۰۵/۰۷/۱۴
--
--  چرا لازم است؟
--    تا پیش از این، هیچ ستونی ثبت نمی‌کرد که ردیف SOURCE_TABLE پس از پایش/چاپ واقعاً
--    از فایل Access پاک شده است یا نه. در نتیجه وقتی شرط حذف با ۰ ردیف تطبیق
--    می‌کرد (مثلاً ستون تاریخ به‌شکل رشته مقایسه می‌شد)، سامانه «موفق» گزارش
--    می‌کرد و ردیف‌ها در SOURCE_TABLE انبار می‌شدند.
--
--  اجرای دستور:
--    mysql -u <user> -p <database> < upgrade-1.33-source-delete-truth.sql
--    (یا از phpMyAdmin → Import)
--    ⚠️ اجرای دوبارهٔ این فایل خطای «Duplicate column» می‌دهد؛ طبیعی است.
--       کد PHP این ستون‌ها را در اولین پردازش «خودش» هم می‌سازد (ensure محافظت‌شده)،
--       پس اجرای دستی الزامی نیست — اما برای گزارش‌گیری دقیق توصیه می‌شود.
-- ============================================================================

ALTER TABLE food_ticket_events
    ADD COLUMN source_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER delivered_by,
    ADD COLUMN source_deleted_at DATETIME NULL AFTER source_deleted,
    ADD COLUMN source_delete_note VARCHAR(255) NULL AFTER source_deleted_at;

CREATE INDEX idx_food_events_src_deleted ON food_ticket_events (source_deleted, punch_date);

-- ============================================================================
--  گزارش‌های پیشنهادی پس از اجرا
-- ============================================================================
-- ۱) چند فیش امروز، منبع‌شان واقعاً پاک شده؟
--    SELECT source_deleted, COUNT(*) FROM food_ticket_events
--     WHERE punch_date = CURDATE() GROUP BY source_deleted;
--
-- ۲) ردیف‌هایی که حذف‌شان ناموفق بوده و باید بررسی شوند:
--    SELECT id, punch_date, punch_time, source_uid, event_type, source_delete_note
--      FROM food_ticket_events
--     WHERE source_deleted = 0 AND source_delete_note IS NOT NULL
--     ORDER BY id DESC LIMIT 50;
--
-- ============================================================================
--  Rollback (در صورت نیاز به بازگشت):
-- ============================================================================
--  DROP INDEX idx_food_events_src_deleted ON food_ticket_events;
--  ALTER TABLE food_ticket_events
--      DROP COLUMN source_delete_note, DROP COLUMN source_deleted_at, DROP COLUMN source_deleted;
-- ============================================================================
