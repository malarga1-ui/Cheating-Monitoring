-- ============================================================
-- Migration v24: AI Content Detection (RapidAPI Failover)
-- ============================================================
-- Run BEFORE deploying PHP code changes.
-- Safe to run multiple times (idempotent).
--
-- Changes:
--   1. Ensure ai_score column exists on answer_records
--   2. Add ai_detection_provider column for audit trail
--   3. Update risk_indicators weights (50/20/15/15)
--   4. Add performance indexes
-- ============================================================

-- 1. Ensure ai_score column exists on answer_records
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'answer_records'
      AND COLUMN_NAME  = 'ai_score'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE answer_records ADD COLUMN ai_score SMALLINT NOT NULL DEFAULT 0 COMMENT ''Per-question AI detection score (0-100)'' AFTER change_count',
    'SELECT ''ai_score column already exists'' AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add ai_detection_provider column for audit trail
SET @col_exists2 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'answer_records'
      AND COLUMN_NAME  = 'ai_detection_provider'
);

SET @sql2 = IF(
    @col_exists2 = 0,
    'ALTER TABLE answer_records ADD COLUMN ai_detection_provider VARCHAR(32) NOT NULL DEFAULT '''' COMMENT ''Which RapidAPI provider succeeded (Provider_1..4)'' AFTER ai_score',
    'SELECT ''ai_detection_provider column already exists'' AS status'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Add ai_detection_status column
SET @col_exists3 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'answer_records'
      AND COLUMN_NAME  = 'ai_detection_status'
);

SET @sql3 = IF(
    @col_exists3 = 0,
    'ALTER TABLE answer_records ADD COLUMN ai_detection_status VARCHAR(16) NOT NULL DEFAULT '''' COMMENT ''SUCCESS/SKIPPED/FAILED/CONFIG_ERROR'' AFTER ai_detection_provider',
    'SELECT ''ai_detection_status column already exists'' AS status'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 4. Add ai_detection_detected_at timestamp
SET @col_exists4 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'answer_records'
      AND COLUMN_NAME  = 'ai_detected_at'
);

SET @sql4 = IF(
    @col_exists4 = 0,
    'ALTER TABLE answer_records ADD COLUMN ai_detected_at DATETIME(3) NULL COMMENT ''When AI detection was performed'' AFTER ai_detection_status',
    'SELECT ''ai_detected_at column already exists'' AS status'
);
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- 5. Update risk_indicators weights: Behavioral=50%, AI=20%, Network=15%, Similarity=15%
UPDATE risk_indicators SET weight_percent = 50.00 WHERE indicator_key IN (
    'devtools_count', 'screenshot_count', 'suspicious_key_count', 'rapid_answer_changes',
    'paste_count', 'tab_hidden_count', 'page_leave_count', 'copy_count',
    'fullscreen_exit_count', 'answer_speed_ratio', 'blur_count', 'offline_count',
    'copy_selection_chars', 'idle_count', 'right_click_count', 'tab_hidden_duration_ms',
    'typing_backspace_count', 'mouse_move_count', 'mouse_scroll_count',
    'idle_duration_ms', 'typing_keydown_count', 'answer_changed_count', 'other_count'
) AND category = 'behavioral';

UPDATE risk_indicators SET weight_percent = 20.00 WHERE indicator_key IN (
    'ai_suspect_score', 'answer_text_count', 'typing_answer_ratio'
) AND category = 'ai';

UPDATE risk_indicators SET weight_percent = 15.00 WHERE indicator_key IN (
    'same_ip_student_count', 'ip_changed_count', 'same_ip_risk_score'
) AND category = 'network';

UPDATE risk_indicators SET weight_percent = 15.00 WHERE indicator_key IN (
    'similarity_max_score', 'similarity_match_count'
) AND category = 'similarity';

-- 6. Add performance index for AI detection queries
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'answer_records'
      AND INDEX_NAME   = 'idx_answer_ai_pending'
);

SET @sql6 = IF(
    @idx_exists = 0,
    'CREATE INDEX idx_answer_ai_pending ON answer_records (account_id, session_id, ai_score, word_count)',
    'SELECT ''idx_answer_ai_pending already exists'' AS status'
);
PREPARE stmt6 FROM @sql6;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

-- 7. Ensure answer_records table exists (safety net)
CREATE TABLE IF NOT EXISTS answer_records (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id        INT UNSIGNED NOT NULL DEFAULT 0,
    session_id        VARCHAR(64) NOT NULL,
    student_id        INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id           INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_quiz_id    INT UNSIGNED NOT NULL DEFAULT 0,
    question_id       VARCHAR(128) NOT NULL DEFAULT '',
    question_type     VARCHAR(64) NOT NULL DEFAULT '',
    answer_text       TEXT NOT NULL,
    answer_length     INT UNSIGNED NOT NULL DEFAULT 0,
    word_count        INT UNSIGNED NOT NULL DEFAULT 0,
    typing_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    change_count      INT UNSIGNED NOT NULL DEFAULT 1,
    ai_score          SMALLINT NOT NULL DEFAULT 0 COMMENT 'Per-question AI detection score (0-100)',
    ai_detection_provider VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'Which RapidAPI provider succeeded',
    ai_detection_status VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'SUCCESS/SKIPPED/FAILED/CONFIG_ERROR',
    ai_detected_at    DATETIME(3) NULL COMMENT 'When AI detection was performed',
    paste_text        TEXT NULL COMMENT 'Text that was pasted into this answer field',
    paste_length      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Character count of pasted text',
    copy_count_from_question INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of copy events from this question area',
    copy_text         TEXT NULL COMMENT 'Text that was copied from this question area',
    created_at        DATETIME(3) NOT NULL,
    UNIQUE KEY uq_answer_records_event (session_id, question_id),
    KEY idx_answer_records_exam (account_id, exam_id),
    KEY idx_answer_records_student (account_id, student_id),
    KEY idx_answer_records_session (account_id, session_id),
    KEY idx_answer_ai_pending (account_id, session_id, ai_score, word_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Done
SELECT 'Migration v24 completed successfully' AS status;
