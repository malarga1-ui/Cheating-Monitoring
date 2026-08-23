-- ============================================================
-- Migration v28: Similarity Score + Event Sequence Detection
-- ============================================================
-- Safe to run multiple times (idempotent).
--
-- Changes:
--   1. Add similarity_score to answer_records (per-question similarity)
--   2. Add matched_student_id to answer_records (who was matched)
--   3. Add sequence_flags column for event sequence detection
--   4. Add sequence_score column for sequence risk score
-- ============================================================

-- 1. similarity_score column
SET @col1 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'answer_records' AND COLUMN_NAME = 'similarity_score'
);
SET @sql1 = IF(@col1 = 0,
    'ALTER TABLE answer_records ADD COLUMN similarity_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT ''Per-question similarity risk score (0-100) via TF-IDF cosine'' AFTER ai_detected_at',
    'SELECT ''similarity_score column already exists'' AS status'
);
PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;

-- 2. matched_student_id column
SET @col2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'answer_records' AND COLUMN_NAME = 'matched_student_id'
);
SET @sql2 = IF(@col2 = 0,
    'ALTER TABLE answer_records ADD COLUMN matched_student_id INT UNSIGNED NULL COMMENT ''Internal student_id of the most similar answer'' AFTER similarity_score',
    'SELECT ''matched_student_id column already exists'' AS status'
);
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- 3. similarity_max_ratio column (raw cosine ratio 0-100%)
SET @col3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'answer_records' AND COLUMN_NAME = 'similarity_max_ratio'
);
SET @sql3 = IF(@col3 = 0,
    'ALTER TABLE answer_records ADD COLUMN similarity_max_ratio DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT ''Raw max cosine similarity ratio (0-100%)'' AFTER matched_student_id',
    'SELECT ''similarity_max_ratio column already exists'' AS status'
);
PREPARE s3 FROM @sql3; EXECUTE s3; DEALLOCATE PREPARE s3;

-- 4. sequence_flags column (JSON: suspicious sequences detected)
SET @col4 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'session_summaries' AND COLUMN_NAME = 'sequence_flags'
);
SET @sql4 = IF(@col4 = 0,
    'ALTER TABLE session_summaries ADD COLUMN sequence_flags JSON NULL COMMENT ''Detected suspicious event sequences (copy-blur-paste, post-blur mutation)'' AFTER cognitive_score',
    'SELECT ''sequence_flags column already exists'' AS status'
);
PREPARE s4 FROM @sql4; EXECUTE s4; DEALLOCATE PREPARE s4;

-- 5. sequence_score column on session_summaries
SET @col5 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'session_summaries' AND COLUMN_NAME = 'sequence_score'
);
SET @sql5 = IF(@col5 = 0,
    'ALTER TABLE session_summaries ADD COLUMN sequence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT ''Risk score from event sequence detection (0-100)'' AFTER sequence_flags',
    'SELECT ''sequence_score column already exists'' AS status'
);
PREPARE s5 FROM @sql5; EXECUTE s5; DEALLOCATE PREPARE s5;

-- 6. Add sequence detection indicators to risk_indicators
INSERT IGNORE INTO risk_indicators (indicator_key, label_ar, weight_percent, enabled, description, sort_order, category)
VALUES
    ('critical_sequence_detected', 'تسلسل مشبوه', 8, true, 'копия ثم خروج ثم عودة ثم لصق في نفس السؤال خلال 60 ثانية', 25, 'behavioral'),
    ('post_blur_mutation', 'تغيير بعد الخروج', 6, true, 'تغيير الإجابة فوراً بعد العودة من خارج النافذة', 26, 'behavioral');

-- 7. Performance index
SET @idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'answer_records' AND INDEX_NAME = 'idx_answer_similarity'
);
SET @sql7 = IF(@idx = 0,
    'CREATE INDEX idx_answer_similarity ON answer_records (account_id, exam_id, similarity_score)',
    'SELECT ''idx_answer_similarity already exists'' AS status'
);
PREPARE s7 FROM @sql7; EXECUTE s7; DEALLOCATE PREPARE s7;

-- Done
SELECT 'Migration v28 completed successfully' AS status;
