CREATE TABLE IF NOT EXISTS course_students (
    moodle_course_id  INT UNSIGNED NOT NULL,
    student_id        INT UNSIGNED NOT NULL,
    account_id        INT UNSIGNED NOT NULL DEFAULT 0,
    student_name      VARCHAR(255) NOT NULL DEFAULT '',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (moodle_course_id, student_id),
    KEY idx_course_students_account (account_id),
    KEY idx_course_students_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
