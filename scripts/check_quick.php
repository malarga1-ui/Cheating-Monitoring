<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';
$out = __DIR__ . '/out.txt';
file_put_contents($out, "");

$in = '4,5,6';

$r = Database::fetchOne("SELECT account_id, COUNT(*) AS c FROM session_summaries GROUP BY account_id");
file_put_contents($out, "summaries_by_acc: " . json_encode($r) . "\n");

$r = Database::fetchOne("SELECT
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in)) AS exams,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students,
    (SELECT COUNT(*) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS sessions");
file_put_contents($out, "portal: " . json_encode($r) . "\n");

$rows = Database::fetchAll("SELECT e.id, e.moodle_course_id, e.name, (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id) AS students FROM exams e WHERE e.moodle_course_id IN ($in)");
file_put_contents($out, "exams:\n");
foreach ($rows as $row) {
    file_put_contents($out, "  exam={$row['id']} cid={$row['moodle_course_id']} students={$row['students']} {$row['name']}\n");
}

$r = Database::fetchOne("SELECT COUNT(DISTINCT student_id) AS c FROM session_summaries");
file_put_contents($out, "total_distinct_students: {$r['c']}\n");

$rows = Database::fetchAll("SELECT student_id, exam_id, account_id, risk_score, risk_level FROM session_summaries LIMIT 10");
file_put_contents($out, "samples:\n");
foreach ($rows as $row) {
    file_put_contents($out, "  sid={$row['student_id']} exam={$row['exam_id']} acc={$row['account_id']} risk={$row['risk_score']}/{$row['risk_level']}\n");
}
