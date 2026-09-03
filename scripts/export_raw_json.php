<?php
/**
 * CLI Script: Export full raw telemetry JSON data for the SOAR evaluation exam.
 * Usage: php scripts/export_raw_json.php [exam_id]
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';

echo "========================================================\n";
echo "  SOAR Evaluation Exam: Raw Telemetry JSON Exporter     \n";
echo "========================================================\n\n";

// Resolve exam
$examId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($examId > 0) {
    $exam = Database::fetchOne('SELECT * FROM exams WHERE id = ? OR moodle_quiz_id = ? LIMIT 1', [$examId, $examId]);
} else {
    // Look for SOAR exam
    $exam = Database::fetchOne("SELECT * FROM exams WHERE name LIKE '%SOAR%' OR name LIKE '%Network%' ORDER BY id DESC LIMIT 1");
    if (!$exam) {
        $exam = Database::fetchOne("SELECT * FROM exams ORDER BY id DESC LIMIT 1");
    }
}

if (!$exam) {
    die("Error: No exam found in database.\n");
}

$internalId = (int)$exam['id'];
$quizId = (int)$exam['moodle_quiz_id'];
echo "Target Exam: [ID: {$internalId} | Quiz ID: {$quizId}] {$exam['name']}\n";

// Fetch events
$events = Database::fetchAll(
    "SELECT ev.id, ev.event_id, ev.session_id, ev.sequence_number, ev.event_type, ev.event_time,
            ev.moodle_quiz_id, ev.moodle_course_id, ev.moodle_user_id,
            ev.ip_address, ev.user_agent, ev.url, ev.payload_json, ev.created_at,
            s.fullname, s.username
     FROM events ev
     LEFT JOIN students s ON (s.moodle_user_id = ev.moodle_user_id OR s.id = ev.moodle_user_id)
     WHERE ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?
     ORDER BY ev.id ASC",
    [$quizId, $internalId]
);

echo "Total Events Fetched: " . count($events) . "\n";

$parsedEvents = [];
$eventTypesCount = [];

foreach ($events as $ev) {
    $payload = null;
    if (!empty($ev['payload_json'])) {
        $payload = json_decode($ev['payload_json'], true);
    }

    $type = $ev['event_type'];
    $eventTypesCount[$type] = ($eventTypesCount[$type] ?? 0) + 1;

    $parsedEvents[] = [
        'event_id'        => $ev['event_id'],
        'session_id'      => $ev['session_id'],
        'sequence_number' => (int)$ev['sequence_number'],
        'event_type'      => $ev['event_type'],
        'event_time'      => $ev['event_time'],
        'student'         => [
            'id'       => (int)$ev['moodle_user_id'],
            'fullname' => $ev['fullname'] ?? ('طالب #' . $ev['moodle_user_id']),
            'username' => $ev['username'] ?? ('user_' . $ev['moodle_user_id']),
        ],
        'quiz_id'         => (int)$ev['moodle_quiz_id'],
        'course_id'       => (int)$ev['moodle_course_id'],
        'network'         => [
            'ip_address' => $ev['ip_address'],
            'user_agent' => $ev['user_agent'],
        ],
        'url'             => $ev['url'],
        'raw_payload'     => $payload ?? $ev['payload_json'],
        'server_time'     => $ev['created_at'],
    ];
}

// Fetch session summaries
$summaries = Database::fetchAll(
    "SELECT ss.*, s.fullname, s.username
     FROM session_summaries ss
     LEFT JOIN students s ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id)
     WHERE ss.exam_id = ? OR ss.exam_id = ?
     ORDER BY ss.risk_score DESC",
    [$internalId, $quizId]
);

echo "Total Students/Sessions: " . count($summaries) . "\n";
echo "Event Types Breakdown:\n";
foreach ($eventTypesCount as $t => $cnt) {
    echo "  - {$t}: {$cnt}\n";
}

$exportDataset = [
    'metadata' => [
        'project'       => 'SOAR Assessment Security & Integrity Platform',
        'dataset_type'  => 'Raw Student Telemetry & Ground Truth Assessment',
        'generated_at'  => date('Y-m-d H:i:s T'),
        'exam'          => [
            'id'          => $internalId,
            'quiz_id'     => $quizId,
            'name'        => $exam['name'],
        ],
        'total_students'=> count($summaries),
        'total_events'  => count($parsedEvents),
    ],
    'student_summaries' => $summaries,
    'raw_telemetry_events' => $parsedEvents,
];

// Ensure downloads directory exists
$dir = __DIR__ . '/../public/downloads';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$filePath = $dir . '/soar_evaluation_raw_telemetry.json';
$jsonStr = json_encode($exportDataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($filePath, $jsonStr);

echo "\n[SUCCESS] Export generated successfully!\n";
echo "Saved to: " . realpath($filePath) . "\n";
echo "File Size: " . round(filesize($filePath) / 1024, 2) . " KB\n";
echo "Direct Download URL:\n";
echo "  https://jadallahkhaled.com/downloads/soar_evaluation_raw_telemetry.json\n\n";
