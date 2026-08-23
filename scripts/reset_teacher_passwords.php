<?php
/**
 * Reset all teacher passwords to new format: {username}@915
 * Run AFTER cleanup_teachers.sql
 *
 * Usage: php scripts/reset_teacher_passwords.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$teachers = $db->query("SELECT moodle_teacher_id, username FROM teachers")->fetchAll();
$count = 0;

foreach ($teachers as $teacher) {
    $tid = (int)$teacher['moodle_teacher_id'];
    $uname = trim((string)($teacher['username'] ?? ''));
    if ($uname === '') {
        $uname = 'user' . $tid;
    }
    $defaultPassword = $uname . '@915';
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $db->prepare("UPDATE teachers SET password_hash = ?, is_first_login = 1 WHERE moodle_teacher_id = ?")
       ->execute([$hash, $tid]);
    echo "  معلّم #$tid ($uname) → $uname@915" . PHP_EOL;
    $count++;
}

echo PHP_EOL . "تم إعادة تعيين كلمات مرور $count معلّم بالصيغة الجديدة: {username}@915" . PHP_EOL;
