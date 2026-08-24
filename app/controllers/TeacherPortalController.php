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
        if ($ids === []) {
            Response::ok(self::emptySummary());
            return;
        }
        $in = self::safeInts($ids);

        $row = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM exams e WHERE e.account_id = ? AND e.moodle_course_id IN ($in)) AS exams_count,
                (SELECT COUNT(*) FROM exams e WHERE e.account_id = ? AND e.moodle_course_id IN ($in) AND e.status = 'active') AS active_exams,
                (SELECT COUNT(*) FROM courses c WHERE c.account_id = ? AND c.moodle_course_id IN ($in)) AS courses_count,
                (SELECT COUNT(DISTINCT s.id)
                   FROM students s
                  WHERE s.account_id = ?
                    AND (
                      s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id IN ($in))
                      OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id IN ($in))
                    )
                    AND s.username NOT IN (SELECT username FROM teachers WHERE account_id = ? AND username != '')
                ) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)
                   FROM session_summaries ss
                   JOIN exams e3 ON e3.id = ss.exam_id 
                  WHERE e3.account_id = ? AND e3.moodle_course_id IN ($in)) AS sessions_count,
                (SELECT COUNT(DISTINCT ss.student_id)
                   FROM session_summaries ss
                   JOIN exams e4 ON e4.id = ss.exam_id 
                  WHERE e4.account_id = ? AND e4.moodle_course_id IN ($in)
                    AND ss.risk_level IN ('high','critical')) AS suspicious_count",
            [$accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId, $accountId]
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

    /** The teacher's courses, each with co-teachers and per-course counts. */
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
                    (SELECT COUNT(*) FROM exams e WHERE e.account_id = c.account_id AND e.moodle_course_id = c.moodle_course_id) AS exams_count,
                    (SELECT COUNT(DISTINCT s.id)
                       FROM students s
                      WHERE s.account_id = c.account_id
                        AND (
                          s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = c.account_id AND cs.moodle_course_id = c.moodle_course_id)
                          OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = c.account_id AND cs.moodle_course_id = c.moodle_course_id)
                        )
                        AND s.username NOT IN (SELECT username FROM teachers WHERE account_id = c.account_id AND username != '')
                    ) AS students_count
               FROM courses c
              WHERE c.account_id = ? AND c.moodle_course_id IN ($in)
              ORDER BY c.name",
            [$accountId]
        );

        $coTeachers = Database::fetchAll(
            "SELECT DISTINCT ct.moodle_course_id, t.moodle_teacher_id AS teacher_id, t.fullname, t.username
               FROM course_teachers ct
               JOIN teachers t ON t.moodle_teacher_id = ct.moodle_teacher_id AND t.account_id = ct.account_id
              WHERE ct.account_id = ? AND ct.moodle_course_id IN ($in)
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

        $ids = Teachers::courseIds($accountId, $teacherId);
        if ($ids === []) {
            Response::ok([]);
            return;
        }
        $in = self::safeInts($ids);

        $search = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');
        $params = [$accountId];
        $extra = '';
        if ($search !== '') {
            $extra .= ' AND (e.name LIKE ? OR e.moodle_quiz_id LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($status === 'active' || $status === 'ended') {
            $extra .= ' AND e.status = ?';
            $params[] = $status;
        }

        $rows = Database::fetchAll(
            "SELECT e.id, e.moodle_quiz_id, e.moodle_course_id, e.moodle_cmid,
                    e.name, e.moodle_teacher_id, e.teacher_name,
                    e.status, e.first_event_at, e.last_event_at, e.created_at,
                    c.name AS course_name, c.id AS course_id,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS students_count,
                    (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS sessions_count,
                    (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = e.moodle_quiz_id AND ev.account_id = e.account_id) AS events_count,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id AND ss.risk_level IN ('high','critical')) AS suspicious_count
               FROM exams e
               LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND c.account_id = e.account_id
              WHERE e.account_id = ? AND e.moodle_course_id IN ($in)" . $extra . "
              ORDER BY e.last_event_at DESC
              LIMIT 300",
            $params
        );

        // Fallback: if session_summaries is empty but events exist, count from events
        $fallbackNeeded = array_filter($rows, fn($r) => (int)$r['students_count'] === 0 && (int)$r['events_count'] > 0);
        if (!empty($fallbackNeeded)) {
            $quizIds = array_map(fn($r) => (int)$r['moodle_quiz_id'], $fallbackNeeded);
            $placeholders = implode(',', array_fill(0, count($quizIds), '?'));
            $evCounts = Database::fetchAll(
                "SELECT moodle_quiz_id, COUNT(DISTINCT moodle_user_id) AS cnt
                 FROM events WHERE moodle_quiz_id IN ($placeholders) AND account_id = ?
                 GROUP BY moodle_quiz_id",
                array_merge($quizIds, [$accountId])
            );
            $evMap = array_column($evCounts, 'cnt', 'moodle_quiz_id');
            foreach ($rows as &$r) {
                if ((int)$r['students_count'] === 0 && (int)$r['events_count'] > 0) {
                    $qid = (int)$r['moodle_quiz_id'];
                    $r['students_count'] = (int)($evMap[$qid] ?? 0);
                    $r['sessions_count'] = $r['students_count'];
                }
            }
            unset($r);
        }

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

        $exam = self::ownedExam($id, $accountId, $teacherId);
        $quizId = (int)$exam['moodle_quiz_id'];

        $counts = Database::fetchOne(
            'SELECT
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = ? AND ss.account_id = ?) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id = ? AND ss.account_id = ?) AS sessions_count,
                (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = ? AND ev.account_id = ?) AS events_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = ? AND ss.account_id = ? AND ss.risk_level IN ("high","critical")) AS suspicious_count',
            [$id, $accountId, $id, $accountId, $quizId, $accountId, $id, $accountId]
        );
        $studentsCount = (int)$counts['students_count'];
        $sessionsCount = (int)$counts['sessions_count'];
        $eventsCount = (int)$counts['events_count'];

        if ($studentsCount === 0 && $eventsCount > 0) {
            $fallback = Database::fetchOne(
                'SELECT COUNT(DISTINCT ev.moodle_user_id) AS student_count
                 FROM events ev WHERE ev.moodle_quiz_id = ? AND ev.account_id = ?',
                [$quizId, $accountId]
            );
            $studentsCount = (int)($fallback['student_count'] ?? 0);
            $sessionsCount = $studentsCount;
        }

        $riskDist = Database::fetchAll(
            'SELECT risk_level AS level, COUNT(*) AS cnt
             FROM session_summaries WHERE exam_id = ? AND account_id = ?
             GROUP BY risk_level',
            [$id, $accountId]
        );

        $overTime = Database::fetchAll(
            "SELECT DATE_FORMAT(event_time, '%Y-%m-%d %H:00') AS bucket, COUNT(*) AS cnt
             FROM events
             WHERE moodle_quiz_id = ? AND account_id = ?
             GROUP BY bucket ORDER BY bucket ASC",
            [$quizId, $accountId]
        );

        $eventTypes = Database::fetchAll(
            'SELECT event_type AS type, COUNT(*) AS cnt
             FROM events WHERE moodle_quiz_id = ? AND account_id = ?
             GROUP BY event_type ORDER BY cnt DESC',
            [$quizId, $accountId]
        );

        $course = Database::fetchOne(
            'SELECT id, name, moodle_course_id FROM courses WHERE moodle_course_id = ? AND account_id = ?',
            [(int)$exam['moodle_course_id'], $accountId]
        );

        Response::ok([
            'exam' => $exam,
            'course' => $course ? [
                'id' => (int)$course['id'],
                'name' => $course['name'],
                'moodle_course_id' => (int)$course['moodle_course_id'],
            ] : null,
            'counts' => [
                'students' => $studentsCount,
                'sessions' => $sessionsCount,
                'events' => $eventsCount,
                'suspicious' => (int)$counts['suspicious_count'],
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

        self::ownedExam($id, $accountId, $teacherId);

        $students = Analytics::examStudents($id);

        $risk = (string)($_GET['risk'] ?? '');
        $search = trim((string)($_GET['q'] ?? ''));
        $sort = (string)($_GET['sort'] ?? 'risk_desc');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        if ($risk !== '' && $risk !== 'all') {
            $students = array_values(array_filter($students, fn($s) => $s['risk_level'] === $risk));
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

        $ids = Teachers::courseIds($accountId, $teacherId);
        if ($ids === []) {
            Response::ok(self::emptyAnalytics());
            return;
        }
        $in = self::safeInts($ids);

        // Exam IDs owned by this teacher
        $examRows = Database::fetchAll(
            "SELECT e.id, e.moodle_quiz_id, e.name, e.status, c.name AS course_name
               FROM exams e
               LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND c.account_id = e.account_id
              WHERE e.account_id = ? AND e.moodle_course_id IN ($in)",
            [$accountId]
        );
        $examIds = array_map(fn($r) => (int)$r['id'], $examRows);
        if (empty($examIds)) {
            Response::ok(self::emptyAnalytics());
            return;
        }
        $ein = self::safeInts($examIds);

        // Totals
        $totals = Database::fetchOne(
            "SELECT
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id IN ($ein) AND ss.account_id = ?) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id IN ($ein) AND ss.account_id = ?) AS sessions_count,
                (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id IN (
                    SELECT e2.moodle_quiz_id FROM exams e2 WHERE e2.id IN ($ein) AND e2.account_id = ?
                ) AND ev.account_id = ?) AS events_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                   WHERE ss.exam_id IN ($ein) AND ss.account_id = ? AND ss.risk_level IN ('high','critical')) AS suspicious_count,
                (SELECT COUNT(*) FROM exams e3 WHERE e3.id IN ($ein) AND e3.status = 'active') AS active_exams",
            [$accountId, $accountId, $accountId, $accountId, $accountId]
        );

        // Risk distribution
        $riskDist = Database::fetchAll(
            "SELECT risk_level AS level, COUNT(*) AS cnt
               FROM session_summaries WHERE exam_id IN ($ein) AND account_id = ?
              GROUP BY risk_level",
            [$accountId]
        );

        // Event types breakdown
        $eventTypes = Database::fetchAll(
            "SELECT ev.event_type AS type, COUNT(*) AS cnt
               FROM events ev
              WHERE ev.moodle_quiz_id IN (
                SELECT e2.moodle_quiz_id FROM exams e2 WHERE e2.id IN ($ein) AND e2.account_id = ?
              ) AND ev.account_id = ?
              GROUP BY ev.event_type ORDER BY cnt DESC LIMIT 12",
            [$accountId, $accountId]
        );

        // Events over time (24h buckets, last 30 days)
        $eventsOverTime = Database::fetchAll(
            "SELECT DATE_FORMAT(ev.event_time, '%Y-%m-%d') AS bucket, COUNT(*) AS cnt
               FROM events ev
              WHERE ev.moodle_quiz_id IN (
                SELECT e2.moodle_quiz_id FROM exams e2 WHERE e2.id IN ($ein) AND e2.account_id = ?
              ) AND ev.account_id = ?
                AND ev.event_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY bucket ORDER BY bucket ASC",
            [$accountId, $accountId]
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
              WHERE ss.exam_id IN ($ein) AND ss.account_id = ?",
            [$accountId]
        );

        // Top risky students
        $topRisky = Database::fetchAll(
            "SELECT ss.student_id, ss.risk_score, ss.risk_level,
                    ss.same_ip_student_count, ss.same_ip_risk_score,
                    ss.ai_suspect_score, ss.similarity_max_score,
                    ss.tab_hidden_count, ss.paste_count, ss.copy_count,
                    ss.devtools_count, ss.event_count,
                    s.fullname, s.username,
                    e.name AS exam_name
               FROM session_summaries ss
               JOIN students s ON s.id = ss.student_id
               JOIN exams e ON e.id = ss.exam_id
              WHERE ss.exam_id IN ($ein) AND ss.account_id = ?
              ORDER BY ss.risk_score DESC
              LIMIT 20",
            [$accountId]
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
        $exam = Database::fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
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
        self::ownedExam($id, $accountId, $teacherId);

        $groups = Database::fetchAll(
            'SELECT id, ip_address, student_count, student_ids, risk_level, detected_at
             FROM network_groups
             WHERE account_id = ? AND exam_id = ?
             ORDER BY student_count DESC, risk_level DESC',
            [$accountId, $id]
        );

        // Enrich with student names
        $allGroups = [];
        foreach ($groups as $g) {
            $sids = json_decode($g['student_ids'], true) ?: [];
            $names = [];
            if ($sids !== []) {
                $placeholders = implode(',', array_fill(0, count($sids), '?'));
                $students = Database::fetchAll(
                    "SELECT id, fullname, username FROM students WHERE id IN ($placeholders) AND account_id = ?",
                    array_merge($sids, [$accountId])
                );
                foreach ($students as $s) {
                    $names[] = [
                        'id'       => (int)$s['id'],
                        'fullname' => $s['fullname'],
                        'username' => $s['username'],
                    ];
                }
            }
            $allGroups[] = [
                'id'            => (int)$g['id'],
                'ip_address'    => $g['ip_address'],
                'student_count' => (int)$g['student_count'],
                'students'      => $names,
                'risk_level'    => $g['risk_level'],
                'detected_at'   => $g['detected_at'],
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
        self::ownedExam($id, $accountId, $teacherId);

        $minSim = max(0, min(100, (int)($_GET['min_similarity'] ?? 30)));

        $pairs = Database::fetchAll(
            'SELECT sp.student_a_id, sp.student_b_id, sp.similarity_pct,
                    sp.matching_questions, sp.total_questions, sp.detected_at
             FROM similarity_pairs sp
             WHERE sp.account_id = ? AND sp.exam_id = ? AND sp.similarity_pct >= ?
             ORDER BY sp.similarity_pct DESC
             LIMIT 200',
            [$accountId, $id, $minSim]
        );

        // Enrich with student names
        $allIds = [];
        foreach ($pairs as $p) {
            $allIds[] = (int)$p['student_a_id'];
            $allIds[] = (int)$p['student_b_id'];
        }
        $allIds = array_unique($allIds);
        $nameMap = [];
        if ($allIds !== []) {
            $placeholders = implode(',', array_fill(0, count($allIds), '?'));
            $students = Database::fetchAll(
                "SELECT id, fullname, username FROM students WHERE id IN ($placeholders) AND account_id = ?",
                array_merge($allIds, [$accountId])
            );
            foreach ($students as $s) {
                $nameMap[(int)$s['id']] = [
                    'fullname' => $s['fullname'],
                    'username' => $s['username'],
                ];
            }
        }

        $result = [];
        foreach ($pairs as $p) {
            $sa = (int)$p['student_a_id'];
            $sb = (int)$p['student_b_id'];
            $result[] = [
                'student_a' => [
                    'id'       => $sa,
                    'fullname' => $nameMap[$sa]['fullname'] ?? '',
                    'username' => $nameMap[$sa]['username'] ?? '',
                ],
                'student_b' => [
                    'id'       => $sb,
                    'fullname' => $nameMap[$sb]['fullname'] ?? '',
                    'username' => $nameMap[$sb]['username'] ?? '',
                ],
                'similarity_pct'    => (float)$p['similarity_pct'],
                'matching_questions'=> (int)$p['matching_questions'],
                'total_questions'   => (int)$p['total_questions'],
                'detected_at'       => $p['detected_at'],
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

        // Top risky students with full breakdown
        $topRisky = Database::fetchAll(
            'SELECT ss.session_id, ss.risk_score, ss.risk_level,
                    ss.same_ip_student_count, ss.ip_changed_count, ss.same_ip_risk_score,
                    ss.ai_suspect_score, ss.answer_text_count, ss.typing_answer_ratio,
                    ss.similarity_max_score, ss.similarity_match_count,
                    s.fullname, s.username
             FROM session_summaries ss
             JOIN students s ON s.id = ss.student_id
             WHERE ss.exam_id = ? AND ss.account_id = ?
             ORDER BY ss.risk_score DESC
             LIMIT 20',
            [$id, $accountId]
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
        self::ownedExam($id, $accountId, $teacherId);

        $devices = Database::fetchAll(
            "SELECT sd.student_id, sd.ip_address, sd.browser_fp, sd.user_agent,
                    sd.first_seen, sd.last_seen, sd.snapshot_count,
                    s.fullname, s.username
             FROM student_devices sd
             JOIN students s ON s.id = sd.student_id AND s.account_id = sd.account_id
             WHERE sd.account_id = ? AND sd.exam_id = ?
             ORDER BY sd.student_id, sd.first_seen",
            [$accountId, $id]
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
        if ($ids === []) { Response::ok([]); return; }
        $in = self::safeInts($ids);

        $groups = Database::fetchAll(
            "SELECT ng.id, ng.ip_address, ng.student_count, ng.student_ids, ng.risk_level, ng.detected_at,
                    ng.exam_id, e.name AS exam_name
             FROM network_groups ng
             JOIN exams e ON e.id = ng.exam_id AND e.account_id = ng.account_id
             WHERE ng.account_id = ? AND e.moodle_course_id IN ($in)
             ORDER BY ng.student_count DESC, ng.risk_level DESC
             LIMIT 200",
            [$accountId]
        );

        $allGroups = [];
        foreach ($groups as $g) {
            $sids = json_decode($g['student_ids'], true) ?: [];
            $names = [];
            if ($sids !== []) {
                $placeholders = implode(',', array_fill(0, count($sids), '?'));
                $students = Database::fetchAll(
                    "SELECT id, fullname, username FROM students WHERE id IN ($placeholders) AND account_id = ?",
                    array_merge($sids, [$accountId])
                );
                foreach ($students as $s) {
                    $names[] = ['id' => (int)$s['id'], 'fullname' => $s['fullname'], 'username' => $s['username']];
                }
            }
            $allGroups[] = [
                'id' => (int)$g['id'], 'ip_address' => $g['ip_address'],
                'student_count' => (int)$g['student_count'], 'students' => $names,
                'risk_level' => $g['risk_level'], 'detected_at' => $g['detected_at'],
                'exam_id' => (int)$g['exam_id'], 'exam_name' => $g['exam_name'] ?? '',
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
        if ($ids === []) { Response::ok([]); return; }
        $in = self::safeInts($ids);

        $minSim = max(0, min(100, (int)($_GET['min_similarity'] ?? 30)));

        $pairs = Database::fetchAll(
            "SELECT sp.student_a_id, sp.student_b_id, sp.similarity_pct,
                    sp.matching_questions, sp.total_questions, sp.detected_at,
                    sp.exam_id, e.name AS exam_name
             FROM similarity_pairs sp
             JOIN exams e ON e.id = sp.exam_id AND e.account_id = sp.account_id
             WHERE sp.account_id = ? AND e.moodle_course_id IN ($in) AND sp.similarity_pct >= ?
             ORDER BY sp.similarity_pct DESC
             LIMIT 200",
            [$accountId, $minSim]
        );

        $allIds = [];
        foreach ($pairs as $p) {
            $allIds[] = (int)$p['student_a_id'];
            $allIds[] = (int)$p['student_b_id'];
        }
        $allIds = array_unique($allIds);
        $nameMap = [];
        if ($allIds !== []) {
            $placeholders = implode(',', array_fill(0, count($allIds), '?'));
            $students = Database::fetchAll(
                "SELECT id, fullname, username FROM students WHERE id IN ($placeholders) AND account_id = ?",
                array_merge($allIds, [$accountId])
            );
            foreach ($students as $s) {
                $nameMap[(int)$s['id']] = ['fullname' => $s['fullname'], 'username' => $s['username']];
            }
        }

        $result = [];
        foreach ($pairs as $p) {
            $sa = (int)$p['student_a_id'];
            $sb = (int)$p['student_b_id'];
            $result[] = [
                'student_a' => ['id' => $sa, 'fullname' => $nameMap[$sa]['fullname'] ?? '', 'username' => $nameMap[$sa]['username'] ?? ''],
                'student_b' => ['id' => $sb, 'fullname' => $nameMap[$sb]['fullname'] ?? '', 'username' => $nameMap[$sb]['username'] ?? ''],
                'similarity_pct' => (float)$p['similarity_pct'],
                'matching_questions' => (int)$p['matching_questions'],
                'total_questions' => (int)$p['total_questions'],
                'detected_at' => $p['detected_at'],
                'exam_id' => (int)$p['exam_id'], 'exam_name' => $p['exam_name'] ?? '',
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
        if ($ids === []) { Response::ok([]); return; }
        $in = self::safeInts($ids);

        $devices = Database::fetchAll(
            "SELECT sd.student_id, sd.ip_address, sd.browser_fp, sd.user_agent,
                    sd.first_seen, sd.last_seen, sd.snapshot_count, sd.exam_id,
                    s.fullname, s.username, e.name AS exam_name
             FROM student_devices sd
             JOIN students s ON s.id = sd.student_id AND s.account_id = sd.account_id
             JOIN exams e ON e.id = sd.exam_id AND e.account_id = sd.account_id
             WHERE sd.account_id = ? AND e.moodle_course_id IN ($in)
             ORDER BY sd.student_id, sd.first_seen",
            [$accountId]
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
    public static function students(): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        $ids = Teachers::courseIds($accountId, $teacherId);
        if ($ids === []) { Response::ok(['students' => [], 'totals' => self::emptyStudentTotals()]); return; }
        $in = self::safeInts($ids);

        $search = trim((string)($_GET['q'] ?? ''));
        $risk   = (string)($_GET['risk'] ?? '');
        $sort   = (string)($_GET['sort'] ?? 'risk_desc');

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
               LEFT JOIN session_summaries ss ON ss.student_id = s.id AND ss.account_id = s.account_id
                    AND ss.exam_id IN (SELECT id FROM exams WHERE account_id = ? AND moodle_course_id IN ($in))
              WHERE s.account_id = ?
                AND (
                  s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id IN ($in))
                  OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id IN ($in))
                )
                AND s.username NOT IN (SELECT username FROM teachers WHERE account_id = ? AND username != '')
              GROUP BY s.id, s.moodle_user_id, s.fullname, s.username",
            [$accountId, $accountId, $accountId, $accountId, $accountId]
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
               LEFT JOIN session_summaries ss ON ss.student_id = s.id AND ss.account_id = s.account_id
                    AND ss.exam_id IN (SELECT id FROM exams WHERE account_id = ? AND moodle_course_id IN ($in))
              WHERE s.account_id = ?
                AND (
                  s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id IN ($in))
                  OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id IN ($in))
                )
                AND s.username NOT IN (SELECT username FROM teachers WHERE account_id = ? AND username != '')",
            [$accountId, $accountId, $accountId, $accountId, $accountId]
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

        $ids = Teachers::courseIds($accountId, $teacherId);
        if ($ids === []) { Response::error('لا توجد بيانات', 404); return; }
        $in = self::safeInts($ids);

        $student = Database::fetchOne(
            "SELECT s.id, s.fullname, s.username, s.moodle_user_id
               FROM students s
              WHERE (s.id = ? OR s.moodle_user_id = ?) AND s.account_id = ?
              AND (
                EXISTS (
                  SELECT 1 FROM session_summaries ss
                  JOIN exams e ON e.id = ss.exam_id
                  WHERE ss.student_id = s.id AND ss.account_id = s.account_id AND e.moodle_course_id IN ($in)
                )
                OR EXISTS (
                  SELECT 1 FROM course_students cs
                  WHERE (cs.student_id = s.moodle_user_id OR cs.student_id = s.id) AND cs.account_id = s.account_id AND cs.moodle_course_id IN ($in)
                )
                OR EXISTS (
                  SELECT 1 FROM events ev
                  WHERE ev.moodle_user_id = s.moodle_user_id AND ev.account_id = s.account_id AND ev.moodle_course_id IN ($in)
                )
              )",
            [$studentId, $studentId, $accountId]
        );
        if (!$student) { Response::error('الطالب غير موجود أو غير مسجل في مساقاتك', 404); return; }

        $actualStudentId = (int)$student['id'];

        $sessions = Database::fetchAll(
            "SELECT ss.session_id, ss.exam_id, e.name AS exam_name, e.moodle_course_id,
                    c.name AS course_name,
                    ss.first_event_at, ss.last_event_at, ss.event_count,
                    ss.risk_score, ss.risk_level,
                    ss.same_ip_student_count, ss.same_ip_risk_score,
                    ss.ai_suspect_score, ss.similarity_max_score,
                    ss.tab_hidden_count, ss.paste_count, ss.copy_count, ss.devtools_count,
                    ss.answer_text_count, ss.typing_answer_ratio
               FROM session_summaries ss
               JOIN exams e ON e.id = ss.exam_id
               LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND c.account_id = e.account_id
              WHERE ss.student_id = ? AND ss.account_id = ? AND e.moodle_course_id IN ($in)
              ORDER BY ss.first_event_at DESC",
            [$actualStudentId, $accountId]
        );

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
               JOIN exams e ON e.id = ss.exam_id
              WHERE ss.student_id = ? AND ss.account_id = ? AND e.moodle_course_id IN ($in)",
            [$actualStudentId, $accountId]
        );

        $answers = Database::fetchAll(
            "SELECT ar.question_id, ar.question_type, ar.answer_text, ar.answer_length,
                    ar.word_count, ar.typing_duration_ms, ar.change_count,
                    ar.ai_score, ar.ai_detection_provider, ar.created_at,
                    e.name AS exam_name, ar.exam_id
               FROM answer_records ar
               INNER JOIN (
                  SELECT MAX(id) AS max_id FROM answer_records
                  WHERE student_id = ? AND account_id = ?
                  GROUP BY question_id
               ) latest ON ar.id = latest.max_id
               JOIN exams e ON e.id = ar.exam_id AND e.account_id = ar.account_id
              WHERE ar.student_id = ? AND ar.account_id = ? AND e.moodle_course_id IN ($in)
              ORDER BY ar.created_at DESC
              LIMIT 100",
            [$actualStudentId, $accountId, $actualStudentId, $accountId]
        );

        Response::ok([
            'student' => [
                'id'          => (int)$student['id'],
                'fullname'    => $student['fullname'],
                'username'    => $student['username'],
                'moodle_user_id' => (int)$student['moodle_user_id'],
            ],
            'aggregates' => [
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
            'sessions' => array_map(fn($s) => [
                'session_id'           => $s['session_id'],
                'exam_id'              => (int)$s['exam_id'],
                'exam_name'            => $s['exam_name'],
                'course_name'          => $s['course_name'],
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
            ], $sessions),
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
                'created_at'           => $a['created_at'],
                'exam_name'            => $a['exam_name'],
                'exam_id'              => (int)$a['exam_id'],
            ], $answers),
        ]);
    }

    /* ── Course Detail with exams ─────────────────────────────────── */

    /** GET /api/teacher/courses/{id} — course detail with its exams. */
    public static function courseDetail(int $courseMoodleId): void
    {
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        $ids = Teachers::courseIds($accountId, $teacherId);
        if (!in_array($courseMoodleId, $ids)) {
            Response::error('الدورة غير موجودة أو غير مصرح لك بعرضها', 404);
            return;
        }

        $course = Database::fetchOne(
            "SELECT c.id, c.moodle_course_id, c.name, c.created_at
               FROM courses c
              WHERE c.account_id = ? AND c.moodle_course_id = ?",
            [$accountId, $courseMoodleId]
        );
        if (!$course) {
            $course = [
                'id' => $courseMoodleId,
                'moodle_course_id' => $courseMoodleId,
                'name' => 'مساق #' . $courseMoodleId,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        $exams = Database::fetchAll(
            "SELECT e.id, e.moodle_quiz_id, e.name, e.status,
                    e.first_event_at, e.last_event_at, e.created_at,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS students_count,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id AND ss.risk_level IN ('high','critical')) AS suspicious_count,
                    (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = e.moodle_quiz_id AND ev.account_id = e.account_id) AS events_count
               FROM exams e
              WHERE e.account_id = ? AND e.moodle_course_id = ?
              ORDER BY e.last_event_at DESC",
            [$accountId, $courseMoodleId]
        );

        $coTeachers = Database::fetchAll(
            "SELECT t.moodle_teacher_id AS teacher_id, t.fullname, t.username
               FROM course_teachers ct
               JOIN teachers t ON t.moodle_teacher_id = ct.moodle_teacher_id AND t.account_id = ct.account_id
              WHERE ct.account_id = ? AND ct.moodle_course_id = ?",
            [$accountId, $courseMoodleId]
        );

        $students = Database::fetchAll(
            "SELECT s.id AS student_id, s.moodle_user_id, s.fullname, s.username,
                    (SELECT COUNT(DISTINCT ss.exam_id) FROM session_summaries ss JOIN exams e ON e.id = ss.exam_id WHERE ss.student_id = s.id AND e.moodle_course_id = ? AND ss.account_id = s.account_id) AS exams_count,
                    (SELECT MAX(ss.risk_score) FROM session_summaries ss JOIN exams e ON e.id = ss.exam_id WHERE ss.student_id = s.id AND e.moodle_course_id = ? AND ss.account_id = s.account_id) AS risk_score,
                    (SELECT ss.risk_level FROM session_summaries ss JOIN exams e ON e.id = ss.exam_id WHERE ss.student_id = s.id AND e.moodle_course_id = ? AND ss.account_id = s.account_id ORDER BY ss.risk_score DESC LIMIT 1) AS risk_level
               FROM students s
              WHERE s.account_id = ?
                AND (
                  s.moodle_user_id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id = ?)
                  OR s.id IN (SELECT cs.student_id FROM course_students cs WHERE cs.account_id = ? AND cs.moodle_course_id = ?)
                )
                AND s.username NOT IN (SELECT username FROM teachers WHERE account_id = ? AND username != '')",
            [$courseMoodleId, $courseMoodleId, $courseMoodleId, $accountId, $accountId, $courseMoodleId, $accountId, $courseMoodleId, $accountId]
        );

        usort($students, function ($a, $b) {
            $aTook = ((int)$a['exams_count'] > 0) ? 1 : 0;
            $bTook = ((int)$b['exams_count'] > 0) ? 1 : 0;
            if ($aTook !== $bTook) return $bTook <=> $aTook;
            
            $aRisk = (int)($a['risk_score'] ?? 0);
            $bRisk = (int)($b['risk_score'] ?? 0);
            if ($aRisk !== $bRisk) return $bRisk <=> $aRisk;
            
            return mb_strcmp($a['fullname'] ?? '', $b['fullname'] ?? '');
        });

        Response::ok([
            'course' => [
                'id'                => (int)$course['id'],
                'moodle_course_id'  => (int)$course['moodle_course_id'],
                'name'              => $course['name'],
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
            'SELECT DISTINCT moodle_course_id FROM exams WHERE account_id = ? AND moodle_course_id > 0',
            [$accountId]
        );
        return array_map(fn($r) => (int)$r['moodle_course_id'], $rows);
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
            'SELECT ss.session_id, ss.first_event_at, ss.last_event_at, ss.event_count,
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

        // Run the aggregator synchronously so the teacher sees live data immediately
        $aggResult = Aggregator::process(2000);

        Response::ok([
            'ok' => true,
            'courses_synced' => $synced,
            'course_ids' => array_map(fn($p) => (int)$p['moodle_course_id'], $pairs),
            'events_aggregated' => $aggResult['processed'] ?? 0,
        ]);
    }
}
