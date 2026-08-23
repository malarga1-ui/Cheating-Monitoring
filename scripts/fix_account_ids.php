<?php
/**
 * Fix account_id mismatch: update events and session_summaries to match their exam's account_id.
 * Then reset the Aggregator watermark so it reprocesses everything.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';

$out = __DIR__ . '/out.txt';
file_put_contents($out, "ACCOUNT_ID FIX START\n");

// 1. Show before state
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM events WHERE account_id != 6");
file_put_contents($out, "Events with account_id != 6: {$r['c']}\n", FILE_APPEND);

$r = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries WHERE account_id != 6");
file_put_contents($out, "Summaries with account_id != 6: {$r['c']}\n", FILE_APPEND);

// 2. Update ALL events to account_id=6 (all exams are account_id=6)
Database::execute("UPDATE events SET account_id = 6 WHERE account_id != 6");
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM events");
file_put_contents($out, "After fix: total events={$r['c']}\n", FILE_APPEND);

$r = Database::fetchOne("SELECT COUNT(*) AS c FROM events WHERE account_id = 6");
file_put_contents($out, "Events with account_id=6: {$r['c']}\n", FILE_APPEND);

// 3. Delete all session_summaries (Aggregator will recreate with correct account_id)
Database::execute("DELETE FROM session_summaries");
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries");
file_put_contents($out, "After delete: session_summaries={$r['c']}\n", FILE_APPEND);

// 4. Delete all answer_records too (they reference sessions that will be recreated)
Database::execute("DELETE FROM answer_records");
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM answer_records");
file_put_contents($out, "After delete: answer_records={$r['c']}\n", FILE_APPEND);

// 5. Delete ip_snapshots and network_groups (will be recreated)
Database::execute("DELETE FROM ip_snapshots");
Database::execute("DELETE FROM network_groups");

// 6. Reset the Aggregator watermark to 0 so it reprocesses ALL events
Database::execute("UPDATE agg_watermark SET last_event_id = 0 WHERE id = 1");
$wm = Database::fetchOne("SELECT last_event_id FROM agg_watermark WHERE id = 1");
file_put_contents($out, "Watermark reset to: {$wm['last_event_id']}\n", FILE_APPEND);

// 7. Now run the Aggregator
file_put_contents($out, "\nRunning Aggregator...\n", FILE_APPEND);
$result = Aggregator::process(2000);
file_put_contents($out, "Aggregator result: " . json_encode($result) . "\n", FILE_APPEND);

// 8. Verify after
$r = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries");
file_put_contents($out, "After aggregator: session_summaries={$r['c']}\n", FILE_APPEND);

$r = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries WHERE account_id = 6");
file_put_contents($out, "Summaries with account_id=6: {$r['c']}\n", FILE_APPEND);

// 9. Test the portal query
file_put_contents($out, "\n=== Portal test for Ibrahim (tch=4, acc=6, courses 4,5,6) ===\n", FILE_APPEND);
$ids = [4,5,6];
$in = implode(',', $ids);
$portal = Database::fetchOne("SELECT
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in)) AS exams_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in) AND ss.risk_level IN ('high','critical')) AS suspicious_count");
file_put_contents($out, "Portal result: " . json_encode($portal) . "\n", FILE_APPEND);

// 10. If Aggregator only did 1 batch, run again to get more
if ($result['processed'] > 0 && $result['lag'] > 0) {
    file_put_contents($out, "\nRunning Aggregator again (lag={$result['lag']})...\n", FILE_APPEND);
    $result2 = Aggregator::process(2000);
    file_put_contents($out, "Aggregator result2: " . json_encode($result2) . "\n", FILE_APPEND);
    
    $r = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries WHERE account_id = 6");
    file_put_contents($out, "Summaries with account_id=6 after run2: {$r['c']}\n", FILE_APPEND);
}

// 11. Final portal check
$portal = Database::fetchOne("SELECT
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in)) AS exams_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students_count,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in) AND ss.risk_level IN ('high','critical')) AS suspicious_count");
file_put_contents($out, "\n=== FINAL PORTAL RESULT ===\n", FILE_APPEND);
file_put_contents($out, json_encode($portal) . "\n", FILE_APPEND);

file_put_contents($out, "\nDONE\n", FILE_APPEND);
