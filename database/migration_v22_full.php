<?php
/**
 * v22 Full Migration — Fix everything:
 * 1. Create missing tables (users, audit_log, course_access, login_attempts)
 * 2. Add missing columns to teachers (password_hash, is_first_login, login_enabled)
 * 3. Add username column to accounts
 * 4. Set default passwords for all synced teachers
 * 5. Enable login for all teachers
 *
 * Run: php migration_v22_full.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

echo "=== Exam Monitor v22 Full Migration ===\n\n";

// --- 1. Create missing tables ---
$tables = [
    // Staff users table
    "CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id    INT UNSIGNED NOT NULL DEFAULT 0,
        username      VARCHAR(64)  NOT NULL,
        fullname      VARCHAR(190) NOT NULL DEFAULT '',
        email         VARCHAR(190) NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','supervisor') NOT NULL DEFAULT 'admin',
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME     NULL,
        KEY idx_users_account (account_id),
        UNIQUE KEY uq_users_account_username (account_id, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Audit log
    "CREATE TABLE IF NOT EXISTS audit_log (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id  INT UNSIGNED NOT NULL DEFAULT 0,
        actor_type  VARCHAR(32)  NOT NULL DEFAULT '',
        actor_id    INT UNSIGNED NULL,
        actor_name  VARCHAR(190) NOT NULL DEFAULT '',
        action      VARCHAR(64)  NOT NULL DEFAULT '',
        target_type VARCHAR(32)  NOT NULL DEFAULT '',
        target_id   INT UNSIGNED NULL,
        details     TEXT         NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_account (account_id),
        KEY idx_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Course access for supervisors
    "CREATE TABLE IF NOT EXISTS course_access (
        account_id       INT UNSIGNED NOT NULL DEFAULT 0,
        user_id          INT UNSIGNED NOT NULL,
        moodle_course_id INT UNSIGNED NOT NULL,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (account_id, user_id, moodle_course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Login attempts for rate limiting
    "CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_address   VARCHAR(45)  NOT NULL,
        attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login_attempts_ip (ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Session verdicts
    "CREATE TABLE IF NOT EXISTS session_verdicts (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id   INT UNSIGNED NOT NULL DEFAULT 0,
        exam_id      INT UNSIGNED NOT NULL DEFAULT 0,
        account_id   INT UNSIGNED NOT NULL DEFAULT 0,
        student_id   INT UNSIGNED NOT NULL DEFAULT 0,
        verdict      VARCHAR(32) NOT NULL DEFAULT 'clean',
        score        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        details      TEXT        NULL,
        created_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sv_account (account_id),
        KEY idx_sv_exam (exam_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $sql) {
    try {
        Database::execute($sql);
        // Extract table name for display
        preg_match('/IF NOT EXISTS\s+(\w+)/i', $sql, $m);
        $name = $m[1] ?? 'unknown';
        echo "[+] Table ready: {$name}\n";
    } catch (Throwable $e) {
        echo "[!] Table error: {$e->getMessage()}\n";
    }
}

// --- 2. Add missing columns to teachers ---
$teacherCols = [
    'password_hash'  => "ALTER TABLE teachers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER username",
    'is_first_login' => "ALTER TABLE teachers ADD COLUMN is_first_login TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash",
    'login_enabled'  => "ALTER TABLE teachers ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_first_login",
];

foreach ($teacherCols as $col => $sql) {
    $exists = Database::scalar(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teachers' AND COLUMN_NAME = ?",
        [$col]
    );
    if ((int)$exists === 0) {
        Database::execute($sql);
        echo "[+] teachers.{$col} added\n";
    } else {
        echo "[=] teachers.{$col} exists\n";
    }
}

// --- 3. Add username column to accounts ---
$accCol = Database::scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'username'"
);
if ((int)$accCol === 0) {
    Database::execute("ALTER TABLE accounts ADD COLUMN username VARCHAR(190) NOT NULL DEFAULT '' AFTER org_name");
    echo "[+] accounts.username added\n";
} else {
    echo "[=] accounts.username exists\n";
}

// --- 4. Add account_id to users table if missing ---
$usersAccCol = Database::scalar(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_id'"
);
if ((int)$usersAccCol === 0) {
    Database::execute("ALTER TABLE users ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id");
    echo "[+] users.account_id added\n";
} else {
    echo "[=] users.account_id exists\n";
}

// --- 5. Set default passwords for all teachers ---
$teachers = Database::fetchAll("SELECT moodle_teacher_id, username, password_hash, is_first_login, login_enabled FROM teachers ORDER BY moodle_teacher_id");
$count = 0;
foreach ($teachers as $t) {
    $uname = trim($t['username'] ?? '');
    if ($uname === '') {
        $uname = 'teacher' . $t['moodle_teacher_id'];
    }

    $hash = (string)($t['password_hash'] ?? '');
    $needsPassword = ($hash === '' || $hash === '0' || !str_starts_with($hash, '$2y$'));

    if ($needsPassword) {
        $defaultPw = $uname . '@915';
        $newHash = password_hash($defaultPw, PASSWORD_DEFAULT);
        Database::execute(
            "UPDATE teachers SET password_hash = ?, is_first_login = 1 WHERE moodle_teacher_id = ?",
            [$newHash, (int)$t['moodle_teacher_id']]
        );
        $count++;
        echo "[+] Password set: {$uname} -> {$uname}@915\n";
    }

    // Ensure login_enabled = 1
    if ((int)$t['login_enabled'] !== 1) {
        Database::execute("UPDATE teachers SET login_enabled = 1 WHERE moodle_teacher_id = ?", [(int)$t['moodle_teacher_id']]);
        echo "[+] Login enabled: {$uname}\n";
    }
}

echo "\n[=] Updated {$count} teachers with default passwords\n";

// --- 6. Show summary ---
$allTeachers = Database::fetchAll("SELECT moodle_teacher_id, username, fullname, account_id, login_enabled, is_first_login FROM teachers ORDER BY moodle_teacher_id");
echo "\n=== Teachers ===\n";
foreach ($allTeachers as $t) {
    $pw = (int)$t['is_first_login'] === 1 ? 'default (' . $t['username'] . '@915)' : 'CHANGED';
    $enabled = (int)$t['login_enabled'] === 1 ? 'YES' : 'NO';
    echo "  [{$t['moodle_teacher_id']}] {$t['username']} | {$t['fullname']} | acc:{$t['account_id']} | login:{$enabled} | pw:{$pw}\n";
}

$accounts = Database::fetchAll("SELECT id, org_name, username, site_domain FROM accounts ORDER BY id");
echo "\n=== Accounts ===\n";
foreach ($accounts as $a) {
    $domain = $a['site_domain'] ?: '(empty)';
    echo "  [{$a['id']}] {$a['org_name']} | user:{$a['username']} | domain:{$domain}\n";
}

$allTables = Database::fetchAll("SHOW TABLES");
echo "\n=== All Tables (" . count($allTables) . ") ===\n";

echo "\n=== Migration Complete! ===\n";
