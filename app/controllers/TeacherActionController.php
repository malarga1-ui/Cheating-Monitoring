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
            'SELECT * FROM exams WHERE id = ? OR moodle_quiz_id = ? ORDER BY id DESC LIMIT 1',
            [$examId, $examId]
        );
        if ($exam === null) {
            Response::error('الامتحان غير موجود', 404);
        }
        if (!Auth::isOwner() && !Auth::isCustomer() && !Auth::teacherOwnsExam($accountId, $teacherId, $exam)) {
            Response::error('ليس لديك صلاحية على هذا الامتحان', 403);
        }
        return $exam;
    }

    /** Automatically resolve exam ID if not provided explicitly */
    private static function resolveExamId(int $accountId, int $examId, int $studentId): int
    {
        if ($examId > 0) {
            return $examId;
        }
        if ($studentId > 0) {
            $found = (int)Database::scalar(
                'SELECT exam_id FROM session_summaries WHERE (student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?)) ORDER BY last_event_at DESC LIMIT 1',
                [$studentId, $studentId]
            );
            if ($found > 0) {
                return $found;
            }
        }
        return (int)Database::scalar(
            'SELECT e.id FROM exams e JOIN courses c ON c.moodle_course_id = e.moodle_course_id WHERE (c.account_id = ? OR c.account_id = 0) ORDER BY e.id DESC LIMIT 1',
            [$accountId]
        );
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

        $studentId = (int)($body['student_id'] ?? ($body['studentId'] ?? ($body['id'] ?? 0)));
        $examId = self::resolveExamId($accountId, (int)($body['exam_id'] ?? 0), $studentId);
        $sessionId = (int)($body['session_summary_id'] ?? ($body['sessionId'] ?? 0));
        $message = trim((string)($body['message'] ?? ''));

        if ($studentId <= 0 || $message === '') {
            Response::error('رقم الطالب ونص الرسالة مطلوبان', 422);
        }
        if (mb_strlen($message) > 500) {
            Response::error('الرسالة لا يجب أن تتجاوز 500 حرف', 422);
        }

        $exam = self::requireExamOwnership($accountId, $teacherId, $examId);
        $internalExamId = (int)$exam['id'];

        if ($sessionId <= 0) {
            $foundId = Database::scalar(
                'SELECT id FROM session_summaries WHERE (exam_id = ? OR exam_id = ?) AND (student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?)) ORDER BY id DESC LIMIT 1',
                [$internalExamId, (int)$exam['moodle_quiz_id'], $studentId, $studentId]
            );
            $sessionId = $foundId ? (int)$foundId : 0;
        }

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, "send_message", ?, "pending", NOW())',
            [$accountId, $internalExamId, $sessionId, $studentId, $teacherId, $message]
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

        $studentId = (int)($body['student_id'] ?? ($body['studentId'] ?? ($body['id'] ?? 0)));
        $examId = self::resolveExamId($accountId, (int)($body['exam_id'] ?? 0), $studentId);
        $sessionId = (int)($body['session_summary_id'] ?? ($body['sessionId'] ?? 0));

        if ($studentId <= 0) {
            Response::error('رقم الطالب مطلوب', 422);
        }

        $exam = self::requireExamOwnership($accountId, $teacherId, $examId);
        $internalExamId = (int)$exam['id'];

        if ($sessionId <= 0) {
            $foundId = Database::scalar(
                'SELECT id FROM session_summaries WHERE (exam_id = ? OR exam_id = ?) AND (student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?)) ORDER BY id DESC LIMIT 1',
                [$internalExamId, (int)$exam['moodle_quiz_id'], $studentId, $studentId]
            );
            $sessionId = $foundId ? (int)$foundId : 0;
        }

        // Check for existing pending lock
        $existing = Database::fetchOne(
            'SELECT id FROM teacher_actions
             WHERE exam_id = ? AND (student_id = ? OR (session_summary_id > 0 AND session_summary_id = ?)) AND action_type = "lock_exam" AND status = "pending"',
            [$internalExamId, $studentId, $sessionId]
        );
        if ($existing !== null) {
            Response::error('يوجد طلب قفل معلق بالفعل لهذا الطالب', 409);
        }

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, status, created_at)
             VALUES (?, ?, ?, ?, ?, "lock_exam", "pending", NOW())',
            [$accountId, $internalExamId, $sessionId, $studentId, $teacherId]
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

        $studentId = (int)($body['student_id'] ?? ($body['studentId'] ?? ($body['id'] ?? 0)));
        $examId = self::resolveExamId($accountId, (int)($body['exam_id'] ?? 0), $studentId);
        $sessionId = (int)($body['session_summary_id'] ?? ($body['sessionId'] ?? 0));
        $minutes = (int)($body['minutes'] ?? 0);

        if ($studentId <= 0 || $minutes <= 0) {
            Response::error('رقم الطالب وعدد الدقائق مطلوبان', 422);
        }
        if ($minutes > 60) {
            Response::error('لا يمكن تقليص أكثر من 60 دقيقة في المرة الواحدة', 422);
        }

        $exam = self::requireExamOwnership($accountId, $teacherId, $examId);
        $internalExamId = (int)$exam['id'];

        if ($sessionId <= 0) {
            $foundId = Database::scalar(
                'SELECT id FROM session_summaries WHERE (exam_id = ? OR exam_id = ?) AND (student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?)) ORDER BY id DESC LIMIT 1',
                [$internalExamId, (int)$exam['moodle_quiz_id'], $studentId, $studentId]
            );
            $sessionId = $foundId ? (int)$foundId : 0;
        }

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, minutes_to_reduce, status, created_at)
             VALUES (?, ?, ?, ?, ?, "reduce_time", ?, "pending", NOW())',
            [$accountId, $internalExamId, $sessionId, $studentId, $teacherId, $minutes]
        );
        $actionId = (int)Database::scalar('SELECT LAST_INSERT_ID()');

        Database::execute(
            'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "created", NOW())',
            [$actionId]
        );

        Response::ok(['ok' => true, 'action_id' => $actionId]);
    }

    /**
     * POST /api/teacher/actions/unlock
     * Body: { exam_id, session_summary_id, student_id }
     */
    public static function unlockExam(): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $body = em_body_json() ?? [];

        $studentId = (int)($body['student_id'] ?? ($body['studentId'] ?? ($body['id'] ?? 0)));
        $examId = self::resolveExamId($accountId, (int)($body['exam_id'] ?? 0), $studentId);
        $sessionId = (int)($body['session_summary_id'] ?? ($body['sessionId'] ?? 0));

        if ($studentId <= 0) {
            Response::error('رقم الطالب مطلوب', 422);
        }

        $exam = self::requireExamOwnership($accountId, $teacherId, $examId);
        $internalExamId = (int)$exam['id'];

        if ($sessionId <= 0) {
            $foundId = Database::scalar(
                'SELECT id FROM session_summaries WHERE (exam_id = ? OR exam_id = ?) AND (student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?)) ORDER BY id DESC LIMIT 1',
                [$internalExamId, (int)$exam['moodle_quiz_id'], $studentId, $studentId]
            );
            $sessionId = $foundId ? (int)$foundId : 0;
        }

        // Expire any existing locks
        Database::execute(
            'UPDATE teacher_actions SET status = "expired" WHERE exam_id = ? AND student_id = ? AND action_type = "lock_exam"',
            [$internalExamId, $studentId]
        );

        Database::execute(
            'INSERT INTO teacher_actions
                (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, status, created_at)
             VALUES (?, ?, ?, ?, ?, "unlock_exam", "pending", NOW())',
            [$accountId, $internalExamId, $sessionId, $studentId, $teacherId]
        );
        $actionId = (int)Database::scalar('SELECT LAST_INSERT_ID()');

        Database::execute(
            'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "created", NOW())',
            [$actionId]
        );

        Response::ok(['ok' => true, 'action_id' => $actionId]);
    }

    /**
     * Set CORS headers for Moodle plugin requests.
     */
    private static function corsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Exam-Monitor-Secret, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * GET/POST/OPTIONS /api/teacher/actions/check
     * Plugin polls this endpoint. Query/Body: session_id, student_id, exam_id, secret
     * Returns pending actions for the given session.
     */
    public static function check(): void
    {
        self::corsHeaders();
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            Response::empty(204);
            return;
        }

        try {
            self::ensureTables();

            $secret = $_SERVER['HTTP_X_EXAM_MONITOR_SECRET'] ?? ($_GET['k'] ?? '');
            $body = em_body_json() ?? [];
            $pluginSecret = (string)($body['secret'] ?? $secret);
            if ($pluginSecret === '' && !empty($_SERVER['HTTP_X_EXAM_MONITOR_SECRET'])) {
                $pluginSecret = $_SERVER['HTTP_X_EXAM_MONITOR_SECRET'];
            }

            if ($pluginSecret === '') {
                Response::json(['actions' => []]);
                return;
            }

            $account = Accounts::resolveBySecret($pluginSecret);
            if ($account === null) {
                Response::json(['actions' => []]);
                return;
            }
            $accountId = (int)$account['id'];

            // Decrypt if client sent an encrypted envelope
            $decrypted = Crypto::decryptIfEncrypted($body, (string)$account['sync_secret']);
            if (is_array($decrypted)) {
                $body = $decrypted;
            }

            $sessionId = (string)($body['session_id'] ?? ($_GET['session_id'] ?? ''));
            $studentId = (int)($body['student_id'] ?? ($_GET['student_id'] ?? 0));
            $examId = (int)($body['exam_id'] ?? ($_GET['exam_id'] ?? 0));

            // Flexible query builder matching by session_id, student_id (internal or moodle), exam_id (internal or moodle)
            $buildMatch = function(string $statusCondition) use ($accountId, $sessionId, $studentId, $examId): array {
                $where = "account_id = ? AND $statusCondition";
                $params = [$accountId];
                $clauses = [];

                if ($sessionId !== '') {
                    $clauses[] = '(session_summary_id = ? OR session_summary_id IN (SELECT id FROM session_summaries WHERE session_id = ?))';
                    $params[] = is_numeric($sessionId) ? (int)$sessionId : 0;
                    $params[] = $sessionId;
                }

                if ($studentId > 0 && $examId > 0) {
                    $clauses[] = '((student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?) OR student_id IN (SELECT moodle_user_id FROM students WHERE id = ?)) AND (exam_id = ? OR exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ?) OR exam_id IN (SELECT moodle_quiz_id FROM exams WHERE id = ?)))';
                    $params[] = $studentId;
                    $params[] = $studentId;
                    $params[] = $studentId;
                    $params[] = $examId;
                    $params[] = $examId;
                    $params[] = $examId;
                } elseif ($studentId > 0) {
                    $clauses[] = '(student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?) OR student_id IN (SELECT moodle_user_id FROM students WHERE id = ?))';
                    $params[] = $studentId;
                    $params[] = $studentId;
                    $params[] = $studentId;
                }

                if (!empty($clauses)) {
                    $where .= ' AND (' . implode(' OR ', $clauses) . ')';
                }

                return [$where, $params];
            };

            // 1. Pending actions
            [$pendingWhere, $pendingParams] = $buildMatch('status = "pending"');
            $actions = Database::fetchAll(
                "SELECT id, action_type, message, minutes_to_reduce, created_at
                 FROM teacher_actions
                 WHERE $pendingWhere
                 ORDER BY created_at ASC",
                $pendingParams
            );

            // Mark actions as delivered and build result array
            $result = [];
            foreach ($actions as $a) {
                try {
                    Database::execute(
                        'UPDATE teacher_actions SET status = "delivered", delivered_at = NOW() WHERE id = ? AND status = "pending"',
                        [(int)$a['id']]
                    );
                    Database::execute(
                        'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "delivered", NOW())',
                        [(int)$a['id']]
                    );
                } catch (\Throwable $e) {}
                $result[] = [
                    'id' => (int)$a['id'],
                    'action' => $a['action_type'],
                    'message' => $a['message'],
                    'minutes' => $a['minutes_to_reduce'] !== null ? (int)$a['minutes_to_reduce'] : null,
                ];
            }

            // 2. Check if current active session or student is locked
            $isLocked = false;
            $lockParams = [$accountId];
            $lockClauses = [];
            if ($sessionId !== '') {
                $lockClauses[] = '(session_summary_id > 0 AND (session_summary_id = ? OR session_summary_id IN (SELECT id FROM session_summaries WHERE session_id = ?)))';
                $lockParams[] = is_numeric($sessionId) ? (int)$sessionId : 0;
                $lockParams[] = $sessionId;
            }
            if ($studentId > 0) {
                $lockClauses[] = '(student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?) OR student_id IN (SELECT moodle_user_id FROM students WHERE id = ?))';
                $lockParams[] = $studentId;
                $lockParams[] = $studentId;
                $lockParams[] = $studentId;
            }
            if (!empty($lockClauses)) {
                $lockWhere = "account_id = ? AND action_type IN ('lock_exam', 'unlock_exam') AND status IN ('pending', 'delivered') AND (" . implode(' OR ', $lockClauses) . ")";
                $latestLock = Database::fetchOne(
                    "SELECT action_type FROM teacher_actions WHERE $lockWhere ORDER BY id DESC LIMIT 1",
                    $lockParams
                );
                $isLocked = ($latestLock && $latestLock['action_type'] === 'lock_exam');
            }

            // If any action in current pending batch is lock_exam or unlock_exam
            foreach ($result as $act) {
                if ($act['action'] === 'lock_exam') {
                    $isLocked = true;
                } elseif ($act['action'] === 'unlock_exam') {
                    $isLocked = false;
                }
            }

            // 3. Cumulative reduced minutes for current student or session
            $totalReducedMinutes = 0;
            $redParams = [$accountId];
            $redClauses = [];
            if ($sessionId !== '') {
                $redClauses[] = '(session_summary_id > 0 AND (session_summary_id = ? OR session_summary_id IN (SELECT id FROM session_summaries WHERE session_id = ?)))';
                $redParams[] = is_numeric($sessionId) ? (int)$sessionId : 0;
                $redParams[] = $sessionId;
            }
            if ($studentId > 0) {
                $redClauses[] = '(student_id = ? OR student_id IN (SELECT id FROM students WHERE moodle_user_id = ?) OR student_id IN (SELECT moodle_user_id FROM students WHERE id = ?))';
                $redParams[] = $studentId;
                $redParams[] = $studentId;
                $redParams[] = $studentId;
            }
            if (!empty($redClauses)) {
                $redWhere = "account_id = ? AND action_type = 'reduce_time' AND (" . implode(' OR ', $redClauses) . ")";
                $totalReducedMinutes = (int)Database::scalar(
                    "SELECT COALESCE(SUM(minutes_to_reduce), 0) FROM teacher_actions WHERE $redWhere",
                    $redParams
                );
            }

            // Extract attempt/session info if available to assist client-side quiz actions
            $sessionInfo = null;
            if ($sessionId !== '') {
                try {
                    $ssRow = Database::fetchOne(
                        'SELECT session_id, student_id, exam_id FROM session_summaries WHERE session_id = ? OR id = ? LIMIT 1',
                        [$sessionId, is_numeric($sessionId) ? (int)$sessionId : 0]
                    );
                    if ($ssRow) {
                        $sessionInfo = [
                            'session_id' => $ssRow['session_id'],
                            'student_id' => (int)$ssRow['student_id'],
                            'exam_id'    => (int)$ssRow['exam_id'],
                        ];
                    }
                } catch (\Throwable $e) {}
            }

            Response::json([
                'actions' => $result,
                'is_locked' => $isLocked,
                'total_reduced_minutes' => $totalReducedMinutes,
                'session_info' => $sessionInfo,
            ]);
        } catch (\Throwable $e) {
            error_log('[TeacherActionController::check] Error: ' . $e->getMessage());
            Response::json(['actions' => [], 'is_locked' => false, 'total_reduced_minutes' => 0]);
        }
    }

    /**
     * POST /api/teacher/actions/{id}/ack
     * Student acknowledges the action (called by plugin after executing it).
     */
    public static function acknowledge(string $idStr): void
    {
        self::corsHeaders();
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            Response::empty(204);
            return;
        }

        try {
            self::ensureTables();
            $id = (int)$idStr;
            if ($id <= 0) {
                Response::error('Invalid action ID', 400);
            }

            // Verify via plugin secret
            $body = em_body_json() ?? [];
            $secret = $_SERVER['HTTP_X_EXAM_MONITOR_SECRET'] ?? ($_GET['k'] ?? '');
            $pluginSecret = (string)($body['secret'] ?? $secret);
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
        } catch (\Throwable $e) {
            error_log('[TeacherActionController::acknowledge] Error: ' . $e->getMessage());
            Response::ok(['ok' => true]);
        }
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

    /**
     * GET /api/teacher/actions/history
     * Returns all actions dispatched by this teacher, optionally filtered by exam_id.
     */
    public static function history(): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $examId = (int)($_GET['exam_id'] ?? 0);

        try {
            $params = [$accountId];
            $where = 'ta.account_id = ?';

            if ($examId > 0) {
                $where .= ' AND (ta.exam_id = ? OR ta.exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ?))';
                $params[] = $examId;
                $params[] = $examId;
            }

            $actions = Database::fetchAll(
                "SELECT ta.*,
                        COALESCE(st.fullname, CONCAT('طالب #', ta.student_id)) AS student_name,
                        COALESCE(st.username, CONCAT('user_', ta.student_id))   AS student_username,
                        COALESCE(e.name, CONCAT('امتحان #', ta.exam_id))        AS exam_name,
                        COALESCE(t.fullname, CONCAT('مدرس #', ta.teacher_id))   AS teacher_name
                 FROM teacher_actions ta
                 LEFT JOIN students st ON (st.id = ta.student_id OR st.moodle_user_id = ta.student_id) AND (st.account_id = ta.account_id OR st.account_id = 0)
                 LEFT JOIN exams e ON (e.id = ta.exam_id OR e.moodle_quiz_id = ta.exam_id)
                 LEFT JOIN teachers t ON (t.moodle_teacher_id = ta.teacher_id OR t.id = ta.teacher_id) AND (t.account_id = ta.account_id OR t.account_id = 0)
                 WHERE $where
                 ORDER BY ta.id DESC
                 LIMIT 100",
                $params
            );

            // Compute statistics
            $stats = [
                'total'        => count($actions),
                'pending'      => 0,
                'delivered'    => 0,
                'acknowledged' => 0,
                'active_locks' => 0,
            ];

            foreach ($actions as $act) {
                $status = $act['status'] ?? 'pending';
                if (isset($stats[$status])) {
                    $stats[$status]++;
                }
                if ($act['action_type'] === 'lock_exam' && in_array($status, ['pending', 'delivered'])) {
                    $stats['active_locks']++;
                }
            }

            Response::ok([
                'actions' => $actions,
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            error_log('[TeacherActionController::history] ' . $e->getMessage());
            Response::ok(['actions' => [], 'stats' => ['total' => 0, 'pending' => 0, 'delivered' => 0, 'acknowledged' => 0, 'active_locks' => 0]]);
        }
    }

    /**
     * POST /api/teacher/actions/broadcast
     * Dispatches a message or action to ALL active students in an exam.
     * Body: { exam_id, message, action_type }
     */
    public static function broadcast(): void
    {
        self::ensureTables();
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();
        $body = em_body_json() ?? [];

        $examId = (int)($body['exam_id'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));
        $actionType = (string)($body['action_type'] ?? 'send_message');

        if (!in_array($actionType, ['send_message', 'lock_exam', 'reduce_time'], true)) {
            $actionType = 'send_message';
        }

        if ($actionType === 'send_message' && $message === '') {
            Response::error('نص الرسالة مطلوب للبث العام', 422);
        }

        $exam = self::requireExamOwnership($accountId, $teacherId, $examId);
        $internalExamId = (int)$exam['id'];
        $moodleQuizId = (int)$exam['moodle_quiz_id'];

        // Find all active students currently or recently in this exam
        $students = Database::fetchAll(
            'SELECT DISTINCT student_id FROM session_summaries
             WHERE (exam_id = ? OR exam_id = ?) AND student_id > 0',
            [$internalExamId, $moodleQuizId]
        );

        if (empty($students)) {
            // Also check events table
            $students = Database::fetchAll(
                'SELECT DISTINCT moodle_user_id AS student_id FROM events
                 WHERE (moodle_quiz_id = ? OR moodle_quiz_id = ?) AND moodle_user_id > 0
                 AND event_time >= NOW() - INTERVAL 2 HOUR',
                [$internalExamId, $moodleQuizId]
            );
        }

        $insertedCount = 0;
        $minutes = (int)($body['minutes'] ?? 5);

        foreach ($students as $st) {
            $sid = (int)$st['student_id'];
            if ($sid <= 0) continue;

            Database::execute(
                'INSERT INTO teacher_actions
                    (account_id, exam_id, session_summary_id, student_id, teacher_id, action_type, message, minutes_to_reduce, status, created_at)
                 VALUES (?, ?, 0, ?, ?, ?, ?, ?, "pending", NOW())',
                [
                    $accountId,
                    $internalExamId,
                    $sid,
                    $teacherId,
                    $actionType,
                    $actionType === 'send_message' ? $message : null,
                    $actionType === 'reduce_time' ? $minutes : null,
                ]
            );
            $actionId = (int)Database::scalar('SELECT LAST_INSERT_ID()');
            Database::execute(
                'INSERT INTO teacher_action_log (action_id, event_type, created_at) VALUES (?, "created", NOW())',
                [$actionId]
            );
            $insertedCount++;
        }

        Response::ok([
            'ok'             => true,
            'broadcast_count'=> $insertedCount,
            'message'        => "تم إرسال البث بنجاح إلى ($insertedCount) طالب في الامتحان",
        ]);
    }
}
