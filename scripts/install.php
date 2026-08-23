<?php
/**
 * One-time installer:
 *   1. Creates the MySQL database (if missing)
 *   2. Applies database/schema.sql
 *   3. Creates the owner account (email login)
 *
 * Usage:
 *   php scripts/install.php <owner-email> <owner-password> [owner-org]
 * Or via env: EM_OWNER_EMAIL / EM_OWNER_PASS / EM_OWNER_ORG
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

$cfg = em_config('db');

$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']);

try {
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    out('[خطأ] تعذر الاتصال بـ MySQL: ' . $e->getMessage());
    out('تأكد من تشغيل MySQL ومن بيانات الاتصال في app/config.php (أو config.local.php).');
    exit(1);
}

out("التحقق من قاعدة البيانات '{$cfg['database']}' ...");
$dbName = str_replace('`', '', $cfg['database']);
try {
    // Shared hosts (cPanel) may not grant CREATE privilege; the database is
    // usually created from the control panel instead.
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {
    out('تنبيه: لا توجد صلاحية لإنشاء قاعدة البيانات — سنفترض أنها موجودة مسبقًا.');
}
$pdo->exec("USE `$dbName`");

out('تطبيق مخطط قاعدة البيانات ...');
$schemaFile = __DIR__ . '/../database/schema.sql';
$sql = file_get_contents($schemaFile);
if ($sql === false) {
    out('[خطأ] لم يتم العثور على schema.sql');
    exit(1);
}

$statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
foreach ($statements as $stmt) {
    $pdo->exec($stmt);
}
out('تم تطبيق المخطط بنجاح.');

// ---- Owner account (SaaS) ----------------------------------------------
$ownerEmail = strtolower(trim($argv[1] ?? getenv('EM_OWNER_EMAIL') ?: ''));
$ownerPass  = $argv[2] ?? getenv('EM_OWNER_PASS') ?: '';
$ownerOrg   = $argv[3] ?? getenv('EM_OWNER_ORG') ?: 'منصة مراقب الامتحانات';

if ($ownerEmail === '') {
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, 'بريد المالك: ');
        $ownerEmail = strtolower(trim((string)fgets(STDIN)));
    }
}
if ($ownerPass === '') {
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, 'كلمة مرور المالك: ');
        $ownerPass = trim((string)fgets(STDIN));
    }
}
if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    out('[خطأ] بريد المالك غير صالح.');
    exit(1);
}
if (strlen($ownerPass) < 8) {
    out('[خطأ] كلمة مرور المالك يجب أن تكون 8 أحرف على الأقل.');
    exit(1);
}

$stmt = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
$stmt->execute([$ownerEmail]);
if ($stmt->fetch()) {
    out("حساب المالك '$ownerEmail' موجود مسبقًا — يتم تحديث كلمة المرور فقط.");
    $update = $pdo->prepare('UPDATE accounts SET password_hash = ? WHERE email = ?');
    $update->execute([password_hash($ownerPass, PASSWORD_DEFAULT), $ownerEmail]);
} else {
    $insert = $pdo->prepare(
        'INSERT INTO accounts (org_name, email, password_hash, role, status, api_secret, activated_at)
         VALUES (?, ?, ?, "owner", "active", ?, NOW())'
    );
    $insert->execute([$ownerOrg, $ownerEmail, password_hash($ownerPass, PASSWORD_DEFAULT), bin2hex(random_bytes(24))]);
    out("تم إنشاء حساب المالك: $ownerEmail");
}

// ---- Storage dir -----------------------------------------------------
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

out('تم التثبيت بنجاح. سجّل الدخول الآن بحساب المالك.');
out('تهيئة cron (استضافة Namecheap): php ' . __DIR__ . '/worker.php');
