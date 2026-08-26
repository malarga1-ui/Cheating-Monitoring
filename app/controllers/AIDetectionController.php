<?php
/**
 * AI Content Detection Controller — SOAR Platform
 * =================================================
 * Real-time AI content detection for essay answers.
 *
 * Endpoints:
 *   POST /api/ai/detect-content   → Analyze a single answer (called by Moodle plugin on essay save)
 *   POST /api/ai/detect-batch     → Analyze all pending answers for an exam (batch catch-up)
 *   GET  /api/ai/detect-status    → Check RapidAPI configuration status
 */
final class AIDetectionController
{
    private static function jsonHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * POST /api/ai/detect-content
     *
     * Called by the Moodle plugin when a student saves an essay answer (30+ words).
     * Runs FailoverAIDetector and returns the AI score immediately.
     *
     * Body: {
     *   "session_id":    "sess_xxx",
     *   "question_id":   "q123",
     *   "question_type": "essay",
     *   "answer_text":   "...long essay text...",
     *   "moodle_user_id": 123,
     *   "moodle_quiz_id":  456
     * }
     */
    public static function detect(): void
    {
        self::jsonHeader();

        $accountId = Auth::accountId();
        $input     = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['answer_text']) || !isset($input['session_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'answer_text and session_id are required']);
            return;
        }

        $answerText  = (string)$input['answer_text'];
        $sessionId   = (string)$input['session_id'];
        $questionId  = (string)($input['question_id'] ?? '');
        $questionType = (string)($input['question_type'] ?? '');

        // Guard: only analyze essay-type questions with 10+ words
        $wordCount = AIDetector::countWords(trim($answerText));
        if ($wordCount < 10) {
            echo json_encode([
                'ai_score'   => 0.0,
                'status'     => 'SKIPPED',
                'provider'   => 'NONE',
                'word_count' => $wordCount,
                'message'    => 'Essay below minimum word count',
            ]);
            return;
        }

        // Ensure answer_record exists before updating
        self::ensureAnswerRecord($accountId, $sessionId, $input, $answerText, $wordCount);

        // Run AI detection
        $result = AIDetector::analyzeAndPersist(
            $accountId,
            $sessionId,
            $questionId,
            $answerText
        );

        // Update session-level AI score
        try {
            AIDetector::persistScores($accountId, $sessionId);
        } catch (\Throwable $e) {
            error_log('[AIDetectionController] persistScores error: ' . $e->getMessage());
        }

        echo json_encode([
            'ai_score'   => $result['ai_score'],
            'status'     => $result['status'],
            'provider'   => $result['provider'],
            'word_count' => $wordCount,
            'updated'    => $result['updated'] ?? false,
        ]);
    }

    /**
     * POST /api/ai/detect-batch
     *
     * Batch catch-up: analyze all unanswered essay questions for an exam.
     * Called by cron or teacher portal refresh.
     *
     * Body: { "exam_id": 123 }
     */
    public static function detectBatch(): void
    {
        self::jsonHeader();

        $accountId = Auth::accountId();
        $input     = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['exam_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'exam_id required']);
            return;
        }

        $examId = (int)$input['exam_id'];

        // Verify exam belongs to this account
        $exam = Database::fetchOne(
            'SELECT id FROM exams WHERE id = ? AND account_id = ?',
            [$examId, $accountId]
        );
        if (!$exam) {
            http_response_code(404);
            echo json_encode(['error' => 'Exam not found']);
            return;
        }

        // Get all sessions for this exam
        $sessions = Database::fetchAll(
            'SELECT DISTINCT session_id FROM answer_records
             WHERE account_id = ? AND exam_id = ? AND ai_score = 0 AND word_count >= 30',
            [$accountId, $examId]
        );

        $totalAnalyzed = 0;
        $totalFlagged  = 0;
        $details       = [];

        foreach ($sessions as $sess) {
            $result = AIDetector::analyzeSession($accountId, (string)$sess['session_id']);
            AIDetector::persistScores($accountId, (string)$sess['session_id']);

            $totalAnalyzed += $result['analyzed'];
            $totalFlagged  += $result['flagged'];
            $details[]     = [
                'session_id' => $sess['session_id'],
                'analyzed'   => $result['analyzed'],
                'flagged'    => $result['flagged'],
            ];
        }

        echo json_encode([
            'exam_id'     => $examId,
            'total_analyzed' => $totalAnalyzed,
            'total_flagged'  => $totalFlagged,
            'sessions'       => $details,
        ]);
    }

    /**
     * GET /api/ai/detect-status
     *
     * Check if RapidAPI is configured and available.
     */
    public static function status(): void
    {
        self::jsonHeader();

        $apiKey = getenv('RAPIDAPI_KEY') ?: '';
        $configured = $apiKey !== '';

        // Quick probe: call Provider 1 with a test string
        $probe = null;
        if ($configured) {
            $detector = new FailoverAIDetector($apiKey);
            $probe = $detector->detect('This is a test sentence to verify the API connection is working properly.');
        }

        echo json_encode([
            'configured' => $configured,
            'probe'      => $probe ? $probe['status'] : 'NOT_TESTED',
        ]);
    }

    /**
     * Ensure an answer_records row exists before we can update ai_score.
     */
    private static function ensureAnswerRecord(
        int    $accountId,
        string $sessionId,
        array  $input,
        string $answerText,
        int    $wordCount
    ): void {
        $questionId = (string)($input['question_id'] ?? '');
        if ($questionId === '') return;

        $existing = Database::fetchOne(
            'SELECT id FROM answer_records
             WHERE account_id = ? AND session_id = ? AND question_id = ?',
            [$accountId, $sessionId, $questionId]
        );

        if ($existing) return;

        $moodleUserId = (int)($input['moodle_user_id'] ?? 0);
        $moodleQuizId = (int)($input['moodle_quiz_id'] ?? 0);
        $questionType = (string)($input['question_type'] ?? 'essay');

        // Resolve student_id from students table
        $studentId = 0;
        if ($moodleUserId > 0) {
            $student = Database::fetchOne(
                'SELECT id FROM students WHERE account_id = ? AND moodle_user_id = ?',
                [$accountId, $moodleUserId]
            );
            $studentId = $student ? (int)$student['id'] : 0;
        }

        // Resolve exam_id from exams table
        $examId = 0;
        if ($moodleQuizId > 0) {
            $exam = Database::fetchOne(
                'SELECT id FROM exams WHERE account_id = ? AND moodle_quiz_id = ?',
                [$accountId, $moodleQuizId]
            );
            $examId = $exam ? (int)$exam['id'] : 0;
        }

        Database::execute(
            'INSERT INTO answer_records
             (account_id, session_id, student_id, exam_id, moodle_quiz_id, question_id, question_type,
              answer_text, answer_length, word_count, typing_duration_ms, change_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW(3))
             ON DUPLICATE KEY UPDATE
              answer_text = VALUES(answer_text),
              answer_length = VALUES(answer_length),
              word_count = VALUES(word_count),
              question_type = IF(VALUES(question_type) != \'\', VALUES(question_type), question_type)',
            [
                $accountId,
                $sessionId,
                $studentId,
                $examId,
                $moodleQuizId,
                $questionId,
                $questionType,
                $answerText,
                mb_strlen($answerText),
                $wordCount,
            ]
        );
    }
}
