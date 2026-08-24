<?php
/**
 * POST /api/sync - the Moodle plugin pushes real-time lifecycle events here
 * (course_created, quiz_created, user_created, role_assigned). The request is
 * authenticated by the account's api_secret so each event is attributed to
 * the right tenant and expired trials are hard-rejected.
 *
 * Payload shape (from the plugin observers):
 *   { "secret": "…", "type": "course_created", "data": { … } }
 */
final class SyncController
{
    /** Resolve the account from the body secret, or reject (403). */
    private static function resolveAccount(array $body): array
    {
        $secret = (string)($body['secret'] ?? '');
        $account = Accounts::resolveBySecret($secret);
        if ($account === null) {
            Response::error('مفتاح المزامنة غير صحيح أو الحساب غير نشط', 403);
        }
        // Domain binding: reject requests from any site other than the one
        // this account is bound to.
        $siteUrl = (string)($body['site_url'] ?? '');
        if ($siteUrl !== '' && !Accounts::siteAllowed((int)$account['id'], $siteUrl)) {
            Response::error('هذا النطاق غير مرتبط بحسابك', 403);
        }
        return $account;
    }

    public static function ingest(): void
    {
        $body = em_body_json();
        if (!is_array($body)) {
            Response::error('Body غير صالح', 400);
        }

        $account = self::resolveAccount($body);

        $type = (string)($body['type'] ?? '');
        $data = (is_array($body['data'] ?? null)) ? $body['data'] : [];

        switch ($type) {
            case 'course_created':
                self::courseCreated($data, (int)$account['id']);
                break;
            case 'quiz_created':
                self::quizCreated($data, (int)$account['id']);
                break;
            case 'user_created':
                self::userCreated($data, (int)$account['id']);
                break;
            case 'user_enrolment_deleted':
                self::userEnrolmentDeleted($data, (int)$account['id']);
                break;
            case 'user_deleted':
                self::userDeleted($data, (int)$account['id']);
                break;
            case 'role_assigned':
                self::roleAssigned($data, (int)$account['id']);
                break;
            case 'role_unassigned':
                self::roleUnassigned($data, (int)$account['id']);
                break;
            default:
                Response::error('نوع حدث غير معروف', 422);
        }

        Response::ok(['ok' => true, 'type' => $type]);
    }

    /**
     * POST /api/sync/bulk — Initial full sync from Moodle.
     * Accepts { secret, site_url, courses:[], teachers:[], students:[], quizzes:[] }.
     */
    public static function bulkSync(): void
    {
        $body = em_body_json();
        if (!is_array($body)) {
            file_put_contents(__DIR__ . '/../../sync_debug.log', date('Y-m-d H:i:s') . " - Error: Invalid body\n", FILE_APPEND);
            Response::error('Body غير صالح', 400);
        }

        // --- DEBUG LOGGING ---
        $debugData = "SYNC RECEIVED at " . date('Y-m-d H:i:s') . "\n";
        $debugData .= "Has courses: " . isset($body['courses']) . "\n";
        $debugData .= "Has enrollments: " . isset($body['enrollments']) . "\n";
        $debugData .= "Has student_enrollments: " . isset($body['student_enrollments']) . "\n";
        if (isset($body['student_enrollments'])) {
            $debugData .= "Count student_enrollments: " . count($body['student_enrollments']) . "\n";
        }
        file_put_contents(__DIR__ . '/../../sync_debug.log', $debugData . "\n", FILE_APPEND);
        // ---------------------

        $account = self::resolveAccount($body);
        $accountId = (int)$account['id'];

        $synced = ['courses' => 0, 'teachers' => 0, 'students' => 0, 'quizzes' => 0, 'enrollments' => 0];

        // 1. Courses
        $courses = $body['courses'] ?? [];
        foreach ($courses as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) continue;
            $name = em_truncate((string)($c['fullname'] ?? $c['name'] ?? ''), 255);
            Database::execute(
                'INSERT INTO courses (account_id, moodle_course_id, name) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = VALUES(account_id),
                   name = IF(name = "", VALUES(name), name)',
                [$accountId, $cid, $name]
            );
            $synced['courses']++;
        }

        // 2. Teachers
        $teachers = $body['teachers'] ?? [];
        foreach ($teachers as $t) {
            $tid = (int)($t['id'] ?? 0);
            if ($tid <= 0) continue;
            $fullname = em_truncate((string)($t['fullname'] ?? ''), 190);
            $username = em_truncate((string)($t['username'] ?? ''), 190);
            Database::execute(
                'INSERT INTO teachers (moodle_teacher_id, account_id, fullname, username, first_seen_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   account_id = VALUES(account_id),
                   fullname = VALUES(fullname),
                   username = VALUES(username)',
                [$tid, $accountId, $fullname, $username]
            );
            Teachers::setDefaultPassword($username, $tid, $accountId);
            $synced['teachers']++;
        }

        // 3. Students
        $students = $body['students'] ?? [];
        foreach ($students as $s) {
            $sid = (int)($s['id'] ?? 0);
            if ($sid <= 0) continue;
            $fullname = em_truncate((string)($s['fullname'] ?? ''), 190);
            $username = em_truncate((string)($s['username'] ?? ''), 190);
            Database::execute(
                'INSERT INTO students (moodle_user_id, account_id, fullname, username, first_seen_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   account_id = VALUES(account_id),
                   fullname = VALUES(fullname),
                   username = VALUES(username)',
                [$sid, $accountId, $fullname, $username]
            );
            $synced['students']++;
        }

        // 4. Quizzes → exams
        $quizzes = $body['quizzes'] ?? [];
        foreach ($quizzes as $q) {
            $qid = (int)($q['id'] ?? 0);
            $cid = (int)($q['course'] ?? 0);
            $cmid = (int)($q['cmid'] ?? 0);
            if ($qid <= 0) continue;
            $name = em_truncate((string)($q['name'] ?? ''), 255);
            $teacherId = (int)($q['teacher_id'] ?? 0);
            $teacherName = em_truncate((string)($q['teacher_name'] ?? ''), 255);
            $durationMin = max(0, (int)($q['duration_minutes'] ?? 0));
            Database::execute(
                'INSERT INTO exams (account_id, moodle_quiz_id, moodle_course_id, moodle_cmid, name, moodle_teacher_id, teacher_name, duration_minutes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = VALUES(account_id),
                   moodle_cmid = GREATEST(moodle_cmid, VALUES(moodle_cmid)),
                   moodle_teacher_id = IFNULL(moodle_teacher_id, VALUES(moodle_teacher_id)),
                   teacher_name = IF(teacher_name = "", VALUES(teacher_name), teacher_name),
                   name = IF(name = "", VALUES(name), name),
                   duration_minutes = IF(VALUES(duration_minutes) > 0, VALUES(duration_minutes), duration_minutes)',
                [$accountId, $qid, $cid, $cmid, $name, $teacherId ?: null, $teacherName, $durationMin]
            );
            $synced['quizzes']++;
        }

        // 5. Teacher <-> Course assignments
        $enrollments = $body['enrollments'] ?? [];
        if (!empty($enrollments)) {
            Database::execute('DELETE FROM course_teachers WHERE account_id = ?', [$accountId]);
        }
        foreach ($enrollments as $e) {
            $cid = (int)($e['course_id'] ?? 0);
            $tid = (int)($e['teacher_id'] ?? 0);
            if ($cid <= 0 || $tid <= 0) continue;
            $tname = em_truncate((string)($e['teacher_name'] ?? ''), 255);
            Database::execute(
                'INSERT INTO course_teachers (moodle_course_id, moodle_teacher_id, account_id, teacher_name)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = VALUES(account_id),
                   teacher_name = VALUES(teacher_name)',
                [$cid, $tid, $accountId, $tname]
            );
            $synced['enrollments']++;
        }

        // 6. Student <-> Course assignments
        $student_enrollments = $body['student_enrollments'] ?? [];
        if (!empty($student_enrollments)) {
            Database::execute('DELETE FROM course_students WHERE account_id = ?', [$accountId]);
        }
        $synced['student_enrollments'] = 0;
        foreach ($student_enrollments as $se) {
            $cid = (int)($se['course_id'] ?? 0);
            $sid = (int)($se['student_id'] ?? 0);
            if ($cid <= 0 || $sid <= 0) continue;
            $sname = em_truncate((string)($se['student_name'] ?? ''), 255);
            Database::execute(
                'INSERT INTO course_students (moodle_course_id, student_id, account_id, student_name)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = VALUES(account_id),
                   student_name = VALUES(student_name)',
                [$cid, $sid, $accountId, $sname]
            );
            $synced['student_enrollments']++;
        }

        // Clean up students who are no longer enrolled in any course and have no exam session records
        Database::execute(
            'DELETE FROM students 
              WHERE account_id = ? 
                AND moodle_user_id NOT IN (SELECT DISTINCT student_id FROM course_students WHERE account_id = ?)
                AND id NOT IN (SELECT DISTINCT student_id FROM course_students WHERE account_id = ?)
                AND id NOT IN (SELECT DISTINCT student_id FROM session_summaries WHERE account_id = ?)',
            [$accountId, $accountId, $accountId, $accountId]
        );

        Response::ok(['ok' => true, 'synced' => $synced]);
    }

    /**
     * POST /register-teacher - legacy/manual teacher registration used by
     * the plugin's teacher.php page (single combined payload).
     */
    public static function registerTeacher(): void
    {
        $body = em_body_json();
        if (!is_array($body)) {
            Response::error('Body غير صالح', 400);
        }

        $account = self::resolveAccount($body);
        $accountId = (int)$account['id'];

        $teacherId = (int)($body['teacher_id'] ?? 0);
        $teacherName = em_truncate((string)($body['teacher_name'] ?? ''), 255);
        $username = em_truncate((string)($body['username'] ?? ''), 190);
        $courseId = (int)($body['course_id'] ?? 0);
        $courseName = em_truncate((string)($body['course_name'] ?? ''), 255);
        $quizId = (int)($body['quiz_id'] ?? 0);
        $quizName = em_truncate((string)($body['quiz_name'] ?? ''), 255);
        $cmid = (int)($body['cmid'] ?? 0);

        if ($teacherId <= 0) {
            Response::error('معرف المدرس مطلوب', 422);
        }

        // Teacher record.
        Database::execute(
            'INSERT INTO teachers (moodle_teacher_id, account_id, fullname, username, first_seen_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               fullname = VALUES(fullname),
               username = VALUES(username),
               last_seen_at = NOW()',
            [$teacherId, $accountId, $teacherName, $username]
        );

        // Set default password if the teacher has no password yet.
        Teachers::setDefaultPassword($username, $teacherId, $accountId);

        // Course record (so the course shows up even before any exam events).
        if ($courseId > 0) {
            Database::execute(
                'INSERT INTO courses (account_id, moodle_course_id, name) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = IF(account_id = 0, VALUES(account_id), account_id),
                   name = IF(name = "", VALUES(name), name)',
                [$accountId, $courseId, $courseName]
            );
        }

        // Exam record.
        if ($quizId > 0) {
            Database::execute(
                'INSERT INTO exams (account_id, moodle_quiz_id, moodle_course_id, moodle_cmid, name, moodle_teacher_id, teacher_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = IF(account_id = 0, VALUES(account_id), account_id),
                   moodle_cmid = GREATEST(moodle_cmid, VALUES(moodle_cmid)),
                   moodle_teacher_id = IFNULL(moodle_teacher_id, VALUES(moodle_teacher_id)),
                   teacher_name = IF(teacher_name = "", VALUES(teacher_name), teacher_name),
                   name = IF(name = "", VALUES(name), name)',
                [$accountId, $quizId, $courseId, $cmid, $quizName, $teacherId, $teacherName]
            );
        }

        // Teacher <-> course assignment.
        if ($courseId > 0) {
            Database::execute(
                'INSERT INTO course_teachers (moodle_course_id, moodle_teacher_id, account_id, teacher_name)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   account_id = IF(account_id = 0, VALUES(account_id), account_id),
                   teacher_name = IF(teacher_name = "", VALUES(teacher_name), teacher_name)',
                [$courseId, $teacherId, $accountId, $teacherName]
            );
        }

        Response::ok(['ok' => true]);
    }

    private static function courseCreated(array $d, int $accountId): void
    {
        $courseId = (int)($d['id'] ?? 0);
        if ($courseId <= 0) {
            return;
        }
        $name = em_truncate((string)($d['fullname'] ?? $d['name'] ?? ''), 255);
        Database::execute(
            'INSERT INTO courses (account_id, moodle_course_id, name) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               name = IF(name = "", VALUES(name), name)',
            [$accountId, $courseId, $name]
        );
    }

    private static function quizCreated(array $d, int $accountId): void
    {
        $quizId = (int)($d['id'] ?? 0);
        $courseId = (int)($d['course'] ?? 0);
        $cmid = (int)($d['cmid'] ?? 0);
        if ($quizId <= 0) {
            return;
        }
        $name = em_truncate((string)($d['name'] ?? ''), 255);
        Database::execute(
            'INSERT INTO exams (account_id, moodle_quiz_id, moodle_course_id, moodle_cmid, name)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               moodle_cmid = GREATEST(moodle_cmid, VALUES(moodle_cmid)),
               name = IF(name = "", VALUES(name), name)',
            [$accountId, $quizId, $courseId, $cmid, $name]
        );
        // The course this quiz belongs to may not exist yet.
        if ($courseId > 0) {
            Database::execute(
                'INSERT INTO courses (account_id, moodle_course_id, name) VALUES (?, ?, "")
                 ON DUPLICATE KEY UPDATE
                   account_id = IF(account_id = 0, VALUES(account_id), account_id),
                   id = LAST_INSERT_ID(id)',
                [$accountId, $courseId]
            );
        }
    }

    private static function userCreated(array $d, int $accountId): void
    {
        // Only sync users that have a teacher-like role.
        // Without a role archetype we cannot tell teacher from student, so skip.
        $archetype = (string)($d['archetype'] ?? '');
        if ($archetype !== '' && !in_array($archetype, ['teacher', 'editingteacher'], true)) {
            return;
        }
        $userId = (int)($d['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $fullname = em_truncate((string)($d['fullname'] ?? ''), 190);
        $username = em_truncate((string)($d['username'] ?? ''), 190);
        Database::execute(
            'INSERT INTO teachers (moodle_teacher_id, account_id, fullname, username, first_seen_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               fullname = IF(fullname = "", VALUES(fullname), fullname),
               username = IF(username = "", VALUES(username), username)',
            [$userId, $accountId, $fullname, $username]
        );

        // Set default password for new teacher.
        Teachers::setDefaultPassword($username, $userId, $accountId);
    }

    private static function roleAssigned(array $d, int $accountId): void
    {
        $userId = (int)($d['userid'] ?? 0);
        $courseId = (int)($d['courseid'] ?? 0);
        $roleArchetype = (string)($d['archetype'] ?? '');
        if ($userId <= 0 || $courseId <= 0) {
            return;
        }
        // Only teacher-style roles matter for the platform.
        if ($roleArchetype !== '' && !in_array($roleArchetype, ['teacher', 'editingteacher'], true)) {
            return;
        }

        $fullname = em_truncate((string)($d['fullname'] ?? ''), 255);
        $username = em_truncate((string)($d['username'] ?? ''), 190);

        Database::execute(
            'INSERT INTO teachers (moodle_teacher_id, account_id, fullname, username, first_seen_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               fullname = IF(fullname = "", VALUES(fullname), fullname),
               username = IF(username = "", VALUES(username), username),
               last_seen_at = NOW()',
            [$userId, $accountId, $fullname, $username]
        );

        // Set default password for new teacher.
        Teachers::setDefaultPassword($username, $userId, $accountId);

        Database::execute(
            'INSERT INTO course_teachers (moodle_course_id, moodle_teacher_id, account_id, teacher_name)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               account_id = IF(account_id = 0, VALUES(account_id), account_id),
               teacher_name = IF(teacher_name = "", VALUES(teacher_name), teacher_name)',
            [$courseId, $userId, $accountId, $fullname]
        );

        // Attach the teacher to any exams already known for this course.
        Database::execute(
            'UPDATE exams
                SET moodle_teacher_id = IFNULL(moodle_teacher_id, ?),
                    teacher_name = IF(teacher_name = "", ?, teacher_name)
              WHERE account_id = ? AND moodle_course_id = ? AND teacher_name = ""',
            [$userId, $fullname, $accountId, $courseId]
        );
    }

    private static function userEnrolmentDeleted(array $d, int $accountId): void
    {
        $userId = (int)($d['userid'] ?? 0);
        $courseId = (int)($d['courseid'] ?? 0);
        if ($userId <= 0 || $courseId <= 0) {
            return;
        }

        Database::execute(
            'DELETE FROM course_students 
              WHERE account_id = ? 
                AND moodle_course_id = ? 
                AND (student_id = ? OR student_id = (SELECT moodle_user_id FROM students WHERE id = ? AND account_id = ? LIMIT 1))',
            [$accountId, $courseId, $userId, $userId, $accountId]
        );
    }

    private static function userDeleted(array $d, int $accountId): void
    {
        $userId = (int)($d['id'] ?? $d['userid'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        Database::execute(
            'DELETE FROM course_students WHERE account_id = ? AND student_id = ?',
            [$accountId, $userId]
        );

        Database::execute(
            'DELETE FROM students WHERE account_id = ? AND (moodle_user_id = ? OR id = ?)',
            [$accountId, $userId, $userId]
        );

        Database::execute(
            'DELETE FROM teachers WHERE account_id = ? AND moodle_teacher_id = ?',
            [$accountId, $userId]
        );
    }

    private static function roleUnassigned(array $d, int $accountId): void
    {
        $userId = (int)($d['userid'] ?? 0);
        $courseId = (int)($d['courseid'] ?? 0);
        if ($userId <= 0 || $courseId <= 0) {
            return;
        }

        Database::execute(
            'DELETE FROM course_teachers WHERE account_id = ? AND moodle_teacher_id = ? AND moodle_course_id = ?',
            [$accountId, $userId, $courseId]
        );
    }

    /**
     * POST /api/sync/trigger — Trigger a full sync from Moodle.
     * The backend calls the Moodle plugin's sync_api.php with the account's
     * api_secret, which reads all data from Moodle DB and pushes it here.
     */
    public static function triggerSync(): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        if ($accountId <= 0) {
            Response::error('غير مصرح', 401);
        }

        $account = Accounts::findById($accountId);
        if ($account === null) {
            Response::error('الحساب غير موجود', 404);
        }

        $apiSecret = (string)($account['api_secret'] ?? '');
        if ($apiSecret === '') {
            Response::error('المفتاح السري غير موجود');
        }

        $siteDomain = (string)($account['site_domain'] ?? '');

        if ($siteDomain === '') {
            $body = em_body_json() ?? [];
            $moodleUrl = (string)($body['moodle_url'] ?? '');
            if ($moodleUrl !== '') {
                $moodleUrl = rtrim(preg_replace('#^https?://#i', '', $moodleUrl), '/');
                Accounts::updateSiteDomain($accountId, $moodleUrl);
                $siteDomain = $moodleUrl;
            }
        }

        if ($siteDomain === '') {
            Response::error('أدخل رابط موقع المودل أولاً', 409);
        }

        $moodleUrl = 'https://' . $siteDomain . '/mod/quiz/accessrule/exammonitor/sync_api.php';

        $payload = json_encode(['secret' => $apiSecret], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($moodleUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $response === '') {
            Response::error('تعذر الاتصال بموقع مودل: ' . ($err ?: 'خطأ غير معروف'));
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded['ok'])) {
            Response::json($decoded);
        } else {
            $msg = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : 'خطأ غير معروف';
            Response::error('فشلت المزامنة من مودل: ' . $msg);
        }
    }
}
