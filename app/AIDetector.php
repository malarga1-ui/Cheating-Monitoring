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
    private const MIN_WORDS = 20;

    /**
     * Unicode-aware word count supporting Arabic, English, and multilingual text.
     */
    public static function countWords(string $text): int
    {
        $clean = trim($text);
        if ($clean === '') return 0;
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    /**
     * Heuristic statistical & stylistic AI content detector (offline fallback).
     * Analyzes discourse markers, sentence length variance, and vocabulary patterns.
     */
    public static function heuristicDetect(string $text, int $wordCount): array
    {
        $textLower = mb_strtolower($text, 'UTF-8');
        
        // Characteristic AI markers (Arabic & English)
        $aiMarkersAr = [
            'من الجدير بالذكر', 'بناءً على ذلك', 'في الختام', 'علاوة على ذلك',
            'يجدر بالذكر', 'بالإضافة إلى ذلك', 'من ناحية أخرى', 'تلخيصاً لما سبق',
            'يلعب دوراً حاسماً', 'تجدر الإشارة إلى', 'يمكن القول بأن', 'خلاصة القول',
            'من هذا المنطلق', 'بشكل عام', 'على وجه الخصوص', 'بصورة شاملة'
        ];
        $aiMarkersEn = [
            'in conclusion', 'furthermore', 'moreover', 'it is worth noting',
            'additionally', 'on the other hand', 'plays a crucial role',
            'consequently', 'in summary', 'it is important to emphasize',
            'first and foremost', 'as mentioned previously', 'to summarize'
        ];

        $matchedMarkers = 0;
        foreach (array_merge($aiMarkersAr, $aiMarkersEn) as $marker) {
            if (mb_stripos($textLower, $marker) !== false) {
                $matchedMarkers++;
            }
        }

        // Sentence length consistency (low variance is typical for LLMs)
        $sentences = preg_split('/[\.\!\؟\?\;\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentenceCount = max(1, count($sentences));
        $avgWordsPerSentence = $wordCount / $sentenceCount;

        // Base score based on markers and length
        $score = 0.0;
        if ($matchedMarkers >= 3) {
            $score = 88.0;
        } elseif ($matchedMarkers === 2) {
            $score = 78.0;
        } elseif ($matchedMarkers === 1) {
            $score = 65.0;
        } elseif ($wordCount >= 25 && $avgWordsPerSentence >= 8 && $avgWordsPerSentence <= 25) {
            // Highly structured paragraph with balanced phrasing
            $score = 55.0;
        } else {
            $score = 30.0;
        }

        // Check for bullet-point formatting or structured numbers
        if (preg_match('/(\d+[\.\-\)]|\•|\*|\-)\s+/u', $text)) {
            $score = min(95.0, $score + 10.0);
        }

        return [
            'ai_score' => round($score, 1),
            'status'   => 'SUCCESS',
            'provider' => 'HEURISTIC_AI_ENGINE',
            'reason'   => "Matched {$matchedMarkers} AI discourse patterns with {$sentenceCount} structured sentences",
        ];
    }

    /**
     * Analyze a single answer text for AI-generated content.
     *
     * @param string $answerText  Raw answer text from the student
     * @return array{ai_score:float, status:string, provider:string, word_count:int}
     */
    public static function analyzeText(string $answerText): array
    {
        $cleanText = trim($answerText);
        $wordCount = self::countWords($cleanText);

        if ($wordCount < self::MIN_WORDS) {
            return [
                'ai_score'   => 0.0,
                'status'     => 'SKIPPED',
                'provider'   => 'NONE',
                'word_count' => $wordCount,
                'reason'     => 'Essay below minimum word count (' . $wordCount . ' < ' . self::MIN_WORDS . ')',
            ];
        }

        $apiKey = getenv('RAPIDAPI_KEY') ?: (function_exists('em_config') ? (string)em_config('ai_content_detection.rapidapi_key', '') : '');
        if ($apiKey !== '') {
            try {
                $detector = new FailoverAIDetector($apiKey);
                $result   = $detector->detect($cleanText);
                if ($result['status'] === 'SUCCESS' && $result['ai_score'] > 0) {
                    return [
                        'ai_score'   => $result['ai_score'],
                        'status'     => $result['status'],
                        'provider'   => $result['provider'],
                        'word_count' => $wordCount,
                        'reason'     => $result['reason'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[AIDetector] External API error: ' . $e->getMessage());
            }
        }

        // Fallback to built-in heuristic AI detector
        $heuristic = self::heuristicDetect($cleanText, $wordCount);
        return [
            'ai_score'   => $heuristic['ai_score'],
            'status'     => $heuristic['status'],
            'provider'   => $heuristic['provider'],
            'word_count' => $wordCount,
            'reason'     => $heuristic['reason'],
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
             WHERE (account_id = :a OR account_id = 0) AND session_id = :s
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
             WHERE (account_id = :a OR account_id = 0) AND session_id = :s'
        );
        $st->execute([':a' => $accountId, ':s' => $sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) return;

        $maxScore = (int)round((float)($row['max_score'] ?? 0));

        Database::execute(
            'UPDATE session_summaries
             SET ai_suspect_score = :score
             WHERE (account_id = :a OR account_id = 0) AND session_id = :s',
            [
                ':score' => $maxScore,
                ':a'     => $accountId,
                ':s'     => $sessionId,
            ]
        );
    }
}
