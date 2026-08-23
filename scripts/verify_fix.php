<?php
/**
 * Quick check + fix WITHOUT running Aggregator (which times out).
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';

$out = __DIR__ . '/out.txt';
file_put_contents($out, "CHECK\n");

$r = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries");
file_put_contents($out, "summaries={$r['c']}\n", FILE_APPEND);

$r = Database::fetchOne("SELECT COUNT(*) AS c FROM answer_records");
file_put_contents($out, "answers={$r['c']}\n", FILE_APPEND);

// If no summaries, we need to run Aggregator but WITHOUT advanced analytics
if ((int)$Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries")['c'] === 0) {
    file_put_contents($out, "\nNo summaries. Running Aggregator WITHOUT analytics...\n", FILE_APPEND);
    
    // Reset watermark
    Database::execute("UPDATE agg_watermark SET last_event_id = 0 WHERE id = 1");
    
    // Process events manually (just the aggregation part, skip analytics)
    $pdo = Database::connection();
    
    // Import Aggregator class methods we need
    // We'll just call process() with a small batch but catch the analytics error
    try {
        $result = Aggregator::process(2000);
        file_put_contents($out, "Aggregator: " . json_encode($result) . "\n", FILE_APPEND);
    } catch (\Throwable $e) {
        file_put_contents($out, "Aggregator error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    $r2 = Database::fetchOne("SELECT COUNT(*) AS c FROM session_summaries");
    file_put_contents($out, "summaries after: {$r2['c']}\n", FILE_APPEND);
    
    $r3 = Database::fetchOne("SELECT account_id, COUNT(*) AS c FROM session_summaries GROUP BY account_id");
    file_put_contents($out, "by account: " . json_encode($r3) . "\n", FILE_APPEND);
}

// Portal test
$in = '4,5,6';
$portal = Database::fetchOne("SELECT
    (SELECT COUNT(*) FROM exams e WHERE e.account_id = 6 AND e.moodle_course_id IN ($in)) AS exams,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in)) AS students,
    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss JOIN exams e2 ON e2.id = ss.exam_id AND e2.account_id = ss.account_id WHERE e2.account_id = 6 AND e2.moodle_course_id IN ($in) AND ss.risk_level IN ('high','critical')) AS suspicious");
file_put_contents($out, "\nPortal: " . json_encode($portal) . "\n", FILE_APPEND);

// Error log check
$logFile = __DIR__ . '/../storage/logs/php-error.log';
if (file_exists($logFile)) {
    $log = file_get_contents($logFile);
    $lines = explode("\n", trim($log));
    $last20 = array_slice($lines, -20);
    file_put_contents($out, "\nLast PHP errors:\n" . implode("\n", $last20) . "\n", FILE_APPEND);
}

file_put_contents($out, "\nDONE\n", FILE_APPEND);
