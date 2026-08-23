<?php
/**
 * System health & cache stats endpoint.
 */
final class HealthController
{
    public static function stats(): void
    {
        Auth::requireLogin();

        $cacheStats = Cache::stats();
        $dbOk = true;
        try {
            Database::scalar('SELECT 1');
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        Response::ok([
            'status' => $dbOk ? 'healthy' : 'degraded',
            'cache' => $cacheStats,
            'database' => ['connected' => $dbOk],
            'php' => [
                'version' => PHP_VERSION,
                'memory_peak' => memory_get_peak_usage(true),
                'memory_limit' => ini_get('memory_limit'),
            ],
        ]);
    }
}
