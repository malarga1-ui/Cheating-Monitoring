-- Migration v12: Fix missing detected_at columns + moodle_ws_service
-- These columns are queried by TeacherPortalController but were missing from schema.

-- Add detected_at to network_groups
ALTER TABLE network_groups
  ADD COLUMN IF NOT EXISTS detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER risk_level;

-- Add detected_at and question_details to similarity_pairs
ALTER TABLE similarity_pairs
  ADD COLUMN IF NOT EXISTS detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER total_questions,
  ADD COLUMN IF NOT EXISTS question_details MEDIUMTEXT NULL AFTER total_questions;

-- Add similarity columns to answer_records
ALTER TABLE answer_records
  ADD COLUMN IF NOT EXISTS similarity_score SMALLINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS similarity_with_student_id INT UNSIGNED NOT NULL DEFAULT 0;

-- Add moodle_ws_service to accounts (for Moodle WS token verification)
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS moodle_ws_service VARCHAR(128) NULL DEFAULT NULL AFTER moodle_url;
