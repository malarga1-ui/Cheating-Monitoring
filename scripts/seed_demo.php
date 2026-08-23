<?php
/**
 * Demo data generator for local testing / graduation demo.
 * Generates realistic telemetry events across several exams, then runs the
 * Aggregator so dashboards are populated immediately.
 *
 * Usage:  php scripts/seed_demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

$firstNames = [
    'أحمد', 'محمد', 'عمر', 'خالد', 'يوسف', 'عبدالله', 'سلمان', 'فهد', 'ماجد', 'ناصر',
    'فاطمة', 'سارة', 'نورة', 'ريم', 'لمى', 'دانة', 'هند', 'أمل', 'شهد', 'جود',
    'إبراهيم', 'حسن', 'حسين', 'طلال', 'بندر', 'مشعل', 'صالح', 'تركي', 'سعود', 'نايف',
    'الجوهرة', 'العنود', 'مشاعل', 'أسماء', 'مريم', 'وجدان', 'رهف', 'غلا', 'لين', 'يارا',
];
$lastNames = ['الزهراني', 'القحطاني', 'العتيبي', 'المطيري', 'الغامدي', 'الدوسري', 'الشهري', 'السبيعي', 'الشمري', 'الحربي', 'الجهني', 'العنزي'];

$exams = [
    ['quiz_id' => 1, 'course_id' => 101, 'cmid' => 11, 'name' => 'قواعد البيانات',       'students' => 120],
    ['quiz_id' => 2, 'course_id' => 102, 'cmid' => 22, 'name' => 'شبكات الحاسوب',        'students' => 90],
    ['quiz_id' => 3, 'course_id' => 103, 'cmid' => 33, 'name' => 'الخوارزميات',          'students' => 65],
    ['quiz_id' => 4, 'course_id' => 104, 'cmid' => 44, 'name' => 'هندسة البرمجيات',      'students' => 75],
];

$profiles = ['safe', 'safe', 'safe', 'safe', 'low', 'low', 'low', 'medium', 'medium', 'high', 'high', 'critical'];

function makeEvent(array $student, array $quiz, string $sessionId, int &$seq, string $eventType, int $elapsedMs, array $metadata = []): array
{
    static $eventNo = 0;
    $ts = gmdate('Y-m-d\TH:i:s.v\Z', $quiz['start_ts'] + intdiv($elapsedMs, 1000));
    return [
        'schema_version'   => '1.0',
        'event_id'         => 'evt_' . (++$eventNo) . '_' . substr(md5($sessionId . $seq), 0, 8),
        'session_id'       => $sessionId,
        'sequence_number'  => ++$seq,
        'event_type'       => $eventType,
        'timestamp'        => $ts,
        'elapsed_ms'       => $elapsedMs,
        'source'           => ['layer' => 'browser_side', 'component' => 'moodle_quiz_monitor', 'plugin' => 'quizaccess_exammonitor'],
        'moodle'           => [
            'student' => ['id' => $student['id'], 'fullname' => $student['fullname'], 'username' => $student['username']],
            'quiz'    => [
                'id' => $quiz['quiz_id'], 'name' => $quiz['name'], 'attempt_id' => $student['attempt_id'],
                'course_id' => $quiz['course_id'], 'cmid' => $quiz['cmid'],
            ],
        ],
        'browser'          => [
            'url' => 'https://moodle.local/mod/quiz/attempt.php?attempt=' . $student['attempt_id'] . '&cmid=' . $quiz['cmid'],
            'title' => $quiz['name'],
            'visibility_state' => 'visible',
            'has_focus' => true,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'language' => 'ar',
            'platform' => 'Win32',
        ],
        'network'          => ['online' => true, 'connection_supported' => true, 'effective_type' => '4g', 'downlink' => 5.2, 'rtt' => 40, 'save_data' => false],
        'metadata'         => $metadata,
    ];
}

$start = time() - 60 * 90;
$allEvents = [];
$studentCounter = 0;

foreach ($exams as $exam) {
    $exam['start_ts'] = $start + mt_rand(-3600, 0);
    $durationMs = 50 * 60 * 1000; // 50 min exam
    $nStudents = $exam['students'];

    out("توليد بيانات امتحان: {$exam['name']} ({$nStudents} طالب)");

    for ($s = 0; $s < $nStudents; $s++) {
        $studentCounter++;
        $student = [
            'id' => $studentCounter + 1000,
            'fullname' => $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)],
            'username' => 'stu' . (2024100 + $studentCounter),
            'attempt_id' => $s + 1 + ($exam['quiz_id'] * 1000),
        ];

        $sessionId = 'sess_' . mt_rand(100000, 999999) . '_' . substr(md5($student['username'] . $exam['quiz_id']), 0, 8);
        $seq = 0;
        $events = [];
        $profile = $profiles[array_rand($profiles)];

        // --- Baseline presence events ----------------------------------
        $events[] = makeEvent($student, $exam, $sessionId, $seq, 'window_focus', 200, ['reason' => 'browser_window_gained_focus']);
        $events[] = makeEvent($student, $exam, $sessionId, $seq, 'network_online', 300, ['reason' => 'browser_detected_online_status']);
        for ($h = 1; $h <= 45; $h++) {
            $events[] = makeEvent($student, $exam, $sessionId, $seq, 'heartbeat', $h * 60000);
            if ($h % 3 === 0) {
                $events[] = makeEvent($student, $exam, $sessionId, $seq, 'mouse_summary', $h * 60000, ['clicks' => mt_rand(0, 3), 'distance_px' => mt_rand(50, 900)]);
            }
        }
        // A few answer changes during the exam
        for ($q = 1; $q <= 12; $q++) {
            $events[] = makeEvent($student, $exam, $sessionId, $seq, 'answer_changed', mt_rand(20000, 45 * 60000), [
                'question' => ['question_dom_id' => 'q-' . $q, 'question_number' => (string)$q, 'question_type' => 'multichoice'],
                'answer' => ['field_name' => 'q' . $q, 'field_id' => 'id_q' . $q, 'field_tag' => 'input', 'field_type' => 'radio', 'checked' => true, 'answer_value' => (string)mt_rand(1, 4)],
            ]);
        }

        // --- Suspicious behavior by profile -----------------------------
        $tabHiddenCount = 0;
        switch ($profile) {
            case 'low':
                $tabHiddenCount = mt_rand(1, 2);
                break;
            case 'medium':
                $tabHiddenCount = mt_rand(3, 5);
                $events[] = makeEvent($student, $exam, $sessionId, $seq, 'copy', mt_rand(5 * 60000, 40 * 60000), ['action' => 'copy_detected']);
                $events[] = makeEvent($student, $exam, $sessionId, $seq, 'right_click', mt_rand(8 * 60000, 42 * 60000), ['action' => 'context_menu_opened']);
                break;
            case 'high':
                $tabHiddenCount = mt_rand(6, 9);
                for ($i = 0; $i < 3; $i++) {
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'copy', mt_rand(3 * 60000, 40 * 60000), ['action' => 'copy_detected']);
                }
                for ($i = 0; $i < 2; $i++) {
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'paste', mt_rand(4 * 60000, 42 * 60000), ['action' => 'paste_detected']);
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'right_click', mt_rand(6 * 60000, 44 * 60000), ['action' => 'context_menu_opened']);
                }
                $events[] = makeEvent($student, $exam, $sessionId, $seq, 'window_blur', mt_rand(10 * 60000, 43 * 60000), ['reason' => 'browser_window_lost_focus']);
                break;
            case 'critical':
                $tabHiddenCount = mt_rand(10, 14);
                for ($i = 0; $i < 5; $i++) {
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'copy', mt_rand(2 * 60000, 45 * 60000), ['action' => 'copy_detected']);
                }
                for ($i = 0; $i < 4; $i++) {
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'paste', mt_rand(3 * 60000, 46 * 60000), ['action' => 'paste_detected']);
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'right_click', mt_rand(5 * 60000, 47 * 60000), ['action' => 'context_menu_opened']);
                }
                for ($i = 0; $i < 3; $i++) {
                    $events[] = makeEvent($student, $exam, $sessionId, $seq, 'window_blur', mt_rand(6 * 60000, 46 * 60000), ['reason' => 'browser_window_lost_focus']);
                }
                $events[] = makeEvent($student, $exam, $sessionId, $seq, 'page_leave', 47 * 60000, ['reason' => 'page_beforeunload']);
                break;
        }

        // tab switches with durations
        for ($i = 0; $i < $tabHiddenCount; $i++) {
            $t = mt_rand(4 * 60000, 44 * 60000);
            $durSec = mt_rand(5, $profile === 'critical' ? 240 : 90);
            $events[] = makeEvent($student, $exam, $sessionId, $seq, 'tab_hidden', $t, ['reason' => 'document_visibility_changed_to_hidden']);
            $events[] = makeEvent($student, $exam, $sessionId, $seq, 'tab_hidden_duration', $t + $durSec * 1000, ['duration_ms' => $durSec * 1000]);
            $events[] = makeEvent($student, $exam, $sessionId, $seq, 'tab_visible', $t + $durSec * 1000 + 50, ['reason' => 'document_visibility_changed_to_visible']);
        }

        usort($events, fn($a, $b) => $a['elapsed_ms'] <=> $b['elapsed_ms']);
        $allEvents = array_merge($allEvents, $events);
    }
}

out('عدد الأحداث المُولَّدة: ' . number_format(count($allEvents)));

// Insert in batches through the real ingest path (lossless append-only).
$startT = microtime(true);
$accepted = 0;
$skipped = 0;
foreach (array_chunk($allEvents, 500) as $chunk) {
    $res = Ingest::ingestPayload($chunk);
    $accepted += $res['accepted'];
    $skipped += $res['skipped'];
}
out("تم إدراج $accepted حدث (تخطى $skipped) في " . round(microtime(true) - $startT, 2) . ' ثانية.');

out('تشغيل العامل (Aggregator) لتوليد الملخصات والدرجات ...');
$res = Aggregator::process(5000);
out(json_encode($res));
out('اكتمل توليد البيانات التجريبية. سجّل الدخول وشاهد النتائج.');
