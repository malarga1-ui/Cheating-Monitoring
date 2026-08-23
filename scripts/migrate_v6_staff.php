<?php
/**
 * Staff & permissions migration (v6):
 *   1. users.account_id          — staff members now belong to one university
 *      account (was: global legacy supervisors with account_id = 0).
 *   2. users per-account unique username (account_id, username).
 *   3. course_access.account_id  — supervisor grants are now scoped to an
 *      account (course_access.user_id -> users.id within the same account).
 *
 * Usage:
 *   php scripts/migrate_v6_staff.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- 1. users.account_id ------------------------------------------------
$cols = $db->query("SHOW COLUMNS FROM users LIKE 'account_id'")->fetchAll();
if (!$cols) {
    $db->exec('ALTER TABLE users ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id');
    echo 'أضيف العمود users.account_id.' . PHP_EOL;
} else {
    echo 'العمود users.account_id موجود مسبقاً — تم التخطي.' . PHP_EOL;
}

$idx = $db->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_account'"
)->fetchColumn();
if ((int)$idx === 0) {
    $db->exec('ALTER TABLE users ADD KEY idx_users_account (account_id)');
    echo 'أضيف الفهرس users.idx_users_account.' . PHP_EOL;
} else {
    echo 'الفهرس users.idx_users_account موجود مسبقاً — تم التخطي.' . PHP_EOL;
}

// ---- 2. per-account unique username --------------------------------------
$idx = $db->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_account_username'"
)->fetchColumn();
if ((int)$idx === 0) {
    $dup = (int)$db->query(
        'SELECT COUNT(*) FROM (SELECT account_id, username, COUNT(*) AS c
                                 FROM users GROUP BY account_id, username HAVING c > 1) d'
    )->fetchColumn();
    if ($dup > 0) {
        echo 'تنبيه: توجد أسماء مستخدمين مكررة في users (' . $dup . ') — لا يمكن تحويل الفهرس إلى (account_id, username) تلقائياً. أزل التكرارات وأعد التشغيل.' . PHP_EOL;
    } else {
        try {
            $db->exec('ALTER TABLE users DROP INDEX uq_users_username');
        } catch (Throwable $e) {
            echo 'ملاحظة: لم يوجد الفهرس القديم uq_users_username (' . $e->getMessage() . ').' . PHP_EOL;
        }
        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_account_username (account_id, username)');
        echo 'أُنشئ الفهرس الفريد users.uq_users_account_username (account_id, username).' . PHP_EOL;
    }
} else {
    echo 'الفهرس users.uq_users_account_username موجود مسبقاً — تم التخطي.' . PHP_EOL;
}

// ---- 3. course_access.account_id -----------------------------------------
$cols = $db->query("SHOW COLUMNS FROM course_access LIKE 'account_id'")->fetchAll();
if (!$cols) {
    $db->exec('ALTER TABLE course_access ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id');
    echo 'أضيف العمود course_access.account_id.' . PHP_EOL;
} else {
    echo 'العمود course_access.account_id موجود مسبقاً — تم التخطي.' . PHP_EOL;
}

$idx = $db->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'course_access' AND index_name = 'idx_course_access_account'"
)->fetchColumn();
if ((int)$idx === 0) {
    $db->exec('ALTER TABLE course_access ADD KEY idx_course_access_account (account_id, user_id)');
    echo 'أضيف الفهرس course_access.idx_course_access_account.' . PHP_EOL;
} else {
    echo 'الفهرس course_access.idx_course_access_account موجود مسبقاً — تم التخطي.' . PHP_EOL;
}

echo 'اكتمل ترحيل الموظفين والصلاحيات (v6) بنجاح.' . PHP_EOL;
