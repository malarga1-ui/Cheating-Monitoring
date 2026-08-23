<?php
/**
 * Student analytics endpoints (tenant-scoped by account).
 */
final class StudentController
{
    /** Global student list with aggregate counters + risk, scoped by account. */
    public static function list(): void
    {
        Auth::requireLogin();
        $scopeSs = Auth::accountFilterSql('ss');
        $scopeSt = Auth::accountFilterSql('st');
        $scopeE  = Auth::accountFilterSql('e');

        $search = trim((string)($_GET['q'] ?? ''));
        $risk   = (string)($_GET['risk'] ?? '');

        $where = [];
        $params = [];
        if ($scopeSs)  $where[] = $scopeSs;
        if ($scopeSt)  $where[] = $scopeSt;
        if ($scopeE)   $where[] = $scopeE;
        if ($search !== '') {
            $where[] = '(st.fullname LIKE ? OR st.username LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($risk !== '' && in_array($risk, ['safe', 'low', 'medium', 'high', 'critical'], true)) {
            $where[] = 'overall.risk_level = ?';
            $params[] = $risk;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = 'SELECT st.id, st.fullname, st.username, st.first_seen_at, st.last_seen_at,
                       COUNT(DISTINCT ss.session_id) AS sessions_count,
                       SUM(ss.event_count) AS event_count,
                       COUNT(DISTINCT e.id) AS exams_count,
                       SUM(ss.tab_hidden_count) AS tab_hidden_count,
                       SUM(ss.tab_visible_count) AS tab_visible_count,
                       SUM(ss.tab_hidden_duration_ms) AS tab_hidden_duration_ms,
                       SUM(ss.copy_count) AS copy_count,
                       SUM(ss.copy_selection_chars) AS copy_selection_chars,
                       SUM(ss.paste_count) AS paste_count,
                       SUM(ss.right_click_count) AS right_click_count,
                       SUM(ss.blur_count) AS blur_count,
                       SUM(ss.page_leave_count) AS page_leave_count,
                       SUM(ss.offline_count) AS offline_count,
                       SUM(ss.devtools_count) AS devtools_count,
                       SUM(ss.suspicious_key_count) AS suspicious_key_count,
                       SUM(ss.screenshot_count) AS screenshot_count,
                       SUM(ss.rapid_answer_changes) AS rapid_answer_changes,
                       SUM(ss.idle_count) AS idle_count,
                       SUM(ss.idle_duration_ms) AS idle_duration_ms,
                       SUM(ss.fullscreen_exit_count) AS fullscreen_exit_count,
                       SUM(ss.typing_keydown_count) AS typing_keydown_count,
                       SUM(ss.typing_backspace_count) AS typing_backspace_count,
                       SUM(ss.typing_enter_count) AS typing_enter_count,
                       SUM(ss.mouse_click_count) AS mouse_click_count,
                       SUM(ss.mouse_move_count) AS mouse_move_count,
                       SUM(ss.mouse_scroll_count) AS mouse_scroll_count,
                       MIN(ss.first_event_at) AS first_event_at,
                       MAX(ss.last_event_at) AS last_event_at
                FROM session_summaries ss
                JOIN students st ON st.id = ss.student_id
                JOIN exams e ON e.id = ss.exam_id' . $whereSql . '
                GROUP BY st.id, st.fullname, st.username, st.first_seen_at, st.last_event_at';

        $rows = Database::fetchAll(
            'SELECT * FROM (' . $sql . ') overall WHERE ' . $whereSql . ' ORDER BY last_event_at DESC LIMIT 1000',
            $params
        );

        $students = [];
        foreach ($rows as $r) {
            $counters = Analytics::countersFromRow($r);
            $riskResult = RiskEngine::score($counters);
            $students[] = [
                'student_id' => (int)$r['id'],
                'fullname' => $r['fullname'],
                'username' => $r['username'],
                'sessions_count' => (int)$r['sessions_count'],
                'event_count' => (int)$r['event_count'],
                'exams_count' => (int)$r['exams_count'],
                'first_seen_at' => $r['first_seen_at'],
                'last_seen_at' => $r['last_event_at'],
                'risk_score' => $riskResult['score'],
                'risk_level' => $riskResult['level'],
                'risk_label' => RiskEngine::labelAr($riskResult['level']),
                'totals' => array_merge($counters, [
                    'event_count' => (int)$r['event_count'],
                    'exams_count' => (int)$r['exams_count'],
                ]),
            ];
        }

        Response::ok($students);
    }

    public static function profile(int $id): void
    {
        Auth::requireLogin();

        $student = Database::fetchOne('SELECT * FROM students WHERE id = ?', [$id]);
        if (!$student) {
            Response::error('الطالب غير موجود', 404);
        }
        Auth::requireRowAccess($student);

        // A supervisor may only see this student's exams within their granted
        // courses (requireRowAccess already ensured the student has at least
        // one session there).
        $courseWhere = Auth::isSupervisor()
            ? (' AND e.moodle_course_id IN ' . Auth::supervisorCoursesInSql())
            : '';

        // Per-exam aggregates for this student (scoped by account_id + supervisor courses).
        $params = [$id];
        $accountFilter = '';
        $accountId = Auth::accountId();
        if ($accountId > 0) {
            $accountFilter = ' AND ss.account_id = ?';
            $params[] = $accountId;
        }

        $rows = Database::fetchAll(
            'SELECT ss.exam_id, e.moodle_quiz_id, e.name AS exam_name, e.status,
                    e.duration_minutes,
                    COUNT(DISTINCT ss.session_id) AS sessions_count,
                    SUM(ss.event_count) AS event_count,
                    SUM(ss.tab_hidden_count) AS tab_hidden_count,
                    SUM(ss.tab_visible_count) AS tab_visible_count,
                    SUM(ss.tab_hidden_duration_ms) AS tab_hidden_duration_ms,
                    SUM(ss.copy_count) AS copy_count,
                    SUM(ss.copy_selection_chars) AS copy_selection_chars,
                    SUM(ss.paste_count) AS paste_count,
                    SUM(ss.right_click_count) AS right_click_count,
                    SUM(ss.blur_count) AS blur_count,
                    SUM(ss.page_leave_count) AS page_leave_count,
                    SUM(ss.offline_count) AS offline_count,
                    SUM(ss.answer_changed_count) AS answer_changed_count,
                    SUM(ss.devtools_count) AS devtools_count,
                    SUM(ss.suspicious_key_count) AS suspicious_key_count,
                    SUM(ss.screenshot_count) AS screenshot_count,
                    SUM(ss.rapid_answer_changes) AS rapid_answer_changes,
                    SUM(ss.idle_count) AS idle_count,
                    SUM(ss.idle_duration_ms) AS idle_duration_ms,
                    SUM(ss.fullscreen_exit_count) AS fullscreen_exit_count,
                    SUM(ss.typing_keydown_count) AS typing_keydown_count,
                    SUM(ss.typing_backspace_count) AS typing_backspace_count,
                    SUM(ss.typing_enter_count) AS typing_enter_count,
                    SUM(ss.mouse_click_count) AS mouse_click_count,
                    SUM(ss.mouse_move_count) AS mouse_move_count,
                    SUM(ss.mouse_scroll_count) AS mouse_scroll_count,
                    MIN(ss.first_event_at) AS first_event_at,
                    MAX(ss.last_event_at) AS last_event_at,
                    MAX(ss.same_ip_student_count) AS same_ip_student_count,
                    MAX(ss.ip_changed_count) AS ip_changed_count,
                    MAX(ss.same_ip_risk_score) AS same_ip_risk_score,
                    MAX(ss.ai_suspect_score) AS ai_suspect_score,
                    MAX(ss.typing_answer_ratio) AS typing_answer_ratio,
                    MAX(ss.similarity_max_score) AS similarity_max_score,
                    GROUP_CONCAT(DISTINCT ss.ip_address ORDER BY ss.ip_address SEPARATOR \', \') AS ip_addresses,
                    (SELECT COUNT(*) FROM answer_records ar WHERE ar.exam_id = ss.exam_id AND ar.student_id = ss.student_id) AS answer_count,
                    (SELECT ROUND(AVG(ar.ai_score)) FROM answer_records ar WHERE ar.exam_id = ss.exam_id AND ar.student_id = ss.student_id AND ar.ai_score > 0) AS avg_ai_per_question
             FROM session_summaries ss
             JOIN exams e ON e.id = ss.exam_id
             WHERE ss.student_id = ?' . $accountFilter . $courseWhere . '
             GROUP BY ss.exam_id, e.moodle_quiz_id, e.name, e.status, e.duration_minutes',
            $params
        );

        $exams = [];

        foreach ($rows as $r) {
            $counters = [
                'tab_hidden_count' => (int)$r['tab_hidden_count'],
                'tab_visible_count' => (int)$r['tab_visible_count'],
                'tab_hidden_duration_ms' => (int)$r['tab_hidden_duration_ms'],
                'copy_count' => (int)$r['copy_count'],
                'copy_selection_chars' => (int)$r['copy_selection_chars'],
                'paste_count' => (int)$r['paste_count'],
                'right_click_count' => (int)$r['right_click_count'],
                'blur_count' => (int)$r['blur_count'],
                'page_leave_count' => (int)$r['page_leave_count'],
                'offline_count' => (int)$r['offline_count'],
                'devtools_count' => (int)$r['devtools_count'],
                'suspicious_key_count' => (int)$r['suspicious_key_count'],
                'screenshot_count' => (int)$r['screenshot_count'],
                'rapid_answer_changes' => (int)$r['rapid_answer_changes'],
                'idle_count' => (int)$r['idle_count'],
                'idle_duration_ms' => (int)$r['idle_duration_ms'],
                'fullscreen_exit_count' => (int)$r['fullscreen_exit_count'],
                'typing_keydown_count' => (int)$r['typing_keydown_count'],
                'typing_backspace_count' => (int)$r['typing_backspace_count'],
                'typing_enter_count' => (int)$r['typing_enter_count'],
                'mouse_click_count' => (int)$r['mouse_click_count'],
                'mouse_move_count' => (int)$r['mouse_move_count'],
                'mouse_scroll_count' => (int)$r['mouse_scroll_count'],
            ];
            $risk = RiskEngine::score($counters);

            $exams[] = [
                'exam_id' => (int)$r['exam_id'],
                'moodle_quiz_id' => (int)$r['moodle_quiz_id'],
                'exam_name' => $r['exam_name'],
                'status' => $r['status'],
                'duration_minutes' => (int)($r['duration_minutes'] ?? 0),
                'sessions_count' => (int)$r['sessions_count'],
                'event_count' => (int)$r['event_count'],
                'first_event_at' => $r['first_event_at'],
                'last_event_at' => $r['last_event_at'],
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'risk_label' => RiskEngine::labelAr($risk['level']),
                'ip_addresses' => $r['ip_addresses'] ?? '',
                'same_ip_student_count' => (int)($r['same_ip_student_count'] ?? 0),
                'ip_changed_count' => (int)($r['ip_changed_count'] ?? 0),
                'ai_suspect_score' => (int)($r['ai_suspect_score'] ?? 0),
                'avg_ai_per_question' => (int)($r['avg_ai_per_question'] ?? 0),
                'answer_count' => (int)($r['answer_count'] ?? 0),
                'similarity_max_score' => (int)($r['similarity_max_score'] ?? 0),
                'typing_answer_ratio' => (float)($r['typing_answer_ratio'] ?? 0),
                'categories' => $risk['categories'],
            ];
        }

        Response::ok([
            'student' => [
                'id' => (int)$student['id'],
                'fullname' => $student['fullname'],
                'username' => $student['username'],
                'first_seen_at' => $student['first_seen_at'],
                'last_seen_at' => $student['last_seen_at'],
            ],
            'exams' => $exams,
        ]);
    }

    public static function sessions(int $id): void
    {
        Auth::requireLogin();

        $student = Database::fetchOne('SELECT id FROM students WHERE id = ?', [$id]);
        if (!$student) {
            Response::error('الطالب غير موجود', 404);
        }
        Auth::requireRowAccess($student);

        $scopeSs = Auth::accountFilterSql('ss');
        $scopeE  = Auth::accountFilterSql('e');
        $where = ['ss.student_id = ?'];
        $params = [$id];
        if ($scopeSs) $where[] = $scopeSs;
        if ($scopeE)  $where[] = $scopeE;
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = Database::fetchAll(
            'SELECT ss.session_id, ss.exam_id, e.name AS exam_name, e.status,
                    ss.event_count, ss.first_event_at, ss.last_event_at, ss.risk_score, ss.risk_level,
                    ss.tab_hidden_count, ss.tab_visible_count, ss.tab_hidden_duration_ms,
                    ss.copy_count, ss.copy_selection_chars, ss.paste_count, ss.right_click_count,
                    ss.blur_count, ss.page_leave_count, ss.offline_count, ss.answer_changed_count,
                    ss.devtools_count, ss.suspicious_key_count, ss.screenshot_count, ss.rapid_answer_changes,
                    ss.idle_count, ss.idle_duration_ms, ss.fullscreen_exit_count,
                    ss.typing_keydown_count, ss.typing_backspace_count, ss.typing_enter_count,
                    ss.mouse_click_count, ss.mouse_move_count, ss.mouse_scroll_count
             FROM session_summaries ss
             JOIN exams e ON e.id = ss.exam_id
             ' . $whereSql . '
             ORDER BY ss.last_event_at DESC
             LIMIT 500',
            $params
        );

        Response::ok(array_map(function ($r) {
            return [
                'session_id' => $r['session_id'],
                'exam_id' => (int)$r['exam_id'],
                'exam_name' => $r['exam_name'],
                'status' => $r['status'],
                'event_count' => (int)$r['event_count'],
                'first_event_at' => $r['first_event_at'],
                'last_event_at' => $r['last_event_at'],
                'risk_score' => (int)$r['risk_score'],
                'risk_level' => $r['risk_level'],
                'counters' => [
                    'tab_hidden_count' => (int)$r['tab_hidden_count'],
                    'tab_visible_count' => (int)$r['tab_visible_count'],
                    'tab_hidden_duration_ms' => (int)$r['tab_hidden_duration_ms'],
                    'copy_count' => (int)$r['copy_count'],
                    'copy_selection_chars' => (int)$r['copy_selection_chars'],
                    'paste_count' => (int)$r['paste_count'],
                    'right_click_count' => (int)$r['right_click_count'],
                    'blur_count' => (int)$r['blur_count'],
                    'page_leave_count' => (int)$r['page_leave_count'],
                    'offline_count' => (int)$r['offline_count'],
                    'answer_changed_count' => (int)$r['answer_changed_count'],
                    'devtools_count' => (int)$r['devtools_count'],
                    'suspicious_key_count' => (int)$r['suspicious_key_count'],
                    'screenshot_count' => (int)$r['screenshot_count'],
                    'rapid_answer_changes' => (int)$r['rapid_answer_changes'],
                    'idle_count' => (int)$r['idle_count'],
                    'idle_duration_ms' => (int)$r['idle_duration_ms'],
                    'fullscreen_exit_count' => (int)$r['fullscreen_exit_count'],
                    'typing_keydown_count' => (int)$r['typing_keydown_count'],
                    'typing_backspace_count' => (int)$r['typing_backspace_count'],
                    'typing_enter_count' => (int)$r['typing_enter_count'],
                    'mouse_click_count' => (int)$r['mouse_click_count'],
                    'mouse_move_count' => (int)$r['mouse_move_count'],
                    'mouse_scroll_count' => (int)$r['mouse_scroll_count'],
                ],
            ];
        }, $rows));
    }

    public static function events(int $id): void
    {
        Auth::requireLogin();

        $student = Database::fetchOne('SELECT id FROM students WHERE id = ?', [$id]);
        if (!$student) {
            Response::error('الطالب غير موجود', 404);
        }
        Auth::requireRowAccess($student);

        $examId = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : null;
        $limit = min(2000, max(1, (int)($_GET['limit'] ?? 500)));

        $where = ['st.id = ?'];
        $params = [$id];

        $scopeEv = Auth::accountFilterSql('ev');
        if ($scopeEv) $where[] = $scopeEv;

        if ($examId !== null) {
            $exam = Database::fetchOne('SELECT moodle_quiz_id FROM exams WHERE id = ?', [$examId]);
            if (!$exam) {
                Response::error('الامتحان غير موجود', 404);
            }
            Auth::requireRowAccess($exam);
            $where[] = 'ev.moodle_quiz_id = ?';
            $params[] = (int)$exam['moodle_quiz_id'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $sql = 'SELECT ev.event_type, ev.event_time, ev.duration_ms, ev.elapsed_ms, ev.session_id, ev.payload
                FROM events ev
                JOIN students st ON st.moodle_user_id = ev.moodle_user_id
                ' . $whereSql . '
                ORDER BY ev.event_time ASC LIMIT ' . (int)$limit;

        $rows = Database::fetchAll($sql, $params);

        Response::ok(array_map(function ($r) {
            $payload = json_decode($r['payload'], true) ?: [];
            $metadata = $payload['metadata'] ?? null;
            $browser = $payload['browser'] ?? null;
            $network = $payload['network'] ?? null;
            $moodle = $payload['moodle'] ?? null;
            return [
                'event_type' => $r['event_type'],
                'event_time' => $r['event_time'],
                'duration_ms' => $r['duration_ms'] !== null ? (int)$r['duration_ms'] : null,
                'elapsed_ms' => $r['elapsed_ms'] !== null ? (int)$r['elapsed_ms'] : null,
                'session_id' => $r['session_id'],
                'metadata' => is_array($metadata) ? $metadata : null,
                'url' => $browser['url'] ?? null,
                'browser' => is_array($browser) ? $browser : null,
                'network' => is_array($network) ? $network : null,
                'moodle' => is_array($moodle) ? $moodle : null,
            ];
        }, $rows));
    }

    /** Get answer records + AI scores for a student in a specific exam. */
    public static function examAnswers(int $id, int $examId): void
    {
        Auth::requireLogin();

        $student = Database::fetchOne('SELECT id, fullname, username FROM students WHERE id = ?', [$id]);
        if (!$student) {
            Response::error('الطالب غير موجود', 404);
        }
        Auth::requireRowAccess($student);

        $exam = Database::fetchOne(
            'SELECT id, name, duration_minutes, moodle_course_id FROM exams WHERE id = ?',
            [$examId]
        );
        if (!$exam) {
            Response::error('الامتحان غير موجود', 404);
        }
        Auth::requireRowAccess($exam);

        // Get answer records — deduplicated: latest per question_id
        $answers = Database::fetchAll(
            'SELECT ar.id, ar.session_id, ar.question_id, ar.question_type,
                    ar.answer_text, ar.answer_length, ar.word_count,
                    ar.typing_duration_ms, ar.change_count, ar.ai_score,
                    ar.ai_detection_provider, ar.ai_detection_status, ar.ai_detected_at,
                    ar.created_at
             FROM answer_records ar
             INNER JOIN (
                 SELECT MAX(id) AS max_id
                 FROM answer_records
                 WHERE exam_id = ? AND student_id = ? AND account_id = ?
                 GROUP BY question_id
             ) latest ON ar.id = latest.max_id
             ORDER BY ar.question_id ASC, ar.created_at ASC',
            [$examId, $id, Auth::accountId()]
        );

        // Get sessions for this student in this exam
        $sessions = Database::fetchAll(
            'SELECT ss.session_id, ss.first_event_at, ss.last_event_at, ss.event_count,
                    ss.risk_score, ss.risk_level, ss.ai_suspect_score,
                    ss.same_ip_student_count, ss.ip_changed_count, ss.same_ip_risk_score,
                    ss.similarity_max_score, ss.ip_address
             FROM session_summaries ss
             WHERE ss.exam_id = ? AND ss.student_id = ? AND ss.account_id = ?
             ORDER BY ss.first_event_at',
            [$examId, $id, Auth::accountId()]
        );

        // Stats
        $totalQuestions = count($answers);
        $aiScores = array_filter(array_column($answers, 'ai_score'), fn($s) => $s > 0);
        $avgAi = $totalQuestions > 0 ? round(array_sum(array_column($answers, 'ai_score')) / $totalQuestions) : 0;

        $sessionDuration = 0;
        if (!empty($sessions)) {
            $first = strtotime($sessions[0]['first_event_at']);
            $last = strtotime(end($sessions)['last_event_at']);
            $sessionDuration = max(0, $last - $first);
        }

        $durationMin = (int)($exam['duration_minutes'] ?? 0);
        $speedRatio = 0;
        if ($durationMin > 0 && $sessionDuration > 0) {
            $speedRatio = round($sessionDuration / ($durationMin * 60), 2);
        }

        Response::ok([
            'student' => [
                'id'       => (int)$student['id'],
                'fullname' => $student['fullname'],
                'username' => $student['username'],
            ],
            'exam' => [
                'id'               => (int)$exam['id'],
                'name'             => $exam['name'],
                'duration_minutes' => $durationMin,
            ],
            'answers' => array_map(fn($a) => [
                'id'                   => (int)$a['id'],
                'session_id'           => $a['session_id'],
                'question_id'          => $a['question_id'],
                'question_type'        => $a['question_type'],
                'answer_text'          => $a['answer_text'],
                'answer_length'        => (int)$a['answer_length'],
                'word_count'           => (int)$a['word_count'],
                'typing_duration_ms'   => (int)$a['typing_duration_ms'],
                'change_count'         => (int)$a['change_count'],
                'ai_score'             => (int)$a['ai_score'],
                'ai_detection_provider' => $a['ai_detection_provider'] ?? '',
                'ai_detection_status'  => $a['ai_detection_status'] ?? '',
                'ai_detected_at'       => $a['ai_detected_at'] ?? null,
                'paste_text'           => $a['paste_text'] ?? null,
                'paste_length'         => (int)($a['paste_length'] ?? 0),
                'copy_count_from_question' => (int)($a['copy_count_from_question'] ?? 0),
                'copy_text'            => $a['copy_text'] ?? null,
                'created_at'           => $a['created_at'],
            ], $answers),
            'sessions' => array_map(fn($s) => [
                'session_id'             => $s['session_id'],
                'first_event_at'         => $s['first_event_at'],
                'last_event_at'          => $s['last_event_at'],
                'event_count'            => (int)$s['event_count'],
                'risk_score'             => (int)$s['risk_score'],
                'risk_level'             => $s['risk_level'],
                'ai_suspect_score'       => (int)$s['ai_suspect_score'],
                'same_ip_student_count'  => (int)$s['same_ip_student_count'],
                'ip_changed_count'       => (int)$s['ip_changed_count'],
                'same_ip_risk_score'     => (int)$s['same_ip_risk_score'],
                'similarity_max_score'   => (int)$s['similarity_max_score'],
                'ip_address'             => $s['ip_address'],
            ], $sessions),
            'stats' => [
                'total_questions'  => $totalQuestions,
                'avg_ai_score'     => $avgAi,
                'questions_with_ai'=> count($aiScores),
                'session_duration' => $sessionDuration,
                'speed_ratio'      => $speedRatio,
                'duration_minutes' => $durationMin,
            ],
        ]);
    }
}