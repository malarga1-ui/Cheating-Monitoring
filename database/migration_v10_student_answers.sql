-- v10: Student answers, AI per-question, speed detection, duration
-- Run AFTER migration_v9_advanced_analytics.sql

-- Add duration_minutes to exams
ALTER TABLE exams
    ADD COLUMN IF NOT EXISTS duration_minutes INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Time limit from Moodle in minutes (0 = unlimited)' AFTER teacher_name;

-- Add ai_score to answer_records
ALTER TABLE answer_records
    ADD COLUMN IF NOT EXISTS ai_score SMALLINT NOT NULL DEFAULT 0
    COMMENT 'Per-question AI detection score (0-100)' AFTER change_count;

-- Add answer_speed_ratio to session_summaries
ALTER TABLE session_summaries
    ADD COLUMN IF NOT EXISTS answer_speed_ratio DECIMAL(5,2) NOT NULL DEFAULT 0
    COMMENT 'Ratio of actual exam time to allowed time (lower = faster, suspicious)' AFTER typing_answer_ratio;
