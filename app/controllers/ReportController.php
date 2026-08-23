<?php
/**
 * Reports endpoints (CSV export + report data) — tenant-scoped.
 */
final class ReportController
{
    /** Full report payload for the printable web report. */
    public static function examReport(int $id): void
    {
        Auth::requireLogin();

        $exam = Database::fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        if (!$exam) {
            Response::error('الامتحان غير موجود', 404);
        }
        Auth::requireRowAccess($exam);

        $students = Analytics::examStudents($id);

        usort($students, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        $summary = [
            'total_students' => count($students),
            'teacher_id' => isset($exam['moodle_teacher_id']) ? (int)$exam['moodle_teacher_id'] : null,
            'teacher_name' => $exam['teacher_name'] ?? '',
            'suspicious' => count(array_filter($students, fn($s) => $s['risk_level'] === 'high' || $s['risk_level'] === 'critical')),
            'high' => count(array_filter($students, fn($s) => $s['risk_level'] === 'high')),
            'critical' => count(array_filter($students, fn($s) => $s['risk_level'] === 'critical')),
            'medium' => count(array_filter($students, fn($s) => $s['risk_level'] === 'medium')),
            'low' => count(array_filter($students, fn($s) => $s['risk_level'] === 'low')),
            'safe' => count(array_filter($students, fn($s) => $s['risk_level'] === 'safe')),
            'total_events' => array_sum(array_column($students, 'event_count')),
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];

        Response::ok([
            'exam' => $exam,
            'summary' => $summary,
            'students' => $students,
        ]);
    }

    /** CSV download of the per-student report. */
    public static function examCsv(int $id): void
    {
        Auth::requireLogin();

        $exam = Database::fetchOne('SELECT * FROM exams WHERE id = ?', [$id]);
        if (!$exam) {
            Response::error('الامتحان غير موجود', 404);
        }
        Auth::requireRowAccess($exam);

        $students = Analytics::examStudents($id);
        usort($students, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        $filename = 'exam_' . (int)$id . '_report_' . gmdate('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'الطالب', 'اسم المستخدم', 'الجلسات', 'الأحداث',
            'إخفاء التبويب', 'مدة الإخفاء (ث)', 'نسخ', 'حروف منسوخة', 'لصق',
            'نقر يمين', 'فقدان التركيز', 'مغادرة الصفحة', 'انقطاع النت',
            'تغيير إجابة', 'أدوات مطور', 'مفاتيح مشبوهة', 'لقطات شاشة', 'تغييرات سريعة',
            'خمول', 'مدة الخمول (ث)', 'خروج ملء الشاشة', 'كتابة', 'مسح',
            'نقرات', 'حركة فأرة', 'تمرير', 'أخرى', 'درجة المخاطر', 'مستوى المخاطر',
            'أول حدث', 'آخر حدث',
        ]);

        foreach ($students as $s) {
            fputcsv($out, [
                $s['fullname'],
                $s['username'],
                $s['sessions_count'],
                $s['event_count'],
                $s['tab_hidden_count'],
                round($s['tab_hidden_duration_ms'] / 1000, 1),
                $s['copy_count'],
                $s['copy_selection_chars'],
                $s['paste_count'],
                $s['right_click_count'],
                $s['blur_count'],
                $s['page_leave_count'],
                $s['offline_count'],
                $s['answer_changed_count'],
                $s['devtools_count'],
                $s['suspicious_key_count'],
                $s['screenshot_count'],
                $s['rapid_answer_changes'],
                $s['idle_count'],
                round($s['idle_duration_ms'] / 1000, 1),
                $s['fullscreen_exit_count'],
                $s['typing_keydown_count'],
                $s['typing_backspace_count'],
                $s['mouse_click_count'],
                $s['mouse_move_count'],
                $s['mouse_scroll_count'],
                $s['other_count'],
                $s['risk_score'],
                $s['risk_label'],
                $s['first_event_at'],
                $s['last_event_at'],
            ]);
        }
        fclose($out);
        exit;
    }
}