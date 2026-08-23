<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = Database::connection();
$pdo->exec('TRUNCATE TABLE session_summaries');
$pdo->exec('TRUNCATE TABLE sessions');
$pdo->exec('UPDATE agg_watermark SET last_event_id = 0 WHERE id = 1');
echo 'reset done' . PHP_EOL;
