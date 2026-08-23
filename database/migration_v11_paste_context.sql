-- Migration v11: Add paste_text and copy_text to answer_records
-- Adds columns to track the actual text that was pasted or copied per question.

ALTER TABLE answer_records
  ADD COLUMN IF NOT EXISTS paste_text TEXT NULL COMMENT 'Text that was pasted into this answer field' AFTER ai_score,
  ADD COLUMN IF NOT EXISTS paste_length INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Character count of pasted text' AFTER paste_text,
  ADD COLUMN IF NOT EXISTS copy_count_from_question INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of copy events from this question area' AFTER paste_length,
  ADD COLUMN IF NOT EXISTS copy_text TEXT NULL COMMENT 'Text that was copied from this question area' AFTER copy_count_from_question;
