<?php
/**
 * SimilarityEngine — Word Trigram Cosine similarity (Eq 3.9-3.11).
 *
 * Algorithm:
 *   1. For each question, compare every pair of students.
 *   2. Compute Word Trigram Cosine similarity (Eq 3.9).
 *   3. Binary match: m_{iq} = 1 if max cosine ≥ τ, else 0 (Eq 3.10).
 *   4. S_i = (1/Q_S) × Σ m_{iq} (Eq 3.11).
 *
 * Pairwise threshold: τ = 0.75 (75%).
 * Stored as percentage in DB (0-100), used as proportion (0-1) in RiskEngine.
 */

final class SimilarityEngine
{
    private const MIN_QUESTIONS = 2;
    private const PAIR_THRESHOLD = 0.75; // τ = 0.75

    /* ── Public API ─────────────────────────────────────────────── */

    /**
     * Run full similarity analysis for an exam.
     *
     * @return array{pairs: array, sessions: array<string,array>}
     */
    public static function analyzeExam(int $accountId, int $examId): array
    {
        $db = Database::connection();

        $allAnswers = self::loadExamAnswers($db, $accountId, $examId);
        if (empty($allAnswers)) {
            return ['pairs' => [], 'sessions' => []];
        }

        $byStudent = self::groupByStudent($allAnswers);
        $pairs = self::compareAllPairs($byStudent);
        self::persistPairs($db, $accountId, $examId, $pairs);

        $sessions = self::buildSessionSummary($pairs);
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

        $worst = null;
        $maxSim = 0;
        $matchCount = 0;

        foreach ($byStudent as $otherSid => $otherAnswers) {
            if ($otherSid === $sessionId) continue;
            $result = self::compareTwo($targetAnswers, $otherAnswers);
            if ($result['similarity'] > $maxSim) {
                $maxSim = $result['similarity'];
                $worst = $result;
            }
            if ($result['matched'] > 0) {
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

    /* ── Pairwise Comparison (Eq 3.9-3.10) ───────────────────── */

    private static function compareAllPairs(array $byStudent): array
    {
        $students = array_keys($byStudent);
        $count = count($students);
        $pairs = [];

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
                    'matched'    => $result['matched'],
                    'total'      => $result['total'],
                ];
            }
        }

        usort($pairs, fn($x, $y) => $y['similarity'] <=> $x['similarity']);
        return $pairs;
    }

    /**
     * Compare two students using Word Trigram Cosine similarity.
     *
     * For each question:
     *   - Compute cosine similarity on word trigram frequency vectors
     *   - m_{iq} = 1 if cosine ≥ τ (0.75), else 0
     *
     * Return: similarity as max cosine (0-100), matched question count, total questions.
     */
    private static function compareTwo(array $a, array $b): array
    {
        $aQ = $a['questions'];
        $bQ = $b['questions'];
        $commonQ = array_intersect_key($aQ, $bQ);
        $total = count($commonQ);

        if ($total < self::MIN_QUESTIONS) {
            return ['similarity' => 0, 'matched' => 0, 'total' => $total];
        }

        $matchingQuestions = 0;
        $maxCosine = 0.0;

        foreach ($commonQ as $qid => $aAns) {
            $bAns = $bQ[$qid];
            $textA = $aAns['answer_text'] ?? '';
            $textB = $bAns['answer_text'] ?? '';

            $cosine = self::trigramCosine($textA, $textB);
            $maxCosine = max($maxCosine, $cosine);

            if ($cosine >= self::PAIR_THRESHOLD) {
                $matchingQuestions++;
            }
        }

        // S_i as proportion of matched questions (Eq 3.11)
        $similarityPct = $total > 0 ? (int)round(($matchingQuestions / $total) * 100) : 0;

        return [
            'similarity' => min(100, $similarityPct),
            'matched'    => $matchingQuestions,
            'total'      => $total,
        ];
    }

    /* ── Word Trigram Cosine (Eq 3.9) ────────────────────────── */

    /**
     * Compute cosine similarity on word trigram frequency vectors.
     *
     * Steps:
     *   1. Tokenize texts into words
     *   2. Generate trigrams (consecutive word triples)
     *   3. Build frequency vectors
     *   4. Compute cosine similarity
     */
    private static function trigramCosine(string $textA, string $textB): float
    {
        $textA = mb_strtolower(trim($textA));
        $textB = mb_strtolower(trim($textB));

        if ($textA === '' && $textB === '') return 1.0;
        if ($textA === '' || $textB === '') return 0.0;

        $wordsA = self::tokenize($textA);
        $wordsB = self::tokenize($textB);

        // Need at least 3 words to form a trigram
        if (count($wordsA) < 3 && count($wordsB) < 3) {
            // Fall back to word-level cosine for very short texts
            return self::wordCosine($wordsA, $wordsB);
        }

        $trigramsA = self::wordTrigrams($wordsA);
        $trigramsB = self::wordTrigrams($wordsB);

        if (empty($trigramsA) && empty($trigramsB)) return 1.0;
        if (empty($trigramsA) || empty($trigramsB)) return 0.0;

        // Build frequency vectors
        $freqA = array_count_values($trigramsA);
        $freqB = array_count_values($trigramsB);

        $allKeys = array_unique(array_merge(array_keys($freqA), array_keys($freqB)));

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($allKeys as $key) {
            $a = $freqA[$key] ?? 0;
            $b = $freqB[$key] ?? 0;
            $dot += $a * $b;
            $magA += $a * $a;
            $magB += $b * $b;
        }

        $magA = sqrt($magA);
        $magB = sqrt($magB);

        if ($magA == 0.0 || $magB == 0.0) return 0.0;

        return $dot / ($magA * $magB);
    }

    /**
     * Fallback word-level cosine for very short texts (< 3 words).
     */
    private static function wordCosine(array $wordsA, array $wordsB): float
    {
        if (empty($wordsA) && empty($wordsB)) return 1.0;
        if (empty($wordsA) || empty($wordsB)) return 0.0;

        $freqA = array_count_values($wordsA);
        $freqB = array_count_values($wordsB);

        $allKeys = array_unique(array_merge(array_keys($freqA), array_keys($freqB)));

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($allKeys as $key) {
            $a = $freqA[$key] ?? 0;
            $b = $freqB[$key] ?? 0;
            $dot += $a * $b;
            $magA += $a * $a;
            $magB += $b * $b;
        }

        $magA = sqrt($magA);
        $magB = sqrt($magB);

        if ($magA == 0.0 || $magB == 0.0) return 0.0;

        return $dot / ($magA * $magB);
    }

    /**
     * Generate word trigrams from a list of words.
     * Each trigram is "word_i word_{i+1} word_{i+2}".
     */
    private static function wordTrigrams(array $words): array
    {
        $n = count($words);
        if ($n < 3) return $words; // fallback to individual words

        $trigrams = [];
        for ($i = 0; $i <= $n - 3; $i++) {
            $trigrams[] = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
        }
        return $trigrams;
    }

    /**
     * Tokenize text into words (Arabic + Latin + digits).
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
        $db->prepare("DELETE FROM similarity_pairs WHERE account_id = :a AND exam_id = :e")
           ->execute([':a' => $accountId, ':e' => $examId]);

        $st = $db->prepare(
            "INSERT INTO similarity_pairs
             (account_id, exam_id, student_a_id, student_b_id, similarity_pct, matching_questions, total_questions)
             VALUES (:a, :e, :sa, :sb, :sim, :match, :total)"
        );

        foreach ($pairs as $p) {
            if ($p['similarity'] < 10) continue;
            $st->execute([
                ':a'     => $accountId,
                ':e'     => $examId,
                ':sa'    => $p['student_a'],
                ':sb'    => $p['student_b'],
                ':sim'   => $p['similarity'],
                ':match' => $p['matched'],
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
                    'match_count'    => $p['matched'],
                    'student_id'     => $p['student_a'],
                ];
            }
            if (!isset($sessions[$sB]) || $sessions[$sB]['max_similarity'] < $p['similarity']) {
                $sessions[$sB] = [
                    'max_similarity' => $p['similarity'],
                    'match_count'    => $p['matched'],
                    'student_id'     => $p['student_b'],
                ];
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
