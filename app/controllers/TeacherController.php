<?php
/**
 * Teacher endpoints (tenant-scoped by account).
 */
final class TeacherController
{
    /** List all synced teachers with per-teacher aggregates. */
    public static function list(): void
    {
        Auth::requireLogin();
        $scopeT = Auth::accountFilterSql('t');

        $rows = Database::fetchAll(
            "SELECT t.moodle_teacher_id, t.fullname, t.username, t.first_seen_at, t.last_seen_at,
                    (SELECT COUNT(*) FROM course_teachers ct WHERE ct.moodle_teacher_id = t.moodle_teacher_id AND ct.account_id = t.account_id) AS courses_count,
                    (SELECT COUNT(DISTINCT e.id)
                       FROM course_teachers ct2
                       JOIN exams e ON e.moodle_course_id = ct2.moodle_course_id AND e.account_id = ct2.account_id
                      WHERE ct2.moodle_teacher_id = t.moodle_teacher_id AND ct2.account_id = t.account_id) AS exams_count,
                    (SELECT COUNT(DISTINCT ss.student_id)
                       FROM course_teachers ct3
                       JOIN exams e2 ON e2.moodle_course_id = ct3.moodle_course_id AND e2.account_id = ct3.account_id
                       JOIN session_summaries ss ON ss.exam_id = e2.id AND ss.account_id = e2.account_id
                      WHERE ct3.moodle_teacher_id = t.moodle_teacher_id AND ct3.account_id = t.account_id) AS students_count
               FROM teachers t"
            . ($scopeT ? ' WHERE ' . $scopeT : '')
            . ' ORDER BY t.fullname, t.username LIMIT 500'
        );

        Response::ok(array_map(function ($r) {
            $r['moodle_teacher_id'] = (int)$r['moodle_teacher_id'];
            $r['courses_count'] = (int)$r['courses_count'];
            $r['exams_count'] = (int)$r['exams_count'];
            $r['students_count'] = (int)$r['students_count'];
            return $r;
        }, $rows));
    }

    /** Teacher detail: their courses and the exams within each. */
    public static function detail(int $teacherId): void
    {
        Auth::requireLogin();

        $scopeT = Auth::accountFilterSql('t');
        $whereT = $scopeT ? ('WHERE ' . $scopeT . ' AND ') : 'WHERE ';

        $teacher = Database::fetchOne(
            'SELECT * FROM teachers ' . $whereT . 'moodle_teacher_id = ?',
            [$teacherId]
        );
        if (!$teacher) {
            Response::error('المدرّس غير موجود', 404);
        }
        Auth::requireRowAccess($teacher);

        $scopeCT = Auth::accountFilterSql('ct');
        $scopeC  = Auth::accountFilterSql('c');
        $whereCT = $scopeCT ? ('WHERE ' . $scopeCT . ' AND ') : 'WHERE ';
        $whereC  = $scopeC ? ('WHERE ' . $scopeC . ' AND ') : 'WHERE ';

        // A supervisor only sees the teacher's courses inside their grants.
        $supervisorCourses = Auth::isSupervisor()
            ? (' AND c.moodle_course_id IN ' . Auth::supervisorCoursesInSql())
            : '';

        $courses = Database::fetchAll(
            "SELECT c.id, c.moodle_course_id, c.name,
                    (SELECT COUNT(*) FROM exams e WHERE e.moodle_course_id = c.moodle_course_id AND e.account_id = c.account_id) AS exams_count,
                    (SELECT COUNT(DISTINCT ss.student_id)
                       FROM exams e2
                       JOIN session_summaries ss ON ss.exam_id = e2.id AND ss.account_id = e2.account_id
                      WHERE e2.moodle_course_id = c.moodle_course_id AND e2.account_id = c.account_id) AS students_count
             FROM course_teachers ct
             JOIN courses c ON c.moodle_course_id = ct.moodle_course_id AND c.account_id = ct.account_id
             " . $whereCT . "ct.moodle_teacher_id = ?" . $supervisorCourses . "
             ORDER BY c.name",
            [$teacherId]
        );

        $courseIds = array_map(fn($r) => (int)$r['moodle_course_id'], $courses);
        $exams = [];
        if ($courseIds !== []) {
            $in = implode(',', array_map('intval', $courseIds));
            $scopeE = Auth::accountFilterSql('e');
            $whereE = $scopeE ? ('WHERE ' . $scopeE . ' AND ') : 'WHERE ';
            $exams = Database::fetchAll(
                "SELECT e.id, e.moodle_quiz_id, e.moodle_course_id, e.name, e.teacher_name,
                        e.status, e.first_event_at, e.last_event_at,
                        (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS students_count,
                        (SELECT COUNT(DISTINCT ss.session_id)  FROM session_summaries ss WHERE ss.exam_id = e.id AND ss.account_id = e.account_id) AS sessions_count,
                        (SELECT COUNT(*) FROM events ev WHERE ev.moodle_quiz_id = e.moodle_quiz_id AND ev.account_id = e.account_id) AS events_count,
                        (SELECT COUNT(DISTINCT ss.student_id) FROM session_summaries ss
                           WHERE ss.exam_id = e.id AND ss.account_id = e.account_id AND ss.risk_level IN ('high','critical')) AS suspicious_count
                 FROM exams e
                 " . $whereE . "e.moodle_course_id IN ($in)
                 ORDER BY e.last_event_at DESC"
            );
        }

        Response::ok([
            'teacher' => [
                'moodle_teacher_id' => (int)$teacher['moodle_teacher_id'],
                'fullname' => $teacher['fullname'],
                'username' => $teacher['username'],
                'first_seen_at' => $teacher['first_seen_at'],
                'last_seen_at' => $teacher['last_seen_at'],
            ],
            'courses' => array_map(function ($r) {
                $r['id'] = (int)$r['id'];
                $r['moodle_course_id'] = (int)$r['moodle_course_id'];
                $r['exams_count'] = (int)$r['exams_count'];
                $r['students_count'] = (int)$r['students_count'];
                return $r;
            }, $courses),
            'exams' => array_map(function ($r) {
                $r['id'] = (int)$r['id'];
                $r['moodle_quiz_id'] = (int)$r['moodle_quiz_id'];
                $r['moodle_course_id'] = (int)$r['moodle_course_id'];
                $r['students_count'] = (int)$r['students_count'];
                $r['sessions_count'] = (int)$r['sessions_count'];
                $r['events_count'] = (int)$r['events_count'];
                $r['suspicious_count'] = (int)$r['suspicious_count'];
                return $r;
            }, $exams),
        ]);
    }
}