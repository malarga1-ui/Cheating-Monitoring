-- ============================================================
-- Migration: Add FOREIGN KEY constraints + indexes
-- Execute AFTER schema.sql on existing databases
-- ============================================================

SET NAMES utf8mb4;

-- Add FOREIGN KEY constraints (skip if already exist)
-- Note: We check before adding to avoid errors on re-run

-- 1. courses.account_id -> accounts.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND CONSTRAINT_NAME = 'fk_courses_account');
SET @sql = IF(@exists = 0, 'ALTER TABLE courses ADD CONSTRAINT fk_courses_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. exams.account_id -> accounts.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND CONSTRAINT_NAME = 'fk_exams_account');
SET @sql = IF(@exists = 0, 'ALTER TABLE exams ADD CONSTRAINT fk_exams_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. students.account_id -> accounts.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND CONSTRAINT_NAME = 'fk_students_account');
SET @sql = IF(@exists = 0, 'ALTER TABLE students ADD CONSTRAINT fk_students_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. sessions.student_id -> students.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND CONSTRAINT_NAME = 'fk_sessions_student');
SET @sql = IF(@exists = 0, 'ALTER TABLE sessions ADD CONSTRAINT fk_sessions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. sessions.exam_id -> exams.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND CONSTRAINT_NAME = 'fk_sessions_exam');
SET @sql = IF(@exists = 0, 'ALTER TABLE sessions ADD CONSTRAINT fk_sessions_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. session_summaries.student_id -> students.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'session_summaries' AND CONSTRAINT_NAME = 'fk_summaries_student');
SET @sql = IF(@exists = 0, 'ALTER TABLE session_summaries ADD CONSTRAINT fk_summaries_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. session_summaries.exam_id -> exams.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'session_summaries' AND CONSTRAINT_NAME = 'fk_summaries_exam');
SET @sql = IF(@exists = 0, 'ALTER TABLE session_summaries ADD CONSTRAINT fk_summaries_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. teachers.account_id -> accounts.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND CONSTRAINT_NAME = 'fk_teachers_account');
SET @sql = IF(@exists = 0, 'ALTER TABLE teachers ADD CONSTRAINT fk_teachers_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. course_teachers.moodle_teacher_id -> teachers.moodle_teacher_id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'course_teachers' AND CONSTRAINT_NAME = 'fk_ct_teacher');
SET @sql = IF(@exists = 0, 'ALTER TABLE course_teachers ADD CONSTRAINT fk_ct_teacher FOREIGN KEY (moodle_teacher_id) REFERENCES teachers(moodle_teacher_id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 10. course_access.user_id -> users.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'course_access' AND CONSTRAINT_NAME = 'fk_ca_user');
SET @sql = IF(@exists = 0, 'ALTER TABLE course_access ADD CONSTRAINT fk_ca_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 11. events.account_id -> accounts.id
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND CONSTRAINT_NAME = 'fk_events_account');
SET @sql = IF(@exists = 0, 'ALTER TABLE events ADD CONSTRAINT fk_events_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add missing indexes
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND INDEX_NAME = 'idx_events_received');
SET @sql = IF(@exists = 0, 'ALTER TABLE events ADD INDEX idx_events_received (received_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND INDEX_NAME = 'idx_events_account_type');
SET @sql = IF(@exists = 0, 'ALTER TABLE events ADD INDEX idx_events_account_type (account_id, event_type)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND INDEX_NAME = 'idx_sessions_risk');
SET @sql = IF(@exists = 0, 'ALTER TABLE sessions ADD INDEX idx_sessions_risk (risk_level)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
