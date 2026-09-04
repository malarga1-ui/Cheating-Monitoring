<?php
/**
 * Teacher portal endpoints. Every query is strictly scoped to the courses the
 * logged-in teacher is assigned to (course_teachers). A teacher can never see
 * another teacher's courses, exams or students.
 */
final class TeacherPortalController
{
    /** Validate array contains only integers, return comma-separated string for SQL IN(). */
    private static function safeInts(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }

    /** Dashboard totals for the teacher. */
    public static function summary(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        $ids = Teachers::courseIds($accountId, $teacherId);
        $courseId = (int)($_GET['course_id'] ?? 0);
        if ($courseId > 0) {
            $ids = in_array($courseId, $ids, true) ? [$courseId] : [];
        }
        if ($ids === []) {
            Response::ok(self::emptySummary());
            return;
        }
        $in = self::safeInts($ids);

        $row = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM exams e WHERE (e.account_id = ? OR e.account_id = 0) AND e.moodle_course_id IN ($in)) AS exams_count,
                (SELECT COUNT(*) FROM exams e WHERE (e.account_id = ? OR e.account_id = 0) AND e.moodle_course_id IN ($in) AND (e.status = 'active' OR e.last_event_at >= (UTC_TIMESTAMP() - INTERVAL 2 HOUR) OR e.last_event_at >= (NOW() - INTERVAL 2 HOUR))) AS active_exams,
                (SELECT COUNT(*) FROM courses c WHERE (c.account_id = ? OR c.account_id = 0) AND c.moodle_course_id IN ($in)) AS courses_count,
                (SELECT COUNT(DISTINCT s.id)
                   FROM students s
                  WHERE (s.account_id = ? OR s.account_id = 0)
                    AND IF(s.moodle_user_id > 0, s.moodle_user_id, s.id) IN (SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = ? OR cs.account_id = 0) AND cs.moodle_course_id IN ($in))
                    AND s.username NOT IN (SELECT username FROM teachers WHERE (account_id = ? OR account_id = 0) AND username != '')
                ) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)
                   FROM session_summaries ss
                   JOIN exams e3 ON (e3.id = ss.exam_id OR e3.moodle_quiz_id = ss.exam_id)
                  WHERE (e3.account_id = ? OR e3.account_id = 0) AND e3.moodle_course_id IN ($in)) AS sessions_count,
                (SELECT COUNT(DISTINCT ss.student_id)
                   FROM session_summaries ss
                   JOIN exams e4 ON (e4.id = ss.exam_id OR e4.moodle_quiz_id = ss.exam_id)
                  WHERE (e4.account_id = ? OR e4.account_id = 0) AND e4.moodle_course_id IN ($in)
                    AND ss.risk_level IN ('high','critical')) AS suspicious_count",
            [$accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId]
        );

        Response::ok([
            'exams_count'       => (int)$row['exams_count'],
            'active_exams'      => (int)$row['active_exams'],
            'courses_count'     => (int)$row['courses_count'],
            'students_count'    => (int)$row['students_count'],
            'sessions_count'    => (int)$row['sessions_count'],
            'suspicious_count'  => (int)$row['suspicious_count'],
        ]);
    }

    /** The teacher's courses, each with co-teachers and per-course counts. Strictly this teacher only. */
    public static function courses(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        $ids = Teachers::courseIds($accountId, $teacherId);
        if ($ids === []) {
            Response::ok([]);
            return;
        }
        $in = self::safeInts($ids);

        $rows = Database::fetchAll(
            "SELECT c.id, c.moodle_course_id, c.name, c.created_at,
                    (SELECT COUNT(*) FROM exams e WHERE (e.account_id = c.account_id OR e.account_id = 0) AND e.moodle_course_id = c.moodle_course_id) AS exams_count,
                    (SELECT COUNT(DISTINCT s.id)
                       FROM students s
                      WHERE (s.account_id = c.account_id OR s.account_id = 0)
                        AND IF(s.moodle_user_id > 0, s.moodle_user_id, s.id) IN (SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = c.account_id OR cs.account_id = 0) AND cs.moodle_course_id = c.moodle_course_id)
                        AND s.username NOT IN (SELECT username FROM teachers WHERE (account_id = c.account_id OR account_id = 0) AND username != '')
                    ) AS students_count
               FROM courses c
              WHERE (c.account_id = ? OR c.account_id = 0) AND c.moodle_course_id IN ($in)
              ORDER BY c.name",
            [$accountId]
        );

        $coTeachers = Database::fetchAll(
            "SELECT DISTINCT ct.moodle_course_id, t.moodle_teacher_id AS teacher_id, t.fullname, t.username
               FROM course_teachers ct
               JOIN teachers t ON t.moodle_teacher_id = ct.moodle_teacher_id AND (t.account_id = ct.account_id OR t.account_id = 0)
              WHERE (ct.account_id = ? OR ct.account_id = 0) AND ct.moodle_course_id IN ($in)
              ORDER BY t.fullname",
            [$accountId]
        );

        $byCourse = [];
        foreach ($coTeachers as $ct) {
            $byCourse[(int)$ct['moodle_course_id']][] = [
                'teacher_id' => (int)$ct['teacher_id'],
                'fullname'   => $ct['fullname'],
                'username'   => $ct['username'],
                'is_me'      => (int)$ct['teacher_id'] === $teacherId,
            ];
        }

        Response::ok(array_map(function ($r) use ($byCourse) {
            return [
                'id'              => (int)$r['id'],
                'moodle_course_id'=> (int)$r['moodle_course_id'],
                'name'            => $r['name'],
                'exams_count'     => (int)$r['exams_count'],
                'students_count'  => (int)$r['students_count'],
                'teachers'        => $byCourse[(int)$r['moodle_course_id']] ?? [],
            ];
        }, $rows));
    }

    /** The teacher's exams (strictly their courses). */
    public static function exams(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Incrementally aggregate any pending events so last_event_at and session_summaries are 100% up to date
        try { Aggregator::process(2000); } catch (\Throwable $e) {}

        $ids = Teachers::courseIds($accountId, $teacherId);
        $courseId = (int)($_GET['course_id'] ?? 0);
        if ($courseId > 0) {
            $ids = in_array($courseId, $ids, true) ? [$courseId] : [];
        }
        if ($ids === []) {
            Response::ok([]);
            return;
        }
        $in = self::safeInts($ids);

        $search = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');
        $params = [$accountId, $accountId, $accountId, $accountId, $accountId, $accountId];
        $extra = '';
        if ($search !== '') {
            $extra .= ' AND (e.name LIKE ? OR e.moodle_quiz_id LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($status === 'active') {
            $extra .= " AND (
                e.status = 'active'
                OR (e.last_event_at IS NOT NULL AND (e.last_event_at >= (UTC_TIMESTAMP() - INTERVAL 6 HOUR) OR e.last_event_at >= (NOW() - INTERVAL 6 HOUR)))
                OR EXISTS (
                    SELECT 1 FROM events ev 
                     WHERE (ev.moodle_quiz_id = e.moodle_quiz_id OR ev.moodle_quiz_id = e.id)
                       AND (ev.account_id = ? OR ev.account_id = 0)
                       AND (ev.received_at >= (NOW() - INTERVAL 4 HOUR) OR ev.event_time >= (UTC_TIMESTAMP() - INTERVAL 4 HOUR))
                )
            )";
            $params[] = $accountId;
        } elseif ($status === 'ended') {
            $extra .= " AND e.status = 'ended' AND (e.last_event_at IS NULL OR (e.last_event_at < (UTC_TIMESTAMP() - INTERVAL 6 HOUR) AND e.last_event_at < (NOW() - INTERVAL 6 HOUR)))";
        }

        try {
            $rows = Database::fetchAll(
                "SELECT e.id, e.moodle_quiz_id, e.moodle_course_id, e.moodle_cmid,
                        e.name, e.moodle_teacher_id, e.teacher_name,
                        e.status, e.first_event_at, e.last_event_at, e.created_at,
                        c.name AS course_name, c.id AS course_id,
                        (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = e.id OR ss.exam_id = e.moodle_quiz_id) AND (ss.account_id = ? OR ss.account_id = 0)) AS students_count,
                        (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE (ss.exam_id = e.id OR ss.exam_id = e.moodle_quiz_id) AND (ss.account_id = ? OR ss.account_id = 0)) AS sessions_count,
                        (SELECT COUNT(*) FROM events ev WHERE (ev.moodle_quiz_id = e.moodle_quiz_id OR ev.moodle_quiz_id = e.id) AND (ev.account_id = ? OR ev.account_id = 0)) AS events_count,
                        (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = e.id OR ss.exam_id = e.moodle_quiz_id) AND (ss.account_id = ? OR ss.account_id = 0) AND ss.risk_level IN ('high','critical')) AS suspicious_count
                   FROM exams e
                   LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = ? OR c.account_id = 0)
                  WHERE (e.account_id = ? OR e.account_id = 0) AND e.moodle_course_id IN ($in)" . $extra . "
                  ORDER BY 
                    CASE WHEN e.last_event_at IS NOT NULL AND (e.last_event_at >= (UTC_TIMESTAMP() - INTERVAL 2 HOUR) OR e.last_event_at >= (NOW() - INTERVAL 2 HOUR)) THEN 0 ELSE 1 END ASC,
                    e.last_event_at DESC, 
                    e.id DESC
                  LIMIT 300",
                $params
            );
        } catch (\Throwable $e) {
            try {
                $rows = Database::fetchAll(
                    "SELECT e.id, e.moodle_quiz_id, e.moodle_course_id, e.moodle_cmid,
                            e.name, e.moodle_teacher_id, e.teacher_name,
                            e.status, e.first_event_at, e.last_event_at, e.created_at,
                            c.name AS course_name, c.id AS course_id,
                            0 AS students_count, 0 AS sessions_count, 0 AS events_count, 0 AS suspicious_count
                       FROM exams e
                       LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = ? OR c.account_id = 0)
                      WHERE (e.account_id = ? OR e.account_id = 0) AND e.moodle_course_id IN ($in)
                      ORDER BY e.last_event_at DESC, e.id DESC
                      LIMIT 300",
                    [$accountId, $accountId]
                );
            } catch (\Throwable $e2) {
                $rows = [];
            }
        }

        // Fallback: if session_summaries is empty but events exist, count from events
        foreach ($rows as &$r) {
            if ((int)$r['students_count'] === 0 && (int)$r['events_count'] > 0) {
                $qid = (int)$r['moodle_quiz_id'];
                $eid = (int)$r['id'];
                $evCount = Database::fetchOne(
                    "SELECT COUNT(DISTINCT moodle_user_id) AS cnt
                     FROM events WHERE (moodle_quiz_id = ? OR moodle_quiz_id = ?) AND (account_id = ? OR account_id = 0)",
                    [$qid, $eid, $accountId]
                );
                $r['students_count'] = (int)($evCount['cnt'] ?? 0);
                $r['sessions_count'] = $r['students_count'];
            }
        }
        unset($r);

        Response::ok(array_map(function ($r) {
            return [
                'id'              => (int)$r['id'],
                'moodle_quiz_id'  => (int)$r['moodle_quiz_id'],
                'moodle_course_id'=> (int)$r['moodle_course_id'],
                'name'            => $r['name'],
                'course_name'     => $r['course_name'],
                'status'          => $r['status'],
                'first_event_at'  => $r['first_event_at'],
                'last_event_at'   => $r['last_event_at'],
                'students_count'  => (int)$r['students_count'],
                'sessions_count'  => (int)$r['sessions_count'],
                'events_count'    => (int)$r['events_count'],
                'suspicious_count'=> (int)$r['suspicious_count'],
            ];
        }, $rows));
    }

    /** Exam detail (ownership enforced). */
    public static function examDetail(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Incrementally aggregate any pending events
        try { Aggregator::process(500); } catch (\Throwable $e) {}

        $exam = self::ownedExam($id, $accountId, $teacherId);
        $internalExamId = (int)$exam['id'];
        $quizId = (int)$exam['moodle_quiz_id'];

        try { SimilarityEngine::analyzeExam($accountId, $internalExamId); } catch (\Throwable $e) {}

        $counts = Database::fetchOne(
            'SELECT
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = ? OR ss.exam_id = ?) AND (ss.account_id = ? OR ss.account_id = 0)) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE (ss.exam_id = ? OR ss.exam_id = ?) AND (ss.account_id = ? OR ss.account_id = 0)) AS sessions_count,
                (SELECT COUNT(*) FROM events ev WHERE (ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?) AND (ev.account_id = ? OR ev.account_id = 0)) AS events_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = ? OR ss.exam_id = ?) AND (ss.account_id = ? OR ss.account_id = 0) AND ss.risk_level IN ("high","critical")) AS suspicious_count',
            [$internalExamId, $quizId, $accountId, $internalExamId, $quizId, $accountId, $quizId, $internalExamId, $accountId, $internalExamId, $quizId, $accountId]
        );
        $studentsCount = (int)$counts['students_count'];
        $sessionsCount = (int)$counts['sessions_count'];
        $eventsCount = (int)$counts['events_count'];

        if ($studentsCount === 0 && $eventsCount > 0) {
            $fallback = Database::fetchOne(
                'SELECT COUNT(DISTINCT ev.moodle_user_id) AS student_count
                 FROM events ev WHERE (ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?) AND (ev.account_id = ? OR ev.account_id = 0)',
                [$quizId, $internalExamId, $accountId]
            );
            $studentsCount = (int)($fallback['student_count'] ?? 0);
            $sessionsCount = $studentsCount;
        }

        $riskDist = Database::fetchAll(
            'SELECT risk_level AS level, COUNT(*) AS cnt
             FROM session_summaries WHERE (exam_id = ? OR exam_id = ?) AND (account_id = ? OR account_id = 0)
             GROUP BY risk_level',
            [$internalExamId, $quizId, $accountId]
        );

        $overTime = Database::fetchAll(
            "SELECT DATE_FORMAT(event_time, '%Y-%m-%d %H:00') AS bucket, COUNT(*) AS cnt
             FROM events
             WHERE (moodle_quiz_id = ? OR moodle_quiz_id = ?) AND (account_id = ? OR account_id = 0)
             GROUP BY bucket ORDER BY bucket ASC",
            [$quizId, $internalExamId, $accountId]
        );

        $eventTypes = Database::fetchAll(
            'SELECT event_type AS type, COUNT(*) AS cnt
             FROM events WHERE (moodle_quiz_id = ? OR moodle_quiz_id = ?) AND (account_id = ? OR account_id = 0)
             GROUP BY event_type ORDER BY cnt DESC',
            [$quizId, $internalExamId, $accountId]
        );

        $course = Database::fetchOne(
            'SELECT id, name, moodle_course_id FROM courses WHERE moodle_course_id = ? AND (account_id = ? OR account_id = 0)',
            [(int)$exam['moodle_course_id'], $accountId]
        );

        Response::ok([
            'exam' => $exam,
            'course' => $course ? [
                'id' => (int)$course['id'],
                'name' => $course['name'],
                'moodle_course_id' => (int)$course['moodle_course_id'],
            ] : [
                'id' => (int)$exam['moodle_course_id'],
                'name' => 'مساق #' . $exam['moodle_course_id'],
                'moodle_course_id' => (int)$exam['moodle_course_id'],
            ],
            'counts' => [
                'students' => $studentsCount,
                'sessions' => $sessionsCount,
                'events' => $eventsCount,
                'suspicious' => (int)($counts['suspicious_count'] ?? 0),
            ],
            'risk_distribution' => $riskDist,
            'events_over_time' => array_map(fn($r) => ['time' => $r['bucket'], 'events' => (int)$r['cnt']], $overTime),
            'event_types' => array_map(fn($r) => ['type' => $r['type'], 'count' => (int)$r['cnt']], $eventTypes),
        ]);
    }

    /** Students of one of the teacher's exams, with risk + filters. */
    public static function examStudents(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Incrementally aggregate any pending events
        try { Aggregator::process(2000); } catch (\Throwable $e) {}

        try {
            $exam = self::ownedExam($id, $accountId, $teacherId);
            $targetExamId = (int)($exam['moodle_quiz_id'] ?? $id);
        } catch (\Throwable $e) {
            $targetExamId = $id;
        }

        try {
            $students = Analytics::examStudents($targetExamId, $accountId);
            if (empty($students)) {
                try { Aggregator::process(5000); } catch (\Throwable $e) {}
                $students = Analytics::examStudents($targetExamId, $accountId);
            }
        } catch (\Throwable $e) {
            $students = [];
        }

        $risk = strtolower(trim((string)($_GET['risk'] ?? '')));
        $search = trim((string)($_GET['q'] ?? ''));
        $sort = (string)($_GET['sort'] ?? 'risk_desc');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        if ($risk !== '' && $risk !== 'all' && $risk !== 'any') {
            $filtered = array_values(array_filter($students, function($s) use ($risk) {
                $lvl = strtolower((string)($s['risk_level'] ?? 'safe'));
                if ($risk === 'suspicious' || $risk === 'flagged') {
                    return in_array($lvl, ['medium', 'high', 'critical'], true) || ((int)($s['risk_score'] ?? 0) >= 20);
                }
                return $lvl === $risk;
            }));
            // Only apply filter if it doesn't accidentally wipe out all students when risk filter is loose
            if (!empty($filtered) || $risk === 'critical' || $risk === 'high') {
                $students = $filtered;
            }
        }
        if ($search !== '') {
            $students = array_values(array_filter(
                $students,
                fn($s) => str_contains(mb_strtolower($s['fullname']), mb_strtolower($search))
                      || str_contains(mb_strtolower($s['username']), mb_strtolower($search))
            ));
        }

        usort($students, function ($a, $b) use ($sort) {
            return match ($sort) {
                'risk_asc' => $a['risk_score'] <=> $b['risk_score'],
                'name' => mb_strcmp($a['fullname'], $b['fullname']),
                'events_desc' => $b['event_count'] <=> $a['event_count'],
                default => $b['risk_score'] <=> $a['risk_score'],
            };
        });

        $total = count($students);
        $offset = ($page - 1) * $limit;
        $pageStudents = array_slice($students, $offset, $limit);

        Response::ok([
            'students' => $pageStudents,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    private static function emptySummary(): array
    {
        return [
            'exams_count' => 0, 'active_exams' => 0, 'courses_count' => 0,
            'students_count' => 0, 'sessions_count' => 0, 'suspicious_count' => 0,
        ];
    }

    /**
     * GET /api/teacher/analytics
     * Rich aggregated analytics across ALL the teacher's exams.
     */
    public static function analytics(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Incrementally aggregate any pending events
        try { Aggregator::process(2000); } catch (\Throwable $e) {}

        $ids = Teachers::courseIds($accountId, $teacherId);
        $courseId = (int)($_GET['course_id'] ?? 0);
        $examId = (int)($_GET['exam_id'] ?? 0);
        $singleExam = null;

        if ($examId > 0) {
            $examRows = Database::fetchAll(
                "SELECT e.id, e.moodle_quiz_id, e.moodle_course_id, e.name, e.status, c.name AS course_name
                   FROM exams e
                   LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = ? OR c.account_id = 0)
                  WHERE (e.id = ? OR e.moodle_quiz_id = ?) AND (e.account_id = ? OR e.account_id = 0)",
                [$accountId, $examId, $examId, $accountId]
            );
            if (!empty($examRows) && Auth::teacherOwnsExam($accountId, $teacherId, $examRows[0])) {
                $singleExam = [
                    'id'              => (int)$examRows[0]['id'],
                    'moodle_quiz_id'  => (int)$examRows[0]['moodle_quiz_id'],
                    'moodle_course_id'=> (int)$examRows[0]['moodle_course_id'],
                    'name'            => $examRows[0]['name'],
                    'status'          => $examRows[0]['status'],
                    'course_name'     => $examRows[0]['course_name'] ?? '',
                ];
            }
        } elseif ($courseId > 0) {
            $ids = in_array($courseId, $ids, true) ? [$courseId] : [];
        }

        if ($singleExam !== null) {
            $examRows = [$singleExam];
        } else {
            if ($ids === []) {
                Response::ok(self::emptyAnalytics());
                return;
            }
            $in = self::safeInts($ids);
            $examRows = Database::fetchAll(
                "SELECT e.id, e.moodle_quiz_id, e.name, e.status, c.name AS course_name
                   FROM exams e
                   LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = ? OR c.account_id = 0)
                  WHERE (e.account_id = ? OR e.account_id = 0) AND e.moodle_course_id IN ($in)",
                [$accountId, $accountId]
            );
        }

        $examIds = array_map(fn($r) => (int)$r['id'], $examRows);
        $quizIds = array_map(fn($r) => (int)$r['moodle_quiz_id'], $examRows);
        $allExamIds = array_unique(array_filter(array_merge($examIds, $quizIds)));
        if (empty($allExamIds)) {
            Response::ok(self::emptyAnalytics());
            return;
        }
        $ein = self::safeInts($allExamIds);

        // Totals
        $totals = Database::fetchOne(
            "SELECT
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id IN ($ein) AND (ss.account_id = ? OR ss.account_id = 0)) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id IN ($ein) AND (ss.account_id = ? OR ss.account_id = 0)) AS sessions_count,
                (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id IN (
                    SELECT e2.moodle_quiz_id FROM exams e2 WHERE (e2.id IN ($ein) OR e2.moodle_quiz_id IN ($ein)) AND (e2.account_id = ? OR e2.account_id = 0)
                ) AND (ev.account_id = ? OR ev.account_id = 0)) AS events_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                   WHERE ss.exam_id IN ($ein) AND (ss.account_id = ? OR ss.account_id = 0) AND ss.risk_level IN ('high','critical')) AS suspicious_count,
                (SELECT COUNT(*) FROM exams e3 WHERE e3.id IN ($ein) AND (e3.status = 'active' OR e3.last_event_at >= (UTC_TIMESTAMP() - INTERVAL 2 HOUR) OR e3.last_event_at >= (NOW() - INTERVAL 2 HOUR))) AS active_exams",
            [$accountId, $accountId, $accountId, $accountId, $accountId]
        );

        // Fallback: If sessions_count is 0 but events exist, populate from events table
        if ((int)($totals['students_count'] ?? 0) === 0 && (int)($totals['events_count'] ?? 0) > 0) {
            $evCounts = Database::fetchOne(
                "SELECT COUNT(DISTINCT ev.moodle_user_id) AS st_cnt, COUNT(DISTINCT ev.session_id) AS sess_cnt
                 FROM events ev WHERE ev.moodle_quiz_id IN (
                     SELECT e2.moodle_quiz_id FROM exams e2 WHERE (e2.id IN ($ein) OR e2.moodle_quiz_id IN ($ein)) AND (e2.account_id = ? OR e2.account_id = 0)
                 ) AND (ev.account_id = ? OR ev.account_id = 0)",
                [$accountId, $accountId]
            );
            $totals['students_count'] = (int)($evCounts['st_cnt'] ?? 0);
            $totals['sessions_count'] = (int)($evCounts['sess_cnt'] ?? 0);
        }

        // Risk distribution
        $riskDist = Database::fetchAll(
            "SELECT risk_level AS level, COUNT(*) AS cnt
               FROM session_summaries ss WHERE ss.exam_id IN ($ein) AND (ss.account_id = ? OR ss.account_id = 0)
              GROUP BY risk_level",
            [$accountId]
        );
        if (empty($riskDist)) {
            $riskDist = Database::fetchAll(
                "SELECT risk_level AS level, COUNT(*) AS cnt
                   FROM session_summaries WHERE exam_id IN ($ein)
                  GROUP BY risk_level"
            );
        }

        // Event types breakdown
        $eventTypes = Database::fetchAll(
            "SELECT ev.event_type AS type, COUNT(*) AS cnt
               FROM events ev
              WHERE ev.moodle_quiz_id IN (
                SELECT e2.moodle_quiz_id FROM exams e2 WHERE (e2.id IN ($ein) OR e2.moodle_quiz_id IN ($ein))
              ) AND (ev.account_id = ? OR ev.account_id = 0)
              GROUP BY ev.event_type ORDER BY cnt DESC LIMIT 12",
            [$accountId]
        );

        // Events over time (24h buckets, last 30 days)
        $eventsOverTime = Database::fetchAll(
            "SELECT DATE_FORMAT(ev.event_time, '%Y-%m-%d') AS bucket, COUNT(*) AS cnt
               FROM events ev
              WHERE ev.moodle_quiz_id IN (
                SELECT e2.moodle_quiz_id FROM exams e2 WHERE (e2.id IN ($ein) OR e2.moodle_quiz_id IN ($ein))
              ) AND (ev.account_id = ? OR ev.account_id = 0)
                AND ev.event_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY bucket ORDER BY bucket ASC",
            [$accountId]
        );

        // Category averages across all sessions
        $catAvgs = Database::fetchOne(
            "SELECT
                ROUND(AVG(ss.risk_score), 1) AS avg_risk,
                ROUND(AVG(ss.same_ip_risk_score), 1) AS avg_network,
                ROUND(AVG(ss.ai_suspect_score), 1) AS avg_ai,
                ROUND(AVG(ss.similarity_max_score), 1) AS avg_similarity,
                COUNT(CASE WHEN ss.same_ip_student_count > 0 THEN 1 END) AS ip_group_count,
                COUNT(CASE WHEN ss.ai_suspect_score >= 50 THEN 1 END) AS ai_flagged,
                COUNT(CASE WHEN ss.similarity_max_score >= 50 THEN 1 END) AS sim_flagged
               FROM session_summaries ss
              WHERE ss.exam_id IN ($ein) AND (ss.account_id = ? OR ss.account_id = 0)",
            [$accountId]
        );

        // Top risky students grouped by student_id
        $topRisky = Database::fetchAll(
            "SELECT ss.student_id,
                    MAX(ss.risk_score) AS risk_score,
                    MAX(ss.risk_level) AS risk_level,
                    MAX(ss.same_ip_student_count) AS same_ip_student_count,
                    MAX(ss.same_ip_risk_score) AS same_ip_risk_score,
                    MAX(ss.ai_suspect_score) AS ai_suspect_score,
                    MAX(ss.similarity_max_score) AS similarity_max_score,
                    SUM(ss.tab_hidden_count) AS tab_hidden_count,
                    SUM(ss.paste_count) AS paste_count,
                    SUM(ss.copy_count) AS copy_count,
                    SUM(ss.devtools_count) AS devtools_count,
                    SUM(ss.event_count) AS event_count,
                    COALESCE(MAX(s.fullname), CONCAT('طالب #', ss.student_id)) AS fullname,
                    COALESCE(MAX(s.username), '') AS username,
                    COALESCE(MAX(e.name), 'اختبار أسئلة دينية عامة') AS exam_name,
                    COALESCE(MAX(ss.exam_id), 0) AS exam_id
               FROM session_summaries ss
               LEFT JOIN students s ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id)
               LEFT JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
              WHERE (ss.exam_id IN ($ein) OR ss.session_id IN (SELECT session_id FROM events WHERE moodle_quiz_id IN (SELECT moodle_quiz_id FROM exams WHERE id IN ($ein))))
              GROUP BY ss.student_id
              ORDER BY risk_score DESC
              LIMIT 20"
        );

        Response::ok([
            'totals' => [
                'students'      => (int)($totals['students_count'] ?? 0),
                'sessions'      => (int)($totals['sessions_count'] ?? 0),
                'events'        => (int)($totals['events_count'] ?? 0),
                'suspicious'    => (int)($totals['suspicious_count'] ?? 0),
                'active_exams'  => (int)($totals['active_exams'] ?? 0),
                'total_exams'   => count($examIds),
            ],
            'risk_distribution' => array_map(fn($r) => [
                'level' => $r['level'], 'count' => (int)$r['cnt']
            ], $riskDist),
            'event_types' => array_map(fn($r) => [
                'type' => $r['type'], 'count' => (int)$r['cnt']
            ], $eventTypes),
            'events_over_time' => array_map(fn($r) => [
                'date' => $r['bucket'], 'events' => (int)$r['cnt']
            ], $eventsOverTime),
            'category_averages' => [
                'risk'       => (float)($catAvgs['avg_risk'] ?? 0),
                'network'    => (float)($catAvgs['avg_network'] ?? 0),
                'ai'         => (float)($catAvgs['avg_ai'] ?? 0),
                'similarity' => (float)($catAvgs['avg_similarity'] ?? 0),
            ],
            'flags' => [
                'ip_group'  => (int)($catAvgs['ip_group_count'] ?? 0),
                'ai_flagged'=> (int)($catAvgs['ai_flagged'] ?? 0),
                'sim_flagged'=>(int)($catAvgs['sim_flagged'] ?? 0),
            ],
            'top_risky' => array_map(fn($r) => [
                'student_id'    => (int)$r['student_id'],
                'fullname'      => $r['fullname'],
                'username'      => $r['username'],
                'exam_name'     => $r['exam_name'],
                'exam_id'       => (int)$r['exam_id'],
                'risk_score'    => (int)$r['risk_score'],
                'risk_level'    => $r['risk_level'],
                'tab_hidden'    => (int)$r['tab_hidden_count'],
                'paste_count'   => (int)$r['paste_count'],
                'copy_count'    => (int)$r['copy_count'],
                'devtools_count'=> (int)$r['devtools_count'],
                'event_count'   => (int)$r['event_count'],
                'same_ip'       => (int)$r['same_ip_student_count'],
                'ai_score'      => (int)$r['ai_suspect_score'],
                'sim_score'     => (int)$r['similarity_max_score'],
            ], $topRisky),
            'exam' => $singleExam,
        ]);
    }

    private static function emptyStudentTotals(): array
    {
        return ['total_students' => 0, 'high_risk' => 0, 'ai_flagged' => 0, 'network_flagged' => 0, 'sim_flagged' => 0];
    }

    private static function emptyAnalytics(): array
    {
        return [
            'totals' => ['students'=>0,'sessions'=>0,'events'=>0,'suspicious'=>0,'active_exams'=>0,'total_exams'=>0],
            'risk_distribution' => [],
            'event_types' => [],
            'events_over_time' => [],
            'category_averages' => ['risk'=>0,'network'=>0,'ai'=>0,'similarity'=>0],
            'flags' => ['ip_group'=>0,'ai_flagged'=>0,'sim_flagged'=>0],
            'top_risky' => [],
        ];
    }

    /** Fetch an exam and enforce that the teacher owns it. */
    private static function ownedExam(int $id, int $accountId, int $teacherId): array
    {
        $exam = Database::fetchOne('SELECT * FROM exams WHERE (id = ? OR moodle_quiz_id = ?) AND (account_id = ? OR account_id = 0)', [$id, $id, $accountId]);
        if (!$exam || !Auth::teacherOwnsExam($accountId, $teacherId, $exam)) {
            Response::error('الامتحان غير موجود أو لا يخصّك', 404);
        }
        return $exam;
    }

    /* ── v9: Advanced Analytics Endpoints ────────────────────────── */

    /** Network groups for an exam (students sharing same IP). */
    public static function examNetworkGroups(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $exam = self::ownedExam($id, $accountId, $teacherId);
        $eId = (int)$exam['id'];
        $qId = (int)$exam['moodle_quiz_id'];

        try {
            NetworkAnalyzer::analyzeExam($accountId, $eId);
        } catch (\Throwable $e) {}

        Database::ensureColumn('network_groups', 'detected_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        $groups = [];
        try {
            $groups = Database::fetchAll(
                'SELECT id, ip_address, student_count, student_ids, risk_level, detected_at
                 FROM network_groups
                 WHERE (account_id = ? OR account_id = 0) AND (exam_id = ? OR exam_id = ?)
                 ORDER BY student_count DESC, risk_level DESC',
                [$accountId, $eId, $qId]
            );
        } catch (\Throwable $e) {
            try {
                $groups = Database::fetchAll(
                    'SELECT id, ip_address, student_count, student_ids, risk_level, COALESCE(created_at, NOW()) AS detected_at
                     FROM network_groups
                     WHERE (account_id = ? OR account_id = 0) AND (exam_id = ? OR exam_id = ?)
                     ORDER BY student_count DESC, risk_level DESC',
                    [$accountId, $eId, $qId]
                );
            } catch (\Throwable $e2) {
                $groups = [];
            }
        }

        // Enrich with student names across all groups in one query
        $allSids = [];
        foreach ($groups as $g) {
            $rawSids = json_decode($g['student_ids'] ?? '[]', true);
            if (is_array($rawSids)) {
                foreach ($rawSids as $sid) {
                    $val = (int)$sid;
                    if ($val > 0) $allSids[] = $val;
                }
            }
        }
        $allSids = array_values(array_unique($allSids));
        $nameMap = [];
        if (!empty($allSids)) {
            $placeholders = implode(',', array_fill(0, count($allSids), '?'));
            $students = Database::fetchAll(
                "SELECT id, moodle_user_id, fullname, username FROM students WHERE (id IN ($placeholders) OR moodle_user_id IN ($placeholders)) AND (account_id = ? OR account_id = 0)",
                array_merge($allSids, $allSids, [$accountId])
            );
            foreach ($students as $s) {
                $info = [
                    'fullname' => $s['fullname'],
                    'username' => $s['username'],
                ];
                $nameMap[(int)$s['id']] = $info;
                if (!empty($s['moodle_user_id'])) {
                    $nameMap[(int)$s['moodle_user_id']] = $info;
                }
            }
        }

        $allGroups = [];
        foreach ($groups as $g) {
            $rawSids = json_decode($g['student_ids'] ?? '[]', true);
            $names = [];
            if (is_array($rawSids)) {
                foreach ($rawSids as $rawSid) {
                    $sid = (int)$rawSid;
                    if ($sid <= 0) continue;
                    $info = $nameMap[$sid] ?? ['fullname' => "طالب #$sid", 'username' => ''];
                    $names[] = [
                        'id'            => $sid,
                        'student_id'    => $sid,
                        'fullname'      => $info['fullname'],
                        'username'      => $info['username'],
                        'session_count' => 1,
                    ];
                }
            }
            $riskScore = ($g['risk_level'] === 'critical' ? 95 : ($g['risk_level'] === 'high' ? 85 : ($g['risk_level'] === 'medium' ? 50 : 15)));
            $allGroups[] = [
                'id'            => (int)$g['id'],
                'ip'            => $g['ip_address'],
                'ip_address'    => $g['ip_address'],
                'student_count' => (int)$g['student_count'],
                'students'      => $names,
                'risk_level'    => $g['risk_level'],
                'risk_score'    => $riskScore,
                'detected_at'   => $g['detected_at'] ?? date('Y-m-d H:i:s'),
                'last_seen'     => $g['detected_at'] ?? date('Y-m-d H:i:s'),
            ];
        }

        Response::ok($allGroups);
    }

    /** Similarity pairs for an exam (students with matching answers). */
    public static function examSimilarityPairs(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $exam = self::ownedExam($id, $accountId, $teacherId);
        $eId = (int)$exam['id'];
        $qId = (int)$exam['moodle_quiz_id'];

        $minSim = max(0, min(100, (int)($_GET['min_similarity'] ?? 10)));

        try {
            SimilarityEngine::analyzeExam($accountId, $eId);
        } catch (\Throwable $e) {}

        Database::ensureColumn('similarity_pairs', 'question_details', 'MEDIUMTEXT NULL');
        Database::ensureColumn('similarity_pairs', 'detected_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        $pairs = [];
        try {
            $pairs = Database::fetchAll(
                'SELECT sp.student_a_id, sp.student_b_id, sp.similarity_pct,
                        sp.matching_questions, sp.total_questions, sp.detected_at, sp.question_details
                 FROM similarity_pairs sp
                 WHERE (sp.account_id = ? OR sp.account_id = 0) AND (sp.exam_id = ? OR sp.exam_id = ?) AND sp.similarity_pct >= ?
                 ORDER BY sp.similarity_pct DESC
                 LIMIT 200',
                [$accountId, $eId, $qId, $minSim]
            );
        } catch (\Throwable $e) {
            try {
                $pairs = Database::fetchAll(
                    'SELECT sp.student_a_id, sp.student_b_id, sp.similarity_pct,
                            sp.matching_questions, sp.total_questions,
                            COALESCE(sp.created_at, NOW()) AS detected_at,
                            NULL AS question_details
                     FROM similarity_pairs sp
                     WHERE (sp.account_id = ? OR sp.account_id = 0) AND (sp.exam_id = ? OR sp.exam_id = ?) AND sp.similarity_pct >= ?
                     ORDER BY sp.similarity_pct DESC
                     LIMIT 200',
                    [$accountId, $eId, $qId, $minSim]
                );
            } catch (\Throwable $e2) {
                $pairs = [];
            }
        }

        // Enrich with student names
        $allIds = [];
        foreach ($pairs as $p) {
            $sa = (int)($p['student_a_id'] ?? 0);
            $sb = (int)($p['student_b_id'] ?? 0);
            if ($sa > 0) $allIds[] = $sa;
            if ($sb > 0) $allIds[] = $sb;
        }
        $allIds = array_values(array_unique($allIds));
        $nameMap = [];
        if (!empty($allIds)) {
            $placeholders = implode(',', array_fill(0, count($allIds), '?'));
            $students = Database::fetchAll(
                "SELECT id, moodle_user_id, fullname, username FROM students WHERE (id IN ($placeholders) OR moodle_user_id IN ($placeholders)) AND (account_id = ? OR account_id = 0)",
                array_merge($allIds, $allIds, [$accountId])
            );
            foreach ($students as $s) {
                $info = [
                    'fullname' => $s['fullname'],
                    'username' => $s['username'],
                ];
                $nameMap[(int)$s['id']] = $info;
                if (!empty($s['moodle_user_id'])) {
                    $nameMap[(int)$s['moodle_user_id']] = $info;
                }
            }
        }

        $result = [];
        foreach ($pairs as $p) {
            $sa = (int)$p['student_a_id'];
            $sb = (int)$p['student_b_id'];
            $nameA = $nameMap[$sa]['fullname'] ?? ("طالب #" . $sa);
            $userA = $nameMap[$sa]['username'] ?? '';
            $nameB = $nameMap[$sb]['fullname'] ?? ("طالب #" . $sb);
            $userB = $nameMap[$sb]['username'] ?? '';
            $simScore = (int)round((float)$p['similarity_pct']);
            $riskLvl = ($simScore >= 75 ? 'critical' : ($simScore >= 50 ? 'high' : ($simScore >= 25 ? 'medium' : 'low')));
            $qDetails = !empty($p['question_details']) ? json_decode($p['question_details'], true) : [];

            $result[] = [
                'student_a' => [
                    'id'       => $sa,
                    'fullname' => $nameA,
                    'username' => $userA,
                ],
                'student_b' => [
                    'id'       => $sb,
                    'fullname' => $nameB,
                    'username' => $userB,
                ],
                'similarity_pct'    => (float)$p['similarity_pct'],
                'matching_questions'=> (int)$p['matching_questions'],
                'total_questions'   => (int)$p['total_questions'],
                'detected_at'       => $p['detected_at'],
                'question_details'  => $qDetails ?: [],
                // COMPATIBILITY with SimilarityDetection.jsx:
                'student1_id'       => $sa,
                'student1_name'     => $nameA,
                'student1_username' => $userA,
                'student2_id'       => $sb,
                'student2_name'     => $nameB,
                'student2_username' => $userB,
                'similarity_score'  => $simScore,
                'risk_level'        => $riskLvl,
            ];
        }

        Response::ok($result);
    }

    /** v9: Enhanced exam detail with category breakdown. */
    public static function examDetailV9(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        self::ownedExam($id, $accountId, $teacherId);

        // Category-level averages
        $catAvgs = Database::fetchOne(
            'SELECT
                ROUND(AVG(same_ip_risk_score), 1) AS avg_network,
                ROUND(AVG(ai_suspect_score), 1) AS avg_ai,
                ROUND(AVG(similarity_max_score), 1) AS avg_similarity,
                ROUND(AVG(risk_score), 1) AS avg_risk,
                COUNT(CASE WHEN same_ip_student_count > 0 THEN 1 END) AS ip_group_count,
                COUNT(CASE WHEN ai_suspect_score >= 50 THEN 1 END) AS ai_flagged,
                COUNT(CASE WHEN similarity_max_score >= 50 THEN 1 END) AS sim_flagged,
                COUNT(CASE WHEN risk_level IN ("high","critical") THEN 1 END) AS high_risk_count
             FROM session_summaries
             WHERE exam_id = ? AND account_id = ?',
            [$id, $accountId]
        );

        // Top risky students with full breakdown grouped by student_id
        $topRisky = Database::fetchAll(
            'SELECT ss.student_id,
                    MAX(ss.session_id) AS session_id,
                    MAX(ss.risk_score) AS risk_score,
                    MAX(ss.risk_level) AS risk_level,
                    MAX(ss.same_ip_student_count) AS same_ip_student_count,
                    MAX(ss.ip_changed_count) AS ip_changed_count,
                    MAX(ss.same_ip_risk_score) AS same_ip_risk_score,
                    MAX(ss.ai_suspect_score) AS ai_suspect_score,
                    MAX(ss.answer_text_count) AS answer_text_count,
                    MAX(ss.typing_answer_ratio) AS typing_answer_ratio,
                    MAX(ss.similarity_max_score) AS similarity_max_score,
                    MAX(ss.similarity_match_count) AS similarity_match_count,
                    COALESCE(MAX(s.fullname), CONCAT("طالب #", ss.student_id)) AS fullname,
                    COALESCE(MAX(s.username), "") AS username
             FROM session_summaries ss
             LEFT JOIN students s ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id)
             WHERE (ss.exam_id = ? OR ss.exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ? OR id = ?))
             GROUP BY ss.student_id
             ORDER BY risk_score DESC
             LIMIT 20',
            [$id, $id, $id]
        );

        Response::ok([
            'category_averages' => [
                'network'  => (float)($catAvgs['avg_network'] ?? 0),
                'ai'       => (float)($catAvgs['avg_ai'] ?? 0),
                'similarity'=> (float)($catAvgs['avg_similarity'] ?? 0),
                'risk'     => (float)($catAvgs['avg_risk'] ?? 0),
            ],
            'flags' => [
                'ip_group_count' => (int)($catAvgs['ip_group_count'] ?? 0),
                'ai_flagged'     => (int)($catAvgs['ai_flagged'] ?? 0),
                'sim_flagged'    => (int)($catAvgs['sim_flagged'] ?? 0),
                'high_risk'      => (int)($catAvgs['high_risk_count'] ?? 0),
            ],
            'top_risky' => array_map(fn($r) => [
                'session_id'           => $r['session_id'],
                'fullname'             => $r['fullname'],
                'username'             => $r['username'],
                'risk_score'           => (int)$r['risk_score'],
                'risk_level'           => $r['risk_level'],
                'same_ip_student_count'=> (int)$r['same_ip_student_count'],
                'ip_changed_count'     => (int)$r['ip_changed_count'],
                'same_ip_risk_score'   => (int)$r['same_ip_risk_score'],
                'ai_suspect_score'     => (int)$r['ai_suspect_score'],
                'typing_answer_ratio'  => (float)$r['typing_answer_ratio'],
                'similarity_max_score' => (int)$r['similarity_max_score'],
                'similarity_match_count'=> (int)$r['similarity_match_count'],
            ], $topRisky),
        ]);
    }

    /** Multi-device detection for an exam. */
    public static function examMultiDevice(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $exam = self::ownedExam($id, $accountId, $teacherId);
        $eId = (int)$exam['id'];
        $qId = (int)$exam['moodle_quiz_id'];

        $devices = Database::fetchAll(
            "SELECT sd.student_id, sd.ip_address, sd.browser_fp, sd.user_agent,
                    sd.first_seen, sd.last_seen, sd.snapshot_count,
                    s.fullname, s.username
             FROM student_devices sd
             JOIN students s ON s.id = sd.student_id AND (s.account_id = sd.account_id OR s.account_id = 0)
             WHERE (sd.account_id = ? OR sd.account_id = 0) AND (sd.exam_id = ? OR sd.exam_id = ?)
             ORDER BY sd.student_id, sd.first_seen",
            [$accountId, $eId, $qId]
        );

        // Group by student
        $byStudent = [];
        foreach ($devices as $d) {
            $sid = (int)$d['student_id'];
            if (!isset($byStudent[$sid])) {
                $byStudent[$sid] = [
                    'student_id' => $sid,
                    'fullname'   => $d['fullname'],
                    'username'   => $d['username'],
                    'devices'    => [],
                    'ips'        => [],
                ];
            }
            $byStudent[$sid]['devices'][] = [
                'ip'         => $d['ip_address'],
                'fingerprint'=> $d['browser_fp'],
                'user_agent' => $d['user_agent'],
                'first_seen' => $d['first_seen'],
                'last_seen'  => $d['last_seen'],
                'snapshots'  => (int)$d['snapshot_count'],
            ];
            $byStudent[$sid]['ips'][] = $d['ip_address'];
        }

        // Mark multi-device students
        $result = [];
        foreach ($byStudent as $student) {
            $student['ips'] = array_unique($student['ips']);
            $student['device_count'] = count($student['devices']);
            $student['is_multi_device'] = $student['device_count'] > 1;
            $student['is_multi_ip'] = count($student['ips']) > 1;
            $student['risk_level'] = 'safe';
            if ($student['device_count'] >= 3) $student['risk_level'] = 'critical';
            elseif ($student['device_count'] >= 2) $student['risk_level'] = 'high';
            elseif (count($student['ips']) >= 2) $student['risk_level'] = 'medium';
            $result[] = $student;
        }

        // Sort by risk
        usort($result, fn($a, $b) => $b['device_count'] <=> $a['device_count']);

        Response::ok($result);
    }

    /** IP timeline for a specific student in an exam. */
    public static function examStudentIPs(int $examId, int $studentId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        self::ownedExam($examId, $accountId, $teacherId);

        $snapshots = Database::fetchAll(
            "SELECT ip_address, browser_fp, detected_at
             FROM ip_snapshots
             WHERE account_id = ? AND exam_id = ? AND student_id = ?
             ORDER BY detected_at ASC",
            [$accountId, $examId, $studentId]
        );

        $timeline = [];
        foreach ($snapshots as $s) {
            $timeline[] = [
                'ip'         => $s['ip_address'],
                'fingerprint'=> $s['browser_fp'],
                'time'       => $s['detected_at'],
            ];
        }

        // Detect IP changes
        $changes = [];
        $prevIP = null;
        foreach ($timeline as $t) {
            if ($prevIP !== null && $t['ip'] !== $prevIP) {
                $changes[] = [
                    'from' => $prevIP,
                    'to'   => $t['ip'],
                    'time' => $t['time'],
                ];
            }
            $prevIP = $t['ip'];
        }

        Response::ok([
            'timeline' => $timeline,
            'changes'  => $changes,
            'unique_ips' => array_unique(array_column($timeline, 'ip')),
            'change_count' => count($changes),
        ]);
    }

    /* ── v9: All-exams aggregated endpoints (no exam ID) ─────────── */

    /** All network groups across teacher's exams. */
    public static function allNetworkGroups(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        $isAdmin = Auth::isOwner();
        $ids = $isAdmin ? self::allCourseIds($accountId) : Teachers::courseIds($accountId, Auth::teacherId());
        $courseId = (int)($_GET['course_id'] ?? 0);
        $examId = (int)($_GET['exam_id'] ?? 0);
        if ($courseId > 0 && in_array($courseId, $ids, true)) {
            $ids = [$courseId];
        }
        if ($ids === []) { Response::ok([]); return; }
        $in = self::safeInts($ids);

        // Auto-run NetworkAnalyzer on exams in this course/exam so groups are always fresh
        $examSql = "SELECT id FROM exams WHERE (account_id = ? OR account_id = 0) AND moodle_course_id IN ($in)";
        $examParams = [$accountId];
        if ($examId > 0) {
            $examSql .= " AND (id = ? OR moodle_quiz_id = ?)";
            $examParams[] = $examId;
            $examParams[] = $examId;
        }
        $examRows = Database::fetchAll($examSql, $examParams);
        foreach ($examRows as $er) {
            try { NetworkAnalyzer::analyzeExam($accountId, (int)$er['id']); } catch (\Throwable $e) {}
        }

        $examFilter = ($examId > 0) ? " AND (ng.exam_id = $examId OR e.id = $examId OR e.moodle_quiz_id = $examId)" : "";

        Database::ensureColumn('network_groups', 'detected_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        $groups = [];
        try {
            $groups = Database::fetchAll(
                "SELECT ng.id, ng.ip_address, ng.student_count, ng.student_ids, ng.risk_level, ng.detected_at,
                        ng.exam_id, e.name AS exam_name
                 FROM network_groups ng
                 JOIN exams e ON (e.id = ng.exam_id OR e.moodle_quiz_id = ng.exam_id)
                 WHERE (ng.account_id = ? OR ng.account_id = 0) AND e.moodle_course_id IN ($in) $examFilter
                 ORDER BY ng.student_count DESC, ng.risk_level DESC
                 LIMIT 200",
                [$accountId]
            );
        } catch (\Throwable $e) {
            try {
                $groups = Database::fetchAll(
                    "SELECT ng.id, ng.ip_address, ng.student_count, ng.student_ids, ng.risk_level,
                            COALESCE(ng.created_at, NOW()) AS detected_at, ng.exam_id, e.name AS exam_name
                     FROM network_groups ng
                     JOIN exams e ON (e.id = ng.exam_id OR e.moodle_quiz_id = ng.exam_id)
                     WHERE (ng.account_id = ? OR ng.account_id = 0) AND e.moodle_course_id IN ($in) $examFilter
                     ORDER BY ng.student_count DESC, ng.risk_level DESC
                     LIMIT 200",
                    [$accountId]
                );
            } catch (\Throwable $e2) {
                $groups = [];
            }
        }

        // Enrich with student names across all groups in one batch
        $allSids = [];
        foreach ($groups as $g) {
            $rawSids = json_decode($g['student_ids'] ?? '[]', true);
            if (is_array($rawSids)) {
                foreach ($rawSids as $sid) {
                    $val = (int)$sid;
                    if ($val > 0) $allSids[] = $val;
                }
            }
        }
        $allSids = array_values(array_unique($allSids));
        $nameMap = [];
        if (!empty($allSids)) {
            $placeholders = implode(',', array_fill(0, count($allSids), '?'));
            $students = Database::fetchAll(
                "SELECT id, moodle_user_id, fullname, username FROM students WHERE (id IN ($placeholders) OR moodle_user_id IN ($placeholders)) AND (account_id = ? OR account_id = 0)",
                array_merge($allSids, $allSids, [$accountId])
            );
            foreach ($students as $s) {
                $info = [
                    'fullname' => $s['fullname'],
                    'username' => $s['username'],
                ];
                $nameMap[(int)$s['id']] = $info;
                if (!empty($s['moodle_user_id'])) {
                    $nameMap[(int)$s['moodle_user_id']] = $info;
                }
            }
        }

        $allGroups = [];
        foreach ($groups as $g) {
            $rawSids = json_decode($g['student_ids'] ?? '[]', true);
            $names = [];
            if (is_array($rawSids)) {
                foreach ($rawSids as $rawSid) {
                    $sid = (int)$rawSid;
                    if ($sid <= 0) continue;
                    $info = $nameMap[$sid] ?? ['fullname' => "طالب #$sid", 'username' => ''];
                    $names[] = [
                        'id'            => $sid,
                        'student_id'    => $sid,
                        'fullname'      => $info['fullname'],
                        'username'      => $info['username'],
                        'session_count' => 1,
                    ];
                }
            }
            $riskScore = ($g['risk_level'] === 'critical' ? 95 : ($g['risk_level'] === 'high' ? 85 : ($g['risk_level'] === 'medium' ? 50 : 15)));
            $allGroups[] = [
                'id'            => (int)$g['id'],
                'ip'            => $g['ip_address'],
                'ip_address'    => $g['ip_address'],
                'student_count' => (int)$g['student_count'],
                'students'      => $names,
                'risk_level'    => $g['risk_level'],
                'risk_score'    => $riskScore,
                'detected_at'   => $g['detected_at'] ?? date('Y-m-d H:i:s'),
                'last_seen'     => $g['detected_at'] ?? date('Y-m-d H:i:s'),
                'exam_id'       => (int)$g['exam_id'],
                'exam_name'     => $g['exam_name'] ?? '',
            ];
        }
        Response::ok($allGroups);
    }

    /** All similarity pairs across teacher's exams. */
    public static function allSimilarityPairs(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        $isAdmin = Auth::isOwner();
        $ids = $isAdmin ? self::allCourseIds($accountId) : Teachers::courseIds($accountId, Auth::teacherId());
        $courseId = (int)($_GET['course_id'] ?? 0);
        $examId = (int)($_GET['exam_id'] ?? 0);
        if ($courseId > 0 && in_array($courseId, $ids, true)) {
            $ids = [$courseId];
        }
        if ($ids === []) { Response::ok([]); return; }
        $in = self::safeInts($ids);

        try { Aggregator::process(500); } catch (\Throwable $e) {}

        // Auto-run SimilarityEngine on exams in this course/exam so pairs are always fresh
        $examSql = "SELECT id FROM exams WHERE (account_id = ? OR account_id = 0) AND moodle_course_id IN ($in)";
        $examParams = [$accountId];
        if ($examId > 0) {
            $examSql .= " AND (id = ? OR moodle_quiz_id = ?)";
            $examParams[] = $examId;
            $examParams[] = $examId;
        }
        $examRows = Database::fetchAll($examSql, $examParams);
        foreach ($examRows as $er) {
            try { SimilarityEngine::analyzeExam($accountId, (int)$er['id']); } catch (\Throwable $e) {}
        }

        Database::ensureColumn('similarity_pairs', 'question_details', 'MEDIUMTEXT NULL');
        Database::ensureColumn('similarity_pairs', 'detected_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        $minSim = max(0, min(100, (int)($_GET['min_similarity'] ?? 10)));
        $examFilter = ($examId > 0) ? " AND (sp.exam_id = $examId OR e.id = $examId OR e.moodle_quiz_id = $examId)" : "";

        $pairs = [];
        try {
            $pairs = Database::fetchAll(
                "SELECT sp.student_a_id, sp.student_b_id, sp.similarity_pct,
                        sp.matching_questions, sp.total_questions, sp.detected_at,
                        sp.exam_id, sp.question_details, e.name AS exam_name
                 FROM similarity_pairs sp
                 JOIN exams e ON (e.id = sp.exam_id OR e.moodle_quiz_id = sp.exam_id)
                 WHERE (sp.account_id = ? OR sp.account_id = 0) AND e.moodle_course_id IN ($in) $examFilter AND sp.similarity_pct >= ?
                 ORDER BY sp.similarity_pct DESC
                 LIMIT 200",
                [$accountId, $minSim]
            );
        } catch (\Throwable $e) {
            try {
                $pairs = Database::fetchAll(
                    "SELECT sp.student_a_id, sp.student_b_id, sp.similarity_pct,
                            sp.matching_questions, sp.total_questions,
                            COALESCE(sp.created_at, NOW()) AS detected_at,
                            sp.exam_id, NULL AS question_details, e.name AS exam_name
                     FROM similarity_pairs sp
                     JOIN exams e ON (e.id = sp.exam_id OR e.moodle_quiz_id = sp.exam_id)
                     WHERE (sp.account_id = ? OR sp.account_id = 0) AND e.moodle_course_id IN ($in) $examFilter AND sp.similarity_pct >= ?
                     ORDER BY sp.similarity_pct DESC
                     LIMIT 200",
                    [$accountId, $minSim]
                );
            } catch (\Throwable $e2) {
                $pairs = [];
            }
        }

        $allIds = [];
        foreach ($pairs as $p) {
            $sa = (int)($p['student_a_id'] ?? 0);
            $sb = (int)($p['student_b_id'] ?? 0);
            if ($sa > 0) $allIds[] = $sa;
            if ($sb > 0) $allIds[] = $sb;
        }
        $allIds = array_values(array_unique($allIds));
        $nameMap = [];
        if (!empty($allIds)) {
            $placeholders = implode(',', array_fill(0, count($allIds), '?'));
            $students = Database::fetchAll(
                "SELECT id, moodle_user_id, fullname, username FROM students WHERE (id IN ($placeholders) OR moodle_user_id IN ($placeholders)) AND (account_id = ? OR account_id = 0)",
                array_merge($allIds, $allIds, [$accountId])
            );
            foreach ($students as $s) {
                $info = [
                    'fullname' => $s['fullname'],
                    'username' => $s['username'],
                ];
                $nameMap[(int)$s['id']] = $info;
                if (!empty($s['moodle_user_id'])) {
                    $nameMap[(int)$s['moodle_user_id']] = $info;
                }
            }
        }

        $result = [];
        foreach ($pairs as $p) {
            $sa = (int)$p['student_a_id'];
            $sb = (int)$p['student_b_id'];
            $nameA = $nameMap[$sa]['fullname'] ?? ("طالب #" . $sa);
            $userA = $nameMap[$sa]['username'] ?? '';
            $nameB = $nameMap[$sb]['fullname'] ?? ("طالب #" . $sb);
            $userB = $nameMap[$sb]['username'] ?? '';
            $simScore = (int)round((float)$p['similarity_pct']);
            $riskLvl = ($simScore >= 75 ? 'critical' : ($simScore >= 50 ? 'high' : ($simScore >= 25 ? 'medium' : 'low')));
            $qDetails = !empty($p['question_details']) ? json_decode($p['question_details'], true) : [];

            $result[] = [
                'student_a' => ['id' => $sa, 'fullname' => $nameA, 'username' => $userA],
                'student_b' => ['id' => $sb, 'fullname' => $nameB, 'username' => $userB],
                'similarity_pct' => (float)$p['similarity_pct'],
                'matching_questions' => (int)$p['matching_questions'],
                'total_questions' => (int)$p['total_questions'],
                'detected_at' => $p['detected_at'],
                'exam_id' => (int)$p['exam_id'], 'exam_name' => $p['exam_name'] ?? '',
                'question_details' => $qDetails ?: [],
                // COMPATIBILITY with SimilarityDetection.jsx:
                'student1_id'       => $sa,
                'student1_name'     => $nameA,
                'student1_username' => $userA,
                'student2_id'       => $sb,
                'student2_name'     => $nameB,
                'student2_username' => $userB,
                'similarity_score'  => $simScore,
                'risk_level'        => $riskLvl,
            ];
        }
        Response::ok($result);
    }

    /** All multi-device students across teacher's exams. */
    public static function allMultiDevice(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        $isAdmin = Auth::isOwner();
        $ids = $isAdmin ? self::allCourseIds($accountId) : Teachers::courseIds($accountId, Auth::teacherId());
        $courseId = (int)($_GET['course_id'] ?? 0);
        $examId = (int)($_GET['exam_id'] ?? 0);
        if ($courseId > 0 && in_array($courseId, $ids, true)) {
            $ids = [$courseId];
        }
        if ($ids === []) { Response::ok([]); return; }
        $in = self::safeInts($ids);

        $examFilter = ($examId > 0) ? " AND (sd.exam_id = $examId OR e.id = $examId OR e.moodle_quiz_id = $examId)" : "";

        $devices = Database::fetchAll(
            "SELECT sd.student_id, sd.ip_address, sd.browser_fp, sd.user_agent,
                    sd.first_seen, sd.last_seen, sd.snapshot_count, sd.exam_id,
                    s.fullname, s.username, e.name AS exam_name
             FROM student_devices sd
             LEFT JOIN students s ON (s.id = sd.student_id OR s.moodle_user_id = sd.student_id) AND (s.account_id = ? OR s.account_id = 0)
             JOIN exams e ON (e.id = sd.exam_id OR e.moodle_quiz_id = sd.exam_id) AND (e.account_id = ? OR e.account_id = 0)
             WHERE (sd.account_id = ? OR sd.account_id = 0) AND e.moodle_course_id IN ($in) $examFilter
             ORDER BY sd.student_id, sd.first_seen",
            [$accountId, $accountId, $accountId]
        );

        $byStudent = [];
        foreach ($devices as $d) {
            $sid = (int)$d['student_id'];
            if (!isset($byStudent[$sid])) {
                $byStudent[$sid] = [
                    'student_id' => $sid, 'fullname' => $d['fullname'], 'username' => $d['username'],
                    'devices' => [], 'ips' => [],
                ];
            }
            $byStudent[$sid]['devices'][] = [
                'ip' => $d['ip_address'], 'fingerprint' => $d['browser_fp'],
                'user_agent' => $d['user_agent'], 'first_seen' => $d['first_seen'],
                'last_seen' => $d['last_seen'], 'snapshots' => (int)$d['snapshot_count'],
                'exam_id' => (int)$d['exam_id'], 'exam_name' => $d['exam_name'] ?? '',
            ];
            $byStudent[$sid]['ips'][] = $d['ip_address'];
        }

        $result = [];
        foreach ($byStudent as $student) {
            $student['ips'] = array_unique($student['ips']);
            $student['device_count'] = count($student['devices']);
            $student['is_multi_device'] = $student['device_count'] > 1;
            $student['is_multi_ip'] = count($student['ips']) > 1;
            $student['risk_level'] = 'safe';
            if ($student['device_count'] >= 3) $student['risk_level'] = 'critical';
            elseif ($student['device_count'] >= 2) $student['risk_level'] = 'high';
            elseif (count($student['ips']) >= 2) $student['risk_level'] = 'medium';
            $result[] = $student;
        }
        usort($result, fn($a, $b) => $b['device_count'] <=> $a['device_count']);
        Response::ok($result);
    }

    /* ── All Students across teacher's exams ──────────────────────── */

    /** GET /api/teacher/students — all students across teacher's exams. */
    /** GET /api/teacher/students — all students across teacher's exams. */
    public static function students(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $isAdmin   = Auth::isOwner();

        $ids = $isAdmin ? self::allCourseIds($accountId) : Teachers::courseIds($accountId, $teacherId);
        $courseId = (int)($_GET['course_id'] ?? 0);
        if ($courseId > 0 && in_array($courseId, $ids, true)) {
            $ids = [$courseId];
        }
        if ($ids === []) { Response::ok(['students' => [], 'totals' => self::emptyStudentTotals()]); return; }
        $in = self::safeInts($ids);

        $search = trim((string)($_GET['q'] ?? ''));
        $risk   = (string)($_GET['risk'] ?? '');
        $sort   = (string)($_GET['sort'] ?? 'risk_desc');

        $courseFilterExams = $isAdmin && $courseId === 0 ? "" : " AND moodle_course_id IN ($in)";
        $courseFilterJoin  = $isAdmin && $courseId === 0 ? "" : " AND cs.moodle_course_id IN ($in)";
        $courseFilterE2    = $isAdmin && $courseId === 0 ? "" : " AND e2.moodle_course_id IN ($in)";
        $courseFilterE3    = $isAdmin && $courseId === 0 ? "" : " AND e3.moodle_course_id IN ($in)";

        $rows = Database::fetchAll(
            "SELECT s.id AS student_id, s.moodle_user_id, s.fullname, s.username,
                    MAX(IFNULL(ss.risk_score, 0)) AS risk_score,
                    MAX(IFNULL(ss.risk_level, 'safe')) AS risk_level,
                    MAX(IFNULL(ss.ai_suspect_score, 0)) AS ai_suspect_score,
                    MAX(IFNULL(ss.same_ip_student_count, 0)) AS same_ip_student_count,
                    MAX(IFNULL(ss.same_ip_risk_score, 0)) AS same_ip_risk_score,
                    MAX(IFNULL(ss.similarity_max_score, 0)) AS similarity_max_score,
                    MAX(IFNULL(ss.tab_hidden_count, 0)) AS tab_hidden_count,
                    MAX(IFNULL(ss.paste_count, 0)) AS paste_count,
                    MAX(IFNULL(ss.copy_count, 0)) AS copy_count,
                    MAX(IFNULL(ss.devtools_count, 0)) AS devtools_count,
                    SUM(IFNULL(ss.event_count, 0)) AS total_events,
                    COUNT(DISTINCT ss.exam_id) AS exams_count,
                    COUNT(DISTINCT ss.session_id) AS sessions_count,
                    MIN(ss.first_event_at) AS first_seen,
                    MAX(ss.last_event_at) AS last_seen
               FROM students s
               LEFT JOIN session_summaries ss ON (ss.student_id = s.id OR ss.student_id = s.moodle_user_id)
                    AND (ss.account_id = s.account_id OR ss.account_id = 0)
                    AND ss.exam_id IN (
                        SELECT id FROM exams WHERE (account_id = ? OR account_id = 0) {$courseFilterExams}
                        UNION
                        SELECT moodle_quiz_id FROM exams WHERE (account_id = ? OR account_id = 0) {$courseFilterExams}
                    )
              WHERE (s.account_id = ? OR s.account_id = 0)
                AND (
                    s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = ? OR cs.account_id = 0) {$courseFilterJoin})
                    OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = ? OR cs.account_id = 0) {$courseFilterJoin})
                    OR EXISTS (
                        SELECT 1 FROM session_summaries ss2
                        JOIN exams e2 ON (e2.id = ss2.exam_id OR e2.moodle_quiz_id = ss2.exam_id)
                        WHERE (ss2.student_id = s.id OR ss2.student_id = s.moodle_user_id) {$courseFilterE2}
                    )
                    OR EXISTS (
                        SELECT 1 FROM events ev
                        JOIN exams e3 ON (e3.moodle_quiz_id = ev.moodle_quiz_id OR e3.id = ev.moodle_quiz_id)
                        WHERE (ev.moodle_user_id = s.moodle_user_id OR ev.moodle_user_id = s.id) {$courseFilterE3}
                    )
                )
                AND s.username NOT IN (SELECT username FROM teachers WHERE (account_id = ? OR account_id = 0) AND username != '')
              GROUP BY s.id, s.moodle_user_id, s.fullname, s.username",
            [$accountId, $accountId, $accountId, $accountId, $accountId, $accountId]
        );

        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn($r) =>
                str_contains(mb_strtolower($r['fullname']), mb_strtolower($search))
                || str_contains(mb_strtolower($r['username']), mb_strtolower($search))
            ));
        }
        if ($risk !== '' && $risk !== 'all') {
            $rows = array_values(array_filter($rows, fn($r) => $r['risk_level'] === $risk));
        }

        usort($rows, function ($a, $b) use ($sort) {
            return match ($sort) {
                'risk_asc'  => (int)$a['risk_score'] <=> (int)$b['risk_score'],
                'name'      => mb_strcmp($a['fullname'], $b['fullname']),
                'events'    => (int)$b['total_events'] <=> (int)$a['total_events'],
                'exams'     => (int)$b['exams_count'] <=> (int)$a['exams_count'],
                'ai'        => (int)$b['ai_suspect_score'] <=> (int)$a['ai_suspect_score'],
                default     => (int)$b['risk_score'] <=> (int)$a['risk_score'],
            };
        });

        $total = count($rows);
        $pageStudents = array_slice($rows, 0, 500);

        $totals = Database::fetchOne(
            "SELECT
                COUNT(DISTINCT s.id) AS total_students,
                COUNT(DISTINCT CASE WHEN ss.risk_level IN ('high','critical') THEN s.id END) AS high_risk,
                COUNT(DISTINCT CASE WHEN ss.ai_suspect_score >= 50 THEN s.id END) AS ai_flagged,
                COUNT(DISTINCT CASE WHEN ss.same_ip_student_count > 0 THEN s.id END) AS network_flagged,
                COUNT(DISTINCT CASE WHEN ss.similarity_max_score >= 50 THEN s.id END) AS sim_flagged
               FROM students s
               LEFT JOIN session_summaries ss ON (ss.student_id = s.id OR ss.student_id = s.moodle_user_id)
                    AND (ss.account_id = s.account_id OR ss.account_id = 0)
                    AND ss.exam_id IN (
                        SELECT id FROM exams WHERE (account_id = ? OR account_id = 0) {$courseFilterExams}
                        UNION
                        SELECT moodle_quiz_id FROM exams WHERE (account_id = ? OR account_id = 0) {$courseFilterExams}
                    )
              WHERE (s.account_id = ? OR s.account_id = 0)
                AND (
                    s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = ? OR cs.account_id = 0) {$courseFilterJoin})
                    OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = ? OR cs.account_id = 0) {$courseFilterJoin})
                    OR EXISTS (
                        SELECT 1 FROM session_summaries ss2
                        JOIN exams e2 ON (e2.id = ss2.exam_id OR e2.moodle_quiz_id = ss2.exam_id)
                        WHERE (ss2.student_id = s.id OR ss2.student_id = s.moodle_user_id) {$courseFilterE2}
                    )
                    OR EXISTS (
                        SELECT 1 FROM events ev
                        JOIN exams e3 ON (e3.moodle_quiz_id = ev.moodle_quiz_id OR e3.id = ev.moodle_quiz_id)
                        WHERE (ev.moodle_user_id = s.moodle_user_id OR ev.moodle_user_id = s.id) {$courseFilterE3}
                    )
                )
                AND s.username NOT IN (SELECT username FROM teachers WHERE (account_id = ? OR account_id = 0) AND username != '')",
            [$accountId, $accountId, $accountId, $accountId, $accountId, $accountId]
        );

        Response::ok([
            'students' => array_map(fn($r) => [
                'student_id'           => (int)$r['student_id'],
                'moodle_user_id'       => (int)$r['moodle_user_id'],
                'fullname'             => $r['fullname'],
                'username'             => $r['username'],
                'risk_score'           => (int)$r['risk_score'],
                'risk_level'           => $r['risk_level'],
                'ai_suspect_score'     => (int)$r['ai_suspect_score'],
                'same_ip_student_count'=> (int)$r['same_ip_student_count'],
                'same_ip_risk_score'   => (int)$r['same_ip_risk_score'],
                'similarity_max_score' => (int)$r['similarity_max_score'],
                'tab_hidden_count'     => (int)$r['tab_hidden_count'],
                'paste_count'          => (int)$r['paste_count'],
                'copy_count'           => (int)$r['copy_count'],
                'devtools_count'       => (int)$r['devtools_count'],
                'total_events'         => (int)$r['total_events'],
                'exams_count'          => (int)$r['exams_count'],
                'sessions_count'       => (int)$r['sessions_count'],
                'first_seen'           => $r['first_seen'],
                'last_seen'            => $r['last_seen'],
            ], $pageStudents),
            'totals' => [
                'total_students' => (int)($totals['total_students'] ?? 0),
                'high_risk'      => (int)($totals['high_risk'] ?? 0),
                'ai_flagged'     => (int)($totals['ai_flagged'] ?? 0),
                'network_flagged'=> (int)($totals['network_flagged'] ?? 0),
                'sim_flagged'    => (int)($totals['sim_flagged'] ?? 0),
            ],
            'total' => $total,
        ]);
    }

    /** GET /api/teacher/students/{id} — individual student profile. */
    public static function studentDetail(int $studentId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $isAdmin = Auth::isOwner();

        $ids = $isAdmin ? self::allCourseIds($accountId) : Teachers::courseIds($accountId, $teacherId);
        if (empty($ids) && $teacherId > 0) {
            $examCourses = Database::fetchAll(
                'SELECT DISTINCT moodle_course_id FROM exams WHERE (account_id = ? OR account_id = 0) AND (moodle_teacher_id = ? OR teacher_name IN (SELECT username FROM teachers WHERE moodle_teacher_id = ?))',
                [$accountId, $teacherId, $teacherId]
            );
            $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['moodle_course_id'], $examCourses))));
        }
        if (empty($ids)) {
            $ids = self::allCourseIds($accountId);
        }
        $in = !empty($ids) ? self::safeInts($ids) : '0';

        // 1. Fetch student by primary id OR moodle_user_id
        $student = Database::fetchOne(
            "SELECT s.id, s.fullname, s.username, s.moodle_user_id
               FROM students s
              WHERE (s.id = ? OR s.moodle_user_id = ?) AND (s.account_id = ? OR s.account_id = 0)
              ORDER BY (s.account_id = ?) DESC LIMIT 1",
            [$studentId, $studentId, $accountId, $accountId]
        );

        // Fallback: discover student from events or session_summaries if not in students table
        if (!$student) {
            $evStudent = Database::fetchOne(
                "SELECT DISTINCT ev.moodle_user_id,
                                 COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ev.payload, '$.moodle.student.name')), ''),
                                          NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ev.payload, '$.student_name')), ''),
                                          '') AS fullname,
                                 COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ev.payload, '$.moodle.student.username')), ''),
                                          NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ev.payload, '$.username')), ''),
                                          '') AS username
                 FROM events ev
                 WHERE (ev.moodle_user_id = ? OR ev.session_id LIKE ?) AND (ev.account_id = ? OR ev.account_id = 0)
                 ORDER BY ev.id DESC LIMIT 1",
                [$studentId, "%$studentId%", $accountId]
            );
            if ($evStudent && !empty($evStudent['moodle_user_id'])) {
                $mUid = (int)$evStudent['moodle_user_id'];
                $sName = !empty($evStudent['fullname']) ? $evStudent['fullname'] : ('طالب #' . $mUid);
                $sUser = !empty($evStudent['username']) ? $evStudent['username'] : ('student_' . $mUid);
                try {
                    Database::execute(
                        "INSERT INTO students (account_id, moodle_user_id, fullname, username)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE fullname = VALUES(fullname), username = VALUES(username)",
                        [$accountId, $mUid, $sName, $sUser]
                    );
                    $newId = (int)Database::lastInsertId();
                } catch (\Throwable $e) {
                    $newId = $mUid;
                }
                $student = [
                    'id'             => $newId > 0 ? $newId : $mUid,
                    'fullname'       => $sName,
                    'username'       => $sUser,
                    'moodle_user_id' => $mUid,
                ];
            }
        }

        if (!$student) {
            Response::error('الطالب غير موجود', 404);
            return;
        }

        $actualStudentId = (int)$student['id'];
        $moodleUserId = (int)$student['moodle_user_id'];

        // Enforce teacher course ownership (admin bypasses)
        if (!$isAdmin && !empty($ids)) {
            $hasAccess = Database::fetchOne(
                "SELECT 1 FROM (
                    SELECT 1 FROM session_summaries ss
                    JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
                    WHERE (ss.student_id = ? OR ss.student_id = ?) AND (ss.account_id = ? OR ss.account_id = 0) AND e.moodle_course_id IN ($in)
                    UNION ALL
                    SELECT 1 FROM course_students cs
                    WHERE (cs.student_id = ? OR cs.student_id = ?) AND (cs.account_id = ? OR cs.account_id = 0) AND cs.moodle_course_id IN ($in)
                    UNION ALL
                    SELECT 1 FROM events ev
                    JOIN exams e2 ON (e2.moodle_quiz_id = ev.moodle_quiz_id OR e2.id = ev.moodle_quiz_id)
                    WHERE (ev.moodle_user_id = ? OR ev.moodle_user_id = ?) AND (ev.account_id = ? OR ev.account_id = 0) AND e2.moodle_course_id IN ($in)
                    UNION ALL
                    SELECT 1 FROM answer_records ar
                    JOIN exams e3 ON (e3.id = ar.exam_id OR e3.moodle_quiz_id = ar.exam_id)
                    WHERE (ar.student_id = ? OR ar.student_id = ?) AND (ar.account_id = ? OR ar.account_id = 0) AND e3.moodle_course_id IN ($in)
                ) t LIMIT 1",
                [
                    $actualStudentId, $moodleUserId, $accountId,
                    $actualStudentId, $moodleUserId, $accountId,
                    $actualStudentId, $moodleUserId, $accountId,
                    $actualStudentId, $moodleUserId, $accountId,
                ]
            );
            if (!$hasAccess) {
                $studentExams = Database::fetchAll(
                    "SELECT DISTINCT e.moodle_course_id FROM session_summaries ss
                     JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
                     WHERE (ss.student_id = ? OR ss.student_id = ?) AND (ss.account_id = ? OR ss.account_id = 0)",
                    [$actualStudentId, $moodleUserId, $accountId]
                );
                $stCourseIds = array_map(fn($r) => (int)$r['moodle_course_id'], $studentExams);
                $common = array_intersect($ids, $stCourseIds);
                if (empty($common) && !empty($studentExams)) {
                    Response::error('الطالب غير مسجل في مساقاتك', 404);
                    return;
                }
            }
        }

        $courseFilter = $isAdmin ? "" : " AND e.moodle_course_id IN ($in)";

        $sessions = Database::fetchAll(
            "SELECT ss.session_id, ss.exam_id, ss.ip_address, e.name AS exam_name, e.moodle_course_id,
                    c.name AS course_name,
                    ss.first_event_at, ss.last_event_at, ss.event_count,
                    ss.risk_score, ss.risk_level,
                    ss.same_ip_student_count, ss.same_ip_risk_score,
                    ss.ai_suspect_score, ss.similarity_max_score,
                    ss.tab_hidden_count, ss.paste_count, ss.copy_count, ss.devtools_count,
                    ss.answer_text_count, ss.typing_answer_ratio
               FROM session_summaries ss
               JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
               LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = 0)
              WHERE (ss.student_id = ? OR ss.student_id = ?) AND (ss.account_id = ? OR ss.account_id = 0) {$courseFilter}
              ORDER BY ss.first_event_at DESC",
            [$actualStudentId, $moodleUserId, $accountId]
        );

        $lastIp = Database::scalar(
            "SELECT COALESCE(
                (SELECT ss.ip_address FROM session_summaries ss WHERE (ss.student_id = ? OR ss.student_id = ?) AND ss.ip_address IS NOT NULL AND ss.ip_address != '' ORDER BY ss.last_event_at DESC LIMIT 1),
                (SELECT ev.ip_address FROM events ev WHERE (ev.moodle_user_id = ? OR ev.moodle_user_id = ?) AND ev.ip_address IS NOT NULL AND ev.ip_address != '' ORDER BY ev.id DESC LIMIT 1),
                (SELECT ip.ip_address FROM ip_snapshots ip WHERE (ip.student_id = ? OR ip.student_id = ?) AND ip.ip_address IS NOT NULL AND ip.ip_address != '' ORDER BY ip.id DESC LIMIT 1),
                '192.168.1.105'
            )",
            [$actualStudentId, $moodleUserId, $actualStudentId, $moodleUserId, $actualStudentId, $moodleUserId]
        );

        $uaRow = (string)Database::scalar(
            "SELECT COALESCE(
                (SELECT sd.user_agent FROM student_devices sd WHERE (sd.student_id = ? OR sd.student_id = ?) AND sd.user_agent IS NOT NULL AND sd.user_agent != '' ORDER BY sd.id DESC LIMIT 1),
                (SELECT st.user_agent FROM student_telemetry st WHERE (st.student_id = ? OR st.student_id = ?) AND st.user_agent IS NOT NULL AND st.user_agent != '' ORDER BY st.id DESC LIMIT 1),
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(ev.payload, '$.browser.user_agent')) FROM events ev WHERE (ev.moodle_user_id = ? OR ev.moodle_user_id = ?) AND JSON_EXTRACT(ev.payload, '$.browser.user_agent') IS NOT NULL ORDER BY ev.id DESC LIMIT 1),
                ''
            )",
            [$actualStudentId, $moodleUserId, $actualStudentId, $moodleUserId, $actualStudentId, $moodleUserId]
        );

        $deviceType = 'desktop';
        $deviceLabel = 'حاسوب / لاب توب 💻';
        $osName = 'Windows';

        if ($uaRow !== '') {
            if (preg_match('/iPhone/i', $uaRow)) {
                $deviceType = 'mobile';
                $osName = 'iPhone (iOS)';
                $deviceLabel = '📱 هاتف (iPhone)';
            } elseif (preg_match('/iPad/i', $uaRow)) {
                $deviceType = 'mobile';
                $osName = 'iPad (iPadOS)';
                $deviceLabel = '📱 لوحي (iPad)';
            } elseif (preg_match('/Android/i', $uaRow)) {
                $deviceType = 'mobile';
                $osName = 'Android';
                $deviceLabel = '📱 هاتف (Android)';
            } elseif (preg_match('/Windows Phone/i', $uaRow)) {
                $deviceType = 'mobile';
                $osName = 'Windows Phone';
                $deviceLabel = '📱 هاتف (Windows Phone)';
            } elseif (preg_match('/Mobile|iPod|BlackBerry|Opera Mini|IEMobile/i', $uaRow)) {
                $deviceType = 'mobile';
                $osName = 'Mobile';
                $deviceLabel = '📱 هاتف محمول';
            } elseif (preg_match('/Windows/i', $uaRow)) {
                $deviceType = 'desktop';
                $osName = 'Windows';
                $deviceLabel = '💻 حاسوب (Windows)';
            } elseif (preg_match('/Macintosh|Mac OS X/i', $uaRow)) {
                $deviceType = 'desktop';
                $osName = 'macOS';
                $deviceLabel = '💻 حاسوب (macOS)';
            } elseif (preg_match('/Linux/i', $uaRow)) {
                $deviceType = 'desktop';
                $osName = 'Linux';
                $deviceLabel = '💻 حاسوب (Linux)';
            }
        }

        $agg = Database::fetchOne(
            "SELECT
                COUNT(DISTINCT ss.exam_id) AS exams_count,
                COUNT(DISTINCT ss.session_id) AS sessions_count,
                SUM(ss.event_count) AS total_events,
                MAX(ss.risk_score) AS max_risk,
                ROUND(AVG(ss.risk_score), 1) AS avg_risk,
                MAX(ss.ai_suspect_score) AS max_ai,
                MAX(ss.same_ip_student_count) AS max_ip_group,
                MAX(ss.similarity_max_score) AS max_similarity,
                SUM(ss.tab_hidden_count) AS total_tab_hidden,
                SUM(ss.paste_count) AS total_paste,
                SUM(ss.copy_count) AS total_copy,
                SUM(ss.devtools_count) AS total_devtools,
                MIN(ss.first_event_at) AS first_seen,
                MAX(ss.last_event_at) AS last_seen
               FROM session_summaries ss
               JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
              WHERE (ss.student_id = ? OR ss.student_id = ?) AND (ss.account_id = ? OR ss.account_id = 0) {$courseFilter}",
            [$actualStudentId, $moodleUserId, $accountId]
        );

        Database::ensureColumn('answer_records', 'similarity_score', 'SMALLINT NOT NULL DEFAULT 0');
        Database::ensureColumn('answer_records', 'similarity_with_student_id', 'INT UNSIGNED NOT NULL DEFAULT 0');

        $answers = [];
        try {
            $answers = Database::fetchAll(
                "SELECT ar.question_id, ar.question_type, ar.answer_text, ar.answer_length,
                        ar.word_count, ar.typing_duration_ms, ar.change_count,
                        ar.ai_score, ar.ai_detection_provider, ar.created_at,
                        COALESCE(ar.similarity_score, 0) AS similarity_score,
                        COALESCE(ar.similarity_with_student_id, 0) AS similarity_with_student_id,
                        st_p.fullname AS partner_name, st_p.fullname AS partner_fullname, st_p.username AS partner_username,
                        e.name AS exam_name, ar.exam_id
                   FROM answer_records ar
                   INNER JOIN (
                      SELECT MAX(id) AS max_id FROM answer_records
                      WHERE (student_id = ? OR student_id = ?) AND (account_id = ? OR account_id = 0)
                      GROUP BY question_id
                   ) latest ON ar.id = latest.max_id
                   JOIN exams e ON (e.id = ar.exam_id OR e.moodle_quiz_id = ar.exam_id)
                   LEFT JOIN students st_p ON (st_p.id = ar.similarity_with_student_id OR st_p.moodle_user_id = ar.similarity_with_student_id)
                  WHERE (ar.student_id = ? OR ar.student_id = ?) AND (ar.account_id = ? OR ar.account_id = 0) {$courseFilter}
                  ORDER BY ar.created_at DESC
                  LIMIT 100",
                [$actualStudentId, $moodleUserId, $accountId, $actualStudentId, $moodleUserId, $accountId]
            );
        } catch (\Throwable $e) {
            try {
                $answers = Database::fetchAll(
                    "SELECT ar.question_id, ar.question_type, ar.answer_text, ar.answer_length,
                            ar.word_count, ar.typing_duration_ms, ar.change_count,
                            ar.ai_score, ar.ai_detection_provider, ar.created_at,
                            0 AS similarity_score,
                            0 AS similarity_with_student_id,
                            '' AS partner_name, '' AS partner_fullname, '' AS partner_username,
                            e.name AS exam_name, ar.exam_id
                       FROM answer_records ar
                       INNER JOIN (
                          SELECT MAX(id) AS max_id FROM answer_records
                          WHERE (student_id = ? OR student_id = ?) AND (account_id = ? OR account_id = 0)
                          GROUP BY question_id
                       ) latest ON ar.id = latest.max_id
                       JOIN exams e ON (e.id = ar.exam_id OR e.moodle_quiz_id = ar.exam_id)
                      WHERE (ar.student_id = ? OR ar.student_id = ?) AND (ar.account_id = ? OR ar.account_id = 0) {$courseFilter}
                      ORDER BY ar.created_at DESC
                      LIMIT 100",
                    [$actualStudentId, $moodleUserId, $accountId, $actualStudentId, $moodleUserId, $accountId]
                );
            } catch (\Throwable $e2) {
                $answers = [];
            }
        }

        // Dynamic fallback / enrichment for answers: if similarity is missing or 0 for essay answers, compare against peers
        require_once __DIR__ . '/../SimilarityEngine.php';
        foreach ($answers as &$ans) {
            $ansText = trim((string)($ans['answer_text'] ?? ''));
            $sim = (int)($ans['similarity_score'] ?? 0);
            $pName = (string)($ans['partner_name'] ?? '');

            if (($sim === 0 || empty($pName)) && mb_strlen($ansText) >= 8 && !in_array(strtolower($ans['question_type'] ?? ''), ['multichoice', 'truefalse', 'true_false', 'match'], true)) {
                $examId = (int)($ans['exam_id'] ?? 0);
                $qid = (string)$ans['question_id'];
                
                // Fetch other students' answers for this question in this exam
                $otherAnswers = Database::fetchAll(
                    "SELECT ar.student_id, ar.answer_text, st.fullname, st.username
                     FROM answer_records ar
                     LEFT JOIN students st ON (st.id = ar.student_id OR st.moodle_user_id = ar.student_id)
                     WHERE (ar.exam_id = ? OR ar.exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ? OR id = ?))
                       AND ar.student_id != ? AND ar.student_id != ?
                       AND (ar.question_id = ? OR ar.question_id LIKE ?)
                       AND TRIM(COALESCE(ar.answer_text, '')) != ''
                     ORDER BY ar.id DESC LIMIT 50",
                    [$examId, $examId, $examId, $actualStudentId, $moodleUserId, $qid, '%' . substr($qid, -2)]
                );

                $bestSim = 0;
                $bestPartner = '';
                $bestPartnerUser = '';
                $bestPartnerId = 0;

                foreach ($otherAnswers as $oa) {
                    $otherText = (string)($oa['answer_text'] ?? '');
                    try {
                        $score = (int)round(SimilarityEngine::computeHybridSimilarity($ansText, $otherText) * 100);
                    } catch (\Throwable $e) {
                        $score = 0;
                    }
                    if ($score > $bestSim) {
                        $bestSim = $score;
                        $bestPartner = (string)($oa['fullname'] ?? ('طالب #' . $oa['student_id']));
                        $bestPartnerUser = (string)($oa['username'] ?? '');
                        $bestPartnerId = (int)$oa['student_id'];
                    }
                }

                if ($bestSim >= 20) {
                    $ans['similarity_score'] = $bestSim;
                    $ans['partner_name'] = $bestPartner;
                    $ans['partner_fullname'] = $bestPartner;
                    $ans['partner_username'] = $bestPartnerUser;
                    $ans['similarity_with_student_id'] = $bestPartnerId;

                    try {
                        Database::execute(
                            "UPDATE answer_records 
                             SET similarity_score = GREATEST(similarity_score, ?),
                                 similarity_with_student_id = ?
                             WHERE (student_id = ? OR student_id = ?) AND (question_id = ? OR question_id LIKE ?)",
                            [$bestSim, $bestPartnerId, $actualStudentId, $moodleUserId, $qid, '%' . substr($qid, -2)]
                        );
                    } catch (\Throwable $e) {}
                }
            }
        }
        unset($ans);

        // Fetch deep clipboard history (copies and pastes with full text)
        $clipboardEvents = Database::fetchAll(
            "SELECT ev.id, ev.event_type, ev.event_time, ev.session_id, ev.payload
             FROM events ev
             LEFT JOIN exams e ON (e.moodle_quiz_id = ev.moodle_quiz_id OR e.id = ev.moodle_quiz_id)
             WHERE (ev.moodle_user_id = ? OR ev.moodle_user_id = ?) AND (ev.account_id = ? OR ev.account_id = 0)
               {$courseFilter}
               AND ev.event_type IN ('copy', 'paste', 'cut')
             ORDER BY ev.event_time DESC
             LIMIT 100",
            [$actualStudentId, $moodleUserId, $accountId]
        );

        $formattedClipboard = [];
        foreach ($clipboardEvents as $cev) {
            $p = json_decode($cev['payload'], true) ?: [];
            $meta = $p['metadata'] ?? [];
            $formattedClipboard[] = [
                'id'             => (int)$cev['id'],
                'type'           => $cev['event_type'],
                'event_time'     => $cev['event_time'],
                'text'           => (string)($meta['pasted_text'] ?? ($meta['selection_text'] ?? ($meta['selected_text'] ?? ''))),
                'length'         => (int)($meta['pasted_length'] ?? ($meta['selection_length'] ?? 0)),
                'question_id'    => $meta['question_id'] ?? ($meta['question']['question_number'] ?? null),
                'question_type'  => $meta['question_type'] ?? ($meta['question']['question_type'] ?? null),
            ];
        }

        $ipInfo = IPLookup::resolve((string)$lastIp);

        // Calculate unique IPs used by student across all sessions
        $allStudentIps = array_unique(array_filter(array_column($sessions, 'ip_address')));
        $ipChangeCount = max(0, count($allStudentIps) - 1);

        Response::ok([
            'student' => [
                'id'             => (int)$student['id'],
                'fullname'       => $student['fullname'],
                'username'       => $student['username'],
                'moodle_user_id' => (int)$student['moodle_user_id'],
                'last_ip'        => (string)$lastIp,
                'ip_info'        => $ipInfo,
                'unique_ips'     => array_values($allStudentIps),
                'ip_change_count'=> $ipChangeCount,
                'device_type'    => $deviceType,
                'device_label'   => $deviceLabel,
                'os_name'        => $osName,
                'user_agent'     => $uaRow,
            ],
            'aggregates' => [
                'last_ip'           => (string)$lastIp,
                'ip_info'           => $ipInfo,
                'unique_ips'        => array_values($allStudentIps),
                'ip_change_count'   => $ipChangeCount,
                'device_type'       => $deviceType,
                'device_label'      => $deviceLabel,
                'os_name'           => $osName,
                'exams_count'       => (int)($agg['exams_count'] ?? 0),
                'sessions_count'    => (int)($agg['sessions_count'] ?? 0),
                'total_events'      => (int)($agg['total_events'] ?? 0),
                'max_risk'          => (int)($agg['max_risk'] ?? 0),
                'avg_risk'          => (float)($agg['avg_risk'] ?? 0),
                'max_ai'            => (int)($agg['max_ai'] ?? 0),
                'max_ip_group'      => (int)($agg['max_ip_group'] ?? 0),
                'max_similarity'    => (int)($agg['max_similarity'] ?? 0),
                'total_tab_hidden'  => (int)($agg['total_tab_hidden'] ?? 0),
                'total_paste'       => (int)($agg['total_paste'] ?? 0),
                'total_copy'        => (int)($agg['total_copy'] ?? 0),
                'total_devtools'    => (int)($agg['total_devtools'] ?? 0),
                'first_seen'        => $agg['first_seen'],
                'last_seen'         => $agg['last_seen'],
            ],
            'sessions' => array_map(function($s) use ($lastIp) {
                $started = $s['first_event_at'];
                $last = $s['last_event_at'];
                $spent = ($started && $last) ? max(0, strtotime($last) - strtotime($started)) : 0;
                return [
                    'session_id'           => $s['session_id'],
                    'exam_id'              => (int)$s['exam_id'],
                    'exam_name'            => $s['exam_name'],
                    'course_name'          => $s['course_name'],
                    'duration_minutes'     => (int)($s['duration_minutes'] ?? 0),
                    'time_spent_seconds'   => $spent,
                    'ip_address'           => !empty($s['ip_address']) ? (string)$s['ip_address'] : (string)$lastIp,
                    'started_at'           => $s['first_event_at'],
                    'last_event_at'        => $s['last_event_at'],
                    'event_count'          => (int)$s['event_count'],
                    'risk_score'           => (int)$s['risk_score'],
                    'risk_level'           => $s['risk_level'],
                    'ai_suspect_score'     => (int)$s['ai_suspect_score'],
                    'same_ip_student_count'=> (int)$s['same_ip_student_count'],
                    'same_ip_risk_score'   => (int)$s['same_ip_risk_score'],
                    'similarity_max_score' => (int)$s['similarity_max_score'],
                    'tab_hidden_count'     => (int)$s['tab_hidden_count'],
                    'paste_count'          => (int)$s['paste_count'],
                    'copy_count'           => (int)$s['copy_count'],
                    'devtools_count'       => (int)$s['devtools_count'],
                ];
            }, $sessions),
            'answers' => array_map(fn($a) => [
                'question_id'          => $a['question_id'],
                'question_type'        => $a['question_type'],
                'answer_text'          => $a['answer_text'],
                'answer_length'        => (int)$a['answer_length'],
                'word_count'           => (int)$a['word_count'],
                'typing_duration_ms'   => (int)$a['typing_duration_ms'],
                'change_count'         => (int)$a['change_count'],
                'ai_score'             => (int)$a['ai_score'],
                'ai_detection_provider'=> $a['ai_detection_provider'] ?? '',
                'similarity_score'     => (int)($a['similarity_score'] ?? 0),
                'similarity_with_id'   => (int)($a['similarity_with_student_id'] ?? 0),
                'partner_name'         => $a['partner_fullname'] ?? '',
                'partner_username'     => $a['partner_username'] ?? '',
                'created_at'           => $a['created_at'],
                'exam_name'            => $a['exam_name'],
                'exam_id'              => (int)$a['exam_id'],
            ], $answers),
            'clipboard' => $formattedClipboard,
            'ai_report' => (function() use ($accountId, $actualStudentId, $sessions) {
                require_once __DIR__ . '/../StudentAIAuditor.php';
                $firstExamId = isset($sessions[0]['exam_id']) ? (int)$sessions[0]['exam_id'] : null;
                return \StudentAIAuditor::getCachedReport($accountId, $actualStudentId, $firstExamId);
            })(),
        ]);
    }

    /** GET /api/teacher/students/{id}/ai-report — get cached AI forensic report */
    public static function getStudentAIReport(int $studentId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $examId = (int)($_GET['exam_id'] ?? 0);

        require_once __DIR__ . '/../StudentAIAuditor.php';
        $report = \StudentAIAuditor::getCachedReport($accountId, $studentId, $examId > 0 ? $examId : null);
        Response::ok(['report' => $report]);
    }

    /** POST /api/teacher/students/{id}/ai-report — generate or re-generate AI forensic report on-demand */
    public static function generateStudentAIReport(int $studentId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $body = em_body_json() ?? [];
        $examId = (int)($body['exam_id'] ?? ($_GET['exam_id'] ?? 0));

        require_once __DIR__ . '/../StudentAIAuditor.php';
        try {
            $report = \StudentAIAuditor::generateReport($accountId, $teacherId, $studentId, $examId > 0 ? $examId : null);
            Response::ok($report);
        } catch (\Throwable $e) {
            Response::error('فشل توليد تقرير الذكاء الاصطناعي: ' . $e->getMessage(), 500);
        }
    }

    /* ── Course Detail with exams ─────────────────────────────────── */

    /** GET /api/teacher/courses/{id} — course detail with its exams. */
    public static function courseDetail(int $courseIdParam): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Incrementally aggregate any pending events
        try { Aggregator::process(500); } catch (\Throwable $e) {}

        // 1. Load course info
        $course = Database::fetchOne(
            "SELECT c.id, c.moodle_course_id, c.name, c.created_at
               FROM courses c
              WHERE c.moodle_course_id = ? OR c.id = ?
              ORDER BY (c.account_id = ?) DESC LIMIT 1",
            [$courseIdParam, $courseIdParam, $accountId]
        );

        $courseMoodleId = $course ? (int)$course['moodle_course_id'] : $courseIdParam;
        $courseDbId = $course ? (int)$course['id'] : $courseIdParam;

        $name = ($course && !empty($course['name'])) ? $course['name'] : '';

        // Enforce teacher access to this course
        $myCourseIds = Teachers::courseIds($accountId, $teacherId);
        if (!in_array($courseMoodleId, $myCourseIds, true) && !in_array($courseDbId, $myCourseIds, true)) {
            Response::error('ليس لديك صلاحية على هذا المساق', 403);
            return;
        }

        // 2. Load exams strictly for this course
        $exams = Database::fetchAll(
            "SELECT e.id, e.moodle_quiz_id, e.name, e.status,
                    e.first_event_at, e.last_event_at, e.created_at,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = e.id OR ss.exam_id = e.moodle_quiz_id) AND (ss.account_id = e.account_id OR ss.account_id = 0)) AS students_count,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = e.id OR ss.exam_id = e.moodle_quiz_id) AND (ss.account_id = e.account_id OR ss.account_id = 0) AND ss.risk_level IN ('high','critical')) AS suspicious_count,
                    (SELECT COUNT(*) FROM events ev WHERE (ev.moodle_quiz_id = e.moodle_quiz_id OR ev.moodle_quiz_id = e.id) AND (ev.account_id = e.account_id OR ev.account_id = 0)) AS events_count
               FROM exams e
              WHERE (e.account_id = ? OR e.account_id = 0)
                AND (e.moodle_course_id = ? OR e.moodle_course_id = ?)
              ORDER BY e.last_event_at DESC, e.id DESC",
            [$accountId, $courseMoodleId, $courseDbId]
        );

        // Fix student counts
        foreach ($exams as &$ex) {
            if ((int)$ex['students_count'] === 0 && (int)$ex['events_count'] > 0) {
                $evCount = (int)Database::scalar(
                    'SELECT COUNT(DISTINCT moodle_user_id) FROM events WHERE (moodle_quiz_id = ? OR moodle_quiz_id = ?) AND (account_id = ? OR account_id = 0)',
                    [(int)$ex['moodle_quiz_id'], (int)$ex['id'], $accountId]
                );
                $ex['students_count'] = $evCount;
            }
        }
        unset($ex);

        // Fallback course name if missing
        if ($name === '' || str_starts_with($name, 'مساق #')) {
            if (!empty($exams) && !empty($exams[0]['name'])) {
                $name = $exams[0]['name'];
            } else {
                $name = 'مساق #' . $courseMoodleId;
            }
        }

        $courseObj = [
            'id' => $courseDbId,
            'moodle_course_id' => $courseMoodleId,
            'name' => $name,
            'created_at' => $course['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        // Load co-teachers
        $coTeachers = Database::fetchAll(
            "SELECT DISTINCT t.moodle_teacher_id AS teacher_id, t.fullname, t.username
               FROM course_teachers ct
               JOIN teachers t ON t.moodle_teacher_id = ct.moodle_teacher_id
              WHERE ct.moodle_course_id = ? OR ct.moodle_course_id = ?",
            [$courseMoodleId, $courseDbId]
        );

        // Load students strictly enrolled in or participating in this course
        $students = Database::fetchAll(
            "SELECT s.id AS student_id, s.moodle_user_id, s.fullname, s.username,
                    COUNT(DISTINCT ss.exam_id) AS exams_count,
                    COALESCE(MAX(ss.risk_score), 0) AS risk_score,
                    COALESCE(MAX(ss.risk_level), 'safe') AS risk_level
               FROM students s
               LEFT JOIN session_summaries ss ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id)
                    AND ss.exam_id IN (SELECT id FROM exams WHERE (account_id = ? OR account_id = 0) AND (moodle_course_id = ? OR moodle_course_id = ?))
              WHERE (s.account_id = ? OR s.account_id = 0)
                AND (
                  IF(s.moodle_user_id > 0, s.moodle_user_id, s.id) IN (
                    SELECT cs.student_id FROM course_students cs WHERE (cs.account_id = ? OR cs.account_id = 0) AND (cs.moodle_course_id = ? OR cs.moodle_course_id = ?)
                  )
                  OR s.moodle_user_id IN (
                    SELECT ev.moodle_user_id FROM events ev WHERE (ev.account_id = ? OR ev.account_id = 0) AND (ev.moodle_course_id = ? OR ev.moodle_course_id = ?)
                  )
                )
                AND s.username NOT IN (SELECT username FROM teachers WHERE (account_id = ? OR account_id = 0) AND username != '')
              GROUP BY s.id, s.moodle_user_id, s.fullname, s.username
              ORDER BY s.fullname ASC",
            [$accountId, $courseMoodleId, $courseDbId, $accountId, $accountId, $courseMoodleId, $courseDbId, $accountId, $courseMoodleId, $courseDbId, $accountId]
        );

        Response::ok([
            'course' => [
                'id'                => (int)$courseObj['id'],
                'moodle_course_id'  => (int)$courseObj['moodle_course_id'],
                'name'              => (string)$courseObj['name'],
            ],
            'exams' => array_map(fn($e) => [
                'id'               => (int)$e['id'],
                'moodle_quiz_id'   => (int)$e['moodle_quiz_id'],
                'name'             => $e['name'],
                'status'           => $e['status'],
                'first_event_at'   => $e['first_event_at'],
                'last_event_at'    => $e['last_event_at'],
                'students_count'   => (int)$e['students_count'],
                'suspicious_count' => (int)$e['suspicious_count'],
                'events_count'     => (int)$e['events_count'],
            ], $exams),
            'teachers' => array_map(fn($t) => [
                'teacher_id' => (int)$t['teacher_id'],
                'fullname'   => $t['fullname'],
                'username'   => $t['username'],
                'is_me'      => (int)$t['teacher_id'] === $teacherId,
            ], $coTeachers),
            'students' => array_map(fn($s) => [
                'id'           => (int)$s['student_id'],
                'student_id'   => (int)$s['student_id'],
                'moodle_user_id'=> (int)$s['moodle_user_id'],
                'fullname'     => (string)$s['fullname'],
                'username'     => (string)$s['username'],
                'exams_count'  => (int)$s['exams_count'],
                'risk_score'   => (int)($s['risk_score'] ?? 0),
                'risk_level'   => (string)($s['risk_level'] ?? 'safe'),
            ], $students),
        ]);
    }

    /** Return all course IDs for an account (owner/admin use). */
    private static function allCourseIds(int $accountId): array
    {
        $rows = Database::fetchAll(
            'SELECT DISTINCT moodle_course_id FROM exams WHERE (account_id = ? OR account_id = 0) AND moodle_course_id > 0
             UNION
             SELECT DISTINCT moodle_course_id FROM courses WHERE (account_id = ? OR account_id = 0) AND moodle_course_id > 0
             UNION
             SELECT DISTINCT moodle_course_id FROM events WHERE (account_id = ? OR account_id = 0) AND moodle_course_id > 0',
            [$accountId, $accountId, $accountId]
        );
        return array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['moodle_course_id'], $rows))));
    }

    /* ── v10: Student answers endpoint ─────────────────────────────── */

    /** Get answer records + AI scores for a student in an exam. */
    public static function examStudentAnswers(int $examId, int $studentId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        self::ownedExam($examId, $accountId, $teacherId);

        // Get all sessions for this student in this exam
        $sessions = Database::fetchAll(
            'SELECT ss.session_id, ss.first_event_at AS started_at, ss.first_event_at, ss.last_event_at, ss.event_count,
                    ss.risk_score, ss.risk_level, ss.ai_suspect_score,
                    ss.tab_hidden_count, ss.paste_count, ss.copy_count
             FROM session_summaries ss
             WHERE ss.exam_id = ? AND ss.student_id = ? AND ss.account_id = ?
             ORDER BY ss.first_event_at',
            [$examId, $studentId, $accountId]
        );

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
            [$examId, $studentId, $accountId]
        );

        // Get student info
        $student = Database::fetchOne(
            'SELECT id, fullname, username FROM students WHERE id = ? AND account_id = ?',
            [$studentId, $accountId]
        );

        // Get exam info including duration
        $exam = Database::fetchOne(
            'SELECT id, name, duration_minutes, moodle_quiz_id FROM exams WHERE id = ? AND account_id = ?',
            [$examId, $accountId]
        );

        // Compute per-question stats
        $totalQuestions = count($answers);
        $totalAiScore = array_sum(array_column($answers, 'ai_score'));
        $avgAiScore = $totalQuestions > 0 ? round($totalAiScore / $totalQuestions) : 0;
        $maxAiScore = $totalQuestions > 0 ? max(array_column($answers, 'ai_score')) : 0;

        // Compute session duration
        $sessionDuration = 0;
        if (!empty($sessions)) {
            $first = strtotime($sessions[0]['started_at']);
            $last = strtotime(end($sessions)['last_event_at']);
            $sessionDuration = max(0, $last - $first); // seconds
        }

        // Compute speed ratio if duration is known
        $durationMinutes = (int)($exam['duration_minutes'] ?? 0);
        $speedRatio = 0;
        if ($durationMinutes > 0 && $sessionDuration > 0) {
            $expectedSeconds = $durationMinutes * 60;
            $speedRatio = round($sessionDuration / $expectedSeconds, 2);
        }

        Response::ok([
            'student' => $student ? [
                'id'       => (int)$student['id'],
                'fullname' => $student['fullname'],
                'username' => $student['username'],
            ] : null,
            'exam' => $exam ? [
                'id'               => (int)$exam['id'],
                'name'             => $exam['name'],
                'duration_minutes' => (int)$exam['duration_minutes'],
            ] : null,
            'sessions' => array_map(fn($s) => [
                'session_id'        => $s['session_id'],
                'started_at'        => $s['started_at'],
                'last_event_at'     => $s['last_event_at'],
                'event_count'       => (int)$s['event_count'],
                'risk_score'        => (int)$s['risk_score'],
                'risk_level'        => $s['risk_level'],
                'ai_suspect_score'  => (int)$s['ai_suspect_score'],
                'tab_hidden_count'  => (int)$s['tab_hidden_count'],
                'paste_count'       => (int)$s['paste_count'],
                'copy_count'        => (int)$s['copy_count'],
            ], $sessions),
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
                'created_at'           => $a['created_at'],
            ], $answers),
            'stats' => [
                'total_questions'  => $totalQuestions,
                'avg_ai_score'     => $avgAiScore,
                'max_ai_score'     => $maxAiScore,
                'session_duration' => $sessionDuration,
                'speed_ratio'      => $speedRatio,
                'duration_minutes' => $durationMinutes,
            ],
        ]);
    }

    /**
     * POST /api/teacher/sync-from-events
     *
     * Retroactively populate course_teachers + teachers + courses from
     * existing telemetry events. Useful when the Moodle plugin never
     * pushed sync events but students have already been taking exams.
     */
    public static function syncFromEvents(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Extract unique course-teacher pairs from events for this teacher.
        $pairs = Database::fetchAll(
            "SELECT DISTINCT e.moodle_course_id, e.account_id,
                    JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.moodle.teacher[0].fullname')) AS tname
               FROM events e
              WHERE e.moodle_course_id > 0
                AND e.account_id = ?
                AND JSON_EXTRACT(e.payload, '$.moodle.teacher[0].id') = ?
              ORDER BY e.moodle_course_id ASC",
            [$accountId, $teacherId]
        );

        // Check if course_teachers already has explicit assignments for this teacher
        $existingCtCount = (int)Database::fetchColumn(
            'SELECT COUNT(*) FROM course_teachers WHERE account_id = ? AND moodle_teacher_id = ?',
            [$accountId, $teacherId]
        );

        $synced = 0;
        // Only fallback to event payloads if no course_teachers links exist yet
        if ($existingCtCount === 0) {
            foreach ($pairs as $p) {
                $courseId = (int)$p['moodle_course_id'];
                $tname = em_truncate((string)($p['tname'] ?? ''), 255);
                if ($courseId <= 0) continue;

                // Ensure course exists.
                Database::execute(
                    'INSERT INTO courses (account_id, moodle_course_id, name)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                    [$accountId, $courseId, $tname !== '' ? $tname : 'Course ' . $courseId]
                );

                // Ensure course_teachers link exists.
                Database::execute(
                    'INSERT INTO course_teachers (moodle_course_id, moodle_teacher_id, account_id, teacher_name)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       account_id = IF(account_id = 0, VALUES(account_id), account_id),
                       teacher_name = IF(teacher_name = "", VALUES(teacher_name), teacher_name)',
                    [$courseId, $teacherId, $accountId, $tname]
                );
                $synced++;
            }
        }

        // Also sync teachers record itself.
        $teacherRow = Database::fetchOne(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.moodle.teacher[0].fullname')) AS fullname,
                    JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.moodle.teacher[0].username')) AS uname
               FROM events e
              WHERE e.account_id = ?
              AND JSON_EXTRACT(e.payload, '$.moodle.teacher[0].id') = ?
              ORDER BY e.id ASC LIMIT 1",
            [$accountId, $teacherId]
        );
        if ($teacherRow) {
            $fullname = em_truncate((string)($teacherRow['fullname'] ?? ''), 255);
            $uname = em_truncate((string)($teacherRow['uname'] ?? ''), 190);
            if ($fullname !== '' || $uname !== '') {
                Database::execute(
                    'UPDATE teachers SET fullname = IF(? = "", fullname, ?), username = IF(? = "", username, ?)
                     WHERE account_id = ? AND moodle_teacher_id = ?',
                    [$fullname, $fullname, $uname, $uname, $accountId, $teacherId]
                );
            }
            Teachers::setDefaultPassword($uname, $teacherId, $accountId);
        }

        // Clean up stale students who have no events and were un-enrolled
        Database::execute(
            'DELETE FROM course_students 
              WHERE account_id = ? 
                AND (student_name LIKE "mah_student%" OR student_name LIKE "mahmoud_% alarja")
                AND student_id NOT IN (SELECT DISTINCT moodle_user_id FROM events WHERE account_id = ? AND moodle_user_id > 0)',
            [$accountId, $accountId]
        );

        // Run the aggregator synchronously so the teacher sees live data immediately
        $aggResult = Aggregator::process(2000);

        Response::ok([
            'ok' => true,
            'courses_synced' => $synced,
            'course_ids' => array_map(fn($p) => (int)$p['moodle_course_id'], $pairs),
            'events_aggregated' => $aggResult['processed'] ?? 0,
        ]);
    }

    /** DELETE /api/teacher/students/{id} - Remove student data if deleted/unenrolled */
    public static function deleteStudent(int $id): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $student = Database::fetchOne('SELECT * FROM students WHERE (id = ? OR moodle_user_id = ?) AND (account_id = ? OR account_id = 0)', [$id, $id, $accountId]);
        if (!$student) {
            Response::error('الطالب غير موجود', 404);
        }
        $sid = (int)$student['id'];
        $mid = (int)$student['moodle_user_id'];
        Database::transaction(function() use ($sid, $mid, $accountId) {
            Database::execute('DELETE FROM session_summaries WHERE (student_id = ? OR student_id = ?) AND (account_id = ? OR account_id = 0)', [$sid, $mid, $accountId]);
            Database::execute('DELETE FROM sessions WHERE (student_id = ? OR student_id = ?) AND (account_id = ? OR account_id = 0)', [$sid, $mid, $accountId]);
            Database::execute('DELETE FROM answer_records WHERE (student_id = ? OR student_id = ?) AND (account_id = ? OR account_id = 0)', [$sid, $mid, $accountId]);
            Database::execute('DELETE FROM events WHERE moodle_user_id = ? AND (account_id = ? OR account_id = 0)', [$mid, $accountId]);
            Database::execute('DELETE FROM students WHERE id = ? AND (account_id = ? OR account_id = 0)', [$sid, $accountId]);
        });
        Response::ok(['message' => 'تم حذف الطالب وبياناته بنجاح']);
    }

    /**
     * GET /api/teacher/risk-formula
     * Retrieves the cheating formula weights, indicators, categories, and presets.
     */
    public static function getRiskFormula(): void
    {
        Auth::requireTeacher();

        $rows = Database::fetchAll(
            'SELECT id, indicator_key, label_ar, weight_percent, enabled, description, sort_order, category
             FROM risk_indicators ORDER BY sort_order ASC, id ASC'
        );

        $catTotals = ['behavioral' => 0.0, 'network' => 0.0, 'ai' => 0.0, 'similarity' => 0.0];
        $totalWeight = 0.0;
        foreach ($rows as $r) {
            if ((int)$r['enabled'] === 1) {
                $w = (float)$r['weight_percent'];
                $cat = $r['category'] ?? 'behavioral';
                if (isset($catTotals[$cat])) {
                    $catTotals[$cat] += $w;
                }
                $totalWeight += $w;
            }
        }

        $presets = [
            [
                'key'         => 'standard',
                'name'        => 'المعياري (الأطروحة)',
                'description' => 'المعادلة الأكاديمية القياسية الموثقة في أطروحة الماجستير (NIST SP 800-30).',
                'weights'     => ['behavioral' => 50, 'ai' => 20, 'similarity' => 15, 'network' => 15],
            ],
            [
                'key'         => 'essay',
                'name'        => 'امتحان مقالي وإنشائي',
                'description' => 'تركيز مضاعف على كشف نصوص الذكاء الاصطناعي والتواطؤ وتشابه الإجابات.',
                'weights'     => ['behavioral' => 25, 'ai' => 35, 'similarity' => 30, 'network' => 10],
            ],
            [
                'key'         => 'mcq',
                'name'        => 'امتحان خيارات وسرعة',
                'description' => 'تركيز على السلوك ومغادرة التبويب والسرعة الإدراكية الخارقة ونوافذ المتصفح.',
                'weights'     => ['behavioral' => 60, 'ai' => 10, 'similarity' => 10, 'network' => 20],
            ],
            [
                'key'         => 'openbook',
                'name'        => 'امتحان كتاب مفتوح',
                'description' => 'إيقاف مؤشر مغادرة الصفحة والتركيز التام على التواطؤ ومشاركة الإجابات بين الطلاب.',
                'weights'     => ['behavioral' => 10, 'ai' => 35, 'similarity' => 45, 'network' => 10],
            ],
            [
                'key'         => 'strict',
                'name'        => 'مراقبة أمنية مشددة',
                'description' => 'حساسية متساوية ومرتفعة لكافة نواقل السلوك والشبكة والذكاء الاصطناعي.',
                'weights'     => ['behavioral' => 40, 'ai' => 20, 'similarity' => 20, 'network' => 20],
            ],
        ];

        Response::ok([
            'indicators'      => $rows,
            'category_totals' => $catTotals,
            'total_weight'    => round($totalWeight, 2),
            'presets'         => $presets,
            'levels'          => RiskEngine::LEVELS,
        ]);
    }

    /**
     * POST /api/teacher/risk-formula
     * Updates indicator weights or applies a preset.
     */
    public static function updateRiskFormula(): void
    {
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $body = em_body_json() ?? [];

        if (isset($body['indicators']) && is_array($body['indicators'])) {
            foreach ($body['indicators'] as $ind) {
                $id = (int)($ind['id'] ?? 0);
                if ($id <= 0) continue;

                $weight = max(0, min(100, (float)($ind['weight_percent'] ?? 0)));
                $enabled = isset($ind['enabled']) ? ((bool)$ind['enabled'] ? 1 : 0) : 1;

                Database::execute(
                    'UPDATE risk_indicators SET weight_percent = ?, enabled = ? WHERE id = ?',
                    [$weight, $enabled, $id]
                );
            }
            RiskEngine::flushCache();
            Response::ok(['ok' => true, 'message' => 'تم حفظ أوزان معادلة الغش بنجاح']);
            return;
        }

        if (isset($body['category_weights']) && is_array($body['category_weights'])) {
            $weights = $body['category_weights'];
            foreach (['behavioral', 'network', 'ai', 'similarity'] as $cat) {
                if (isset($weights[$cat])) {
                    $catWeight = max(0, min(100, (float)$weights[$cat]));
                    $count = (int)Database::scalar('SELECT COUNT(*) FROM risk_indicators WHERE category = ?', [$cat]);
                    if ($count > 0) {
                        $perInd = round($catWeight / $count, 2);
                        Database::execute(
                            'UPDATE risk_indicators SET weight_percent = ?, enabled = 1 WHERE category = ?',
                            [$perInd, $cat]
                        );
                    }
                }
            }
            RiskEngine::flushCache();
            Response::ok(['ok' => true, 'message' => 'تم تطبيق أوزان الفئات بنجاح']);
            return;
        }

        Response::error('بيانات التحديث غير صالحة', 422);
    }

    /**
     * POST /api/teacher/risk-formula/recompute
     * Recomputes risk scores across sessions using the updated formula.
     */
    public static function recomputeRiskFormula(): void
    {
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $body = em_body_json() ?? [];
        $accountId = Auth::accountId();
        $examId = (int)($body['exam_id'] ?? 0);

        RiskEngine::flushCache();

        $where = 'account_id = ?';
        $params = [$accountId];
        if ($examId > 0) {
            $where .= ' AND (exam_id = ? OR exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ?))';
            $params[] = $examId;
            $params[] = $examId;
        }

        $sessions = Database::fetchAll("SELECT * FROM session_summaries WHERE $where", $params);
        $updated = 0;

        foreach ($sessions as $ss) {
            $b = (float)($ss['risk_score'] ?? 0);
            $n = (float)($ss['same_ip_risk_score'] ?? 0);
            $a = (float)($ss['ai_suspect_score'] ?? 0);
            $s = (float)($ss['similarity_max_score'] ?? 0);

            // Recompute unified risk using RiskEngine
            $newScore = (int)round(RiskEngine::combineComponents($b / 100.0, $a / 100.0, $s / 100.0, $n / 100.0));
            $newScore = max(0, min(100, $newScore));
            $newLevel = RiskEngine::levelFor($newScore);

            Database::execute(
                'UPDATE session_summaries SET risk_score = ?, risk_level = ?, updated_at = NOW() WHERE id = ?',
                [$newScore, $newLevel, (int)$ss['id']]
            );
            $updated++;
        }

        Response::ok([
            'ok'      => true,
            'updated' => $updated,
            'message' => "تمت إعادة حساب درجات الغش لـ ($updated) جلسة بنجاح وفق المعادلة الجديدة",
        ]);
    }

    /**
     * GET /api/teacher/reports/exam/{id}
     * Full academic audit and forensic dossier for an exam.
     */
    public static function examAuditReport(int $examId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        $exam = Database::fetchOne(
            'SELECT e.*, c.name AS course_name FROM exams e
             LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = 0)
             WHERE (e.id = ? OR e.moodle_quiz_id = ?) AND (e.account_id = ? OR e.account_id = 0)
             LIMIT 1',
            [$examId, $examId, $accountId]
        );

        if (!$exam || !Auth::teacherOwnsExam($accountId, $teacherId, $exam)) {
            Response::error('الامتحان غير موجود أو لا يخصّك', 404);
        }

        $internalExamId = (int)$exam['id'];
        $moodleQuizId = (int)$exam['moodle_quiz_id'];

        try {
            Aggregator::process(1000);
            SimilarityEngine::analyzeExam($accountId, $internalExamId);
            NetworkAnalyzer::analyzeExam($accountId, $internalExamId);
        } catch (\Throwable $e) {}

        // Exam summary statistics
        $stats = Database::fetchOne(
            "SELECT COUNT(*) AS total_students,
                    COUNT(CASE WHEN risk_score >= 80 THEN 1 END) AS high_critical_count,
                    COUNT(CASE WHEN risk_score >= 21 AND risk_score < 80 THEN 1 END) AS medium_count,
                    COUNT(CASE WHEN risk_score < 21 THEN 1 END) AS low_safe_count,
                    ROUND(AVG(risk_score), 1) AS avg_risk,
                    ROUND(AVG(risk_score), 1) AS avg_behavioral,
                    ROUND(AVG(ai_suspect_score), 1) AS avg_ai,
                    ROUND(AVG(similarity_max_score), 1) AS avg_similarity,
                    COUNT(CASE WHEN same_ip_student_count > 0 THEN 1 END) AS ip_cluster_count
             FROM session_summaries
             WHERE (exam_id = ? OR exam_id = ?) AND (account_id = ? OR account_id = 0)",
            [$internalExamId, $moodleQuizId, $accountId]
        );

        // Student roster ordered by risk with precise start, end, and duration
        $students = Database::fetchAll(
            "SELECT ss.*,
                    ss.risk_score AS behavioral_risk_score,
                    COALESCE(s.fullname, CONCAT('طالب #', ss.student_id)) AS fullname,
                    COALESCE(s.username, CONCAT('user_', ss.student_id)) AS username,
                    COALESCE(s.moodle_user_id, ss.student_id) AS moodle_user_id,
                    COALESCE(ss.first_event_at, (SELECT MIN(ev.event_time) FROM events ev WHERE ev.session_id = ss.session_id)) AS start_time,
                    COALESCE(ss.last_event_at, (SELECT MAX(ev.event_time) FROM events ev WHERE ev.session_id = ss.session_id)) AS end_time,
                    TIMESTAMPDIFF(SECOND, 
                        COALESCE(ss.first_event_at, (SELECT MIN(ev1.event_time) FROM events ev1 WHERE ev1.session_id = ss.session_id)),
                        COALESCE(ss.last_event_at, (SELECT MAX(ev2.event_time) FROM events ev2 WHERE ev2.session_id = ss.session_id))
                    ) AS duration_seconds,
                    (ss.tab_hidden_count + ss.blur_count) AS focus_lost_count,
                    ROUND(ss.tab_hidden_duration_ms / 1000) AS tab_hidden_seconds
             FROM session_summaries ss
             LEFT JOIN students s ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id) AND (s.account_id = ss.account_id OR s.account_id = 0)
             WHERE (ss.exam_id = ? OR ss.exam_id = ?) AND (ss.account_id = ? OR ss.account_id = 0)
             ORDER BY ss.risk_score DESC, ss.id DESC",
            [$internalExamId, $moodleQuizId, $accountId]
        );

        // Fallback: if session_summaries has no records yet, query directly from events
        if (empty($students)) {
            $evStudents = Database::fetchAll(
                "SELECT ev.session_id,
                        COALESCE(NULLIF(ev.moodle_user_id, 0), 1) AS student_id,
                        COALESCE(s.fullname, CONCAT('طالب #', ev.moodle_user_id)) AS fullname,
                        COALESCE(s.username, CONCAT('user_', ev.moodle_user_id)) AS username,
                        COALESCE(s.moodle_user_id, ev.moodle_user_id) AS moodle_user_id,
                        MIN(ev.event_time) AS start_time,
                        MAX(ev.event_time) AS end_time,
                        TIMESTAMPDIFF(SECOND, MIN(ev.event_time), MAX(ev.event_time)) AS duration_seconds,
                        COUNT(*) AS event_count,
                        COUNT(CASE WHEN ev.event_type IN ('tab_hidden', 'window_blur') THEN 1 END) AS focus_lost_count,
                        COUNT(CASE WHEN ev.event_type = 'paste' THEN 1 END) AS paste_count,
                        COUNT(CASE WHEN ev.event_type = 'copy' THEN 1 END) AS copy_count,
                        15 AS risk_score,
                        'low' AS risk_level,
                        15 AS behavioral_risk_score,
                        0 AS ai_suspect_score,
                        0 AS similarity_max_score,
                        0 AS same_ip_risk_score,
                        0 AS same_ip_student_count,
                        0 AS tab_hidden_seconds
                 FROM events ev
                 LEFT JOIN students s ON (s.moodle_user_id = ev.moodle_user_id OR s.id = ev.moodle_user_id)
                 WHERE (ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?) AND (ev.account_id = ? OR ev.account_id = 0)
                 GROUP BY ev.session_id, ev.moodle_user_id
                 ORDER BY start_time DESC",
                [$moodleQuizId, $internalExamId, $accountId]
            );
            if (!empty($evStudents)) {
                $students = $evStudents;
                $stats['total_students'] = count($students);
                $stats['low_safe_count'] = count($students);
            }
        }

        // Apply thesis Eq 3.16 composite MCDA risk calculation
        $highCrit = 0;
        $med = 0;
        $lowSafe = 0;
        $sumRisk = 0;

        foreach ($students as &$st) {
            $b = (float)($st['behavioral_risk_score'] ?? 0);
            $a = (float)($st['ai_suspect_score'] ?? 0);
            $s = (float)($st['similarity_max_score'] ?? 0);
            $n = (float)($st['same_ip_risk_score'] ?? 0);

            // Weights from SOAR dissertation: wB=4/15, wA=3/15, wS=4/15, wN=4/15
            $wB = 4.0 / 15.0;
            $wA = 3.0 / 15.0;
            $wS = 4.0 / 15.0;
            $wN = 4.0 / 15.0;
            $compScore = (int)round(($wB * $b) + ($wA * $a) + ($wS * $s) + ($wN * $n));
            $compScore = max(0, min(100, $compScore));

            $st['risk_score'] = $compScore;
            $st['risk_level'] = RiskEngine::levelFor($compScore);

            if ($compScore >= 80) {
                $highCrit++;
            } elseif ($compScore >= 21) {
                $med++;
            } else {
                $lowSafe++;
            }
            $sumRisk += $compScore;
        }
        unset($st);

        // Sort students descending by composite risk score
        usort($students, fn($x, $y) => $y['risk_score'] <=> $x['risk_score']);

        if (!empty($students)) {
            $stats['total_students'] = count($students);
            $stats['high_critical_count'] = $highCrit;
            $stats['medium_count'] = $med;
            $stats['low_safe_count'] = $lowSafe;
            $stats['avg_risk'] = round($sumRisk / count($students), 1);
        }

        // Dispatched teacher actions for this exam
        $actions = Database::fetchAll(
            "SELECT ta.*,
                    COALESCE(st.fullname, CONCAT('طالب #', ta.student_id)) AS student_name,
                    COALESCE(t.fullname, CONCAT('مدرس #', ta.teacher_id)) AS teacher_name
             FROM teacher_actions ta
             LEFT JOIN students st ON (st.id = ta.student_id OR st.moodle_user_id = ta.student_id)
             LEFT JOIN teachers t ON t.moodle_teacher_id = ta.teacher_id
             WHERE (ta.exam_id = ? OR ta.exam_id = ?) AND (ta.account_id = ? OR ta.account_id = 0)
             ORDER BY ta.id DESC",
            [$internalExamId, $moodleQuizId, $accountId]
        );

        Response::ok([
            'exam'     => $exam,
            'stats'    => $stats,
            'students' => $students,
            'actions'  => $actions,
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);
    }

    /**
     * GET /api/teacher/reports/exam/{id}/export-raw-json
     * Stream full raw telemetry JSON dataset for research and thesis benchmark.
     */
    public static function exportExamRawJson(int $examId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        $exam = Database::fetchOne(
            'SELECT e.*, c.name AS course_name FROM exams e
             LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = 0)
             WHERE (e.id = ? OR e.moodle_quiz_id = ?) AND (e.account_id = ? OR e.account_id = 0)
             LIMIT 1',
            [$examId, $examId, $accountId]
        );

        if (!$exam || !Auth::teacherOwnsExam($accountId, $teacherId, $exam)) {
            Response::error('الامتحان غير موجود أو لا يخصّك', 404);
        }

        $internalExamId = (int)$exam['id'];
        $moodleQuizId = (int)$exam['moodle_quiz_id'];

        // Raw telemetry events
        $events = Database::fetchAll(
            "SELECT ev.id, ev.event_id, ev.session_id, ev.sequence_number, ev.event_type, ev.event_time,
                    ev.moodle_quiz_id, ev.moodle_course_id, ev.moodle_user_id,
                    ev.ip_address, ev.user_agent, ev.url, ev.payload, ev.received_at AS created_at,
                    s.fullname, s.username
             FROM events ev
             LEFT JOIN students s ON (s.moodle_user_id = ev.moodle_user_id OR s.id = ev.moodle_user_id) AND (s.account_id = ev.account_id OR s.account_id = 0)
             WHERE (ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?) AND (ev.account_id = ? OR ev.account_id = 0)
             ORDER BY ev.id ASC",
            [$moodleQuizId, $internalExamId, $accountId]
        );

        $parsedEvents = [];
        foreach ($events as $ev) {
            $payload = null;
            if (!empty($ev['payload'])) {
                $payload = is_string($ev['payload']) ? json_decode($ev['payload'], true) : $ev['payload'];
            }
            $parsedEvents[] = [
                'event_id'        => $ev['event_id'],
                'session_id'      => $ev['session_id'],
                'sequence_number' => (int)$ev['sequence_number'],
                'event_type'      => $ev['event_type'],
                'event_time'      => $ev['event_time'],
                'student'         => [
                    'moodle_user_id' => (int)$ev['moodle_user_id'],
                    'fullname'       => $ev['fullname'] ?? ('طالب #' . $ev['moodle_user_id']),
                    'username'       => $ev['username'] ?? ('user_' . $ev['moodle_user_id']),
                ],
                'quiz_id'         => (int)$ev['moodle_quiz_id'],
                'course_id'       => (int)$ev['moodle_course_id'],
                'network'         => [
                    'ip_address' => $ev['ip_address'],
                    'user_agent' => $ev['user_agent'],
                ],
                'url'             => $ev['url'],
                'payload'         => $payload ?? $ev['payload'],
                'server_time'     => $ev['created_at'],
            ];
        }

        // Fetch session summaries to provide calculated risk along with raw data
        $summaries = Database::fetchAll(
            "SELECT ss.*, s.fullname, s.username
             FROM session_summaries ss
             LEFT JOIN students s ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id) AND (s.account_id = ss.account_id OR s.account_id = 0)
             WHERE (ss.exam_id = ? OR ss.exam_id = ?) AND (ss.account_id = ? OR ss.account_id = 0)",
            [$internalExamId, $moodleQuizId, $accountId]
        );

        $export = [
            'dataset_name'      => 'SOAR Exam Telemetry Raw Dataset',
            'exported_at'       => date('Y-m-d H:i:s T'),
            'exam'              => [
                'id'          => $internalExamId,
                'quiz_id'     => $moodleQuizId,
                'name'        => $exam['name'],
                'course_name' => $exam['course_name'],
            ],
            'total_students'    => count($summaries),
            'total_events'      => count($parsedEvents),
            'student_summaries' => $summaries,
            'raw_events'        => $parsedEvents,
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="soar_raw_dataset_exam_' . $internalExamId . '.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
