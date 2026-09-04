<?php
/**
 * Risk engine — Thesis SOAR risk scoring (Eq 3.16, Table 3.2).
 *
 * Weights:
 *   w_B = 4/15  (Behavioral)     ≈ 0.26667
 *   w_A = 3/15  (AI Detection)   = 0.20000
 *   w_S = 4/15  (Similarity)     ≈ 0.26667
 *   w_N = 4/15  (Network)        ≈ 0.26667
 *
 * Availability-adjusted formula (Eq 3.16):
 *   R_{i,%} = 100 × Σ(w_k × X_{k,i}) / Σ(w_k), k ∈ K_i
 *
 * Clipped min-max normalization (Eq 3.1):
 *   n_{k,i} = min(1, max(0, (x - x_min) / (x_max - x_min)))
 *
 * Behavioral score (Eq 3.2-3.6):
 *   B_i = (n_F + n_D + n_P) / 3
 *   n_F = min(1, F_i / F_max), F_max = Q_i
 *   n_D = min(1, D_i / D_max), D_max = T_i (seconds)
 *   n_P = min(1, P_i / P_max), P_max = 2 × Q_i
 *
 * NIST SP 800-30 Risk Levels (Table 3.1):
 *   [0%-4.99%]    Very Low
 *   [5%-20.99%]   Low
 *   [21%-79.99%]  Moderate (alert threshold)
 *   [80%-95.99%]  High
 *   [96%-100%]    Very High
 */
final class RiskEngine
{
    private static ?array $indicatorsCache = null;

    private const DEFAULT_CATEGORIES = [
        'behavioral' => ['label_ar' => 'سلوكي',        'weight' => 4.0 / 15.0, 'description' => 'إشارات سلوك المتصفح والإجابات'],
        'ai'         => ['label_ar' => 'ذكاء اصطناعي', 'weight' => 3.0 / 15.0, 'description' => 'كشف أنماط الإجابات المولّدة بالـ AI'],
        'similarity' => ['label_ar' => 'تشابه',        'weight' => 4.0 / 15.0, 'description' => 'مقارنة إجابات الطلاب ببعضهم'],
        'network'    => ['label_ar' => 'شبكة',         'weight' => 4.0 / 15.0, 'description' => 'تغيير IP أو جلسات متزامنة'],
    ];

    /** Seed indicator definitions for UI display. */
    private const DEFAULT_INDICATORS = [
        'tab_hidden_count'       => ['إخفاء التبويب',             0, true,  'الانتقال إلى تبويب آخر ثم العودة.', 'behavioral'],
        'tab_hidden_duration_ms' => ['مدة إخفاء التبويب',         0, true,  'الوقت الإجمالي خارج الامتحان.', 'behavioral'],
        'page_leave_count'       => ['مغادرة الصفحة',             0, true,  'محاولة مغادرة صفحة الامتحان.', 'behavioral'],
        'blur_count'             => ['فقدان التركيز',             0, true,  'الانتقال من النافذة إلى نافذة أخرى.', 'behavioral'],
        'paste_count'            => ['لصق',                       0, true,  'لصق نص من مصدر خارجي.', 'behavioral'],
        'copy_count'             => ['نسخ',                       0, true,  'نسخ نص من صفحة الامتحان.', 'behavioral'],
        'devtools_count'         => ['فتح أدوات المطوّر',         0, true,  'دخول وضع المطورين أثناء الامتحان.', 'behavioral'],
        'screenshot_count'       => ['محاولة لقطة شاشة',          0, true,  'محاولة أخذ لقطة للشاشة.', 'behavioral'],
        'suspicious_key_count'   => ['مفاتيح مشبوهة',            0, true,  'ضغط F12 أو Alt+Tab.', 'behavioral'],
        'rapid_answer_changes'   => ['تغيير إجابة سريع',          0, true,  'تعديل الإجابات بشكل متكرر وسريع.', 'behavioral'],
        'fullscreen_exit_count'  => ['الخروج من ملء الشاشة',      0, true,  'الخروج من وضع ملء الشاشة.', 'behavioral'],
        'idle_count'             => ['فترات خمول',                0, true,  'توقف النشاط لفترة طويلة.', 'behavioral'],
        'right_click_count'      => ['نقر يمين',                  0, true,  'فتح قائمة النقر الأيمن.', 'behavioral'],
        'offline_count'          => ['انقطاع النت',               0, true,  'انقطاع الاتصال بالإنترنت.', 'behavioral'],
        'ai_suspect_score'       => ['إجابات مشبوهة بالـ AI',     0, true,  'مؤشر أن الإجابات مولّدة بالـ AI.', 'ai'],
        'similarity_max_score'   => ['أعلى تشابه',                0, true,  'أعلى نسبة تشابه مع طالب آخر.', 'similarity'],
        'network_score_N'        => ['الشبكة',                    0, true,  'تحليل الشبكة.', 'network'],
    ];

    public const COUNTER_KEYS = [
        'tab_hidden_count', 'tab_hidden_duration_ms', 'tab_visible_count',
        'copy_count', 'copy_selection_chars', 'paste_count', 'right_click_count',
        'blur_count', 'focus_count', 'page_leave_count', 'offline_count',
        'answer_changed_count', 'devtools_count', 'suspicious_key_count',
        'screenshot_count', 'rapid_answer_changes', 'idle_count', 'idle_duration_ms',
        'fullscreen_exit_count', 'typing_keydown_count', 'typing_backspace_count',
        'typing_enter_count', 'mouse_click_count', 'mouse_move_count', 'mouse_scroll_count',
        'other_count', 'ai_suspect_score', 'similarity_max_score', 'network_score_N',
    ];

    /**
     * NIST SP 800-30 Risk Levels (Table 3.1).
     *
     * Binary classification threshold: Risk ≥ 21.0%
     */
    public const LEVELS = [
        ['level' => 'safe',     'label_ar' => 'منخفض جداً', 'min' => 0],
        ['level' => 'low',      'label_ar' => 'منخفض',      'min' => 5],
        ['level' => 'medium',   'label_ar' => 'متوسط',      'min' => 21],
        ['level' => 'high',     'label_ar' => 'مرتفع',      'min' => 80],
        ['level' => 'critical', 'label_ar' => 'مرتفع جداً', 'min' => 96],
    ];

    /* ── Indicator helpers (for UI) ─────────────────────────────── */

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
        } catch (\Throwable $e) {
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

    /* ── Thesis Behavioral Score (Eq 3.2-3.6) ─────────────────── */

    /**
     * Compute B_i — Refined behavioral score using calibrated focus, duration, clipboard and violation metrics.
     *
     * n_F = min(1, F_i / F_max) — focus-change events, scaled by question count Q
     * n_D = min(1, D_i / D_crit) — absence duration scaled to realistic tolerance (not entire exam duration)
     * n_P = min(1, P_weighted / P_max) — weighted paste (2x) and copy (1x) events
     * n_V = min(1, V_i) — technical violations (devtools, fullscreen escape, screenshots)
     */
    public static function computeBehavioral(array $counters, int $Q, int $T): float
    {
        $Q = max(1, $Q);

        // 1. F_i: focus-change count (blurs, tab hidden, page leaves)
        $F = (int)($counters['tab_hidden_count'] ?? 0)
           + (int)($counters['page_leave_count'] ?? 0)
           + (int)($counters['blur_count'] ?? 0);
        // Realistic tolerance: 1 accidental blur allowed, scaled by Q (max tolerance reached at min(8, max(3, Q)))
        $F_max = max(3, min(12, $Q));
        $n_F = min(1.0, max(0.0, $F / (float)$F_max));

        // 2. D_i: absence duration in seconds
        $D_ms = (float)($counters['tab_hidden_duration_ms'] ?? 0);
        $D = $D_ms / 1000.0;
        // In an exam, leaving for > 45-90 seconds is severe. Scale D_crit between 30s and 120s based on exam duration
        $D_crit = min(120.0, max(30.0, (float)$T * 0.06));
        $n_D = min(1.0, max(0.0, $D / $D_crit));

        // 3. P_i: copy + paste count (pasting is penalized 2x compared to copying)
        $pasteCount = (int)($counters['paste_count'] ?? 0);
        $copyCount  = (int)($counters['copy_count'] ?? 0);
        $P_weighted = ($pasteCount * 2.0) + ($copyCount * 1.0);
        $P_max = max(2.0, min(10.0, (float)$Q * 1.2));
        $n_P = min(1.0, $P_weighted / $P_max);

        // 4. n_V: technical and security violations
        $devtools = (int)($counters['devtools_count'] ?? 0);
        $fullscreen = (int)($counters['fullscreen_exit_count'] ?? 0);
        $screenshots = (int)($counters['screenshot_count'] ?? 0);
        $suspiciousKeys = (int)($counters['suspicious_key_count'] ?? 0);
        $n_V = min(1.0, ($devtools * 0.6) + ($fullscreen * 0.4) + ($screenshots * 0.5) + ($suspiciousKeys * 0.25));

        // Base multi-indicator synthesis
        $B_raw = (0.35 * $n_F) + (0.25 * $n_D) + (0.30 * $n_P) + (0.10 * $n_V);

        // Synergy correlation penalty: leaving the screen frequently AND copying/pasting is classic cheating pattern
        if ($n_F >= 0.50 && $n_P >= 0.50) {
            $B_raw += 0.15;
        }

        return min(1.0, max(0.0, $B_raw));
    }

    public const PRESETS = [
        'balanced' => [
            'label_ar' => 'متوازن (الافتراضي)',
            'weights'  => ['behavioral' => 4.0 / 15.0, 'ai' => 3.0 / 15.0, 'similarity' => 4.0 / 15.0, 'network' => 4.0 / 15.0],
            'description' => 'مناسب للامتحانات المختلطة (أسئلة مقالية واختيارية).',
        ],
        'mcq' => [
            'label_ar' => 'اختيار من متعدد (MCQ)',
            'weights'  => ['behavioral' => 0.50, 'ai' => 0.00, 'similarity' => 0.00, 'network' => 0.50],
            'description' => 'مناسب للامتحانات الموضوعية، يركز على سلوك التصفح وأمان الشبكة.',
        ],
        'essay' => [
            'label_ar' => 'أسئلة مقالية (Essay)',
            'weights'  => ['behavioral' => 0.20, 'ai' => 0.35, 'similarity' => 0.35, 'network' => 0.10],
            'description' => 'يركز بشكل مكثف على كشف الذكاء الاصطناعي والتشابه النصي.',
        ],
        'coding' => [
            'label_ar' => 'برمجة وكتابة كود (Coding)',
            'weights'  => ['behavioral' => 0.25, 'ai' => 0.35, 'similarity' => 0.30, 'network' => 0.10],
            'description' => 'مناسب لامتحانات البرمجة وحل المسائل البرمجية.',
        ],
    ];

    /**
     * Directly combine the 4 normalized components into a 0-100% risk score (Eq 3.16 + Non-compensatory fusion).
     */
    public static function combineComponents(float $B, float $A, float $S, float $N): float
    {
        $wB = 4.0 / 15.0;
        $wA = 3.0 / 15.0;
        $wS = 4.0 / 15.0;
        $wN = 4.0 / 15.0;

        $weighted = ($wB * $B + $wA * $A + $wS * $S + $wN * $N) / ($wB + $wA + $wS + $wN);

        // Availability-adjusted evaluation
        $activeW = $wB;
        $activeNum = $wB * $B;
        if ($A > 0) { $activeW += $wA; $activeNum += $wA * $A; }
        if ($S > 0) { $activeW += $wS; $activeNum += $wS * $S; }
        if ($N > 0) { $activeW += $wN; $activeNum += $wN * $N; }
        $activeScore = $activeW > 0 ? ($activeNum / $activeW) : $weighted;

        // Dominant Threat Principle: critical cheating in one vector cannot be diluted by innocence in others
        $maxComp = max($B, $A, $S, $N);
        $dominant = (0.75 * $maxComp) + (0.25 * $weighted);

        $finalRatio = max($weighted, max($activeScore, $dominant));

        // Multi-vector synergy: if 2 or more vectors are significantly elevated
        $elevated = ($B >= 0.5 ? 1 : 0) + ($A >= 0.5 ? 1 : 0) + ($S >= 0.5 ? 1 : 0) + ($N >= 0.5 ? 1 : 0);
        if ($elevated >= 2) {
            $finalRatio = min(1.0, $finalRatio + 0.10);
        }

        return min(100.0, max(0.0, round($finalRatio * 100.0, 1)));
    }

    /* ── Main Scoring (Eq 3.16) ────────────────────────────────── */

    /**
     * Compute final risk score using availability-adjusted weighted sum and dominant threat fusion.
     * Supports dynamic presets and custom teacher-defined weights.
     *
     * @param array $counters session_summaries columns + exam context.
     *   Required keys: question_count, exam_minutes
     *   Optional keys: preset ('balanced'|'mcq'|'essay'|'coding'), weights: array{behavioral:float, ai:float, similarity:float, network:float}
     *   Optional: ai_suspect_score (0-100), similarity_max_score (0-100), network_score_N (0-100)
     * @param array|null $customWeights Optional explicit weight override
     * @return array{score:int, level:string, contributions:array, categories:array}
     */
    public static function score(array $counters, ?array $customWeights = null): array
    {
        // Exam context
        $Q = max(1, (int)($counters['question_count'] ?? 5));
        $T_min = max(1, (int)($counters['exam_minutes'] ?? 15));
        $T = $T_min * 60; // seconds

        // 1. Compute sub-scores (each 0.0 – 1.0)
        $B = self::computeBehavioral($counters, $Q, $T);

        $aiRaw = (float)($counters['ai_suspect_score'] ?? 0);
        $A = min(1.0, max(0.0, $aiRaw / 100.0));

        $simRaw = (float)($counters['similarity_max_score'] ?? 0);
        $S = min(1.0, max(0.0, $simRaw / 100.0));

        $netRaw = (float)($counters['network_score_N'] ?? 0);
        $N = min(1.0, max(0.0, $netRaw / 100.0));

        // 2. Resolve weights (Preset vs Custom vs Default Balanced)
        $presetKey = (string)($counters['preset'] ?? 'balanced');
        $resolvedWeights = self::PRESETS[$presetKey]['weights'] ?? self::PRESETS['balanced']['weights'];

        if ($customWeights !== null) {
            $resolvedWeights = array_merge($resolvedWeights, $customWeights);
        } elseif (!empty($counters['weights']) && is_array($counters['weights'])) {
            $resolvedWeights = array_merge($resolvedWeights, $counters['weights']);
        }

        $wB = (float)($resolvedWeights['behavioral'] ?? (4.0 / 15.0));
        $wA = (float)($resolvedWeights['ai'] ?? (3.0 / 15.0));
        $wS = (float)($resolvedWeights['similarity'] ?? (4.0 / 15.0));
        $wN = (float)($resolvedWeights['network'] ?? (4.0 / 15.0));

        // 3. Availability-adjusted weighted sum (Eq 3.16)
        $sumAllW = max(0.001, $wB + $wA + $wS + $wN);
        $weightedPct = 100.0 * ($wB * $B + $wA * $A + $wS * $S + $wN * $N) / $sumAllW;

        // Dynamic availability: only include modules that were active or reported non-zero signals
        $activeW = $wB;
        $activeNum = $wB * $B;
        if ($A > 0 || !empty($counters['has_ai_evaluated'])) {
            $activeW += $wA;
            $activeNum += $wA * $A;
        }
        if ($S > 0 || !empty($counters['has_similarity_evaluated'])) {
            $activeW += $wS;
            $activeNum += $wS * $S;
        }
        if ($N > 0 || !empty($counters['has_network_evaluated'])) {
            $activeW += $wN;
            $activeNum += $wN * $N;
        }
        $activePct = $activeW > 0 ? (100.0 * $activeNum / $activeW) : $weightedPct;

        // 4. Dominant Threat Fusion:
        // If a student commits blatant behavioral cheating or AI plagiarism, the overall risk must reflect that!
        $maxComponentPct = 100.0 * max($B, $A, $S, $N);
        $dominantPct = (0.75 * $maxComponentPct) + (0.25 * $weightedPct);

        $finalRiskPct = max($weightedPct, max($activePct, $dominantPct));

        // Multi-vector synergy: if 2 or more vectors are high (e.g. Behavioral >= 50% AND AI >= 50%)
        $elevatedCount = ($B >= 0.50 ? 1 : 0) + ($A >= 0.50 ? 1 : 0) + ($S >= 0.50 ? 1 : 0) + ($N >= 0.50 ? 1 : 0);
        if ($elevatedCount >= 2) {
            $finalRiskPct = min(100.0, $finalRiskPct + 10.0);
        }

        $riskPct = min(100.0, max(0.0, $finalRiskPct));
        $riskScore = (int)round($riskPct);

        // 4. Category breakdown for UI
        $sumW = max(0.001, $wB + $wA + $wS + $wN);
        $categories = [
            'behavioral' => ['score' => (int)round($B * 100), 'max' => 100, 'weight' => (int)round(($wB / $sumW) * 100)],
            'ai'         => ['score' => (int)round($A * 100), 'max' => 100, 'weight' => (int)round(($wA / $sumW) * 100)],
            'similarity' => ['score' => (int)round($S * 100), 'max' => 100, 'weight' => (int)round(($wS / $sumW) * 100)],
            'network'    => ['score' => (int)round($N * 100), 'max' => 100, 'weight' => (int)round(($wN / $sumW) * 100)],
        ];

        // 5. Per-indicator raw values (for UI detail)
        $contributions = [];
        foreach (self::DEFAULT_INDICATORS as $key => $spec) {
            $contributions[$key] = (int)round((float)($counters[$key] ?? 0));
        }

        return [
            'score'         => $riskScore,
            'level'         => self::levelFor($riskScore),
            'preset'        => $presetKey,
            'contributions' => $contributions,
            'categories'    => $categories,
        ];
    }

    /* ── Level helpers ─────────────────────────────────────────── */

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
            'low'      => 'blue',
            'medium'   => 'amber',
            'high'     => 'orange',
            'critical' => 'red',
            default    => 'gray',
        };
    }
}
