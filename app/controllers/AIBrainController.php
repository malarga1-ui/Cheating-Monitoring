<?php
/**
 * AI Brain Controller — Exam Monitor Platform
 * ============================================
 * Provides endpoints for AI-powered analysis:
 *   POST /api/ai/predict   → Predict for one student session
 *   POST /api/ai/batch     → Predict for all students in exam
 *   POST /api/ai/patterns  → Detect cheating rings
 *   POST /api/ai/report    → Generate smart report
 *   POST /api/ai/train     → Retrain models
 *   GET  /api/ai/status    → Check ML service status
 */
final class AIBrainController
{
    private static function jsonHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    public static function status(): void
    {
        self::jsonHeader();
        $available = AIBrain::isAvailable();
        echo json_encode([
            'ml_service' => $available ? 'online' : 'offline',
            'mode'       => $available ? 'ml-engine' : 'local-fallback',
            'version'    => '2.0',
        ]);
    }

    public static function predict(): void
    {
        Auth::requireLogin();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['session_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'session_id required']);
            return;
        }

        $result = AIBrain::predict(
            (int)$input['session_id'],
            (int)($input['student_id'] ?? 0),
            $input['features'] ?? $input['counters'] ?? [],
            $input['student_name'] ?? ''
        );

        echo json_encode($result);
    }

    public static function batch(): void
    {
        Auth::requireLogin();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['exam_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'exam_id required']);
            return;
        }

        $accountId = Auth::accountId();

        // Get students for this exam
        $students = self::getExamStudents((int)$input['exam_id'], $accountId);
        if ($students === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Exam not found or unauthorized']);
            return;
        }

        $result = AIBrain::batch((int)$input['exam_id'], $students);
        echo json_encode($result);
    }

    public static function patterns(): void
    {
        Auth::requireLogin();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['exam_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'exam_id required']);
            return;
        }

        $accountId = Auth::accountId();
        $students = self::getExamStudents((int)$input['exam_id'], $accountId);
        if ($students === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Exam not found']);
            return;
        }

        $result = AIBrain::patterns((int)$input['exam_id'], $students);
        echo json_encode($result);
    }

    public static function report(): void
    {
        Auth::requireLogin();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['exam_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'exam_id required']);
            return;
        }

        $accountId = Auth::accountId();
        $examId = (int)$input['exam_id'];

        // Get exam info
        $exam = Database::fetchOne(
            'SELECT id, name, question_count, time_limit FROM exams WHERE id = ? AND account_id = ?',
            [$examId, $accountId]
        );
        if (!$exam) {
            http_response_code(404);
            echo json_encode(['error' => 'Exam not found']);
            return;
        }

        $students = self::getExamStudents($examId, $accountId);
        if (!$students) $students = [];

        $result = AIBrain::report(
            $examId,
            $exam['name'] ?? '',
            $students,
            (int)($exam['question_count'] ?? 0),
            (int)($exam['time_limit'] ?? 0)
        );

        echo json_encode($result);
    }

    public static function train(): void
    {
        Auth::requireLogin();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['samples']) || !isset($input['labels'])) {
            http_response_code(400);
            echo json_encode(['error' => 'samples and labels required']);
            return;
        }

        $result = AIBrain::train($input['samples'], $input['labels']);
        echo json_encode($result);
    }

    /**
     * Get all student sessions for an exam with their behavioral counters.
     */
    private static function getExamStudents(int $examId, int $accountId): ?array
    {
        $exam = Database::fetchOne(
            'SELECT id FROM exams WHERE id = ? AND account_id = ?',
            [$examId, $accountId]
        );
        if (!$exam) return null;

        $rows = Database::fetchAll(
            'SELECT ss.*, s.fullname, s.username
             FROM session_summaries ss
             JOIN students s ON s.id = ss.student_id
             WHERE ss.exam_id = ? AND ss.account_id = ?
             ORDER BY ss.risk_score DESC',
            [$examId, $accountId]
        );

        return array_map(fn($r) => [
            'session_id'   => $r['session_id'],
            'student_id'   => (int)$r['student_id'],
            'student_name' => $r['fullname'] ?? $r['username'] ?? '',
            'counters'     => $r,
        ], $rows);
    }
}
