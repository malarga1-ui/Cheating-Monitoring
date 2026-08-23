<?php
/**
 * NetworkAnalyzer v12 — Smart fingerprint + timing + device collision detection.
 *
 * Detection rules (shared IP alone = Score 0):
 *   1. IDENTICAL_DEVICE_COLLISION (+45%): Same IP + same fingerprint_hash = same physical device
 *   2. SYNCHRONIZED_ANSWERING    (+35%): Same answer to same question within ≤3s on same IP
 *   3. IP_CHANGED_MID_EXAM       (+30%): IP changes during exam session
 *   4. SUSPICIOUS_TIMEZONE        (+20%): Timezone mismatch with exam location
 *
 * Design: Shared IP alone (e.g., university hub/router) is Score 0 (innocent).
 * Only suspicious when combined with device fingerprint collision or timing correlation.
 */

final class NetworkAnalyzer
{
    /* ── Smart Detection Rule Weights ─────────────────────────── */
    private const W_IDENTICAL_DEVICE_COLLISION = 45;
    private const W_SYNCHRONIZED_ANSWERING     = 35;
    private const W_IP_CHANGED_MID_EXAM        = 30;
    private const W_SUSPICIOUS_TIMEZONE         = 20;

    /* ── Timing Parameters ────────────────────────────────────── */
    private const SYNC_WINDOW_SEC = 3;
    private const MIN_SYNC_PAIRS  = 2;

    /* ── Run for a full exam ────────────────────────────────────── */

    /**
     * Analyze all sessions for a given exam.
     *
     * @return array{groups:array, sessions:array<string,array>, multi_device:array, timing_correlation:array}
     */
    public static function analyzeExam(int $accountId, int $examId): array
    {
        $db = Database::connection();

        $groups = self::buildIPGroups($db, $accountId, $examId);
        $multiDevice = self::detectMultiDevice($db, $accountId, $examId);
        $timingCorrelation = self::detectTimingCorrelation($db, $accountId, $examId);
        $sessions = self::scoreSessions($db, $accountId, $examId, $groups, $multiDevice, $timingCorrelation);

        self::persistGroups($db, $accountId, $examId, $groups);
        self::persistSessionScores($db, $sessions);

        return [
            'groups' => $groups,
            'sessions' => $sessions,
            'multi_device' => $multiDevice,
            'timing_correlation' => $timingCorrelation,
        ];
    }

    /**
     * Quick score for a single session.
     */
    public static function scoreSession(int $accountId, string $sessionId): array
    {
        $db = Database::connection();

        $summary = $db->prepare('SELECT account_id, exam_id FROM session_summaries WHERE session_id = ? AND account_id = ?');
        $summary->execute([$sessionId, $accountId]);
        $row = $summary->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['same_ip_count' => 0, 'ip_changed_count' => 0, 'risk_score' => 0, 'network_score_N' => 0, 'multi_device' => false];
        }

        $examId = (int)$row['exam_id'];

        // Get this session's IPs and fingerprint_hash
        $ips = self::getSessionIPs($db, $sessionId);
        $fpCount = self::getSessionFPCount($db, $accountId, $examId, $sessionId);
        $fpHash = self::getSessionFingerprintHash($db, $sessionId);

        // Count how many other sessions share these IPs
        $sameIPCount = 0;
        foreach ($ips as $ip) {
            $count = self::countSessionsByIP($db, $accountId, $examId, $ip);
            $sameIPCount = max($sameIPCount, $count - 1);
        }

        $ipChangeCount = max(0, count($ips) - 1);
        $isMultiDevice = $fpCount > 1;

        // Smart scoring: shared IP alone = Score 0
        $score = 0;
        $deviceCollision = false;
        $syncAnswering = false;
        $ipChanged = false;
        $tzSuspicious = false;

        // Rule 1: IDENTICAL_DEVICE_COLLISION (+45%): Same IP + same fingerprint_hash
        if ($sameIPCount >= 1 && $fpHash !== '') {
            $deviceCollision = self::detectDeviceCollision($db, $accountId, $examId, $ips, $fpHash, $sessionId);
            if ($deviceCollision) {
                $score += self::W_IDENTICAL_DEVICE_COLLISION;
            }
        }

        // Rule 3: IP_CHANGED_MID_EXAM (+30%)
        if ($ipChangeCount > 0) {
            $score += self::W_IP_CHANGED_MID_EXAM;
            $ipChanged = true;
        }

        // Rule 4: SUSPICIOUS_TIMEZONE (+20%): Check for timezone mismatch
        $tzSuspicious = self::detectTimezoneMismatch($db, $sessionId);
        if ($tzSuspicious) {
            $score += self::W_SUSPICIOUS_TIMEZONE;
        }

        return [
            'same_ip_count'    => $sameIPCount,
            'ip_changed_count' => $ipChangeCount,
            'risk_score'       => min(100, $score),
            'network_score_N'  => min(100, $score),
            'multi_device'     => $isMultiDevice,
            'device_count'     => $fpCount,
            'device_collision' => $deviceCollision,
            'ip_changed'       => $ipChanged,
            'timezone_suspicious' => $tzSuspicious,
        ];
    }

    /* ── Rule 1: IDENTICAL_DEVICE_COLLISION Detection ──────────── */

    /**
     * Detect if the same fingerprint_hash appears under different sessions on the same IP.
     * This indicates the same physical device was used by multiple students (cheating via shared device).
     */
    private static function detectDeviceCollision(PDO $db, int $accountId, int $examId, array $ips, string $fpHash, string $excludeSessionId): bool
    {
        if (empty($ips) || $fpHash === '') return false;

        foreach ($ips as $ip) {
            // Count other sessions with same IP AND same fingerprint_hash
            $st = $db->prepare(
                "SELECT COUNT(DISTINCT sd.session_id)
                 FROM student_devices sd
                 WHERE sd.account_id = :a AND sd.exam_id = :e
                   AND sd.fingerprint_hash = :fp
                   AND sd.ip_address = :ip
                   AND sd.session_id != :sid"
            );
            $st->execute([':a' => $accountId, ':e' => $examId, ':fp' => $fpHash, ':ip' => $ip, ':sid' => $excludeSessionId]);
            $count = (int)$st->fetchColumn();
            if ($count > 0) return true;

            // Also check ip_snapshots for fingerprint_hash
            $st2 = $db->prepare(
                "SELECT COUNT(DISTINCT is2.session_id)
                 FROM ip_snapshots is2
                 WHERE is2.account_id = :a AND is2.exam_id = :e
                   AND is2.fingerprint_hash = :fp
                   AND is2.ip_address = :ip
                   AND is2.session_id != :sid"
            );
            $st2->execute([':a' => $accountId, ':e' => $examId, ':fp' => $fpHash, ':ip' => $ip, ':sid' => $excludeSessionId]);
            $count2 = (int)$st2->fetchColumn();
            if ($count2 > 0) return true;
        }

        return false;
    }

    /* ── Rule 2: SYNCHRONIZED_ANSWERING Detection ──────────────── */

    /**
     * Detect when students on the same IP provide the same answer to the same question within ≤3s.
     * This is a strong indicator of coordinated cheating.
     */
    private static function detectSyncAnswering(PDO $db, int $accountId, int $examId): array
    {
        // Get all answer submissions with timestamps
        $st = $db->prepare(
            "SELECT ss.session_id, ss.student_id, ar.question_id, ar.answer_text, ar.created_at
             FROM answer_records ar
             JOIN session_summaries ss ON ss.session_id = ar.session_id AND ss.account_id = ar.account_id
             WHERE ar.account_id = :a AND ar.exam_id = :e AND ar.answer_text != ''
             ORDER BY ar.question_id, ar.created_at"
        );
        $st->execute([':a' => $accountId, ':e' => $examId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($rows) < 10) return [];

        // Group by question_id
        $byQuestion = [];
        foreach ($rows as $r) {
            $byQuestion[$r['question_id']][] = $r;
        }

        $syncPairs = [];
        foreach ($byQuestion as $qId => $answers) {
            $n = count($answers);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($answers[$i]['session_id'] === $answers[$j]['session_id']) continue;
                    if ($answers[$i]['answer_text'] !== $answers[$j]['answer_text']) continue;
                    $diff = abs(strtotime($answers[$i]['created_at']) - strtotime($answers[$j]['created_at']));
                    if ($diff <= self::SYNC_WINDOW_SEC) {
                        $key = $answers[$i]['session_id'] . '|' . $answers[$j]['session_id'];
                        if (!isset($syncPairs[$key])) {
                            $syncPairs[$key] = [
                                'session_ids' => [$answers[$i]['session_id'] => true, $answers[$j]['session_id'] => true],
                                'count' => 0,
                                'question_id' => $qId,
                            ];
                        }
                        $syncPairs[$key]['count']++;
                    }
                }
            }
        }

        $result = [];
        foreach ($syncPairs as $c) {
            if ($c['count'] >= self::MIN_SYNC_PAIRS) {
                $result[] = [
                    'session_ids' => $c['session_ids'],
                    'count'       => $c['count'],
                    'question_id' => $c['question_id'],
                    'score'       => min(self::W_SYNCHRONIZED_ANSWERING, $c['count'] * 12),
                ];
            }
        }

        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);
        return $result;
    }

    /* ── Rule 4: SUSPICIOUS_TIMEZONE Detection ─────────────────── */

    /**
     * Detect timezone mismatch: student's reported timezone differs from expected exam timezone.
     */
    private static function detectTimezoneMismatch(PDO $db, string $sessionId): bool
    {
        // Check ip_snapshots for timezone data
        $st = $db->prepare(
            "SELECT client_timezone FROM ip_snapshots
             WHERE session_id = :s AND client_timezone != '' AND client_timezone IS NOT NULL
             LIMIT 1"
        );
        $st->execute([':s' => $sessionId]);
        $tz = $st->fetchColumn();

        if (!$tz || $tz === '') return false;

        // Compare with exam timezone (configurable, default: Asia/Baghdad for Iraq)
        $examTimezone = 'Asia/Baghdad';
        try {
            $examTz = new DateTimeZone($examTimezone);
            $studentTz = new DateTimeZone($tz);
            $examOffset = $examTz->getOffset(new DateTime('now', $examTz));
            $studentOffset = $studentTz->getOffset(new DateTime('now', $studentTz));
            // Suspicious if timezone differs by more than 3 hours
            return abs($examOffset - $studentOffset) > 3 * 3600;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ── IP Groups ────────────────────────────────────────────── */

    private static function buildIPGroups(PDO $db, int $accountId, int $examId): array
    {
        // Primary: use ip_snapshots (periodic, accurate)
        $rows = $db->prepare(
            "SELECT session_id, student_id, ip_address, detected_at
             FROM ip_snapshots
             WHERE account_id = :a AND exam_id = :e
             ORDER BY detected_at"
        );
        $rows->execute([':a' => $accountId, ':e' => $examId]);
        $snapshots = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fallback: use events.ip_address
        if (empty($snapshots)) {
            $snapshots = $db->prepare(
                "SELECT session_id, moodle_user_id AS student_id, ip_address, event_time AS detected_at
                 FROM events
                 WHERE account_id = :a AND moodle_quiz_id = :e AND ip_address != ''
                 GROUP BY session_id, moodle_user_id, ip_address
                 ORDER BY event_time"
            );
            $snapshots->execute([':a' => $accountId, ':e' => $examId]);
            $snapshots = $snapshots->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

        // Calculate risk level for each group
        foreach ($groups as &$g) {
            $g['student_count'] = count($g['students']);
            $g['student_ids'] = array_keys($g['students']);
            $g['session_count'] = count($g['sessions']);

            if ($g['student_count'] >= 5) $g['risk_level'] = 'critical';
            elseif ($g['student_count'] >= 4) $g['risk_level'] = 'high';
            elseif ($g['student_count'] >= 3) $g['risk_level'] = 'medium';
            elseif ($g['student_count'] >= 2) $g['risk_level'] = 'low';
            else $g['risk_level'] = 'safe';
        }
        unset($g);

        // Sort by student count descending
        uasort($groups, fn($a, $b) => $b['student_count'] <=> $a['student_count']);

        return array_values($groups);
    }

    /* ── Multi-Device Detection ──────────────────────────────── */

    private static function detectMultiDevice(PDO $db, int $accountId, int $examId): array
    {
        // Check student_devices table
        $rows = $db->prepare(
            "SELECT student_id, browser_fp, fingerprint_hash, ip_address, user_agent, first_seen, last_seen, snapshot_count
             FROM student_devices
             WHERE account_id = :a AND exam_id = :e
             ORDER BY student_id, first_seen"
        );
        $rows->execute([':a' => $accountId, ':e' => $examId]);
        $devices = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group by student
        $byStudent = [];
        foreach ($devices as $d) {
            $sid = (int)$d['student_id'];
            if (!isset($byStudent[$sid])) {
                $byStudent[$sid] = [];
            }
            $byStudent[$sid][] = $d;
        }

        $multiDevice = [];
        foreach ($byStudent as $studentId => $devs) {
            $fpCount = count($devs);
            if ($fpCount > 1) {
                $ips = array_unique(array_column($devs, 'ip_address'));
                $multiDevice[] = [
                    'student_id'   => $studentId,
                    'device_count' => $fpCount,
                    'ips'          => $ips,
                    'devices'      => $devs,
                    'risk_level'   => $fpCount >= 3 ? 'critical' : ($fpCount >= 2 ? 'high' : 'medium'),
                ];
            } else {
                $ips = array_unique(array_column($devs, 'ip_address'));
                if (count($ips) > 1) {
                    $multiDevice[] = [
                        'student_id'   => $studentId,
                        'device_count' => 1,
                        'ips'          => $ips,
                        'devices'      => $devs,
                        'risk_level'   => 'medium',
                        'ip_changed'   => true,
                    ];
                }
            }
        }

        return $multiDevice;
    }

    /* ── Timing Correlation Detection ─────────────────────────── */

    private static function detectTimingCorrelation(PDO $db, int $accountId, int $examId): array
    {
        // Detect SYNCHRONIZED_ANSWERING (Rule 2)
        $syncAnswering = self::detectSyncAnswering($db, $accountId, $examId);

        // Also detect traditional timing correlation (tab switches, pastes)
        $events = $db->prepare(
            "SELECT session_id, event_type, event_time
             FROM events
             WHERE account_id = :a AND moodle_quiz_id = :e
               AND event_type IN ('tab_hidden', 'paste', 'answer_changed')
             ORDER BY event_time"
        );
        $events->execute([':a' => $accountId, ':e' => $examId]);
        $rows = $events->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $timingCorrelation = $syncAnswering;

        if (count($rows) >= 10) {
            $byType = [];
            foreach ($rows as $r) {
                $byType[$r['event_type']][] = [
                    'session' => $r['session_id'],
                    'time'    => strtotime($r['event_time']),
                ];
            }

            foreach ($byType as $eventType => $evts) {
                $n = count($evts);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if ($evts[$i]['session'] === $evts[$j]['session']) continue;
                        $diff = abs($evts[$i]['time'] - $evts[$j]['time']);
                        if ($diff <= 5) {
                            $key = $evts[$i]['session'] . '|' . $evts[$j]['session'];
                            $found = false;
                            foreach ($timingCorrelation as &$tc) {
                                if (isset($tc['session_ids'][$evts[$i]['session']]) && isset($tc['session_ids'][$evts[$j]['session']])) {
                                    $tc['count']++;
                                    $found = true;
                                    break;
                                }
                            }
                            unset($tc);
                            if (!$found) {
                                $timingCorrelation[] = [
                                    'session_ids' => [$evts[$i]['session'] => true, $evts[$j]['session'] => true],
                                    'count' => 1,
                                    'event_type' => $eventType,
                                    'score' => 8,
                                ];
                            }
                        }
                    }
                }
            }
        }

        usort($timingCorrelation, fn($a, $b) => $b['score'] <=> $a['score']);
        return $timingCorrelation;
    }

    /* ── Scoring ─────────────────────────────────────────────── */

    private static function scoreSessions(PDO $db, int $accountId, int $examId, array $groups, array $multiDevice, array $timingCorrelation = []): array
    {
        // Build multi-device lookup by student_id
        $mdLookup = [];
        foreach ($multiDevice as $md) {
            $mdLookup[$md['student_id']] = $md;
        }

        // Build IP-to-count lookup
        $ipCounts = [];
        foreach ($groups as $g) {
            $ipCounts[$g['ip']] = $g['student_count'];
        }

        // Get all sessions
        $sessions = $db->prepare(
            "SELECT ss.session_id, ss.student_id, ss.ip_address
             FROM session_summaries ss
             WHERE ss.exam_id = :e AND ss.account_id = :a"
        );
        $sessions->execute([':e' => $examId, ':a' => $accountId]);
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

        // Also bulk-fetch from events for any still-empty IPs
        $stillNeedIP = [];
        foreach ($needIP as $sid) {
            if (!isset($snapIPs[$sid])) $stillNeedIP[] = $sid;
        }
        if (!empty($stillNeedIP)) {
            $placeholders2 = implode(',', array_fill(0, count($stillNeedIP), '?'));
            $evSt = $db->prepare(
                "SELECT session_id, ip_address FROM events
                 WHERE session_id IN ($placeholders2) AND ip_address != '' AND ip_address != 'unknown'
                 GROUP BY session_id ORDER BY event_time"
            );
            $evSt->execute($stillNeedIP);
            foreach ($evSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!isset($snapIPs[$row['session_id']])) {
                    $snapIPs[$row['session_id']] = $row['ip_address'];
                }
            }
        }

        $scores = [];
        foreach ($rows as $r) {
            $sid = $r['session_id'];
            $studentId = (int)$r['student_id'];
            $ip = $r['ip_address'] ?: ($snapIPs[$sid] ?? '');

            // Same IP count
            $sameIPCount = isset($ipCounts[$ip]) ? max(0, $ipCounts[$ip] - 1) : 0;

            // IP changes in this session
            $sessionIPs = self::getSessionIPs($db, $sid);
            $ipChangeCount = max(0, count($sessionIPs) - 1);

            // Multi-device check
            $isMultiDevice = isset($mdLookup[$studentId]);
            $deviceCount = $isMultiDevice ? $mdLookup[$studentId]['device_count'] : 1;

            // Get fingerprint hash for device collision detection
            $fpHash = self::getSessionFingerprintHash($db, $sid);

            // ── Smart scoring (shared IP alone = Score 0) ──
            $score = 0;
            $deviceCollision = false;
            $ipChanged = false;
            $tzSuspicious = false;

            // Rule 1: IDENTICAL_DEVICE_COLLISION (+45%)
            if ($sameIPCount >= 1 && $fpHash !== '') {
                $deviceCollision = self::detectDeviceCollision($db, $accountId, $examId, $ip ? [$ip] : [], $fpHash, $sid);
                if ($deviceCollision) {
                    $score += self::W_IDENTICAL_DEVICE_COLLISION;
                }
            }

            // Rule 3: IP_CHANGED_MID_EXAM (+30%)
            if ($ipChangeCount > 0) {
                $score += self::W_IP_CHANGED_MID_EXAM;
                $ipChanged = true;
            }

            // Rule 4: SUSPICIOUS_TIMEZONE (+20%)
            $tzSuspicious = self::detectTimezoneMismatch($db, $sid);
            if ($tzSuspicious) {
                $score += self::W_SUSPICIOUS_TIMEZONE;
            }

            // Rule 2: SYNCHRONIZED_ANSWERING (+35%)
            $timingScore = 0;
            foreach ($timingCorrelation as $tc) {
                if (isset($tc['session_ids'][$sid])) {
                    $timingScore = max($timingScore, $tc['score']);
                }
            }
            $score += $timingScore;

            $scores[$sid] = [
                'session_id'      => $sid,
                'student_id'      => $studentId,
                'same_ip_count'   => $sameIPCount,
                'ip_changed_count'=> $ipChangeCount,
                'risk_score'      => min(100, max(0, $score)),
                'network_score_N' => min(100, max(0, $score)),
                'primary_ip'      => $ip,
                'multi_device'    => $isMultiDevice,
                'device_count'    => $deviceCount,
                'timing_score'    => $timingScore,
                'device_collision' => $deviceCollision,
                'ip_changed'      => $ipChanged,
                'timezone_suspicious' => $tzSuspicious,
            ];
        }

        return $scores;
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    private static function getSessionIPs(PDO $db, string $sessionId): array
    {
        // Try ip_snapshots first
        $st = $db->prepare(
            "SELECT DISTINCT ip_address FROM ip_snapshots WHERE session_id = :s AND ip_address != '' AND ip_address != 'unknown'"
        );
        $st->execute([':s' => $sessionId]);
        $ips = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Fallback to events
        if (empty($ips)) {
            $st = $db->prepare(
                "SELECT DISTINCT ip_address FROM events WHERE session_id = :s AND ip_address != '' AND ip_address != 'unknown'"
            );
            $st->execute([':s' => $sessionId]);
            $ips = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        return $ips;
    }

    private static function getSessionFPCount(PDO $db, int $accountId, int $examId, string $sessionId): int
    {
        $summary = $db->prepare(
            "SELECT student_id FROM session_summaries WHERE session_id = :s AND account_id = :a"
        );
        $summary->execute([':s' => $sessionId, ':a' => $accountId]);
        $row = $summary->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 1;

        $st2 = $db->prepare(
            "SELECT COUNT(DISTINCT browser_fp) FROM student_devices
             WHERE account_id = :a AND exam_id = :e AND student_id = :sid"
        );
        $st2->execute([':a' => $accountId, ':e' => $examId, ':sid' => $row['student_id']]);
        return (int)$st2->fetchColumn();
    }

    /**
     * Get the dominant fingerprint_hash for a session (from ip_snapshots or student_devices).
     */
    private static function getSessionFingerprintHash(PDO $db, string $sessionId): string
    {
        // Try ip_snapshots first
        $st = $db->prepare(
            "SELECT fingerprint_hash FROM ip_snapshots
             WHERE session_id = :s AND fingerprint_hash != '' AND fingerprint_hash IS NOT NULL
             ORDER BY detected_at DESC LIMIT 1"
        );
        $st->execute([':s' => $sessionId]);
        $fp = $st->fetchColumn();
        if ($fp) return $fp;

        // Try student_devices
        $st2 = $db->prepare(
            "SELECT sd.fingerprint_hash FROM student_devices sd
             WHERE sd.session_id = :s AND sd.fingerprint_hash != '' AND sd.fingerprint_hash IS NOT NULL
             ORDER BY sd.last_seen DESC LIMIT 1"
        );
        $st2->execute([':s' => $sessionId]);
        $fp2 = $st2->fetchColumn();
        return $fp2 ?: '';
    }

    private static function countSessionsByIP(PDO $db, int $accountId, int $examId, string $ip): int
    {
        $st = $db->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM ip_snapshots
             WHERE account_id = :a AND exam_id = :e AND ip_address = :ip"
        );
        $st->execute([':a' => $accountId, ':e' => $examId, ':ip' => $ip]);
        $count = (int)$st->fetchColumn();

        if ($count === 0) {
            $st = $db->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM events
                 WHERE account_id = :a AND moodle_quiz_id = :e AND ip_address = :ip"
            );
            $st->execute([':a' => $accountId, ':e' => $examId, ':ip' => $ip]);
            $count = (int)$st->fetchColumn();
        }

        return $count;
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
                ':lvl' => $g['risk_level'],
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
                ':ip'    => $s['primary_ip'],
                ':same'  => $s['same_ip_count'],
                ':changed' => $s['ip_changed_count'],
                ':risk'  => $s['risk_score'],
                ':netN'  => $s['network_score_N'] ?? $s['risk_score'],
                ':s'     => $s['session_id'],
            ]);
        }
    }
}
