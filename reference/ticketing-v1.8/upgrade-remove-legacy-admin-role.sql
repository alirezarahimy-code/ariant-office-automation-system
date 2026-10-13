-- حذف نقش قدیمی «admin» (ادمین (نقش قدیمی)) از فهرست نقش‌ها.
-- ادمین‌های اصلی به «primary_admin» تبدیل می‌شوند (سطح دسترسی همان است).
-- سایر ردیف‌های admin به «supervisor» می‌روند؛ قبل از اجرا تعدادشان را بررسی کنید:
--   SELECT id, username, is_primary_admin FROM users WHERE role = 'admin';

UPDATE users SET role = 'primary_admin', is_primary_admin = 1
WHERE role = 'admin' AND is_primary_admin = 1;

UPDATE users SET role = 'supervisor'
WHERE role = 'admin' AND is_primary_admin = 0;
