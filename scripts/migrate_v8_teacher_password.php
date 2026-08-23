<?php
/**
 * Teacher password migration (v8):
 *   1. teachers.password_hash    — platform-side password for teacher portal login.
 *   2. teachers.is_first_login   — flag to force password change on first login.
 *
 * When a teacher is synced from Moodle, a default password is generated and
 * hashed here. The teacher must change it on their first portal login.
 *
 * Default password pattern: {username}@915
 *
 * Usage:
 *   php scripts/migrate_v8_teacher_password.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Add password_hash column.
$cols = $db->query("SHOW COLUMNS FROM teachers LIKE 'password_hash'")->fetchAll();
if (!$cols) {
    $db->exec("ALTER TABLE teachers ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER login_enabled");
    echo "أضيف العمود teachers.password_hash." . PHP_EOL;
} else {
    echo "العمود teachers.password_hash موجود مسبقاً — تم التخطي." . PHP_EOL;
}

// 2. Add is_first_login column.
$cols = $db->query("SHOW COLUMNS FROM teachers LIKE 'is_first_login'")->fetchAll();
if (!$cols) {
    $db->exec("ALTER TABLE teachers ADD COLUMN is_first_login TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash");
    echo "أضيف العمود teachers.is_first_login." . PHP_EOL;
} else {
    echo "العمود teachers.is_first_login موجود مسبقاً — تم التخطي." . PHP_EOL;
}

// 3. Generate default passwords for existing teachers that have no password yet.
$teachers = $db->query(
    "SELECT moodle_teacher_id, username, password_hash FROM teachers WHERE password_hash = '' OR password_hash IS NULL"
)->fetchAll();

$count = 0;
foreach ($teachers as $teacher) {
    $tid = (int)$teacher['moodle_teacher_id'];
    $uname = trim((string)($teacher['username'] ?? ''));
    if ($uname === '') {
        $uname = 'user' . $tid;
    }
    $defaultPassword = $uname . '@915';
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $db->prepare("UPDATE teachers SET password_hash = ? WHERE moodle_teacher_id = ?")
       ->execute([$hash, $tid]);
    $count++;
}

if ($count > 0) {
    echo "تم إنشاء كلمات مرور افتراضية لـ $count معلّم." . PHP_EOL;
} else {
    echo "جميع المعلمين لديهم كلمات مرور بالفعل." . PHP_EOL;
}

echo "اكتمل ترحيل كلمات مرور المعلمين (v8) بنجاح." . PHP_EOL;
echo PHP_EOL;
echo "=== كلمات المرور الافتراضية ===" . PHP_EOL;
foreach ($teachers as $teacher) {
    $tid = (int)$teacher['moodle_teacher_id'];
    $uname = trim((string)($teacher['username'] ?? ''));
    if ($uname === '') {
        $uname = 'user' . $tid;
    }
    echo "  معلّم #$tid ($uname) → $uname@915" . PHP_EOL;
}
