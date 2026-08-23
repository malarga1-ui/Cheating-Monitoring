<?php
/**
 * Raw telemetry viewer (tenant-scoped by account).
 */
final class RawController
{
    /** List raw events with their full JSON payloads. */
    public static function events(): void
    {
        Auth::requireLogin();
        $scope = Auth::accountFilterSql('ev');

        $limit  = min(max((int)($_GET['limit'] ?? 50), 1), 200);
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $type   = trim((string)($_GET['type'] ?? ''));
        $session = trim((string)($_GET['session'] ?? ''));
        $user   = (int)($_GET['user'] ?? 0);
        $q      = trim((string)($_GET['q'] ?? ''));

        $where = [];
        $params = [];
        if ($scope) $where[] = $scope;
        if ($type !== '') {
            $where[] = 'event_type = ?';
            $params[] = $type;
        }
        if ($session !== '') {
            $where[] = 'session_id = ?';
            $params[] = $session;
        }
        if ($user > 0) {
            $where[] = 'moodle_user_id = ?';
            $params[] = $user;
        }
        if ($q !== '') {
            $where[] = '(event_id LIKE ? OR session_id LIKE ? OR payload LIKE ?)';
            $like = "%$q%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int)Database::scalar(
            "SELECT COUNT(*) FROM events $whereSql",
            $params
        );

        $rows = Database::fetchAll(
            "SELECT id, event_id, schema_version, session_id, sequence_number,
                    event_type, moodle_user_id, moodle_quiz_id, moodle_course_id,
                    moodle_cmid, attempt_id, event_time, received_at, elapsed_ms,
                    duration_ms, ip_address, user_agent, url, payload
             FROM events
             $whereSql
             ORDER BY id DESC
             LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset,
            $params
        );

        $events = array_map(function ($r) {
            $payload = json_decode($r['payload'], true);
            $r['payload'] = $payload !== null ? $payload : ['__raw' => $r['payload']];
            return $r;
        }, $rows);

        Response::ok([
            'events' => $events,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Distinct event types seen in the store (for the filter dropdown). */
    public static function types(): void
    {
        Auth::requireLogin();
        $scope = Auth::accountFilterSql('ev');

        $sql = 'SELECT event_type AS type, COUNT(*) AS cnt
                FROM events ev';
        if ($scope) {
            $sql .= ' WHERE ' . $scope;
        }
        $sql .= ' GROUP BY event_type ORDER BY cnt DESC';

        $rows = Database::fetchAll($sql);
        Response::ok($rows);
    }

    /** Latest event id and count — used to detect new arrivals. */
    public static function stats(): void
    {
        Auth::requireLogin();
        $scope = Auth::accountFilterSql('ev');
        $where = $scope ? ('WHERE ' . $scope) : '';

        $total = (int)Database::scalar('SELECT COUNT(*) FROM events' . $where);
        $latest = (int)Database::scalar('SELECT COALESCE(MAX(id), 0) FROM events' . $where);
        $lastAt = Database::scalar('SELECT MAX(received_at) FROM events' . $where);

        Response::ok([
            'total_events' => $total,
            'latest_id' => $latest,
            'last_event_at' => $lastAt,
        ]);
    }
}