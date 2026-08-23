-- Fix: ensure audit_log table exists (run if missing)
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    actor_type  VARCHAR(16)  NOT NULL DEFAULT 'account',
    actor_id    INT UNSIGNED NULL,
    actor_name  VARCHAR(255) NOT NULL DEFAULT '',
    action      VARCHAR(64)  NOT NULL,
    target_type VARCHAR(32)  NULL,
    target_id   INT UNSIGNED NULL,
    details     JSON         NULL,
    created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_audit_account_time (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
