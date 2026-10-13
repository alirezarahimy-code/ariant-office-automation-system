-- کارمندان ثبت‌شده از بخش غذا (فرم کارمندان یا ایمپورت) حساب ورود نیستند.
-- قبلاً auth_source = local می‌گرفتند و در فهرست کاربران سامانه ظاهر می‌شدند.
-- از این پس auth_source = food می‌گیرند و در فهرست کاربران دیده نمی‌شوند.
-- (ردیف در جدول users می‌ماند تا سفارش‌های غذا به آن وصل باشند.)

ALTER TABLE users MODIFY auth_source ENUM('local','ldap','food') NOT NULL DEFAULT 'local';

-- ردیف‌های قبلی: فقط کارمندان غذا (بدون رمز و با نام کاربری food_employee_…)
UPDATE users
SET auth_source = 'food'
WHERE auth_source = 'local'
  AND password_hash IS NULL
  AND username LIKE 'food\_employee\_%';
