<?php
/**
 * v22 migration: Create admin account + set teacher default passwords.
 * Run: php migration_v22_admin.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

echo "=== Exam Monitor v22 Migration ===\n\n";

// 1. Ensure username column exists
$colExists = Database::scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'username'"
);
if ((int)$colExists === 0) {
    Database::execute("ALTER TABLE accounts ADD COLUMN username VARCHAR(190) NOT NULL DEFAULT '' AFTER org_name");
    echo "[+] Added username column to accounts\n";
} else {
    echo "[=] username column already exists\n";
}

// 2. Ensure teachers table has password_hash and is_first_login columns
$pwExists = Database::scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = 'password_hash'"
);
if ((int)$pwExists === 0) {
    Database::execute("ALTER TABLE teachers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER username");
    echo "[+] Added password_hash column to teachers\n";
} else {
    echo "[=] password_hash column already exists\n";
}

$flExists = Database::scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = 'is_first_login'"
);
if ((int)$flExists === 0) {
    Database::execute("ALTER TABLE teachers ADD COLUMN is_first_login TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash");
    echo "[+] Added is_first_login column to teachers\n";
} else {
    echo "[=] is_first_login column already exists\n";
}

// 3. Create admin account (owner)
$admin = Database::fetchOne("SELECT id FROM accounts WHERE username = 'admin' LIMIT 1");
if ($admin === null) {
    $hash = password_hash('admin@915', PASSWORD_DEFAULT);
    Database::execute(
        "INSERT INTO accounts (email, password_hash, role, status, org_name, username, trial_started_at, trial_ends_at)
         VALUES (?, ?, 'owner', 'trial', 'منصة مراقب الامتحانات', 'admin', NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY))",
        ['admin@exammonitor.com', $hash]
    );
    echo "[+] Created admin account (username: admin, password: admin@915)\n";
} else {
    echo "[=] Admin account already exists (id: {$admin['id']})\n";
}

// 4. Set default passwords for teachers
$teachers = Database::fetchAll("SELECT id, username, password_hash, is_first_login FROM teachers WHERE password_hash = '' OR password_hash IS NULL");
$count = 0;
foreach ($teachers as $t) {
    $uname = trim($t['username'] ?? '');
    if ($uname === '') {
        $uname = 'teacher' . $t['id'];
    }
    $defaultPw = $uname . '@915';
    $hash = password_hash($defaultPw, PASSWORD_DEFAULT);
    Database::execute(
        "UPDATE teachers SET password_hash = ?, is_first_login = 1 WHERE id = ?",
        [$hash, (int)$t['id']]
    );
    $count++;
}
echo "[+] Set default passwords for {$count} teachers (pattern: {username}@915)\n";

// 5. Show summary
$accounts = Database::fetchAll("SELECT id, username, email, role, org_name FROM accounts ORDER BY id");
echo "\n=== Accounts ===\n";
foreach ($accounts as $a) {
    echo "  [{$a['id']}] {$a['username']} | {$a['email']} | {$a['role']} | {$a['org_name']}\n";
}

$teachersAll = Database::fetchAll("SELECT id, username, is_first_login FROM teachers ORDER BY id");
echo "\n=== Teachers ===\n";
foreach ($teachersAll as $t) {
    $pw = $t['is_first_login'] ? 'default (must change)' : 'changed';
    echo "  [{$t['id']}] {$t['username']} | password: {$pw}\n";
}

echo "\n=== Done! ===\n";
