<?php
/**
 * Teacher real-time action system.
 * Allows teachers to send messages, lock exams, and reduce time for students.
 *
 * POST /api/teacher/actions/message  - Send a warning message to student screen
 * POST /api/teacher/actions/lock     - Lock/end a student's exam session
 * POST /api/teacher/actions/reduce-time - Reduce student's exam time
 * GET  /api/teacher/actions/check    - Plugin polls for pending actions (public, session-based)
 * POST /api/teacher/actions/{id}/ack - Student acknowledges the action
 * GET  /api/teacher/actions/{examId}/log - Teacher views action log for an exam
 */
final class TeacherActionController
{
    private static function ensureTables(): void
    {
        try {
            Database::execute("CREATE TABLE IF NOT EXISTS teacher_actions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                exam_id INT NOT NULL,
                session_summary_id INT NOT NULL,
                student_id INT NOT NULL,
                teacher_id INT NOT NULL,
                action_type ENUM('send_message', 'lock_exam', 'reduce_time') NOT NULL,
                message TEXT DEFAULT NULL,
                minutes_to_reduce INT DEFAULT NULL,
                status ENUM('pending', 'delivered', 'acknowledged', 'expired') DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delivered_at DATETIME DEFAULT NULL,
                acknowledged_at DATETIME DEFAULT NULL,
                INDEX idx_teacher_actions_exam (exam_id, status),
                INDEX idx_teacher_actions_session (session_summary_id, status),
                INDEX idx_teacher_actions_account (account_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            Database::execute("CREATE TABLE IF NOT EXISTS teacher_action_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action_id INT NOT NULL,
                event_type ENUM('created', 'delivered', 'acknowledged', 'expired') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_action_log_action (action_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {}
    }

    /** Validate that the teacher owns this exam. */
    private static function requireExamOwnership(int $accountId, int $teacherId, int $examId): array
    {
        $exam = Database::fetchOne(
            'SELECT * FROM exams WHERE id = ? AND account_id = ?',
            [$examId, $accountId]
        );
        if ($exam === null) {
            Response::error('الامتحان غير موجود', 404);
        }
        try {
            $courseIds = Teachers::courseIds($accountId, $teacherId);
            if (!empty($courseIds) && !in_array((int)$exam['moodle_course_id'], $courseIds, true)) {
                Response::error('ليس لديك صلاحية على هذا الامتحان', 403);
            }
        } catch (\Throwable $e) {
            // If course_teachers table doesn't exist or query fails, allow access
        }
        return $exam;
    }

    /**
     * POST /api/teacher/actions/message
     * Body: { exam_id, session_summary_id, student_id, message }
     */
    public static function sendMessage(): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $body = em_body_json() ?? [];

        $examId = (int)($body['exam_id'] ?? 0);
        $sessionId = (int)($body['session_summary_id'] ?? 0);
        $studentId = (int)($body['student_id'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));

        if ($examId <= 0 || $sessionId <= 0 || $studentId <= 0 || $message === '') {
            Response::error('جميع الحقول مطلوبة', 422);
        }
        if (mb_strlen($message) > 500) {
            Response::error('الرسالة لا يجب أن تتجاوز 500 حرف', 422);
        }

        self::requireExamOwnership($accountId, $teacherId, $examId);

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, "send_message", ?, "pending", NOW())',
            [$accountId, $examId, $sessionId, $studentId, $teacherId, $message]
        );
        $actionId = (int)Database::scalar('SELECT LAST_INSERT_ID()');

        Database::execute(
            'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "created", NOW())',
            [$actionId]
        );

        Response::ok(['ok' => true, 'action_id' => $actionId]);
    }

    /**
     * POST /api/teacher/actions/lock
     * Body: { exam_id, session_summary_id, student_id }
     */
    public static function lockExam(): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $body = em_body_json() ?? [];

        $examId = (int)($body['exam_id'] ?? 0);
        $sessionId = (int)($body['session_summary_id'] ?? 0);
        $studentId = (int)($body['student_id'] ?? 0);

        if ($examId <= 0 || $sessionId <= 0 || $studentId <= 0) {
            Response::error('جميع الحقول مطلوبة', 422);
        }

        self::requireExamOwnership($accountId, $teacherId, $examId);

        // Check for existing pending lock
        $existing = Database::fetchOne(
            'SELECT id FROM teacher_actions
             WHERE exam_id = ? AND session_summary_id = ? AND action_type = "lock_exam" AND status = "pending"',
            [$examId, $sessionId]
        );
        if ($existing !== null) {
            Response::error('يوجد طلب قفل معلق بالفعل لهذا الطالب', 409);
        }

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, status, created_at)
             VALUES (?, ?, ?, ?, ?, "lock_exam", "pending", NOW())',
            [$accountId, $examId, $sessionId, $studentId, $teacherId]
        );
        $actionId = (int)Database::scalar('SELECT LAST_INSERT_ID()');

        Database::execute(
            'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "created", NOW())',
            [$actionId]
        );

        Response::ok(['ok' => true, 'action_id' => $actionId]);
    }

    /**
     * POST /api/teacher/actions/reduce-time
     * Body: { exam_id, session_summary_id, student_id, minutes }
     */
    public static function reduceTime(): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $body = em_body_json() ?? [];

        $examId = (int)($body['exam_id'] ?? 0);
        $sessionId = (int)($body['session_summary_id'] ?? 0);
        $studentId = (int)($body['student_id'] ?? 0);
        $minutes = (int)($body['minutes'] ?? 0);

        if ($examId <= 0 || $sessionId <= 0 || $studentId <= 0 || $minutes <= 0) {
            Response::error('جميع الحقول مطلوبة وعدد الدقائق يجب أن يكون أكبر من صفر', 422);
        }
        if ($minutes > 60) {
            Response::error('لا يمكن تقليص أكثر من 60 دقيقة في المرة الواحدة', 422);
        }

        self::requireExamOwnership($accountId, $teacherId, $examId);

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, minutes_to_reduce, status, created_at)
             VALUES (?, ?, ?, ?, ?, "reduce_time", ?, "pending", NOW())',
            [$accountId, $examId, $sessionId, $studentId, $teacherId, $minutes]
        );
        $actionId = (int)Database::scalar('SELECT LAST_INSERT_ID()');

        Database::execute(
            'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "created", NOW())',
            [$actionId]
        );

        Response::ok(['ok' => true, 'action_id' => $actionId]);
    }

    /**
     * GET /api/teacher/actions/check
     * Plugin polls this endpoint. Query: ?session_id=xxx&account_id=xxx
     * Returns pending actions for the given session.
     */
    public static function check(): void
    {
        // This endpoint is called by the Moodle plugin with session-based auth.
        // We verify via the API secret in the request header.
        $secret = $_SERVER['HTTP_X_EXAM_MONITOR_SECRET'] ?? '';
        $body = em_body_json() ?? [];
        $pluginSecret = (string)($body['secret'] ?? $secret);
        $sessionId = (int)($body['session_id'] ?? ($_GET['session_id'] ?? 0));

        if ($pluginSecret === '' || $sessionId <= 0) {
            Response::json(['actions' => []]);
            return;
        }

        $account = Accounts::resolveBySecret($pluginSecret);
        if ($account === null) {
            Response::json(['actions' => []]);
            return;
        }
        $accountId = (int)$account['id'];

        // Find pending actions for this session
        $actions = Database::fetchAll(
            'SELECT id, action_type, message, minutes_to_reduce, created_at
             FROM teacher_actions
             WHERE session_summary_id = ? AND account_id = ? AND status = "pending"
             ORDER BY created_at ASC',
            [$sessionId, $accountId]
        );

        // Mark them as delivered
        $result = [];
        foreach ($actions as $a) {
            Database::execute(
                'UPDATE teacher_actions SET status = "delivered", delivered_at = NOW() WHERE id = ? AND status = "pending"',
                [(int)$a['id']]
            );
            Database::execute(
                'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "delivered", NOW())',
                [(int)$a['id']]
            );
            $result[] = [
                'id' => (int)$a['id'],
                'action' => $a['action_type'],
                'message' => $a['message'],
                'minutes' => $a['minutes_to_reduce'] !== null ? (int)$a['minutes_to_reduce'] : null,
            ];
        }

        Response::json(['actions' => $result]);
    }

    /**
     * POST /api/teacher/actions/{id}/ack
     * Student acknowledges the action (called by plugin after executing it).
     */
    public static function acknowledge(string $idStr): void
    {
        $id = (int)$idStr;
        if ($id <= 0) {
            Response::error('Invalid action ID', 400);
        }

        // Verify via plugin secret
        $body = em_body_json() ?? [];
        $pluginSecret = (string)($body['secret'] ?? '');
        $account = Accounts::resolveBySecret($pluginSecret);
        if ($account === null) {
            Response::error('Unauthorized', 403);
        }

        Database::execute(
            'UPDATE teacher_actions SET status = "acknowledged", acknowledged_at = NOW() WHERE id = ? AND account_id = ? AND status = "delivered"',
            [$id, (int)$account['id']]
        );
        Database::execute(
            'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "acknowledged", NOW())',
            [$id]
        );

        Response::ok(['ok' => true]);
    }

    /**
     * GET /api/teacher/actions/{examId}/log
     * Returns action log for an exam (teacher portal).
     */
    public static function log(string $examIdStr): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $examId = (int)$examIdStr;

        if ($examId <= 0) {
            Response::error('Invalid exam ID', 400);
        }

        self::requireExamOwnership($accountId, $teacherId, $examId);

        try {
            $actions = Database::fetchAll(
                'SELECT ta.*,
                        COALESCE(st.fullname, CONCAT(\'student_\', ta.student_id)) AS student_name,
                        COALESCE(st.username, CONCAT(\'user_\', ta.student_id))   AS student_username,
                        COALESCE(t.fullname, CONCAT(\'teacher_\', ta.teacher_id))  AS teacher_name
                 FROM teacher_actions ta
                 LEFT JOIN students st ON st.id = ta.student_id AND st.account_id = ta.account_id
                 LEFT JOIN teachers t ON t.moodle_teacher_id = ta.teacher_id AND t.account_id = ta.account_id
                 WHERE ta.exam_id = ? AND ta.account_id = ?
                 ORDER BY ta.created_at DESC',
                [$examId, $accountId]
            );
        } catch (\Throwable $e) {
            $actions = [];
        }

        Response::ok(['actions' => $actions]);
    }
}
