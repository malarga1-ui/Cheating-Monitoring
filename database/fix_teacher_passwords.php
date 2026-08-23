<?php
/**
 * Quick fix: set each teacher's default password to {username}@915
 * Run: php fix_teacher_passwords.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$teachers = Database::fetchAll("SELECT moodle_teacher_id, username, password_hash FROM teachers ORDER BY moodle_teacher_id");

echo "=== Fixing teacher passwords ===\n\n";

foreach ($teachers as $t) {
    $uname = trim($t['username'] ?? '');
    if ($uname === '') {
        $uname = 'teacher' . $t['moodle_teacher_id'];
    }
    
    $defaultPw = $uname . '@915';
    $hash = password_hash($defaultPw, PASSWORD_DEFAULT);
    
    Database::execute(
        "UPDATE teachers SET password_hash = ?, is_first_login = 1 WHERE moodle_teacher_id = ?",
        [$hash, (int)$t['moodle_teacher_id']]
    );
    
    echo "[+] {$uname} -> {$defaultPw}\n";
}

echo "\n=== Done! All teachers can now log in with {username}@915 ===\n";
