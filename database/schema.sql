-- ============================================================
-- Exam Monitor Platform - MySQL Schema (MySQL 8+)
-- Two-track design:
--   TRACK 1 (write path):  events  -> append-only, durable, lossless.
--   TRACK 2 (read path):   exams / students / sessions / session_summaries
--                          populated by the background Aggregator (watermark).
-- ============================================================
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- TRACK 2: Aggregated / reference tables
-- ------------------------------------------------------------

-- ------------------------------------------------------------
-- SaaS accounts (multi-tenant). Owner = platform operator (never
-- locked, sees all tenants). Customer = tenant with a 7-day trial
-- then a hard lock until they pay / get a license key.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_name         VARCHAR(190) NOT NULL DEFAULT '',
    email            VARCHAR(190) NOT NULL,
    password_hash    VARCHAR(255) NOT NULL,
    role             ENUM('owner','customer') NOT NULL DEFAULT 'customer',
    status           ENUM('trial','expired','active','suspended') NOT NULL DEFAULT 'trial',
    api_secret       VARCHAR(64) NOT NULL,
    license_key      VARCHAR(190) NOT NULL DEFAULT '',
    site_domain      VARCHAR(255) NOT NULL DEFAULT '',
    setup_progress   TEXT NULL COMMENT 'JSON: onboarding steps done (setup wizard)',
    trial_started_at DATETIME NULL,
    trial_ends_at    DATETIME NULL,
    activated_at     DATETIME NULL,
    last_login_at    DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounts_email (email),
    UNIQUE KEY uq_accounts_api_secret (api_secret),
    KEY idx_accounts_site_domain (site_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL,
    fullname      VARCHAR(190) NOT NULL DEFAULT '',
    email         VARCHAR(190) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','supervisor') NOT NULL DEFAULT 'admin',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Moodle courses (auto-registered from events; names editable by admin)
CREATE TABLE IF NOT EXISTS courses (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_course_id INT UNSIGNED NOT NULL,
    name             VARCHAR(255) NOT NULL DEFAULT '',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_courses_account_moodle (account_id, moodle_course_id),
    KEY idx_courses_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which supervisors may see which courses (admin sees everything).
CREATE TABLE IF NOT EXISTS course_access (
    user_id          INT UNSIGNED NOT NULL,
    moodle_course_id INT UNSIGNED NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, moodle_course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Moodle teachers (auto-synced from Moodle role assignments / user_created).
CREATE TABLE IF NOT EXISTS teachers (
    moodle_teacher_id INT UNSIGNED PRIMARY KEY,
    account_id        INT UNSIGNED NOT NULL DEFAULT 0,
    fullname          VARCHAR(190) NOT NULL DEFAULT '',
    username          VARCHAR(190) NOT NULL DEFAULT '',
    password_hash     VARCHAR(255) NOT NULL DEFAULT '',
    is_first_login    TINYINT(1) NOT NULL DEFAULT 1,
    login_enabled     TINYINT(1) NOT NULL DEFAULT 1,
    first_seen_at     DATETIME NULL,
    last_seen_at      DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_teachers_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which teachers are assigned to which Moodle course (from role_assigned).
CREATE TABLE IF NOT EXISTS course_teachers (
    moodle_course_id  INT UNSIGNED NOT NULL,
    moodle_teacher_id INT UNSIGNED NOT NULL,
    account_id        INT UNSIGNED NOT NULL DEFAULT 0,
    teacher_name      VARCHAR(255) NOT NULL DEFAULT '',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (moodle_course_id, moodle_teacher_id),
    KEY idx_course_teachers_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exams (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_quiz_id  INT UNSIGNED NOT NULL,
    moodle_course_id INT UNSIGNED NOT NULL,
    moodle_cmid     INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL DEFAULT '',
    moodle_teacher_id INT UNSIGNED NULL,
    teacher_name    VARCHAR(255) NOT NULL DEFAULT '',
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Time limit from Moodle in minutes (0 = unlimited)',
    first_event_at  DATETIME NULL,
    last_event_at   DATETIME NULL,
    status          ENUM('active','ended') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_exams_account_quiz_course (account_id, moodle_quiz_id, moodle_course_id),
    KEY idx_exams_status (status),
    KEY idx_exams_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_user_id INT UNSIGNED NOT NULL,
    fullname      VARCHAR(190) NOT NULL DEFAULT '',
    username      VARCHAR(190) NOT NULL DEFAULT '',
    first_seen_at DATETIME NULL,
    last_seen_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_account_moodle (account_id, moodle_user_id),
    KEY idx_students_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One browser session per exam attempt
CREATE TABLE IF NOT EXISTS sessions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id    VARCHAR(64) NOT NULL,
    account_id    INT UNSIGNED NOT NULL DEFAULT 0,
    student_id    INT UNSIGNED NULL,
    exam_id       INT UNSIGNED NULL,
    attempt_id    INT UNSIGNED NULL,
    started_at    DATETIME NOT NULL,
    ended_at      DATETIME NULL,
    last_event_at DATETIME NULL,
    event_count   INT UNSIGNED NOT NULL DEFAULT 0,
    risk_score    SMALLINT NOT NULL DEFAULT 0,
    risk_level    ENUM('safe','low','medium','high','critical') NOT NULL DEFAULT 'safe',
    UNIQUE KEY uq_sessions_session (session_id),
    KEY idx_sessions_student_exam (student_id, exam_id),
    KEY idx_sessions_exam (exam_id),
    KEY idx_sessions_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-session signal counters (populated by the Aggregator)
CREATE TABLE IF NOT EXISTS session_summaries (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id             VARCHAR(64) NOT NULL,
    account_id             INT UNSIGNED NOT NULL DEFAULT 0,
    student_id             INT UNSIGNED NOT NULL,
    exam_id                INT UNSIGNED NOT NULL,
    attempt_id             INT UNSIGNED NULL,
    first_event_at         DATETIME NULL,
    last_event_at          DATETIME NULL,
    event_count            INT UNSIGNED NOT NULL DEFAULT 0,
    tab_hidden_count       INT UNSIGNED NOT NULL DEFAULT 0,
    tab_visible_count      INT UNSIGNED NOT NULL DEFAULT 0,
    tab_hidden_duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    copy_count             INT UNSIGNED NOT NULL DEFAULT 0,
    copy_selection_chars   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    paste_count            INT UNSIGNED NOT NULL DEFAULT 0,
    right_click_count      INT UNSIGNED NOT NULL DEFAULT 0,
    blur_count             INT UNSIGNED NOT NULL DEFAULT 0,
    focus_count            INT UNSIGNED NOT NULL DEFAULT 0,
    page_leave_count       INT UNSIGNED NOT NULL DEFAULT 0,
    offline_count          INT UNSIGNED NOT NULL DEFAULT 0,
    answer_changed_count   INT UNSIGNED NOT NULL DEFAULT 0,
    devtools_count         INT UNSIGNED NOT NULL DEFAULT 0,
    suspicious_key_count   INT UNSIGNED NOT NULL DEFAULT 0,
    screenshot_count       INT UNSIGNED NOT NULL DEFAULT 0,
    rapid_answer_changes   INT UNSIGNED NOT NULL DEFAULT 0,
    idle_count             INT UNSIGNED NOT NULL DEFAULT 0,
    idle_duration_ms       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    fullscreen_exit_count  INT UNSIGNED NOT NULL DEFAULT 0,
    typing_keydown_count   INT UNSIGNED NOT NULL DEFAULT 0,
    typing_backspace_count INT UNSIGNED NOT NULL DEFAULT 0,
    typing_enter_count     INT UNSIGNED NOT NULL DEFAULT 0,
    mouse_click_count      INT UNSIGNED NOT NULL DEFAULT 0,
    mouse_move_count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mouse_scroll_count     INT UNSIGNED NOT NULL DEFAULT 0,
    other_count            INT UNSIGNED NOT NULL DEFAULT 0,
    risk_score             SMALLINT NOT NULL DEFAULT 0,
    risk_level             ENUM('safe','low','medium','high','critical') NOT NULL DEFAULT 'safe',
    -- v9: network columns
    ip_address             VARCHAR(45) NOT NULL DEFAULT '',
    ip_country             VARCHAR(64) NOT NULL DEFAULT '',
    ip_city                VARCHAR(128) NOT NULL DEFAULT '',
    same_ip_student_count  INT UNSIGNED NOT NULL DEFAULT 0,
    ip_changed_count       INT UNSIGNED NOT NULL DEFAULT 0,
    same_ip_risk_score     SMALLINT NOT NULL DEFAULT 0,
    -- v9: AI detection columns
    ai_suspect_score       SMALLINT NOT NULL DEFAULT 0,
    answer_text_count      INT UNSIGNED NOT NULL DEFAULT 0,
    avg_answer_length      INT UNSIGNED NOT NULL DEFAULT 0,
    typing_answer_ratio    DECIMAL(5,2) NOT NULL DEFAULT 0,
    -- v9: similarity columns
    similarity_max_score   SMALLINT NOT NULL DEFAULT 0,
    similarity_match_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_summaries_session (session_id),
    KEY idx_summaries_exam_student (exam_id, student_id),
    KEY idx_summaries_risk (risk_level),
    KEY idx_summaries_exam_risk (exam_id, risk_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TRACK 1: Append-only event store (durable buffer / queue)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL DEFAULT 0,
    event_id         VARCHAR(64) NOT NULL,
    schema_version   VARCHAR(10) NOT NULL DEFAULT '1.0',
    session_id       VARCHAR(64) NOT NULL,
    sequence_number  INT UNSIGNED NOT NULL DEFAULT 0,
    event_type       VARCHAR(50) NOT NULL,
    moodle_user_id   INT UNSIGNED NOT NULL,
    moodle_quiz_id   INT UNSIGNED NOT NULL,
    moodle_course_id INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_cmid      INT UNSIGNED NOT NULL DEFAULT 0,
    attempt_id       INT UNSIGNED NULL,
    event_time       DATETIME(3) NOT NULL,
    received_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    elapsed_ms       INT UNSIGNED NULL,
    duration_ms      BIGINT UNSIGNED NULL,
    ip_address       VARCHAR(45) NULL,
    user_agent       VARCHAR(512) NULL,
    url              VARCHAR(2048) NULL,
    payload          JSON NOT NULL,
    UNIQUE KEY uq_events_event_id (event_id),
    KEY idx_events_session (session_id),
    KEY idx_events_type (event_type),
    KEY idx_events_time (event_time),
    KEY idx_events_user (moodle_user_id),
    KEY idx_events_quiz_time (moodle_quiz_id, event_time),
    KEY idx_events_quiz_user (moodle_quiz_id, moodle_user_id),
    KEY idx_events_account (account_id),
    KEY idx_events_account_quiz (account_id, moodle_quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Watermark for incremental aggregation (at-least-once, idempotent upserts)
CREATE TABLE IF NOT EXISTS agg_watermark (
    id            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO agg_watermark (id, last_event_id) VALUES (1, 0)
    ON DUPLICATE KEY UPDATE id = id;

-- Simple IP throttle for the public telemetry endpoint
CREATE TABLE IF NOT EXISTS telemetry_throttle (
    ip_address   VARCHAR(45) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hit_count    INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (ip_address, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Configurable cheating formula ("معادلة الغش")
-- Each indicator is a signal (a session_summaries counter) with a
-- percentage weight. Student score = sum of weights of the indicators
-- they triggered, capped at 100. Fully editable from the admin UI.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risk_indicators (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indicator_key  VARCHAR(64)  NOT NULL,
    label_ar       VARCHAR(190) NOT NULL DEFAULT '',
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    description    VARCHAR(255) NOT NULL DEFAULT '',
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    category       VARCHAR(32)  NOT NULL DEFAULT 'behavioral' COMMENT 'behavioral|network|ai|similarity',
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_risk_indicators_key (indicator_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO risk_indicators (indicator_key, label_ar, weight_percent, enabled, description, sort_order, category) VALUES
    ('devtools_count',         'فتح أدوات المطوّر',        10, 1, 'دخول وضع المطورين أثناء الامتحان (F12 / فحص).', 1, 'behavioral'),
    ('screenshot_count',       'محاولة لقطة شاشة',         8, 1, 'محاولة أخذ لقطة للشاشة أثناء الامتحان.', 2, 'behavioral'),
    ('suspicious_key_count',   'مفاتيح مشبوهة',            7, 1, 'ضغط F12 أو Alt+Tab أو مفاتيح النظام أثناء الامتحان.', 3, 'behavioral'),
    ('rapid_answer_changes',   'تغيير إجابة سريع',         7, 1, 'تعديل الإجابات بشكل متكرر وسريع.', 4, 'behavioral'),
    ('paste_count',            'لصق',                      7, 1, 'لصق نص من مصدر خارجي.', 5, 'behavioral'),
    ('tab_hidden_count',       'إخفاء التبويب',            7, 1, 'الانتقال إلى تبويب آخر ثم العودة.', 6, 'behavioral'),
    ('page_leave_count',       'مغادرة الصفحة',            7, 1, 'محاولة مغادرة صفحة الامتحان.', 7, 'behavioral'),
    ('copy_count',             'نسخ',                      5, 1, 'نسخ نص من صفحة الامتحان.', 8, 'behavioral'),
    ('fullscreen_exit_count',  'الخروج من ملء الشاشة',     5, 1, 'الخروج من وضع ملء الشاشة المفروض.', 9, 'behavioral'),
    ('answer_speed_ratio',     'سرعة الإجابة المشبوهة',    5, 1, 'نسبة الوقت الفعلي مقابل المتوقع للإجابة.', 10, 'behavioral'),
    ('blur_count',             'فقدان التركيز',            4, 1, 'الانتقال من النافذة إلى نافذة أخرى.', 11, 'behavioral'),
    ('offline_count',          'انقطاع النت',              4, 1, 'انقطاع الاتصال بالإنترنت أثناء الامتحان.', 12, 'behavioral'),
    ('copy_selection_chars',   'تحديد نص للنسخ',           3, 1, 'تحديد نصوص طويلة في صفحة الامتحان.', 13, 'behavioral'),
    ('idle_count',             'فترات خمول',               3, 1, 'توقف النشاط لفترة طويلة (علامة إجابة خارجية).', 14, 'behavioral'),
    ('right_click_count',      'نقر يمين',                 3, 1, 'فتح قائمة النقر الأيمن.', 15, 'behavioral'),
    ('tab_hidden_duration_ms', 'مدة إخفاء التبويب',        3, 1, 'الوقت الإجمالي الذي قضاه الطالب خارج الامتحان.', 16, 'behavioral'),
    ('typing_backspace_count', 'مسح متكرر أثناء الكتابة', 0, 0, 'حذف متكرر أثناء كتابة إجابات المقالية.', 16, 'behavioral'),
    ('mouse_move_count',       'حركة الفأرة',              0, 0, 'حركة فأرة مكثفة.', 17, 'behavioral'),
    ('mouse_scroll_count',     'تمرير الفأرة',             0, 0, 'تمرير متكرر في الصفحة.', 18, 'behavioral'),
    ('idle_duration_ms',       'مدة الخمول',               0, 0, 'إجمالي وقت التوقف عن النشاط.', 19, 'behavioral'),
    ('typing_keydown_count',   'كتابة',                    0, 0, 'عدد ضغطات المفاتيح.', 20, 'behavioral'),
    ('answer_changed_count',   'تغيير الإجابات',           0, 0, 'عدد مرات تغيير الإجابات.', 21, 'behavioral'),
    ('other_count',            'أحداث أخرى',               0, 0, 'أحداث إضافية غير مصنّفة.', 22, 'behavioral'),
    -- v9: Network indicators
    ('same_ip_student_count',  'تجمع بنفس الـ IP',         7, 1, 'عدد الطلاب المتصلين بنفس عنوان IP.', 23, 'network'),
    ('ip_changed_count',       'تغيير الـ IP',             5, 1, 'عدد مرات تغيير IP أثناء الامتحان.', 24, 'network'),
    ('same_ip_risk_score',     'خطورة الشبكة',             3, 1, 'مؤشر الخطورة المحسوب من تحليل الشبكة.', 25, 'network'),
    -- v9: AI detection indicators
    ('ai_suspect_score',       'إجابات مشبوهة بالـ AI',   10, 1, 'مؤشر أن الإجابات مولّدة بالذكاء الاصطناعي.', 26, 'ai'),
    ('answer_text_count',      'عدد الإجابات النصية',       6, 1, 'عدد الإجابات التي تحتوي على نص كامل.', 27, 'ai'),
    ('typing_answer_ratio',    'نسبة الكتابة الفعلية',      4, 1, 'نسبة الإجابات المكتوبة بالكيبورد.', 28, 'ai'),
    -- v9: Similarity indicators
    ('similarity_max_score',   'أعلى تشابه',               8, 1, 'أعلى نسبة تشابه مع طالب آخر.', 29, 'similarity'),
    ('similarity_match_count', 'عدد التطابقات',            7, 1, 'عدد الإجابات المتطابقة مع طلاب آخرين.', 30, 'similarity')
ON DUPLICATE KEY UPDATE indicator_key = indicator_key;

-- ------------------------------------------------------------
-- AUDIT TRAIL: account-scoped accountability log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    actor_type  VARCHAR(16)  NOT NULL DEFAULT 'account',
    actor_id    INT UNSIGNED NULL,
    actor_name  VARCHAR(255) NOT NULL DEFAULT '',
    action      VARCHAR(64)  NOT NULL,
    target_type VARCHAR(32)  NULL,
    target_id   INT UNSIGNED NULL,
    details     JSON         NULL,
    created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_audit_account_time (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- AUDIT TRAIL: account-scoped accountability log (v7) — above
-- ------------------------------------------------------------

-- ------------------------------------------------------------
-- License / trial activation (singleton row, id = 1).
-- Fresh installs must include this table (kept in sync with
-- scripts/migrate_v2_signals.php Phase 5 + Activation.php).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activation (
    id               TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status           ENUM('unactivated','trial','active') NOT NULL DEFAULT 'unactivated',
    license_key      VARCHAR(190) NOT NULL DEFAULT '',
    trial_started_at DATETIME NULL,
    trial_ends_at    DATETIME NULL,
    activated_at     DATETIME NULL,
    last_check_at    DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO activation (id, status) VALUES (1, 'unactivated')
    ON DUPLICATE KEY UPDATE id = id;

-- ------------------------------------------------------------
-- IP throttle for public login endpoints (AuthController /
-- StaffAuthController / TeacherAuthController rate-limiting).
-- Fresh installs must include this table (kept in sync with
-- database/migration_add_login_attempts.sql + migration_add_performance_indexes.sql).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_attempts_ip (ip_address, attempted_at),
    INDEX idx_login_attempts_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- One-time signed login tokens (plugin -> teacher portal).
-- Fresh installs must include this table (kept in sync with
-- scripts/migrate_v5_teacher_portal.php).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teacher_login_tokens (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id   INT UNSIGNED NOT NULL DEFAULT 0,
    teacher_id   INT UNSIGNED NOT NULL DEFAULT 0,
    token_hash   CHAR(64) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_teacher_tokens_hash (token_hash),
    KEY idx_teacher_tokens_account (account_id, teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- v9: Answer records — extracted from answer_changed events.
-- Populated by Aggregator::saveAnswerRecords().
-- Used by AIDetector + SimilarityEngine.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS answer_records (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id        INT UNSIGNED NOT NULL DEFAULT 0,
    session_id        VARCHAR(64) NOT NULL,
    student_id        INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id           INT UNSIGNED NOT NULL DEFAULT 0,
    moodle_quiz_id    INT UNSIGNED NOT NULL DEFAULT 0,
    question_id       VARCHAR(128) NOT NULL DEFAULT '',
    question_type     VARCHAR(64) NOT NULL DEFAULT '',
    answer_text       TEXT NOT NULL,
    answer_length     INT UNSIGNED NOT NULL DEFAULT 0,
    word_count        INT UNSIGNED NOT NULL DEFAULT 0,
    typing_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    change_count      INT UNSIGNED NOT NULL DEFAULT 1,
    ai_score          SMALLINT NOT NULL DEFAULT 0 COMMENT 'Per-question AI detection score (0-100)',
    paste_text        TEXT NULL COMMENT 'Text that was pasted into this answer field',
    paste_length      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Character count of pasted text',
    copy_count_from_question INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of copy events from this question area',
    copy_text         TEXT NULL COMMENT 'Text that was copied from this question area',
    created_at        DATETIME(3) NOT NULL,
    UNIQUE KEY uq_answer_records_event (session_id, question_id),
    KEY idx_answer_records_exam (account_id, exam_id),
    KEY idx_answer_records_student (account_id, student_id),
    KEY idx_answer_records_session (account_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- v9: IP snapshots — periodic browser-detected IPs every 5 min.
-- Populated by Aggregator::saveIPSnapshots().
-- Used by NetworkAnalyzer for multi-device detection.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ip_snapshots (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id    INT UNSIGNED NOT NULL DEFAULT 0,
    session_id    VARCHAR(64) NOT NULL,
    student_id    INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id       INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address    VARCHAR(45) NOT NULL DEFAULT '',
    user_agent    VARCHAR(512) NOT NULL DEFAULT '',
    browser_fp    VARCHAR(255) NOT NULL DEFAULT '',
    detected_at   DATETIME(3) NOT NULL,
    UNIQUE KEY uq_ip_snapshots (session_id, detected_at),
    KEY idx_ip_snapshots_exam (account_id, exam_id),
    KEY idx_ip_snapshots_session (account_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- v9: Student devices — unique browser fingerprints per student per exam.
-- Populated by Aggregator::saveIPSnapshots().
-- Used by NetworkAnalyzer for multi-device detection.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_devices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL DEFAULT 0,
    student_id      INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id         INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45) NOT NULL DEFAULT '',
    user_agent      VARCHAR(512) NOT NULL DEFAULT '',
    browser_fp      VARCHAR(255) NOT NULL DEFAULT '',
    first_seen      DATETIME(3) NOT NULL,
    last_seen       DATETIME(3) NOT NULL,
    snapshot_count  INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_student_devices_fp (account_id, student_id, exam_id, browser_fp),
    KEY idx_student_devices_exam (account_id, exam_id),
    KEY idx_student_devices_student (account_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- v9: Network groups — students sharing the same IP.
-- Populated by NetworkAnalyzer::persistGroups().
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS network_groups (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id      INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id         INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45) NOT NULL DEFAULT '',
    student_count   INT UNSIGNED NOT NULL DEFAULT 0,
    student_ids     JSON NULL,
    risk_level      ENUM('safe','low','medium','high','critical') NOT NULL DEFAULT 'safe',
    detected_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_network_groups (account_id, exam_id, ip_address),
    KEY idx_network_groups_exam (account_id, exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- v9: Similarity pairs — pairwise student answer similarity.
-- Populated by SimilarityEngine::persistPairs().
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS similarity_pairs (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id           INT UNSIGNED NOT NULL DEFAULT 0,
    exam_id              INT UNSIGNED NOT NULL DEFAULT 0,
    student_a_id         INT UNSIGNED NOT NULL DEFAULT 0,
    student_b_id         INT UNSIGNED NOT NULL DEFAULT 0,
    similarity_pct       SMALLINT NOT NULL DEFAULT 0,
    matching_questions   INT UNSIGNED NOT NULL DEFAULT 0,
    total_questions      INT UNSIGNED NOT NULL DEFAULT 0,
    detected_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_similarity_pairs_exam (account_id, exam_id),
    KEY idx_similarity_pairs_score (account_id, exam_id, similarity_pct DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
