<?php
/**
 * Background worker entry point (CLI / cron).
 *
 * Usage:
 *   php scripts/worker.php [maxEventsPerRun]          # run once
 *   php scripts/worker.php --loop [maxEventsPerRun]   # run every ~60s until the
 *                                                       host-enforced 5-minute
 *                                                       cron window elapses
 *
 * Why a loop: some shared hosts (Namecheap cPanel) forbid cron intervals below
 * 5 minutes. The loop keeps the watermark moving every minute while the cron
 * only fires every 5 minutes. Every Aggregator::process() call is idempotent
 * (watermark + INSERT..ON DUPLICATE KEY UPDATE), so overlapping runs are safe.
 *
 * A lock file guarantees only one worker is active at a time on this host.
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$loop = false;
$maxEvents = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--loop') {
        $loop = true;
    } elseif (is_numeric($arg)) {
        $maxEvents = (int)$arg;
    }
}

// CLI normally has max_execution_time = 0 already, but be explicit.
set_time_limit(0);
ignore_user_abort(true);

$lockFile = __DIR__ . '/../storage/worker.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0777, true);
}

$fp = @fopen($lockFile, 'c');
if ($fp === false) {
    fwrite(STDERR, "[تحذير] تعذر فتح ملف القفل.\n");
} elseif (!flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "منشغل: worker آخر يعمل حالياً — تم التخطي.\n");
    exit(0);
}

function runOnce(?int $maxEvents): void
{
    $started = microtime(true);
    $result = Aggregator::process(2000, $maxEvents);
    $elapsed = round((microtime(true) - $started) * 1000);
    echo json_encode(array_merge($result, ['elapsed_ms' => $elapsed]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

if (!$loop) {
    runOnce($maxEvents);
    exit(0);
}

// Bounded loop: run for ~250s so it stays inside the */5 cron window and
// never overlaps with the next cron invocation.
$endTime = time() + 250;
$first = true;

while (time() < $endTime) {
    if (!$first) {
        sleep(55); // slightly under 60s so we stay inside the window
    }
    $first = false;
    runOnce($maxEvents);
}

@fclose($fp);
