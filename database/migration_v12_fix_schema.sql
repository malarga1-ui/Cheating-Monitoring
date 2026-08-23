-- Migration v12: Fix missing detected_at columns + moodle_ws_service
-- These columns are queried by TeacherPortalController but were missing from schema.

-- Add detected_at to network_groups
ALTER TABLE network_groups
  ADD COLUMN IF NOT EXISTS detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER risk_level;

-- Add detected_at to similarity_pairs
ALTER TABLE similarity_pairs
  ADD COLUMN IF NOT EXISTS detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER total_questions;

-- Add moodle_ws_service to accounts (for Moodle WS token verification)
ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS moodle_ws_service VARCHAR(128) NULL DEFAULT NULL AFTER moodle_url;
