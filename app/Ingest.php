<?php
/**
 * Telemetry ingest - WRITE PATH ONLY.
 *
 * Guarantees:
 *  - Zero data loss: every accepted event is committed durably to `events`
 *    BEFORE the HTTP response returns.
 *  - Zero analysis work: no upserts, no risk scoring, no aggregation.
 *    Analysis happens later in the background Aggregator.
 *  - High throughput: multi-row INSERT IGNORE for batch payloads, dedup by
 *    event_id, one DB connection.
 *
 * Accepts a single event object OR an array of events.
 */
final class Ingest
{
    /** Per-request hard limits (mirrored from config). */
    private const MAX_BATCH = 500;

    /**
     * @param int $accountId owning account (resolved from the api_secret)
     * @return array{accepted:int, skipped:int}
     */
    public static function ingestPayload($payload, int $accountId = 0): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            if (count($payload) > self::MAX_BATCH) {
                $payload = array_slice($payload, 0, self::MAX_BATCH);
            }
            return self::ingestRows($payload, $accountId);
        }
        return self::ingestRows([$payload], $accountId);
    }

    /** @return array{accepted:int, skipped:int} */
    private static function ingestRows(array $events, int $accountId = 0): array
    {
        $rows = [];
        $accepted = 0;
        $skipped = 0;

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                $skipped++;
                continue;
            }
            $row = self::normalize($ev);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $row['account_id'] = $accountId;
            $rows[] = $row;
            $accepted++;
        }

        if ($rows !== []) {
            self::insertRows($rows);
        }

        // v28: Persist device telemetry from heartbeat events
        self::persistDeviceTelemetry($events, $accountId);

        return ['accepted' => $accepted, 'skipped' => $skipped];
    }

    /**
     * v28: Extract device_telemetry from heartbeat/page_leave events and persist to student_telemetry table.
     * Runs as best-effort (errors are logged but don't block ingest).
     */
    private static function persistDeviceTelemetry(array $events, int $accountId): void
    {
        try {
            $db = Database::connection();
            $st = $db->prepare(
                "INSERT IGNORE INTO student_telemetry
                 (account_id, exam_id, session_id, student_id, client_ip, fingerprint_hash,
                  screen_resolution, client_timezone, device_memory_gb, cpu_cores,
                  user_agent, language, platform, created_at)
                 VALUES (:a, :e, :s, :sid, :ip, :fp, :sr, :tz, :mem, :cpu, :ua, :lang, :plat, NOW())"
            );

            foreach ($events as $ev) {
                $metadata = $ev['metadata'] ?? [];
                $telemetry = $metadata['device_telemetry'] ?? null;
                if (!is_array($telemetry)) {
                    $telemetry = [
                        'fingerprint_hash' => $metadata['fingerprint_hash'] ?? ($ev['browser']['user_agent'] ?? ''),
                        'screen_resolution' => $metadata['screen_resolution'] ?? '',
                        'client_timezone' => $metadata['client_timezone'] ?? '',
                        'device_memory_gb' => $metadata['device_memory_gb'] ?? null,
                        'cpu_cores' => $metadata['cpu_cores'] ?? null,
                        'language' => $ev['browser']['language'] ?? '',
                        'platform' => $ev['browser']['platform'] ?? '',
                    ];
                }

                $moodle = $ev['moodle'] ?? [];
                $student = $moodle['student'] ?? [];
                $quiz = $moodle['quiz'] ?? [];
                $sessionId = $ev['session_id'] ?? '';
                $studentId = isset($student['id']) ? (int)$student['id'] : 0;
                $examId = isset($quiz['id']) ? (int)$quiz['id'] : 0;

                if ($sessionId === '' || $studentId <= 0 || $examId <= 0) continue;

                $fpHash = $telemetry['fingerprint_hash'] ?? '';
                // Skip if we already have a record with this fingerprint for this session
                if ($fpHash !== '') {
                    $chkSt = $db->prepare("SELECT id FROM student_telemetry WHERE session_id = :s AND fingerprint_hash = :fp LIMIT 1");
                    $chkSt->execute([':s' => $sessionId, ':fp' => $fpHash]);
                    if ($chkSt->fetch()) continue;
                }

                $st->execute([
                    ':a'   => $accountId,
                    ':e'   => $examId,
                    ':s'   => $sessionId,
                    ':sid' => $studentId,
                    ':ip'  => em_client_ip(),
                    ':fp'  => $fpHash,
                    ':sr'  => $telemetry['screen_resolution'] ?? '',
                    ':tz'  => $telemetry['client_timezone'] ?? '',
                    ':mem' => isset($telemetry['device_memory_gb']) ? (float)$telemetry['device_memory_gb'] : null,
                    ':cpu' => isset($telemetry['cpu_cores']) ? (int)$telemetry['cpu_cores'] : null,
                    ':ua'  => em_truncate($_SERVER['HTTP_USER_AGENT'] ?? null, 512),
                    ':lang' => $telemetry['language'] ?? '',
                    ':plat' => $telemetry['platform'] ?? '',
                ]);

                // Also update ip_snapshots with fingerprint_hash if missing
                $updateSnap = $db->prepare(
                    "UPDATE ip_snapshots SET fingerprint_hash = COALESCE(fingerprint_hash, :fp), client_timezone = COALESCE(client_timezone, :tz)
                     WHERE session_id = :s AND (fingerprint_hash IS NULL OR fingerprint_hash = '')"
                );
                $updateSnap->execute([':fp' => $fpHash, ':tz' => $telemetry['client_timezone'] ?? '', ':s' => $sessionId]);

                break; // Only need one telemetry record per request
            }
        } catch (\Throwable $e) {
            error_log("Ingest::persistDeviceTelemetry error: " . $e->getMessage());
        }
    }

    private static function insertRows(array $rows): void
    {
        $pdo = Database::connection();

        $columns = [
            'account_id', 'event_id', 'schema_version', 'session_id', 'sequence_number', 'event_type',
            'moodle_user_id', 'moodle_quiz_id', 'moodle_course_id', 'moodle_cmid', 'attempt_id',
            'event_time', 'received_at', 'elapsed_ms', 'duration_ms', 'ip_address', 'user_agent', 'url', 'payload',
        ];
        $valueCols = [
            ':account', ':event', ':schema', ':session', ':seq', ':type',
            ':user', ':quiz', ':course', ':cmid', ':attempt',
            ':etime', 'NOW(3)', ':elapsed', ':dur', ':ip', ':ua', ':url', ':payload',
        ];

        $chunks = array_chunk($rows, 200); // multi-row inserts of 200 each for better performance
        foreach ($chunks as $chunk) {
            $valueRows = [];
            $params = [];

            foreach ($chunk as $i => $row) {
                $prefix = 'r' . $i . '_';
                $cells = [];
                foreach ($valueCols as $vc) {
                    $cells[] = ($vc === 'NOW(3)') ? 'NOW(3)' : ':' . $prefix . ltrim($vc, ':');
                }
                $valueRows[] = '(' . implode(', ', $cells) . ')';

                $params[':' . $prefix . 'account']  = $row['account_id'];
                $params[':' . $prefix . 'event']     = $row['event_id'];
                $params[':' . $prefix . 'schema']    = $row['schema_version'];
                $params[':' . $prefix . 'session']   = $row['session_id'];
                $params[':' . $prefix . 'seq']       = $row['sequence_number'];
                $params[':' . $prefix . 'type']      = $row['event_type'];
                $params[':' . $prefix . 'user']      = $row['moodle_user_id'];
                $params[':' . $prefix . 'quiz']      = $row['moodle_quiz_id'];
                $params[':' . $prefix . 'course']    = $row['moodle_course_id'];
                $params[':' . $prefix . 'cmid']      = $row['moodle_cmid'];
                $params[':' . $prefix . 'attempt']   = $row['attempt_id'];
                $params[':' . $prefix . 'etime']     = $row['event_time'];
                $params[':' . $prefix . 'elapsed']   = $row['elapsed_ms'];
                $params[':' . $prefix . 'dur']       = $row['duration_ms'];
                $params[':' . $prefix . 'ip']        = $row['ip_address'];
                $params[':' . $prefix . 'ua']        = $row['user_agent'];
                $params[':' . $prefix . 'url']       = $row['url'];
                $params[':' . $prefix . 'payload']   = $row['payload_json'];
            }

            $sql = 'INSERT IGNORE INTO events (' . implode(', ', $columns) . ') VALUES '
                 . implode(', ', $valueRows);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }

    // ---------------------------------------------------------------

    private static function normalize(array $ev): ?array
    {
        $eventType = $ev['event_type'] ?? null;
        $sessionId = $ev['session_id'] ?? null;
        if (!is_string($eventType) || $eventType === '' || !is_string($sessionId) || $sessionId === '') {
            return null;
        }
        $eventType = substr($eventType, 0, 50);
        $sessionId = substr($sessionId, 0, 64);

        $moodle = (is_array($ev['moodle'] ?? null)) ? $ev['moodle'] : [];
        $student = (is_array($moodle['student'] ?? null)) ? $moodle['student'] : [];
        $quiz = (is_array($moodle['quiz'] ?? null)) ? $moodle['quiz'] : [];

        $moodleUserId = isset($student['id']) ? (int)$student['id'] : null;
        $moodleQuizId = isset($quiz['id']) ? (int)$quiz['id'] : null;
        if ($moodleUserId === null || $moodleQuizId === null) {
            return null;
        }

        $eventId = $ev['event_id'] ?? null;
        if (!is_string($eventId) || $eventId === '') {
            $eventId = 'em_' . md5($sessionId . '|' . ($ev['sequence_number'] ?? 0) . '|' . ($ev['timestamp'] ?? microtime(true)));
        }

        $metadata = (is_array($ev['metadata'] ?? null)) ? $ev['metadata'] : [];
        $browser = (is_array($ev['browser'] ?? null)) ? $ev['browser'] : [];

        return [
            'event_id'         => substr($eventId, 0, 64),
            'schema_version'   => substr((string)($ev['schema_version'] ?? '1.0'), 0, 10),
            'session_id'       => $sessionId,
            'sequence_number'  => max(0, (int)($ev['sequence_number'] ?? 0)),
            'event_type'       => $eventType,
            'moodle_user_id'   => $moodleUserId,
            'moodle_quiz_id'   => $moodleQuizId,
            'moodle_course_id' => isset($quiz['course_id']) ? (int)$quiz['course_id'] : 0,
            'moodle_cmid'      => isset($quiz['cmid']) ? (int)$quiz['cmid'] : 0,
            'attempt_id'       => isset($quiz['attempt_id']) ? (int)$quiz['attempt_id'] : null,
            'event_time'       => em_iso_to_mysql($ev['timestamp'] ?? null, gmdate('Y-m-d H:i:s.v')),
            'elapsed_ms'       => isset($ev['elapsed_ms']) ? max(0, (int)$ev['elapsed_ms']) : null,
            'duration_ms'      => self::extractDuration($eventType, $metadata),
            'ip_address'       => em_client_ip(),
            'user_agent'       => em_truncate($_SERVER['HTTP_USER_AGENT'] ?? null, 512),
            'url'              => em_truncate($browser['url'] ?? null, 2048),
            'payload_json'     => em_json_encode($ev),
        ];
    }

    private static function extractDuration(string $eventType, array $metadata): ?int
    {
        // Only tab_hidden_duration carries the authoritative duration.
        // tab_visible also embeds hidden_duration_ms but it is a duplicate of
        // the dedicated event, so it must not double-count the duration.
        if ($eventType !== 'tab_hidden_duration') {
            return null;
        }
        foreach (['duration_ms', 'durationMs', 'hidden_duration_ms', 'hiddenDurationMs', 'duration_seconds', 'durationSeconds', 'duration'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $val = (float)$metadata[$key];
                if ($val < 0) {
                    return null;
                }
                $isSeconds = str_contains($key, 'seconds');
                return $isSeconds ? (int)round($val * 1000) : (int)round($val);
            }
        }
        return null;
    }
}
