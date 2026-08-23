-- v10 fix: Add missing columns to teachers table
-- Run AFTER migration_v10_student_answers.sql

ALTER TABLE teachers
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NOT NULL DEFAULT ''
    AFTER username;

ALTER TABLE teachers
    ADD COLUMN IF NOT EXISTS is_first_login TINYINT(1) NOT NULL DEFAULT 1
    AFTER password_hash;

ALTER TABLE teachers
    ADD COLUMN IF NOT EXISTS login_enabled TINYINT(1) NOT NULL DEFAULT 1
    AFTER is_first_login;
