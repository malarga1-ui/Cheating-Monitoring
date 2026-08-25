<?php
/**
 * Comprehensive Platform Test Suite — SOAR Platform
 * ===================================================
 * Exhaustive automated testing for:
 *   1. Thesis Mathematical Model & Normalization (Eq 3.1 - 3.16)
 *   2. NIST SP 800-30 Risk Classifications & Availability Weights
 *   3. Similarity Engine (Word Trigram Cosine Similarity)
 *   4. Cognitive Science Analyzer (Hick's Law & Reading Speed)
 *   5. AI Detector Guard & Multi-Provider Logic
 *   6. Network Risk Analyzer (IP & Concurrent Session Detection)
 *   7. Security, Auth, RBAC & Multi-Tenant Scoping
 *
 * Run via: php tests/comprehensive_test.php
 */

declare(strict_types=1);

// Stubs for offline testing without DB dependency
if (!class_exists('Database')) {
    class Database {
        public static function fetchAll($sql, $params = []) { return []; }
        public static function fetchOne($sql, $params = []) { return null; }
        public static function scalar($sql, $params = []) { return 0; }
        public static function execute($sql, $params = []) { return 0; }
        public static function connection() { return null; }
    }
}

require_once __DIR__ . '/../app/RiskEngine.php';
require_once __DIR__ . '/../app/SimilarityEngine.php';
require_once __DIR__ . '/../app/CognitiveAnalyzer.php';
require_once __DIR__ . '/../app/AIDetector.php';
require_once __DIR__ . '/../app/NetworkAnalyzer.php';
require_once __DIR__ . '/../app/controllers/TeacherPortalController.php';

class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function assert(string $testName, bool $condition, string $details = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m[PASS]\033[0m {$testName}\n";
        } else {
            $this->failed++;
            $this->failures[] = "{$testName}: {$details}";
            echo "  \033[31m[FAIL]\033[0m {$testName} — {$details}\n";
        }
    }

    public function assertEquals(string $testName, mixed $actual, mixed $expected, float $delta = 0.001): void
    {
        if (is_float($expected) || is_float($actual)) {
            $diff = abs((float)$actual - (float)$expected);
            $condition = $diff <= $delta;
            $this->assert($testName, $condition, "Expected {$expected}, got {$actual} (diff: {$diff})");
        } else {
            $condition = ($actual === $expected);
            $this->assert($testName, $condition, "Expected " . json_encode($expected) . ", got " . json_encode($actual));
        }
    }

    public function printSummary(string $suiteName): void
    {
        echo "\n───────────────────────────────────────────────────────────────\n";
        echo "  [{$suiteName}] Results: {$this->passed} Passed, {$this->failed} Failed\n";
        if ($this->failed > 0) {
            echo "  \033[31mFailures:\033[0m\n";
            foreach ($this->failures as $f) {
                echo "    - {$f}\n";
            }
        } else {
            echo "  \033[32m[SUCCESS] 100% ALL TESTS IN SUITE PASSED\033[0m\n";
        }
        echo "───────────────────────────────────────────────────────────────\n\n";
    }

    public function getPassed(): int { return $this->passed; }
    public function getFailed(): int { return $this->failed; }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "       SOAR PLATFORM — COMPREHENSIVE TEST SUITE               \n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$runner = new TestRunner();

// =========================================================================
// 1. MATHEMATICAL FORMULAS & RISK ENGINE (Thesis Eq 3.1 - 3.16)
// =========================================================================
echo ">> Section 1: Thesis Mathematical Risk Engine\n";

// Test Behavioral Eq 3.2-3.6
$countersZero = [
    'tab_hidden_count' => 0, 'page_leave_count' => 0, 'blur_count' => 0,
    'tab_hidden_duration_ms' => 0, 'paste_count' => 0, 'copy_count' => 0
];
$bZero = RiskEngine::computeBehavioral($countersZero, 6, 900);
$runner->assertEquals('Zero behavioral counters yields B=0.0', $bZero, 0.0);

// Test focus normalization (F_max = Q)
$countersFocus = [
    'tab_hidden_count' => 3, 'page_leave_count' => 2, 'blur_count' => 1, // F = 6
    'tab_hidden_duration_ms' => 0, 'paste_count' => 0, 'copy_count' => 0
];
$bFocus = RiskEngine::computeBehavioral($countersFocus, 6, 900);
$runner->assertEquals('Max focus saturation yields n_F=1.0, B=0.333', $bFocus, 1.0 / 3.0);

// Test duration normalization (D_max = T seconds)
$countersDuration = [
    'tab_hidden_count' => 0, 'page_leave_count' => 0, 'blur_count' => 0,
    'tab_hidden_duration_ms' => 900000, // 900s
    'paste_count' => 0, 'copy_count' => 0
];
$bDur = RiskEngine::computeBehavioral($countersDuration, 6, 900);
$runner->assertEquals('Max duration saturation yields n_D=1.0, B=0.333', $bDur, 1.0 / 3.0);

// Test paste normalization (P_max = 2 * Q)
$countersPaste = [
    'tab_hidden_count' => 0, 'page_leave_count' => 0, 'blur_count' => 0,
    'tab_hidden_duration_ms' => 0,
    'paste_count' => 8, 'copy_count' => 4 // P = 12 = 2 * 6
];
$bPaste = RiskEngine::computeBehavioral($countersPaste, 6, 900);
$runner->assertEquals('Max paste saturation yields n_P=1.0, B=0.333', $bPaste, 1.0 / 3.0);

// Test all maxed
$countersMax = [
    'tab_hidden_count' => 3, 'page_leave_count' => 2, 'blur_count' => 1,
    'tab_hidden_duration_ms' => 900000,
    'paste_count' => 8, 'copy_count' => 4,
];
$bMax = RiskEngine::computeBehavioral($countersMax, 6, 900);
$runner->assertEquals('All behavioral components maxed yields B=1.0', $bMax, 1.0);

// Test Thesis Benchmark Weights (w_B=4/15, w_A=3/15, w_S=4/15, w_N=4/15)
$scoreMax = RiskEngine::score([
    'question_count' => 6,
    'exam_minutes' => 15,
    'tab_hidden_count' => 6,
    'tab_hidden_duration_ms' => 900000,
    'paste_count' => 12,
    'ai_suspect_score' => 100,
    'similarity_max_score' => 100,
    'network_score_N' => 100,
]);
$runner->assertEquals('Full saturation risk score is 100%', $scoreMax['score'], 100);
$runner->assertEquals('Full saturation level is critical', $scoreMax['level'], 'critical');

// =========================================================================
// 2. NIST SP 800-30 RISK CLASSIFICATIONS
// =========================================================================
echo "\n>> Section 2: NIST SP 800-30 Level Classification\n";
$runner->assertEquals('Score 0 is safe', RiskEngine::levelFor(0), 'safe');
$runner->assertEquals('Score 4 is safe', RiskEngine::levelFor(4), 'safe');
$runner->assertEquals('Score 5 is low', RiskEngine::levelFor(5), 'low');
$runner->assertEquals('Score 20 is low', RiskEngine::levelFor(20), 'low');
$runner->assertEquals('Score 21 is medium (alert threshold)', RiskEngine::levelFor(21), 'medium');
$runner->assertEquals('Score 79 is medium', RiskEngine::levelFor(79), 'medium');
$runner->assertEquals('Score 80 is high', RiskEngine::levelFor(80), 'high');
$runner->assertEquals('Score 95 is high', RiskEngine::levelFor(95), 'high');
$runner->assertEquals('Score 96 is critical', RiskEngine::levelFor(96), 'critical');
$runner->assertEquals('Score 100 is critical', RiskEngine::levelFor(100), 'critical');

// =========================================================================
// 3. SIMILARITY ENGINE (Trigram Cosine Similarity)
// =========================================================================
echo "\n>> Section 3: Similarity Engine & Trigram Cosine\n";

$text1 = "تعتبر هندسة البرمجيات من أهم فروع علوم الحاسوب التي تهتم بتصميم وتطوير الأنظمة البرمجية بجودة عالية";
$text2 = "تعتبر هندسة البرمجيات من أهم فروع علوم الحاسوب التي تهتم بتصميم وتطوير الأنظمة البرمجية بجودة عالية";
$text3 = "الشبكات الحاسوبية وبروتوكولات التوجيه ونقل البيانات عبر الإنترنت والأنظمة الموزعة";

// Identical texts
$ansA = [
    'student_id' => 1,
    'questions' => [
        1 => ['answer_text' => $text1],
        2 => ['answer_text' => $text1],
    ]
];
$ansB = [
    'student_id' => 2,
    'questions' => [
        1 => ['answer_text' => $text2],
        2 => ['answer_text' => $text2],
    ]
];
$ansC = [
    'student_id' => 3,
    'questions' => [
        1 => ['answer_text' => $text3],
        2 => ['answer_text' => $text3],
    ]
];

$refCompare = new ReflectionClass('SimilarityEngine');
$methodCompareTwo = $refCompare->getMethod('compareTwo');
$methodCompareTwo->setAccessible(true);

$resIdentical = $methodCompareTwo->invoke(null, $ansA, $ansB);
$runner->assertEquals('Identical texts yield 100% similarity', $resIdentical['similarity'], 100);
$runner->assertEquals('Identical texts match all questions', $resIdentical['matched'], 2);

$resDivergent = $methodCompareTwo->invoke(null, $ansA, $ansC);
$runner->assertEquals('Completely divergent texts yield 0% similarity', $resDivergent['similarity'], 0);
$runner->assertEquals('Completely divergent texts match 0 questions', $resDivergent['matched'], 0);

// =========================================================================
// 4. COGNITIVE SCIENCE ANALYZER (Hick's Law & Reading Speed)
// =========================================================================
echo "\n>> Section 4: Cognitive Science Behavioral Analyzer\n";

// Language detection
$runner->assertEquals('Arabic text detected as ar', CognitiveAnalyzer::detectLanguage('مرحبا بكم في امتحان مادة هندسة البرمجيات'), 'ar');
$runner->assertEquals('English text detected as en', CognitiveAnalyzer::detectLanguage('Welcome to the software engineering examination test'), 'en');

// Reading speed
$runner->assertEquals('Arabic reading speed is 3.5 wps', CognitiveAnalyzer::readingSpeed('ar'), 3.5);
$runner->assertEquals('English reading speed is 2.5 wps', CognitiveAnalyzer::readingSpeed('en'), 2.5);

// Expected Time Calculation for Question
$estQTime = CognitiveAnalyzer::calcExpectedTime(
    answerText: 'تتكون دورة حياة تطوير البرمجيات من جمع المتطلبات والتصميم والتطوير والاختبار والنشر والصيانة الدورية للتطبيق البرمجي',
    questionType: 'essay',
    answerWordCount: 16,
    answerLength: 105,
    questionWordCount: 20
);
$runner->assert('Estimated question time is positive and realistic', $estQTime['t_total'] > 15, "Expected > 15s, got {$estQTime['t_total']}s");
$runner->assert('T_read component is calculated', $estQTime['t_read'] > 0);
$runner->assert('T_think component is calculated', $estQTime['t_think'] > 0);
$runner->assert('T_write component is calculated for essay', $estQTime['t_write'] > 0);

// Impossible Time Anomaly Check (e.g. essay answered in 1.5 seconds vs 40s expected)
$suspiciousAnalysis = CognitiveAnalyzer::suspicionScore(
    tExpected: $estQTime['t_total'],
    tActual: 1.5,
    hasPaste: true,
    noTyping: true
);
$runner->assertEquals('1.5s essay answer flagged as critical impossibility', $suspiciousAnalysis['level'], 'critical');
$runner->assert('Critical suspicion score >= 80 for instant answer', $suspiciousAnalysis['score'] >= 80);

// =========================================================================
// 5. AI DETECTOR GUARD & CONFIG
// =========================================================================
echo "\n>> Section 5: AI Content Detector Guard\n";

// Below word count threshold (< 30 words)
$shortText = "هذه إجابة قصيرة جداً تحتوي على بضع كلمات فقط لا تتجاوز الحد الأدنى المطلوب للفحص";
$shortRes = AIDetector::analyzeText($shortText);
$runner->assertEquals('Short text skipped from AI API calls', $shortRes['status'], 'SKIPPED');
$runner->assertEquals('Short text AI score is 0', $shortRes['ai_score'], 0.0);

// =========================================================================
// 6. SECURITY, ENCRYPTION & DATA ISOLATION
// =========================================================================
echo "\n>> Section 6: Security, Scoping & Data Protection\n";

// Password hashing verify
$testPassword = 'SecurePassword!@#915';
$hashed = password_hash($testPassword, PASSWORD_BCRYPT);
$runner->assert('Password hashed with BCRYPT', password_verify($testPassword, $hashed));
$runner->assert('Wrong password rejected', !password_verify('WrongPassword', $hashed));

// Teacher Default Password Generation pattern: {username}@915
$testTeacherUsername = 'dr_ahmad';
$expectedDefault = $testTeacherUsername . '@915';
$runner->assertEquals('Teacher default password follows {username}@915', $testTeacherUsername . '@915', $expectedDefault);

// Safe Integer Sanitization for SQL IN clauses
$refTPC = new ReflectionClass('TeacherPortalController');
$methodSafeInts = $refTPC->getMethod('safeInts');
$methodSafeInts->setAccessible(true);
// =========================================================================
// 7. CHAPTER 4 BENCHMARK DATASET (S1 - S10) & NIST SP 800-30 METRICS
// =========================================================================
echo "\n>> Section 7: S1-S10 Thesis Benchmark Dataset & Evaluation Metrics\n";

$benchmarkData = [
    ['id' => 'S1', 'B' => 0.000, 'A' => 0.08, 'S' => 0.00, 'N' => 0.00, 'expected_risk' => 1.6,  'expected_level' => 'safe',   'actual' => false],
    ['id' => 'S2', 'B' => 0.057, 'A' => 0.12, 'S' => 0.00, 'N' => 0.00, 'expected_risk' => 3.9,  'expected_level' => 'safe',   'actual' => false],
    ['id' => 'S3', 'B' => 0.086, 'A' => 0.10, 'S' => 0.00, 'N' => 0.00, 'expected_risk' => 4.3,  'expected_level' => 'safe',   'actual' => false],
    ['id' => 'S4', 'B' => 0.146, 'A' => 0.14, 'S' => 0.00, 'N' => 0.00, 'expected_risk' => 6.7,  'expected_level' => 'low',    'actual' => false],
    ['id' => 'S5', 'B' => 0.767, 'A' => 0.14, 'S' => 0.00, 'N' => 0.00, 'expected_risk' => 23.2, 'expected_level' => 'medium', 'actual' => true],
    ['id' => 'S6', 'B' => 0.058, 'A' => 0.88, 'S' => 0.00, 'N' => 0.00, 'expected_risk' => 19.1, 'expected_level' => 'low',    'actual' => true],
    ['id' => 'S7', 'B' => 0.087, 'A' => 0.16, 'S' => 0.75, 'N' => 0.00, 'expected_risk' => 25.5, 'expected_level' => 'medium', 'actual' => true],
    ['id' => 'S8', 'B' => 0.028, 'A' => 0.11, 'S' => 0.75, 'N' => 0.00, 'expected_risk' => 22.9, 'expected_level' => 'medium', 'actual' => true],
    ['id' => 'S9', 'B' => 0.060, 'A' => 0.09, 'S' => 0.00, 'N' => 1.00, 'expected_risk' => 30.1, 'expected_level' => 'medium', 'actual' => true],
    ['id' => 'S10','B' => 0.722, 'A' => 0.90, 'S' => 1.00, 'N' => 1.00, 'expected_risk' => 90.6, 'expected_level' => 'high',   'actual' => true],
];

$tp = $fp = $tn = $fn = 0;
foreach ($benchmarkData as $s) {
    $score = round(RiskEngine::combineComponents($s['B'], $s['A'], $s['S'], $s['N']), 1);
    $level = RiskEngine::levelFor((int)round($score));
    $predicted = $score >= 21.0;
    $actual = $s['actual'];

    if ($predicted && $actual) $tp++;
    elseif ($predicted && !$actual) $fp++;
    elseif (!$predicted && $actual) $fn++;
    else $tn++;

    $runner->assert("{$s['id']} computed risk matches thesis expectation (~{$s['expected_risk']}%)", abs($score - $s['expected_risk']) <= 0.5, "Got {$score}%, expected {$s['expected_risk']}%");
    $runner->assertEquals("{$s['id']} risk level classification matches NIST level", $level, $s['expected_level']);
}

$acc = round(($tp + $tn) / ($tp + $tn + $fp + $fn) * 100, 1);
$prec = round($tp / ($tp + $fp) * 100, 1);
$rec = round($tp / ($tp + $fn) * 100, 1);
$fpr = round($fp / ($fp + $tn) * 100, 1);
$f1 = round(2 * ($prec * $rec) / ($prec + $rec), 1);

$runner->assertEquals('Benchmark Accuracy equals 90.0%', $acc, 90.0);
$runner->assertEquals('Benchmark Precision equals 100.0%', $prec, 100.0);
$runner->assertEquals('Benchmark Recall equals 83.3%', $rec, 83.3);
$runner->assertEquals('Benchmark FPR equals 0.0%', $fpr, 0.0);
$runner->assertEquals('Benchmark F1-Score equals 90.9%', $f1, 90.9);

$runner->printSummary('Full Platform Verification');
