-- ============================================================
-- Migration v9: Advanced Analytics Tables + Columns
-- Run this ONCE on existing servers to add v9 support.
-- All statements use IF NOT EXISTS / safe ALTER for re-runnability.
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Add v9 columns to session_summaries (safe ALTER)
-- ------------------------------------------------------------
-- Network columns
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS ip_address             VARCHAR(45)  NOT NULL DEFAULT '' AFTER risk_level;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS ip_country             VARCHAR(64)  NOT NULL DEFAULT '' AFTER ip_address;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS ip_city                VARCHAR(128) NOT NULL DEFAULT '' AFTER ip_country;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS same_ip_student_count  INT UNSIGNED NOT NULL DEFAULT 0  AFTER ip_city;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS ip_changed_count       INT UNSIGNED NOT NULL DEFAULT 0  AFTER same_ip_student_count;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS same_ip_risk_score     SMALLINT     NOT NULL DEFAULT 0  AFTER ip_changed_count;

-- AI detection columns
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS ai_suspect_score       SMALLINT     NOT NULL DEFAULT 0  AFTER same_ip_risk_score;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS answer_text_count      INT UNSIGNED NOT NULL DEFAULT 0  AFTER ai_suspect_score;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS avg_answer_length      INT UNSIGNED NOT NULL DEFAULT 0  AFTER answer_text_count;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS typing_answer_ratio    DECIMAL(5,2) NOT NULL DEFAULT 0  AFTER avg_answer_length;

-- Similarity columns
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS similarity_max_score   SMALLINT     NOT NULL DEFAULT 0  AFTER typing_answer_ratio;
ALTER TABLE session_summaries ADD COLUMN IF NOT EXISTS similarity_match_count INT UNSIGNED NOT NULL DEFAULT 0  AFTER similarity_max_score;

-- ------------------------------------------------------------
-- 2. Create v9 tables (IF NOT EXISTS for safety)
-- ------------------------------------------------------------

-- Answer records
CREATE TABLE IF NOT EXISTS answer_records (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id         INT UNSIGNED NOT NULL DEFAULT 0,
    session_id         VARCHAR(64) NOT NULL,
    student_id         INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id            INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_quiz_id     INT UNSIGNED NOT NULL DEFAULT 0,
    question_id        VARCHAR(128) NOT NULL DEFAULT '',
    question_type      VARCHAR(64) NOT NULL DEFAULT '',
    answer_text        TEXT NOT NULL,
    answer_length      INT UNSIGNED NOT NULL DEFAULT 0,
    word_count         INT UNSIGNED NOT NULL DEFAULT 0,
    typing_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    change_count       INT UNSIGNED NOT NULL DEFAULT 1,
    created_at         DATETIME(3) NOT NULL,
    UNIQUE KEY uq_answer_records_event (session_id, question_id),
    KEY idx_answer_records_exam (account_id, exam_id),
    KEY idx_answer_records_student (account_id, student_id),
    KEY idx_answer_records_session (account_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP snapshots
CREATE TABLE IF NOT EXISTS ip_snapshots (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED NOT NULL DEFAULT 0,
    session_id    VARCHAR(64) NOT NULL,
    student_id    INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id       INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address    VARCHAR(45) NOT NULL DEFAULT '',
    user_agent    VARCHAR(512) NOT NULL DEFAULT '',
    browser_fp    VARCHAR(255) NOT NULL DEFAULT '',
    detected_at   DATETIME(3) NOT NULL,
    UNIQUE KEY uq_ip_snapshots (session_id, detected_at),
    KEY idx_ip_snapshots_exam (account_id, exam_id),
    KEY idx_ip_snapshots_session (account_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student devices
CREATE TABLE IF NOT EXISTS student_devices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL DEFAULT 0,
    student_id      INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id         INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45) NOT NULL DEFAULT '',
    user_agent      VARCHAR(512) NOT NULL DEFAULT '',
    browser_fp      VARCHAR(255) NOT NULL DEFAULT '',
    first_seen      DATETIME(3) NOT NULL,
    last_seen       DATETIME(3) NOT NULL,
    snapshot_count  INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_student_devices_fp (account_id, student_id, exam_id, browser_fp),
    KEY idx_student_devices_exam (account_id, exam_id),
    KEY idx_student_devices_student (account_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Network groups
CREATE TABLE IF NOT EXISTS network_groups (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id         INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45) NOT NULL DEFAULT '',
    student_count   INT UNSIGNED NOT NULL DEFAULT 0,
    student_ids     JSON NULL,
    risk_level      ENUM('safe','low','medium','high','critical') NOT NULL DEFAULT 'safe',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_network_groups (account_id, exam_id, ip_address),
    KEY idx_network_groups_exam (account_id, exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Similarity pairs
CREATE TABLE IF NOT EXISTS similarity_pairs (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id           INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id              INT UNSIGNED NOT NULL DEFAULT 0,
    student_a_id         INT UNSIGNED NOT NULL DEFAULT 0,
    student_b_id         INT UNSIGNED NOT NULL DEFAULT 0,
    similarity_pct       SMALLINT NOT NULL DEFAULT 0,
    matching_questions   INT UNSIGNED NOT NULL DEFAULT 0,
    total_questions      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_similarity_pairs_exam (account_id, exam_id),
    KEY idx_similarity_pairs_score (account_id, exam_id, similarity_pct DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
