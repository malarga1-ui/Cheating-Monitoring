<?php
/**
 * AI Brain — PHP Integration Layer
 * =================================
 * Calls the Python ML Engine for behavioral analysis.
 * Falls back to local statistical scoring when Python service is unavailable.
 *
 * Usage from Aggregator / RiskEngine:
 *   $result = AIBrain::predict($sessionId, $studentId, $counters);
 *   // $result = ['ai_score' => 0-100, 'risk_level' => '...', 'recommendations' => [...]]
 */
final class AIBrain
{
    private const ML_URL = 'http://127.0.0.1:8765';
    private const TIMEOUT = 4;

    /**
     * Predict cheating probability for a single student session.
     */
    public static function predict(int $sessionId, int $studentId, array $counters, string $studentName = ''): array
    {
        $payload = [
            'session_id'   => (string)$sessionId,
            'student_id'   => $studentId,
            'student_name' => $studentName,
            'exam_id'      => (int)($counters['exam_id'] ?? 0),
            'features'     => $counters,
        ];

        $response = self::call('/predict', $payload);
        if ($response === null) {
            return self::localFallback($counters);
        }
        return $response;
    }

    /**
     * Batch predict for all students in an exam.
     */
    public static function batch(int $examId, array $students): array
    {
        $payload = [
            'exam_id'  => $examId,
            'students' => array_map(fn($s) => [
                'session_id'   => (string)($s['session_id'] ?? 0),
                'student_id'   => (int)($s['student_id'] ?? 0),
                'student_name' => $s['student_name'] ?? '',
                'exam_id'      => $examId,
                'features'     => $s['counters'] ?? $s,
            ], $students),
        ];

        $response = self::call('/batch', $payload);
        if ($response === null) {
            // Fallback: predict each locally
            $results = [];
            foreach ($students as $s) {
                $c = $s['counters'] ?? $s;
                $results[] = self::localFallback($c, $s['student_id'] ?? 0, $s['student_name'] ?? '');
            }
            usort($results, fn($a, $b) => $b['ai_score'] <=> $a['ai_score']);
            return ['exam_id' => $examId, 'results' => $results,
                    'flagged' => count(array_filter($results, fn($r) => in_array($r['risk_level'], ['high', 'critical']))),
                    'total' => count($results)];
        }
        return $response;
    }

    /**
     * Detect cheating rings / patterns across students.
     */
    public static function patterns(int $examId, array $students): array
    {
        $payload = [
            'exam_id'  => $examId,
            'students' => array_map(fn($s) => [
                'session_id'   => (string)($s['session_id'] ?? 0),
                'student_id'   => (int)($s['student_id'] ?? 0),
                'student_name' => $s['student_name'] ?? '',
                'exam_id'      => $examId,
                'features'     => $s['counters'] ?? $s,
            ], $students),
        ];

        return self::call('/patterns', $payload) ?? ['rings' => [], 'ip_groups' => [], 'timing_groups' => []];
    }

    /**
     * Generate a smart report for an exam.
     */
    public static function report(int $examId, string $examName, array $students, int $totalQuestions = 0, int $examMinutes = 0): array
    {
        $payload = [
            'exam_id'          => $examId,
            'exam_name'        => $examName,
            'total_questions'  => $totalQuestions,
            'exam_minutes'     => $examMinutes,
            'students'         => array_map(fn($s) => [
                'session_id'   => (string)($s['session_id'] ?? 0),
                'student_id'   => (int)($s['student_id'] ?? 0),
                'student_name' => $s['student_name'] ?? '',
                'exam_id'      => $examId,
                'features'     => $s['counters'] ?? $s,
            ], $students),
        ];

        return self::call('/report', $payload) ?? [];
    }

    /**
     * Retrain models with new labeled data.
     */
    public static function train(array $samples, array $labels): array
    {
        $payload = [
            'samples' => array_map(fn($s, $i) => [
                'session_id'   => (string)($s['session_id'] ?? $i),
                'student_id'   => (int)($s['student_id'] ?? $i),
                'features'     => $s['counters'] ?? $s,
            ], $samples, array_keys($samples)),
            'labels'  => $labels,
        ];

        return self::call('/train', $payload) ?? ['status' => 'error'];
    }

    /**
     * Check if ML service is available.
     */
    public static function isAvailable(): bool
    {
        $ch = curl_init(self::ML_URL . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r !== false && str_contains($r, '"ok"');
    }

    /* ── Internal ─────────────────────────────────────────────────── */

    private static function call(string $endpoint, array $payload): ?array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init(self::ML_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code !== 200) {
            error_log("AIBrain ML call failed: $endpoint code=$code err=$err");
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Local PHP fallback when Python ML service is unavailable.
     * Uses simplified threshold-based scoring.
     */
    private static function localFallback(array $c, int $studentId = 0, string $name = ''): array
    {
        $score = 0;

        // Behavioral scoring
        $score += min(25, ($c['paste_count'] ?? 0) * 5);
        $score += min(20, ($c['tab_hidden_count'] ?? 0) * 3);
        $score += min(15, ($c['devtools_count'] ?? 0) * 8);
        $score += min(10, ($c['screenshot_count'] ?? 0) * 5);
        $score += min(10, ($c['copy_count'] ?? 0) * 2);
        $score += min(10, ($c['right_click_count'] ?? 0) * 2);
        $score += min(10, ($c['suspicious_key_count'] ?? 0) * 3);

        // Network scoring
        if (($c['same_ip_student_count'] ?? 0) >= 3) $score += 15;
        if (($c['ip_changed_count'] ?? 0) >= 2) $score += 10;

        // Keystroke scoring
        if (($c['typing_answer_ratio'] ?? 0) > 0.8) $score += 5;

        $score = min(100, max(0, $score));
        $level = match(true) {
            $score >= 80 => 'critical',
            $score >= 60 => 'high',
            $score >= 40 => 'medium',
            $score >= 20 => 'low',
            default => 'safe',
        };

        $recs = [];
        if (($c['tab_hidden_count'] ?? 0) >= 5) {
            $recs[] = ['action' => 'send_message', 'priority' => 'high',
                'message' => 'تم رصد نشاط مشبوه: اخفاء متكرر للتبويب.'];
        }
        if (($c['paste_count'] ?? 0) >= 3) {
            $recs[] = ['action' => 'send_message', 'priority' => 'high',
                'message' => 'تم رصد لصق متكرر من مصادر خارجية.'];
        }
        if ($score >= 70) {
            $recs[] = ['action' => 'lock_exam', 'priority' => 'critical',
                'message' => 'درجة الخطورة عالية جداً. يُنصح بقفل الامتحان.'];
        }

        return [
            'session_id'   => '',
            'student_id'   => $studentId,
            'student_name' => $name,
            'ai_score'     => $score,
            'risk_level'   => $level,
            'cheat_probability' => $score,
            'anomaly_score'     => 0,
            'top_features'      => [],
            'recommendations'   => $recs,
            'method'            => 'local-fallback',
        ];
    }
}
