<?php
/**
 * Setup wizard migration (v4):
 *   Adds `setup_progress` (JSON) to `accounts` so each tenant can track
 *   their onboarding steps (download plugin, update Moodle, connect,
 *   enable monitoring) with a progress bar.
 *
 * Usage:
 *   php scripts/migrate_v4_setup.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = $db->query("SHOW COLUMNS FROM accounts LIKE 'setup_progress'")->fetchAll();
if (!$cols) {
    $db->exec("ALTER TABLE accounts ADD COLUMN setup_progress TEXT NULL AFTER site_domain");
    echo "أضيف العمود setup_progress إلى جدول accounts." . PHP_EOL;
} else {
    echo "العمود setup_progress موجود مسبقاً — تم التخطي." . PHP_EOL;
}

echo "اكتمل ترحيل معالج التثبيت بنجاح." . PHP_EOL;
