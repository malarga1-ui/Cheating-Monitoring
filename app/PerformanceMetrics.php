<?php
/**
 * Performance metrics engine — Chapter 3.4 evaluation.
 *
 * Computes classification metrics:
 *   - Accuracy
 *   - Precision
 *   - Recall (Sensitivity)
 *   - False Positive Rate (FPR) — target < 5%
 *   - F1-Score
 *   - Response Time
 *
 * Uses instructor-confirmed labels from session_verdicts table
 * to calculate ground truth.
 */
final class PerformanceMetrics
{
    /**
     * Risk score thresholds for classification.
     * A student is "predicted positive" (flagged) if risk_score >= threshold.
     */
    private const FLAG_THRESHOLD = 40;

    /**
     * Compute all performance metrics for a given exam.
     *
     * @return array{accuracy:float, precision:float, recall:float, fpr:float, f1:float,
     *               tp:int, fp:int, tn:int, fn:int, total:int, response_time_ms:int}
     */
    public static function computeForExam(int $examId): array
    {
        // Get all session summaries with risk scores for this exam
        $sessions = Database::fetchAll(
            'SELECT ss.id, ss.student_id, ss.risk_score, ss.first_event_at, ss.last_event_at,
                    sv.verdict
             FROM session_summaries ss
             LEFT JOIN session_verdicts sv ON sv.session_summary_id = ss.id
             WHERE ss.exam_id = ?',
            [$examId]
        );

        if (empty($sessions)) {
            return self::emptyResult();
        }

        $tp = $fp = $tn = $fn = 0;
        $totalResponseMs = 0;
        $responseCount = 0;

        foreach ($sessions as $s) {
            $riskScore = (int)($s['risk_score'] ?? 0);
            $predicted = $riskScore >= self::FLAG_THRESHOLD;

            // Ground truth from instructor verdict
            $verdict = $s['verdict'] ?? null;
            $actual = null;
            if ($verdict === 'cheating') {
                $actual = true;
            } elseif ($verdict === 'clean') {
                $actual = false;
            }

            // Skip sessions without ground truth labels
            if ($actual === null) {
                continue;
            }

            if ($predicted && $actual) {
                $tp++;
            } elseif ($predicted && !$actual) {
                $fp++;
            } elseif (!$predicted && $actual) {
                $fn++;
            } else {
                $tn++;
            }

            // Response time: time from first event to when risk score was computed
            if ($s['first_event_at'] && $s['last_event_at']) {
                $first = strtotime($s['first_event_at']);
                $last = strtotime($s['last_event_at']);
                if ($first && $last && $last > $first) {
                    $totalResponseMs += ($last - $first) * 1000;
                    $responseCount++;
                }
            }
        }

        $total = $tp + $fp + $tn + $fn;

        $accuracy = $total > 0 ? ($tp + $tn) / $total : 0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
        $fpr = ($fp + $tn) > 0 ? $fp / ($fp + $tn) : 0;
        $f1 = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0;
        $avgResponseMs = $responseCount > 0 ? (int)($totalResponseMs / $responseCount) : 0;

        $result = [
            'accuracy'         => round($accuracy, 4),
            'precision'        => round($precision, 4),
            'recall'           => round($recall, 4),
            'fpr'              => round($fpr, 4),
            'f1_score'         => round($f1, 4),
            'tp'               => $tp,
            'fp'               => $fp,
            'tn'               => $tn,
            'fn'               => $fn,
            'total'            => $total,
            'avg_response_time_ms' => $avgResponseMs,
            'fpr_below_target' => $fpr < 0.05,
        ];

        // Persist to performance_metrics table
        self::persist($examId, $result);

        return $result;
    }

    /**
     * Get cached or compute metrics for an exam, optionally scoped by account.
     */
    public static function getForExam(int $examId, int $accountId = 0): array
    {
        // Try cache first
        $cached = Database::fetchOne(
            'SELECT * FROM performance_metrics WHERE exam_id = ?',
            [$examId]
        );

        if ($cached !== null) {
            return [
                'accuracy'         => (float)$cached['accuracy'],
                'precision'        => (float)$cached['precision_val'],
                'recall'           => (float)$cached['recall'],
                'fpr'              => (float)$cached['false_positive_rate'],
                'f1_score'         => (float)$cached['f1_score'],
                'tp'               => (int)$cached['true_positives'],
                'fp'               => (int)$cached['false_positives'],
                'tn'               => (int)$cached['true_negatives'],
                'fn'               => (int)$cached['false_negatives'],
                'total'            => (int)$cached['total_sessions'],
                'avg_response_time_ms' => (int)$cached['avg_response_time_ms'],
                'fpr_below_target' => (float)$cached['false_positive_rate'] < 0.05,
                'computed_at'      => $cached['computed_at'],
            ];
        }

        return self::computeForExam($examId);
    }

    /**
     * Compute metrics for all exams (dashboard overview), scoped by account.
     */
    public static function allExams(int $accountId = 0): array
    {
        $sql = 'SELECT id, name FROM exams';
        $params = [];
        if ($accountId > 0) {
            $sql .= ' WHERE account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY id DESC';
        $exams = Database::fetchAll($sql, $params);
        $results = [];
        foreach ($exams as $exam) {
            $metrics = self::getForExam((int)$exam['id'], $accountId);
            $results[] = [
                'exam_id' => (int)$exam['id'],
                'exam_name' => $exam['name'],
                'metrics' => $metrics,
            ];
        }
        return $results;
    }

    /**
     * Compute overall system-wide metrics for an account.
     */
    public static function systemWide(int $accountId = 0): array
    {
        $sql = 'SELECT ss.id, ss.risk_score, sv.verdict
                 FROM session_summaries ss
                 LEFT JOIN session_verdicts sv ON sv.session_summary_id = ss.id';
        $params = [];
        if ($accountId > 0) {
            $sql .= ' WHERE ss.account_id = ?';
            $params[] = $accountId;
        }
        $sessions = Database::fetchAll($sql, $params);

        $tp = $fp = $tn = $fn = 0;
        foreach ($sessions as $s) {
            $riskScore = (int)($s['risk_score'] ?? 0);
            $predicted = $riskScore >= self::FLAG_THRESHOLD;
            $verdict = $s['verdict'] ?? null;

            if ($verdict === null) continue;

            $actual = $verdict === 'cheating';

            if ($predicted && $actual) $tp++;
            elseif ($predicted && !$actual) $fp++;
            elseif (!$predicted && $actual) $fn++;
            else $tn++;
        }

        $total = $tp + $fp + $tn + $fn;
        $accuracy = $total > 0 ? ($tp + $tn) / $total : 0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
        $fpr = ($fp + $tn) > 0 ? $fp / ($fp + $tn) : 0;
        $f1 = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0;

        return [
            'accuracy'         => round($accuracy, 4),
            'precision'        => round($precision, 4),
            'recall'           => round($recall, 4),
            'fpr'              => round($fpr, 4),
            'f1_score'         => round($f1, 4),
            'tp'               => $tp,
            'fp'               => $fp,
            'tn'               => $tn,
            'fn'               => $fn,
            'total'            => $total,
            'fpr_below_target' => $fpr < 0.05,
            'labeled_sessions' => $total,
            'unlabeled_sessions' => count($sessions) - $total,
        ];
    }

    /**
     * Set instructor verdict for a session (ground truth label).
     */
    public static function setVerdict(int $sessionId, string $verdict, int $userId = 0): bool
    {
        if (!in_array($verdict, ['cheating', 'clean'], true)) {
            return false;
        }

        Database::execute(
            'INSERT INTO session_verdicts (session_summary_id, verdict, labeled_by, labeled_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), labeled_by = VALUES(labeled_by), labeled_at = NOW()',
            [$sessionId, $verdict, $userId]
        );

        // Invalidate cached metrics for this exam
        $session = Database::fetchOne('SELECT exam_id FROM session_summaries WHERE id = ?', [$sessionId]);
        if ($session) {
            Database::execute('DELETE FROM performance_metrics WHERE exam_id = ?', [(int)$session['exam_id']]);
        }

        return true;
    }

    private static function persist(int $examId, array $metrics): void
    {
        try {
            Database::execute(
                'INSERT INTO performance_metrics
                    (exam_id, total_sessions, true_positives, false_positives, true_negatives, false_negatives,
                     accuracy, precision_val, recall, f1_score, false_positive_rate, avg_response_time_ms, computed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    total_sessions = VALUES(total_sessions),
                    true_positives = VALUES(true_positives),
                    false_positives = VALUES(false_positives),
                    true_negatives = VALUES(true_negatives),
                    false_negatives = VALUES(false_negatives),
                    accuracy = VALUES(accuracy),
                    precision_val = VALUES(precision_val),
                    recall = VALUES(recall),
                    f1_score = VALUES(f1_score),
                    false_positive_rate = VALUES(false_positive_rate),
                    avg_response_time_ms = VALUES(avg_response_time_ms),
                    computed_at = NOW()',
                [
                    $examId, $metrics['total'], $metrics['tp'], $metrics['fp'], $metrics['tn'], $metrics['fn'],
                    $metrics['accuracy'], $metrics['precision'], $metrics['recall'],
                    $metrics['f1_score'], $metrics['fpr'], $metrics['avg_response_time_ms'],
                ]
            );
        } catch (\Throwable $e) {
            error_log("PerformanceMetrics::persist error: " . $e->getMessage());
        }
    }

    private static function emptyResult(): array
    {
        return [
            'accuracy'         => 0, 'precision' => 0, 'recall' => 0,
            'fpr' => 0, 'f1_score' => 0,
            'tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0, 'total' => 0,
            'avg_response_time_ms' => 0, 'fpr_below_target' => true,
        ];
    }
}
