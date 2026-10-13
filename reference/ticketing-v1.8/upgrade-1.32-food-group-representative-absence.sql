-- ============================================================================
--  1.32 — نمایندهٔ گروه با L_UID + غیبت روزانهٔ گروه + تحویل غذا
-- ============================================================================
--  اجرا: یک‌بار روی دیتابیس سامانه (بعد از upgrade-1.31-food-ticket-templates.sql).
--  همهٔ تغییرات افزایشی (additive) هستند و هیچ رکورد موجودی حذف/بازنویسی نمی‌شود.
--  همین تغییرات در اولین اجرا توسط کد PHP هم به‌صورت محافظت‌شده اعمال می‌شوند
--  (food_ticket_groups_ensure / food_ticket_absence_ensure)، پس اجرای دستی این فایل
--  برای محیط‌هایProduction توصیه می‌شود ولی اجباری نیست.
-- ============================================================================

-- ۱) شناسهٔ نمایندهٔ گروه = L_UID
--    کارت RFID (rfid_card) از منطق «شناسایی نماینده» حذف می‌شود و فقط برای
--    سازگاری با گروه‌های قدیمی (لگاسی) باقی می‌ماند؛ بنابراین NULL-پذیر می‌شود.
ALTER TABLE food_ticket_groups
    ADD COLUMN l_uid VARCHAR(80) NULL AFTER title,
    ADD UNIQUE KEY uq_food_group_uid (l_uid),
    MODIFY COLUMN rfid_card VARCHAR(120) NULL;
-- نکته: در MySQL چند مقدار NULL در یک کلید UNIQUE مجاز است؛ پس چند گروه بدون کارت
--       با هم تعارضی ندارند. برای گروه‌های موجود، مقدار l_uid را از پنل وارد کنید.

-- ۲) نوع «ماشه»ی اجرا (L_UID یا کارت لگاسی) + شمارندهٔ غایب‌ها در خلاصهٔ اجرا
ALTER TABLE food_ticket_group_runs
    ADD COLUMN trigger_kind ENUM('uid','card','legacy_card') NOT NULL DEFAULT 'card' AFTER group_title,
    ADD COLUMN trigger_uid VARCHAR(80) NULL AFTER trigger_kind,
    ADD COLUMN absent_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER no_food_count;

-- ۳) غیبت روزانه — «غیبت» به‌صورت Exception ذخیره می‌شود، نه حضور.
--    نبودِ رکورد برای (تاریخ، کاربر) یعنی «حاضر». هیچ Reset روزانه‌ای لازم نیست.
CREATE TABLE IF NOT EXISTS food_ticket_daily_absence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    absence_date DATE NOT NULL,
    group_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    reason VARCHAR(300) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_food_absence_day_user (absence_date, user_id),
    INDEX idx_food_absence_group_day (group_id, absence_date),
    CONSTRAINT fk_food_absence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_food_absence_group FOREIGN KEY (group_id) REFERENCES food_ticket_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۳.۵) تاریخچهٔ L_UID نماینده‌ها — برای تشخیص «تردد با L_UID ناشناخته»
--      (اگر L_UID گروهی عوض/حذف شود و نماینده با شناسهٔ قبلی بزند، این جدول به ما می‌گوید
--      که این شناسه قبلاً نماینده بوده و باید به‌عنوان تردد ناشناخته ثبت/هشدار شود.)
CREATE TABLE IF NOT EXISTS food_ticket_group_uid_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(80) NOT NULL,
    group_id INT UNSIGNED NULL,
    action ENUM('set','changed','removed') NOT NULL,
    actor_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_food_uid_history_uid (uid),
    INDEX idx_food_uid_history_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۳.۶) وضعیت «متوقف به‌خاطر اعلام غیبت»: اگر بعد از صدور فیش، عضو «غایب» اعلام شود،
--      فیش چاپ‌نشده‌اش از صف چاپ خارج می‌شود (held_absent) و اگر دوباره حاضر شود،
--      خودکار به صف برمی‌گردد. فیش چاپ‌شده هرگز تغییر نمی‌کند.
ALTER TABLE food_ticket_events
    MODIFY COLUMN print_status ENUM('not_printed','pending','printing','printed','print_error','failed','held_absent') NOT NULL DEFAULT 'not_printed';

-- ۴) وضعیت تحویل غذا، مستقل از وضعیت چاپ
--    print_status (چاپ) و delivery_status (تحویل) دو مفهوم جدا هستند؛
--    «چاپ موفق» به‌خودی‌خود به معنی «تحویل قطعی» نیست.
ALTER TABLE food_ticket_events
    ADD COLUMN delivery_status ENUM('pending','delivered') NOT NULL DEFAULT 'pending' AFTER print_status,
    ADD COLUMN delivered_at DATETIME NULL AFTER delivery_status,
    ADD COLUMN delivered_by INT UNSIGNED NULL AFTER delivered_at;

-- ============================================================================
--  Rollback (در صورت نیاز به بازگشت) — به‌ترتیب معکوس اجرا شود:
-- ============================================================================
--  ALTER TABLE food_ticket_events
--      MODIFY COLUMN print_status ENUM('not_printed','pending','printing','printed','print_error','failed') NOT NULL DEFAULT 'not_printed';
--      -- ⚠️ پیش از این Rollback، مقدارهای held_absent را به pending برگردانید:
--      -- UPDATE food_ticket_events SET print_status='pending' WHERE print_status='held_absent';
--  ALTER TABLE food_ticket_events
--      DROP COLUMN delivered_by, DROP COLUMN delivered_at, DROP COLUMN delivery_status;
--  DROP TABLE IF EXISTS food_ticket_group_uid_history;
--  DROP TABLE IF EXISTS food_ticket_daily_absence;
--  ALTER TABLE food_ticket_group_runs
--      DROP COLUMN absent_count, DROP COLUMN trigger_uid, DROP COLUMN trigger_kind;
--  ALTER TABLE food_ticket_groups
--      DROP INDEX uq_food_group_uid, DROP COLUMN l_uid,
--      MODIFY COLUMN rfid_card VARCHAR(120) NOT NULL;
--  -- ⚠️ پیش از NOT NULL کردن دوبارهٔ rfid_card مطمئن شوید هیچ گروه بدون کارتی وجود ندارد.
-- ============================================================================
