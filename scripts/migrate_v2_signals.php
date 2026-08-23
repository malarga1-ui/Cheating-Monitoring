<?php
/**
 * Migration: adds the strong-signal counter columns introduced in the
 * "phase 2" update to existing session_summaries tables without dropping
 * any data.
 *
 * Usage:
 *   php scripts/migrate_v2_signals.php
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function mout(string $msg): void
{
    echo $msg . PHP_EOL;
}

$cfg = em_config('db');

$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']);

try {
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    mout('[خطأ] تعذر الاتصال بـ MySQL: ' . $e->getMessage());
    exit(1);
}

$dbName = str_replace('`', '', $cfg['database']);
$pdo->exec("USE `$dbName`");

$newColumns = [
    'devtools_count'         => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'suspicious_key_count'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'screenshot_count'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'rapid_answer_changes'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'idle_count'             => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'fullscreen_exit_count'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'typing_keydown_count'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'mouse_click_count'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'tab_visible_count'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'copy_selection_chars'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'idle_duration_ms'       => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'typing_backspace_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'typing_enter_count'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
    'mouse_move_count'       => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'mouse_scroll_count'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
];

$table = 'session_summaries';

try {
    $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    mout('[خطأ] الجدول ' . $table . ' غير موجود: ' . $e->getMessage());
    exit(1);
}

$added = 0;
foreach ($newColumns as $col => $def) {
    if (in_array($col, $existing, true)) {
        mout("العمود $col موجود مسبقاً — تم التخطي.");
        continue;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    mout("تمت إضافة العمود $col.");
    $added++;
}

if ($added === 0) {
    mout('لا توجد أعمدة جديدة — المخطط محدّث بالفعل.');
} else {
    mout("اكتمل ترحيل الأعمدة: أُضيف $added عموداً.");
}

// --- Phase 3: teacher columns on exams -------------------------------------

mout('إضافة أعمدة المدرس إلى جدول الامتحانات (exams) ...');
$examTable = 'exams';
$examColumns = [
    'moodle_teacher_id' => 'INT UNSIGNED NULL',
    'teacher_name'      => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
];
try {
    $existingExamCols = $pdo->query("SHOW COLUMNS FROM `$examTable`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    mout('[خطأ] الجدول ' . $examTable . ' غير موجود: ' . $e->getMessage());
    exit(1);
}
$examAdded = 0;
foreach ($examColumns as $col => $def) {
    if (in_array($col, $existingExamCols, true)) {
        mout("العمود $col موجود مسبقاً — تم التخطي.");
        continue;
    }
    $pdo->exec("ALTER TABLE `$examTable` ADD COLUMN `$col` $def");
    mout("تمت إضافة العمود $col.");
    $examAdded++;
}
if ($examAdded === 0) {
    mout('لا توجد أعمدة جديدة في exams — المخطط محدّث بالفعل.');
} else {
    mout("اكتمل ترحيل أعمدة الامتحانات: أُضيف $examAdded عموداً.");
}

// --- Phase 2b: course authorization tables ---------------------------------

mout('التحقق من جداول الصلاحيات (courses / course_access) ...');
$courseTables = [
    'courses' => "CREATE TABLE IF NOT EXISTS courses (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        moodle_course_id INT UNSIGNED NOT NULL,
        name             VARCHAR(255) NOT NULL DEFAULT '',
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_courses_moodle_course (moodle_course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'course_access' => "CREATE TABLE IF NOT EXISTS course_access (
        user_id          INT UNSIGNED NOT NULL,
        moodle_course_id INT UNSIGNED NOT NULL,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, moodle_course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($courseTables as $t => $ddl) {
    $pdo->exec($ddl);
    mout("تم إنشاء الجدول $t (أو موجود مسبقاً).");
}

// --- Phase 3b: teacher sync tables -----------------------------------------

mout('التحقق من جداول المدرسين (teachers / course_teachers) ...');
$teacherTables = [
    'teachers' => "CREATE TABLE IF NOT EXISTS teachers (
        moodle_teacher_id INT UNSIGNED PRIMARY KEY,
        fullname          VARCHAR(190) NOT NULL DEFAULT '',
        username          VARCHAR(190) NOT NULL DEFAULT '',
        first_seen_at     DATETIME NULL,
        last_seen_at      DATETIME NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'course_teachers' => "CREATE TABLE IF NOT EXISTS course_teachers (
        moodle_course_id  INT UNSIGNED NOT NULL,
        moodle_teacher_id INT UNSIGNED NOT NULL,
        teacher_name      VARCHAR(255) NOT NULL DEFAULT '',
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (moodle_course_id, moodle_teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($teacherTables as $t => $ddl) {
    $pdo->exec($ddl);
    mout("تم إنشاء الجدول $t (أو موجود مسبقاً).");
}

// Register any courses already present in exams.
$pdo->exec(
    "INSERT IGNORE INTO courses (moodle_course_id, name)
     SELECT DISTINCT moodle_course_id, '' FROM exams WHERE moodle_course_id > 0"
);
mout('تم تسجيل الدورات الموجودة في الامتحانات الحالية.');

// --- Phase 4: configurable cheating formula (risk_indicators) ---------------

mout('التحقق من جدول معادلة الغش (risk_indicators) ...');
$pdo->exec("CREATE TABLE IF NOT EXISTS risk_indicators (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indicator_key  VARCHAR(64)  NOT NULL,
    label_ar       VARCHAR(190) NOT NULL DEFAULT '',
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    description    VARCHAR(255) NOT NULL DEFAULT '',
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_risk_indicators_key (indicator_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
mout('تم إنشاء جدول risk_indicators (أو موجود مسبقاً).');

$seed = [
    ['devtools_count', 'فتح أدوات المطوّر', 12, 1, 'دخول وضع المطورين أثناء الامتحان (F12 / فحص).', 1],
    ['screenshot_count', 'محاولة لقطة شاشة', 10, 1, 'محاولة أخذ لقطة للشاشة أثناء الامتحان.', 2],
    ['suspicious_key_count', 'مفاتيح مشبوهة', 8, 1, 'ضغط F12 أو Alt+Tab أو مفاتيح النظام أثناء الامتحان.', 3],
    ['rapid_answer_changes', 'تغيير إجابة سريع', 8, 1, 'تعديل الإجابات بشكل متكرر وسريع.', 4],
    ['paste_count', 'لصق', 8, 1, 'لصق نص من مصدر خارجي.', 5],
    ['tab_hidden_count', 'إخفاء التبويب', 8, 1, 'الانتقال إلى تبويب آخر ثم العودة.', 6],
    ['page_leave_count', 'مغادرة الصفحة', 8, 1, 'محاولة مغادرة صفحة الامتحان.', 7],
    ['copy_count', 'نسخ', 6, 1, 'نسخ نص من صفحة الامتحان.', 8],
    ['fullscreen_exit_count', 'الخروج من ملء الشاشة', 6, 1, 'الخروج من وضع ملء الشاشة المفروض.', 9],
    ['blur_count', 'فقدان التركيز', 5, 1, 'الانتقال من النافذة إلى نافذة أخرى.', 10],
    ['offline_count', 'انقطاع النت', 5, 1, 'انقطاع الاتصال بالإنترنت أثناء الامتحان.', 11],
    ['copy_selection_chars', 'تحديد نص للنسخ', 4, 1, 'تحديد نصوص طويلة في صفحة الامتحان.', 12],
    ['idle_count', 'فترات خمول', 4, 1, 'توقف النشاط لفترة طويلة (علامة إجابة خارجية).', 13],
    ['right_click_count', 'نقر يمين', 4, 1, 'فتح قائمة النقر الأيمن.', 14],
    ['tab_hidden_duration_ms', 'مدة إخفاء التبويب', 4, 1, 'الوقت الإجمالي الذي قضاه الطالب خارج الامتحان.', 15],
    ['typing_backspace_count', 'مسح متكرر أثناء الكتابة', 0, 0, 'حذف متكرر أثناء كتابة إجابات المقالية.', 16],
    ['mouse_move_count', 'حركة الفأرة', 0, 0, 'حركة فأرة مكثفة.', 17],
    ['mouse_scroll_count', 'تمرير الفأرة', 0, 0, 'تمرير متكرر في الصفحة.', 18],
    ['idle_duration_ms', 'مدة الخمول', 0, 0, 'إجمالي وقت التوقف عن النشاط.', 19],
    ['typing_keydown_count', 'كتابة', 0, 0, 'عدد ضغطات المفاتيح.', 20],
    ['answer_changed_count', 'تغيير الإجابات', 0, 0, 'عدد مرات تغيير الإجابات.', 21],
    ['other_count', 'أحداث أخرى', 0, 0, 'أحداث إضافية غير مصنّفة.', 22],
];

$stmt = $pdo->prepare(
    'INSERT INTO risk_indicators (indicator_key, label_ar, weight_percent, enabled, description, sort_order)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE indicator_key = indicator_key'
);
foreach ($seed as $s) {
    $stmt->execute($s);
}
mout('تمت تهيئة محددات معادلة الغش (' . count($seed) . ' محدداً).');

// --- Phase 5: license / trial activation -----------------------------------

mout('التحقق من جدول التفعيل (activation) ...');
$pdo->exec("CREATE TABLE IF NOT EXISTS activation (
    id               TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status           ENUM('unactivated','trial','active') NOT NULL DEFAULT 'unactivated',
    license_key      VARCHAR(190) NOT NULL DEFAULT '',
    trial_started_at DATETIME NULL,
    trial_ends_at    DATETIME NULL,
    activated_at     DATETIME NULL,
    last_check_at    DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT INTO activation (id, status) VALUES (1, 'unactivated')
            ON DUPLICATE KEY UPDATE id = id");
mout('تم إنشاء جدول التفعيل (أو موجود مسبقاً).');

mout('اكتمل الترحيل بنجاح.');
