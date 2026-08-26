<?php
/**
 * CognitiveAnalyzer — Behavioral Time Analysis Engine.
 *
 * Calculates the EXPECTED time a student should spend on each question
 * based on cognitive science models (Hick's Law, Mental Chronometry),
 * then compares with ACTUAL time to detect impossibly fast answers.
 *
 * Scientific basis:
 *   - T_read:   Reading time based on word count + language factor
 *   - T_think:  Cognitive processing time (Hick's Law)
 *   - T_write:  Writing/execution time for text answers
 *   - T_total = T_read + T_think + T_write
 *
 * References:
 *   - Hick, W.H. (1952) — On the rate of gain of information
 *   - Fitts, P.M. (1954) — Information capacity of the motor system
 *   - Rayner, K. (2009) — Eye movements in reading (reading speeds)
 */
final class CognitiveAnalyzer
{
    /* ── Reading Speed Constants (words per second) ─────────── */
    private const READING_SPEED_ARABIC = 3.5;   // Native Arabic reader
    private const READING_SPEED_ENGLISH = 2.5;   // Arabic speaker reading English (L2)
    private const READING_SPEED_NATIVE_EN = 4.2; // Native English reader

    /* ── Typing Speed Constants (words per second) ──────────── */
    private const TYPING_SPEED = 0.6;  // ~36 WPM average

    /* ── Hick's Law constant (seconds per bit) ─────────────── */
    private const HICK_K = 0.83;  // Standard HCI constant

    /* ── Complexity Weights per question type ────────────────── */
    private const COMPLEXITY = [
        'truefalse'    => ['cw' => 1.0, 'choices' => 2, 'label' => 'True/False'],
        'multichoice'  => ['cw' => 2.0, 'choices' => 4, 'label' => 'MCQ'],
        'shortanswer'  => ['cw' => 1.5, 'choices' => 0, 'label' => 'Short Answer'],
        'essay'        => ['cw' => 4.0, 'choices' => 0, 'label' => 'Essay'],
        'matching'     => ['cw' => 2.5, 'choices' => 0, 'label' => 'Matching'],
        'calculated'   => ['cw' => 3.0, 'choices' => 4, 'label' => 'Calculated'],
        'default'      => ['cw' => 2.0, 'choices' => 4, 'label' => 'Unknown'],
    ];

    /* ── Estimated question word counts by type (when question text unavailable) */
    private const ESTIMATED_QUESTION_WORDS = [
        'truefalse'   => 15,
        'multichoice' => 25,
        'shortanswer' => 20,
        'essay'       => 40,
        'matching'    => 30,
        'calculated'  => 35,
        'default'     => 20,
    ];

    /* ── Suspicion Thresholds ────────────────────────────────── */
    private const THRESHOLD_CRITICAL = 0.20;  // < 20% of expected = critical
    private const THRESHOLD_HIGH     = 0.40;  // < 40% = high
    private const THRESHOLD_MEDIUM   = 0.60;  // < 60% = medium
    private const THRESHOLD_LOW      = 0.80;  // < 80% = low

    /* ── Language Detection ──────────────────────────────────── */

    /**
     * Detect dominant language of a text string.
     * Returns 'ar', 'en', or 'mixed'.
     */
    public static function detectLanguage(string $text): string
    {
        if ($text === '') return 'en';

        $arabic = 0;
        $latin = 0;
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $code = mb_ord(mb_substr($text, $i, 1));
            if ($code >= 0x0600 && $code <= 0x06FF) $arabic++;
            elseif ($code >= 0x0041 && $code <= 0x007A) $latin++;
        }

        $total = $arabic + $latin;
        if ($total === 0) return 'en';

        $arRatio = $arabic / $total;
        if ($arRatio > 0.5) return 'ar';
        if ($arRatio < 0.2) return 'en';
        return 'mixed';
    }

    /**
     * Get reading speed (words/sec) for a language relative to the student.
     * Assumes the student's native language is Arabic (configurable in future).
     */
    public static function readingSpeed(string $lang): float
    {
        return match ($lang) {
            'ar'    => self::READING_SPEED_ARABIC,
            'en'    => self::READING_SPEED_ENGLISH,
            default => (self::READING_SPEED_ARABIC + self::READING_SPEED_ENGLISH) / 2,
        };
    }

    /* ── Per-Question Time Calculation ──────────────────────── */

    /**
     * Normalize a Moodle question type CSS class to our key.
     */
    public static function normalizeQuestionType(?string $rawType): string
    {
        if ($rawType === null || $rawType === '') return 'default';

        $lower = strtolower(trim($rawType));

        if (str_contains($lower, 'truefalse') || str_contains($lower, 'tf')) return 'truefalse';
        if (str_contains($lower, 'multichoice') || str_contains($lower, 'choice') || str_contains($lower, 'mcq')) return 'multichoice';
        if (str_contains($lower, 'essay') || str_contains($lower, 'essay')) return 'essay';
        if (str_contains($lower, 'short') || str_contains($lower, 'numerical') || str_contains($lower, 'calculatedmulti')) return 'shortanswer';
        if (str_contains($lower, 'match')) return 'matching';
        if (str_contains($lower, 'calc')) return 'calculated';

        return 'default';
    }

    /**
     * Calculate expected reading time for a question.
     *
     * T_read = (question_words × L_factor) / S_avg
     *
     * @param int    $questionWordCount  Estimated words in the question text
     * @param string $lang               Detected language ('ar', 'en', 'mixed')
     * @param string $questionType       Normalized question type key
     */
    public static function calcReadTime(int $questionWordCount, string $lang, string $questionType): float
    {
        $speed = self::readingSpeed($lang);
        if ($speed <= 0) $speed = self::READING_SPEED_ARABIC;

        // Use provided count or estimate from type
        $words = $questionWordCount > 0
            ? $questionWordCount
            : (self::ESTIMATED_QUESTION_WORDS[$questionType] ?? self::ESTIMATED_QUESTION_WORDS['default']);

        return $words / $speed;
    }

    /**
     * Calculate expected cognitive thinking time using Hick's Law.
     *
     * T_think = k × log2(n + 1)
     *
     * Where n = number of choices, k = processing constant.
     * For essay/shortanswer, we use complexity weight × reading time instead.
     *
     * @param string $questionType Normalized question type key
     * @param float  $tRead        Reading time (used for non-choice questions)
     */
    public static function calcThinkTime(string $questionType, float $tRead): float
    {
        $spec = self::COMPLEXITY[$questionType] ?? self::COMPLEXITY['default'];

        if ($spec['choices'] > 0) {
            // Hick's Law for choice-based questions
            $n = $spec['choices'];
            $hickTime = self::HICK_K * log($n + 1, 2);

            // Add reading-comprehension time (1x for simple, up to 2x for complex)
            return $hickTime + ($tRead * $spec['cw'] * 0.5);
        }

        // For open-ended: T_think = T_read × C_w
        return $tRead * $spec['cw'];
    }

    /**
     * Calculate expected writing/execution time.
     *
     * MCQ/T/F: simple click ≈ 1.5s (Fitts's Law)
     * Essay/Short: answer_words / typing_speed
     *
     * @param string $questionType    Normalized question type key
     * @param int    $answerWordCount Words in the student's answer
     * @param int    $answerLength    Characters in the answer
     */
    public static function calcWriteTime(string $questionType, int $answerWordCount, int $answerLength): float
    {
        $spec = self::COMPLEXITY[$questionType] ?? self::COMPLEXITY['default'];

        if ($spec['choices'] > 0) {
            // Mouse click time (Fitts's Law approximation)
            return 1.5;
        }

        // Open-ended: typing time
        $words = $answerWordCount;
        if ($words === 0 && $answerLength > 0) {
            // Estimate words from characters (~5 chars per word average)
            $words = max(1, (int)ceil($answerLength / 5));
        }

        if ($words === 0) return 1.0; // Minimum execution time

        return $words / self::TYPING_SPEED;
    }

    /**
     * Calculate total expected time for one question.
     *
     * @return array{t_read:float, t_think:float, t_write:float, t_total:float, lang:string, question_type:string}
     */
    public static function calcExpectedTime(
        string $answerText,
        string $questionType,
        int $answerWordCount = 0,
        int $answerLength = 0,
        int $questionWordCount = 0
    ): array {
        $qType = self::normalizeQuestionType($questionType);
        $lang = self::detectLanguage($answerText);

        $tRead = self::calcReadTime($questionWordCount, $lang, $qType);
        $tThink = self::calcThinkTime($qType, $tRead);
        $tWrite = self::calcWriteTime($qType, $answerWordCount, $answerLength);

        $tTotal = $tRead + $tThink + $tWrite;

        return [
            't_read'       => round($tRead, 2),
            't_think'      => round($tThink, 2),
            't_write'      => round($tWrite, 2),
            't_total'      => round($tTotal, 2),
            'lang'         => $lang,
            'question_type'=> $qType,
        ];
    }

    /* ── Per-Question Suspicion Score ───────────────────────── */

    /**
     * Calculate how suspicious a single answer is based on time analysis.
     *
     * Returns a score 0-100 where:
     *   0   = normal (T_actual >= 60% of T_expected)
     *   25  = low suspicion
     *   50  = medium suspicion
     *   75  = high suspicion
     *   100 = critical (impossibly fast)
     *
     * @param float $tExpected Expected time in seconds
     * @param float $tActual   Actual time in seconds (0 if unknown)
     * @param bool  $hasPaste  Whether paste event occurred for this question
     * @param bool  $noTyping  Whether answer appeared without typing
     */
    public static function suspicionScore(
        float $tExpected,
        float $tActual,
        bool $hasPaste = false,
        bool $noTyping = false
    ): array {
        if ($tExpected <= 0) {
            return ['score' => 0, 'level' => 'safe', 'reason' => 'insufficient_data'];
        }

        // Base ratio
        $ratio = ($tActual > 0) ? ($tActual / $tExpected) : 0;

        // Determine base score from ratio
        if ($ratio < self::THRESHOLD_CRITICAL) {
            $score = 90 + (1 - $ratio / self::THRESHOLD_CRITICAL) * 10; // 90-100
            $reason = 'impossibly_fast';
        } elseif ($ratio < self::THRESHOLD_HIGH) {
            $score = 65 + (1 - ($ratio - self::THRESHOLD_CRITICAL) / (self::THRESHOLD_HIGH - self::THRESHOLD_CRITICAL)) * 25; // 65-90
            $reason = 'very_fast';
        } elseif ($ratio < self::THRESHOLD_MEDIUM) {
            $score = 35 + (1 - ($ratio - self::THRESHOLD_HIGH) / (self::THRESHOLD_MEDIUM - self::THRESHOLD_HIGH)) * 30; // 35-65
            $reason = 'suspiciously_fast';
        } elseif ($ratio < self::THRESHOLD_LOW) {
            $score = 10 + (1 - ($ratio - self::THRESHOLD_MEDIUM) / (self::THRESHOLD_LOW - self::THRESHOLD_MEDIUM)) * 25; // 10-35
            $reason = 'slightly_fast';
        } else {
            $score = max(0, 10 * (1 - ($ratio - self::THRESHOLD_LOW) / (1 - self::THRESHOLD_LOW))); // 0-10
            $reason = 'normal';
        }

        // Boost: paste event without typing = near-certain AI/cheating
        if ($hasPaste && $noTyping) {
            $score = max($score, 95);
            $reason = 'paste_no_typing';
        } elseif ($hasPaste && $ratio < self::THRESHOLD_MEDIUM) {
            $score = max($score, 70);
            $reason = 'fast_with_paste';
        } elseif ($noTyping) {
            $score = max($score, 60);
            $reason = 'no_typing_detected';
        }

        $level = match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default      => 'low',
        };

        return [
            'score'  => min(100, max(0, (int)round($score))),
            'level'  => $level,
            'ratio'  => round($ratio, 3),
            'reason' => $reason,
        ];
    }

    /* ── Session-Level Analysis ─────────────────────────────── */

    /**
     * Analyze all questions in a session and produce aggregate cognitive metrics.
     *
     * @param array $records  Array of answer_records rows for this session
     * @param array $events   Array of answer_changed events for this session (for T_actual)
     * @return array{questions:array, avg_suspicion:float, max_suspicion:float, total_questions:int, flagged:int}
     */
    public static function analyzeSession(array $records, array $events = []): array
    {
        if (empty($records)) {
            return [
                'questions'      => [],
                'avg_suspicion'  => 0,
                'max_suspicion'  => 0,
                'total_questions'=> 0,
                'flagged'        => 0,
                'cognitive_score'=> 0,
            ];
        }

        // Build T_actual from events timeline
        $questionTimes = self::buildQuestionTimelines($events);

        $questions = [];
        $totalSuspicion = 0;
        $maxSuspicion = 0;
        $flagged = 0;

        foreach ($records as $rec) {
            $qId = $rec['question_id'] ?? '';
            if ($qId === '' || $qId === null) continue;

            $answerText  = $rec['answer_text'] ?? '';
            $qType       = $rec['question_type'] ?? '';
            $wordCount   = (int)($rec['word_count'] ?? 0);
            $answerLen   = (int)($rec['answer_length'] ?? 0);
            $typingMs    = (int)($rec['typing_duration_ms'] ?? 0);
            $pasteLen    = (int)($rec['paste_length'] ?? 0);
            $copyCount   = (int)($rec['copy_count_from_question'] ?? 0);

            // Calculate expected time
            $expected = self::calcExpectedTime($answerText, $qType, $wordCount, $answerLen);

            // Determine actual time
            $tActual = 0;
            $hasPaste = ($pasteLen > 0);
            $noTyping = false;

            // Method 1: From event timeline
            if (isset($questionTimes[$qId])) {
                $tActual = $questionTimes[$qId]['duration'];
            }

            // Method 2: Use typing_duration_ms for text answers
            if ($tActual <= 0 && $typingMs > 0) {
                $tActual = $typingMs / 1000.0;
            }

            // Method 3: If no typing at all for a text answer, suspicious
            if ($tActual <= 0 && $wordCount > 3) {
                $noTyping = true;
                // Estimate from answer length as if pasted instantly
                $tActual = max(0.1, $answerLen / 500.0); // Fake: ~500 chars/ms = instant
            }

            // Calculate suspicion
            $suspicion = self::suspicionScore($expected['t_total'], $tActual, $hasPaste, $noTyping);

            $totalSuspicion += $suspicion['score'];
            if ($suspicion['score'] > $maxSuspicion) {
                $maxSuspicion = $suspicion['score'];
            }
            if ($suspicion['score'] >= 50) {
                $flagged++;
            }

            $questions[] = [
                'question_id'       => $qId,
                'question_type'     => $expected['question_type'],
                'language'          => $expected['lang'],
                'answer_words'      => $wordCount,
                'answer_chars'      => $answerLen,
                't_expected'        => $expected['t_total'],
                't_read'            => $expected['t_read'],
                't_think'           => $expected['t_think'],
                't_write'           => $expected['t_write'],
                't_actual'          => round($tActual, 2),
                'suspicion_score'   => $suspicion['score'],
                'suspicion_level'   => $suspicion['level'],
                'suspicion_reason'  => $suspicion['reason'],
                'has_paste'         => $hasPaste,
                'no_typing'         => $noTyping,
                'copy_count'        => $copyCount,
            ];
        }

        $count = count($questions);
        $avgSuspicion = $count > 0 ? round($totalSuspicion / $count, 1) : 0;

        // Overall cognitive score (weighted toward flagged questions)
        $cognitiveScore = self::computeCognitiveScore($questions);

        return [
            'questions'       => $questions,
            'avg_suspicion'   => $avgSuspicion,
            'max_suspicion'   => $maxSuspicion,
            'total_questions' => $count,
            'flagged'         => $flagged,
            'cognitive_score' => $cognitiveScore,
        ];
    }

    /**
     * Compute an overall 0-100 cognitive suspicion score for a session.
     * Heavily weighted toward worst-case questions.
     */
    private static function computeCognitiveScore(array $questions): int
    {
        if (empty($questions)) return 0;

        // 60% worst question + 25% average + 15% flagged ratio
        $maxScore = 0;
        $sum = 0;
        $flaggedCount = 0;

        foreach ($questions as $q) {
            $s = $q['suspicion_score'];
            $sum += $s;
            if ($s > $maxScore) $maxScore = $s;
            if ($s >= 50) $flaggedCount++;
        }

        $avg = $sum / count($questions);
        $flagRatio = count($questions) > 0 ? ($flaggedCount / count($questions)) * 100 : 0;

        $score = ($maxScore * 0.60) + ($avg * 0.25) + ($flagRatio * 0.15);

        return min(100, max(0, (int)round($score)));
    }

    /**
     * Build per-question timelines from answer_changed events.
     * T_actual = span from first to last event for each question.
     */
    private static function buildQuestionTimelines(array $events): array
    {
        $timelines = [];

        foreach ($events as $ev) {
            $payload = json_decode($ev['payload'] ?? '{}', true) ?: [];
            $meta = $payload['metadata'] ?? [];
            $qId = $meta['question_id'] ?? $meta['questionId'] ?? '';
            if ($qId === '') continue;

            $time = $ev['event_time'] ?? '';
            if ($time === '') continue;

            if (!isset($timelines[$qId])) {
                $timelines[$qId] = [
                    'first' => $time,
                    'last'  => $time,
                ];
            } else {
                if ($time < $timelines[$qId]['first']) $timelines[$qId]['first'] = $time;
                if ($time > $timelines[$qId]['last'])  $timelines[$qId]['last'] = $time;
            }
        }

        $result = [];
        foreach ($timelines as $qId => $tl) {
            $first = strtotime($tl['first']);
            $last = strtotime($tl['last']);
            $duration = max(0, $last - $first);
            $result[$qId] = [
                'first'    => $tl['first'],
                'last'     => $tl['last'],
                'duration' => $duration,
            ];
        }

        return $result;
    }

    /* ── External Device Lookup Detection ──────────────────── */

    /**
     * v28: Detect patterns suggesting student is using an external device (phone, second screen).
     *
     * Signals:
     *   1. Mouse freeze >15s followed by instant answer (<1s): student looked at phone, then clicked
     *   2. Long essay (>40 words) with 0 backspace: text was pasted or typed from external source
     *   3. Constant overhead latency: answers appear in bursts rather than gradually
     *
     * @param array $events   All events for this session
     * @param array $records  All answer_records for this session
     * @return array{probability:float, signals:array}
     */
    public static function detectExternalLookup(array $events, array $records = []): array
    {
        $signals = [];
        $score = 0;

        // Signal 1: Mouse freeze >15s followed by instant answer (<1s)
        $mouseEvents = [];
        foreach ($events as $ev) {
            $type = $ev['event_type'] ?? '';
            if (in_array($type, ['mousemove', 'click', 'answer_changed', 'paste'], true)) {
                $mouseEvents[] = [
                    'type' => $type,
                    'time' => strtotime($ev['event_time'] ?? '0'),
                ];
            }
        }
        if (count($mouseEvents) >= 2) {
            for ($i = 1; $i < count($mouseEvents); $i++) {
                $gap = $mouseEvents[$i]['time'] - $mouseEvents[$i - 1]['time'];
                // Long freeze followed by instant action
                if ($gap > 15 && $mouseEvents[$i]['type'] === 'answer_changed') {
                    $signals[] = 'mouse_freeze_then_answer (' . round($gap, 1) . 's gap)';
                    $score += 25;
                    break;
                }
            }
        }

        // Signal 2: Long essay (>40 words) with 0 backspace
        $totalBackspace = 0;
        foreach ($events as $ev) {
            $payload = json_decode($ev['payload'] ?? '{}', true) ?: [];
            $meta = $payload['metadata'] ?? [];
            $totalBackspace += (int)($meta['typing']['backspace_count'] ?? 0);
        }
        foreach ($records as $rec) {
            $wordCount = (int)($rec['word_count'] ?? 0);
            $qType = strtolower($rec['question_type'] ?? '');
            if (str_contains($qType, 'essay') && $wordCount > 40 && $totalBackspace === 0) {
                $signals[] = 'essay_no_backspace (' . $wordCount . ' words, 0 backspace)';
                $score += 30;
                break;
            }
        }

        // Signal 3: Constant overhead latency (answers appear in bursts)
        $answerTimes = [];
        foreach ($events as $ev) {
            if (($ev['event_type'] ?? '') === 'answer_changed') {
                $answerTimes[] = strtotime($ev['event_time'] ?? '0');
            }
        }
        if (count($answerTimes) >= 5) {
            $gaps = [];
            for ($i = 1; $i < count($answerTimes); $i++) {
                $gaps[] = $answerTimes[$i] - $answerTimes[$i - 1];
            }
            // Check for suspiciously regular intervals (>80% of gaps within 1s of each other)
            $avgGap = array_sum($gaps) / count($gaps);
            if ($avgGap > 0) {
                $regular = 0;
                foreach ($gaps as $g) {
                    if (abs($g - $avgGap) < 1.0) $regular++;
                }
                $regularRatio = $regular / count($gaps);
                if ($regularRatio > 0.8 && count($gaps) >= 5) {
                    $signals[] = 'regular_answer_intervals (ratio=' . round($regularRatio, 2) . ')';
                    $score += 20;
                }
            }
        }

        $probability = min(100, $score);

        return [
            'probability' => $probability,
            'signals'     => $signals,
        ];
    }

    /**
     * Fetch all events for a session (used by external_lookup detection).
     */
    private static function fetchAllEvents(PDO $db, string $sessionId, int $accountId): array
    {
        $st = $db->prepare(
            "SELECT event_type, event_time, payload
             FROM events
             WHERE session_id = :s AND account_id = :a
             ORDER BY event_time"
        );
        $st->execute([':s' => $sessionId, ':a' => $accountId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ── Bulk Analysis for Exam ─────────────────────────────── */

    /**
     * Analyze cognitive time for all sessions in an exam.
     * Updates session_summaries with cognitive_score.
     *
     * @return array{analyzed:int, flagged_sessions:int}
     */
    public static function analyzeExam(int $accountId, int $examId): array
    {
        $db = Database::connection();

        $exam = Database::fetchOne(
            'SELECT id, moodle_quiz_id, account_id FROM exams WHERE id = ? OR moodle_quiz_id = ? ORDER BY (account_id = ?) DESC LIMIT 1',
            [$examId, $examId, $accountId]
        );
        $intId = $exam ? (int)$exam['id'] : $examId;
        $quizId = $exam ? (int)$exam['moodle_quiz_id'] : $examId;

        // Get all sessions for this exam
        $stmt = $db->prepare(
            "SELECT session_id, student_id FROM session_summaries
             WHERE (exam_id = :eid OR exam_id = :qid) AND (account_id = :a OR account_id = 0)"
        );
        $stmt->execute([':eid' => $intId, ':qid' => $quizId, ':a' => $accountId]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($sessions)) {
            return ['analyzed' => 0, 'flagged_sessions' => 0];
        }

        $analyzed = 0;
        $flaggedSessions = 0;

        $updateSt = $db->prepare(
            "UPDATE session_summaries
             SET cognitive_score = :cs, cognitive_details = :cd
             WHERE session_id = :sid"
        );

        // Try to also persist external_lookup_probability if column exists
        $updateExtSt = null;
        try {
            $db->exec("SELECT external_lookup_probability FROM session_summaries LIMIT 0");
            $updateExtSt = $db->prepare(
                "UPDATE session_summaries SET external_lookup_probability = :elp, external_lookup_signals = :els WHERE session_id = :sid"
            );
        } catch (\Throwable $e) {
            // Column doesn't exist yet — ignore
        }

        foreach ($sessions as $s) {
            $sid = $s['session_id'];

            // Get answer_records for this session
            $recSt = $db->prepare(
                "SELECT question_id, question_type, answer_text, word_count, answer_length,
                        typing_duration_ms, paste_length, copy_count_from_question, created_at
                 FROM answer_records
                 WHERE session_id = :s AND (account_id = :a OR account_id = 0) AND (exam_id = :eid OR exam_id = :qid)
                 ORDER BY created_at"
            );
            $recSt->execute([':s' => $sid, ':a' => $accountId, ':eid' => $intId, ':qid' => $quizId]);
            $records = $recSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Get answer_changed events for timeline
            $evSt = $db->prepare(
                "SELECT payload, event_time, event_type
                 FROM events
                 WHERE session_id = :s AND (account_id = :a OR account_id = 0) AND event_type = 'answer_changed'
                 ORDER BY event_time"
            );
            $evSt->execute([':s' => $sid, ':a' => $accountId]);
            $events = $evSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $result = self::analyzeSession($records, $events);

            // v28: Detect external device lookup
            $allEvents = self::fetchAllEvents($db, $sid, $accountId);
            $extLookup = self::detectExternalLookup($allEvents, $records);

            // Persist
            $details = json_encode([
                'avg_suspicion'   => $result['avg_suspicion'],
                'max_suspicion'   => $result['max_suspicion'],
                'total_questions' => $result['total_questions'],
                'flagged'         => $result['flagged'],
                'external_lookup_probability' => $extLookup['probability'],
                'external_lookup_signals'     => $extLookup['signals'],
                'questions'       => array_map(fn($q) => [
                    'question_id'      => $q['question_id'],
                    'question_type'    => $q['question_type'],
                    'language'         => $q['language'],
                    't_expected'       => $q['t_expected'],
                    't_actual'         => $q['t_actual'],
                    'suspicion_score'  => $q['suspicion_score'],
                    'suspicion_level'  => $q['suspicion_level'],
                    'suspicion_reason' => $q['suspicion_reason'],
                ], $result['questions']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                $updateSt->execute([
                    ':cs'  => $result['cognitive_score'],
                    ':cd'  => $details,
                    ':sid' => $sid,
                ]);
                $analyzed++;

                // Persist external_lookup_probability if possible
                if ($updateExtSt !== null) {
                    try {
                        $updateExtSt->execute([
                            ':elp' => $extLookup['probability'],
                            ':els' => json_encode($extLookup['signals'], JSON_UNESCAPED_UNICODE),
                            ':sid' => $sid,
                        ]);
                    } catch (\Throwable $e) {
                        // Column doesn't exist — ignore
                    }
                }

                if ($result['cognitive_score'] >= 50) {
                    $flaggedSessions++;
                }
            } catch (\Throwable $e) {
                error_log("CognitiveAnalyzer persist error: " . $e->getMessage());
            }
        }

        return [
            'analyzed'         => $analyzed,
            'flagged_sessions' => $flaggedSessions,
        ];
    }
}
