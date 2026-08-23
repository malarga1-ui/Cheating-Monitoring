<?php
/**
 * v22: Ensure all synced teachers are login-ready.
 * - Add password_hash column if missing
 * - Add is_first_login column if missing  
 * - Add login_enabled column if missing
 * - Set default password {username}@915 for teachers without one
 * - Enable login for all teachers
 *
 * Run: php migration_v22_teachers.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

echo "=== v22: Teacher Login Readiness ===\n\n";

// 1. Ensure columns exist
$columns = [
    'password_hash'  => "ALTER TABLE teachers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER username",
    'is_first_login' => "ALTER TABLE teachers ADD COLUMN is_first_login TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash",
    'login_enabled'  => "ALTER TABLE teachers ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_first_login",
];

foreach ($columns as $col => $sql) {
    $exists = Database::scalar(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = ?",
        [$col]
    );
    if ((int)$exists === 0) {
        Database::execute($sql);
        echo "[+] Added column: {$col}\n";
    } else {
        echo "[=] Column exists: {$col}\n";
    }
}

// 2. Ensure username column exists on accounts
$accUsername = Database::scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'username'"
);
if ((int)$accUsername === 0) {
    Database::execute("ALTER TABLE accounts ADD COLUMN username VARCHAR(190) NOT NULL DEFAULT '' AFTER org_name");
    echo "[+] Added username column to accounts\n";
}

// 3. Set default password for teachers who don't have one
$teachers = Database::fetchAll("SELECT id, username, password_hash, login_enabled FROM teachers ORDER BY id");
$count = 0;
foreach ($teachers as $t) {
    $uname = trim($t['username'] ?? '');
    if ($uname === '') {
        $uname = 'teacher' . $t['id'];
    }

    $hash = (string)($t['password_hash'] ?? '');
    $needsPassword = ($hash === '' || $hash === '0' || !str_starts_with($hash, '$2y$'));

    if ($needsPassword) {
        $defaultPw = $uname . '@915';
        $newHash = password_hash($defaultPw, PASSWORD_DEFAULT);
        Database::execute(
            "UPDATE teachers SET password_hash = ?, is_first_login = 1 WHERE id = ?",
            [$newHash, (int)$t['id']]
        );
        $count++;
        echo "[+] Set password for: {$uname} (default: {$uname}@915)\n";
    }

    // Ensure login_enabled = 1
    if ((int)$t['login_enabled'] !== 1) {
        Database::execute("UPDATE teachers SET login_enabled = 1 WHERE id = ?", [(int)$t['id']]);
        echo "[+] Enabled login for: {$uname}\n";
    }
}

echo "\n[=] Updated {$count} teachers with default passwords\n";

// 4. Show all teachers
$all = Database::fetchAll("SELECT id, username, fullname, account_id, login_enabled, is_first_login FROM teachers ORDER BY id");
echo "\n=== Teachers ===\n";
foreach ($all as $t) {
    $pw = (int)$t['is_first_login'] === 1 ? 'default' : 'changed';
    $enabled = (int)$t['login_enabled'] === 1 ? 'YES' : 'NO';
    echo "  [{$t['id']}] {$t['username']} | {$t['fullname']} | acc:{$t['account_id']} | login:{$enabled} | pw:{$pw}\n";
}

// 5. Show accounts with site_domain
$accounts = Database::fetchAll("SELECT id, org_name, username, site_domain FROM accounts ORDER BY id");
echo "\n=== Accounts (Universities) ===\n";
foreach ($accounts as $a) {
    $domain = $a['site_domain'] ?: '(empty)';
    echo "  [{$a['id']}] {$a['org_name']} | user:{$a['username']} | domain:{$domain}\n";
}

echo "\n=== Done! ===\n";
