-- Performance Indexes: run each statement separately
-- These will error harmlessly if the index already exists

CREATE INDEX idx_events_quiz_time_cover ON events (moodle_quiz_id, event_time, event_type, session_id);
CREATE INDEX idx_events_account_time ON events (account_id, event_time);
CREATE INDEX idx_summaries_risk_cover ON session_summaries (risk_score DESC, last_event_at DESC, student_id, exam_id);
CREATE INDEX idx_summaries_exam_time ON session_summaries (exam_id, last_event_at DESC);
CREATE INDEX idx_summaries_student ON session_summaries (student_id);
CREATE INDEX idx_students_account_name ON students (account_id, fullname);
CREATE INDEX idx_exams_course ON exams (moodle_course_id);
CREATE INDEX idx_login_attempts_cleanup ON login_attempts (attempted_at);
CREATE INDEX idx_throttle_cleanup ON telemetry_throttle (window_start);
