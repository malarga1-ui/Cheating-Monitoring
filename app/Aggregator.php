<?php
/**
 * Background Aggregator - READ/ANALYSIS PATH.
 *
 * Runs on a schedule (cron every 1-2 minutes) and incrementally processes
 * the raw `events` store using a watermark, producing the aggregated tables
 * (students, exams, sessions, session_summaries + risk scores).
 *
 * At-least-once semantics: the watermark advances per batch and every upsert
 * is idempotent, so reprocessing after a crash is always safe.
 */
final class Aggregator
{
    private static ?PDO $pdo = null;
    private static ?PDOStatement $upsertStudent = null;
    private static ?PDOStatement $upsertExam = null;
    private static ?PDOStatement $upsertSession = null;
    private static ?PDOStatement $upsertCourse = null;
    private static ?PDOStatement $upsertCourseTeacher = null;

    /** Event type -> counter column. */
    private const COUNTER_MAP = [
        'tab_hidden'            => 'tab_hidden_count',
        'tab_visible'           => 'tab_visible_count',
        'copy'                  => 'copy_count',
        'paste'                 => 'paste_count',
        'right_click'           => 'right_click_count',
        'window_blur'           => 'blur_count',
        'window_focus'          => 'focus_count',
        'page_leave'            => 'page_leave_count',
        'network_offline'       => 'offline_count',
        'answer_changed'        => 'answer_changed_count',
        'devtools_shortcut'     => 'devtools_count',
        'screenshot_attempt'    => 'screenshot_count',
        'idle_detected'         => 'idle_count',
        'idle_end'              => null,
        'fullscreen_exit'       => 'fullscreen_exit_count',
        'activity_summary'      => null,
        'typing_summary'        => null,
        'mouse_summary'         => null,
        'heartbeat'             => null,
        'selection_detected'    => null,
        'cut'                   => 'copy_count',
        'print_attempt'         => 'suspicious_key_count',
        'network_online'        => null,
        'typing_answer'         => null,
        'answer_focused'        => null,
        'ip_snapshot'           => null,
        'keystroke_dynamics'    => null,
    ];

    /**
     * Process up to $maxEvents unprocessed events.
     *
     * @return array{processed:int, batches:int, last_id:int, lag:int}
     */
    public static function process(int $batchSize = 2000, ?int $maxEvents = null): array
    {
        $watermark = (int)Database::scalar('SELECT last_event_id FROM agg_watermark WHERE id = 1');
        $maxId = (int)Database::scalar('SELECT COALESCE(MAX(id), 0) FROM events WHERE id > ?', [$watermark]);

        if ($maxId === 0) {
            return ['processed' => 0, 'batches' => 0, 'last_id' => $watermark, 'lag' => 0];
        }

        if ($maxEvents !== null && $maxEvents > 0) {
            $maxId = min($maxId, $watermark + $maxEvents);
        }

        $processed = 0;
        $batches = 0;
        $cursor = $watermark;

        self::$pdo = Database::connection();
        self::prepareStatements();

        $sessionCounters = []; // accumulated across batches in this run

        while ($cursor < $maxId) {
            $events = Database::fetchAll(
                'SELECT id, account_id, session_id, event_type, moodle_user_id, moodle_quiz_id,
                        moodle_course_id, moodle_cmid, attempt_id, event_time, duration_ms, payload, ip_address
                 FROM events WHERE id > ? ORDER BY id ASC LIMIT ' . (int)$batchSize,
                [$cursor]
            );
            if (!$events) {
                break;
            }

            $lastId = (int)$events[count($events) - 1]['id'];
            self::accumulateBatch($events, $sessionCounters);

            Database::execute('UPDATE agg_watermark SET last_event_id = ? WHERE id = 1', [$lastId]);
            $cursor = $lastId;
            $processed += count($events);
            $batches++;

            if ($maxEvents !== null && $processed >= $maxEvents) {
                break;
            }
        }

        // Flush each session once, with counters fully accumulated for this run.
        foreach ($sessionCounters as $sessionId => $counters) {
            self::upsertSession($sessionId, $counters);
            self::flushSummary($sessionId, $counters);
        }

        // ── v9: Run advanced analytics (network, AI, similarity) ──
        self::runAdvancedAnalytics($sessionCounters);

        return ['processed' => $processed, 'batches' => $batches, 'last_id' => $cursor, 'lag' => max(0, $maxId - $cursor)];
    }

    // ---------------------------------------------------------------

    private static function accumulateBatch(array $events, array &$sessionCounters): void
    {
        $students = []; // moodle_user_id => student info
        $exams = [];    // moodle_quiz_id => exam info
        $courseTeachers = []; // "account:course:teacher" => info
        $normalized = [];

        foreach ($events as $ev) {
            $payload = json_decode($ev['payload'], true) ?: [];
            $student = $payload['moodle']['student'] ?? [];
            $teacherList = $payload['moodle']['teacher'] ?? [];
            $quiz = $payload['moodle']['quiz'] ?? [];
            $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

            // First teacher by course role order becomes the primary teacher.
            $teacherId = null;
            $teacherName = '';
            if (is_array($teacherList) && $teacherList !== []) {
                $first = reset($teacherList);
                if (is_array($first)) {
                    $teacherId = isset($first['id']) ? (int)$first['id'] : null;
                    $teacherName = em_truncate((string)($first['fullname'] ?? ''), 255);
                    if ($teacherName === '') {
                        $teacherName = em_truncate((string)($first['username'] ?? ''), 255);
                    }
                }
            }

            $n = [
                'account_id'        => (int)$ev['account_id'],
                'moodle_user_id'    => (int)$ev['moodle_user_id'],
                'moodle_quiz_id'    => (int)$ev['moodle_quiz_id'],
                'course_id'         => (int)$ev['moodle_course_id'],
                'cmid'              => (int)$ev['moodle_cmid'],
                'attempt_id'        => $ev['attempt_id'] !== null ? (int)$ev['attempt_id'] : null,
                'event_time'        => $ev['event_time'],
                'duration_ms'       => $ev['duration_ms'] !== null ? (int)$ev['duration_ms'] : null,
                'event_type'        => $ev['event_type'],
                'session_id'        => $ev['session_id'],
                'student_fullname'  => em_truncate($student['fullname'] ?? '', 190),
                'student_username'  => em_truncate($student['username'] ?? '', 190),
                'quiz_name'         => em_truncate($quiz['name'] ?? '', 255),
                'teacher_id'        => $teacherId,
                'teacher_name'      => $teacherName,
                'ip_address'        => em_truncate($ev['ip_address'] ?? '', 45),
                // Value fields extracted from event metadata for accurate analysis.
                'copy_chars'        => self::metaInt($meta, ['selection_length', 'selectionLength']),
                'typing_down'       => self::metaInt($meta, ['keydown_count', 'typing.keydown_count']),
                'typing_back'       => self::metaInt($meta, ['backspace_count', 'typing.backspace_count']),
                'typing_enter'      => self::metaInt($meta, ['enter_count', 'typing.enter_count']),
                'mouse_move'        => self::metaInt($meta, ['move_count', 'mouse.move_count']),
                'mouse_click'       => self::metaInt($meta, ['click_count', 'mouse.click_count']),
                'mouse_scroll'      => self::metaInt($meta, ['scroll_count', 'mouse.scroll_count']),
                'idle_ms'           => self::metaInt($meta, ['idle_duration_ms', 'idleDurationMs']),
            ];

            $students[$n['account_id'] . ':' . $n['moodle_user_id']] = [
                'account_id' => $n['account_id'],
                'fullname' => $n['student_fullname'],
                'username' => $n['student_username'],
                'time' => $n['event_time'],
            ];
            $exams[$n['account_id'] . ':' . $n['moodle_quiz_id']] = [
                'account_id' => $n['account_id'],
                'course_id' => $n['course_id'],
                'cmid' => $n['cmid'],
                'name' => $n['quiz_name'],
                'time' => $n['event_time'],
                'teacher_id' => $n['teacher_id'],
                'teacher_name' => $n['teacher_name'],
                'duration_minutes' => self::metaInt($meta, ['quiz.duration_minutes', 'quiz.durationMinutes']),
            ];

            if ($n['teacher_id'] !== null && $n['teacher_id'] > 0 && $n['course_id'] > 0) {
                $ctKey = $n['account_id'] . ':' . $n['course_id'] . ':' . $n['teacher_id'];
                if (!isset($courseTeachers[$ctKey])) {
                    $courseTeachers[$ctKey] = [
                        'account_id'   => $n['account_id'],
                        'course_id'    => $n['course_id'],
                        'teacher_id'   => $n['teacher_id'],
                        'teacher_name' => $n['teacher_name'],
                    ];
                }
            }

            $normalized[] = $n;
        }

        // Upsert distinct students / exams once per batch, resolving each
        // external Moodle id to its internal primary key via LAST_INSERT_ID().
        $studentIds = [];
        foreach ($students as $key => $info) {
            [, $uid] = array_pad(explode(':', (string)$key, 2), 2, '0');
            self::upsertStudent((int)$info['account_id'], (int)$uid, $info['fullname'], $info['username'], $info['time']);
            $studentIds[$key] = (int)self::$pdo->lastInsertId();
        }
        $examIds = [];
        foreach ($exams as $key => $info) {
            [, $qid] = array_pad(explode(':', (string)$key, 2), 2, '0');
            self::upsertExam(
                (int)$info['account_id'],
                (int)$qid,
                $info['course_id'],
                $info['cmid'],
                $info['name'],
                $info['time'],
                $info['teacher_id'],
                $info['teacher_name'],
                $info['duration_minutes'] ?? 0
            );
            $examIds[$key] = (int)self::$pdo->lastInsertId();
            if ($info['course_id'] > 0) {
                self::upsertCourse((int)$info['account_id'], $info['course_id'], $info['name']);
            }
        }

        // v30: Auto-populate course_teachers from event data so teachers see their courses
        // without requiring a manual Moodle bulk sync.
        foreach ($courseTeachers as $ct) {
            self::upsertCourseTeacher(
                (int)$ct['account_id'],
                (int)$ct['course_id'],
                (int)$ct['teacher_id'],
                $ct['teacher_name']
            );
        }

        // Accumulate with internal ids so sessions / summaries join cleanly.
        foreach ($normalized as $n) {
            $skey = $n['account_id'] . ':' . $n['moodle_user_id'];
            $ekey = $n['account_id'] . ':' . $n['moodle_quiz_id'];
            $n['student_id'] = $studentIds[$skey] ?? 0;
            $n['exam_id'] = $examIds[$ekey] ?? 0;
            unset($n['moodle_user_id'], $n['moodle_quiz_id']);
            self::accumulate($sessionCounters, $n);
        }
    }

    private static function prepareStatements(): void
    {
        if (self::$upsertStudent !== null) {
            return;
        }
        self::$upsertStudent = self::$pdo->prepare(
            'INSERT INTO students (moodle_user_id, account_id, fullname, username, first_seen_at, last_seen_at)
             VALUES (:muid, :account, :fullname, :username, :ts1, :ts2)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               fullname = IF(:fullname_cond <> "", :fullname_set, fullname),
               username = IF(:username_cond <> "", :username_set, username),
               last_seen_at = :ts3,
               id = LAST_INSERT_ID(id)'
        );

        self::$upsertExam = self::$pdo->prepare(
            'INSERT INTO exams (moodle_quiz_id, account_id, moodle_course_id, moodle_cmid, name, moodle_teacher_id, teacher_name, duration_minutes, first_event_at, last_event_at)
             VALUES (:q, :account, :c, :cm, :name, :tid, :tname, :dur, :ts1, :ts2)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               moodle_cmid = :cm2,
               name = IF(:name_cond <> "", :name_set, name),
               moodle_teacher_id = IFNULL(moodle_teacher_id, :tid2),
               teacher_name = IF(teacher_name = "", :tname2, teacher_name),
               duration_minutes = IF(VALUES(duration_minutes) > 0, VALUES(duration_minutes), duration_minutes),
               first_event_at = LEAST(IFNULL(first_event_at, :ts3), :ts4),
               last_event_at = GREATEST(IFNULL(last_event_at, :ts5), :ts6),
               id = LAST_INSERT_ID(id)'
        );

        self::$upsertSession = self::$pdo->prepare(
            'INSERT INTO sessions (session_id, account_id, student_id, exam_id, attempt_id, started_at, last_event_at, event_count)
             VALUES (:sid, :account, :stid, :exid, :att, :ts1, :ts2, :ec1)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               student_id = IFNULL(student_id, :stid2),
               exam_id = IFNULL(exam_id, :exid2),
               attempt_id = IFNULL(attempt_id, :att2),
               last_event_at = GREATEST(IFNULL(last_event_at, :ts3), :ts4),
               ended_at = NULL,
               event_count = event_count + :ec2,
               id = LAST_INSERT_ID(id)'
        );

        self::$upsertCourse = self::$pdo->prepare(
            'INSERT INTO courses (account_id, moodle_course_id, name)
             VALUES (:account, :cid, :name)
             ON DUPLICATE KEY UPDATE
               id = LAST_INSERT_ID(id),
               name = IF(name = "", :name2, name)'
        );

        self::$upsertCourseTeacher = self::$pdo->prepare(
            'INSERT INTO course_teachers (moodle_course_id, moodle_teacher_id, account_id, teacher_name)
             VALUES (:cid, :tid, :account, :tname)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               teacher_name = IF(teacher_name = "", VALUES(teacher_name), teacher_name)'
        );
    }

    private static function upsertStudent(int $accountId, int $uid, string $fullname, string $username, string $time): void
    {
        self::$upsertStudent->execute([
            ':muid' => $uid,
            ':account' => $accountId,
            ':fullname' => $fullname,
            ':username' => $username,
            ':ts1' => $time,
            ':ts2' => $time,
            ':fullname_cond' => $fullname,
            ':fullname_set' => $fullname,
            ':username_cond' => $username,
            ':username_set' => $username,
            ':ts3' => $time,
        ]);
    }

    private static function upsertExam(int $accountId, int $qid, int $courseId, int $cmid, string $name, string $time, ?int $teacherId = null, string $teacherName = '', int $durationMinutes = 0): void
    {
        self::$upsertExam->execute([
            ':q' => $qid,
            ':account' => $accountId,
            ':c' => $courseId,
            ':cm' => $cmid,
            ':name' => $name,
            ':tid' => $teacherId,
            ':tname' => $teacherName,
            ':ts1' => $time,
            ':ts2' => $time,
            ':cm2' => $cmid,
            ':name_cond' => $name,
            ':name_set' => $name,
            ':tid2' => $teacherId,
            ':tname2' => $teacherName,
            ':ts3' => $time,
            ':ts4' => $time,
            ':ts5' => $time,
            ':ts6' => $time,
            ':dur' => $durationMinutes,
        ]);
    }

    private static function upsertCourse(int $accountId, int $courseId, string $name): void
    {
        self::$upsertCourse->execute([
            ':account' => $accountId,
            ':cid' => $courseId,
            ':name' => $name,
            ':name2' => $name,
        ]);
    }

    private static function upsertCourseTeacher(int $accountId, int $courseId, int $teacherId, string $teacherName): void
    {
        self::$upsertCourseTeacher->execute([
            ':account' => $accountId,
            ':cid' => $courseId,
            ':tid' => $teacherId,
            ':tname' => $teacherName,
        ]);
    }

    private static function upsertSession(string $sessionId, array $c): void
    {
        self::$upsertSession->execute([
            ':sid' => $sessionId,
            ':account' => $c['account_id'],
            ':stid' => $c['student_id'],
            ':exid' => $c['exam_id'],
            ':att' => $c['attempt_id'],
            ':ts1' => $c['first_event_at'],
            ':ts2' => $c['last_event_at'],
            ':ec1' => $c['event_count'],
            ':stid2' => $c['student_id'],
            ':exid2' => $c['exam_id'],
            ':att2' => $c['attempt_id'],
            ':ts3' => $c['last_event_at'],
            ':ts4' => $c['last_event_at'],
            ':ec2' => $c['event_count'],
        ]);
    }

    private static function accumulate(array &$sessionCounters, array $n): void
    {
        $sid = $n['session_id'];
        if (!isset($sessionCounters[$sid])) {
            $sessionCounters[$sid] = [
                'account_id'             => $n['account_id'],
                'student_id'             => $n['student_id'],
                'exam_id'                => $n['exam_id'],
                'attempt_id'             => $n['attempt_id'],
                'first_event_at'         => $n['event_time'],
                'last_event_at'          => $n['event_time'],
                'event_count'            => 0,
                'tab_hidden_count'       => 0,
                'tab_visible_count'      => 0,
                'tab_hidden_duration_ms' => 0,
                'copy_count'             => 0,
                'copy_selection_chars'   => 0,
                'paste_count'            => 0,
                'right_click_count'      => 0,
                'blur_count'             => 0,
                'focus_count'            => 0,
                'page_leave_count'       => 0,
                'offline_count'          => 0,
                'answer_changed_count'   => 0,
                'devtools_count'         => 0,
                'suspicious_key_count'   => 0,
                'screenshot_count'       => 0,
                'rapid_answer_changes'   => 0,
                'idle_count'             => 0,
                'idle_duration_ms'       => 0,
                'fullscreen_exit_count'  => 0,
                'typing_keydown_count'   => 0,
                'typing_backspace_count' => 0,
                'typing_enter_count'     => 0,
                'mouse_click_count'      => 0,
                'mouse_move_count'       => 0,
                'mouse_scroll_count'     => 0,
                'other_count'            => 0,
                // v15: keystroke dynamics
                'dwell_avg_ms'           => 0.0,
                'dwell_std_ms'           => 0.0,
                'dwell_min_ms'           => 0,
                'dwell_max_ms'           => 0,
                'flight_avg_ms'          => 0.0,
                'flight_std_ms'          => 0.0,
                'flight_min_ms'          => 0,
                'flight_max_ms'          => 0,
                'keystroke_samples'      => 0,
                '_dwell_sum'             => 0.0,
                '_dwell_sq_sum'          => 0.0,
                '_flight_sum'            => 0.0,
                '_flight_sq_sum'         => 0.0,
                '_dwell_min'             => PHP_INT_MAX,
                '_dwell_max'             => 0,
                '_flight_min'            => PHP_INT_MAX,
                '_flight_max'            => 0,
                '_ks_count'              => 0,
            ];
        }

        $c = &$sessionCounters[$sid];
        $c['last_event_at'] = $n['event_time'];
        $c['event_count']++;

        // Authoritative duration only comes from the dedicated tab_hidden_duration event.
        if ($n['duration_ms'] !== null) {
            $c['tab_hidden_duration_ms'] += $n['duration_ms'];
        }

        // Real values from summary metadata (not just "an event happened").
        $c['copy_selection_chars']   += $n['copy_chars'];
        $c['typing_keydown_count']   += $n['typing_down'];
        $c['typing_backspace_count'] += $n['typing_back'];
        $c['typing_enter_count']     += $n['typing_enter'];
        $c['mouse_click_count']      += $n['mouse_click'];
        $c['mouse_move_count']       += $n['mouse_move'];
        $c['mouse_scroll_count']     += $n['mouse_scroll'];
        $c['idle_duration_ms']       += $n['idle_ms'];

        $col = self::COUNTER_MAP[$n['event_type']] ?? null;
        if ($n['event_type'] === 'activity_summary' || $n['event_type'] === 'typing_summary' || $n['event_type'] === 'mouse_summary') {
            // Summary events carry real value fields above; no separate counter needed.
        } elseif ($n['event_type'] === 'keystroke_dynamics') {
            // v15: Accumulate keystroke dynamics using online (Welford's) algorithm
            $meta = $n['metadata'];
            $dwellAvg = (float)($meta['dwell_avg_ms'] ?? 0);
            $dwellStd = (float)($meta['dwell_std_ms'] ?? 0);
            $dwellMin = (int)($meta['dwell_min_ms'] ?? 0);
            $dwellMax = (int)($meta['dwell_max_ms'] ?? 0);
            $flightAvg = (float)($meta['flight_avg_ms'] ?? 0);
            $flightStd = (float)($meta['flight_std_ms'] ?? 0);
            $flightMin = (int)($meta['flight_min_ms'] ?? 0);
            $flightMax = (int)($meta['flight_max_ms'] ?? 0);
            $samples = (int)($meta['dwell_samples'] ?? $meta['flight_samples'] ?? 0);

            if ($samples > 0) {
                $c['_ks_count']++;
                $n_ = $c['_ks_count'];

                // Running average for dwell
                $oldAvg = $c['dwell_avg_ms'];
                $c['dwell_avg_ms'] = $oldAvg + ($dwellAvg - $oldAvg) / $n_;
                $c['_dwell_sum'] += $dwellAvg * $samples;
                $c['_dwell_sq_sum'] += ($dwellStd * $dwellStd + $dwellAvg * $dwellAvg) * $samples;
                if ($dwellMin > 0 && $dwellMin < $c['_dwell_min']) $c['_dwell_min'] = $dwellMin;
                if ($dwellMax > $c['_dwell_max']) $c['_dwell_max'] = $dwellMax;

                // Running average for flight
                $oldFlightAvg = $c['flight_avg_ms'];
                $c['flight_avg_ms'] = $oldFlightAvg + ($flightAvg - $oldFlightAvg) / $n_;
                $c['_flight_sum'] += $flightAvg * $samples;
                $c['_flight_sq_sum'] += ($flightStd * $flightStd + $flightAvg * $flightAvg) * $samples;
                if ($flightMin > 0 && $flightMin < $c['_flight_min']) $c['_flight_min'] = $flightMin;
                if ($flightMax > $c['_flight_max']) $c['_flight_max'] = $flightMax;

                $c['keystroke_samples'] += $samples;
            }
        } elseif ($col !== null) {
            $c[$col]++;
        } elseif ($n['duration_ms'] === null) {
            $c['other_count']++;
        }
    }

    /** First numeric metadata value found among candidate keys (else 0). */
    private static function metaInt(array $meta, array $keys): int
    {
        foreach ($keys as $k) {
            // Support both flat keys and dot-notation for nested metadata
            if (str_contains($k, '.')) {
                $parts = explode('.', $k, 2);
                if (isset($meta[$parts[0]]) && is_array($meta[$parts[0]])) {
                    $val = $meta[$parts[0]][$parts[1]] ?? null;
                    if (is_numeric($val)) return max(0, (int)$val);
                }
            }
            if (isset($meta[$k]) && is_numeric($meta[$k])) {
                return max(0, (int)$meta[$k]);
            }
        }
        return 0;
    }

    private static function flushSummary(string $sessionId, array $c): void
    {
        // Merge with the existing row so partial runs never double-count or undercount.
        $merged = $c;
        $existing = Database::fetchOne('SELECT * FROM session_summaries WHERE session_id = ?', [$sessionId]);
        if ($existing) {
            $keys = [
                'event_count', 'tab_hidden_count', 'tab_visible_count', 'tab_hidden_duration_ms',
                'copy_count', 'copy_selection_chars', 'paste_count', 'right_click_count',
                'blur_count', 'focus_count', 'page_leave_count', 'offline_count',
                'answer_changed_count', 'devtools_count', 'suspicious_key_count', 'screenshot_count', 'rapid_answer_changes',
                'idle_count', 'idle_duration_ms', 'fullscreen_exit_count',
                'typing_keydown_count', 'typing_backspace_count', 'typing_enter_count',
                'mouse_click_count', 'mouse_move_count', 'mouse_scroll_count',
                'other_count', 'keystroke_samples',
            ];
            foreach ($keys as $k) {
                $merged[$k] = (int)$existing[$k] + (int)$c[$k];
            }
            $merged['first_event_at'] = min((string)$existing['first_event_at'], (string)$c['first_event_at']);
            $merged['last_event_at'] = max((string)$existing['last_event_at'], (string)$c['last_event_at']);
            $merged['attempt_id'] = $c['attempt_id'] ?? $existing['attempt_id'];
        }

        $risk = RiskEngine::score($merged);

        // v15: Closed-loop response — evaluate and persist automated actions
        $summaryId = (int)($existing['id'] ?? 0);
        if ($summaryId > 0 && $risk['score'] >= 21) {
            try {
                $catScores = [];
                foreach (($risk['categories'] ?? []) as $cat => $info) {
                    $catScores[$cat] = $info['score'] ?? 0;
                }
                ResponseEngine::respond(
                    $risk['score'],
                    $summaryId,
                    (int)$merged['student_id'],
                    (int)$merged['exam_id'],
                    $catScores,
                    $risk['level']
                );
            } catch (\Throwable $e) {
                error_log("ResponseEngine error: " . $e->getMessage());
            }
        }

        // v9: Build dynamic INSERT with all columns including new analytics columns
        $columns = [
            'session_id', 'account_id', 'student_id', 'exam_id', 'attempt_id',
            'first_event_at', 'last_event_at',
            'event_count', 'tab_hidden_count', 'tab_visible_count', 'tab_hidden_duration_ms',
            'copy_count', 'copy_selection_chars', 'paste_count', 'right_click_count',
            'blur_count', 'focus_count', 'page_leave_count', 'offline_count',
            'answer_changed_count', 'devtools_count', 'suspicious_key_count', 'screenshot_count', 'rapid_answer_changes',
            'idle_count', 'idle_duration_ms', 'fullscreen_exit_count',
            'typing_keydown_count', 'typing_backspace_count', 'typing_enter_count',
            'mouse_click_count', 'mouse_move_count', 'mouse_scroll_count',
            'other_count', 'risk_score', 'risk_level',
            // v9: new columns
            'ip_address', 'ip_country', 'ip_city',
            'same_ip_student_count', 'ip_changed_count', 'same_ip_risk_score',
            'ai_suspect_score', 'answer_text_count', 'avg_answer_length', 'typing_answer_ratio',
            'similarity_max_score', 'similarity_match_count',
            // v12: cognitive time analysis
            'cognitive_score', 'cognitive_details',
            // v15: keystroke dynamics
            'dwell_avg_ms', 'dwell_std_ms', 'dwell_min_ms', 'dwell_max_ms',
            'flight_avg_ms', 'flight_std_ms', 'flight_min_ms', 'flight_max_ms',
            'keystroke_samples',
        ];

        $params = [
            ':sid' => $sessionId, ':account' => $merged['account_id'], ':stid' => $merged['student_id'],
            ':exid' => $merged['exam_id'], ':att' => $merged['attempt_id'],
            ':first' => $merged['first_event_at'], ':last' => $merged['last_event_at'],
            ':ec' => $merged['event_count'], ':th' => $merged['tab_hidden_count'],
            ':tvis' => $merged['tab_visible_count'], ':thd' => $merged['tab_hidden_duration_ms'],
            ':cp' => $merged['copy_count'], ':cchars' => $merged['copy_selection_chars'],
            ':ps' => $merged['paste_count'], ':rc' => $merged['right_click_count'],
            ':bl' => $merged['blur_count'], ':fc' => $merged['focus_count'],
            ':pl' => $merged['page_leave_count'], ':off' => $merged['offline_count'],
            ':ac' => $merged['answer_changed_count'], ':dev' => $merged['devtools_count'],
            ':sk' => $merged['suspicious_key_count'], ':shot' => $merged['screenshot_count'],
            ':rapid' => $merged['rapid_answer_changes'],
            ':idle' => $merged['idle_count'], ':idlems' => $merged['idle_duration_ms'],
            ':fsc' => $merged['fullscreen_exit_count'],
            ':typing' => $merged['typing_keydown_count'], ':tback' => $merged['typing_backspace_count'],
            ':tenter' => $merged['typing_enter_count'],
            ':mouse' => $merged['mouse_click_count'], ':mmove' => $merged['mouse_move_count'],
            ':mscroll' => $merged['mouse_scroll_count'], ':other' => $merged['other_count'],
            ':risk' => $risk['score'], ':level' => $risk['level'],
            // v9: network + AI + similarity defaults (updated later by analyzers)
            ':ip' => (string)($merged['ip_address'] ?? ($c['ip_address'] ?? ($existing['ip_address'] ?? ''))), ':ipcountry' => '', ':ipcity' => '',
            ':sameip' => 0, ':ipchanged' => 0, ':iprisk' => 0,
            ':aiscore' => 0, ':acount' => 0, ':avglen' => 0, ':tratio' => 0,
            ':simscore' => 0, ':simmatch' => 0,
            // v12: cognitive time analysis defaults
            ':cogs' => 0, ':cogd' => null,
            // v15: keystroke dynamics
            ':dwell_avg' => $merged['dwell_avg_ms'] ?? 0,
            ':dwell_std' => $merged['dwell_std_ms'] ?? 0,
            ':dwell_min' => ($merged['_dwell_min'] ?? PHP_INT_MAX) === PHP_INT_MAX ? 0 : $merged['_dwell_min'],
            ':dwell_max' => $merged['_dwell_max'] ?? 0,
            ':flight_avg' => $merged['flight_avg_ms'] ?? 0,
            ':flight_std' => $merged['flight_std_ms'] ?? 0,
            ':flight_min' => ($merged['_flight_min'] ?? PHP_INT_MAX) === PHP_INT_MAX ? 0 : $merged['_flight_min'],
            ':flight_max' => $merged['_flight_max'] ?? 0,
            ':ks_samples' => $merged['keystroke_samples'] ?? 0,
        ];

        $sql = 'INSERT INTO session_summaries
                  (' . implode(', ', $columns) . ')
                VALUES
                  (:sid, :account, :stid, :exid, :att, :first, :last,
                   :ec, :th, :tvis, :thd,
                   :cp, :cchars, :ps, :rc,
                   :bl, :fc, :pl, :off,
                   :ac, :dev, :sk, :shot, :rapid,
                   :idle, :idlems, :fsc,
                   :typing, :tback, :tenter,
                   :mouse, :mmove, :mscroll,
                   :other, :risk, :level,
                   :ip, :ipcountry, :ipcity,
                   :sameip, :ipchanged, :iprisk,
                   :aiscore, :acount, :avglen, :tratio,
                     :simscore, :simmatch,
                     :cogs, :cogd,
                     :dwell_avg, :dwell_std, :dwell_min, :dwell_max,
                     :flight_avg, :flight_std, :flight_min, :flight_max,
                     :ks_samples)
                ON DUPLICATE KEY UPDATE
                  first_event_at = VALUES(first_event_at), last_event_at = VALUES(last_event_at),
                  ip_address = COALESCE(NULLIF(VALUES(ip_address), ""), ip_address),
                  event_count = VALUES(event_count),
                  tab_hidden_count = VALUES(tab_hidden_count),
                  tab_visible_count = VALUES(tab_visible_count),
                  tab_hidden_duration_ms = VALUES(tab_hidden_duration_ms),
                  copy_count = VALUES(copy_count),
                  copy_selection_chars = VALUES(copy_selection_chars),
                  paste_count = VALUES(paste_count),
                  right_click_count = VALUES(right_click_count),
                  blur_count = VALUES(blur_count),
                  focus_count = VALUES(focus_count),
                  page_leave_count = VALUES(page_leave_count),
                  offline_count = VALUES(offline_count),
                  answer_changed_count = VALUES(answer_changed_count),
                  devtools_count = VALUES(devtools_count),
                  suspicious_key_count = VALUES(suspicious_key_count),
                  screenshot_count = VALUES(screenshot_count),
                  rapid_answer_changes = VALUES(rapid_answer_changes),
                  idle_count = VALUES(idle_count),
                  idle_duration_ms = VALUES(idle_duration_ms),
                  fullscreen_exit_count = VALUES(fullscreen_exit_count),
                  typing_keydown_count = VALUES(typing_keydown_count),
                  typing_backspace_count = VALUES(typing_backspace_count),
                  typing_enter_count = VALUES(typing_enter_count),
                  mouse_click_count = VALUES(mouse_click_count),
                  mouse_move_count = VALUES(mouse_move_count),
                  mouse_scroll_count = VALUES(mouse_scroll_count),
                  other_count = VALUES(other_count),
                  risk_score = VALUES(risk_score),
                  risk_level = VALUES(risk_level),
                  dwell_avg_ms = VALUES(dwell_avg_ms),
                  dwell_std_ms = VALUES(dwell_std_ms),
                  dwell_min_ms = VALUES(dwell_min_ms),
                  dwell_max_ms = VALUES(dwell_max_ms),
                  flight_avg_ms = VALUES(flight_avg_ms),
                  flight_std_ms = VALUES(flight_std_ms),
                  flight_min_ms = VALUES(flight_min_ms),
                  flight_max_ms = VALUES(flight_max_ms),
                  keystroke_samples = VALUES(keystroke_samples)';

        Database::execute($sql, $params);

        Database::execute(
            'UPDATE sessions SET risk_score = ?, risk_level = ? WHERE session_id = ?',
            [$risk['score'], $risk['level'], $sessionId]
        );
    }

    /* ── v9: Advanced Analytics Pipeline ─────────────────────────── */

    /**
     * Run all advanced analyzers for the processed sessions.
     * Called after flushSummary for every session in the batch.
     */
    private static function runAdvancedAnalytics(array $sessionCounters): void
    {
        // Group sessions by exam for exam-wide analysis
        $examSessions = [];
        foreach ($sessionCounters as $sessionId => $c) {
            $examId = (int)($c['exam_id'] ?? 0);
            $accountId = (int)($c['account_id'] ?? 0);
            if ($examId > 0 && $accountId > 0) {
                $examSessions[$accountId][$examId][] = $sessionId;
            }
        }

        // 1. Save IP snapshots for multi-device detection
        try {
            self::saveIPSnapshots($sessionCounters);
        } catch (\Throwable $e) {
            error_log("saveIPSnapshots error: " . $e->getMessage());
        }

        // 2. Save answer records from answer_changed events
        try {
            self::saveAnswerRecords($sessionCounters);
        } catch (\Throwable $e) {
            error_log("saveAnswerRecords error: " . $e->getMessage());
        }

        // v14: Update question_count on exams from answer_records
        self::updateExamQuestionCounts();

        // 3. Run analyzers per exam
        foreach ($examSessions as $accountId => $exams) {
            foreach ($exams as $examId => $sessions) {
                try {
                    // Network analysis
                    NetworkAnalyzer::analyzeExam($accountId, $examId);
                } catch (\Throwable $e) {
                    error_log("NetworkAnalyzer error: " . $e->getMessage());
                }

                try {
                    // Similarity analysis
                    SimilarityEngine::analyzeExam($accountId, $examId);
                } catch (\Throwable $e) {
                    error_log("SimilarityEngine error: " . $e->getMessage());
                }

                // AI detection per session (RapidAPI failover chain)
                foreach ($sessions as $sessionId) {
                    try {
                        $result = AIDetector::analyzeSession($accountId, $sessionId);
                        AIDetector::persistScores($accountId, $sessionId);
                    } catch (\Throwable $e) {
                        error_log("AIDetector error: " . $e->getMessage());
                    }
                }

                // v12: Cognitive time analysis (per-question time validation)
                try {
                    CognitiveAnalyzer::analyzeExam($accountId, $examId);
                } catch (\Throwable $e) {
                    error_log("CognitiveAnalyzer error: " . $e->getMessage());
                }

                // Re-score all sessions with full AI/similarity/network data in real-time
                try {
                    self::rescoreSessions($accountId, $examId);
                } catch (\Throwable $e) {
                    error_log("rescoreSessions error: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Re-compute risk_score for all sessions in an exam after analyzers
     * have written AI, similarity, and network scores to session_summaries.
     */
    private static function rescoreSessions(int $accountId, int $examId): void
    {
        $pdo = Database::connection();

        $exam = Database::fetchOne('SELECT id, moodle_quiz_id, question_count, duration_minutes FROM exams WHERE id = ? OR moodle_quiz_id = ? LIMIT 1', [$examId, $examId]);
        $intId = $exam ? (int)$exam['id'] : $examId;
        $quizId = $exam ? (int)$exam['moodle_quiz_id'] : $examId;
        $qCount = $exam ? (int)($exam['question_count'] ?? 0) : 0;
        $durMin = $exam ? (int)($exam['duration_minutes'] ?? 15) : 15;

        $rows = $pdo->prepare(
            "SELECT * FROM session_summaries WHERE (account_id = :a OR account_id = 0) AND (exam_id = :eid OR exam_id = :qid)"
        );
        $rows->execute([':a' => $accountId, ':eid' => $intId, ':qid' => $quizId]);
        $summaries = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($summaries)) return;

        $update = $pdo->prepare(
            "UPDATE session_summaries SET risk_score = :risk, risk_level = :level WHERE id = :id"
        );
        $updateSession = $pdo->prepare(
            "UPDATE sessions SET risk_score = :risk, risk_level = :level WHERE session_id = :sid"
        );

        foreach ($summaries as $s) {
            // Rebuild counters from the session_summaries row
            $counters = $s;
            $counters['question_count'] = $qCount > 0 ? $qCount : (int)($s['question_count'] ?? 0);
            $counters['exam_minutes']   = $durMin > 0 ? $durMin : (int)($s['duration_minutes'] ?? 15);

            $risk = RiskEngine::score($counters);

            $update->execute([
                ':risk'  => $risk['score'],
                ':level' => $risk['level'],
                ':id'    => $s['id'],
            ]);

            $updateSession->execute([
                ':risk'  => $risk['score'],
                ':level' => $risk['level'],
                ':sid'   => $s['session_id'],
            ]);
        }
    }

    /**
     * Save IP snapshots from events for multi-device detection.
     */
    private static function saveIPSnapshots(array $sessionCounters): void
    {
        if (empty($sessionCounters)) return;
        $pdo = Database::connection();

        foreach ($sessionCounters as $sessionId => $c) {
            $accountId = (int)$c['account_id'];
            $examId    = (int)$c['exam_id'];
            $studentId = (int)$c['student_id'];

            if ($examId <= 0) continue;

            // Fetch events for this session
            $st = $pdo->prepare(
                "SELECT id, ip_address, user_agent, payload, event_time
                 FROM events
                 WHERE session_id = :s AND (account_id = :a OR account_id = 0)
                 ORDER BY id"
            );
            $st->execute([':s' => $sessionId, ':a' => $accountId]);
            $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($events)) continue;

            $ins = $pdo->prepare(
                "INSERT IGNORE INTO ip_snapshots
                 (account_id, session_id, student_id, exam_id, ip_address, user_agent, browser_fp, detected_at)
                 VALUES (:a, :sid, :stid, :eid, :ip, :ua, :fp, :detected)"
            );

            $devIns = $pdo->prepare(
                "INSERT INTO student_devices
                 (account_id, student_id, exam_id, ip_address, user_agent, browser_fp, first_seen, last_seen, snapshot_count)
                 VALUES (:a, :stid, :eid, :ip, :ua, :fp, :detected, :detected, 1)
                 ON DUPLICATE KEY UPDATE
                   ip_address = VALUES(ip_address),
                   user_agent = VALUES(user_agent),
                   last_seen = VALUES(last_seen),
                   snapshot_count = snapshot_count + 1"
            );

            foreach ($events as $ev) {
                $payload = json_decode($ev['payload'], true) ?: [];
                $meta = $payload['metadata'] ?? [];
                $telemetry = $meta['device_telemetry'] ?? [];

                $ua = $ev['user_agent'] ?: ($meta['user_agent'] ?? ($payload['browser']['user_agent'] ?? ''));
                $fp = $telemetry['fingerprint_hash'] ?? ($meta['browser_fingerprint'] ?? ($meta['fingerprint_hash'] ?? ''));

                // Prefer server-side HTTP IP (always accurate) over browser WebRTC (often 'unknown')
                $ip = $ev['ip_address'] ?: ($meta['ip_address'] ?? '');
                if ($ip === '' || $ip === 'unknown') continue;

                $ins->execute([
                    ':a'       => $accountId,
                    ':sid'     => $sessionId,
                    ':stid'    => $studentId,
                    ':eid'     => $examId,
                    ':ip'      => $ip,
                    ':ua'      => $ua,
                    ':fp'      => $fp,
                    ':detected'=> $ev['event_time'],
                ]);

                // Also upsert student_devices for multi-device detection
                $devIns->execute([
                    ':a'       => $accountId,
                    ':stid'    => $studentId,
                    ':eid'     => $examId,
                    ':ip'      => $ip,
                    ':ua'      => $ua,
                    ':fp'      => $fp,
                    ':detected'=> $ev['event_time'],
                ]);
            }
        }
    }

    /**
     * Extract answer text from answer_changed events and store as answer_records.
     */
    private static function saveAnswerRecords(array $sessionCounters): void
    {
        if (empty($sessionCounters)) return;
        $pdo = Database::connection();

        foreach ($sessionCounters as $sessionId => $c) {
            $accountId = (int)$c['account_id'];
            $examId    = (int)$c['exam_id'];
            $studentId = (int)$c['student_id'];

            if ($examId <= 0) continue;

            // Fetch answer_changed events for this session that haven't been processed
            $st = $pdo->prepare(
                "SELECT id, payload, event_time
                 FROM events
                 WHERE session_id = :s AND (account_id = :a OR account_id = 0) AND event_type = 'answer_changed'
                 ORDER BY id"
            );
            $st->execute([':s' => $sessionId, ':a' => $accountId]);
            $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($events)) continue;

            $ins = $pdo->prepare(
                "INSERT INTO answer_records
                 (account_id, session_id, student_id, exam_id, moodle_quiz_id, question_id, question_type,
                  answer_text, answer_length, word_count, typing_duration_ms, change_count,
                  paste_text, paste_length, copy_count_from_question, copy_text, created_at)
                 VALUES (:a, :sid, :stid, :eid, :qid, :qnum, :qtype, :atxt, :alen, :wc, :tdur, :ccnt,
                         :ptxt, :plen, :ccopy, :ctxt, :created)
                 ON DUPLICATE KEY UPDATE
                  answer_text = VALUES(answer_text),
                  answer_length = VALUES(answer_length),
                  word_count = VALUES(word_count),
                  typing_duration_ms = VALUES(typing_duration_ms),
                  change_count = VALUES(change_count),
                  paste_text = COALESCE(VALUES(paste_text), paste_text),
                  paste_length = GREATEST(VALUES(paste_length), paste_length),
                  copy_count_from_question = GREATEST(VALUES(copy_count_from_question), copy_count_from_question),
                  copy_text = COALESCE(VALUES(copy_text), copy_text),
                  question_type = IF(VALUES(question_type) != '', VALUES(question_type), question_type),
                  created_at = VALUES(created_at)"
            );

            foreach ($events as $ev) {
                $payload = json_decode($ev['payload'], true) ?: [];
                $meta = $payload['metadata'] ?? [];

                $questionId   = $meta['question_id'] ?? $meta['questionId'] ?? ($meta['question']['question_dom_id'] ?? ($meta['question']['question_number'] ?? 'q1'));
                $questionType = $meta['question_type'] ?? $meta['questionType'] ?? ($meta['question']['question_type'] ?? 'multichoice');
                $answerText   = $meta['answer_text'] ?? $meta['answerText'] ?? ($meta['answer']['answer_text'] ?? ($meta['answer']['answer_value'] ?? ($meta['value'] ?? '')));
                $wordCount    = $meta['word_count'] ?? $meta['wordCount'] ?? ($meta['answer']['word_count'] ?? 0);
                $changeCount  = $meta['change_count'] ?? $meta['changeCount'] ?? 1;
                $typeDuration = $meta['typing_duration_ms'] ?? $meta['typingDurationMs'] ?? 0;

                if ($answerText === '' && $questionId === '') continue;

                $answerLen = mb_strlen($answerText);
                if ($answerText !== '' && $wordCount === 0) {
                    $wordCount = AIDetector::countWords($answerText);
                }

                if ($typeDuration === 0) {
                    $typeDuration = self::computeTypingDuration($sessionId, $questionId, $ev['event_time']);
                }

                $pasteText = $meta['pasted_text'] ?? null;
                $pasteLen = $meta['pasted_length'] ?? ($pasteText ? mb_strlen($pasteText) : 0);
                $copyCount = 0;
                $copyText = null;

                $ins->execute([
                    ':a'      => $accountId,
                    ':sid'    => $sessionId,
                    ':stid'   => $studentId,
                    ':eid'    => $examId,
                    ':qid'    => $examId,
                    ':qnum'   => $questionId,
                    ':qtype'  => $questionType,
                    ':atxt'   => $answerText,
                    ':alen'   => $answerLen,
                    ':wc'     => $wordCount,
                    ':tdur'   => $typeDuration,
                    ':ccnt'   => $changeCount,
                    ':ptxt'   => $pasteText,
                    ':plen'   => $pasteLen,
                    ':ccopy'  => $copyCount,
                    ':ctxt'   => $copyText,
                    ':created' => $ev['event_time'],
                ]);

                // Instantly trigger AI analysis if answer has >= 10 words
                if ($wordCount >= 10) {
                    try {
                        AIDetector::analyzeAndPersist($accountId, $sessionId, (string)$questionId, $answerText);
                    } catch (\Throwable $e) {}
                }
            }

            self::saveCopyContext($pdo, $sessionId, $accountId, $examId, $studentId);
            self::savePasteContext($pdo, $sessionId, $accountId, $examId, $studentId);
            AIDetector::persistScores($accountId, $sessionId);
        }
    }

    /**
     * Process copy events and update answer_records with copy context.
     */
    private static function saveCopyContext(PDO $pdo, string $sessionId, int $accountId, int $examId, int $studentId): void
    {
        $st = $pdo->prepare(
            "SELECT payload, event_time FROM events
             WHERE session_id = :s AND (account_id = :a OR account_id = 0) AND event_type = 'copy'
             ORDER BY event_time"
        );
        $st->execute([':s' => $sessionId, ':a' => $accountId]);
        $copyEvents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($copyEvents)) return;

        $updateSt = $pdo->prepare(
            "UPDATE answer_records
             SET copy_count_from_question = copy_count_from_question + 1,
                 copy_text = CASE WHEN copy_text IS NULL OR copy_text = '' THEN :ctxt ELSE copy_text END
             WHERE session_id = :s AND (account_id = :a OR account_id = 0)
               AND (question_id = :qid OR question_id LIKE :qidlike)"
        );

        foreach ($copyEvents as $ce) {
            $payload = json_decode($ce['payload'], true) ?: [];
            $meta = $payload['metadata'] ?? [];
            $selectedText = $meta['selected_text'] ?? ($meta['selection_text'] ?? null);
            $questionId = (string)($meta['question']['question_number'] ?? ($meta['question_id'] ?? ($meta['question']['question_dom_id'] ?? '')));

            $qid = $questionId ?: '';
            if ($qid === '' && $selectedText === null) continue;

            $cleanQid = preg_replace('/[^0-9]/', '', $qid);

            $updateSt->execute([
                ':ctxt'    => $selectedText ? mb_substr($selectedText, 0, 2000) : null,
                ':s'       => $sessionId,
                ':a'       => $accountId,
                ':qid'     => $qid ?: 'q1',
                ':qidlike' => '%' . ($cleanQid ?: '1') . '%',
            ]);
        }
    }

    /**
     * Process paste events and update answer_records with paste context and instant AI suspicion score.
     */
    private static function savePasteContext(PDO $pdo, string $sessionId, int $accountId, int $examId, int $studentId): void
    {
        $st = $pdo->prepare(
            "SELECT payload, event_time FROM events
             WHERE session_id = :s AND (account_id = :a OR account_id = 0) AND event_type = 'paste'
             ORDER BY event_time ASC"
        );
        $st->execute([':s' => $sessionId, ':a' => $accountId]);
        $pasteEvents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($pasteEvents)) return;

        $updateSt = $pdo->prepare(
            "UPDATE answer_records
             SET paste_text = CASE WHEN paste_text IS NULL OR paste_text = '' THEN :ptxt ELSE CONCAT(paste_text, '\n---\n', :ptxt2) END,
                 paste_length = GREATEST(paste_length, :plen),
                 ai_score = GREATEST(ai_score, :aiscore)
             WHERE session_id = :s AND (account_id = :a OR account_id = 0)
               AND (question_id = :qid OR question_id LIKE :qidlike)"
        );

        foreach ($pasteEvents as $pe) {
            $payload = json_decode($pe['payload'], true) ?: [];
            $meta = $payload['metadata'] ?? [];
            $pastedText = trim((string)($meta['pasted_text'] ?? ($payload['text'] ?? '')));
            $pastedLength = (int)($meta['pasted_length'] ?? mb_strlen($pastedText));
            $questionId = (string)($meta['question_id'] ?? ($meta['question']['question_number'] ?? ($meta['question']['question_dom_id'] ?? '')));

            if ($pastedText === '' && $pastedLength === 0) continue;

            // Direct academic suspicion: pasting essay chunks without typing is a hallmark of external AI answers
            $aiScore = 0;
            if ($pastedLength >= 80 || mb_strlen($pastedText) >= 80) {
                $aiScore = 95;
            } elseif ($pastedLength >= 35 || mb_strlen($pastedText) >= 35) {
                $aiScore = 80;
            } elseif ($pastedLength >= 15) {
                $aiScore = 65;
            }

            $cleanQid = preg_replace('/[^0-9]/', '', $questionId);

            $updateSt->execute([
                ':ptxt'    => mb_substr($pastedText, 0, 3000),
                ':ptxt2'   => mb_substr($pastedText, 0, 1500),
                ':plen'    => $pastedLength,
                ':aiscore' => $aiScore,
                ':s'       => $sessionId,
                ':a'       => $accountId,
                ':qid'     => $questionId ?: 'q1',
                ':qidlike' => '%' . ($cleanQid ?: '1') . '%',
            ]);
        }
    }

    /**
     * Compute typing duration for an answer by looking at typing events.
     */
    private static function computeTypingDuration(string $sessionId, string $questionId, string $eventTime): int
    {
        $pdo = Database::connection();

        // Sum typing_summary durations in a window around the answer event
        $st = $pdo->prepare(
            "SELECT SUM(duration_ms) as total
             FROM events
             WHERE session_id = :s
               AND event_type = 'typing_summary'
               AND event_time <= :et
               AND event_time >= DATE_SUB(:et2, INTERVAL 10 SECOND)"
        );
        $st->execute([':s' => $sessionId, ':et' => $eventTime, ':et2' => $eventTime]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    /**
     * v14: Compute distinct question_count per exam from answer_records
     * and update the exams table so RiskEngine can normalize by context.
     */
    private static function updateExamQuestionCounts(): void
    {
        $pdo = Database::connection();
        $rows = $pdo->query(
            "SELECT exam_id, COUNT(DISTINCT question_id) AS qcount
             FROM answer_records
             WHERE question_id IS NOT NULL AND question_id != ''
             GROUP BY exam_id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($rows)) return;

        $st = $pdo->prepare(
            "UPDATE exams SET question_count = :qc WHERE id = :id AND (question_count IS NULL OR question_count = 0 OR question_count != :qc2)"
        );

        foreach ($rows as $r) {
            $st->execute([
                ':qc'  => (int)$r['qcount'],
                ':id'  => (int)$r['exam_id'],
                ':qc2' => (int)$r['qcount'],
            ]);
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  v28: TF-IDF Similarity Check via Python Engine
     * ══════════════════════════════════════════════════════════════ */

    /**
     * For each essay answer (≥10 words), compare against other students'
     * answers for the same question using the Python TF-IDF engine.
     */
    private static function runSimilarityCheck(int $accountId, int $examId): void
    {
        $pdo = Database::connection();

        // Find unanswered similarity checks for essay answers
        $rows = $pdo->prepare(
            "SELECT id, session_id, student_id, question_id, answer_text, word_count
             FROM answer_records
             WHERE account_id = :a AND exam_id = :e
               AND word_count >= 10
               AND answer_text IS NOT NULL AND answer_text != ''
               AND similarity_score = 0 AND similarity_max_ratio = 0
             ORDER BY question_id, student_id"
        );
        $rows->execute([':a' => $accountId, ':e' => $examId]);
        $records = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($records)) return;

        // Group by question for batch comparison
        $byQuestion = [];
        foreach ($records as $r) {
            $qid = $r['question_id'];
            $byQuestion[$qid][] = $r;
        }

        $updateSt = $pdo->prepare(
            "UPDATE answer_records
             SET similarity_score = :sim, similarity_max_ratio = :ratio, matched_student_id = :msid
             WHERE id = :id"
        );

        foreach ($byQuestion as $qid => $answers) {
            // Build comparison list from ALL answers for this question (including already-scored)
            $allForQ = $pdo->prepare(
                "SELECT id, student_id, answer_text
                 FROM answer_records
                 WHERE account_id = :a AND exam_id = :e AND question_id = :q
                   AND answer_text IS NOT NULL AND answer_text != ''
                   AND word_count >= 10
                 ORDER BY student_id"
            );
            $allForQ->execute([':a' => $accountId, ':e' => $examId, ':q' => $qid]);
            $allAnswers = $allForQ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (count($allAnswers) < 2) continue;

            foreach ($answers as $record) {
                $targetText = $record['answer_text'];
                $targetSid = (int)$record['student_id'];

                // Build comparison list (exclude self)
                $comparisonTexts = [];
                $comparisonIds = [];
                foreach ($allAnswers as $other) {
                    if ((int)$other['student_id'] === $targetSid) continue;
                    $comparisonTexts[] = $other['answer_text'];
                    $comparisonIds[] = (int)$other['student_id'];
                }

                if (empty($comparisonTexts)) continue;

                // Call Python engine
                $result = self::callPythonSimilarity($targetText, $comparisonTexts);
                if ($result === null) continue;

                $simScore = $result['similarity_score_S'];
                $maxRatio = $result['max_similarity_ratio'];
                $matchedIdx = $result['matched_index'];
                $matchedSid = ($matchedIdx >= 0 && isset($comparisonIds[$matchedIdx]))
                    ? $comparisonIds[$matchedIdx] : null;

                $updateSt->execute([
                    ':sim'  => $simScore,
                    ':ratio'=> $maxRatio,
                    ':msid' => $matchedSid,
                    ':id'   => (int)$record['id'],
                ]);
            }
        }
    }

    /**
     * Call Python ML Engine POST /similarity/check
     */
    private static function callPythonSimilarity(string $targetAnswer, array $comparisonAnswers): ?array
    {
        $payload = json_encode([
            'target_answer'      => $targetAnswer,
            'comparison_answers' => $comparisonAnswers,
        ]);

        $ch = curl_init('http://127.0.0.1:8765/similarity/check');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($err || $response === false) return null;

        $data = json_decode($response, true);
        if (!is_array($data)) return null;

        return $data;
    }

    /* ══════════════════════════════════════════════════════════════
     *  v28: Event Sequence Detection via Python Engine
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Detect suspicious event sequences for a session:
     * 1. Copy→Blur→Focus→Paste within 60s
     * 2. Blur (>10s)→Answer Changed within 5s
     */
    private static function runSequenceDetection(int $accountId, string $sessionId): void
    {
        $pdo = Database::connection();

        // Fetch relevant events for this session
        $evSt = $pdo->prepare(
            "SELECT event_type, event_time, duration_ms
             FROM events
             WHERE session_id = :s AND account_id = :a
               AND event_type IN ('copy','cut','paste','window_blur','window_focus',
                                  'tab_hidden','tab_visible','answer_changed')
             ORDER BY event_time ASC"
        );
        $evSt->execute([':s' => $sessionId, ':a' => $accountId]);
        $events = $evSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($events) < 2) return;

        // Convert to format expected by Python engine
        $baseTime = strtotime($events[0]['event_time']) * 1000;
        $evPayload = [];
        foreach ($events as $e) {
            $evPayload[] = [
                'type'       => $e['event_type'],
                'time_ms'    => (strtotime($e['event_time']) * 1000) - $baseTime,
                'duration_ms'=> (int)($e['duration_ms'] ?? 0),
            ];
        }

        // Call Python engine
        $result = self::callPythonSequence($evPayload);
        if ($result === null) return;

        // Update session_summaries with sequence detection results
        $seqFlags = json_encode([
            'critical_sequence' => $result['critical_sequence_detected'] ?? false,
            'post_blur_mutation'=> $result['post_blur_mutation'] ?? false,
            'sequences'         => $result['suspicious_sequences'] ?? [],
            'mutations'         => $result['mutation_events'] ?? [],
        ], JSON_UNESCAPED_UNICODE);

        $seqScore = $result['sequence_score'] ?? 0.0;

        $pdo->prepare(
            "UPDATE session_summaries
             SET sequence_flags = :flags, sequence_score = :score
             WHERE session_id = :s AND account_id = :a"
        )->execute([
            ':flags' => $seqFlags,
            ':score' => $seqScore,
            ':s'     => $sessionId,
            ':a'     => $accountId,
        ]);
    }

    /**
     * Call Python ML Engine POST /sequence/check
     */
    private static function callPythonSequence(array $events): ?array
    {
        $payload = json_encode(['events' => $events]);

        $ch = curl_init('http://127.0.0.1:8765/sequence/check');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);

        if ($err || $response === false) return null;

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
