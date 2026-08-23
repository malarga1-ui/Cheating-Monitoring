<?php
/**
 * Risk engine v12 — Updated weights (50/20/15/15) + RapidAPI AI content detection.
 *
 * Categories and default weights (updated per thesis revision):
 *   1. Behavioral (سلوكي)                50%
 *   2. AI Detection (ذكاء اصطناعي)       20%  (RapidAPI failover chain)
 *   3. Network (شبكة)                    15%
 *   4. Similarity (تشابه)                15%
 *
 * Features:
 *   - Event cooldown: rapid repeated events of same type are dampened
 *   - Diminishing returns: 20 pastes != 4x the risk of 5 pastes
 *   - Soft cap per indicator before correlation dampening
 *   - Exam context normalization (question count + duration)
 */
final class RiskEngine
{
    private static ?array $indicatorsCache = null;

    private const DEFAULT_CATEGORIES = [
        'behavioral' => ['label_ar' => 'سلوكي',       'weight' => 50, 'description' => 'إشارات سلوك المتصفح والإجابات'],
        'ai'         => ['label_ar' => 'ذكاء اصطناعي', 'weight' => 20, 'description' => 'كشف أنماط الإجابات المولّدة بالـ AI (RapidAPI)'],
        'network'    => ['label_ar' => 'شبكة',         'weight' => 15, 'description' => 'كشف التجمع بنفس عنوان IP'],
        'similarity' => ['label_ar' => 'تشابه',        'weight' => 15, 'description' => 'مقارنة إجابات الطلاب ببعضهم'],
    ];

    /**
     * Thresholds: each value maps raw count → contribution factor (0.0 – 1.0).
     * Evaluated by finding the highest threshold the value meets or exceeds.
     */
    private const THRESHOLDS = [
        // ── Behavioral ──
        'devtools_count'         => [1 => 0.4,  3 => 0.7,  5 => 1.0],
        'screenshot_count'       => [1 => 0.5,  3 => 0.8,  5 => 1.0],
        'suspicious_key_count'   => [1 => 0.3,  3 => 0.6,  8 => 1.0],
        'rapid_answer_changes'   => [2 => 0.3,  5 => 0.6,  10 => 0.8,  20 => 1.0],
        'paste_count'            => [1 => 0.3,  3 => 0.6,  8 => 0.9,  15 => 1.0],
        'tab_hidden_count'       => [1 => 0.2,  3 => 0.5,  6 => 0.8,  12 => 1.0],
        'page_leave_count'       => [1 => 0.4,  3 => 0.7,  5 => 1.0],
        'copy_count'             => [1 => 0.3,  5 => 0.7,  10 => 1.0],
        'fullscreen_exit_count'  => [1 => 0.4,  3 => 0.8,  5 => 1.0],
        'answer_speed_ratio'     => [10 => 0.3,  25 => 0.5,  50 => 0.7,  80 => 1.0], // value = percent (0.1 => 10, etc.)
        'blur_count'             => [1 => 0.15, 3 => 0.35, 8 => 0.6,  20 => 0.85,  40 => 1.0],
        'offline_count'          => [1 => 0.3,  3 => 0.6,  6 => 1.0],
        'copy_selection_chars'   => [50 => 0.2,  200 => 0.5,  500 => 0.8,  1000 => 1.0],
        'idle_count'             => [1 => 0.3,  3 => 0.6,  6 => 0.8,  10 => 1.0],
        'right_click_count'      => [1 => 0.3,  3 => 0.6,  8 => 1.0],
        'tab_hidden_duration_ms' => [5000 => 0.2,  15000 => 0.4,  60000 => 0.7,  180000 => 0.9,  600000 => 1.0],
        'typing_backspace_count' => [5 => 0.3,  15 => 0.6,  30 => 1.0],
        'mouse_move_count'       => [0 => 0],
        'mouse_scroll_count'     => [0 => 0],
        'idle_duration_ms'       => [0 => 0],
        'typing_keydown_count'   => [0 => 0],
        'answer_changed_count'   => [0 => 0],
        'other_count'            => [0 => 0],

        // ── Network (handled via boostFromDirectScores) ──
        'same_ip_student_count'  => [2 => 0.3,  4 => 0.6,  7 => 0.8,  12 => 1.0],
        'ip_changed_count'       => [1 => 0.3,  3 => 0.6,  5 => 1.0],
        'same_ip_risk_score'     => [0 => 0],

        // ── AI (handled via boostFromDirectScores) ──
        'ai_suspect_score'       => [0 => 0],
        'answer_text_count'      => [0 => 0],
        'typing_answer_ratio'    => [0 => 0],

        // ── Similarity (handled via boostFromDirectScores) ──
        'similarity_max_score'   => [0 => 0],
        'similarity_match_count' => [2 => 0.3,  5 => 0.6,  10 => 1.0],

        // ── v28: Sequence detection (handled via boostFromDirectScores) ──
        'sequence_score'          => [0 => 0],

        // ── v28: External device detection (handled via boostFromDirectScores) ──
        'network_score_N'         => [0 => 0],
        'external_lookup_probability' => [0 => 0],
    ];

    /**
     * v14: Keys that should be normalized by exam context (question count / duration).
     * These are count-based indicators where 5 events in a 5-question exam ≠ 5 events in a 100-question exam.
     */
    private const NORMALIZABLE_KEYS = [
        'paste_count', 'copy_count', 'tab_hidden_count', 'page_leave_count',
        'right_click_count', 'blur_count', 'offline_count', 'idle_count',
        'devtools_count', 'screenshot_count', 'suspicious_key_count',
        'rapid_answer_changes', 'fullscreen_exit_count', 'copy_selection_chars',
    ];

    /** v14: Baseline reference values for normalization (typical 20-question exam, 60 minutes). */
    private const BASELINE_QUESTIONS = 20;
    private const BASELINE_MINUTES  = 60;

    /**
     * Correlation groups: indicators that fire from the SAME user action.
     * Group cap = max indicator weight + dampened additions.
     *
     * Format: group_name => [indicator_key => dampening_factor]
     *   - First indicator gets full contribution
     *   - Subsequent indicators multiplied by dampening_factor
     */
    private const CORRELATION_GROUPS = [
        'tab_switch' => [
            'tab_hidden_count'       => 0.3,
            'tab_hidden_duration_ms' => 0.3,
            'blur_count'             => 0.2,
        ],
        'screenshot_devtools' => [
            'screenshot_count'       => 0.4,
            'devtools_count'         => 0.4,
        ],
        'copy_paste' => [
            'paste_count'            => 0.5,
            'copy_count'             => 0.5,
            'copy_selection_chars'   => 0.3,
        ],
    ];

    /**
     * Seed formula for DEFAULT_INDICATORS.
     * [label_ar, weight_percent, enabled, description, category].
     */
    private const DEFAULT_INDICATORS = [
        'devtools_count'         => ['فتح أدوات المطوّر',        10, true,  'دخول وضع المطورين أثناء الامتحان (F12 / فحص).', 'behavioral'],
        'screenshot_count'       => ['محاولة لقطة شاشة',          8, true,  'محاولة أخذ لقطة للشاشة أثناء الامتحان.', 'behavioral'],
        'suspicious_key_count'   => ['مفاتيح مشبوهة',             7, true,  'ضغط F12 أو Alt+Tab أو مفاتيح النظام أثناء الامتحان.', 'behavioral'],
        'rapid_answer_changes'   => ['تغيير إجابة سريع',           7, true,  'تعديل الإجابات بشكل متكرر وسريع.', 'behavioral'],
        'paste_count'            => ['لصق',                        7, true,  'لصق نص من مصدر خارجي.', 'behavioral'],
        'tab_hidden_count'       => ['إخفاء التبويب',              7, true,  'الانتقال إلى تبويب آخر ثم العودة.', 'behavioral'],
        'page_leave_count'       => ['مغادرة الصفحة',              7, true,  'محاولة مغادرة صفحة الامتحان.', 'behavioral'],
        'copy_count'             => ['نسخ',                        5, true,  'نسخ نص من صفحة الامتحان.', 'behavioral'],
        'fullscreen_exit_count'  => ['الخروج من ملء الشاشة',       5, true,  'الخروج من وضع ملء الشاشة المفروض.', 'behavioral'],
        'answer_speed_ratio'     => ['سرعة الإجابة المشبوهة',      5, true,  'نسبة الوقت الفعلي مقابل المتوقع للإجابة.', 'behavioral'],
        'blur_count'             => ['فقدان التركيز',              4, true,  'الانتقال من النافذة إلى نافذة أخرى.', 'behavioral'],
        'offline_count'          => ['انقطاع النت',                4, true,  'انقطاع الاتصال بالإنترنت أثناء الامتحان.', 'behavioral'],
        'copy_selection_chars'   => ['تحديد نص للنسخ',             3, true,  'تحديد نصوص طويلة في صفحة الامتحان.', 'behavioral'],
        'idle_count'             => ['فترات خمول',                 3, true,  'توقف النشاط لفترة طويلة (علامة إجابة خارجية).', 'behavioral'],
        'right_click_count'      => ['نقر يمين',                   3, true,  'فتح قائمة النقر الأيمن.', 'behavioral'],
        'tab_hidden_duration_ms' => ['مدة إخفاء التبويب',          3, true,  'الوقت الإجمالي الذي قضاه الطالب خارج الامتحان.', 'behavioral'],
        'typing_backspace_count' => ['مسح متكرر أثناء الكتابة',    0, false, 'حذف متكرر أثناء كتابة إجابات المقالية.', 'behavioral'],
        'mouse_move_count'       => ['حركة الفأرة',                0, false, 'حركة فأرة مكثفة.', 'behavioral'],
        'mouse_scroll_count'     => ['تمرير الفأرة',               0, false, 'تمرير متكرر في الصفحة.', 'behavioral'],
        'idle_duration_ms'       => ['مدة الخمول',                 0, false, 'إجمالي وقت التوقف عن النشاط.', 'behavioral'],
        'typing_keydown_count'   => ['كتابة',                      0, false, 'عدد ضغطات المفاتيح.', 'behavioral'],
        'answer_changed_count'   => ['تغيير الإجابات',             0, false, 'عدد مرات تغيير الإجابات.', 'behavioral'],
        'other_count'            => ['أحداث أخرى',                 0, false, 'أحداث إضافية غير مصنّفة.', 'behavioral'],

        'same_ip_student_count'  => ['تجمع بنفس الـ IP',           7, true,  'عدد الطلاب المتصلين بنفس عنوان IP.', 'network'],
        'ip_changed_count'       => ['تغيير الـ IP',               5, true,  'عدد مرات تغيير IP أثناء الامتحان.', 'network'],
        'same_ip_risk_score'     => ['خطورة الشبكة',               3, true,  'مؤشر الخطورة المحسوب من تحليل الشبكة.', 'network'],

        'ai_suspect_score'       => ['إجابات مشبوهة بالـ AI',      8, true,  'مؤشر أن الإجابات مولّدة بالذكاء الاصطناعي.', 'ai'],
        'answer_text_count'      => ['عدد الإجابات النصية',         4, true,  'عدد الإجابات التي تحتوي على نص كامل.', 'ai'],
        'typing_answer_ratio'    => ['نسبة الكتابة الفعلية',        3, true,  'نسبة الإجابات المكتوبة بالكيبورد.', 'ai'],

        'similarity_max_score'   => ['أعلى تشابه',                 6, true,  'أعلى نسبة تشابه مع طالب آخر.', 'similarity'],
        'similarity_match_count' => ['عدد التطابقات',              4, true,  'عدد الإجابات المتطابقة مع طلاب آخرين.', 'similarity'],

        'sequence_score'          => ['تسلسل أحداث مشبوه',         5, true,  ' kopie→خروج→عودة→لصق أو تغيير بعد الخروج.', 'behavioral'],

        'network_score_N'              => ['الشبكة الذكية',           5, true,  'الكشف عن الأجهزة المشتركة والﺰواية المشبوهة.', 'network'],
        'external_lookup_probability'  => ['احتمال بحث خارجي',        3, true,  'استخدام جهاز خارجي أثناء الامتحان.', 'behavioral'],
    ];

    public const COUNTER_KEYS = [
        'devtools_count', 'screenshot_count', 'suspicious_key_count', 'rapid_answer_changes',
        'paste_count', 'tab_hidden_count', 'page_leave_count', 'copy_count',
        'fullscreen_exit_count', 'blur_count', 'offline_count', 'copy_selection_chars',
        'idle_count', 'right_click_count', 'tab_hidden_duration_ms', 'typing_backspace_count',
        'mouse_move_count', 'mouse_scroll_count', 'idle_duration_ms', 'typing_keydown_count',
        'answer_changed_count', 'other_count', 'answer_speed_ratio',
        'same_ip_student_count', 'ip_changed_count', 'same_ip_risk_score',
        'ai_suspect_score', 'answer_text_count', 'typing_answer_ratio',
        'similarity_max_score', 'similarity_match_count',
        'sequence_score',
        'network_score_N', 'external_lookup_probability',
    ];

    public const LEVELS = [
        ['level' => 'safe',     'label_ar' => 'آمن',      'min' => 0],
        ['level' => 'low',      'label_ar' => 'منخفض',    'min' => 20],
        ['level' => 'medium',   'label_ar' => 'متوسط',    'min' => 40],
        ['level' => 'high',     'label_ar' => 'مرتفع',    'min' => 60],
        ['level' => 'critical', 'label_ar' => 'حرج',      'min' => 80],
    ];

    public static function indicators(): array
    {
        if (self::$indicatorsCache !== null) {
            return self::$indicatorsCache;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT id, indicator_key, label_ar, weight_percent, enabled, description, sort_order, category
                 FROM risk_indicators ORDER BY sort_order ASC, id ASC'
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        if ($rows === []) {
            return self::defaults();
        }
        self::$indicatorsCache = array_map(static function (array $r): array {
            return [
                'id'          => (int)$r['id'],
                'key'         => (string)$r['indicator_key'],
                'label'       => (string)$r['label_ar'],
                'weight'      => (float)$r['weight_percent'],
                'enabled'     => (int)$r['enabled'] === 1,
                'description' => (string)$r['description'],
                'sort'        => (int)$r['sort_order'],
                'category'    => (string)($r['category'] ?? 'behavioral'),
            ];
        }, $rows);
        return self::$indicatorsCache;
    }

    public static function defaults(): array
    {
        $out = [];
        $i = 1;
        foreach (self::DEFAULT_INDICATORS as $key => $spec) {
            $out[] = [
                'id'          => 0,
                'key'         => $key,
                'label'       => $spec[0],
                'weight'      => (float)$spec[1],
                'enabled'     => $spec[2],
                'description' => $spec[3],
                'sort'        => $i,
                'category'    => $spec[4] ?? 'behavioral',
            ];
            $i++;
        }
        return $out;
    }

    public static function categories(): array
    {
        return self::DEFAULT_CATEGORIES;
    }

    public static function flushCache(): void
    {
        self::$indicatorsCache = null;
    }

    /* ── Proportional scoring ─────────────────────────────────────── */

    /**
     * Compute factor (0.0–1.0) for a raw value against thresholds.
     * Thresholds: [value => factor], evaluated highest-threshold-first.
     */
    private static function factorFor(string $key, float $val): float
    {
        if ($val <= 0) {
            return 0.0;
        }
        // answer_speed_ratio arrives as a decimal ratio (0.1 = 10x faster than
        // the class average). The thresholds use whole percentages, so scale up.
        if ($key === 'answer_speed_ratio') {
            $val = $val * 100;
        }
        $thresholds = self::THRESHOLDS[$key] ?? null;
        if ($thresholds === null || $thresholds === []) {
            return $val > 0 ? 1.0 : 0.0;
        }
        $factor = 0.0;
        foreach ($thresholds as $threshold => $f) {
            if ($val >= $threshold) {
                $factor = (float)$f;
            }
        }

        if ($factor >= 1.0 && $val > 1) {
            $excess = $val - (float)array_key_last($thresholds);
            if ($excess > 0) {
                $diminishing = log(1 + $excess) / log(1 + $excess + 10);
                $factor = 1.0 - (0.15 * $diminishing);
            }
        }

        return min(1.0, $factor);
    }

    /**
     * v14: Normalize a raw event count by exam context.
     *
     * Concept: 5 pastes in a 5-question exam = density 1.0 (critical)
     *          5 pastes in a 100-question exam = density 0.05 (negligible)
     *
     * Also factors in exam duration: shorter exams have less time for events,
     * so the same count is more suspicious.
     *
     * The normalized value replaces the raw value before threshold evaluation,
     * so the same thresholds work for all exam sizes.
     */
    private static function normalizeByContext(
        string $key,
        float $rawValue,
        int $questionCount,
        int $examMinutes,
        array $counters
    ): float {
        if ($rawValue <= 0) return 0;

        // ── 1. Question density normalization ──
        // density = raw_count / (question_count * events_per_question_baseline)
        // We scale so that at BASELINE_QUESTIONS, density = raw_count
        $questionFactor = self::BASELINE_QUESTIONS / max(1, $questionCount);
        $normalized = $rawValue * $questionFactor;

        // ── 2. Time pressure normalization ──
        // Shorter exams = more suspicious per event (less time to naturally occur)
        // A 10-minute exam with 5 tab_hides is worse than a 120-minute exam with 5
        if ($examMinutes > 0) {
            $timeFactor = self::BASELINE_MINUTES / max(10, $examMinutes);
            // Only amplify for short exams, don't deflate for long ones
            if ($timeFactor > 1.0) {
                $normalized *= min(2.0, $timeFactor); // Cap at 2x amplification
            }
        }

        // ── 3. Special contextual rules ──
        switch ($key) {
            case 'paste_count':
                // Paste in MCQ/T/F is anomalous (no text field to paste into)
                // Boost: if paste count > question_count/2, it's nearly every question
                if ($questionCount > 0 && $rawValue >= $questionCount * 0.5) {
                    $normalized *= 1.5;
                }
                break;

            case 'copy_count':
                // Copy without subsequent paste is less suspicious (just reading)
                // But copy+paste together = deliberate cheating pattern
                $pasteCount = (float)($counters['paste_count'] ?? 0);
                if ($pasteCount > 0 && $rawValue > 0) {
                    $copyPasteRatio = min($rawValue, $pasteCount) / max(1, max($rawValue, $pasteCount));
                    $normalized *= (1.0 + $copyPasteRatio * 0.5); // Up to 1.5x boost
                }
                break;

            case 'tab_hidden_count':
                // Many short hides = switching between exam and answers
                // Few long hides = taking a break (less suspicious)
                $hiddenDurationMs = (float)($counters['tab_hidden_duration_ms'] ?? 0);
                if ($rawValue > 0 && $hiddenDurationMs > 0) {
                    $avgHideDuration = $hiddenDurationMs / $rawValue;
                    // Short average hide = rapid switching = more suspicious
                    if ($avgHideDuration < 5000) { // Less than 5 seconds average
                        $normalized *= 1.3;
                    } elseif ($avgHideDuration > 60000) { // More than 1 minute = break
                        $normalized *= 0.7;
                    }
                }
                break;

            case 'idle_count':
                // Many short idles = confusion or looking up answers
                // Few long idles = stepped away (less suspicious per idle)
                $idleDurationMs = (float)($counters['idle_duration_ms'] ?? 0);
                if ($rawValue > 0 && $idleDurationMs > 0) {
                    $avgIdle = $idleDurationMs / $rawValue;
                    if ($avgIdle > 120000) { // More than 2 min average = stepped away
                        $normalized *= 0.6;
                    }
                }
                break;

            case 'copy_selection_chars':
                // Normalize by total question text length (if available)
                // For now, normalize by question count (more questions = more text to select)
                break;
        }

        return $normalized;
    }

    /**
     * Compute cheating score — v14 proportional + correlation dampening + context normalization.
     *
     * @param array $counters session_summaries columns + exam context.
     *   Optional context keys: question_count, exam_minutes
     * @return array{score:int, level:string, contributions:array, categories:array}
     */
    public static function score(array $counters): array
    {
        $indicators = self::indicators();

        // v14: Extract exam context for normalization
        $questionCount = max(1, (int)($counters['question_count'] ?? 0));
        $examMinutes   = max(1, (int)($counters['exam_minutes'] ?? 0));

        $catScores = [];
        $catWeights = [];
        foreach (self::DEFAULT_CATEGORIES as $cat => $spec) {
            $catScores[$cat] = 0.0;
            $catWeights[$cat] = (float)$spec['weight'];
        }

        // 1. Compute raw contributions (before correlation dampening)
        $rawContrib = [];
        $enabledByCategory = [];
        foreach ($indicators as $ind) {
            $key  = $ind['key'];
            $cat  = $ind['category'] ?? 'behavioral';
            if (!$ind['enabled']) {
                $rawContrib[$key] = 0.0;
                continue;
            }
            $val = (float)($counters[$key] ?? 0);

            // v14: Normalize behavioral counters by exam context
            if ($cat === 'behavioral' && in_array($key, self::NORMALIZABLE_KEYS, true)) {
                $val = self::normalizeByContext($key, $val, $questionCount, $examMinutes, $counters);
            }

            $factor = self::factorFor($key, $val);
            $contribution = $ind['weight'] * $factor;
            $softCap = $ind['weight'] * 0.75;
            if ($contribution > $softCap && $factor < 1.0) {
                $contribution = $softCap + ($contribution - $softCap) * 0.5;
            }
            $rawContrib[$key] = $contribution;
            $enabledByCategory[$cat][] = $key;
        }

        // 2. Apply correlation dampening
        $dampened = self::applyCorrelationDampening($rawContrib);

        // 3. Sum contributions per category
        foreach ($indicators as $ind) {
            $key = $ind['key'];
            $cat = $ind['category'] ?? 'behavioral';
            if (!isset($catScores[$cat])) {
                $catScores[$cat] = 0.0;
            }
            $catScores[$cat] += $dampened[$key] ?? 0.0;
        }

        // 4. Boost from direct pre-computed scores
        self::boostFromDirectScores($counters, $catScores);

        // 5. Cap each category at its max weight
        $catMax = [];
        foreach (self::DEFAULT_CATEGORIES as $cat => $spec) {
            $catMax[$cat] = (float)$spec['weight'];
            $catScores[$cat] = min($catScores[$cat], $catMax[$cat]);
        }

        // 6. Total
        $total = 0.0;
        $categoryBreakdown = [];
        foreach ($catScores as $cat => $raw) {
            $normalized = max(0.0, min($catMax[$cat], $raw));
            $total += $normalized;
            $categoryBreakdown[$cat] = [
                'score'  => (int)round($normalized),
                'max'    => (int)round($catMax[$cat]),
                'weight' => (int)round($catWeights[$cat]),
            ];
        }

        $total = min(100.0, max(0.0, $total));

        $contributions = [];
        foreach ($dampened as $k => $v) {
            $contributions[$k] = (int)round($v);
        }

        return [
            'score'         => (int)round($total),
            'level'         => self::levelFor((int)round($total)),
            'contributions' => $contributions,
            'categories'    => $categoryBreakdown,
        ];
    }

    /**
     * Apply correlation dampening: indicators in the same group
     * that fire from the same user action get dampened contributions.
     *
     * Logic: within each group, indicators are sorted by weight (desc).
     * The highest-weight indicator keeps full contribution.
     * Subsequent indicators are multiplied by their dampening factor.
     */
    private static function applyCorrelationDampening(array $rawContrib): array
    {
        $result = $rawContrib;
        $appliedKeys = [];

        foreach (self::CORRELATION_GROUPS as $groupName => $members) {
            $activeMembers = [];
            foreach ($members as $key => $dampening) {
                $val = $rawContrib[$key] ?? 0.0;
                if ($val > 0) {
                    $activeMembers[$key] = ['contrib' => $val, 'dampening' => $dampening];
                }
            }

            if (count($activeMembers) < 2) {
                continue;
            }

            // Sort by contribution descending — highest keeps full
            uasort($activeMembers, fn($a, $b) => $b['contrib'] <=> $a['contrib']);

            $first = true;
            foreach ($activeMembers as $key => $info) {
                if ($first) {
                    $first = false;
                    continue;
                }
                $dampened = $info['contrib'] * $info['dampening'];
                $result[$key] = $dampened;
                $appliedKeys[] = $key;
            }
        }

        return $result;
    }

    /**
     * Boost category scores from pre-computed direct values.
     */
    private static function boostFromDirectScores(array $counters, array &$catScores): void
    {
        $netRisk = (float)($counters['same_ip_risk_score'] ?? 0);
        if ($netRisk > 0) {
            // Maps 0-100 network risk to 0-15 category contribution (15% weight)
            $mapped = ($netRisk / 100) * 15;
            $catScores['network'] = max($catScores['network'], $mapped);
        }

        // v28: Smart network analysis with fingerprint-based detection
        $networkScoreN = (float)($counters['network_score_N'] ?? 0);
        if ($networkScoreN > 0) {
            // Maps 0-100 smart network risk to 0-15 category contribution (15% weight)
            $mapped = ($networkScoreN / 100) * 15;
            $catScores['network'] = max($catScores['network'], $mapped);
        }

        // v28: External device lookup probability boost
        $externalLookup = (float)($counters['external_lookup_probability'] ?? 0);
        if ($externalLookup > 0) {
            // Maps 0-100 external lookup risk to 0-50 behavioral contribution
            $mapped = ($externalLookup / 100) * 50;
            $catScores['behavioral'] = max($catScores['behavioral'], $mapped);
        }

        $aiScore = (float)($counters['ai_suspect_score'] ?? 0);
        if ($aiScore > 0) {
            // Maps 0-100 AI detection score to 0-20 category contribution (20% weight)
            $mapped = ($aiScore / 100) * 20;
            $catScores['ai'] = max($catScores['ai'], $mapped);
        }

        $simScore = (float)($counters['similarity_max_score'] ?? 0);
        if ($simScore > 0) {
            // Maps 0-100 similarity score to 0-15 category contribution (15% weight)
            $mapped = ($simScore / 100) * 15;
            $catScores['similarity'] = max($catScores['similarity'], $mapped);
        }

        // v12: Cognitive time analysis boost (per-question time validation)
        $cogScore = (float)($counters['cognitive_score'] ?? 0);
        if ($cogScore > 0) {
            // Maps 0-100 cognitive suspicion to 0-50 behavioral contribution
            $mapped = ($cogScore / 100) * 50;
            $catScores['behavioral'] = max($catScores['behavioral'], $mapped);
        }

        // v28: Event sequence detection boost (copy→blur→focus→paste, post-blur mutation)
        $seqScore = (float)($counters['sequence_score'] ?? 0);
        if ($seqScore > 0) {
            // Maps 0-100 sequence risk to 0-40 behavioral contribution
            // High weight because confirmed suspicious sequences are strong cheat indicators
            $mapped = ($seqScore / 100) * 40;
            $catScores['behavioral'] = max($catScores['behavioral'], $mapped);
        }
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    public static function levelFor(int $score): string
    {
        $level = 'safe';
        foreach (self::LEVELS as $l) {
            if ($score >= $l['min']) {
                $level = $l['level'];
            }
        }
        return $level;
    }

    public static function labelAr(string $level): string
    {
        foreach (self::LEVELS as $l) {
            if ($l['level'] === $level) {
                return $l['label_ar'];
            }
        }
        return $level;
    }

    public static function badgeColor(string $level): string
    {
        return match ($level) {
            'safe'     => 'green',
            'low'      => 'teal',
            'medium'   => 'amber',
            'high'     => 'orange',
            'critical' => 'red',
            default    => 'gray',
        };
    }
}
