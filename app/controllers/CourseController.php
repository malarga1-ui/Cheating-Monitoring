<?php
/**
 * Course management + supervisor course-access endpoints (owner only for access mgmt).
 */
final class CourseController
{
    public static function list(): void
    {
        Auth::requireLogin();
        $scopeC = Auth::accountFilterSql('c');
        $scopeE = Auth::accountFilterSql('e');
        $scopeSs = Auth::accountFilterSql('ss');

        $sql = 'SELECT c.id, c.moodle_course_id, c.name, c.created_at,
                       (SELECT COUNT(*) FROM exams e WHERE e.moodle_course_id = c.moodle_course_id AND e.account_id = c.account_id) AS exams_count,
                       (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                         JOIN exams e ON e.id = ss.exam_id AND ss.account_id = e.account_id
                         WHERE e.moodle_course_id = c.moodle_course_id AND ss.account_id = e.account_id) AS students_count
                  FROM courses c';
        $params = [];
        if ($scopeC = Auth::accountFilterSql('c')) {
            $sql .= ' WHERE ' . $scopeC;
        }
        $sql .= ' ORDER BY c.name, c.moodle_course_id';

        $rows = Database::fetchAll($sql, $params);
        Response::ok(array_map(function ($r) {
            $r['id'] = (int)$r['id'];
            $r['moodle_course_id'] = (int)$r['moodle_course_id'];
            $r['exams_count'] = (int)$r['exams_count'];
            $r['students_count'] = (int)$r['students_count'];
            return $r;
        }, $rows));
    }

    public static function updateName(int $id): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();

        $course = Database::fetchOne('SELECT * FROM courses WHERE id = ?', [$id]);
        if (!$course) {
            Response::error('الدورة غير موجودة', 404);
        }
        Auth::requireRowAccess($course);

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            Response::error('الاسم مطلوب', 422);
        }

        Database::execute(
            'UPDATE courses SET name = ? WHERE id = ? AND account_id = ?',
            [mb_substr($name, 0, 255), $id, (int)$course['account_id']]
        );
        Response::ok(['ok' => true]);
    }

    /** Full course page: course info + its exams with per-exam stats + risk distribution. */
    public static function detail(int $id): void
    {
        Auth::requireLogin();

        $course = Database::fetchOne('SELECT * FROM courses WHERE id = ?', [$id]);
        if (!$course) {
            Response::error('الدورة غير موجودة', 404);
        }
        Auth::requireRowAccess($course);

        $courseId = (int)$course['moodle_course_id'];
        $scopeE = Auth::accountFilterSql('e');
        $scopeSs = Auth::accountFilterSql('ss');
        $scopeEv = Auth::accountFilterSql('ev');

        $whereE = $scopeE ? ('WHERE ' . $scopeE . ' AND ') : 'WHERE ';
        $whereSs = $scopeSs ? ('WHERE ' . $scopeSs . ' AND ') : 'WHERE ';
        $whereEv = $scopeEv ? ('WHERE ' . $scopeEv . ' AND ') : 'WHERE ';

        $counts = Database::fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM exams e ' . $whereE . 'e.moodle_course_id = ?) AS exams_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                   JOIN exams e ON e.id = ss.exam_id AND ss.account_id = e.account_id
                  ' . $whereSs . 'e.moodle_course_id = ?) AS students_count,
                (SELECT COUNT(DISTINCT ss.session_id) FROM session_summaries ss
                   JOIN exams e ON e.id = ss.exam_id AND ss.account_id = e.account_id
                  ' . $whereSs . 'e.moodle_course_id = ?) AS sessions_count,
                (SELECT COUNT(*) FROM events ev ' . $whereEv . 'ev.moodle_course_id = ?) AS events_count,
                (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                   JOIN exams e ON e.id = ss.exam_id AND ss.account_id = e.account_id
                  ' . $whereSs . 'e.moodle_course_id = ? AND ss.risk_level IN ("high","critical")) AS suspicious_count',
            [$courseId, $courseId, $courseId, $courseId, $courseId]
        );

        $exams = Database::fetchAll(
            'SELECT e.id, e.moodle_quiz_id, e.name, e.status, e.first_event_at, e.last_event_at,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS students_count,
                    (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS sessions_count,
                    (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = e.moodle_quiz_id AND ev.account_id = e.account_id) AS events_count,
                    (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                       WHERE ss.exam_id = e.id AND ss.account_id = e.account_id AND ss.risk_level IN ("high","critical")) AS suspicious_count,
                    (SELECT COUNT(*) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id AND ss.risk_level = "critical") AS critical_sessions,
                    (SELECT COUNT(*) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id AND ss.risk_level = "high") AS high_sessions
             FROM exams e
             WHERE e.moodle_course_id = ? AND e.account_id = (SELECT account_id FROM courses WHERE id = ?)
             ORDER BY e.last_event_at DESC',
            [$courseId, $course['id']]
        );

        $riskDist = Database::fetchAll(
            'SELECT ss.risk_level AS level, COUNT(*) AS cnt
             FROM session_summaries ss
             JOIN exams e ON e.id = ss.exam_id AND ss.account_id = e.account_id
             WHERE e.moodle_course_id = ? AND e.account_id = (SELECT account_id FROM courses WHERE id = ?)
             GROUP BY ss.risk_level',
            [$courseId, $course['id']]
        );

        Response::ok([
            'course' => [
                'id' => (int)$course['id'],
                'moodle_course_id' => (int)$course['moodle_course_id'],
                'name' => $course['name'],
                'created_at' => $course['created_at'],
            ],
            'counts' => [
                'exams' => (int)$counts['exams_count'],
                'students' => (int)$counts['students_count'],
                'sessions' => (int)$counts['sessions_count'],
                'events' => (int)$counts['events_count'],
                'suspicious' => (int)$counts['suspicious_count'],
            ],
            'risk_distribution' => array_map(fn($r) => [
                'level' => $r['level'],
                'cnt' => (int)$r['cnt'],
            ], $riskDist),
            'exams' => array_map(function ($r) {
                $r['id'] = (int)$r['id'];
                $r['moodle_quiz_id'] = (int)$r['moodle_quiz_id'];
                $r['students_count'] = (int)$r['students_count'];
                $r['sessions_count'] = (int)$r['sessions_count'];
                $r['events_count'] = (int)$r['events_count'];
                $r['suspicious_count'] = (int)$r['suspicious_count'];
                $r['critical_sessions'] = (int)$r['critical_sessions'];
                $r['high_sessions'] = (int)$r['high_sessions'];
                return $r;
            }, $exams),
        ]);
    }

    /** Supervisors with their allowed course ids (for the access manager) — owner only. */
    public static function accessList(): void
    {
        Auth::requireAdmin();

        $rows = Database::fetchAll(
            'SELECT u.id, u.username, u.fullname,
                    (SELECT COUNT(*) FROM course_access ca WHERE ca.user_id = u.id) AS courses_count
             FROM users u
             WHERE u.role = "supervisor"
             ORDER BY u.username'
        );
        Response::ok(array_map(function ($r) {
            $r['id'] = (int)$r['id'];
            $r['courses_count'] = (int)$r['courses_count'];
            return $r;
        }, $rows));
    }

    public static function accessDetail(int $userId): void
    {
        Auth::requireAdmin();

        $user = Database::fetchOne('SELECT id, username, fullname, role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            Response::error('المستخدم غير موجود', 404);
        }

        $granted = Database::fetchAll(
            'SELECT ca.moodle_course_id FROM course_access ca WHERE ca.user_id = ?',
            [$userId]
        );
        $grantedIds = array_map(fn($r) => (int)$r['moodle_course_id'], $granted);

        Response::ok([
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'fullname' => $user['fullname'],
                'role' => $user['role'],
            ],
            'granted_course_ids' => $grantedIds,
        ]);
    }

    /** Replace the full set of granted courses for a supervisor. */
    public static function setAccess(int $userId): void
    {
        Auth::requireAdmin();
        Auth::guardStateChangingRequest();

        $user = Database::fetchOne('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            Response::error('المستخدم غير موجود', 404);
        }
        if ($user['role'] !== 'supervisor') {
            Response::error('الصلاحيات مخصصة لدور المشرف فقط', 422);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $courseIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($body['course_ids'] ?? [])
        ))));

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM course_access WHERE user_id = " . (int)$userId);
            $stmt = $pdo->prepare('INSERT INTO course_access (user_id, moodle_course_id) VALUES (?, ?)');
            foreach ($courseIds as $cid) {
                $stmt->execute([(int)$userId, $cid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Response::error('تعذر حفظ الصلاحيات: ' . $e->getMessage(), 500);
        }

        Response::ok(['granted_course_ids' => $courseIds]);
    }

    /** Students list for a specific course — with per-student risk + counters. */
    public static function students(int $id): void
    {
        Auth::requireLogin();

        $course = Database::fetchOne('SELECT * FROM courses WHERE id = ?', [$id]);
        if (!$course) {
            Response::error('الدورة غير موجودة', 404);
        }
        Auth::requireRowAccess($course);

        $courseId = (int)$course['moodle_course_id'];
        $accountId = (int)$course['account_id'];

        $rows = Database::fetchAll(
            "SELECT s.id, s.moodle_user_id, s.fullname, s.username, s.first_seen_at, s.last_seen_at,
                    COUNT(DISTINCT ss.session_id) AS sessions_count,
                    SUM(ss.event_count) AS events_count,
                    MAX(ss.risk_score) AS max_risk_score,
                    MAX(ss.ai_suspect_score) AS ai_suspect_score,
                    MAX(ss.same_ip_student_count) AS same_ip_student_count,
                    MAX(ss.ip_changed_count) AS ip_changed_count,
                    MAX(ss.similarity_max_score) AS similarity_max_score,
                    SUM(ss.tab_hidden_count) AS tab_hidden_count,
                    SUM(ss.paste_count) AS paste_count,
                    SUM(ss.copy_count) AS copy_count,
                    SUM(ss.devtools_count) AS devtools_count,
                    GROUP_CONCAT(DISTINCT ss.ip_address ORDER BY ss.ip_address SEPARATOR ', ') AS ip_addresses
             FROM students s
             JOIN session_summaries ss ON ss.student_id = s.id AND ss.account_id = s.account_id
             JOIN exams e ON e.id = ss.exam_id
             WHERE e.moodle_course_id = ? AND s.account_id = ?
             GROUP BY s.id
             ORDER BY max_risk_score DESC, s.fullname",
            [$courseId, $accountId]
        );

        Response::ok(array_map(function ($r) use ($accountId) {
            $riskScore = (int)$r['max_risk_score'];
            $level = RiskEngine::levelFor($riskScore);
            return [
                'id'                    => (int)$r['id'],
                'moodle_user_id'        => (int)$r['moodle_user_id'],
                'fullname'              => $r['fullname'],
                'username'              => $r['username'],
                'first_seen_at'         => $r['first_seen_at'],
                'last_seen_at'          => $r['last_seen_at'],
                'sessions_count'        => (int)$r['sessions_count'],
                'events_count'          => (int)($r['events_count'] ?? 0),
                'risk_score'            => $riskScore,
                'risk_level'            => $level,
                'ai_suspect_score'      => (int)$r['ai_suspect_score'],
                'same_ip_student_count' => (int)$r['same_ip_student_count'],
                'ip_changed_count'      => (int)$r['ip_changed_count'],
                'similarity_max_score'  => (int)$r['similarity_max_score'],
                'tab_hidden_count'      => (int)$r['tab_hidden_count'],
                'paste_count'           => (int)$r['paste_count'],
                'copy_count'            => (int)$r['copy_count'],
                'devtools_count'        => (int)$r['devtools_count'],
                'ip_addresses'          => $r['ip_addresses'] ?? '',
            ];
        }, $rows));
    }
}