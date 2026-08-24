<?php
/**
 * AI Content Detector — SOAR Integration Layer
 * ==============================================
 * Bridges FailoverAIDetector (RapidAPI) with answer_records.
 *
 * Guard clause: only runs for essay questions with 30+ words.
 * Called from:
 *   - AIDetectionController (real-time, on essay save)
 *   - Aggregator::runAdvancedAnalytics (batch, catches missed answers)
 */
final class AIDetector
{
    private const MIN_WORDS = 30;

    /**
     * Analyze a single answer text for AI-generated content.
     *
     * @param string $answerText  Raw answer text from the student
     * @return array{ai_score:float, status:string, provider:string, word_count:int}
     */
    public static function analyzeText(string $answerText): array
    {
        $cleanText = trim($answerText);
        $wordCount = str_word_count($cleanText);

        if ($wordCount < self::MIN_WORDS) {
            return [
                'ai_score'   => 0.0,
                'status'     => 'SKIPPED',
                'provider'   => 'NONE',
                'word_count' => $wordCount,
                'reason'     => 'Essay below minimum word count (' . $wordCount . ' < ' . self::MIN_WORDS . ')',
            ];
        }

        $apiKey = getenv('RAPIDAPI_KEY') ?: (string)em_config('ai_content_detection.rapidapi_key', '');
        if ($apiKey === '') {
            error_log('[AIDetector] RAPIDAPI_KEY not configured');
            return [
                'ai_score'   => 0.0,
                'status'     => 'CONFIG_ERROR',
                'provider'   => 'NONE',
                'word_count' => $wordCount,
                'reason'     => 'RAPIDAPI_KEY not set in environment or configuration',
            ];
        }

        $detector = new FailoverAIDetector($apiKey);
        $result   = $detector->detect($cleanText);

        return [
            'ai_score'   => $result['ai_score'],
            'status'     => $result['status'],
            'provider'   => $result['provider'],
            'word_count' => $wordCount,
            'reason'     => $result['reason'] ?? null,
        ];
    }

    /**
     * Analyze a single answer and persist the score to answer_records.
     *
     * @param int    $accountId
     * @param string $sessionId
     * @param string $questionId   The question DOM id or question number
     * @param string $answerText   The answer text to analyze
     * @return array{ai_score:float, status:string, provider:string, updated:bool}
     */
    public static function analyzeAndPersist(
        int    $accountId,
        string $sessionId,
        string $questionId,
        string $answerText
    ): array {
        $result = self::analyzeText($answerText);

        $updated = false;
        if ($result['status'] === 'SUCCESS' && $result['ai_score'] > 0) {
            try {
                Database::execute(
                    'UPDATE answer_records
                     SET ai_score = :score,
                         ai_detection_provider = :provider,
                         ai_detection_status = :status,
                         ai_detected_at = NOW(3)
                     WHERE account_id = :a AND session_id = :s AND question_id = :q
                       AND ai_score = 0',
                    [
                        ':score'    => (int)round($result['ai_score']),
                        ':provider' => $result['provider'],
                        ':status'   => $result['status'],
                        ':a'        => $accountId,
                        ':s'        => $sessionId,
                        ':q'        => $questionId,
                    ]
                );
                $updated = (Database::connection()->rowCount() > 0);
            } catch (\Throwable $e) {
                error_log('[AIDetector] persist error: ' . $e->getMessage());
            }
        } elseif ($result['status'] === 'SKIPPED' || $result['status'] === 'CONFIG_ERROR') {
            // Record the skip/error for audit trail
            try {
                Database::execute(
                    'UPDATE answer_records
                     SET ai_detection_status = :status,
                         ai_detected_at = NOW(3)
                     WHERE account_id = :a AND session_id = :s AND question_id = :q
                       AND ai_detection_status = \'\'',
                    [
                        ':status' => $result['status'],
                        ':a'      => $accountId,
                        ':s'      => $sessionId,
                        ':q'      => $questionId,
                    ]
                );
            } catch (\Throwable $e) {
                // Ignore — non-critical
            }
        }

        $result['updated'] = $updated;
        return $result;
    }

    /**
     * Analyze all unanswered essay answers for a session (batch catch-up).
     * Called from Aggregator::runAdvancedAnalytics.
     *
     * @param int    $accountId
     * @param string $sessionId
     * @return array{analyzed:int, flagged:int, scores:array}
     */
    public static function analyzeSession(int $accountId, string $sessionId): array
    {
        $pdo = Database::connection();

        $st = $pdo->prepare(
            'SELECT id, question_id, answer_text, word_count, ai_score
             FROM answer_records
             WHERE account_id = :a AND session_id = :s
               AND ai_score = 0
               AND word_count >= ' . self::MIN_WORDS
        );
        $st->execute([':a' => $accountId, ':s' => $sessionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $analyzed = 0;
        $flagged  = 0;
        $scores   = [];

        foreach ($rows as $row) {
            $answerText = $row['answer_text'] ?? '';
            if (trim($answerText) === '') continue;

            $result = self::analyzeAndPersist(
                $accountId,
                $sessionId,
                (string)$row['question_id'],
                $answerText
            );

            $analyzed++;
            $scores[] = [
                'question_id' => $row['question_id'],
                'ai_score'    => $result['ai_score'],
                'status'      => $result['status'],
                'provider'    => $result['provider'],
            ];

            if ($result['ai_score'] >= 50) {
                $flagged++;
            }
        }

        return [
            'analyzed' => $analyzed,
            'flagged'  => $flagged,
            'scores'   => $scores,
        ];
    }

    /**
     * Persist aggregated AI scores to session_summaries.
     * Updates ai_suspect_score with the max score across all answers.
     *
     * @param int    $accountId
     * @param string $sessionId
     */
    public static function persistScores(int $accountId, string $sessionId): void
    {
        $pdo = Database::connection();

        $st = $pdo->prepare(
            'SELECT MAX(ai_score) AS max_score, COUNT(*) AS total, SUM(CASE WHEN ai_score >= 50 THEN 1 ELSE 0 END) AS flagged
             FROM answer_records
             WHERE account_id = :a AND session_id = :s'
        );
        $st->execute([':a' => $accountId, ':s' => $sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) return;

        $maxScore = (int)round((float)($row['max_score'] ?? 0));

        Database::execute(
            'UPDATE session_summaries
             SET ai_suspect_score = :score
             WHERE account_id = :a AND session_id = :s',
            [
                ':score' => $maxScore,
                ':a'     => $accountId,
                ':s'     => $sessionId,
            ]
        );
    }
}
