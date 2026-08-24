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
     * Compute B_i = (n_F + n_D + n_P) / 3.
     *
     * n_F = min(1, F_i / F_max) — focus-change events, F_max = Q_i
     *   F_i = tab_hidden_count + page_leave_count + blur_count
     *   (events with duration < δ_F = 3s excluded at collection time)
     *
     * n_D = min(1, D_i / D_max) — absence duration, D_max = T_i (seconds)
     *   D_i = tab_hidden_duration_ms / 1000
     *
     * n_P = min(1, P_i / P_max) — paste count, P_max = 2 × Q_i
     *   P_i = paste_count + copy_count
     */
    public static function computeBehavioral(array $counters, int $Q, int $T): float
    {
        // F_i: focus-change count
        $F = (int)($counters['tab_hidden_count'] ?? 0)
           + (int)($counters['page_leave_count'] ?? 0)
           + (int)($counters['blur_count'] ?? 0);
        $F_max = max(1, $Q);
        $n_F = min(1.0, $F / $F_max);

        // D_i: absence duration in seconds
        $D_ms = (float)($counters['tab_hidden_duration_ms'] ?? 0);
        $D = $D_ms / 1000.0;
        $D_max = max(1.0, (float)$T);
        $n_D = min(1.0, $D / $D_max);

        // P_i: copy + paste count
        $P = (int)($counters['paste_count'] ?? 0) + (int)($counters['copy_count'] ?? 0);
        $P_max = max(1, 2 * $Q);
        $n_P = min(1.0, $P / $P_max);

        return ($n_F + $n_D + $n_P) / 3.0;
    }

    /* ── Main Scoring (Eq 3.16) ────────────────────────────────── */

    /**
     * Compute final risk score using availability-adjusted weighted sum.
     *
     * @param array $counters session_summaries columns + exam context.
     *   Required keys: question_count, exam_minutes
     *   Optional: ai_suspect_score (0-100), similarity_max_score (0-100), network_score_N (0-100)
     * @return array{score:int, level:string, contributions:array, categories:array}
     */
    public static function score(array $counters): array
    {
        // Exam context
        $Q = max(1, (int)($counters['question_count'] ?? 6));
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

        // 2. Availability-adjusted weighted sum (Eq 3.16)
        $wB = 4.0 / 15.0;
        $wA = 3.0 / 15.0;
        $wS = 4.0 / 15.0;
        $wN = 4.0 / 15.0;

        $numerator   = $wB * $B + $wA * $A + $wS * $S + $wN * $N;
        $denominator = $wB + $wA + $wS + $wN;

        $riskPct = $denominator > 0 ? 100.0 * $numerator / $denominator : 0.0;
        $riskPct = min(100.0, max(0.0, $riskPct));
        $riskScore = (int)round($riskPct);

        // 3. Category breakdown for UI
        $categories = [
            'behavioral' => ['score' => (int)round($B * 100), 'max' => 100, 'weight' => (int)round($wB * 100)],
            'ai'         => ['score' => (int)round($A * 100), 'max' => 100, 'weight' => (int)round($wA * 100)],
            'similarity' => ['score' => (int)round($S * 100), 'max' => 100, 'weight' => (int)round($wS * 100)],
            'network'    => ['score' => (int)round($N * 100), 'max' => 100, 'weight' => (int)round($wN * 100)],
        ];

        // 4. Per-indicator raw values (for UI detail)
        $contributions = [];
        foreach (self::DEFAULT_INDICATORS as $key => $spec) {
            $contributions[$key] = (int)round((float)($counters[$key] ?? 0));
        }

        return [
            'score'         => $riskScore,
            'level'         => self::levelFor($riskScore),
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
