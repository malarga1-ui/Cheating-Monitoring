<?php
/**
 * Teacher authentication:
 *   GET  /api/public/sites             -> list of universities (public)
 *   POST /api/auth/teacher-login       -> Moodle username/password verification
 *   POST /api/auth/teacher-token-login -> one-time signed token from the plugin
 *
 * A teacher never creates an account: identity is the synced `teachers` row
 * inside the account of the university they belong to, and access is strictly
 * limited to the courses/exams they are assigned to.
 */
final class TeacherAuthController
{
    /** Public list of registered universities (for the "pick your university" step). */
    public static function sites(): void
    {
        $rows = Database::fetchAll(
            "SELECT id, org_name, site_domain
               FROM accounts
              WHERE role IN ('customer','owner')
                AND status IN ('trial','active')
              ORDER BY org_name ASC"
        );
        Response::ok(array_map(function ($r) {
            return [
                'id'          => (int)$r['id'],
                'org_name'    => $r['org_name'],
                'site_domain' => $r['site_domain'],
            ];
        }, $rows));
    }

    /** POST /api/auth/teacher-login — verify the platform password (not Moodle). */
    public static function login(): void
    {
        Auth::start();

        $body = em_body_json() ?? [];
        $accountId = (int)($body['account_id'] ?? 0);
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($accountId <= 0 || $username === '' || $password === '') {
            Response::error('اختر الجامعة وأدخل اسم المستخدم وكلمة المرور', 422);
        }

        self::rateLimit();

        $account = Accounts::findById($accountId);
        if ($account === null) {
            Response::error('الجامعة غير موجودة', 404);
        }
        Accounts::enforceStatus((int)$account['id']);
        $account = Accounts::findById((int)$account['id']);
        if (Accounts::locked($account)) {
            Response::error('حساب هذه الجامعة غير نشط حالياً', 403);
        }

        // Authenticate against the platform password (not Moodle).
        $teacher = Teachers::authenticate((int)$account['id'], $username, $password);
        if ($teacher === null) {
            self::recordAttempt();
            Response::error('اسم المستخدم أو كلمة المرور غير صحيحة', 401);
        }

        Auth::attemptTeacher((int)$account['id'], $teacher);

        // Check if the teacher must change their password.
        $mustChange = Teachers::mustChangePassword($teacher);

        Response::ok([
            'user'            => Auth::user(),
            'status'          => Accounts::status((int)$account['id']),
            'csrf'            => Auth::csrfToken(),
            'must_change_password' => $mustChange,
        ]);
    }

    /** POST /api/auth/teacher-token-login — one-time token minted by the plugin. */
    public static function tokenLogin(): void
    {
        Auth::start();

        $body = em_body_json() ?? [];
        $token = trim((string)($body['token'] ?? ''));
        if ($token === '') {
            Response::error('رمز الدخول مطلوب', 422);
        }

        self::rateLimit();

        $payload = self::verifyToken($token);
        if ($payload === null) {
            Response::error('رمز الدخول غير صالح أو منتهي الصلاحية — أعد إنشاءه من صفحة المودل', 401);
        }

        $account = Accounts::findByDomain((string)($payload['site'] ?? ''));
        if ($account === null) {
            Response::error('الموقع غير مرتبط بحساب نشط', 403);
        }

        $teacher = Teachers::findLoginCandidate((int)$account['id'], (int)($payload['tid'] ?? 0), '');
        if ($teacher === null) {
            Response::error('هذا الحساب غير مرتبط بدور مدرّس في منصتنا — تواصل مع إدارة جامعتك', 403);
        }

        Auth::attemptTeacher((int)$account['id'], $teacher);
        Database::execute('UPDATE teachers SET last_seen_at = NOW() WHERE account_id = ? AND moodle_teacher_id = ?', [(int)$account['id'], (int)$teacher['moodle_teacher_id']]);

        Response::ok([
            'user'   => Auth::user(),
            'status' => Accounts::status((int)$account['id']),
            'csrf'   => Auth::csrfToken(),
        ]);
    }

    /**
     * Ask Moodle to exchange the credentials for a token, then fetch the
     * site info to confirm the user id.
     *
     * @return array|null  ['userid'=>int, 'username'=>string] or null when the
     *                     site is unreachable, false when credentials fail.
     */
    private static function verifyMoodleCredentials(int $accountId, string $domain, string $username, string $password): array|bool|null
    {
        $scheme = (str_starts_with($domain, 'localhost') || filter_var($domain, FILTER_VALIDATE_IP))
            ? 'http' : 'https';
        $base = $scheme . '://' . $domain;

        $service = Accounts::wsService($accountId);

        $resp = self::httpJson($base . '/login/token.php', [
            'username' => $username,
            'password' => $password,
            'service'  => $service,
        ]);
        if ($resp === null) {
            return null;
        }
        if (empty($resp['token'])) {
            return false;
        }

        $info = self::httpJson($base . '/webservice/rest/server.php?' . http_build_query([
            'wstoken'              => (string)$resp['token'],
            'wsfunction'           => 'core_webservice_get_site_info',
            'moodlewsrestformat'   => 'json',
        ]));
        if ($info === null || empty($info['userid'])) {
            return false;
        }
        return [
            'userid'   => (int)$info['userid'],
            'username' => (string)($info['username'] ?? $username),
        ];
    }

    /** @return array|null decoded payload, or null when invalid/expired. */
    private static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $raw = base64_decode($parts[0], true);
        if ($raw === false) {
            return null;
        }
        $account = null;
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['site']) || empty($payload['tid'])) {
            return null;
        }
        $account = Accounts::findByDomain((string)$payload['site']);
        if ($account === null) {
            return null;
        }
        $expected = hash_hmac('sha256', $raw, (string)$account['api_secret']);
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        if (!isset($payload['exp']) || (int)$payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    /** @return array|null decoded JSON, or null on any transport failure. */
    private static function httpJson(string $url, array $params = []): ?array
    {
        $ch = curl_init($url . ($params !== [] ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ExamMonitor/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        if ($body === false || $err !== 0) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** Basic per-IP throttling for the public teacher-login endpoints. */
    private static function rateLimit(): void
    {
        $ip = em_rate_limit_ip();
        $count = (int)Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL 1 MINUTE',
            [$ip]
        );
        if ($count >= 10) {
            Response::error('محاولات كثيرة — حاول بعد دقيقة', 429);
        }
    }

    private static function recordAttempt(): void
    {
        Database::execute(
            'INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())',
            [em_client_ip()]
        );
    }

    /**
     * POST /api/auth/teacher-change-password
     * Force or voluntary password change for a logged-in teacher.
     */
    public static function changePassword(): void
    {
        Auth::requireTeacher();
        Auth::guardStateChangingRequest();

        $body = em_body_json() ?? [];
        $newPassword = (string)($body['new_password'] ?? '');
        $confirmPassword = (string)($body['confirm_password'] ?? '');

        if ($newPassword === '' || $confirmPassword === '') {
            Response::error('كلمة المرور الجديدة وتأكيدها مطلوبان', 422);
        }
        if ($newPassword !== $confirmPassword) {
            Response::error('كلمتا المرور غير متطابقتين', 422);
        }
        if (strlen($newPassword) < 6) {
            Response::error('كلمة المرور يجب ألا تقل عن 6 أحرف', 422);
        }

        $accountId = Auth::accountId();
        $teacherId = Auth::teacherId();

        // Don't allow setting the same default password as the new one.
        $teacher = Teachers::findById($teacherId);
        $teacherUsername = $teacher['username'] ?? ('user' . $teacherId);
        $defaultPw = Teachers::defaultPassword($teacherUsername);
        if ($newPassword === $defaultPw) {
            Response::error('لا يمكنك استخدام كلمة المرور الافتراضية — اختر كلمة مرور جديدة', 422);
        }

        $ok = Teachers::changePassword($accountId, $teacherId, $newPassword);
        if (!$ok) {
            Response::error('فشل تغيير كلمة المرور', 500);
        }

        Response::ok(['ok' => true, 'message' => 'تم تغيير كلمة المرور بنجاح']);
    }
}
