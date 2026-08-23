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

        $data = Cache::remember("dashboard_summary_{$accountId}", function () use ($accountId, $isOwner) {
            $scopeEv = Auth::accountFilterSql('ev');
            $scopeE  = Auth::accountFilterSql('e');
            $scopeSs = Auth::accountFilterSql('ss');
            $scopeSt = Auth::accountFilterSql('s');

            $eventsWhere = $scopeEv ? (' WHERE ' . $scopeEv) : '';

            $totalEvents = (int)Database::scalar('SELECT COUNT(*) FROM events ev' . $eventsWhere);
            $totalSessions = (int)Database::scalar('SELECT COUNT(DISTINCT session_id) FROM events ev' . $eventsWhere);
            $totalStudents = (int)Database::scalar('SELECT COUNT(*) FROM students s' . ($scopeSt ? ' WHERE ' . $scopeSt : ''));
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
                'exams' => $examsTotal,
                'active_exams' => $examsActive,
                'suspicious_sessions' => $suspiciousSessions,
                'suspicious_students' => $suspiciousStudents,
                'events_last_hour' => $eventsLastHour,
            ];
        }, 30);

        $system = Cache::remember("dashboard_system_{$accountId}", function () {
            $lastEventAt = Database::scalar('SELECT MAX(received_at) FROM events');
            $lastAggAt = Database::scalar('SELECT updated_at FROM agg_watermark WHERE id = 1');
            $lag = self::ingestLag();
            return [
                'last_event_at' => $lastEventAt,
                'last_aggregation_at' => $lastAggAt,
                'pending_events' => $lag,
            ];
        }, 15);

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
        }, 60);

        Response::ok(['range' => $range, 'points' => $data]);
    }

    public static function eventTypes(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();

        $data = Cache::remember("event_types_{$accountId}", function () {
            $scope = Auth::accountFilterSql('ev');

            $sql = 'SELECT event_type AS type, COUNT(*) AS cnt
                    FROM events ev';
            if ($scope) {
                $sql .= ' WHERE ' . $scope;
            }
            $sql .= ' GROUP BY event_type ORDER BY cnt DESC';

            $rows = Database::fetchAll($sql);
            return array_map(fn($r) => [
                'type' => $r['type'],
                'count' => (int)$r['cnt'],
            ], $rows);
        }, 60);

        Response::ok($data);
    }

    public static function topRisky(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();

        $data = Cache::remember("top_risky_{$accountId}", function () {
            $scopeSs = Auth::accountFilterSql('ss');
            $scopeSt = Auth::accountFilterSql('s');
            $scopeE  = Auth::accountFilterSql('e');

            $where = [];
            if ($scopeSs) {
                $where[] = $scopeSs;
            }
            if ($scopeSt) {
                $where[] = $scopeSt;
            }
            if ($scopeE) {
                $where[] = $scopeE;
            }
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

            $rows = Database::fetchAll(
                'SELECT ss.id, ss.session_id, ss.student_id, ss.risk_score, ss.risk_level,
                        ss.tab_hidden_count, ss.tab_visible_count, ss.copy_count, ss.copy_selection_chars, ss.paste_count,
                        ss.devtools_count, ss.suspicious_key_count, ss.screenshot_count, ss.rapid_answer_changes,
                        ss.idle_count, ss.idle_duration_ms, ss.fullscreen_exit_count,
                        ss.typing_keydown_count, ss.typing_backspace_count, ss.typing_enter_count,
                        ss.mouse_click_count, ss.mouse_move_count, ss.mouse_scroll_count,
                        ss.last_event_at, ss.event_count,
                        s.fullname, s.username,
                        e.name AS exam_name, e.id AS exam_id, e.moodle_quiz_id
                 FROM session_summaries ss
                 JOIN students s ON s.id = ss.student_id
                 JOIN exams e ON e.id = ss.exam_id' . $whereSql . '
                 ORDER BY ss.risk_score DESC, ss.last_event_at DESC
                 LIMIT 10'
            );

            return array_map(function ($r) {
                $r['risk_score'] = (int)$r['risk_score'];
                $r['tab_hidden_count'] = (int)$r['tab_hidden_count'];
                $r['tab_visible_count'] = (int)$r['tab_visible_count'];
                $r['copy_count'] = (int)$r['copy_count'];
                $r['copy_selection_chars'] = (int)$r['copy_selection_chars'];
                $r['paste_count'] = (int)$r['paste_count'];
                $r['devtools_count'] = (int)$r['devtools_count'];
                $r['suspicious_key_count'] = (int)$r['suspicious_key_count'];
                $r['screenshot_count'] = (int)$r['screenshot_count'];
                $r['rapid_answer_changes'] = (int)$r['rapid_answer_changes'];
                $r['idle_count'] = (int)$r['idle_count'];
                $r['idle_duration_ms'] = (int)$r['idle_duration_ms'];
                $r['fullscreen_exit_count'] = (int)$r['fullscreen_exit_count'];
                $r['typing_keydown_count'] = (int)$r['typing_keydown_count'];
                $r['typing_backspace_count'] = (int)$r['typing_backspace_count'];
                $r['typing_enter_count'] = (int)$r['typing_enter_count'];
                $r['mouse_click_count'] = (int)$r['mouse_click_count'];
                $r['mouse_move_count'] = (int)$r['mouse_move_count'];
                $r['mouse_scroll_count'] = (int)$r['mouse_scroll_count'];
                $r['event_count'] = (int)$r['event_count'];
                $r['exam_id'] = (int)$r['exam_id'];
                $r['moodle_quiz_id'] = (int)$r['moodle_quiz_id'];
                return $r;
            }, $rows);
        }, 30);

        Response::ok($data);
    }

    /**
     * Rich educational overview: courses → exams → students + risk per course.
     */
    public static function eduOverview(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();

        $data = Cache::remember("edu_overview_{$accountId}", function () use ($accountId) {
            // 1. Courses
            $courses = Database::fetchAll(
                'SELECT c.id, c.name, c.moodle_course_id
                 FROM courses c WHERE c.account_id = ? ORDER BY c.name',
                [$accountId]
            );
            $moodleCourseIds = array_column($courses, 'moodle_course_id');
            $examsByCourse = [];

            if (!empty($moodleCourseIds)) {
                $placeholders = implode(',', array_fill(0, count($moodleCourseIds), '?'));

                // 2. All exams for these courses (with correlated sub-counts — cached)
                $exams = Database::fetchAll(
                    "SELECT e.id, e.name, e.moodle_quiz_id, e.moodle_course_id, e.status,
                            (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS student_count,
                            (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = e.moodle_quiz_id AND ev.account_id = e.account_id) AS event_count,
                            (SELECT COUNT(DISTINCT ss2.student_id) FROM session_summaries ss2 WHERE ss2.exam_id = e.id AND ss2.account_id = e.account_id AND ss2.risk_level IN ('high','critical')) AS suspicious_count,
                            (SELECT ROUND(AVG(ss3.risk_score), 1) FROM session_summaries ss3 WHERE ss3.exam_id = e.id AND ss3.account_id = e.account_id) AS avg_risk
                     FROM exams e
                     WHERE e.moodle_course_id IN ($placeholders) AND e.account_id = ?
                     ORDER BY e.name",
                    array_merge($moodleCourseIds, [$accountId])
                );
                foreach ($exams as $ex) {
                    $mcid = (int)$ex['moodle_course_id'];
                    $examsByCourse[$mcid][] = [
                        'id' => (int)$ex['id'],
                        'name' => $ex['name'],
                        'moodle_quiz_id' => (int)$ex['moodle_quiz_id'],
                        'status' => $ex['status'],
                        'student_count' => (int)$ex['student_count'],
                        'event_count' => (int)$ex['event_count'],
                        'suspicious_count' => (int)$ex['suspicious_count'],
                        'avg_risk' => (float)$ex['avg_risk'],
                    ];
                }
            }

            // 3. Risk distribution
            $riskDist = Database::fetchAll(
                'SELECT risk_level AS level, COUNT(*) AS cnt
                 FROM session_summaries WHERE account_id = ?
                 GROUP BY risk_level',
                [$accountId]
            );

            // 4. Threats
            $threats = Database::fetchAll(
                'SELECT event_type AS type, COUNT(*) AS cnt
                 FROM events WHERE account_id = ?
                 GROUP BY event_type ORDER BY cnt DESC LIMIT 8',
                [$accountId]
            );

            // 5. Top suspicious
            $topSuspicious = Database::fetchAll(
                'SELECT ss.student_id, ss.risk_score, ss.risk_level,
                        ss.same_ip_student_count, ss.ai_suspect_score, ss.similarity_max_score,
                        ss.copy_count, ss.paste_count, ss.tab_hidden_count,
                        s.fullname, s.username,
                        e.name AS exam_name, e.id AS exam_id,
                        c.name AS course_name
                 FROM session_summaries ss
                 JOIN students s ON s.id = ss.student_id
                 JOIN exams e ON e.id = ss.exam_id
                 LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id AND c.account_id = e.account_id
                 WHERE ss.account_id = ? AND ss.risk_level IN ("high","critical")
                 ORDER BY ss.risk_score DESC
                 LIMIT 10',
                [$accountId]
            );

            // 6. Total students
            $totalStudentsAll = (int)Database::scalar(
                'SELECT COUNT(DISTINCT moodle_user_id) FROM students WHERE account_id = ?',
                [$accountId]
            );

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
        }, 30);

        Response::ok($data);
    }

    private static function ingestLag(): int
    {
        $max = (int)Database::scalar('SELECT COALESCE(MAX(id), 0) FROM events');
        $watermark = (int)Database::scalar('SELECT last_event_id FROM agg_watermark WHERE id = 1');
        return max(0, $max - $watermark);
    }
}
