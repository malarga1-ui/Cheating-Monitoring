<?php
/**
 * StudentAIAuditor — Intelligent Academic Forensic Report Generator
 * =================================================================
 * Generates comprehensive, forensic AI academic dossiers for individual students
 * by analyzing telemetry, copy/paste clipboard logs, typing biometrics,
 * question similarity, AI text scores, and tab switching behavior.
 *
 * Uses OpenRouter API (Primary: google/gemini-2.5-flash, Fallback: openai/gpt-4o-mini).
 */
final class StudentAIAuditor
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const MODELS = [
        'google/gemini-2.0-flash-001',
        'google/gemini-flash-1.5',
        'openai/gpt-4o-mini',
        'meta-llama/llama-3.3-70b-instruct',
    ];

    /** Retrieve OpenRouter API Key safely */
    private static function getApiKey(): string
    {
        if (!empty(getenv('OPENROUTER_API_KEY'))) {
            return getenv('OPENROUTER_API_KEY');
        }

        $localConfig = __DIR__ . '/../config.local.php';
        if (file_exists($localConfig)) {
            $cfg = include $localConfig;
            if (is_array($cfg) && !empty($cfg['openrouter']['api_key'])) {
                return $cfg['openrouter']['api_key'];
            }
        }

        // Base64 decoded key
        return base64_decode('c2stb3ItdjEtNDI2MDI1ODEyMjFiMjNjMTYyYjg3OTU4ZDc2NDBiNmFjMjU3MTA5N2Y0OWRiMmVlMTBmZjQ2NGNhYzg3MmFkNg==');
    }

    /** Ensure reports table exists. */
    public static function ensureTable(): void
    {
        try {
            Database::execute(
                "CREATE TABLE IF NOT EXISTS student_ai_reports (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    account_id INT UNSIGNED NOT NULL DEFAULT 0,
                    student_id INT UNSIGNED NOT NULL,
                    exam_id INT UNSIGNED NOT NULL DEFAULT 0,
                    teacher_id INT UNSIGNED NOT NULL DEFAULT 0,
                    risk_score INT NOT NULL DEFAULT 0,
                    risk_level VARCHAR(30) NOT NULL DEFAULT 'safe',
                    model_used VARCHAR(100) NOT NULL DEFAULT '',
                    report_markdown LONGTEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_student_exam (student_id, exam_id),
                    KEY idx_account_student (account_id, student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {}
    }

    /** Retrieve existing cached report if available. */
    public static function getCachedReport(int $accountId, int $studentId, ?int $examId = null): ?array
    {
        self::ensureTable();

        $sql = "SELECT r.*, e.name AS exam_name
                  FROM student_ai_reports r
                  LEFT JOIN exams e ON (e.id = r.exam_id OR e.moodle_quiz_id = r.exam_id)
                 WHERE r.student_id = ? AND (r.account_id = ? OR r.account_id = 0)";
        $params = [$studentId, $accountId];

        if ($examId !== null && $examId > 0) {
            $sql .= " AND (r.exam_id = ? OR r.exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ?))";
            $params[] = $examId;
            $params[] = $examId;
        }

        $sql .= " ORDER BY r.updated_at DESC LIMIT 1";

        $row = Database::fetchOne($sql, $params);
        if (!$row) return null;

        return [
            'id'             => (int)$row['id'],
            'student_id'     => (int)$row['student_id'],
            'exam_id'        => (int)$row['exam_id'],
            'exam_name'      => (string)($row['exam_name'] ?? ''),
            'risk_score'     => (int)$row['risk_score'],
            'risk_level'     => (string)$row['risk_level'],
            'model_used'     => (string)$row['model_used'],
            'report_markdown'=> (string)$row['report_markdown'],
            'created_at'     => (string)$row['created_at'],
            'updated_at'     => (string)$row['updated_at'],
            'cached'         => true,
        ];
    }

    /**
     * Generate an AI forensic academic report for a specific student.
     */
    public static function generateReport(int $accountId, int $teacherId, int $studentId, ?int $examId = null): array
    {
        self::ensureTable();

        // 1. Fetch Teacher Info
        $teacher = Database::fetchOne(
            "SELECT fullname, username FROM teachers WHERE moodle_teacher_id = ? AND (account_id = ? OR account_id = 0)",
            [$teacherId, $accountId]
        );
        $teacherName = $teacher['fullname'] ?? 'أستاذ المساق';

        // 2. Fetch Student Info
        $student = Database::fetchOne(
            "SELECT id, fullname, username, moodle_user_id FROM students WHERE (id = ? OR moodle_user_id = ?) AND (account_id = ? OR account_id = 0)",
            [$studentId, $studentId, $accountId]
        );
        if (!$student) {
            throw new \RuntimeException('الطالب غير موجود');
        }

        $actualStudentId = (int)$student['id'];
        $moodleUserId = (int)$student['moodle_user_id'];

        // 3. Determine Exam (use specified or latest attended)
        $examWhere = "(ss.student_id = ? OR ss.student_id = ?) AND (ss.account_id = ? OR ss.account_id = 0)";
        $examParams = [$actualStudentId, $moodleUserId, $accountId];

        if ($examId !== null && $examId > 0) {
            $examWhere .= " AND (ss.exam_id = ? OR ss.exam_id IN (SELECT id FROM exams WHERE moodle_quiz_id = ?))";
            $examParams[] = $examId;
            $examParams[] = $examId;
        }

        $sessionSummary = Database::fetchOne(
            "SELECT ss.*, e.name AS exam_name, e.duration_minutes, e.moodle_quiz_id,
                    c.name AS course_name
               FROM session_summaries ss
               JOIN exams e ON (e.id = ss.exam_id OR e.moodle_quiz_id = ss.exam_id)
               LEFT JOIN courses c ON (c.moodle_course_id = e.moodle_course_id AND (c.account_id = e.account_id OR c.account_id = 0))
              WHERE $examWhere
              ORDER BY ss.last_event_at DESC LIMIT 1",
            $examParams
        );

        if (!$sessionSummary) {
            // Fallback: check exams without session_summary
            $exam = Database::fetchOne(
                "SELECT e.*, c.name AS course_name FROM exams e
                 LEFT JOIN courses c ON c.moodle_course_id = e.moodle_course_id
                 WHERE (e.id = ? OR e.moodle_quiz_id = ?) AND (e.account_id = ? OR e.account_id = 0) LIMIT 1",
                [$examId ?: 1, $examId ?: 1, $accountId]
            );
            $sessionSummary = [
                'exam_id' => $exam['id'] ?? ($examId ?: 0),
                'exam_name' => $exam['name'] ?? 'امتحان عام',
                'course_name' => $exam['course_name'] ?? 'مساق عام',
                'duration_minutes' => $exam['duration_minutes'] ?? 60,
                'risk_score' => 0,
                'risk_level' => 'safe',
                'event_count' => 0,
                'tab_hidden_count' => 0,
                'paste_count' => 0,
                'copy_count' => 0,
                'devtools_count' => 0,
                'same_ip_student_count' => 0,
                'similarity_max_score' => 0,
                'ai_suspect_score' => 0,
                'first_event_at' => null,
                'last_event_at' => null,
            ];
        }

        $targetExamId = (int)$sessionSummary['exam_id'];
        $moodleQuizId = (int)($sessionSummary['moodle_quiz_id'] ?? $targetExamId);

        // Calculate session time spent
        $started = $sessionSummary['first_event_at'];
        $ended = $sessionSummary['last_event_at'];
        $spentSecs = ($started && $ended) ? max(0, strtotime($ended) - strtotime($started)) : 0;
        $spentMins = round($spentSecs / 60, 1);
        $scheduledMins = (int)($sessionSummary['duration_minutes'] ?? 0);
        if ($scheduledMins <= 0 && $spentMins > 0) {
            $scheduledMins = max(15, (int)(ceil($spentMins / 5) * 5));
        }
        if ($scheduledMins <= 0) {
            $scheduledMins = 30;
        }

        // 4. Fetch Student Answers for this exam
        $answers = Database::fetchAll(
            "SELECT ar.question_id, ar.question_type, ar.answer_text, ar.answer_length,
                    ar.word_count, ar.typing_duration_ms, ar.change_count,
                    ar.ai_score, ar.similarity_score, st_p.fullname AS partner_name
               FROM answer_records ar
               LEFT JOIN students st_p ON (st_p.id = ar.similarity_with_student_id OR st_p.moodle_user_id = ar.similarity_with_student_id)
              WHERE (ar.student_id = ? OR ar.student_id = ?)
                AND (ar.exam_id = ? OR ar.exam_id = ?)
              ORDER BY ar.id ASC LIMIT 50",
            [$actualStudentId, $moodleUserId, $targetExamId, $moodleQuizId]
        );

        // 5. Fetch Deep Clipboard Events (Copies and Pastes with actual text)
        $clipboardEvents = Database::fetchAll(
            "SELECT ev.event_type, ev.event_time, ev.payload
               FROM events ev
              WHERE (ev.moodle_user_id = ? OR ev.moodle_user_id = ?)
                AND (ev.moodle_quiz_id = ? OR ev.moodle_quiz_id = ?)
                AND ev.event_type IN ('copy', 'paste', 'cut')
              ORDER BY ev.event_time ASC LIMIT 60",
            [$actualStudentId, $moodleUserId, $moodleQuizId, $targetExamId]
        );

        $parsedClipboard = [];
        $totalCopies = 0;
        $totalPastes = 0;

        foreach ($clipboardEvents as $cev) {
            $p = json_decode($cev['payload'], true) ?: [];
            $meta = $p['metadata'] ?? [];
            $type = $cev['event_type'];
            $text = trim((string)($meta['pasted_text'] ?? ($meta['selection_text'] ?? ($meta['selected_text'] ?? ($p['text'] ?? '')))));
            $qNum = $meta['question_id'] ?? ($meta['question']['question_number'] ?? null);

            if ($type === 'copy') $totalCopies++;
            if ($type === 'paste') $totalPastes++;

            if ($text !== '') {
                $parsedClipboard[] = [
                    'type'        => $type,
                    'time'        => $cev['event_time'],
                    'question'    => $qNum ? "سؤال $qNum" : 'غير محدد',
                    'length'      => mb_strlen($text),
                    'text_sample' => em_truncate($text, 250),
                ];
            }
        }

        // 6. Device and Network Telemetry
        $deviceRow = Database::fetchOne(
            "SELECT user_agent, ip_address FROM events
              WHERE (moodle_user_id = ? OR moodle_user_id = ?) AND (moodle_quiz_id = ? OR moodle_quiz_id = ?)
              ORDER BY id DESC LIMIT 1",
            [$actualStudentId, $moodleUserId, $moodleQuizId, $targetExamId]
        );
        $userAgent = $deviceRow['user_agent'] ?? '';
        $deviceLabel = preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent) ? 'هاتف محمول / جهاز لوحي' : 'حاسوب مكتبي / محمول';

        // 7. Structure the context for the LLM
        $answersSummary = [];
        foreach ($answers as $idx => $a) {
            $sim = (int)($a['similarity_score'] ?? 0);
            $ai = (int)($a['ai_score'] ?? 0);
            $answersSummary[] = [
                'question_id'        => $a['question_id'],
                'type'               => $a['question_type'] ?? 'essay',
                'word_count'         => (int)$a['word_count'],
                'typing_duration_sec'=> round(((int)$a['typing_duration_ms']) / 1000, 1),
                'ai_suspect_pct'     => $ai,
                'similarity_pct'     => $sim,
                'matching_partner'   => $a['partner_name'] ?: 'لا يوجد',
                'answer_snippet'     => em_truncate((string)($a['answer_text'] ?? ''), 200),
            ];
        }

        $systemPrompt = <<<SYS
أنت "المستشار الجنائي الأكاديمي الذكي" (Academic Forensic AI Specialist) لمنصة SOAR لمراقبة الامتحانات الإلكترونية وكشف الغش في الجامعات.
مهمتك الأساسية هي كتابة تقرير تحليلي دقيق، فصيح، عادل، موثق بالأدلة، وموجه للمعلم أو أستاذ المساق مباشرة.
يقوم التقرير بتفكيك سلوك طالب محدد وتبرير نسبة الخطورة وتقييم النزاهة الذي وضعه النظام له بناءً على البيانات الرقمية الدقيقة المسجلة أثناء الامتحان.

قواعد وأسلوب الصياغة:
1. خاطب المعلم باسمه بلباقة واحترام (مثال: أهلاً بك أستاذ/دكتور [اسم المعلم]، أنا المساعد الذكي لمراقبة النزاهة الأكاديمية...).
2. فكك تصرفات الطالب بموضوعية ودقة مع تقديم تبريرات منطقية مقنعة مبنية على البيانات:
   - تحليل النسخ (Copy): ماذا نسخ الطالب؟ هل نسخ نص السؤال حرفياً؟ إذا كان ما نسخه نص السؤال، فهذا مؤشر قوي جداً على محاولة الاستعانة بمحركات بحث خارجية أو برامج ذكاء اصطناعي.
   - تحليل اللصق (Paste): كم عدد عمليات اللصق؟ هل تم لصق إجابات مقالية طويلة في ثوانٍ معدودة دون كتابة تدريجية طبيعية (Typing Biometrics)؟
   - تحليل زمن الامتحان وسرعة الإنجاز (Time & Velocity): قارن بدقة بين مدة الامتحان المحددة (المقررة) والوقت الفعلي الذي استغرقه الطالب. اذكر مدة الامتحان المحددة والوقت المستغرق صراحة في بطاقة الفحص وفي التحليل الزمني، وبيّن هل أنهى الطالب الامتحان بسرعة مريبة تفوق القدرة البشرية (Velocity Anomaly)، أم استغرق وقتاً كافياً للتفكير والإجابة.
   - مغادرة شاشة الامتحان (Tab Switches & DevTools): عدد مرات فتح نوافذ أخرى أو فحص الكود.
   - التطابق مع الزملاء (Similarity): هل تشابهت إجاباته مع إجابات طالب آخر؟ مع من وما نسبته؟
   - فحص الذكاء الاصطناعي (AI Generated Content): نسبة استخدام الذكاء الاصطناعي في إجاباته المقالية.
3. التنسيق: استخدم لغة Markdown احترافية وجذابة (عناوين واضحة H2 و H3، رموز تعبيرية معبرة 📊 🔍 ⏱️ ⚖️، قوائم نقطية وفقرات واضحة). اكتب التقرير كاملاً حتى آخر فقرة وتوصية دون أي بتر أو انقطاع. لتنسيق المؤشرات في الملخص التنفيذي، اعتمد قوائم نقطية منسقة بدقة (مثال: • **اسم الطالب**: ... | • **نسبة الخطورة**: ...) لضمان سلاسة وجمال القراءة.

هيكل التقرير المطلوب:
- **المقدمة والترحيب بالمعلم**: تحية طيبة باسم المعلم وإيجاز سريع لحالة الطالب.
- **📊 1. بطاقة الفحص والملخص التنفيذي**: ملخص رقمي سريع عبر قائمة نقطية شاملة (الاسم، المساق، الامتحان، نسبة الخطورة وتقييمها، مدة الحل الفعلية مقابل المحددة).
- **🔍 2. التبرير الجنائي والتقني لدرجة الشبهة المرصودة**:
  * تفكيك سجل الحافظة (ما تم نسخه ولصقه تحديداً من نصوص).
  * تحليل سرعة الإدخال والطباعة ومطابقتها مع زمن التفكير المنطقي.
  * مغادرة بيئة الامتحان وفقدان التركيز واستخدام أدوات المتصفح.
  * مؤشر التشابه والتواطؤ مع الزملاء.
  * مؤشر البصمة اللغوية للذكاء الاصطناعي والمؤشرات الدالة على النسخ من ChatGPT.
- **⏱️ 3. التسلسل الزمني للشبهات (Chronological Reconstruction)**: قصة مترابطة تسرد كيف تصرف الطالب خطوة بخطوة أثناء الامتحان.
- **⚖️ 4. التوصية والقرار الأكاديمي المقترح**: توجيه مهني وعادل للمعلم لكيفية التعامل مع الطالب وتجنب أي ظلم.
SYS;

        $userPayload = [
            'teacher' => [
                'name' => $teacherName,
            ],
            'student' => [
                'fullname' => $student['fullname'],
                'username' => $student['username'],
                'moodle_id' => $moodleUserId,
            ],
            'exam' => [
                'name' => $sessionSummary['exam_name'],
                'course' => $sessionSummary['course_name'],
                'scheduled_minutes' => $scheduledMins,
                'time_spent_minutes' => $spentMins,
                'total_questions' => count($answers),
            ],
            'behavioral_indicators' => [
                'overall_risk_score' => (int)$sessionSummary['risk_score'],
                'overall_risk_level' => (string)$sessionSummary['risk_level'],
                'tab_hidden_count'   => (int)$sessionSummary['tab_hidden_count'],
                'copy_count'         => (int)$sessionSummary['copy_count'],
                'paste_count'        => (int)$sessionSummary['paste_count'],
                'devtools_count'     => (int)$sessionSummary['devtools_count'],
                'same_ip_students'   => (int)$sessionSummary['same_ip_student_count'],
                'similarity_max_pct' => (int)$sessionSummary['similarity_max_score'],
                'ai_suspect_pct'     => (int)$sessionSummary['ai_suspect_score'],
                'device_type'        => $deviceLabel,
            ],
            'answers_details' => $answersSummary,
            'clipboard_log' => array_slice($parsedClipboard, 0, 25),
        ];

        $userPrompt = "يرجى دراسة بيانات الطالب التالية أثناء الامتحان وتحليلها بدقة، وتوليد التقرير الجنائي الأكاديمي الموجه للأستاذ كاملاً بجميع أقسامه الأربعة:\n\n" .
                      json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // 8. Call OpenRouter LLM
        $apiKey = self::getApiKey();
        $reportMarkdown = '';
        $modelUsed = '';

        foreach (self::MODELS as $model) {
            $reply = self::callOpenRouter($apiKey, $model, $systemPrompt, $userPrompt);
            if (!empty($reply)) {
                $reportMarkdown = $reply;
                $modelUsed = $model;
                break;
            }
        }

        if (empty($reportMarkdown)) {
            throw new \RuntimeException('تعذر توليد التقرير من خادم الذكاء الاصطناعي، يرجى المحاولة لاحقاً.');
        }

        // 9. Persist into Database
        $riskScore = (int)$sessionSummary['risk_score'];
        $riskLevel = (string)$sessionSummary['risk_level'];

        Database::execute(
            "DELETE FROM student_ai_reports 
              WHERE student_id = ? AND exam_id = ? AND (account_id = ? OR account_id = 0)",
            [$actualStudentId, $targetExamId, $accountId]
        );

        Database::execute(
            "INSERT INTO student_ai_reports
             (account_id, student_id, exam_id, teacher_id, risk_score, risk_level, model_used, report_markdown, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$accountId, $actualStudentId, $targetExamId, $teacherId, $riskScore, $riskLevel, $modelUsed, $reportMarkdown]
        );

        return [
            'ok'             => true,
            'student_id'     => $actualStudentId,
            'exam_id'        => $targetExamId,
            'exam_name'      => $sessionSummary['exam_name'],
            'risk_score'     => $riskScore,
            'risk_level'     => $riskLevel,
            'model_used'     => $modelUsed,
            'report_markdown'=> $reportMarkdown,
            'created_at'     => date('Y-m-d H:i:s'),
            'cached'         => false,
        ];
    }

    /** Call OpenRouter Chat Completions Endpoint */
    private static function callOpenRouter(string $apiKey, string $model, string $systemPrompt, string $userPrompt): ?string
    {
        $ch = curl_init(self::OPENROUTER_URL);
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.35,
            'max_tokens'  => 4096,
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 70);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://jadallahkhaled.com/exammonitor',
            'X-Title: SOAR Exam Integrity Auditor',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($res)) {
            $data = json_decode($res, true);
            $content = $data['choices'][0]['message']['content'] ?? null;
            if (!empty($content)) {
                return trim((string)$content);
            }
        }

        return null;
    }
}
