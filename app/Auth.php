<?php
/**
 * Auth: session-based authentication over the `accounts` table with
 * tenant scoping. Owner (role=owner) sees everything; a customer sees
 * only rows belonging to their account_id.
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $cfg = em_config('auth');
        session_name($cfg['session_name']);
        session_set_cookie_params([
            'lifetime' => (int)$cfg['session_lifetime'],
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool)$cfg['cookie_secure'],
            'httponly' => (bool)$cfg['cookie_httponly'],
            'samesite' => $cfg['cookie_samesite'],
        ]);
        session_start();
    }

    public static function attempt(string $email, string $password): bool
    {
        $account = Accounts::authenticate($email, $password);
        if ($account === null) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['auth_type']   = 'account';
        $_SESSION['account_id']  = (int)$account['id'];
        $_SESSION['role']        = $account['role'];
        $_SESSION['email']       = $account['email'];
        $_SESSION['org_name']    = $account['org_name'];
        $_SESSION['csrf']        = em_random_bytes_hex(16);
        return true;
    }

    /**
     * Start a teacher session. The teacher is scoped to one account and sees
     * only the exams/courses they are assigned to.
     */
    public static function attemptTeacher(int $accountId, array $teacher): void
    {
        session_regenerate_id(true);
        $_SESSION['auth_type']       = 'teacher';
        $_SESSION['account_id']      = (int)$accountId;
        $_SESSION['role']            = 'teacher';
        $_SESSION['teacher_id']      = (int)$teacher['moodle_teacher_id'];
        $_SESSION['teacher_name']    = (string)($teacher['fullname'] ?? '');
        $_SESSION['teacher_username']= (string)($teacher['username'] ?? '');
        $_SESSION['org_name']        = (string)($teacher['org_name'] ?? '');
        $_SESSION['csrf']            = em_random_bytes_hex(16);
    }

    /**
     * Start a staff session (admin / supervisor of one university account).
     * The supervisor role is additionally limited to the courses in
     * course_access via accountFilterSql() / requireRowAccess().
     */
    public static function attemptStaff(int $accountId, array $staff): void
    {
        session_regenerate_id(true);
        $_SESSION['auth_type']   = 'staff';
        $_SESSION['account_id']  = (int)$accountId;
        $_SESSION['role']        = (string)$staff['role'];   // admin | supervisor
        $_SESSION['staff_id']    = (int)$staff['id'];
        $_SESSION['staff_role']  = (string)$staff['role'];
        $_SESSION['staff_name']  = (string)($staff['fullname'] ?? '');
        $_SESSION['staff_username'] = (string)($staff['username'] ?? '');
        $_SESSION['org_name']    = (string)($staff['org_name'] ?? '');
        $_SESSION['csrf']        = em_random_bytes_hex(16);
    }

    /** Current user (account row, or teacher variant) or null. */
    public static function user(): ?array
    {
        if (empty($_SESSION['account_id'])) {
            return null;
        }
        $account = Accounts::findById((int)$_SESSION['account_id']);
        if ($account === null) {
            return null;
        }
        Accounts::enforceStatus((int)$account['id']);
        if (Accounts::locked($account)) {
            return null;
        }

        if (self::isTeacher()) {
            $teacher = Teachers::findByAccountAndId((int)$account['id'], (int)$_SESSION['teacher_id']);
            if ($teacher === null) {
                return null;
            }
            return [
                'id'           => (int)$account['id'],
                'authType'     => 'teacher',
                'role'         => 'teacher',
                'org_name'     => $account['org_name'],
                'teacher'      => [
                    'moodle_teacher_id' => (int)$teacher['moodle_teacher_id'],
                    'fullname'          => $teacher['fullname'],
                    'username'          => $teacher['username'],
                ],
            ];
        }

        if (self::isStaff()) {
            $staff = Staff::findById((int)$account['id'], (int)$_SESSION['staff_id']);
            if ($staff === null || (int)$staff['is_active'] !== 1) {
                return null;
            }
            return [
                'id'         => (int)$account['id'],
                'authType'   => 'staff',
                'role'       => (string)$staff['role'],   // admin | supervisor
                'staffRole'  => (string)$staff['role'],
                'org_name'   => $account['org_name'],
                'staff'      => [
                    'id'       => (int)$staff['id'],
                    'username' => $staff['username'],
                    'fullname' => $staff['fullname'],
                    'role'     => $staff['role'],
                ],
            ];
        }

        return [
            'id'       => (int)$account['id'],
            'authType' => 'account',
            'role'     => $account['role'],
            'email'    => $account['email'],
            'org_name' => $account['org_name'],
        ];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function accountId(): int
    {
        return (int)($_SESSION['account_id'] ?? 0);
    }

    public static function isTeacher(): bool
    {
        return ($_SESSION['auth_type'] ?? '') === 'teacher';
    }

    public static function teacherId(): int
    {
        return (int)($_SESSION['teacher_id'] ?? 0);
    }

    public static function isStaff(): bool
    {
        return ($_SESSION['auth_type'] ?? '') === 'staff';
    }

    public static function staffId(): int
    {
        return (int)($_SESSION['staff_id'] ?? 0);
    }

    public static function staffRole(): string
    {
        return (string)($_SESSION['staff_role'] ?? '');
    }

    public static function isStaffAdmin(): bool
    {
        return self::isStaff() && self::staffRole() === 'admin';
    }

    public static function isSupervisor(): bool
    {
        return self::isStaff() && self::staffRole() === 'supervisor';
    }

    public static function isOwner(): bool
    {
        return ($_SESSION['role'] ?? '') === 'owner';
    }

    public static function isCustomer(): bool
    {
        return ($_SESSION['role'] ?? '') === 'customer';
    }

    /**
     * Tenant scope: null = owner (all data); otherwise the account_id the
     * customer/teacher may see.
     */
    public static function scope(): ?int
    {
        $user = self::user();
        if ($user === null) {
            return 0;
        }
        return $user['role'] === 'owner' ? null : (int)$user['id'];
    }

    /**
     * Build a SQL tenant filter for a table alias. Returns '' for the owner.
     * Example: Auth::accountFilterSql('ss') -> "ss.account_id = 12".
     *
     * For a supervisor this becomes a course-level restriction built from
     * course_access (see supervisorFilterSql).
     */
    public static function accountFilterSql(string $alias): string
    {
        if (self::isSupervisor()) {
            return self::supervisorFilterSql($alias);
        }
        $scope = self::scope();
        if ($scope === null) {
            return '';
        }
        if ($scope <= 0) {
            return '0=1';
        }
        return "$alias.account_id = $scope";
    }

    /**
     * Course ids the logged-in supervisor may see (empty = none).
     * Cached for the duration of the request.
     */
    public static function supervisorCourseIds(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $rows = Database::fetchAll(
            'SELECT moodle_course_id FROM course_access WHERE account_id = ? AND user_id = ?',
            [self::accountId(), self::staffId()]
        );
        $cache = array_map(fn($r) => (int)$r['moodle_course_id'], $rows);
        return $cache;
    }

    /** "(1,2,3)" for the granted course ids, or "(0)" when none. */
    public static function supervisorCoursesInSql(): string
    {
        $ids = self::supervisorCourseIds();
        if ($ids === []) {
            return '(0)';
        }
        return '(' . implode(',', $ids) . ')';
    }

    public static function supervisorHasCourse(int $courseId): bool
    {
        return in_array($courseId, self::supervisorCourseIds(), true);
    }

    /**
     * Course-restricted scope for a supervisor, per table alias.
     *   e / ev / c / ct  -> moodle_course_id on the row itself
     *   ss               -> sessions of exams in the granted courses
     *   s / st           -> students having any session in the granted courses
     *   t                -> teachers assigned to the granted courses
     */
    private static function supervisorFilterSql(string $alias): string
    {
        $in  = self::supervisorCoursesInSql();
        $acc = self::accountId();

        return match ($alias) {
            'e', 'ev', 'c', 'ct' =>
                "$alias.moodle_course_id IN $in",
            't' =>
                "$alias.moodle_teacher_id IN (
                    SELECT ct.moodle_teacher_id FROM course_teachers ct
                     WHERE ct.account_id = $acc AND ct.moodle_course_id IN $in)",
            'ss' =>
                "$alias.account_id = $acc AND $alias.exam_id IN (
                    SELECT e2.id FROM exams e2
                     WHERE e2.account_id = $acc AND e2.moodle_course_id IN $in)",
            's', 'st' =>
                "$alias.id IN (
                    SELECT DISTINCT ssx.student_id FROM session_summaries ssx
                     WHERE ssx.account_id = $acc AND ssx.exam_id IN (
                        SELECT e3.id FROM exams e3
                         WHERE e3.account_id = $acc AND e3.moodle_course_id IN $in))",
            default =>
                "$alias.account_id = $acc",
        };
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function verifyCsrf(): bool
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($header)
            && $header !== ''
            && hash_equals(self::csrfToken(), $header);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        self::start();
        if (!self::check()) {
            Response::error('غير مصرح لك، سجّل الدخول أولاً', 401);
        }
    }

    public static function requireOwner(): void
    {
        self::requireLogin();
        if (!self::isOwner()) {
            Response::error('ليس لديك صلاحية لهذا الإجراء', 403);
        }
    }

    /** @deprecated owner-only alias for old supervisor-management endpoints. */
    public static function requireAdmin(): void
    {
        self::requireOwner();
    }

    public static function requireTeacher(): void
    {
        self::requireLogin();
        if ((self::isTeacher() && self::teacherId() > 0) || self::isOwner() || self::isCustomer() || self::isStaffAdmin()) {
            return;
        }
        Response::error('هذا المسار مخصص لحساب المدرّس فقط', 403);
    }

    /**
     * SQL filter that restricts rows to the courses the logged-in teacher is
     * assigned to. Builds an IN (…) over the teacher's course ids, or 0=1.
     * Expects the exams/courses alias to be `{alias}.moodle_course_id`.
     */
    public static function teacherCoursesSql(string $alias, int $accountId, int $teacherId): string
    {
        if ($teacherId <= 0) {
            return '0=1';
        }
        $courses = Database::fetchAll(
            'SELECT moodle_course_id FROM course_teachers WHERE account_id = ? AND moodle_teacher_id = ?',
            [$accountId, $teacherId]
        );
        $ids = array_map(fn($r) => (int)$r['moodle_course_id'], $courses);
        if ($ids === []) {
            return '0=1';
        }
        return $alias . '.moodle_course_id IN (' . implode(',', $ids) . ')';
    }

    /** Does the given teacher own this exam (course assignment)? */
    public static function teacherOwnsExam(int $accountId, int $teacherId, array $exam): bool
    {
        if (self::isOwner()) {
            return true;
        }

        // 1. Direct teacher assignment on exam record
        if ((int)($exam['moodle_teacher_id'] ?? 0) === $teacherId && $teacherId > 0) {
            return true;
        }

        // 2. Course-level assignment
        $mCourseId = (int)($exam['moodle_course_id'] ?? 0);
        $teacherCourseIds = Teachers::courseIds($accountId, $teacherId);
        if ($mCourseId > 0) {
            if (in_array($mCourseId, $teacherCourseIds, true)) {
                return true;
            }
            // Check if course_teachers has this course by moodle_course_id or internal id
            $count = (int)Database::scalar(
                'SELECT COUNT(*) FROM course_teachers ct
                  WHERE ct.moodle_teacher_id = ?
                    AND (
                      ct.moodle_course_id = ?
                      OR ct.moodle_course_id IN (SELECT c.moodle_course_id FROM courses c WHERE (c.id = ? OR c.moodle_course_id = ?))
                      OR ct.moodle_course_id IN (SELECT c.id FROM courses c WHERE (c.id = ? OR c.moodle_course_id = ?))
                    )',
                [$teacherId, $mCourseId, $mCourseId, $mCourseId, $mCourseId, $mCourseId]
            );
            if ($count > 0) {
                return true;
            }
        }

        // 3. Fallback: check if any event for this exam has this teacher or teacher's courses
        $quizId = (int)($exam['moodle_quiz_id'] ?? 0);
        $examId = (int)($exam['id'] ?? 0);
        $courseIn = empty($teacherCourseIds) ? '0' : implode(',', array_map('intval', $teacherCourseIds));
        $evCount = (int)Database::scalar(
            "SELECT COUNT(*) FROM events ev
              WHERE (ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?)
                AND (
                  ev.moodle_course_id IN ($courseIn)
                  OR ev.payload LIKE ?
                )",
            [$quizId, $examId, "%\"id\":$teacherId%"]
        );
        if ($evCount > 0) {
            return true;
        }

        // 4. Default: teacher does not own this exam
        return false;
    }

    /**
     * Tenant row check: the owner may access anything; a customer may only
     * access rows whose account_id matches theirs. A supervisor must be
     * within their granted courses too.
     */
    public static function requireRowAccess(array $row, string $accountCol = 'account_id'): void
    {
        self::requireLogin();
        if (self::isOwner()) {
            return;
        }
        if ((int)($row[$accountCol] ?? 0) !== self::accountId()) {
            Response::error('ليس لديك صلاحية لعرض هذه البيانات', 403);
        }
        if (self::isSupervisor()) {
            self::assertSupervisorRow($row);
        }
    }

    /** Row-level course check for a supervisor (see supervisorCourseIds). */
    private static function assertSupervisorRow(array $row): void
    {
        $acc = self::accountId();

        // courses / exams / course_teachers / events rows carry the course id.
        if (isset($row['moodle_course_id'])) {
            if (!self::supervisorHasCourse((int)$row['moodle_course_id'])) {
                Response::error('ليس لديك صلاحية لعرض هذه البيانات', 403);
            }
            return;
        }

        // session_summaries rows carry exam_id.
        if (isset($row['exam_id'])) {
            $courseId = (int)Database::scalar(
                'SELECT moodle_course_id FROM exams WHERE id = ? AND account_id = ?',
                [(int)$row['exam_id'], $acc]
            );
            if (!self::supervisorHasCourse($courseId)) {
                Response::error('ليس لديك صلاحية لعرض هذه البيانات', 403);
            }
            return;
        }

        // students rows (moodle_user_id, no exam context).
        if (isset($row['moodle_user_id'])) {
            $in = self::supervisorCoursesInSql();
            $cnt = (int)Database::scalar(
                'SELECT COUNT(*) FROM session_summaries ss
                   JOIN exams e ON e.id = ss.exam_id AND e.account_id = ss.account_id
                  WHERE ss.student_id = ? AND e.account_id = ? AND e.moodle_course_id IN ' . $in,
                [(int)$row['id'], $acc]
            );
            if ($cnt === 0) {
                Response::error('ليس لديك صلاحية لعرض هذه البيانات', 403);
            }
            return;
        }

        // teachers rows (moodle_teacher_id).
        if (isset($row['moodle_teacher_id'])) {
            $in = self::supervisorCoursesInSql();
            $cnt = (int)Database::scalar(
                'SELECT COUNT(*) FROM course_teachers ct
                  WHERE ct.account_id = ? AND ct.moodle_teacher_id = ? AND ct.moodle_course_id IN ' . $in,
                [$acc, (int)$row['moodle_teacher_id']]
            );
            if ($cnt === 0) {
                Response::error('ليس لديك صلاحية لعرض هذه البيانات', 403);
            }
            return;
        }
    }

    /**
     * Account management guard: the university account holder (customer) or an
     * admin staff member may manage the account's staff. Supervisors and the
     * platform owner are excluded (the owner manages accounts globally).
     */
    public static function requireAccountAdmin(): void
    {
        self::requireLogin();
        $role = (string)($_SESSION['role'] ?? '');
        if ($role === 'customer' || $role === 'owner' || self::isStaffAdmin()) {
            return;
        }
        Response::error('ليس لديك صلاحية لإدارة الموظفين', 403);
    }

    public static function guardStateChangingRequest(): void
    {
        if (!self::verifyCsrf()) {
            Response::error('رمز الحماية غير صالح', 403);
        }
    }
}
