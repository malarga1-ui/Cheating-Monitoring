<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';

$out = __DIR__ . '/out.txt';
file_put_contents($out, "START\n");

// 1. What account_ids do events have?
file_put_contents($out, "\n=== EVENTS account_id distribution ===\n", FILE_APPEND);
$rows = Database::fetchAll("SELECT account_id, COUNT(*) AS cnt, MIN(moodle_quiz_id) AS min_qid, MAX(moodle_quiz_id) AS max_qid FROM events GROUP BY account_id ORDER BY account_id");
foreach ($rows as $r) {
    file_put_contents($out, "  account_id={$r['account_id']} events={$r['cnt']} qid_range=[{$r['min_qid']}-{$r['max_qid']}]\n", FILE_APPEND);
}

// 2. What account_id do exams have?
file_put_contents($out, "\n=== EXAMS account_id ===\n", FILE_APPEND);
$rows = Database::fetchAll("SELECT id, account_id, moodle_quiz_id, moodle_course_id, name FROM exams ORDER BY id");
foreach ($rows as $r) {
    file_put_contents($out, "  exam={$r['id']} account={$r['account_id']} qid={$r['moodle_quiz_id']} cid={$r['moodle_course_id']} name={$r['name']}\n", FILE_APPEND);
}

// 3. What account_id do session_summaries have?
file_put_contents($out, "\n=== SESSION_SUMMARIES account_id distribution ===\n", FILE_APPEND);
$rows = Database::fetchAll("SELECT account_id, COUNT(*) AS cnt FROM session_summaries GROUP BY account_id");
foreach ($rows as $r) {
    file_put_contents($out, "  account_id={$r['account_id']} summaries={$r['cnt']}\n", FILE_APPEND);
}

// 4. Events for qid=19 specifically
file_put_contents($out, "\n=== EVENTS for qid=19 account_ids ===\n", FILE_APPEND);
$rows = Database::fetchAll("SELECT account_id, COUNT(*) AS cnt, COUNT(DISTINCT moodle_user_id) AS students FROM events WHERE moodle_quiz_id = 19 GROUP BY account_id");
foreach ($rows as $r) {
    file_put_contents($out, "  account_id={$r['account_id']} events={$r['cnt']} students={$r['students']}\n", FILE_APPEND);
}

// 5. Session summaries for exam_id=7 specifically
file_put_contents($out, "\n=== SESSION_SUMMARIES for exam_id=7 ===\n", FILE_APPEND);
$rows = Database::fetchAll("SELECT account_id, session_id, student_id, risk_score, risk_level FROM session_summaries WHERE exam_id = 7");
foreach ($rows as $r) {
    file_put_contents($out, "  account={$r['account_id']} sess={$r['session_id']} student={$r['student_id']} risk={$r['risk_score']}/{$r['risk_level']}\n", FILE_APPEND);
}

// 6. What does teacher portal actually see?
file_put_contents($out, "\n=== PORTAL SUMMARY for Ibrahim (tch=4, acc=6) ===\n", FILE_APPEND);
$ids = [4,5,6];
$in = implode(',', $ids);

$r = Database::fetchOne("SELECT
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in)) AS exams_count,
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in) AND e.status = 'active') AS active_exams,
    (SELECT COUNT(*) FROM courses c WHERE c.account_id = 6 AND c.moodle_course_id IN ($in)) AS courses_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e4 ON e4.id = ss.exam_id AND e4.account_id = ss.account_id WHERE e4.account_id = 6 AND e4.moodle_course_id IN ($in) AND ss.risk_level IN ('high','critical')) AS suspicious_count");
file_put_contents($out, "  " . json_encode($r) . "\n", FILE_APPEND);

// 7. What if we IGNORE account_id in the join?
file_put_contents($out, "\n=== PORTAL SUMMARY ignoring account_id mismatch ===\n", FILE_APPEND);
$r2 = Database::fetchOne("SELECT
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id WHERE e2.moodle_course_id IN ($in)) AS students_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id WHERE e2.moodle_course_id IN ($in) AND ss.risk_level IN ('high','critical')) AS suspicious_count,
    (SELECT COUNT(*) FROM events ev WHERE ev.moodle_course_id IN ($in)) AS total_events,
    (SELECT COUNT(DISTINCT ev.moodle_user_id) FROM events ev WHERE ev.moodle_course_id IN ($in)) AS distinct_students_from_events");
file_put_contents($out, "  " . json_encode($r2) . "\n", FILE_APPEND);

// 8. What are the actual exams that would appear if we fix the query?
file_put_contents($out, "\n=== EXAMS for courses 4,5,6 (ignoring account_id) ===\n", FILE_APPEND);
$rows = Database::fetchAll("SELECT e.id, e.account_id, e.moodle_quiz_id, e.moodle_course_id, e.name,
    (SELECT COUNT(DISTINCT ev.moodle_user_id) FROM events ev WHERE ev.moodle_quiz_id = e.moodle_quiz_id) AS students_from_events
FROM exams e WHERE e.moodle_course_id IN ($in) ORDER BY e.moodle_course_id, e.id");
foreach ($rows as $r) {
    file_put_contents($out, "  exam={$r['id']} account={$r['account_id']} qid={$r['moodle_quiz_id']} cid={$r['moodle_course_id']} students={$r['students_from_events']} name={$r['name']}\n", FILE_APPEND);
}

file_put_contents($out, "\nDONE\n", FILE_APPEND);
