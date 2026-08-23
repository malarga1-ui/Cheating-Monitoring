<?php
/**
 * Cleanup old events (older than 30 days).
 * Run via cron: 0 3 * * * php /path/to/cleanup_old_events.php
 */

// Allow running from CLI only
if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

$dir = __DIR__ . '/../app';
require_once $dir . '/config.php';
require_once $dir . '/Database.php';
require_once $dir . '/helpers.php';

$config = require $dir . '/config.php';
Database::init($config['db']);

$days = (int)($argv[1] ?? 30);
$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

echo "Deleting events older than {$cutoff}...\n";

$deleted = Database::execute(
    'DELETE FROM events WHERE event_time < ? ORDER BY event_time ASC LIMIT 10000',
    [$cutoff]
);

// Continue in batches if needed
$totalDeleted = $deleted;
while ($deleted > 0) {
    $deleted = Database::execute(
        'DELETE FROM events WHERE event_time < ? ORDER BY event_time ASC LIMIT 10000',
        [$cutoff]
    );
    $totalDeleted += $deleted;
    if ($deleted > 0) {
        echo "  Deleted {$totalDeleted} rows so far...\n";
        usleep(100000); // 100ms pause between batches
    }
}

// Also cleanup old telemetry_throttle entries (older than 1 hour)
Database::execute(
    'DELETE FROM telemetry_throttle WHERE window_start < ?',
    [time() - 3600]
);

echo "Done. Total events deleted: {$totalDeleted}\n";
