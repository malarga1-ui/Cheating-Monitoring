<?php
/**
 * Audit log migration (v7):
 *   audit_log — account-scoped accountability trail for security actions
 *   (staff management, API key rotation, staff sign-ins).
 *
 * Usage:
 *   php scripts/migrate_v7_audit.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'أُنشئ جدول سجل النشاط audit_log.' . PHP_EOL;
echo 'اكتمل ترحيل سجل النشاط (v7) بنجاح.' . PHP_EOL;
