<?php
/**
 * Live End-to-End Test Suite for all 4 Roles:
 * 1. Super Admin (Owner)
 * 2. University Staff (Supervisor/Admin)
 * 3. Teacher 1 (mah_teacher1)
 * 4. Teacher 2 (azez)
 */

$baseUrl = 'https://jadallahkhaled.com';

class HttpClient {
    private string $cookieFile;
    private ?string $csrfToken = null;
    private string $baseUrl;

    public function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'em_cookie_');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $method, string $path, ?array $jsonBody = null): array {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $headers = ['Accept: application/json'];
        if ($this->csrfToken) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (isset($data['csrf']) && is_string($data['csrf'])) {
            $this->csrfToken = $data['csrf'];
        } elseif (isset($data['data']['csrf']) && is_string($data['data']['csrf'])) {
            $this->csrfToken = $data['data']['csrf'];
        }

        return [
            'status' => $httpCode,
            'data'   => $data,
            'raw'    => $raw,
            'error'  => $err,
        ];
    }
}

function testSection(string $title): void {
    echo "\n\033[1;34m" . str_repeat('=', 65) . "\033[0m\n";
    echo "\033[1;37m  $title\033[0m\n";
    echo "\033[1;34m" . str_repeat('=', 65) . "\033[0m\n";
}

function testAssert(string $desc, bool $condition, ?string $extra = null): void {
    if ($condition) {
        echo "  \033[32m[PASS]\033[0m $desc\n";
    } else {
        echo "  \033[31m[FAIL]\033[0m $desc" . ($extra ? " ($extra)" : "") . "\n";
    }
}

// -------------------------------------------------------------
// 1. Check Public Endpoints & Universities
// -------------------------------------------------------------
testSection("1. Public Sites & Infrastructure");
$anon = new HttpClient($baseUrl);
$res = $anon->request('GET', '/api/public/sites');
testAssert("GET /api/public/sites returns 200", $res['status'] === 200);
$sites = $res['data']['data'] ?? $res['data'] ?? [];
testAssert("University list contains accounts", is_array($sites) && count($sites) > 0);
$islamicUni = null;
foreach ($sites as $s) {
    if (str_contains($s['org_name'] ?? '', 'إسلام') || ($s['id'] ?? 0) === 1) {
        $islamicUni = $s;
        break;
    }
}
$accountId = $islamicUni ? (int)$islamicUni['id'] : 1;
testAssert("Resolved University Account ID ($accountId)", $accountId > 0);

// -------------------------------------------------------------
// 2. Super Admin (Owner) Account Test
// -------------------------------------------------------------
testSection("2. Super Admin (Owner) Account (jad2003banna@gmail.com)");
$admin = new HttpClient($baseUrl);
$adminLogin = $admin->request('POST', '/api/auth/login', [
    'email'    => 'jad2003banna@gmail.com',
    'password' => 'jad@Admin123$',
]);
testAssert("Admin Login HTTP 200", $adminLogin['status'] === 200, "HTTP {$adminLogin['status']}: " . ($adminLogin['data']['error'] ?? $adminLogin['raw']));
$user = $adminLogin['data']['data']['user'] ?? $adminLogin['data']['user'] ?? [];
testAssert("User role is owner/admin", ($user['role'] ?? '') === 'owner' || ($user['role'] ?? '') === 'admin');

if ($adminLogin['status'] === 200) {
    $dash = $admin->request('GET', '/api/dashboard/summary');
    testAssert("Admin Dashboard Summary (200)", $dash['status'] === 200);

    $courses = $admin->request('GET', '/api/courses');
    testAssert("Admin Courses List (200)", $courses['status'] === 200);

    $exams = $admin->request('GET', '/api/exams');
    testAssert("Admin Exams List (200)", $exams['status'] === 200);

    $systemPerf = $admin->request('GET', '/api/performance/system');
    testAssert("Admin Performance Metrics Chapter 4 (200)", $systemPerf['status'] === 200);

    $staffList = $admin->request('GET', '/api/staff');
    echo "\n  [DEBUG] Staff List on Server:\n";
    echo "  " . json_encode($staffList['data'] ?? $staffList['raw'], JSON_UNESCAPED_UNICODE) . "\n";

    $teachersList = $admin->request('GET', '/api/teachers');
    echo "\n  [DEBUG] Teachers List on Server:\n";
    echo "  " . json_encode($teachersList['data'] ?? $teachersList['raw'], JSON_UNESCAPED_UNICODE) . "\n";
}

// -------------------------------------------------------------
// 3. University Staff (adminIslam) Test
// -------------------------------------------------------------
testSection("3. University Staff Account (adminIslam)");
$staff = new HttpClient($baseUrl);
$staffPasses = ['123@islam', 'adminIslam@915', 'adminIslam', '12345678', 'admin@123', 'admin', '123456'];
$staffLogin = null;
$winningStaffPass = null;
foreach ($staffPasses as $sp) {
    $res = $staff->request('POST', '/api/auth/staff-login', [
        'account_id' => $accountId,
        'username'   => 'adminIslam',
        'password'   => $sp,
    ]);
    if ($res['status'] === 200) {
        $staffLogin = $res;
        $winningStaffPass = $sp;
        break;
    }
}
if (!$staffLogin) {
    $staffLogin = $staff->request('POST', '/api/auth/staff-login', [
        'account_id' => $accountId,
        'username'   => 'adminIslam',
        'password'   => '123@islam',
    ]);
}
testAssert("Staff Login (Password: " . ($winningStaffPass ?? '123@islam') . ")", $staffLogin['status'] === 200, "HTTP {$staffLogin['status']}: " . ($staffLogin['data']['error'] ?? $staffLogin['raw']));

if ($staffLogin['status'] === 200) {
    $staffMe = $staff->request('GET', '/api/auth/me');
    testAssert("Staff Auth Me (200)", $staffMe['status'] === 200);

    $staffDash = $staff->request('GET', '/api/dashboard/summary');
    testAssert("Staff Dashboard Summary (200)", $staffDash['status'] === 200);
}

// -------------------------------------------------------------
// 4. Teacher 1 (mah_teacher1) Test
// -------------------------------------------------------------
testSection("4. Teacher 1 Account (mah_teacher1)");
$t1 = new HttpClient($baseUrl);
$t1Passes = ['mah_teacher1@123', 'mah_teacher1@915', '123456', '12345678', 'Teacher@mah_teacher1', 'Admin123', '123456789'];
$t1Login = null;
$winningT1Pass = null;
foreach ($t1Passes as $tp) {
    $res = $t1->request('POST', '/api/auth/teacher-login', [
        'account_id' => $accountId,
        'username'   => 'mah_teacher1',
        'password'   => $tp,
    ]);
    if ($res['status'] === 200) {
        $t1Login = $res;
        $winningT1Pass = $tp;
        break;
    }
}
if (!$t1Login) {
    $t1Login = $t1->request('POST', '/api/auth/teacher-login', [
        'account_id' => $accountId,
        'username'   => 'mah_teacher1',
        'password'   => 'mah_teacher1@123',
    ]);
}
testAssert("Teacher 1 Login (Password: " . ($winningT1Pass ?? 'mah_teacher1@123') . ")", $t1Login['status'] === 200, "HTTP {$t1Login['status']}: " . ($t1Login['data']['error'] ?? $t1Login['raw']));

if ($t1Login['status'] === 200) {
    $t1Courses = $t1->request('GET', '/api/teacher/courses');
    testAssert("Teacher 1 Courses (200)", $t1Courses['status'] === 200);
    $cList = $t1Courses['data']['data'] ?? $t1Courses['data'] ?? [];
    testAssert("Teacher 1 Courses List is array", is_array($cList));

    $t1Exams = $t1->request('GET', '/api/teacher/exams');
    testAssert("Teacher 1 Exams List (200)", $t1Exams['status'] === 200);

    $t1Students = $t1->request('GET', '/api/teacher/students');
    testAssert("Teacher 1 Students List (200)", $t1Students['status'] === 200);

    $t1Network = $t1->request('GET', '/api/teacher/exams/network');
    testAssert("Teacher 1 Network Groups (200)", $t1Network['status'] === 200);

    $t1Sim = $t1->request('GET', '/api/teacher/exams/similarity');
    testAssert("Teacher 1 Similarity Pairs (200)", $t1Sim['status'] === 200);
}

// -------------------------------------------------------------
// 5. Teacher 2 (azez) Test
// -------------------------------------------------------------
testSection("5. Teacher 2 Account (azez)");
$t2 = new HttpClient($baseUrl);
$t2Passes = ['123456789', 'azez@915', 'azez@123', '123456', '12345678', 'ahmed@915', 'Teacher@azez'];
$t2Login = null;
$winningT2Pass = null;
foreach ($t2Passes as $tp) {
    $res = $t2->request('POST', '/api/auth/teacher-login', [
        'account_id' => $accountId,
        'username'   => 'azez',
        'password'   => $tp,
    ]);
    if ($res['status'] === 200) {
        $t2Login = $res;
        $winningT2Pass = $tp;
        break;
    }
}
if (!$t2Login) {
    $t2Login = $t2->request('POST', '/api/auth/teacher-login', [
        'account_id' => $accountId,
        'username'   => 'azez',
        'password'   => '123456789',
    ]);
}
testAssert("Teacher 2 Login (Password: " . ($winningT2Pass ?? '123456789') . ")", $t2Login['status'] === 200, "HTTP {$t2Login['status']}: " . ($t2Login['data']['error'] ?? $t2Login['raw']));

if ($t2Login['status'] === 200) {
    $t2Courses = $t2->request('GET', '/api/teacher/courses');
    testAssert("Teacher 2 Courses (200)", $t2Courses['status'] === 200);

    $t2Exams = $t2->request('GET', '/api/teacher/exams');
    testAssert("Teacher 2 Exams List (200)", $t2Exams['status'] === 200);

    $t2Students = $t2->request('GET', '/api/teacher/students');
    testAssert("Teacher 2 Students List (200)", $t2Students['status'] === 200);
}

echo "\n\033[1;32m═══════════════════════════════════════════════════════════════\033[0m\n";
echo "  Live Verification Completed.\n";
echo "\033[1;32m═══════════════════════════════════════════════════════════════\033[0m\n\n";
