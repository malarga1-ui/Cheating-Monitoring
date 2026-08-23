<?php
/**
 * System / settings endpoints (tenant-scoped where applicable).
 */
final class SettingsController
{
    public static function status(): void
    {
        Auth::requireLogin();
        $scopeEv = Auth::accountFilterSql('ev');
        $scopeSs = Auth::accountFilterSql('ss');
        $scopeE  = Auth::accountFilterSql('e');
        $scopeSt = Auth::accountFilterSql('s');

        $watermark = (int)Database::scalar('SELECT last_event_id FROM agg_watermark WHERE id = 1');
        $maxEvent = (int)Database::scalar('SELECT COALESCE(MAX(id), 0) FROM events' . ($scopeEv ? ' WHERE ' . $scopeEv : ''));

        $totalEvents = (int)Database::scalar('SELECT COUNT(*) FROM events ev' . ($scopeEv ? ' WHERE ' . $scopeEv : ''));
        $totalSessions = (int)Database::scalar('SELECT COUNT(DISTINCT session_id) FROM events ev' . ($scopeEv ? ' WHERE ' . $scopeEv : ''));
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

        Response::ok([
            'app' => [
                'name' => em_config('app.name'),
                'env' => em_config('app.env'),
                'php' => PHP_VERSION,
                'time' => gmdate('Y-m-d H:i:s') . ' UTC',
            ],
            'telemetry' => [
                'endpoint' => em_config('app.base_url') . '/telemetry',
                'last_event_at' => Database::scalar('SELECT MAX(received_at) FROM events'),
                'total_events' => $totalEvents,
                'events_last_hour' => (int)Database::scalar(
                    'SELECT COUNT(*) FROM events ev WHERE ev.event_time >= UTC_TIMESTAMP() - INTERVAL 1 HOUR' . ($scopeEv ? ' AND ' . $scopeEv : '')
                ),
            ],
            'aggregator' => [
                'last_run_at' => Database::scalar('SELECT updated_at FROM agg_watermark WHERE id = 1'),
                'processed_events' => $watermark,
                'pending_events' => max(0, $maxEvent - $watermark),
            ],
            'database' => [
                'events_table_rows' => $totalEvents,
                'summary_rows' => (int)Database::scalar('SELECT COUNT(*) FROM session_summaries' . ($scopeSs ? ' WHERE ' . $scopeSs : '')),
                'exams' => $examsTotal,
                'students' => $totalStudents,
            ],
        ]);
    }

    public static function riskModel(): void
    {
        Auth::requireLogin();
        Response::ok([
            'levels' => RiskEngine::LEVELS,
            'indicators' => RiskEngine::indicators(),
        ]);
    }

    // ---------------------------------------------------------------
    // Configurable cheating formula ("معادلة الغش") — global (owner manages)
    // ---------------------------------------------------------------

    /** GET /api/settings/risk-indicators — list the formula indicators. */
    public static function riskIndicators(): void
    {
        Auth::requireLogin();

        $rows = Database::fetchAll(
            'SELECT id, indicator_key, label_ar, weight_percent, enabled, description, sort_order, category
             FROM risk_indicators ORDER BY sort_order ASC, id ASC'
        );

        $total = 0.0;
        foreach ($rows as $r) {
            if ((int)$r['enabled'] === 1) {
                $total += (float)$r['weight_percent'];
            }
        }

        Response::ok([
            'indicators'           => $rows,
            'total_enabled_weight' => round($total, 2),
            'levels'               => RiskEngine::LEVELS,
        ]);
    }

    /** POST /api/settings/risk-indicators — add a new indicator (owner only). */
    public static function createRiskIndicator(): void
    {
        Auth::requireAdmin();
        Auth::guardStateChangingRequest();

        $in  = self::jsonBody();
        $key = trim((string)($in['key'] ?? ''));
        $label = trim((string)($in['label'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        $weight = self::weight($in['weight'] ?? null);

        if ($key === '' || $label === '') {
            Response::error('المفتاح والاسم مطلوبان', 422);
        }
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $key)) {
            Response::error('المفتاح يجب أن يكون أحرفاً إنجليزية صغيرة وشرطة سفلية فقط', 422);
        }
        if (Database::fetchOne('SELECT id FROM risk_indicators WHERE indicator_key = ?', [$key])) {
            Response::error('هذا المحدد موجود مسبقاً', 422);
        }

        $maxSort = (int)Database::scalar('SELECT COALESCE(MAX(sort_order), 0) FROM risk_indicators');
        Database::execute(
            'INSERT INTO risk_indicators (indicator_key, label_ar, weight_percent, enabled, description, sort_order)
             VALUES (?, ?, ?, 1, ?, ?)',
            [$key, $label, $weight, $description, $maxSort + 1]
        );
        RiskEngine::flushCache();

        Response::ok(['message' => 'تمت إضافة المحدد']);
    }

    /** POST /api/settings/risk-indicators/{id} — edit an indicator (owner only). */
    public static function updateRiskIndicator(int $id): void
    {
        Auth::requireAdmin();
        Auth::guardStateChangingRequest();

        $row = Database::fetchOne('SELECT * FROM risk_indicators WHERE id = ?', [$id]);
        if (!$row) {
            Response::error('المحدد غير موجود', 404);
        }

        $in = self::jsonBody();
        $label = trim((string)($in['label'] ?? $row['label_ar']));
        $description = trim((string)($in['description'] ?? $row['description']));
        $weight = self::weight($in['weight'] ?? $row['weight_percent']);
        $enabled = isset($in['enabled']) ? ((bool)$in['enabled'] ? 1 : 0) : (int)$row['enabled'];

        if ($label === '') {
            Response::error('الاسم مطلوب', 422);
        }

        Database::execute(
            'UPDATE risk_indicators SET label_ar = ?, weight_percent = ?, enabled = ?, description = ? WHERE id = ?',
            [$label, $weight, $enabled, $description, $id]
        );
        RiskEngine::flushCache();

        Response::ok(['message' => 'تم تحديث المحدد']);
    }

    /** POST /api/settings/risk-indicators/{id}/delete — remove an indicator (owner only). */
    public static function deleteRiskIndicator(int $id): void
    {
        Auth::requireAdmin();
        Auth::guardStateChangingRequest();

        Database::execute('DELETE FROM risk_indicators WHERE id = ?', [$id]);
        RiskEngine::flushCache();

        Response::ok(['message' => 'تم حذف المحدد']);
    }

    /**
     * POST /api/settings/risk-indicators/recompute — re-score every stored
     * session with the current formula (owner only).
     */
    public static function recomputeRisk(): void
    {
        Auth::requireAdmin();
        Auth::guardStateChangingRequest();

        $updated = 0;
        $offset = 0;
        $cols = implode(', ', RiskEngine::COUNTER_KEYS);

        while (true) {
            $rows = Database::fetchAll(
                "SELECT id, session_id, $cols FROM session_summaries ORDER BY id ASC LIMIT 500 OFFSET ?",
                [$offset]
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $counters = [];
                foreach (RiskEngine::COUNTER_KEYS as $k) {
                    $counters[$k] = (int)($r[$k] ?? 0);
                }
                $risk = RiskEngine::score($counters);
                Database::execute(
                    'UPDATE session_summaries SET risk_score = ?, risk_level = ? WHERE id = ?',
                    [$risk['score'], $risk['level'], (int)$r['id']]
                );
                Database::execute(
                    'UPDATE sessions SET risk_score = ?, risk_level = ? WHERE session_id = ?',
                    [$risk['score'], $risk['level'], $r['session_id']]
                );
                $updated++;
            }
            $offset += 500;
            if (count($rows) < 500) {
                break;
            }
        }

        Response::ok(['message' => 'تمت إعادة حساب درجات الغش', 'updated' => $updated]);
    }

    private static function jsonBody(): array
    {
        return json_decode((string)file_get_contents('php://input'), true) ?: [];
    }

    /** Validate + normalize a weight between 0 and 100. */
    private static function weight($value): float
    {
        $w = (float)$value;
        if ($w < 0 || $w > 100) {
            Response::error('النسبة يجب أن تكون بين 0 و 100', 422);
        }
        return round($w, 2);
    }
}