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
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');

$isProd = em_is_production();
if ($isProd) {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
}

set_exception_handler(function (Throwable $e) use ($isProd): void {
    error_log('[ExamMonitor] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('خطأ في الخادم: ' . $e->getMessage(), 500, ['trace' => $isProd ? null : $e->getTraceAsString()]);
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
