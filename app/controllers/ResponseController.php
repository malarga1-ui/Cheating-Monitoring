<?php
/**
 * Controller for automated SOAR response actions.
 * All endpoints require authentication and scope data by account_id.
 */
class ResponseController
{
    /**
     * GET /api/responses/pending/{examId}
     * Returns unacknowledged responses for an exam.
     */
    public static function pending(array $params): void
    {
        Auth::requireLogin();
        $examId = (int)($params['examId'] ?? 0);
        if ($examId <= 0) {
            Response::json(['error' => 'Invalid exam ID'], 400);
            return;
        }
        $accountId = Auth::accountId();
        $responses = ResponseEngine::pendingForExam($examId, $accountId);
        Response::json($responses);
    }

    /**
     * GET /api/responses/stats
     * Returns response statistics scoped to the user's account.
     */
    public static function stats(): void
    {
        Auth::requireLogin();
        $accountId = Auth::accountId();
        Response::json(ResponseEngine::stats($accountId));
    }

    /**
     * POST /api/responses/{id}/acknowledge
     * Mark a response as reviewed by instructor.
     */
    public static function acknowledge(array $params): void
    {
        Auth::requireLogin();
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::json(['error' => 'Invalid response ID'], 400);
            return;
        }
        $userId = Auth::staffId() ?: Auth::teacherId();
        $ok = ResponseEngine::acknowledge($id, $userId);
        Response::json(['success' => $ok]);
    }

    /**
     * POST /api/responses/exam/{examId}/ack-all
     * Bulk acknowledge all pending responses for an exam.
     */
    public static function acknowledgeExam(array $params): void
    {
        Auth::requireLogin();
        $examId = (int)($params['examId'] ?? 0);
        if ($examId <= 0) {
            Response::json(['error' => 'Invalid exam ID'], 400);
            return;
        }
        $userId = Auth::staffId() ?: Auth::teacherId();
        $count = ResponseEngine::acknowledgeExam($examId, $userId);
        Response::json(['acknowledged' => $count]);
    }

    /**
     * GET /api/responses/session/{sessionId}
     * Returns all responses for a specific session.
     */
    public static function forSession(array $params): void
    {
        Auth::requireLogin();
        $sessionId = (int)($params['sessionId'] ?? 0);
        if ($sessionId <= 0) {
            Response::json(['error' => 'Invalid session ID'], 400);
            return;
        }
        Response::json(ResponseEngine::forSession($sessionId));
    }
}
