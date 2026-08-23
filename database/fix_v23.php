<?php
require_once __DIR__ . '/../app/bootstrap.php';

// 1. Create audit_log table if missing
try {
    Database::execute("CREATE TABLE IF NOT EXISTS audit_log (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "audit_log: OK" . PHP_EOL;
} catch (\Throwable $e) {
    echo "audit_log: " . $e->getMessage() . PHP_EOL;
}

// 2. Fix site_domain for ALL accounts
Database::execute("UPDATE accounts SET site_domain = 'moodle.luckydraw.world' WHERE site_domain = '' OR site_domain IS NULL");
echo "site_domain fixed for all accounts" . PHP_EOL;

// 3. Show status
$accounts = Database::fetchAll('SELECT id, email, site_domain, api_secret IS NOT NULL AS has_secret FROM accounts ORDER BY id');
foreach ($accounts as $a) {
    echo "  id={$a['id']} email={$a['email']} domain={$a['site_domain']} secret=" . ($a['has_secret'] ? 'YES' : 'NO') . PHP_EOL;
}

// 4. Show tables count
$tables = Database::fetchAll("SHOW TABLES");
echo "Total tables: " . count($tables) . PHP_EOL;
