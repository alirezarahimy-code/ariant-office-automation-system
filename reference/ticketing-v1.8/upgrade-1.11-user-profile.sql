/* Run once after the existing migrations to enable self-service user profiles. */
ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER full_name;
ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name;
ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER last_name;
