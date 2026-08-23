<?php
/**
 * Teacher portal migration (v5):
 *   1. teachers.login_enabled   — allow the admin to disable a teacher's portal access.
 *   2. accounts.moodle_ws_service — Moodle web-service shortname used to verify
 *      teacher credentials via /login/token.php (default: moodle_mobile_app).
 *   3. teacher_login_tokens     — one-time signed login tokens (plugin -> portal).
 *
 * Usage:
 *   php scripts/migrate_v5_teacher_portal.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = $db->query("SHOW COLUMNS FROM teachers LIKE 'login_enabled'")->fetchAll();
if (!$cols) {
    $db->exec("ALTER TABLE teachers ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER username");
    echo "أضيف العمود teachers.login_enabled." . PHP_EOL;
} else {
    echo "العمود teachers.login_enabled موجود مسبقاً — تم التخطي." . PHP_EOL;
}

$cols = $db->query("SHOW COLUMNS FROM accounts LIKE 'moodle_ws_service'")->fetchAll();
if (!$cols) {
    $db->exec("ALTER TABLE accounts ADD COLUMN moodle_ws_service VARCHAR(64) NOT NULL DEFAULT '' AFTER site_domain");
    echo "أضيف العمود accounts.moodle_ws_service." . PHP_EOL;
} else {
    echo "العمود accounts.moodle_ws_service موجود مسبقاً — تم التخطي." . PHP_EOL;
}

$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('teacher_login_tokens', $tables, true)) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS teacher_login_tokens (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL DEFAULT 0,
            teacher_id INT UNSIGNED NOT NULL DEFAULT 0,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at    DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_teacher_tokens_hash (token_hash),
            KEY idx_teacher_tokens_account (account_id, teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "أُنشئ جدول teacher_login_tokens." . PHP_EOL;
} else {
    echo "جدول teacher_login_tokens موجود مسبقاً — تم التخطي." . PHP_EOL;
}

echo "اكتمل ترحيل بوابة المدرّس (v5) بنجاح." . PHP_EOL;
