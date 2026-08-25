<?php
/**
 * Application bootstrap: autoload, error handling, CORS, request helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

date_default_timezone_set(em_config('app.timezone', 'UTC'));

// ---------------------------------------------------------------
// Simple PSR-4-ish autoloader for app/*.php (single namespace level)
// ---------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $paths = [
        __DIR__ . '/' . $class . '.php',
        __DIR__ . '/controllers/' . $class . '.php',
    ];
    foreach ($paths as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// ---------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');

$isProd = em_is_production();

set_exception_handler(function (Throwable $e) use ($isProd): void {
    error_log('[ExamMonitor] ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $isApi = str_starts_with($path, '/api/') || str_starts_with($path, '/telemetry');

    if ($isApi) {
        Response::error('خطأ في الخادم: ' . $e->getMessage(), 500, ['trace' => $isProd ? null : $e->getTraceAsString()]);
    } else {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        echo "<!DOCTYPE html><html dir='rtl' lang='ar'><head><meta charset='utf-8'><title>مراقب الامتحانات</title><style>body{font-family:system-ui,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#fff;padding:2rem;border-radius:1.5rem;box-shadow:0 20px 40px -15px rgba(0,0,0,.08);max-width:480px;text-align:center;border:1px solid #e2e8f0}h1{color:#e11d48;font-size:1.2rem;margin-bottom:0.5rem;font-weight:800}p{color:#64748b;font-size:0.875rem;line-height:1.5}a{display:inline-block;margin-top:1.25rem;padding:0.7rem 1.5rem;background:#4f46e5;color:#fff;text-decoration:none;border-radius:0.75rem;font-weight:800;font-size:0.85rem}</style></head><body><div class='card'><h1>تعذر تحميل الصفحة المؤقت</h1><p>{$msg}</p><a href='/admin'>إعادة المحاولة</a></div></body></html>";
        exit;
    }
});

// ---------------------------------------------------------------
// CORS (needed for the cross-origin telemetry beacons from Moodle)
// ---------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Content-Security-Policy: default-src 'self' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' https:; connect-src 'self' https:; frame-ancestors 'self';");

// ---------------------------------------------------------------
// JSON body reader
// ---------------------------------------------------------------
function em_body_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
