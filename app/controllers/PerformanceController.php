<?php
/**
 * Controller for performance metrics (Chapter 3.4).
 * All endpoints require authentication.
 */
class PerformanceController
{
    /**
     * GET /api/performance/system
     * System-wide metrics across all exams for the current account.
     */
    public static function systemWide(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        Response::json(PerformanceMetrics::systemWide($accountId));
    }

    /**
     * GET /api/performance/exam/{examId}
     * Metrics for a specific exam.
     */
    public static function examMetrics(array $params): void
    {
        Auth::requireLogin();
        $examId = (int)($params['examId'] ?? 0);
        if ($examId <= 0) {
            Response::json(['error' => 'Invalid exam ID'], 400);
            return;
        }
        $accountId = Auth::accountId();
        Response::json(PerformanceMetrics::getForExam($examId, $accountId));
    }

    /**
     * GET /api/performance/all
     * Metrics for all exams in the current account.
     */
    public static function allExams(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        Response::json(PerformanceMetrics::allExams($accountId));
    }

    /**
     * POST /api/performance/verdict
     * Set instructor verdict (ground truth label) for a session.
     * Body: { session_id: int, verdict: "cheating"|"clean" }
     */
    public static function setVerdict(): void
    {
        Auth::requireLogin();
        $body = em_body_json();
        $sessionId = (int)($body['session_id'] ?? 0);
        $verdict = (string)($body['verdict'] ?? '');

        if ($sessionId <= 0 || !in_array($verdict, ['cheating', 'clean'], true)) {
            Response::json(['error' => 'Invalid parameters. session_id required, verdict must be "cheating" or "clean".'], 400);
            return;
        }

        $userId = Auth::staffId() ?: Auth::teacherId();
        $ok = PerformanceMetrics::setVerdict($sessionId, $verdict, $userId);
        Response::json(['success' => $ok]);
    }

    /**
     * POST /api/performance/recompute/{examId}
     * Force recompute metrics for an exam.
     */
    public static function recompute(array $params): void
    {
        Auth::requireLogin();
        $examId = (int)($params['examId'] ?? 0);
        if ($examId <= 0) {
            Response::json(['error' => 'Invalid exam ID'], 400);
            return;
        }
        Database::execute('DELETE FROM performance_metrics WHERE exam_id = ?', [$examId]);
        $metrics = PerformanceMetrics::computeForExam($examId);
        Response::json($metrics);
    }
}
