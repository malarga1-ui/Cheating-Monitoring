<?php
/**
 * Diagnostic script — run via: php diag.php
 * Checks both the 400 (sync) and 500 (audit) issues.
 */
require_once __DIR__ . '/../app/bootstrap.php';

echo "=== DIAGNOSTIC ===\n\n";

// 1. Check accounts table
echo "1. ACCOUNTS:\n";
$accounts = Database::fetchAll('SELECT id, username, org_name, email, site_domain, api_secret IS NOT NULL AS has_secret, role, status FROM accounts ORDER BY id');
foreach ($accounts as $a) {
    echo "   id={$a['id']} user={$a['username']} role={$a['role']} status={$a['status']} domain={$a['site_domain']} secret=" . ($a['has_secret'] ? 'YES' : 'NO') . "\n";
}
echo "\n";

// 2. Check audit_log table existence + schema
echo "2. AUDIT_LOG table:\n";
$tables = Database::fetchAll("SHOW TABLES LIKE 'audit_log'");
if (empty($tables)) {
    echo "   *** TABLE audit_log DOES NOT EXIST ***\n";
} else {
    $cols = Database::fetchAll("DESCRIBE audit_log");
    echo "   Columns: " . implode(', ', array_column($cols, 'Field')) . "\n";
    $count = Database::scalar('SELECT COUNT(*) FROM audit_log');
    echo "   Row count: $count\n";
}
echo "\n";

// 3. Check all required tables
echo "3. ALL TABLES:\n";
$required = ['accounts', 'teachers', 'students', 'courses', 'exams', 'telemetry_events',
    'session_summaries', 'event_flags', 'network_groups', 'similarity_pairs',
    'multi_device_sessions', 'settings', 'risk_indicators', 'risk_indicator_values',
    'risk_evaluations', 'teacher_actions', 'teacher_action_logs', 'verdicts',
    'users', 'audit_log', 'course_access', 'login_attempts', 'session_verdicts',
    'staff', 'course_teachers', 'student_answers', 'exam_snapshots'];
$existing = array_column(Database::fetchAll("SHOW TABLES"), 0);
$missing = array_diff($required, $existing);
if (!empty($missing)) {
    echo "   *** MISSING TABLES: " . implode(', ', $missing) . " ***\n";
} else {
    echo "   All " . count($required) . " tables present.\n";
}
echo "\n";

// 4. Check if the session would work for auth
echo "4. SESSION AUTH CHECK:\n";
echo "   session_name: " . em_config('auth.session_name') . "\n";
echo "   cookie_secure: " . (em_config('auth.cookie_secure') ? 'true' : 'false') . "\n";
echo "   cookie_samesite: " . em_config('auth.cookie_samesite') . "\n";
echo "\n";

// 5. Test Moodle connectivity
echo "5. MOODLE CONNECTIVITY:\n";
$account = Database::fetchOne('SELECT * FROM accounts WHERE id = 1');
$domain = $account['site_domain'] ?? '';
$secret = $account['api_secret'] ?? '';
echo "   domain: $domain\n";
echo "   secret: " . ($secret !== '' ? substr($secret, 0, 10) . '...' : 'EMPTY') . "\n";
if ($domain !== '') {
    $url = "https://$domain/mod/quiz/accessrule/exammonitor/sync_api.php";
    echo "   Testing URL: $url\n";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['secret' => $secret]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    echo "   HTTP: $httpCode\n";
    echo "   curl_error: $err\n";
    echo "   curl_errno: $errno\n";
    echo "   Response: " . substr($response ?: '(empty)', 0, 300) . "\n";
} else {
    echo "   *** NO DOMAIN CONFIGURED ***\n";
}
echo "\n";

echo "=== DONE ===\n";
