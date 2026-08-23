<?php
/**
 * SaaS migration (v3):
 *   1. Creates the `accounts` table (email login, 7-day trial, api secret).
 *   2. Adds `account_id` to every tenant table.
 *   3. Seeds the owner account (the platform operator — never locked).
 *   4. Backfills existing data under the owner account (account_id = 0).
 *
 * Usage:
 *   php scripts/migrate_v3_saas.php "<owner-email>" "<owner-password>" "<owner-org>"
 * Or via env: EM_OWNER_EMAIL / EM_OWNER_PASS / EM_OWNER_ORG
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

$ownerEmail = $argv[1] ?? getenv('EM_OWNER_EMAIL') ?: '';
$ownerPass  = $argv[2] ?? getenv('EM_OWNER_PASS') ?: '';
$ownerOrg   = $argv[3] ?? getenv('EM_OWNER_ORG') ?: 'منصة مراقب الامتحانات';

if ($ownerEmail === '' || $ownerPass === '') {
    fwrite(STDERR, "Usage: php scripts/migrate_v3_saas.php <owner-email> <owner-password> [owner-org]\n");
    exit(1);
}
if (strlen($ownerPass) < 8) {
    fwrite(STDERR, "كلمة مرور المالك يجب أن تكون 8 أحرف على الأقل.\n");
    exit(1);
}

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- accounts -------------------------------------------------------------
$db->exec("CREATE TABLE IF NOT EXISTS accounts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_name      VARCHAR(190) NOT NULL DEFAULT '',
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('owner','customer') NOT NULL DEFAULT 'customer',
    status        ENUM('trial','expired','active','suspended') NOT NULL DEFAULT 'trial',
    api_secret    VARCHAR(64) NOT NULL,
    license_key   VARCHAR(190) NOT NULL DEFAULT '',
    site_domain   VARCHAR(255) NOT NULL DEFAULT '',
    trial_started_at DATETIME NULL,
    trial_ends_at    DATETIME NULL,
    activated_at     DATETIME NULL,
    last_login_at    DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounts_email (email),
    UNIQUE KEY uq_accounts_api_secret (api_secret),
    KEY idx_accounts_site_domain (site_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
out('تم إنشاء جدول accounts (أو موجود مسبقاً).');

// Upgrade an existing accounts table (deployed before site_domain existed).
$accCols = $db->query("SHOW COLUMNS FROM accounts LIKE 'site_domain'")->fetchAll();
if (!$accCols) {
    $db->exec('ALTER TABLE accounts ADD COLUMN site_domain VARCHAR(255) NOT NULL DEFAULT "" AFTER license_key, ADD KEY idx_accounts_site_domain (site_domain)');
    out('أضيف العمود site_domain إلى جدول accounts.');
}

// ---- account_id columns -----------------------------------------------------
$tables = [
    'courses', 'teachers', 'course_teachers', 'exams', 'students',
    'sessions', 'session_summaries', 'events',
];
foreach ($tables as $t) {
    $cols = $db->query("SHOW COLUMNS FROM `$t` LIKE 'account_id'")->fetchAll();
    if (!$cols) {
        $db->exec("ALTER TABLE `$t` ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0");
        out("أضيف العمود account_id إلى جدول $t.");
    } else {
        out("العمود account_id موجود مسبقاً في $t — تم التخطي.");
    }
}

// ---- owner account ----------------------------------------------------------
$stmt = $db->prepare('SELECT id FROM accounts WHERE email = ?');
$stmt->execute([$ownerEmail]);
if ($stmt->fetch()) {
    out("حساب المالك '$ownerEmail' موجود مسبقاً — يتم تحديث كلمة المرور فقط.");
    $upd = $db->prepare('UPDATE accounts SET password_hash = ? WHERE email = ?');
    $upd->execute([password_hash($ownerPass, PASSWORD_DEFAULT), $ownerEmail]);
} else {
    $ins = $db->prepare(
        'INSERT INTO accounts (org_name, email, password_hash, role, status, api_secret, activated_at)
         VALUES (?, ?, ?, "owner", "active", ?, NOW())'
    );
    $ins->execute([$ownerOrg, $ownerEmail, password_hash($ownerPass, PASSWORD_DEFAULT), bin2hex(random_bytes(24))]);
    out("تم إنشاء حساب المالك: $ownerEmail");
}

// Existing rows (account_id = 0) belong to the owner.
$ownerId = (int)$db->query("SELECT id FROM accounts WHERE email = '" . str_replace("'", "''", $ownerEmail) . "'")->fetchColumn();
foreach ($tables as $t) {
    $db->exec("UPDATE `$t` SET account_id = $ownerId WHERE account_id = 0");
}
out('تم إسناد البيانات القديمة إلى حساب المالك.');

out('اكتمل ترحيل SaaS بنجاح.');
