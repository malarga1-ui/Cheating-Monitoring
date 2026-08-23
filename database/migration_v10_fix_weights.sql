-- v10 fix: Reset indicator weights to match new proportional scoring formula
-- Run AFTER migration_v10_student_answers.sql

UPDATE risk_indicators SET weight_percent = 10 WHERE indicator_key = 'devtools_count';
UPDATE risk_indicators SET weight_percent = 8  WHERE indicator_key = 'screenshot_count';
UPDATE risk_indicators SET weight_percent = 7  WHERE indicator_key = 'suspicious_key_count';
UPDATE risk_indicators SET weight_percent = 7  WHERE indicator_key = 'rapid_answer_changes';
UPDATE risk_indicators SET weight_percent = 7  WHERE indicator_key = 'paste_count';
UPDATE risk_indicators SET weight_percent = 7  WHERE indicator_key = 'tab_hidden_count';
UPDATE risk_indicators SET weight_percent = 7  WHERE indicator_key = 'page_leave_count';
UPDATE risk_indicators SET weight_percent = 5  WHERE indicator_key = 'copy_count';
UPDATE risk_indicators SET weight_percent = 5  WHERE indicator_key = 'fullscreen_exit_count';
UPDATE risk_indicators SET weight_percent = 5  WHERE indicator_key = 'answer_speed_ratio';
UPDATE risk_indicators SET weight_percent = 4  WHERE indicator_key = 'blur_count';
UPDATE risk_indicators SET weight_percent = 4  WHERE indicator_key = 'offline_count';
UPDATE risk_indicators SET weight_percent = 3  WHERE indicator_key = 'copy_selection_chars';
UPDATE risk_indicators SET weight_percent = 3  WHERE indicator_key = 'idle_count';
UPDATE risk_indicators SET weight_percent = 3  WHERE indicator_key = 'right_click_count';
UPDATE risk_indicators SET weight_percent = 3  WHERE indicator_key = 'tab_hidden_duration_ms';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'typing_backspace_count';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'mouse_move_count';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'mouse_scroll_count';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'idle_duration_ms';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'typing_keydown_count';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'answer_changed_count';
UPDATE risk_indicators SET weight_percent = 0  WHERE indicator_key = 'other_count';

UPDATE risk_indicators SET weight_percent = 7  WHERE indicator_key = 'same_ip_student_count';
UPDATE risk_indicators SET weight_percent = 5  WHERE indicator_key = 'ip_changed_count';
UPDATE risk_indicators SET weight_percent = 3  WHERE indicator_key = 'same_ip_risk_score';

UPDATE risk_indicators SET weight_percent = 8  WHERE indicator_key = 'ai_suspect_score';
UPDATE risk_indicators SET weight_percent = 4  WHERE indicator_key = 'answer_text_count';
UPDATE risk_indicators SET weight_percent = 3  WHERE indicator_key = 'typing_answer_ratio';

UPDATE risk_indicators SET weight_percent = 6  WHERE indicator_key = 'similarity_max_score';
UPDATE risk_indicators SET weight_percent = 4  WHERE indicator_key = 'similarity_match_count';
