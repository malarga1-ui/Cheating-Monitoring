<?php
/**
 * Advanced analytics migration (v9):
 *   1. session_summaries: network analysis columns
 *   2. session_summaries: AI detection columns
 *   3. session_summaries: similarity columns
 *   4. exam_similarities: pairwise student similarity table
 *   5. network_groups: IP-based student grouping
 *   6. answer_records: individual answer storage for analysis
 *
 * Usage:
 *   php scripts/migrate_v9_advanced_analytics.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function addColumn(PDO $db, string $table, string $col, string $def): void {
    $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetchAll();
    if (!$exists) {
        $db->exec("ALTER TABLE `$table` ADD COLUMN $col $def");
        echo "  + $table.$col" . PHP_EOL;
    }
}

// ─── 1. Network analysis columns in session_summaries ──────────────────
echo "=== network columns ===" . PHP_EOL;
addColumn($db, 'session_summaries', 'ip_address', "VARCHAR(45) NOT NULL DEFAULT '' AFTER other_count");
addColumn($db, 'session_summaries', 'ip_country', "VARCHAR(2) NOT NULL DEFAULT '' AFTER ip_address");
addColumn($db, 'session_summaries', 'ip_city', "VARCHAR(100) NOT NULL DEFAULT '' AFTER ip_country");
addColumn($db, 'session_summaries', 'same_ip_student_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER ip_city");
addColumn($db, 'session_summaries', 'ip_changed_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER same_ip_student_count");
addColumn($db, 'session_summaries', 'same_ip_risk_score', "SMALLINT NOT NULL DEFAULT 0 AFTER ip_changed_count");

// ─── 2. AI detection columns in session_summaries ─────────────────────
echo "=== ai detection columns ===" . PHP_EOL;
addColumn($db, 'session_summaries', 'ai_suspect_score', "SMALLINT NOT NULL DEFAULT 0 AFTER same_ip_risk_score");
addColumn($db, 'session_summaries', 'answer_text_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER ai_suspect_score");
addColumn($db, 'session_summaries', 'avg_answer_length', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER answer_text_count");
addColumn($db, 'session_summaries', 'typing_answer_ratio', "DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER avg_answer_length");

// ─── 3. Similarity columns in session_summaries ───────────────────────
echo "=== similarity columns ===" . PHP_EOL;
addColumn($db, 'session_summaries', 'similarity_max_score', "SMALLINT NOT NULL DEFAULT 0 AFTER typing_answer_ratio");
addColumn($db, 'session_summaries', 'similarity_match_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER similarity_max_score");
addColumn($db, 'session_summaries', 'similarity_exam_id', "INT UNSIGNED NULL AFTER similarity_match_count");

// ─── 0. Add category column to risk_indicators ────────────────────────
echo "=== risk_indicators.category column ===" . PHP_EOL;
addColumn($db, 'risk_indicators', 'category', "VARCHAR(32) NOT NULL DEFAULT 'behavioral' AFTER sort_order");

// ─── 4. Answer records table (for AI + similarity analysis) ───────────
echo "=== answer_records table ===" . PHP_EOL;
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('answer_records', $tables, true)) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS answer_records (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id     INT UNSIGNED NOT NULL DEFAULT 0,
            session_id     VARCHAR(64) NOT NULL,
            student_id     INT UNSIGNED NOT NULL DEFAULT 0,
            exam_id        INT UNSIGNED NOT NULL DEFAULT 0,
            moodle_quiz_id INT UNSIGNED NOT NULL DEFAULT 0,
            question_id    VARCHAR(128) NOT NULL DEFAULT '',
            question_type  VARCHAR(64) NOT NULL DEFAULT '',
            answer_text    TEXT NOT NULL,
            answer_length  INT UNSIGNED NOT NULL DEFAULT 0,
            word_count     INT UNSIGNED NOT NULL DEFAULT 0,
            typing_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            change_count   INT UNSIGNED NOT NULL DEFAULT 0,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_answer_exam (account_id, exam_id),
            KEY idx_answer_session (session_id),
            KEY idx_answer_student (student_id, exam_id),
            KEY idx_answer_question (exam_id, question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + answer_records table created" . PHP_EOL;
} else {
    echo "  answer_records exists" . PHP_EOL;
}

// ─── 5. Network groups table (IP clustering) ──────────────────────────
echo "=== network_groups table ===" . PHP_EOL;
if (!in_array('network_groups', $tables, true)) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS network_groups (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id     INT UNSIGNED NOT NULL DEFAULT 0,
            exam_id        INT UNSIGNED NOT NULL DEFAULT 0,
            ip_address     VARCHAR(45) NOT NULL DEFAULT '',
            student_count  INT UNSIGNED NOT NULL DEFAULT 0,
            student_ids    TEXT NOT NULL COMMENT 'JSON array of student IDs',
            risk_level     ENUM('safe','low','medium','high','critical') NOT NULL DEFAULT 'safe',
            detected_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_netgroup_exam (account_id, exam_id),
            KEY idx_netgroup_ip (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + network_groups table created" . PHP_EOL;
} else {
    echo "  network_groups exists" . PHP_EOL;
}

// ─── 6. Similarity pairs table ────────────────────────────────────────
echo "=== similarity_pairs table ===" . PHP_EOL;
if (!in_array('similarity_pairs', $tables, true)) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS similarity_pairs (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id      INT UNSIGNED NOT NULL DEFAULT 0,
            exam_id         INT UNSIGNED NOT NULL DEFAULT 0,
            student_a_id    INT UNSIGNED NOT NULL DEFAULT 0,
            student_b_id    INT UNSIGNED NOT NULL DEFAULT 0,
            similarity_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
            matching_questions INT UNSIGNED NOT NULL DEFAULT 0,
            total_questions INT UNSIGNED NOT NULL DEFAULT 0,
            detected_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_simpair_exam (account_id, exam_id),
            KEY idx_simpair_students (student_a_id, student_b_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + similarity_pairs table created" . PHP_EOL;
} else {
    echo "  similarity_pairs exists" . PHP_EOL;
}

// ─── 7. IP snapshots table (periodic IP tracking per session) ─────────
echo "=== ip_snapshots table ===" . PHP_EOL;
if (!in_array('ip_snapshots', $tables, true)) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS ip_snapshots (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id     INT UNSIGNED NOT NULL DEFAULT 0,
            session_id     VARCHAR(64) NOT NULL,
            student_id     INT UNSIGNED NOT NULL DEFAULT 0,
            exam_id        INT UNSIGNED NOT NULL DEFAULT 0,
            ip_address     VARCHAR(45) NOT NULL DEFAULT '',
            user_agent     VARCHAR(512) NOT NULL DEFAULT '',
            browser_fp     VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'browser fingerprint hash',
            detected_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ipsnap_session (session_id),
            KEY idx_ipsnap_student (student_id, exam_id),
            KEY idx_ipsnap_exam_ip (exam_id, ip_address),
            KEY idx_ipsnap_time (detected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + ip_snapshots table created" . PHP_EOL;
} else {
    echo "  ip_snapshots exists" . PHP_EOL;
}

// ─── 8. Student devices table (multi-device detection) ────────────────
echo "=== student_devices table ===" . PHP_EOL;
if (!in_array('student_devices', $tables, true)) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS student_devices (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id     INT UNSIGNED NOT NULL DEFAULT 0,
            student_id     INT UNSIGNED NOT NULL DEFAULT 0,
            exam_id        INT UNSIGNED NOT NULL DEFAULT 0,
            ip_address     VARCHAR(45) NOT NULL DEFAULT '',
            user_agent     VARCHAR(512) NOT NULL DEFAULT '',
            browser_fp     VARCHAR(128) NOT NULL DEFAULT '',
            first_seen     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            snapshot_count INT UNSIGNED NOT NULL DEFAULT 1,
            UNIQUE KEY uq_sdev_fingerprint (account_id, student_id, exam_id, browser_fp),
            KEY idx_sdev_exam (account_id, exam_id),
            KEY idx_sdev_student (student_id, exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "  + student_devices table created" . PHP_EOL;
} else {
    echo "  student_devices exists" . PHP_EOL;
}

echo PHP_EOL . "Migration v9 complete." . PHP_EOL;
