<?php
/**
 * Exam analytics endpoints (tenant-scoped by account).
 */
final class ExamController
{
    public static function list(): void
    {
        Auth::requireLogin();
        $scopeE = Auth::accountFilterSql('e');
        $search = trim((string)($_GET['q'] ?? ''));
        $status = (string)($_GET['status'] ?? '');

        $where = [];
        $params = [];
        if ($scopeE) {
            $where[] = $scopeE;
        }
        if ($search !== '') {
            $where[] = '(e.name LIKE ? OR e.moodle_quiz_id LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($status === 'active' || $status === 'ended') {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

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
             $whereSql
             ORDER BY e.last_event_at DESC
             LIMIT 300",
            $params
        );

        Response::ok(array_map(function ($r) {
            $r['students_count'] = (int)$r['students_count'];
            $r['sessions_count'] = (int)$r['sessions_count'];
            $r['events_count'] = (int)$r['events_count'];
            $r['suspicious_count'] = (int)$r['suspicious_count'];
            return $r;
        }, $rows));
    }

    public static function detail(int $id): void
    {
        Auth::requireLogin();

        $exam = Database::fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        if (!$exam) {
            Response::error('الامتحان غير موجود', 404);
        }
        Auth::requireRowAccess($exam);

        $quizId = (int)$exam['moodle_quiz_id'];
        $accountId = (int)$exam['account_id'];

        $counts = Database::fetchOne(
            'SELECT
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = ? AND ss.account_id = ?) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id = ? AND ss.account_id = ?) AS sessions_count,
                (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = ? AND ev.account_id = ?) AS events_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = ? AND ss.account_id = ? AND ss.risk_level IN ("high","critical")) AS suspicious_count',
            [$id, $accountId, $id, $accountId, $quizId, $accountId, $id, $accountId]
        );

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
                'students' => (int)$counts['students_count'],
                'sessions' => (int)$counts['sessions_count'],
                'events' => (int)$counts['events_count'],
                'suspicious' => (int)$counts['suspicious_count'],
            ],
            'risk_distribution' => $riskDist,
            'events_over_time' => array_map(fn($r) => [
                'time' => $r['bucket'],
                'events' => (int)$r['cnt'],
            ], $overTime),
            'event_types' => array_map(fn($r) => [
                'type' => $r['type'],
                'count' => (int)$r['cnt'],
            ], $eventTypes),
        ]);
    }

    /** Rename an exam or toggle its status (owner, or admin in this account). */
    public static function update(int $id): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();

        $exam = Database::fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        if (!$exam) {
            Response::error('الامتحان غير موجود', 404);
        }
        Auth::requireRowAccess($exam);

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = isset($body['name']) ? trim((string)$body['name']) : null;
        $status = isset($body['status']) ? (string)$body['status'] : null;

        if ($name !== null) {
            if ($name === '') {
                Response::error('الاسم مطلوب', 422);
            }
            Database::execute('UPDATE exams SET name = ? WHERE id = ? AND account_id = ?', [mb_substr($name, 0, 255), $id, (int)$exam['account_id']]);
        }
        if ($status !== null) {
            if (!in_array($status, ['active', 'ended'], true)) {
                Response::error('حالة غير صالحة', 422);
            }
            Database::execute('UPDATE exams SET status = ? WHERE id = ? AND account_id = ?', [$status, $id, (int)$exam['account_id']]);
        }

        Response::ok(['ok' => true]);
    }

    public static function students(int $id): void
    {
        Auth::requireLogin();

        $exam = Database::fetchOne('SELECT id, moodle_course_id, account_id FROM exams WHERE id = ?', [$id]);
        if (!$exam) {
            Response::error('الامتحان غير موجود', 404);
        }
        Auth::requireRowAccess($exam);

        $students = Analytics::examStudents($id, (int)$exam['account_id']);

        // In-memory filter / sort (fine for up to a few thousand students).
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
}