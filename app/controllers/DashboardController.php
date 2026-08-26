<?php
/**
 * Dashboard analytics endpoints (tenant-scoped by account).
 */
final class DashboardController
{
    public static function summary(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        $isOwner = Auth::isOwner();

        $data = Cache::remember("dashboard_summary_{$accountId}_" . ($isOwner ? '1' : '0'), function () use ($accountId, $isOwner) {
            $scopeEv = Auth::accountFilterSql('ev');
            $scopeE  = Auth::accountFilterSql('e');
            $scopeSs = Auth::accountFilterSql('ss');
            $scopeSt = Auth::accountFilterSql('s');
            $scopeC  = Auth::accountFilterSql('c');

            $eventsWhere = $scopeEv ? (' WHERE ' . $scopeEv) : '';

            $totalEvents = (int)Database::scalar('SELECT COUNT(*) FROM events ev' . $eventsWhere);
            $totalSessions = (int)Database::scalar('SELECT COUNT(DISTINCT session_id) FROM events ev' . $eventsWhere);
            $totalStudents = (int)Database::scalar('SELECT COUNT(*) FROM students s' . ($scopeSt ? ' WHERE ' . $scopeSt : ''));
            if ($totalStudents === 0) {
                $totalStudents = (int)Database::scalar('SELECT COUNT(DISTINCT moodle_user_id) FROM events ev' . $eventsWhere . ($eventsWhere ? ' AND moodle_user_id > 0' : ' WHERE moodle_user_id > 0'));
            }
            $coursesTotal = (int)Database::scalar('SELECT COUNT(*) FROM courses c' . ($scopeC ? ' WHERE ' . $scopeC : ''));
            if ($coursesTotal === 0) {
                $coursesTotal = (int)Database::scalar('SELECT COUNT(DISTINCT moodle_course_id) FROM exams e' . ($scopeE ? ' WHERE ' . $scopeE . ' AND moodle_course_id > 0' : ' WHERE moodle_course_id > 0'));
            }
            $examsTotal = (int)Database::scalar('SELECT COUNT(*) FROM exams e' . ($scopeE ? ' WHERE ' . $scopeE : ''));
            $examsActive = (int)Database::scalar(
                "SELECT COUNT(*) FROM exams e" . ($scopeE ? ' WHERE ' . $scopeE . ' AND ' : ' WHERE ') . "e.status = 'active'"
            );
            $suspiciousSessions = (int)Database::scalar(
                "SELECT COUNT(*) FROM session_summaries ss" . ($scopeSs ? ' WHERE ' . $scopeSs . ' AND ' : ' WHERE ') . "ss.risk_level IN ('high','critical')"
            );
            $suspiciousStudents = (int)Database::scalar(
                "SELECT COUNT(DISTINCT student_id) FROM session_summaries ss" . ($scopeSs ? ' WHERE ' . $scopeSs . ' AND ' : ' WHERE ') . "ss.risk_level IN ('high','critical')"
            );
            $eventsLastHour = (int)Database::scalar(
                'SELECT COUNT(*) FROM events ev WHERE ev.event_time >= UTC_TIMESTAMP() - INTERVAL 1 HOUR' . ($scopeEv ? ' AND ' . $scopeEv : '')
            );

            return [
                'events' => $totalEvents,
                'sessions' => $totalSessions,
                'students' => $totalStudents,
                'courses' => $coursesTotal,
                'exams' => $examsTotal,
                'active_exams' => $examsActive,
                'suspicious_sessions' => $suspiciousSessions,
                'suspicious_students' => $suspiciousStudents,
                'events_last_hour' => $eventsLastHour,
            ];
        }, 10);

        $system = Cache::remember("dashboard_system_{$accountId}", function () {
            $lastEventAt = Database::scalar('SELECT MAX(received_at) FROM events');
            $lastAggAt = Database::scalar('SELECT updated_at FROM agg_watermark WHERE id = 1');
            $lag = self::ingestLag();
            return [
                'last_event_at' => $lastEventAt,
                'last_aggregation_at' => $lastAggAt,
                'pending_events' => $lag,
            ];
        }, 10);

        Response::ok([
            'totals' => $data,
            'system' => $system,
        ]);
    }

    public static function eventsOverTime(): void
    {
        Auth::requireLogin();
        $range = (string)($_GET['range'] ?? '24h');
        $accountId = Auth::accountId();

        $data = Cache::remember("events_over_time_{$accountId}_{$range}", function () use ($range) {
            [$interval, $format] = match ($range) {
                '7d'   => ['7 DAY', '%Y-%m-%d %H:00'],
                '30d'  => ['30 DAY', '%Y-%m-%d'],
                default => ['24 HOUR', '%Y-%m-%d %H:00'],
            };

            $scope = Auth::accountFilterSql('ev');

            $rows = Database::fetchAll(
                "SELECT DATE_FORMAT(event_time, '$format') AS bucket, COUNT(*) AS cnt
                 FROM events ev
                 WHERE ev.event_time >= UTC_TIMESTAMP() - INTERVAL $interval"
                . ($scope ? ' AND ' . $scope : '') . "
                 GROUP BY bucket
                 ORDER BY bucket ASC"
            );

            return array_map(fn($r) => [
                'time' => $r['bucket'],
                'events' => (int)$r['cnt'],
            ], $rows);
        }, 30);

        Response::ok(['range' => $range, 'points' => $data]);
    }

    public static function eventTypes(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();

        $data = Cache::remember("event_types_{$accountId}", function () {
            $scope = Auth::accountFilterSql('ev');
            $where = $scope ? (' WHERE ' . $scope) : '';

            $rows = Database::fetchAll(
                'SELECT event_type, COUNT(*) AS cnt
                 FROM events ev' . $where . '
                 GROUP BY event_type
                 ORDER BY cnt DESC'
            );

            return array_map(fn($r) => [
                'type' => $r['event_type'],
                'count' => (int)$r['cnt'],
            ], $rows);
        }, 30);

        Response::ok(['types' => $data]);
    }

    public static function liveFeed(): void
    {
        Auth::requireLogin();
        $afterId = max(0, (int)($_GET['after_id'] ?? 0));
        $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));

        $scope = Auth::accountFilterSql('ev');
        $where = 'ev.id > ?';
        $params = [$afterId];
        if ($scope) {
            $where .= ' AND ' . $scope;
        }

        $rows = Database::fetchAll(
            "SELECT ev.id, ev.event_id, ev.session_id, ev.event_type, ev.event_time,
                    ev.received_at, ev.ip_address, ev.moodle_user_id, ev.moodle_quiz_id,
                    ev.moodle_course_id, ev.payload
             FROM events ev
             WHERE $where
             ORDER BY ev.id DESC
             LIMIT ?",
            array_merge($params, [$limit])
        );

        $out = [];
        foreach ($rows as $r) {
            $payload = json_decode($r['payload'] ?? '{}', true) ?: [];
            $moodle = $payload['moodle'] ?? [];
            $student = $moodle['student'] ?? [];
            $out[] = [
                'id' => (int)$r['id'],
                'event_id' => $r['event_id'],
                'session_id' => $r['session_id'],
                'event_type' => $r['event_type'],
                'event_time' => $r['event_time'],
                'received_at' => $r['received_at'],
                'ip_address' => $r['ip_address'],
                'moodle_user_id' => (int)$r['moodle_user_id'],
                'moodle_quiz_id' => (int)$r['moodle_quiz_id'],
                'moodle_course_id' => (int)$r['moodle_course_id'],
                'student_name' => $student['fullname'] ?? null,
                'student_username' => $student['username'] ?? null,
                'exam_name' => $moodle['quiz_name'] ?? null,
                'course_name' => $moodle['course_name'] ?? null,
            ];
        }

        Response::ok(['events' => $out]);
    }

    public static function topSuspicious(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();

        $data = Cache::remember("top_suspicious_{$accountId}", function () {
            $scope = Auth::accountFilterSql('ss');
            $where = $scope ? " AND $scope" : '';

            $rows = Database::fetchAll(
                "SELECT ss.id, ss.student_id,
                        COALESCE(st.fullname, CONCAT('طالب #', ss.student_id)) AS student_name,
                        COALESCE(st.username, '') AS student_username,
                        ss.exam_id,
                        COALESCE(e.name, CONCAT('امتحان #', ss.exam_id)) AS exam_name,
                        COALESCE(e.moodle_course_id, c.id, 0) AS course_id,
                        COALESCE(c.name, CONCAT('مساق #', COALESCE(e.moodle_course_id, 0))) AS course_name,
                        ss.session_id, ss.risk_score, ss.risk_level, ss.event_count,
                        ss.tab_hidden_count, ss.paste_count, ss.copy_count,
                        ss.ai_suspect_score, ss.same_ip_student_count, ss.similarity_max_score,
                        ss.first_event_at, ss.last_event_at
                 FROM session_summaries ss
                 LEFT JOIN students st ON (st.id = ss.student_id OR st.moodle_user_id = ss.student_id)
                 LEFT JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
                 LEFT JOIN courses c ON (c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = 0))
                 WHERE ss.risk_level IN ('high','critical') $where
                 ORDER BY ss.risk_score DESC, ss.last_event_at DESC
                 LIMIT 10"
            );

            return array_map(function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'student_id' => (int)$r['student_id'],
                    'student_name' => $r['student_name'] ?: ("طالب #" . $r['student_id']),
                    'student_username' => $r['student_username'] ?: '',
                    'exam_id' => (int)$r['exam_id'],
                    'exam_name' => $r['exam_name'] ?: ("امتحان #" . $r['exam_id']),
                    'course_id' => (int)$r['course_id'],
                    'course_name' => $r['course_name'] ?: ("مساق #" . $r['course_id']),
                    'session_id' => $r['session_id'],
                    'risk_score' => (int)$r['risk_score'],
                    'risk_level' => $r['risk_level'],
                    'event_count' => (int)$r['event_count'],
                    'tab_hidden_count' => (int)$r['tab_hidden_count'],
                    'paste_count' => (int)$r['paste_count'],
                    'copy_count' => (int)$r['copy_count'],
                    'ai_suspect_score' => (int)$r['ai_suspect_score'],
                    'same_ip_student_count' => (int)$r['same_ip_student_count'],
                    'similarity_max_score' => (int)$r['similarity_max_score'],
                    'first_event_at' => $r['first_event_at'],
                    'last_event_at' => $r['last_event_at'],
                ];
            }, $rows);
        }, 15);

        Response::ok(['students' => $data]);
    }

    public static function topRisky(): void
    {
        self::topSuspicious();
    }

    /**
     * GET /api/dashboard/edu-overview
     * Hierarchical overview: Courses -> Exams with aggregated KPIs.
     */
    public static function eduOverview(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        $isOwner = Auth::isOwner();

        // Incrementally aggregate any pending events
        try { Aggregator::process(500); } catch (\Throwable $e) {}

        $data = Cache::remember("edu_overview_{$accountId}_" . ($isOwner ? 'owner' : 'tenant'), function () use ($accountId, $isOwner) {
            // 1. Courses
            $whereCourse = ($isOwner || $accountId === 0) ? '' : ' WHERE c.account_id = ? OR c.account_id = 0';
            $paramsCourse = ($isOwner || $accountId === 0) ? [] : [$accountId];

            $courses = Database::fetchAll(
                'SELECT c.id, c.name, c.moodle_course_id
                 FROM courses c ' . $whereCourse . ' ORDER BY c.name',
                $paramsCourse
            );

            // Fallback: if courses table has no entries, discover from exams table
            if (empty($courses)) {
                $whereExCourse = ($isOwner || $accountId === 0) ? 'WHERE moodle_course_id > 0' : 'WHERE moodle_course_id > 0 AND (account_id = ? OR account_id = 0)';
                $paramsExCourse = ($isOwner || $accountId === 0) ? [] : [$accountId];
                $discovered = Database::fetchAll(
                    'SELECT DISTINCT moodle_course_id AS id, moodle_course_id, CONCAT("مساق #", moodle_course_id) AS name
                     FROM exams ' . $whereExCourse,
                    $paramsExCourse
                );
                $courses = $discovered;
            }

            $moodleCourseIds = array_map('intval', array_column($courses, 'moodle_course_id'));
            $examsByCourse = [];

            if (!empty($moodleCourseIds)) {
                $placeholders = implode(',', array_fill(0, count($moodleCourseIds), '?'));
                $whereExam = ($isOwner || $accountId === 0) ? '' : ' AND (e.account_id = ? OR e.account_id = 0)';
                $paramsExam = ($isOwner || $accountId === 0) ? $moodleCourseIds : array_merge($moodleCourseIds, [$accountId]);

                // 2. All exams for these courses
                $exams = Database::fetchAll(
                    "SELECT e.id, e.name, e.moodle_quiz_id, e.moodle_course_id, e.status,
                            (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE (ss.exam_id = e.id OR ss.exam_id = e.moodle_quiz_id)) AS student_count,
                            (SELECT COUNT(*) FROM events ev WHERE (ev.moodle_quiz_id = e.moodle_quiz_id OR ev.moodle_quiz_id = e.id)) AS event_count,
                            (SELECT COUNT(DISTINCT ss2.student_id) FROM session_summaries ss2 WHERE (ss2.exam_id = e.id OR ss2.exam_id = e.moodle_quiz_id) AND ss2.risk_level IN ('high','critical')) AS suspicious_count,
                            (SELECT ROUND(AVG(ss3.risk_score), 1) FROM session_summaries ss3 WHERE (ss3.exam_id = e.id OR ss3.exam_id = e.moodle_quiz_id)) AS avg_risk
                     FROM exams e
                     WHERE e.moodle_course_id IN ($placeholders)" . $whereExam . "
                     ORDER BY e.name",
                    $paramsExam
                );
                foreach ($exams as $ex) {
                    $mcid = (int)$ex['moodle_course_id'];
                    $stCount = (int)$ex['student_count'];
                    $evCount = (int)$ex['event_count'];
                    if ($stCount === 0 && $evCount > 0) {
                        $stCount = (int)Database::scalar(
                            'SELECT COUNT(DISTINCT moodle_user_id) FROM events WHERE (moodle_quiz_id = ? OR moodle_quiz_id = ?)',
                            [(int)$ex['moodle_quiz_id'], (int)$ex['id']]
                        );
                    }
                    $examsByCourse[$mcid][] = [
                        'id' => (int)$ex['id'],
                        'name' => $ex['name'],
                        'moodle_quiz_id' => (int)$ex['moodle_quiz_id'],
                        'status' => $ex['status'],
                        'student_count' => $stCount,
                        'event_count' => $evCount,
                        'suspicious_count' => (int)$ex['suspicious_count'],
                        'avg_risk' => (float)($ex['avg_risk'] ?? 0),
                    ];
                }
            }

            // 3. Risk distribution
            $whereRisk = ($isOwner || $accountId === 0) ? '' : ' WHERE account_id = ? OR account_id = 0';
            $paramsRisk = ($isOwner || $accountId === 0) ? [] : [$accountId];
            $riskDist = Database::fetchAll(
                'SELECT risk_level AS level, COUNT(*) AS cnt
                 FROM session_summaries ' . $whereRisk . '
                 GROUP BY risk_level',
                $paramsRisk
            );

            // 4. Threats
            $whereThreats = ($isOwner || $accountId === 0) ? '' : ' WHERE account_id = ? OR account_id = 0';
            $paramsThreats = ($isOwner || $accountId === 0) ? [] : [$accountId];
            $threats = Database::fetchAll(
                'SELECT event_type AS type, COUNT(*) AS cnt
                 FROM events ' . $whereThreats . '
                 GROUP BY event_type ORDER BY cnt DESC LIMIT 8',
                $paramsThreats
            );

            // 5. Top suspicious
            $whereTop = ($isOwner || $accountId === 0) ? ' WHERE ss.risk_level IN ("high","critical")' : ' WHERE (ss.account_id = ? OR ss.account_id = 0) AND ss.risk_level IN ("high","critical")';
            $paramsTop = ($isOwner || $accountId === 0) ? [] : [$accountId];
            $topSuspicious = Database::fetchAll(
                'SELECT ss.student_id, ss.risk_score, ss.risk_level,
                        ss.same_ip_student_count, ss.ai_suspect_score, ss.similarity_max_score,
                        ss.copy_count, ss.paste_count, ss.tab_hidden_count,
                        COALESCE(s.fullname, CONCAT("طالب #", ss.student_id)) AS fullname,
                        COALESCE(s.username, "") AS username,
                        e.name AS exam_name, e.id AS exam_id,
                        c.name AS course_name
                 FROM session_summaries ss
                 LEFT JOIN students s ON (s.id = ss.student_id OR s.moodle_user_id = ss.student_id)
                 LEFT JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
                 LEFT JOIN courses c ON (c.moodle_course_id = e.moodle_course_id)
                 ' . $whereTop . '
                 ORDER BY ss.risk_score DESC
                 LIMIT 10',
                $paramsTop
            );

            // 6. Total students
            $whereStudents = ($isOwner || $accountId === 0) ? '' : ' WHERE account_id = ? OR account_id = 0';
            $paramsStudents = ($isOwner || $accountId === 0) ? [] : [$accountId];
            $totalStudentsAll = (int)Database::scalar(
                'SELECT COUNT(DISTINCT moodle_user_id) FROM students ' . $whereStudents,
                $paramsStudents
            );
            if ($totalStudentsAll === 0) {
                $totalStudentsAll = (int)Database::scalar(
                    'SELECT COUNT(DISTINCT moodle_user_id) FROM events ' . $whereStudents,
                    $paramsStudents
                );
            }

            return [
                'courses' => array_map(function ($c) use ($examsByCourse) {
                    $mcid = (int)$c['moodle_course_id'];
                    $exams = $examsByCourse[$mcid] ?? [];
                    return [
                        'id' => (int)$c['id'],
                        'name' => $c['name'],
                        'moodle_course_id' => $mcid,
                        'exam_count' => count($exams),
                        'student_count' => array_sum(array_column($exams, 'student_count')),
                        'suspicious_count' => array_sum(array_column($exams, 'suspicious_count')),
                        'exams' => $exams,
                    ];
                }, $courses),
                'risk_distribution' => array_map(fn($r) => ['level' => $r['level'], 'count' => (int)$r['cnt']], $riskDist),
                'threats' => array_map(fn($r) => ['type' => $r['type'], 'count' => (int)$r['cnt']], $threats),
                'top_suspicious' => array_map(fn($r) => [
                    'student_id' => (int)$r['student_id'],
                    'fullname' => $r['fullname'],
                    'username' => $r['username'],
                    'exam_name' => $r['exam_name'],
                    'exam_id' => (int)$r['exam_id'],
                    'course_name' => $r['course_name'] ?? '—',
                    'risk_score' => (int)$r['risk_score'],
                    'risk_level' => $r['risk_level'],
                    'same_ip_student_count' => (int)$r['same_ip_student_count'],
                    'ai_suspect_score' => (int)$r['ai_suspect_score'],
                    'similarity_max_score' => (int)$r['similarity_max_score'],
                    'copy_count' => (int)$r['copy_count'],
                    'paste_count' => (int)$r['paste_count'],
                    'tab_hidden_count' => (int)$r['tab_hidden_count'],
                ], $topSuspicious),
                'total_students' => $totalStudentsAll,
            ];
        }, 10);

        Response::ok($data);
    }

    private static function ingestLag(): int
    {
        $max = (int)Database::scalar('SELECT COALESCE(MAX(id), 0) FROM events');
        $watermark = (int)Database::scalar('SELECT last_event_id FROM agg_watermark WHERE id = 1');
        return max(0, $max - $watermark);
    }
}
