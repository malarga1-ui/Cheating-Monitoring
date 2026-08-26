<?php
/**
 * NetworkAnalyzer — Simplified network risk scoring (Eq 3.12-3.14).
 *
 * N_i = (z_IP + z_CS) / 2
 *
 *   z_IP = 1 if IP changed during exam session, else 0
 *   z_CS = 1 if concurrent active sessions detected for same student, else 0
 *
 * Values: 0.0, 0.5, or 1.0
 * Stored as 0-100 in DB, used as 0-1 in RiskEngine.
 */

final class NetworkAnalyzer
{
    /* ── Run for a full exam ────────────────────────────────────── */

    /**
     * Analyze all sessions for a given exam.
     *
     * @return array{groups: array, sessions: array<string,array>}
     */
    public static function analyzeExam(int $accountId, int $examId): array
    {
        $db = Database::connection();

        $exam = Database::fetchOne(
            'SELECT id, moodle_quiz_id, account_id FROM exams WHERE id = ? OR moodle_quiz_id = ? ORDER BY (account_id = ?) DESC LIMIT 1',
            [$examId, $examId, $accountId]
        );
        $intId = $exam ? (int)$exam['id'] : $examId;
        $quizId = $exam ? (int)$exam['moodle_quiz_id'] : $examId;

        $groups = self::buildIPGroups($db, $accountId, $intId, $quizId);
        $sessions = self::scoreSessions($db, $accountId, $intId, $quizId, $groups);

        self::persistGroups($db, $accountId, $intId, $groups);
        self::persistSessionScores($db, $sessions);

        return [
            'groups'   => $groups,
            'sessions' => $sessions,
        ];
    }

    /**
     * Quick score for a single session.
     */
    public static function scoreSession(int $accountId, string $sessionId): array
    {
        $db = Database::connection();

        $summary = $db->prepare(
            'SELECT account_id, exam_id, ip_address FROM session_summaries WHERE session_id = ? AND (account_id = ? OR account_id = 0)'
        );
        $summary->execute([$sessionId, $accountId]);
        $row = $summary->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ip_changed' => false, 'concurrent_sessions' => false, 'network_score_N' => 0];
        }

        $examId = (int)$row['exam_id'];
        $exam = Database::fetchOne('SELECT id, moodle_quiz_id FROM exams WHERE id = ? OR moodle_quiz_id = ? LIMIT 1', [$examId, $examId]);
        $intId = $exam ? (int)$exam['id'] : $examId;
        $quizId = $exam ? (int)$exam['moodle_quiz_id'] : $examId;

        // z_IP: check if IP changed
        $ips = self::getSessionIPs($db, $sessionId);
        $zIP = count($ips) > 1 ? 1 : 0;

        // z_CS: check for concurrent active sessions
        $zCS = self::detectConcurrentSessions($db, $accountId, $intId, $quizId, $sessionId);

        $score = ($zIP + $zCS) / 2.0;
        $scorePct = (int)round($score * 100);

        return [
            'ip_changed'          => $zIP === 1,
            'concurrent_sessions' => $zCS === 1,
            'network_score_N'     => $scorePct,
        ];
    }

    /* ── z_IP Detection ───────────────────────────────────────── */

    /**
     * Check if student's IP changed during exam (Eq 3.13).
     * z_IP = 1 if more than 1 distinct IP found.
     */
    private static function detectIPChange(PDO $db, string $sessionId): int
    {
        $ips = self::getSessionIPs($db, $sessionId);
        return count($ips) > 1 ? 1 : 0;
    }

    /* ── z_CS Detection ───────────────────────────────────────── */

    /**
     * Check for concurrent active sessions for same student (Eq 3.14).
     * z_CS = 1 if the same student has overlapping active sessions.
     */
    private static function detectConcurrentSessions(PDO $db, int $accountId, int $intId, int $quizId, string $sessionId): int
    {
        // Get student_id for this session
        $st = $db->prepare(
            "SELECT student_id FROM session_summaries WHERE session_id = :s AND (account_id = :a OR account_id = 0)"
        );
        $st->execute([':s' => $sessionId, ':a' => $accountId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 0;

        $studentId = (int)$row['student_id'];

        // Count other active sessions for same student in same exam
        $st2 = $db->prepare(
            "SELECT COUNT(DISTINCT session_id) as cnt
             FROM session_summaries
             WHERE (account_id = :a OR account_id = 0) AND (exam_id = :eid OR exam_id = :qid) AND student_id = :sid
               AND session_id != :s"
        );
        $st2->execute([':a' => $accountId, ':eid' => $intId, ':qid' => $quizId, ':sid' => $studentId, ':s' => $sessionId]);
        $otherSessions = (int)$st2->fetchColumn();

        return $otherSessions > 0 ? 1 : 0;
    }

    /* ── IP Groups ────────────────────────────────────────────── */

    private static function buildIPGroups(PDO $db, int $accountId, int $intId, int $quizId): array
    {
        $rows = $db->prepare(
            "SELECT session_id, student_id, ip_address, detected_at
             FROM ip_snapshots
             WHERE (account_id = :a OR account_id = 0) AND (exam_id = :eid OR exam_id = :qid)
             ORDER BY detected_at"
        );
        $rows->execute([':a' => $accountId, ':eid' => $intId, ':qid' => $quizId]);
        $snapshots = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($snapshots)) {
            $rows = $db->prepare(
                "SELECT session_id, moodle_user_id AS student_id, ip_address, event_time AS detected_at
                 FROM events
                 WHERE (account_id = :a OR account_id = 0) AND (moodle_quiz_id = :qid OR moodle_quiz_id = :eid) AND ip_address != '' AND ip_address != 'unknown'
                 GROUP BY session_id, moodle_user_id, ip_address
                 ORDER BY event_time"
            );
            $rows->execute([':a' => $accountId, ':qid' => $quizId, ':eid' => $intId]);
            $snapshots = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $groups = [];
        foreach ($snapshots as $s) {
            $ip = $s['ip_address'] ?: 'unknown';
            if ($ip === 'unknown' || $ip === '') continue;
            if (!isset($groups[$ip])) {
                $groups[$ip] = [
                    'ip'       => $ip,
                    'students' => [],
                    'sessions' => [],
                ];
            }
            $groups[$ip]['students'][(int)$s['student_id']] = true;
            $groups[$ip]['sessions'][$s['session_id']] = true;
        }

        foreach ($groups as &$g) {
            $g['student_count'] = count($g['students']);
            $g['student_ids'] = array_keys($g['students']);
            $g['session_count'] = count($g['sessions']);
        }
        unset($g);

        uasort($groups, fn($a, $b) => $b['student_count'] <=> $a['student_count']);

        return array_values($groups);
    }

    /* ── Scoring ─────────────────────────────────────────────── */

    private static function scoreSessions(PDO $db, int $accountId, int $intId, int $quizId, array $groups): array
    {
        $ipCounts = [];
        foreach ($groups as $g) {
            $ipCounts[$g['ip']] = $g['student_count'];
        }

        $sessions = $db->prepare(
            "SELECT ss.session_id, ss.student_id, ss.ip_address
             FROM session_summaries ss
             WHERE (ss.exam_id = :eid OR ss.exam_id = :qid) AND (ss.account_id = :a OR ss.account_id = 0)"
        );
        $sessions->execute([':eid' => $intId, ':qid' => $quizId, ':a' => $accountId]);
        $rows = $sessions->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Bulk-fetch IPs from ip_snapshots for sessions with empty ip_address
        $needIP = [];
        foreach ($rows as $r) {
            if (empty($r['ip_address'])) {
                $needIP[] = $r['session_id'];
            }
        }
        $snapIPs = [];
        if (!empty($needIP)) {
            $placeholders = implode(',', array_fill(0, count($needIP), '?'));
            $snapSt = $db->prepare(
                "SELECT session_id, ip_address FROM ip_snapshots
                 WHERE session_id IN ($placeholders) AND ip_address != '' AND ip_address != 'unknown'
                 GROUP BY session_id ORDER BY detected_at"
            );
            $snapSt->execute($needIP);
            foreach ($snapSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $snapIPs[$row['session_id']] = $row['ip_address'];
            }
        }

        $scores = [];
        foreach ($rows as $r) {
            $sid = $r['session_id'];
            $studentId = (int)$r['student_id'];
            $ip = $r['ip_address'] ?: ($snapIPs[$sid] ?? '');

            // z_IP: check if IP changed
            $zIP = self::detectIPChange($db, $sid);

            // z_CS: check for concurrent sessions
            $zCS = self::detectConcurrentSessions($db, $accountId, $intId, $quizId, $sid);

            // Same IP count for this IP
            $sameCount = isset($ipCounts[$ip]) ? max(0, $ipCounts[$ip] - 1) : 0;
            $sameIpRisk = $sameCount >= 2 ? 100 : ($sameCount >= 1 ? 80 : 0);

            // N_i = (z_IP + z_CS) / 2
            $score = ($zIP + $zCS) / 2.0;
            $baseScorePct = (int)round($score * 100);
            $finalNetScore = max($baseScorePct, $sameIpRisk);

            $scores[$sid] = [
                'session_id'       => $sid,
                'student_id'       => $studentId,
                'ip_changed'       => $zIP === 1,
                'ip_changed_count' => $zIP,
                'concurrent_sessions' => $zCS === 1,
                'risk_score'       => $sameIpRisk,
                'network_score_N'  => $finalNetScore,
                'primary_ip'       => $ip,
                'same_ip_count'    => $sameCount,
            ];
        }

        return $scores;
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    private static function getSessionIPs(PDO $db, string $sessionId): array
    {
        $st = $db->prepare(
            "SELECT DISTINCT ip_address FROM ip_snapshots
             WHERE session_id = :s AND ip_address != '' AND ip_address != 'unknown'"
        );
        $st->execute([':s' => $sessionId]);
        $ips = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if (empty($ips)) {
            $st = $db->prepare(
                "SELECT DISTINCT ip_address FROM events
                 WHERE session_id = :s AND ip_address != '' AND ip_address != 'unknown'"
            );
            $st->execute([':s' => $sessionId]);
            $ips = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        return $ips;
    }

    /* ── Persistence ──────────────────────────────────────────── */

    private static function persistGroups(PDO $db, int $accountId, int $examId, array $groups): void
    {
        foreach ($groups as $g) {
            if ($g['student_count'] < 2) continue;

            $db->prepare(
                "INSERT INTO network_groups (account_id, exam_id, ip_address, student_count, student_ids, risk_level)
                 VALUES (:a, :e, :ip, :c, :ids, :lvl)
                 ON DUPLICATE KEY UPDATE student_count = VALUES(student_count), student_ids = VALUES(student_ids), risk_level = VALUES(risk_level)"
            )->execute([
                ':a'   => $accountId,
                ':e'   => $examId,
                ':ip'  => $g['ip'],
                ':c'   => $g['student_count'],
                ':ids' => json_encode($g['student_ids']),
                ':lvl' => $g['student_count'] >= 3 ? 'high' : 'low',
            ]);
        }
    }

    private static function persistSessionScores(PDO $db, array $sessions): void
    {
        $st = $db->prepare(
            "UPDATE session_summaries
             SET ip_address = :ip,
                 same_ip_student_count = :same,
                 ip_changed_count = :changed,
                 same_ip_risk_score = :risk,
                 network_score_N = :netN
             WHERE session_id = :s"
        );
        foreach ($sessions as $s) {
            $st->execute([
                ':ip'      => $s['primary_ip'],
                ':same'    => $s['same_ip_count'],
                ':changed' => $s['ip_changed_count'],
                ':risk'    => $s['risk_score'],
                ':netN'    => $s['network_score_N'],
                ':s'       => $s['session_id'],
            ]);
        }
    }
}
