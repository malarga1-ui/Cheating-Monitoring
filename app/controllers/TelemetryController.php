<?php
/**
 * POST /telemetry - the single, ultra-light write endpoint used by the
 * Moodle ExamMonitor plugin. Never drops legitimate events.
 */
final class TelemetryController
{
    public static function options(): void
    {
        self::corsHeaders();
        Response::empty(204);
    }

    public static function ingest(): void
    {
        self::corsHeaders();

        // Per-account kill switch: events are only accepted for a valid,
        // non-expired account (identified by its api_secret, `?k=`).
        $secret = (string)($_GET['k'] ?? '');
        $account = Accounts::resolveBySecret($secret);
        if ($account === null) {
            Response::empty(403);
        }

        $body = em_body_json();
        if ($body === null) {
            Response::empty(400);
        }

        // Handle batch format: { events: [...] } or single event
        $events = $body;
        if (isset($body['events']) && is_array($body['events'])) {
            $events = $body['events'];
        }

        // Domain binding: reject telemetry from any site other than the one
        // this account is bound to (plugin sends site_url in each payload).
        $siteUrl = self::extractSiteUrl($events);
        if ($siteUrl !== '' && !Accounts::siteAllowed((int)$account['id'], $siteUrl)) {
            Response::empty(403);
        }

        // Guard against malformed/oversized bodies.
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $maxBody = (int)em_config('telemetry.max_body_bytes', 524288);
        if ($contentLength > $maxBody) {
            Response::empty(413);
        }

        // Optional hardening (off by default to guarantee zero data loss).
        if (em_config('telemetry.throttle.enabled', false)) {
            if (!self::throttlePass(em_client_ip())) {
                Response::empty(429);
            }
        }

        try {
            $result = Ingest::ingestPayload($events, (int)$account['id']);
            // Immediately run incremental aggregation for 100% real-time risk scores & summaries
            try { Aggregator::process(200); } catch (\Throwable $e) {}
        } catch (Throwable $e) {
            error_log('[ExamMonitor] Telemetry ingest failed: ' . $e->getMessage());
            Response::empty(500);
        }

        header('X-Event-Accepted: ' . $result['accepted']);
        header('X-Event-Skipped: ' . $result['skipped']);
        Response::empty(204);
    }

    public static function health(): void
    {
        self::corsHeaders();
        try {
            $dbOk = true;
            $totalEvents = (int)Database::scalar('SELECT COUNT(*) FROM events');
            $lastEventAt = Database::scalar('SELECT MAX(received_at) FROM events');
            $dbError = null;
        } catch (\Throwable $e) {
            $dbOk = false;
            $totalEvents = 0;
            $lastEventAt = null;
            $dbError = $e->getMessage();
        }
        Response::ok([
            'status' => $dbOk ? 'ok' : 'degraded',
            'database' => $dbOk ? 'connected' : 'error',
            'db_error' => $dbError,
            'total_events' => $totalEvents,
            'last_event_at' => $lastEventAt,
            'php_version' => PHP_VERSION,
        ]);
    }

    /** Pull the Moodle site URL out of a single event or a batch payload. */
    private static function extractSiteUrl($body): string
    {
        if (!is_array($body)) {
            return '';
        }
        $first = array_is_list($body) ? ($body[0] ?? null) : $body;
        if (!is_array($first)) {
            return '';
        }
        if (is_string($first['site_url'] ?? null) && $first['site_url'] !== '') {
            return $first['site_url'];
        }
        $moodle = $first['moodle'] ?? null;
        if (is_array($moodle) && is_string($moodle['site_url'] ?? null) && $moodle['site_url'] !== '') {
            return $moodle['site_url'];
        }
        return '';
    }

    private static function corsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = [
            'https://moodle.luckydraw.world',
            'https://jadallahkhaled.com',
            'http://moodle.luckydraw.world',
            'http://jadallahkhaled.com',
        ];
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
        header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
    }

    private static function throttlePass(string $ip): bool
    {
        $window = (int)em_config('telemetry.throttle.window_seconds', 60);
        $max = (int)em_config('telemetry.throttle.max_per_minute', 600);
        $bucket = intdiv(time(), $window);

        Database::execute(
            'INSERT INTO telemetry_throttle (ip_address, window_start, hit_count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1',
            [$ip, $bucket]
        );
        $hits = (int)Database::scalar(
            'SELECT hit_count FROM telemetry_throttle WHERE ip_address = ? AND window_start = ?',
            [$ip, $bucket]
        );
        return $hits <= $max;
    }
}
