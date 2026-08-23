-- =====================================================
-- Migration v15: Response Layer (SOAR closed-loop).
-- Creates the responses table for automated actions.
-- =====================================================

CREATE TABLE IF NOT EXISTS responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_summary_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    exam_id INT UNSIGNED NOT NULL,
    risk_score INT UNSIGNED NOT NULL DEFAULT 0,
    action VARCHAR(32) NOT NULL DEFAULT 'none',
    severity VARCHAR(16) NOT NULL DEFAULT 'info',
    details JSON DEFAULT NULL,
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    acknowledged_by INT UNSIGNED DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_responses_exam (exam_id),
    INDEX idx_responses_student (student_id),
    INDEX idx_responses_session (session_summary_id),
    INDEX idx_responses_ack (acknowledged, exam_id),
    INDEX idx_responses_severity (severity),
    INDEX idx_responses_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- v15: Keystroke dynamics columns for session_summaries.
-- DwellTime (key hold) + FlightTime (inter-key interval).
-- =====================================================

ALTER TABLE session_summaries
    ADD COLUMN IF NOT EXISTS dwell_avg_ms DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS dwell_std_ms DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS dwell_min_ms INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS dwell_max_ms INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS flight_avg_ms DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS flight_std_ms DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS flight_min_ms INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS flight_max_ms INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS keystroke_samples INT UNSIGNED DEFAULT 0;

-- =====================================================
-- Performance metrics view for Chapter 3.4 evaluation.
-- =====================================================

CREATE TABLE IF NOT EXISTS performance_metrics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id INT UNSIGNED NOT NULL,
    total_sessions INT UNSIGNED NOT NULL DEFAULT 0,
    true_positives INT UNSIGNED NOT NULL DEFAULT 0,
    false_positives INT UNSIGNED NOT NULL DEFAULT 0,
    true_negatives INT UNSIGNED NOT NULL DEFAULT 0,
    false_negatives INT UNSIGNED NOT NULL DEFAULT 0,
    accuracy DECIMAL(5,4) NOT NULL DEFAULT 0,
    precision_val DECIMAL(5,4) NOT NULL DEFAULT 0,
    recall DECIMAL(5,4) NOT NULL DEFAULT 0,
    f1_score DECIMAL(5,4) NOT NULL DEFAULT 0,
    false_positive_rate DECIMAL(5,4) NOT NULL DEFAULT 0,
    avg_response_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_perf_metrics_exam (exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- v15: Session verdicts — instructor ground truth labels.
-- Used by PerformanceMetrics to compute classification metrics.
-- =====================================================

CREATE TABLE IF NOT EXISTS session_verdicts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_summary_id INT UNSIGNED NOT NULL,
    verdict ENUM('cheating', 'clean') NOT NULL,
    labeled_by INT UNSIGNED DEFAULT NULL,
    labeled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_verdict_session (session_summary_id),
    INDEX idx_verdict_exam (session_summary_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
