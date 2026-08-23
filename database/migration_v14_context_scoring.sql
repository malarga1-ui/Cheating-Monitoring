-- Migration v14: Context-Aware Risk Scoring
-- 1. Ensure risk_indicators table exists and is seeded
-- 2. Add question_count to exams
-- 3. Add exam_minutes to session_summaries for normalization

CREATE TABLE IF NOT EXISTS risk_indicators (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indicator_key  VARCHAR(64)  NOT NULL,
    label_ar       VARCHAR(190) NOT NULL DEFAULT '',
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    description    VARCHAR(255) NOT NULL DEFAULT '',
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    category       VARCHAR(32)  NOT NULL DEFAULT 'behavioral',
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_risk_indicators_key (indicator_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO risk_indicators (indicator_key, label_ar, weight_percent, enabled, description, sort_order, category) VALUES
    ('devtools_count',         'فتح أدوات المطوّر',        10, 1, 'دخول وضع المطورين أثناء الامتحان.', 1, 'behavioral'),
    ('screenshot_count',       'محاولة لقطة شاشة',         8, 1, 'محاولة أخذ لقطة للشاشة أثناء الامتحان.', 2, 'behavioral'),
    ('suspicious_key_count',   'مفاتيح مشبوهة',            7, 1, 'ضغط F12 أو Alt+Tab أثناء الامتحان.', 3, 'behavioral'),
    ('rapid_answer_changes',   'تغيير إجابة سريع',         7, 1, 'تعديل الإجابات بشكل متكرر وسريع.', 4, 'behavioral'),
    ('paste_count',            'لصق',                      7, 1, 'لصق نص من مصدر خارجي.', 5, 'behavioral'),
    ('tab_hidden_count',       'إخفاء التبويب',            7, 1, 'الانتقال إلى تبويب آخر ثم العودة.', 6, 'behavioral'),
    ('page_leave_count',       'مغادرة الصفحة',            7, 1, 'محاولة مغادرة صفحة الامتحان.', 7, 'behavioral'),
    ('copy_count',             'نسخ',                      5, 1, 'نسخ نص من صفحة الامتحان.', 8, 'behavioral'),
    ('fullscreen_exit_count',  'الخروج من ملء الشاشة',     5, 1, 'الخروج من وضع ملء الشاشة المفروض.', 9, 'behavioral'),
    ('answer_speed_ratio',     'سرعة الإجابة المشبوهة',    5, 1, 'نسبة الوقت الفعلي مقابل المتوقع.', 10, 'behavioral'),
    ('blur_count',             'فقدان التركيز',            4, 1, 'الانتقال من النافذة إلى نافذة أخرى.', 11, 'behavioral'),
    ('offline_count',          'انقطاع النت',              4, 1, 'انقطاع الاتصال أثناء الامتحان.', 12, 'behavioral'),
    ('copy_selection_chars',   'تحديد نص للنسخ',           3, 1, 'تحديد نصوص طويلة في صفحة الامتحان.', 13, 'behavioral'),
    ('idle_count',             'فترات خمول',               3, 1, 'توقف النشاط لفترة طويلة.', 14, 'behavioral'),
    ('right_click_count',      'نقر يمين',                 3, 1, 'فتح قائمة النقر الأيمن.', 15, 'behavioral'),
    ('tab_hidden_duration_ms', 'مدة إخفاء التبويب',        3, 1, 'الوقت الإجمالي خارج الامتحان.', 16, 'behavioral'),
    ('typing_backspace_count', 'مسح متكرر',               0, 0, 'حذف متكرر أثناء الكتابة.', 17, 'behavioral'),
    ('mouse_move_count',       'حركة الفأرة',              0, 0, 'حركة فأرة مكثفة.', 18, 'behavioral'),
    ('mouse_scroll_count',     'تمرير الفأرة',             0, 0, 'تمرير متكرر.', 19, 'behavioral'),
    ('idle_duration_ms',       'مدة الخمول',               0, 0, 'إجمالي وقت التوقف.', 20, 'behavioral'),
    ('typing_keydown_count',   'كتابة',                    0, 0, 'عدد ضغطات المفاتيح.', 21, 'behavioral'),
    ('answer_changed_count',   'تغيير الإجابات',           0, 0, 'عدد مرات تغيير الإجابات.', 22, 'behavioral'),
    ('other_count',            'أحداث أخرى',               0, 0, 'أحداث إضافية غير مصنّفة.', 23, 'behavioral'),
    ('same_ip_student_count',  'تجمع بنفس الـ IP',         7, 1, 'عدد الطلاب بنفس عنوان IP.', 24, 'network'),
    ('ip_changed_count',       'تغيير الـ IP',             5, 1, 'عدد مرات تغيير IP.', 25, 'network'),
    ('same_ip_risk_score',     'خطورة الشبكة',             3, 1, 'مؤشر الخطورة من تحليل الشبكة.', 26, 'network'),
    ('ai_suspect_score',       'إجابات مشبوهة بالـ AI',   10, 1, 'مؤشر إجابات مولّدة بالـ AI.', 27, 'ai'),
    ('answer_text_count',      'عدد الإجابات النصية',       6, 1, 'عدد الإجابات التي تحتوي على نص.', 28, 'ai'),
    ('typing_answer_ratio',    'نسبة الكتابة الفعلية',      4, 1, 'نسبة الإجابات المكتوبة بالكيبورد.', 29, 'ai'),
    ('similarity_max_score',   'أعلى تشابه',               8, 1, 'أعلى نسبة تشابه مع طالب آخر.', 30, 'similarity'),
    ('similarity_match_count', 'عدد التطابقات',            7, 1, 'عدد الإجابات المتطابقة.', 31, 'similarity')
ON DUPLICATE KEY UPDATE indicator_key = indicator_key;

-- Add question_count to exams for context normalization
ALTER TABLE exams ADD COLUMN question_count INT UNSIGNED DEFAULT 0 COMMENT 'Total questions in this exam (from Moodle sync or answer_records)';
