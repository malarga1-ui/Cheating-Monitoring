-- Migration v13: Add cognitive time analysis columns to session_summaries
-- CognitiveAnalyzer: per-question time validation based on Hick's Law + Mental Chronometry

ALTER TABLE session_summaries
    ADD COLUMN cognitive_score INT DEFAULT 0 COMMENT '0-100 cognitive suspicion score from time analysis',
    ADD COLUMN cognitive_details JSON DEFAULT NULL COMMENT 'Detailed per-question cognitive analysis';

CREATE INDEX idx_session_summaries_cognitive ON session_summaries(cognitive_score);
