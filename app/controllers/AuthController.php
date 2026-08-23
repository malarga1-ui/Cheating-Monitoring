<?php
/**
 * Auth endpoints (email + password over the accounts table).
 */
final class AuthController
{
    public static function login(): void
    {
        Auth::start();

        $body = em_body_json() ?? [];
        $emailOrUsername = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');

        if ($emailOrUsername === '' || $password === '') {
            Response::error('أدخل البريد الإلكتروني أو اسم المستخدم وكلمة المرور', 422);
        }

        // Rate limiting: max 5 attempts per minute per IP
        $ip = em_rate_limit_ip();
        $loginAttempts = self::getLoginAttempts($ip);
        if ($loginAttempts >= 5) {
            Response::error('تم حظر هذا العنوان مؤقتاً — حاول بعد دقيقة', 429);
        }

        // Simple bruteforce delay.
        usleep(200000);

        // Try email first, then username
        $account = Accounts::findByEmail($emailOrUsername);
        if ($account === null) {
            $account = Accounts::findByUsername($emailOrUsername);
        }
        if ($account === null) {
            self::recordLoginAttempt($ip);
            Response::error('البريد الإلكتروني أو اسم المستخدم غير مسجّل', 401);
        }

        Accounts::enforceStatus((int)$account['id']);
        $account = Accounts::findById((int)$account['id']);

        if (Accounts::locked($account)) {
            Response::error('انتهت نسختك التجريبية — أنشئ حساباً جديداً للاستمرار', 403);
        }

        if (!password_verify($password, $account['password_hash'])) {
            self::recordLoginAttempt($ip);
            Response::error('كلمة المرور غير صحيحة — حاول مرة أخرى', 401);
        }

        // Clear login attempts on success
        self::clearLoginAttempts($ip);

        Auth::attempt($account['email'], $password);

        Response::ok([
            'user' => Auth::user(),
            'status' => Accounts::status((int)$account['id']),
            'csrf' => Auth::csrfToken(),
        ]);
    }

    public static function logout(): void
    {
        Auth::start();
        Auth::logout();
        Response::ok();
    }

    public static function me(): void
    {
        Auth::start();
        $user = Auth::user();
        if (!$user) {
            Response::error('غير مصرح', 401);
        }
        Response::ok([
            'user' => $user,
            'status' => Accounts::status(Auth::accountId()),
            'csrf' => Auth::csrfToken(),
        ]);
    }

    public static function changePassword(): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();

        if (Auth::isTeacher()) {
            Response::error('غيّر كلمة مرورك من داخل المودل — الحساب يتبع المودل', 403);
        }
        if (Auth::isStaff()) {
            Response::error('غيّر كلمة مرورك من صفحة الموظفين — يديرها مدير الجامعة', 403);
        }

        $body = em_body_json() ?? [];
        $current = (string)($body['current_password'] ?? '');
        $new = (string)($body['new_password'] ?? '');

        if (strlen($new) < 8) {
            Response::error('كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف', 422);
        }

        $account = Accounts::findById(Auth::accountId());
        if ($account === null || !password_verify($current, $account['password_hash'])) {
            Response::error('كلمة المرور الحالية غير صحيحة', 401);
        }

        Database::execute(
            'UPDATE accounts SET password_hash = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), Auth::accountId()]
        );
        Response::ok(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }

    private static function getLoginAttempts(string $ip): int
    {
        $result = Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL 1 MINUTE',
            [$ip]
        );
        return (int)$result;
    }

    private static function recordLoginAttempt(string $ip): void
    {
        Database::execute(
            'INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())',
            [$ip]
        );
    }

    private static function clearLoginAttempts(string $ip): void
    {
        Database::execute(
            'DELETE FROM login_attempts WHERE ip_address = ?',
            [$ip]
        );
    }
}
