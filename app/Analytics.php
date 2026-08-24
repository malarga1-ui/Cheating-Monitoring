<?php
/**
 * Shared analytics computations used by controllers and reports.
 */
final class Analytics
{
    /**
     * Per-student aggregated counters for an exam, with risk computed from
     * summed signals (accurate, not a per-session max).
     */
    public static function examStudents(int $examId): array
    {
        $exam = Database::fetchOne(
            'SELECT question_count, duration_minutes, account_id FROM exams WHERE id = ?',
            [$examId]
        );
        $questionCount = (int)($exam['question_count'] ?? 0);
        $examMinutes   = (int)($exam['duration_minutes'] ?? 0);
        $accountId     = (int)($exam['account_id'] ?? 0);

        $whereExtra = $accountId > 0 ? ' AND ss.account_id = ?' : '';
        $sqlParams = [$examId];
        if ($accountId > 0) { $sqlParams[] = $accountId; }

        $rows = Database::fetchAll(
            'SELECT ss.student_id,
                    COALESCE(st.fullname, ss.student_name, CONCAT("طالب #", ss.student_id)) AS fullname,
                    COALESCE(st.username, "") AS username,
                    COUNT(DISTINCT ss.session_id) AS sessions_count,
                    SUM(ss.event_count) AS event_count,
                    SUM(ss.tab_hidden_count) AS tab_hidden_count,
                    SUM(ss.tab_visible_count) AS tab_visible_count,
                    SUM(ss.tab_hidden_duration_ms) AS tab_hidden_duration_ms,
                    SUM(ss.copy_count) AS copy_count,
                    SUM(ss.copy_selection_chars) AS copy_selection_chars,
                    SUM(ss.paste_count) AS paste_count,
                    SUM(ss.right_click_count) AS right_click_count,
                    SUM(ss.blur_count) AS blur_count,
                    SUM(ss.focus_count) AS focus_count,
                    SUM(ss.page_leave_count) AS page_leave_count,
                    SUM(ss.offline_count) AS offline_count,
                    SUM(ss.answer_changed_count) AS answer_changed_count,
                    SUM(ss.devtools_count) AS devtools_count,
                    SUM(ss.suspicious_key_count) AS suspicious_key_count,
                    SUM(ss.screenshot_count) AS screenshot_count,
                    SUM(ss.rapid_answer_changes) AS rapid_answer_changes,
                    SUM(ss.idle_count) AS idle_count,
                    SUM(ss.idle_duration_ms) AS idle_duration_ms,
                    SUM(ss.fullscreen_exit_count) AS fullscreen_exit_count,
                    SUM(ss.typing_keydown_count) AS typing_keydown_count,
                    SUM(ss.typing_backspace_count) AS typing_backspace_count,
                    SUM(ss.typing_enter_count) AS typing_enter_count,
                    SUM(ss.mouse_click_count) AS mouse_click_count,
                    SUM(ss.mouse_move_count) AS mouse_move_count,
                    SUM(ss.mouse_scroll_count) AS mouse_scroll_count,
                    SUM(ss.other_count) AS other_count,
                    MIN(ss.first_event_at) AS first_event_at,
                    MAX(ss.last_event_at) AS last_event_at,
                    MAX(ss.same_ip_student_count) AS same_ip_student_count,
                    MAX(ss.ip_changed_count) AS ip_changed_count,
                    MAX(ss.same_ip_risk_score) AS same_ip_risk_score,
                    MAX(ss.ai_suspect_score) AS ai_suspect_score,
                    MAX(ss.typing_answer_ratio) AS typing_answer_ratio,
                    MAX(ss.similarity_max_score) AS similarity_max_score,
                    MAX(ss.similarity_match_count) AS similarity_match_count,
                    MAX(ss.cognitive_score) AS cognitive_score,
                    GROUP_CONCAT(DISTINCT ss.ip_address ORDER BY ss.ip_address SEPARATOR ", ") AS ip_addresses
             FROM session_summaries ss
             LEFT JOIN students st ON (st.id = ss.student_id OR st.moodle_user_id = ss.student_id)
             WHERE ss.exam_id = ?' . $whereExtra . '
             GROUP BY ss.student_id, fullname, username',
            $sqlParams
        );

        $students = [];
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $counters = self::countersFromRow($r);
                $counters['same_ip_student_count'] = (int)($r['same_ip_student_count'] ?? 0);
                $counters['ip_changed_count'] = (int)($r['ip_changed_count'] ?? 0);
                $counters['same_ip_risk_score'] = (int)($r['same_ip_risk_score'] ?? 0);
                $counters['ai_suspect_score'] = (int)($r['ai_suspect_score'] ?? 0);
                $counters['answer_text_count'] = 0;
                $counters['typing_answer_ratio'] = (float)($r['typing_answer_ratio'] ?? 0);
                $counters['similarity_max_score'] = (int)($r['similarity_max_score'] ?? 0);
                $counters['similarity_match_count'] = (int)($r['similarity_match_count'] ?? 0);
                $counters['cognitive_score'] = (int)($r['cognitive_score'] ?? 0);
                // v14: Pass exam context for normalization
                $counters['question_count'] = $questionCount;
                $counters['exam_minutes']   = $examMinutes;
                $risk = RiskEngine::score($counters);

                $students[] = [
                    'student_id' => (int)$r['student_id'],
                    'fullname' => $r['fullname'],
                    'username' => $r['username'],
                    'sessions_count' => (int)$r['sessions_count'],
                    'event_count' => (int)$r['event_count'],
                    'tab_hidden_count' => (int)$r['tab_hidden_count'],
                    'tab_visible_count' => (int)$r['tab_visible_count'],
                    'tab_hidden_duration_ms' => (int)$r['tab_hidden_duration_ms'],
                    'copy_count' => (int)$r['copy_count'],
                    'copy_selection_chars' => (int)$r['copy_selection_chars'],
                    'paste_count' => (int)$r['paste_count'],
                    'right_click_count' => (int)$r['right_click_count'],
                    'blur_count' => (int)$r['blur_count'],
                    'focus_count' => (int)$r['focus_count'],
                    'page_leave_count' => (int)$r['page_leave_count'],
                    'offline_count' => (int)$r['offline_count'],
                    'answer_changed_count' => (int)$r['answer_changed_count'],
                    'devtools_count' => (int)$r['devtools_count'],
                    'suspicious_key_count' => (int)$r['suspicious_key_count'],
                    'screenshot_count' => (int)$r['screenshot_count'],
                    'rapid_answer_changes' => (int)$r['rapid_answer_changes'],
                    'idle_count' => (int)$r['idle_count'],
                    'idle_duration_ms' => (int)$r['idle_duration_ms'],
                    'fullscreen_exit_count' => (int)$r['fullscreen_exit_count'],
                    'typing_keydown_count' => (int)$r['typing_keydown_count'],
                    'typing_backspace_count' => (int)$r['typing_backspace_count'],
                    'typing_enter_count' => (int)$r['typing_enter_count'],
                    'mouse_click_count' => (int)$r['mouse_click_count'],
                    'mouse_move_count' => (int)$r['mouse_move_count'],
                    'mouse_scroll_count' => (int)$r['mouse_scroll_count'],
                    'other_count' => (int)$r['other_count'],
                    'first_event_at' => $r['first_event_at'],
                    'last_event_at' => $r['last_event_at'],
                    'same_ip_student_count' => (int)($r['same_ip_student_count'] ?? 0),
                    'ip_changed_count' => (int)($r['ip_changed_count'] ?? 0),
                    'same_ip_risk_score' => (int)($r['same_ip_risk_score'] ?? 0),
                    'ai_suspect_score' => (int)($r['ai_suspect_score'] ?? 0),
                    'typing_answer_ratio' => (float)($r['typing_answer_ratio'] ?? 0),
                    'similarity_max_score' => (int)($r['similarity_max_score'] ?? 0),
                    'similarity_match_count' => (int)($r['similarity_match_count'] ?? 0),
                    'cognitive_score' => (int)($r['cognitive_score'] ?? 0),
                    'ip_addresses' => (string)($r['ip_addresses'] ?? ''),
                    'risk_score' => $risk['score'],
                    'risk_level' => $risk['level'],
                    'contributions' => $risk['contributions'],
                ];
            }
        } else {
            // Fallback: query raw events if session_summaries is empty
            $mQuizId = (int)Database::scalar('SELECT moodle_quiz_id FROM exams WHERE id = ?', [$examId]);
            if ($mQuizId > 0) {
                $evRows = Database::fetchAll(
                    "SELECT e.moodle_user_id AS student_id,
                            COALESCE(st.fullname, JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.moodle.student.fullname')), CONCAT('طالب #', e.moodle_user_id)) AS fullname,
                            COALESCE(st.username, JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.moodle.student.username')), '') AS username,
                            COUNT(DISTINCT e.session_id) AS sessions_count,
                            COUNT(*) AS event_count,
                            SUM(CASE WHEN e.event_type = 'tab_hidden' THEN 1 ELSE 0 END) AS tab_hidden_count,
                            SUM(CASE WHEN e.event_type = 'paste' THEN 1 ELSE 0 END) AS paste_count,
                            SUM(CASE WHEN e.event_type = 'copy' THEN 1 ELSE 0 END) AS copy_count,
                            SUM(CASE WHEN e.event_type = 'devtools_shortcut' THEN 1 ELSE 0 END) AS devtools_count,
                            MIN(e.event_time) AS first_event_at,
                            MAX(e.event_time) AS last_event_at
                       FROM events e
                       LEFT JOIN students st ON (st.moodle_user_id = e.moodle_user_id AND st.account_id = e.account_id)
                      WHERE e.moodle_quiz_id = ? AND e.account_id = ?
                      GROUP BY e.moodle_user_id, fullname, username",
                    [$mQuizId, $accountId]
                );
                foreach ($evRows as $er) {
                    $c = [
                        'question_count' => $questionCount,
                        'exam_minutes' => $examMinutes,
                        'tab_hidden_count' => (int)$er['tab_hidden_count'],
                        'paste_count' => (int)$er['paste_count'],
                        'copy_count' => (int)$er['copy_count'],
                        'devtools_count' => (int)$er['devtools_count'],
                    ];
                    $risk = RiskEngine::score($c);
                    $students[] = [
                        'student_id' => (int)$er['student_id'],
                        'fullname' => $er['fullname'],
                        'username' => $er['username'],
                        'sessions_count' => (int)$er['sessions_count'],
                        'event_count' => (int)$er['event_count'],
                        'tab_hidden_count' => (int)$er['tab_hidden_count'],
                        'tab_visible_count' => 0,
                        'tab_hidden_duration_ms' => 0,
                        'copy_count' => (int)$er['copy_count'],
                        'copy_selection_chars' => 0,
                        'paste_count' => (int)$er['paste_count'],
                        'right_click_count' => 0,
                        'blur_count' => 0,
                        'focus_count' => 0,
                        'page_leave_count' => 0,
                        'offline_count' => 0,
                        'answer_changed_count' => 0,
                        'devtools_count' => (int)$er['devtools_count'],
                        'suspicious_key_count' => 0,
                        'screenshot_count' => 0,
                        'rapid_answer_changes' => 0,
                        'idle_count' => 0,
                        'idle_duration_ms' => 0,
                        'fullscreen_exit_count' => 0,
                        'typing_keydown_count' => 0,
                        'typing_backspace_count' => 0,
                        'typing_enter_count' => 0,
                        'mouse_click_count' => 0,
                        'mouse_move_count' => 0,
                        'mouse_scroll_count' => 0,
                        'other_count' => 0,
                        'first_event_at' => $er['first_event_at'],
                        'last_event_at' => $er['last_event_at'],
                        'same_ip_student_count' => 0,
                        'ip_changed_count' => 0,
                        'same_ip_risk_score' => 0,
                        'ai_suspect_score' => 0,
                        'typing_answer_ratio' => 0.0,
                        'similarity_max_score' => 0,
                        'similarity_match_count' => 0,
                        'cognitive_score' => 0,
                        'ip_addresses' => '',
                        'risk_score' => $risk['score'],
                        'risk_level' => $risk['level'],
                        'contributions' => $risk['contributions'],
                    ];
                }
            }
        }
                'paste_count' => (int)$r['paste_count'],
                'right_click_count' => (int)$r['right_click_count'],
                'blur_count' => (int)$r['blur_count'],
                'focus_count' => (int)$r['focus_count'],
                'page_leave_count' => (int)$r['page_leave_count'],
                'offline_count' => (int)$r['offline_count'],
                'answer_changed_count' => (int)$r['answer_changed_count'],
                'devtools_count' => (int)$r['devtools_count'],
                'suspicious_key_count' => (int)$r['suspicious_key_count'],
                'screenshot_count' => (int)$r['screenshot_count'],
                'rapid_answer_changes' => (int)$r['rapid_answer_changes'],
                'idle_count' => (int)$r['idle_count'],
                'idle_duration_ms' => (int)$r['idle_duration_ms'],
                'fullscreen_exit_count' => (int)$r['fullscreen_exit_count'],
                'typing_keydown_count' => (int)$r['typing_keydown_count'],
                'typing_backspace_count' => (int)$r['typing_backspace_count'],
                'typing_enter_count' => (int)$r['typing_enter_count'],
                'mouse_click_count' => (int)$r['mouse_click_count'],
                'mouse_move_count' => (int)$r['mouse_move_count'],
                'mouse_scroll_count' => (int)$r['mouse_scroll_count'],
                'other_count' => (int)$r['other_count'],
                'first_event_at' => $r['first_event_at'],
                'last_event_at' => $r['last_event_at'],
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'risk_label' => RiskEngine::labelAr($risk['level']),
                // v9: network + AI + similarity
                'ip_addresses' => $r['ip_addresses'] ?? '',
                'same_ip_student_count' => (int)($r['same_ip_student_count'] ?? 0),
                'ai_suspect_score' => (int)($r['ai_suspect_score'] ?? 0),
                'similarity_max_score' => (int)($r['similarity_max_score'] ?? 0),
                'categories' => $risk['categories'],
            ];
        }
        return $students;
    }

    public static function countersFromRow(array $r): array
    {
        return [
            'tab_hidden_count' => (int)($r['tab_hidden_count'] ?? 0),
            'tab_visible_count' => (int)($r['tab_visible_count'] ?? 0),
            'tab_hidden_duration_ms' => (int)($r['tab_hidden_duration_ms'] ?? 0),
            'copy_count' => (int)($r['copy_count'] ?? 0),
            'copy_selection_chars' => (int)($r['copy_selection_chars'] ?? 0),
            'paste_count' => (int)($r['paste_count'] ?? 0),
            'right_click_count' => (int)($r['right_click_count'] ?? 0),
            'blur_count' => (int)($r['blur_count'] ?? 0),
            'page_leave_count' => (int)($r['page_leave_count'] ?? 0),
            'offline_count' => (int)($r['offline_count'] ?? 0),
            'devtools_count' => (int)($r['devtools_count'] ?? 0),
            'suspicious_key_count' => (int)($r['suspicious_key_count'] ?? 0),
            'screenshot_count' => (int)($r['screenshot_count'] ?? 0),
            'rapid_answer_changes' => (int)($r['rapid_answer_changes'] ?? 0),
            'idle_count' => (int)($r['idle_count'] ?? 0),
            'idle_duration_ms' => (int)($r['idle_duration_ms'] ?? 0),
            'fullscreen_exit_count' => (int)($r['fullscreen_exit_count'] ?? 0),
            'typing_keydown_count' => (int)($r['typing_keydown_count'] ?? 0),
            'typing_backspace_count' => (int)($r['typing_backspace_count'] ?? 0),
            'typing_enter_count' => (int)($r['typing_enter_count'] ?? 0),
            'mouse_click_count' => (int)($r['mouse_click_count'] ?? 0),
            'mouse_move_count' => (int)($r['mouse_move_count'] ?? 0),
            'mouse_scroll_count' => (int)($r['mouse_scroll_count'] ?? 0),
        ];
    }
}
