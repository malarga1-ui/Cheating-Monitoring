<?php
/**
 * SimilarityEngine v11 — Pairwise student answer similarity detection.
 *
 * Compares each student's answers against all other students in the same exam.
 * Uses combined Jaccard + Cosine similarity on word sets — no external API.
 *
 * Approach:
 *   1. For each question, compare every pair of students.
 *   2. Compute word-level Jaccard similarity.
 *   3. Compute Cosine similarity on word frequency vectors.
 *   4. Weighted combination: 60% Jaccard + 40% Cosine.
 *   5. Aggregate per-question similarities into a session-pair score.
 *   6. Flag pairs above threshold.
 *
 * Scoring:
 *   combined > 0.90  = 100 (near-identical)
 *   combined > 0.75  = 70
 *   combined > 0.60  = 45
 *   combined > 0.50  = 25
 *   below            = 0
 *
 * Overall session similarity = max score vs any other student.
 */

final class SimilarityEngine
{
    /* ── Config ─────────────────────────────────────────────────── */
    private const MIN_QUESTIONS     = 2;
    private const HIGH_THRESHOLD    = 70;
    private const CRIT_THRESHOLD    = 90;

    /* ── Public API ─────────────────────────────────────────────── */

    /**
     * Run full similarity analysis for an exam.
     *
     * @return array{pairs: array, sessions: array<string,array>}
     */
    public static function analyzeExam(int $accountId, int $examId): array
    {
        $db = Database::connection();

        // 1. Load all answer records for this exam
        $allAnswers = self::loadExamAnswers($db, $accountId, $examId);
        if (empty($allAnswers)) {
            return ['pairs' => [], 'sessions' => []];
        }

        // 2. Group by student
        $byStudent = self::groupByStudent($allAnswers);

        // 3. Pairwise comparison
        $pairs = self::compareAllPairs($byStudent);

        // 4. Persist pairs
        self::persistPairs($db, $accountId, $examId, $pairs);

        // 5. Build per-session summary
        $sessions = self::buildSessionSummary($pairs);

        // 6. Update session_summaries
        self::persistSessionScores($db, $sessions);

        return ['pairs' => $pairs, 'sessions' => $sessions];
    }

    /**
     * Compare a single session against all others (for real-time scoring).
     */
    public static function scoreSession(int $accountId, int $examId, string $sessionId): array
    {
        $db = Database::connection();

        $allAnswers = self::loadExamAnswers($db, $accountId, $examId);
        if (empty($allAnswers)) {
            return ['max_similarity' => 0, 'match_count' => 0, 'worst_pair' => null];
        }

        $byStudent = self::groupByStudent($allAnswers);
        $targetAnswers = $byStudent[$sessionId] ?? null;
        if (!$targetAnswers) {
            return ['max_similarity' => 0, 'match_count' => 0, 'worst_pair' => null];
        }

        $worst     = null;
        $maxSim    = 0;
        $matchCount = 0;

        foreach ($byStudent as $otherSid => $otherAnswers) {
            if ($otherSid === $sessionId) continue;
            $result = self::compareTwo($targetAnswers, $otherAnswers);
            if ($result['similarity'] > $maxSim) {
                $maxSim = $result['similarity'];
                $worst  = $result;
            }
            if ($result['similarity'] >= self::HIGH_THRESHOLD) {
                $matchCount++;
            }
        }

        return [
            'max_similarity' => $maxSim,
            'match_count'    => $matchCount,
            'worst_pair'     => $worst,
        ];
    }

    /* ── Loaders ──────────────────────────────────────────────── */

    private static function loadExamAnswers(PDO $db, int $accountId, int $examId): array
    {
        $st = $db->prepare(
            "SELECT session_id, student_id, question_id, answer_text, answer_length, word_count
             FROM answer_records
             WHERE account_id = :a AND exam_id = :e
             ORDER BY student_id, question_id"
        );
        $st->execute([':a' => $accountId, ':e' => $examId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function groupByStudent(array $answers): array
    {
        $groups = [];
        foreach ($answers as $a) {
            $sid = $a['session_id'];
            $qid = $a['question_id'];
            if (!isset($groups[$sid])) {
                $groups[$sid] = [
                    'student_id' => (int)$a['student_id'],
                    'questions'  => [],
                ];
            }
            $groups[$sid]['questions'][$qid] = $a;
        }
        return $groups;
    }

    /* ── Comparison ──────────────────────────────────────────── */

    private static function compareAllPairs(array $byStudent): array
    {
        $students = array_keys($byStudent);
        $count    = count($students);
        $pairs    = [];

        // O(n²) comparison — acceptable for < 500 students per exam
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $byStudent[$students[$i]];
                $b = $byStudent[$students[$j]];
                $result = self::compareTwo($a, $b);

                $pairs[] = [
                    'session_a'  => $students[$i],
                    'session_b'  => $students[$j],
                    'student_a'  => $a['student_id'],
                    'student_b'  => $b['student_id'],
                    'similarity' => $result['similarity'],
                    'matching'   => $result['matching'],
                    'total'      => $result['total'],
                ];
            }
        }

        // Sort by similarity descending
        usort($pairs, fn($x, $y) => $y['similarity'] <=> $x['similarity']);
        return $pairs;
    }

    private static function compareTwo(array $a, array $b): array
    {
        $aQ = $a['questions'];
        $bQ = $b['questions'];

        $commonQ = array_intersect_key($aQ, $bQ);
        $total   = count($commonQ);
        if ($total < self::MIN_QUESTIONS) {
            return ['similarity' => 0, 'matching' => 0, 'total' => $total];
        }

        $sumSim = 0;
        $matching = 0;

        foreach ($commonQ as $qid => $aAns) {
            $bAns = $bQ[$qid];
            $textA = $aAns['answer_text'] ?? '';
            $textB = $bAns['answer_text'] ?? '';

            $jaccard = self::jaccardSimilarity($textA, $textB);
            $cosine  = self::cosineSimilarity($textA, $textB);

            $combined = ($jaccard * 0.6) + ($cosine * 0.4);
            $sumSim += $combined;
            if ($combined >= 0.80) {
                $matching++;
            }
        }

        $avgSim = ($sumSim / $total) * 100;

        return [
            'similarity' => (int)round(min(100, $avgSim)),
            'matching'   => $matching,
            'total'      => $total,
        ];
    }

    /**
     * Jaccard similarity on word-level tokens.
     */
    private static function jaccardSimilarity(string $textA, string $textB): float
    {
        $textA = mb_strtolower(trim($textA));
        $textB = mb_strtolower(trim($textB));

        if ($textA === '' && $textB === '') return 1.0;
        if ($textA === '' || $textB === '') return 0.0;

        $wordsA = self::tokenize($textA);
        $wordsB = self::tokenize($textB);

        if (empty($wordsA) && empty($wordsB)) return 1.0;
        if (empty($wordsA) || empty($wordsB)) return 0.0;

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union        = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Cosine similarity on word frequency vectors.
     * Better for detecting topical similarity even when wording differs.
     */
    private static function cosineSimilarity(string $textA, string $textB): float
    {
        $textA = mb_strtolower(trim($textA));
        $textB = mb_strtolower(trim($textB));

        if ($textA === '' && $textB === '') return 1.0;
        if ($textA === '' || $textB === '') return 0.0;

        $wordsA = self::tokenize($textA);
        $wordsB = self::tokenize($textB);

        if (empty($wordsA) || empty($wordsB)) return 0.0;

        $freqA = array_count_values($wordsA);
        $freqB = array_count_values($wordsB);

        $allWords = array_unique(array_merge(array_keys($freqA), array_keys($freqB)));

        $dotProduct = 0;
        $magA = 0;
        $magB = 0;

        foreach ($allWords as $word) {
            $a = $freqA[$word] ?? 0;
            $b = $freqB[$word] ?? 0;
            $dotProduct += $a * $b;
            $magA += $a * $a;
            $magB += $b * $b;
        }

        $magA = sqrt($magA);
        $magB = sqrt($magB);

        if ($magA == 0 || $magB == 0) return 0.0;

        return $dotProduct / ($magA * $magB);
    }

    /**
     * Tokenize text into unique words (Arabic + Latin + digits).
     */
    private static function tokenize(string $text): array
    {
        $words = preg_split('/[^\p{Arabic}\p{L}\d]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_map('mb_strtolower', $words);
        $words = array_filter($words, fn($w) => mb_strlen($w) > 1);
        return array_values($words);
    }

    /* ── Persistence ──────────────────────────────────────────── */

    private static function persistPairs(PDO $db, int $accountId, int $examId, array $pairs): void
    {
        // Clear old pairs for this exam
        $db->prepare("DELETE FROM similarity_pairs WHERE account_id = :a AND exam_id = :e")
           ->execute([':a' => $accountId, ':e' => $examId]);

        $st = $db->prepare(
            "INSERT INTO similarity_pairs
             (account_id, exam_id, student_a_id, student_b_id, similarity_pct, matching_questions, total_questions)
             VALUES (:a, :e, :sa, :sb, :sim, :match, :total)"
        );

        foreach ($pairs as $p) {
            if ($p['similarity'] < 10) continue; // skip noise
            $st->execute([
                ':a'     => $accountId,
                ':e'     => $examId,
                ':sa'    => $p['student_a'],
                ':sb'    => $p['student_b'],
                ':sim'   => $p['similarity'],
                ':match' => $p['matching'],
                ':total' => $p['total'],
            ]);
        }
    }

    private static function buildSessionSummary(array $pairs): array
    {
        $sessions = [];
        foreach ($pairs as $p) {
            if ($p['similarity'] < 10) continue;

            $sA = $p['session_a'];
            $sB = $p['session_b'];

            if (!isset($sessions[$sA]) || $sessions[$sA]['max_similarity'] < $p['similarity']) {
                $sessions[$sA] = [
                    'max_similarity' => $p['similarity'],
                    'match_count'    => $p['similarity'] >= self::HIGH_THRESHOLD ? 1 : 0,
                    'student_id'     => $p['student_a'],
                ];
            }
            if (!isset($sessions[$sB]) || $sessions[$sB]['max_similarity'] < $p['similarity']) {
                $sessions[$sB] = [
                    'max_similarity' => $p['similarity'],
                    'match_count'    => $p['similarity'] >= self::HIGH_THRESHOLD ? 1 : 0,
                    'student_id'     => $p['student_b'],
                ];
            }
            // Count matches
            if ($p['similarity'] >= self::HIGH_THRESHOLD) {
                if (isset($sessions[$sA])) $sessions[$sA]['match_count'] = ($sessions[$sA]['match_count'] ?? 0) + 1;
                if (isset($sessions[$sB])) $sessions[$sB]['match_count'] = ($sessions[$sB]['match_count'] ?? 0) + 1;
            }
        }
        return $sessions;
    }

    private static function persistSessionScores(PDO $db, array $sessions): void
    {
        $st = $db->prepare(
            "UPDATE session_summaries
             SET similarity_max_score   = :max,
                 similarity_match_count = :match
             WHERE session_id = :s"
        );
        foreach ($sessions as $sid => $data) {
            $st->execute([
                ':max'   => $data['max_similarity'],
                ':match' => $data['match_count'],
                ':s'     => $sid,
            ]);
        }
    }
}
