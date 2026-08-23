<?php
/**
 * Migration: Fix answer_records duplication
 *
 * 1. Add UNIQUE KEY on (session_id, question_id) so ON DUPLICATE KEY UPDATE works
 * 2. Clean up existing duplicate rows (keep latest per session+question)
 * 3. Add paste/copy columns if missing
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();

echo "=== Fix answer_records duplication ===" . PHP_EOL;

// 1. Check current indexes
$indexes = $db->query("SHOW INDEX FROM answer_records WHERE Column_name = 'session_id'")->fetchAll(PDO::FETCH_ASSOC);
$hasUnique = false;
foreach ($indexes as $idx) {
    if ($idx['Non_unique'] === '0' && str_contains($idx['Key_name'], 'uniq')) {
        $hasUnique = true;
        break;
    }
}

if (!$hasUnique) {
    // 2. First, clean up existing duplicates — keep only the latest row per session_id + question_id
    echo "  Cleaning up existing duplicates..." . PHP_EOL;

    $cleanup = $db->exec(
        "DELETE ar1 FROM answer_records ar1
         INNER JOIN answer_records ar2
         ON ar1.session_id = ar2.session_id
            AND ar1.question_id = ar2.question_id
            AND ar1.id < ar2.id"
    );
    echo "  Removed " . $cleanup . " duplicate rows" . PHP_EOL;

    // 3. Drop existing non-unique index on (exam_id, question_id) if it conflicts
    $existingIndexes = $db->query("SHOW INDEX FROM answer_records WHERE Key_name = 'idx_answer_question'")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($existingIndexes)) {
        $db->exec("ALTER TABLE answer_records DROP INDEX idx_answer_question");
        echo "  Dropped idx_answer_question (will replace with unique)" . PHP_EOL;
    }

    // 4. Add UNIQUE KEY on (session_id, question_id)
    $db->exec(
        "ALTER TABLE answer_records
         ADD UNIQUE INDEX uniq_answer_session_question (session_id, question_id)"
    );
    echo "  Added UNIQUE KEY on (session_id, question_id)" . PHP_EOL;

    // 5. Re-add a regular index for exam_id queries
    $db->exec(
        "ALTER TABLE answer_records
         ADD INDEX idx_answer_exam_question (account_id, exam_id, question_id)"
    );
    echo "  Added idx_answer_exam_question" . PHP_EOL;
} else {
    echo "  UNIQUE KEY already exists, skipping" . PHP_EOL;
}

// 6. Add paste/copy columns if missing
$columns = $db->query("SHOW COLUMNS FROM answer_records")->fetchAll(PDO::FETCH_COLUMN);
$missingCols = [
    'paste_text' => "ALTER TABLE answer_records ADD COLUMN paste_text TEXT NULL AFTER copy_text",
    'paste_length' => "ALTER TABLE answer_records ADD COLUMN paste_length INT UNSIGNED NOT NULL DEFAULT 0 AFTER paste_text",
    'copy_count_from_question' => "ALTER TABLE answer_records ADD COLUMN copy_count_from_question INT UNSIGNED NOT NULL DEFAULT 0 AFTER paste_length",
    'copy_text' => "ALTER TABLE answer_records ADD COLUMN copy_text TEXT NULL AFTER copy_count_from_question",
    'ai_detection_provider' => "ALTER TABLE answer_records ADD COLUMN ai_detection_provider VARCHAR(64) NOT NULL DEFAULT '' AFTER ai_score",
    'ai_detection_status' => "ALTER TABLE answer_records ADD COLUMN ai_detection_status VARCHAR(32) NOT NULL DEFAULT '' AFTER ai_detection_provider",
    'ai_detected_at' => "ALTER TABLE answer_records ADD COLUMN ai_detected_at DATETIME NULL AFTER ai_detection_status",
];

foreach ($missingCols as $col => $sql) {
    if (!in_array($col, $columns, true)) {
        $db->exec($sql);
        echo "  + Added column: $col" . PHP_EOL;
    }
}

echo "=== Done ===" . PHP_EOL;
