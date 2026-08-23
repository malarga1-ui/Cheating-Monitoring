<?php
/**
 * Secret-authenticated endpoints used by the Moodle plugin to render stats
 * INSIDE Moodle (dashboard.php), so the teacher never has to leave the site.
 *
 *   POST /api/plugin/exam-stats
 *     { secret, site_url, teacher_id, quiz_id }
 *     -> { found, exam, course, counts, students }
 */
final class PluginStatsController
{
    public static function examStats(): void
    {
        $body = em_body_json();
        if (!is_array($body)) {
            Response::error('Body غير صالح', 400);
        }

        $account = self::resolveAccount($body);
        $accountId = (int)$account['id'];

        $teacherId = (int)($body['teacher_id'] ?? 0);
        $quizId = (int)($body['quiz_id'] ?? 0);
        if ($quizId <= 0) {
            Response::error('quiz_id مطلوب', 422);
        }

        $exam = Database::fetchOne(
            'SELECT * FROM exams WHERE account_id = ? AND moodle_quiz_id = ? ORDER BY id ASC LIMIT 1',
            [$accountId, $quizId]
        );
        if (!$exam) {
            // Quiz not synced yet — the plugin keeps monitoring; not an error.
            Response::ok(['found' => false, 'message' => 'لم يُسجَّل هذا الاختبار بعد']);
        }

        // Ownership: the requesting teacher must be assigned to this course.
        if ($teacherId > 0 && !Auth::teacherOwnsExam($accountId, $teacherId, $exam)) {
            Response::error('لا تملك صلاحية لعرض إحصائيات هذا الاختبار', 403);
        }

        $course = Database::fetchOne(
            'SELECT id, name, moodle_course_id FROM courses WHERE account_id = ? AND moodle_course_id = ?',
            [$accountId, (int)$exam['moodle_course_id']]
        );

        $students = Analytics::examStudents((int)$exam['id']);

        $counts = [
            'students' => count($students),
            'sessions' => 0,
            'events' => 0,
            'suspicious' => count(array_filter(
                $students,
                fn($s) => in_array($s['risk_level'], ['high', 'critical'], true)
            )),
        ];
        foreach ($students as $s) {
            $counts['sessions'] += $s['sessions_count'];
            $counts['events'] += $s['event_count'];
        }

        // Keep the payload compact for the Moodle page.
        $students = array_map(fn($s) => [
            'student_id'     => $s['student_id'],
            'fullname'       => $s['fullname'],
            'username'       => $s['username'],
            'risk_score'     => $s['risk_score'],
            'risk_level'     => $s['risk_level'],
            'risk_label'     => $s['risk_label'],
            'sessions_count' => $s['sessions_count'],
            'event_count'    => $s['event_count'],
            'tab_hidden_count' => $s['tab_hidden_count'],
            'paste_count'    => $s['paste_count'],
            'copy_count'     => $s['copy_count'],
            'devtools_count' => $s['devtools_count'],
            'screenshot_count' => $s['screenshot_count'],
            'blur_count'     => $s['blur_count'],
            'page_leave_count' => $s['page_leave_count'],
            'first_event_at' => $s['first_event_at'],
            'last_event_at'  => $s['last_event_at'],
        ], $students);

        usort($students, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        Response::ok([
            'found' => true,
            'exam' => [
                'id'         => (int)$exam['id'],
                'moodle_quiz_id' => (int)$exam['moodle_quiz_id'],
                'moodle_course_id' => (int)$exam['moodle_course_id'],
                'name'       => $exam['name'],
                'status'     => $exam['status'],
                'first_event_at' => $exam['first_event_at'],
                'last_event_at'  => $exam['last_event_at'],
            ],
            'course' => $course ? [
                'id' => (int)$course['id'],
                'name' => $course['name'],
                'moodle_course_id' => (int)$course['moodle_course_id'],
            ] : null,
            'counts' => $counts,
            'students' => $students,
        ]);
    }

    /** Resolve the account from the body secret, or reject (403). */
    private static function resolveAccount(array $body): array
    {
        $secret = (string)($body['secret'] ?? '');
        $account = Accounts::resolveBySecret($secret);
        if ($account === null) {
            Response::error('مفتاح الحساب غير صحيح أو الحساب غير نشط', 403);
        }
        $siteUrl = (string)($body['site_url'] ?? '');
        if ($siteUrl !== '' && !Accounts::siteAllowed((int)$account['id'], $siteUrl)) {
            Response::error('هذا النطاق غير مرتبط بحسابك', 403);
        }
        return $account;
    }
}
