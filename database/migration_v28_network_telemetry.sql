-- Migration: v28 — Student Device Telemetry + Network Smart Detection
-- Run this on the exam_monitor database

-- 1. Add network_score_N and external_lookup columns to session_summaries
ALTER TABLE session_summaries
    ADD COLUMN IF NOT EXISTS network_score_N INT DEFAULT 0 AFTER same_ip_risk_score,
    ADD COLUMN IF NOT EXISTS external_lookup_probability INT DEFAULT 0 AFTER cognitive_score,
    ADD COLUMN IF NOT EXISTS external_lookup_signals JSON DEFAULT NULL AFTER external_lookup_probability;

-- 2. Create student_telemetry table for device fingerprinting
CREATE TABLE IF NOT EXISTS student_telemetry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    exam_id INT NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    student_id INT NOT NULL,
    client_ip VARCHAR(45) DEFAULT NULL,
    fingerprint_hash VARCHAR(128) DEFAULT NULL,
    screen_resolution VARCHAR(20) DEFAULT NULL,
    client_timezone VARCHAR(64) DEFAULT NULL,
    device_memory_gb DECIMAL(5,2) DEFAULT NULL,
    cpu_cores INT DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    language VARCHAR(10) DEFAULT NULL,
    platform VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_telemetry_session (session_id),
    INDEX idx_student_telemetry_exam (account_id, exam_id),
    INDEX idx_student_telemetry_student (account_id, exam_id, student_id),
    INDEX idx_student_telemetry_fp (fingerprint_hash),
    INDEX idx_student_telemetry_ip (client_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add fingerprint_hash column to ip_snapshots if not exists
ALTER TABLE ip_snapshots
    ADD COLUMN IF NOT EXISTS fingerprint_hash VARCHAR(128) DEFAULT NULL AFTER ip_address,
    ADD COLUMN IF NOT EXISTS client_timezone VARCHAR(64) DEFAULT NULL AFTER fingerprint_hash;

-- 4. Add fingerprint_hash column to student_devices if not exists
ALTER TABLE student_devices
    ADD COLUMN IF NOT EXISTS fingerprint_hash VARCHAR(128) DEFAULT NULL AFTER browser_fp;

-- 5. Insert default risk_indicators for new v28 indicators
INSERT IGNORE INTO risk_indicators (indicator_key, label_ar, weight_percent, enabled, description, sort_order, category)
VALUES
    ('network_score_N', 'الشبكة الذكية', 5, 1, 'الكشف عن الأجهزة المشتركة والﺰواية المشبوهة.', 30, 'network'),
    ('external_lookup_probability', 'احتمال بحث خارجي', 3, 1, 'استخدام جهاز خارجي أثناء الامتحان.', 31, 'behavioral');
