-- ============================================================
-- Migration: Add login_attempts table for rate limiting
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(190) NULL,
    is_success TINYINT(1) NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
