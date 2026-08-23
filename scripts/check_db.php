<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

echo "=== DATABASE DEBUG SCRIPT ===\n";

$accounts = Database::fetchAll("SELECT id, org_name, api_secret, site_domain FROM accounts");
echo "ACCOUNTS:\n";
foreach ($accounts as $a) {
    echo " - ID: {$a['id']}, Name: {$a['org_name']}, Secret: {$a['api_secret']}, Domain: {$a['site_domain']}\n";
}

echo "\nADMINS:\n";
$admins = Database::fetchAll("SELECT id, account_id, username FROM users");
foreach ($admins as $ad) {
    echo " - Admin ID: {$ad['id']}, Account ID: {$ad['account_id']}, Username: {$ad['username']}\n";
}

echo "\nTEACHERS:\n";
$teachers = Database::fetchAll("SELECT moodle_teacher_id, account_id, username, fullname FROM teachers");
foreach ($teachers as $t) {
    echo " - Teacher ID: {$t['moodle_teacher_id']}, Account: {$t['account_id']}, User: {$t['username']}, Name: {$t['fullname']}\n";
}

echo "\nSTUDENTS:\n";
$students = Database::fetchAll("SELECT id, account_id, moodle_user_id, username, fullname FROM students");
foreach ($students as $s) {
    echo " - Student DB ID: {$s['id']}, Account: {$s['account_id']}, Moodle ID: {$s['moodle_user_id']}, User: {$s['username']}, Name: {$s['fullname']}\n";
}

echo "\nCOURSE_TEACHERS:\n";
$ct = Database::fetchAll("SELECT account_id, moodle_course_id, moodle_teacher_id FROM course_teachers");
foreach ($ct as $c) {
    echo " - Account: {$c['account_id']}, Course: {$c['moodle_course_id']}, Teacher: {$c['moodle_teacher_id']}\n";
}

echo "\nEXAMS:\n";
$ex = Database::fetchAll("SELECT id, account_id, moodle_course_id, moodle_quiz_id FROM exams");
foreach ($ex as $e) {
    echo " - Exam ID: {$e['id']}, Account: {$e['account_id']}, Moodle Course: {$e['moodle_course_id']}, Moodle Quiz: {$e['moodle_quiz_id']}\n";
}

echo "\nSESSION_SUMMARIES:\n";
$ss = Database::fetchAll("SELECT id, account_id, student_id, exam_id FROM session_summaries");
foreach ($ss as $s) {
    echo " - Session ID: {$s['id']}, Account: {$s['account_id']}, Student ID: {$s['student_id']}, Exam ID: {$s['exam_id']}\n";
}

echo "=== END ===\n";
