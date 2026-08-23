<?php
/**
 * Response engine — Closed-loop SOAR response layer (Section 3.2.4).
 *
 * Maps risk scores to automated actions:
 *   0–40   → Normal monitoring (no action)
 *  40–70   → Flag + increase monitoring
 *  70–90   → Instructor alert
 *  >90     → Strong action (warning + session lock)
 *
 * This closes the "Response Void Gap" identified in Chapter 2:
 *   "What's missing is a closed-loop setup — one that goes beyond just
 *    detection and actually responds in real time."
 */
final class ResponseEngine
{
    /**
     * Threshold definitions from the thesis (Section 3.2.4).
     * Each threshold: [min_score, action_type, label_ar, requires_ack]
     */
    private const THRESHOLDS = [
        ['min' => 0,   'action' => 'none',            'label_ar' => 'مراقبة عادية',           'severity' => 'info',    'requires_ack' => false],
        ['min' => 40,  'action' => 'flag_increased',  'label_ar' => ' علم + مراقبة مكثفة',     'severity' => 'warning', 'requires_ack' => false],
        ['min' => 70,  'action' => 'alert_instructor', 'label_ar' => 'تنبيه المدرّس',          'severity' => 'high',    'requires_ack' => true],
        ['min' => 90,  'action' => 'lock_session',    'label_ar' => '封锁 الجلسة + تحذير',      'severity' => 'critical', 'requires_ack' => true],
    ];

    /**
     * Evaluate a risk score and return the appropriate response action.
     *
     * @param int    $riskScore   Final risk score (0–100).
     * @param int    $sessionId   Session summary ID.
     * @param int    $studentId   Student ID.
     * @param int    $examId      Exam ID.
     * @param array  $categories  Category breakdown from RiskEngine::score().
     * @param string $level       Risk level string from RiskEngine::score().
     * @return array{action:string, label_ar:string, severity:string, requires_ack:bool, details:array}
     */
    public static function evaluate(
        int $riskScore,
        int $sessionId,
        int $studentId,
        int $examId,
        array $categories = [],
        string $level = ''
    ): array {
        $action = self::none();
        foreach (array_reverse(self::THRESHOLDS) as $t) {
            if ($riskScore >= $t['min']) {
                $action = $t;
                break;
            }
        }

        // Build details payload
        $details = [
            'risk_score'   => $riskScore,
            'risk_level'   => $level,
            'categories'   => $categories,
            'evaluated_at' => date('Y-m-d H:i:s'),
        ];

        // Enrich details based on action type
        if ($action['action'] === 'alert_instructor' || $action['action'] === 'lock_session') {
            // Find the top contributing categories
            $topCategories = [];
            if (!empty($categories)) {
                arsort($categories);
                foreach (array_slice($categories, 0, 3, true) as $cat => $score) {
                    $topCategories[] = ['category' => $cat, 'score' => $score];
                }
            }
            $details['top_contributors'] = $topCategories;
        }

        return [
            'action'        => $action['action'],
            'label_ar'      => $action['label_ar'],
            'severity'      => $action['severity'],
            'requires_ack'  => $action['requires_ack'],
            'details'       => $details,
        ];
    }

    /**
     * Execute the response: persist to DB and return the action.
     *
     * @return array{action:string, label_ar:string, severity:string, requires_ack:bool, details:array, saved:bool}
     */
    public static function respond(
        int $riskScore,
        int $sessionId,
        int $studentId,
        int $examId,
        array $categories = [],
        string $level = ''
    ): array {
        $evaluation = self::evaluate($riskScore, $sessionId, $studentId, $examId, $categories, $level);

        // Don't log "none" actions
        if ($evaluation['action'] === 'none') {
            $evaluation['saved'] = false;
            return $evaluation;
        }

        // Check if we already have a recent response for this session (avoid duplicates within 60s)
        $recent = Database::fetchOne(
            'SELECT id FROM responses
             WHERE session_summary_id = ? AND action = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
             LIMIT 1',
            [$sessionId, $evaluation['action']]
        );

        if ($recent !== null) {
            $evaluation['saved'] = false;
            $evaluation['duplicate'] = true;
            return $evaluation;
        }

        // Persist the response
        try {
            Database::execute(
                'INSERT INTO responses
                    (session_summary_id, student_id, exam_id, risk_score, action, severity, details, acknowledged, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())',
                [
                    $sessionId,
                    $studentId,
                    $examId,
                    $riskScore,
                    $evaluation['action'],
                    $evaluation['severity'],
                    json_encode($evaluation['details'], JSON_UNESCAPED_UNICODE),
                ]
            );
            $evaluation['saved'] = true;
        } catch (\Throwable $e) {
            $evaluation['saved'] = false;
            $evaluation['error'] = $e->getMessage();
        }

        return $evaluation;
    }

    /**
     * Get pending (unacknowledged) responses for a given exam, scoped by account.
     */
    public static function pendingForExam(int $examId, int $accountId = 0): array
    {
        $sql = 'SELECT r.*, s.student_id, st.fullname, st.username
                 FROM responses r
                 JOIN session_summaries s ON s.id = r.session_summary_id
                 JOIN students st ON st.id = r.student_id
                 JOIN exams e ON e.id = r.exam_id
                WHERE r.exam_id = ? AND r.acknowledged = 0';
        $params = [$examId];
        if ($accountId > 0) {
            $sql .= ' AND e.account_id = ?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY r.risk_score DESC, r.created_at DESC';
        return Database::fetchAll($sql, $params);
    }

    /**
     * Get all responses for a session.
     */
    public static function forSession(int $sessionId): array
    {
        return Database::fetchAll(
            'SELECT * FROM responses WHERE session_summary_id = ? ORDER BY created_at DESC',
            [$sessionId]
        );
    }

    /**
     * Get summary stats for responses, scoped by account.
     */
    public static function stats(int $accountId = 0): array
    {
        if ($accountId > 0) {
            $total = (int)Database::scalar(
                'SELECT COUNT(*) FROM responses r JOIN exams e ON e.id = r.exam_id WHERE e.account_id = ?',
                [$accountId]
            );
            $pending = (int)Database::scalar(
                'SELECT COUNT(*) FROM responses r JOIN exams e ON e.id = r.exam_id WHERE e.account_id = ? AND r.acknowledged = 0',
                [$accountId]
            );
            $bySeverity = Database::fetchAll(
                'SELECT r.severity, COUNT(*) as count FROM responses r JOIN exams e ON e.id = r.exam_id WHERE e.account_id = ? GROUP BY r.severity',
                [$accountId]
            );
            $byAction = Database::fetchAll(
                'SELECT r.action, COUNT(*) as count FROM responses r JOIN exams e ON e.id = r.exam_id WHERE e.account_id = ? GROUP BY r.action',
                [$accountId]
            );
        } else {
            $total = (int)Database::scalar('SELECT COUNT(*) FROM responses');
            $pending = (int)Database::scalar('SELECT COUNT(*) FROM responses WHERE acknowledged = 0');
            $bySeverity = Database::fetchAll(
                'SELECT severity, COUNT(*) as count FROM responses GROUP BY severity'
            );
            $byAction = Database::fetchAll(
                'SELECT action, COUNT(*) as count FROM responses GROUP BY action'
            );
        }

        return [
            'total'       => $total,
            'pending'     => $pending,
            'by_severity' => $bySeverity,
            'by_action'   => $byAction,
        ];
    }

    /**
     * Acknowledge a response (mark as reviewed by instructor).
     */
    public static function acknowledge(int $responseId, int $ackBy = 0): bool
    {
        $affected = Database::execute(
            'UPDATE responses SET acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND acknowledged = 0',
            [$ackBy, $responseId]
        );
        return $affected > 0;
    }

    /**
     * Bulk acknowledge all responses for an exam.
     */
    public static function acknowledgeExam(int $examId, int $ackBy = 0): int
    {
        return (int)Database::execute(
            'UPDATE responses SET acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE exam_id = ? AND acknowledged = 0',
            [$ackBy, $examId]
        );
    }

    private static function none(): array
    {
        return [
            'min'          => 0,
            'action'       => 'none',
            'label_ar'     => 'مراقبة عادية',
            'severity'     => 'info',
            'requires_ack' => false,
        ];
    }
}
