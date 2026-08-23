<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';
$out = __DIR__ . '/out.txt';
file_put_contents($out, "");

// Fix ALL account_ids to 6
Database::execute("UPDATE session_summaries SET account_id = 6 WHERE account_id != 6");
$ss = Database::fetchOne("SELECT ROW_COUNT() AS c");
file_put_contents($out, "Fixed session_summaries: {$ss['c']} rows\n");

Database::execute("UPDATE answer_records SET account_id = 6 WHERE account_id != 6");
$ar = Database::fetchOne("SELECT ROW_COUNT() AS c");
file_put_contents($out, "Fixed answer_records: {$ar['c']} rows\n");

Database::execute("UPDATE ip_snapshots SET account_id = 6 WHERE account_id != 6");
$ip = Database::fetchOne("SELECT ROW_COUNT() AS c");
file_put_contents($out, "Fixed ip_snapshots: {$ip['c']} rows\n");

Database::execute("UPDATE network_groups SET account_id = 6 WHERE account_id != 6");
$ng = Database::fetchOne("SELECT ROW_COUNT() AS c");
file_put_contents($out, "Fixed network_groups: {$ng['c']} rows\n");

// Now verify portal query
$in = '4,5,6';
$r = Database::fetchOne("SELECT
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in)) AS exams,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in) AND ss.risk_level IN ('high','critical')) AS suspicious");
file_put_contents($out, "Portal (Ibrahim): " . json_encode($r) . "\n");

// Also check with account_id in WHERE on session_summaries
$r2 = Database::fetchOne("SELECT
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in) AND ss.account_id = 6) AS students_with_acc6,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students_without_acc_filter");
file_put_contents($out, "With vs without acc filter: " . json_encode($r2) . "\n");

// Check answer_records too
$ar = Database::fetchOne("SELECT COUNT(*) AS c FROM answer_records WHERE account_id = 6");
file_put_contents($out, "answer_records account_id=6: {$ar['c']}\n");
