<?php
/**
 * Benchmark test — Verify thesis Table 4.3 risk scores.
 *
 * Q=6 questions, T=900s (15 minutes), 10 students S1-S10.
 * Tests each component (B, A, S, N) and the final risk score.
 *
 * Usage: php benchmark.php
 *   - Can run standalone (no DB required)
 *   - Tests RiskEngine::computeBehavioral() and scoring formula
 */

// Bootstrap: autoloader + minimal stubs
$base = __DIR__;
if (file_exists($base . '/vendor/autoload.php')) {
    require_once $base . '/vendor/autoload.php';
}

// Minimal stubs for classes used by RiskEngine
if (!class_exists('Database')) {
    class Database {
        public static function fetchAll($sql, $params = []) { return []; }
        public static function fetchOne($sql, $params = []) { return null; }
        public static function scalar($sql, $params = []) { return 0; }
        public static function execute($sql, $params = []) { return 0; }
        public static function connection() { return null; }
    }
}

require_once __DIR__ . '/server/app/RiskEngine.php';

// ─── Table 4.3 Benchmark Data ──────────────────────────────────────
// Q=6, T=900s
// B values are computed by RiskEngine::computeBehavioral()
// A = ai_suspect_score / 100, S = similarity_max_score / 100, N = network_score_N / 100

$Q = 6;
$T_min = 15; // minutes

$benchmark = [
    // student => [B, A, S, N, expected_risk%, expected_level, behavioral_counters]
    'S1' => [
        'B' => 0.000, 'A' => 0.08, 'S' => 0.00, 'N' => 0.00,
        'expected_risk' => 1.6, 'expected_level' => 'safe',
        'counters' => [
            'tab_hidden_count' => 0, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 0, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 8, 'similarity_max_score' => 0, 'network_score_N' => 0,
        ],
    ],
    'S2' => [
        'B' => 0.057, 'A' => 0.12, 'S' => 0.00, 'N' => 0.00,
        'expected_risk' => 3.9, 'expected_level' => 'safe',
        'counters' => [
            'tab_hidden_count' => 1, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 5000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 12, 'similarity_max_score' => 0, 'network_score_N' => 0,
        ],
    ],
    'S3' => [
        'B' => 0.086, 'A' => 0.10, 'S' => 0.00, 'N' => 0.00,
        'expected_risk' => 4.3, 'expected_level' => 'safe',
        'counters' => [
            'tab_hidden_count' => 1, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 80000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 10, 'similarity_max_score' => 0, 'network_score_N' => 0,
        ],
    ],
    'S4' => [
        'B' => 0.146, 'A' => 0.14, 'S' => 0.00, 'N' => 0.00,
        'expected_risk' => 6.7, 'expected_level' => 'low',
        'counters' => [
            'tab_hidden_count' => 2, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 95000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 14, 'similarity_max_score' => 0, 'network_score_N' => 0,
        ],
    ],
    'S5' => [
        'B' => 0.767, 'A' => 0.14, 'S' => 0.00, 'N' => 0.00,
        'expected_risk' => 23.2, 'expected_level' => 'medium',
        'counters' => [
            'tab_hidden_count' => 3, 'page_leave_count' => 2, 'blur_count' => 1,
            'tab_hidden_duration_ms' => 795000, 'paste_count' => 3, 'copy_count' => 2,
            'ai_suspect_score' => 14, 'similarity_max_score' => 0, 'network_score_N' => 0,
        ],
    ],
    'S6' => [
        'B' => 0.058, 'A' => 0.88, 'S' => 0.00, 'N' => 0.00,
        'expected_risk' => 19.1, 'expected_level' => 'low',
        'counters' => [
            'tab_hidden_count' => 1, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 7000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 88, 'similarity_max_score' => 0, 'network_score_N' => 0,
        ],
    ],
    'S7' => [
        'B' => 0.087, 'A' => 0.16, 'S' => 0.75, 'N' => 0.00,
        'expected_risk' => 25.5, 'expected_level' => 'medium',
        'counters' => [
            'tab_hidden_count' => 1, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 85000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 16, 'similarity_max_score' => 75, 'network_score_N' => 0,
        ],
    ],
    'S8' => [
        'B' => 0.028, 'A' => 0.11, 'S' => 0.75, 'N' => 0.00,
        'expected_risk' => 22.9, 'expected_level' => 'medium',
        'counters' => [
            'tab_hidden_count' => 0, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 75000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 11, 'similarity_max_score' => 75, 'network_score_N' => 0,
        ],
    ],
    'S9' => [
        'B' => 0.060, 'A' => 0.09, 'S' => 0.00, 'N' => 1.00,
        'expected_risk' => 30.1, 'expected_level' => 'medium',
        'counters' => [
            'tab_hidden_count' => 1, 'page_leave_count' => 0, 'blur_count' => 0,
            'tab_hidden_duration_ms' => 12000, 'paste_count' => 0, 'copy_count' => 0,
            'ai_suspect_score' => 9, 'similarity_max_score' => 0, 'network_score_N' => 100,
        ],
    ],
    'S10' => [
        'B' => 0.722, 'A' => 0.90, 'S' => 1.00, 'N' => 1.00,
        'expected_risk' => 90.6, 'expected_level' => 'high',
        'counters' => [
            'tab_hidden_count' => 3, 'page_leave_count' => 1, 'blur_count' => 2,
            'tab_hidden_duration_ms' => 750000, 'paste_count' => 2, 'copy_count' => 2,
            'ai_suspect_score' => 90, 'similarity_max_score' => 100, 'network_score_N' => 100,
        ],
    ],
];

// ─── Run Benchmark ─────────────────────────────────────────────────

echo "═══════════════════════════════════════════════════════════════\n";
echo "  SOAR Benchmark Test — Table 4.3 (Q=6, T=900s)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$passed = 0;
$failed = 0;
$tolerance = 0.5; // ±0.5% tolerance for rounding

foreach ($benchmark as $student => $data) {
    $counters = $data['counters'];
    $counters['question_count'] = $Q;
    $counters['exam_minutes'] = $T_min;

    // Compute behavioral score
    $B_computed = RiskEngine::computeBehavioral($counters, $Q, $T_min * 60);
    $A_computed = min(1.0, max(0.0, $data['A']));
    $S_computed = min(1.0, max(0.0, $data['S']));
    $N_computed = min(1.0, max(0.0, $data['N']));

    // Compute risk score
    $result = RiskEngine::score($counters);
    $riskScore = $result['score'];

    // Check B value
    $bDiff = abs($B_computed - $data['B']);
    $bOk = $bDiff < 0.005;

    // Check risk score
    $riskDiff = abs($riskScore - $data['expected_risk']);
    $riskOk = $riskDiff <= $tolerance;

    // Check level
    $levelOk = $result['level'] === $data['expected_level'];

    $allOk = $bOk && $riskOk && $levelOk;

    if ($allOk) {
        echo "  ✓ $student";
        echo "  B=" . number_format($B_computed, 3) . " (expected {$data['B']})";
        echo "  Risk={$riskScore}% (expected {$data['expected_risk']}%)";
        echo "  Level={$result['level']} (expected {$data['expected_level']})";
        echo "\n";
        $passed++;
    } else {
        echo "  ✗ $student  FAILED\n";
        if (!$bOk) {
            echo "    B: computed=" . number_format($B_computed, 4) . " expected={$data['B']} (diff=" . number_format($bDiff, 4) . ")\n";
        }
        if (!$riskOk) {
            echo "    Risk: computed={$riskScore}% expected={$data['expected_risk']}% (diff=" . number_format($riskDiff, 2) . "%)\n";
        }
        if (!$levelOk) {
            echo "    Level: computed={$result['level']} expected={$data['expected_level']}\n";
        }
        $failed++;
    }
}

echo "\n───────────────────────────────────────────────────────────────\n";
echo "  Results: $passed passed, $failed failed out of " . count($benchmark) . " students\n";

if ($failed === 0) {
    echo "  ✓ ALL BENCHMARK TESTS PASSED\n";
} else {
    echo "  ✗ $failed TEST(S) FAILED\n";
}

echo "───────────────────────────────────────────────────────────────\n\n";

// ─── NIST Risk Level Verification ─────────────────────────────────

echo "  NIST SP 800-30 Risk Level Verification:\n";
$levelTests = [
    [0, 'safe'], [4, 'safe'], [5, 'low'], [20, 'low'],
    [21, 'medium'], [50, 'medium'], [79, 'medium'],
    [80, 'high'], [95, 'high'], [96, 'critical'], [100, 'critical'],
];
foreach ($levelTests as [$score, $expected]) {
    $level = RiskEngine::levelFor($score);
    $ok = $level === $expected;
    echo "  " . ($ok ? '✓' : '✗') . " score=$score → $level (expected $expected)\n";
    if (!$ok) $failed++;
}

echo "\n═══════════════════════════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
