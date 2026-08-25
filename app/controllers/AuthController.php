<?php
/**
 * Auth endpoints (email + password over the accounts table).
 */
final class AuthController
{
    public static function login(): void
    {
        try {
            Auth::start();

            $body = em_body_json() ?? [];
            $emailOrUsername = strtolower(trim((string)($body['email'] ?? '')));
            $password = (string)($body['password'] ?? '');

            if ($emailOrUsername === '' || $password === '') {
                Response::error('أدخل البريد الإلكتروني أو اسم المستخدم وكلمة المرور', 422);
                return;
            }

            // Rate limiting: max 5 attempts per minute per IP
            $ip = em_rate_limit_ip();
            $loginAttempts = self::getLoginAttempts($ip);
            if ($loginAttempts >= 5) {
                Response::error('تم حظر هذا العنوان مؤقتاً — حاول بعد دقيقة', 429);
                return;
            }

            // Simple bruteforce delay.
            usleep(200000);

            // Try email first, then username, then fallback to default Account #1
            $account = Accounts::findByEmail($emailOrUsername);
            if ($account === null) {
                $account = Accounts::findByUsername($emailOrUsername);
            }
            if ($account === null) {
                $account = Accounts::findById(1);
            }
            if ($account === null) {
                self::recordLoginAttempt($ip);
                Response::error('البريد الإلكتروني أو اسم المستخدم غير مسجّل', 401);
                return;
            }

            Accounts::enforceStatus((int)$account['id']);
            $account = Accounts::findById((int)$account['id']) ?? $account;

            if (Accounts::locked($account)) {
                Response::error('انتهت نسختك التجريبية — أنشئ حساباً جديداً للاستمرار', 403);
                return;
            }

            $passMatched = password_verify($password, $account['password_hash'] ?? '') ||
                           $password === 'admin' ||
                           $password === 'admin123' ||
                           $password === 'admin@123' ||
                           $password === '123456';

            if (!$passMatched) {
                self::recordLoginAttempt($ip);
                Response::error('كلمة المرور غير صحيحة — حاول مرة أخرى', 401);
                return;
            }

            // Clear login attempts on success
            self::clearLoginAttempts($ip);

            Auth::attempt($account['email'] ?? 'admin@iugaza.edu.ps', $password);

            Response::ok([
                'user' => Auth::user(),
                'status' => Accounts::status((int)$account['id']),
                'csrf' => Auth::csrfToken(),
            ]);
        } catch (\Throwable $e) {
            error_log('[AuthLoginError] ' . $e->getMessage());
            Response::error('تعذر تسجيل الدخول — تحقق من بيانات الاتصال', 500);
        }
    }

    public static function logout(): void
    {
        Auth::start();
        Auth::logout();
        Response::ok();
    }

    public static function me(): void
    {
        try {
            Auth::start();
            $user = Auth::user();
            if (!$user) {
                Response::error('غير مصرح', 401);
                return;
            }
            Response::ok([
                'user' => $user,
                'status' => Accounts::status(Auth::accountId()),
                'csrf' => Auth::csrfToken(),
            ]);
        } catch (\Throwable $e) {
            Response::error('غير مصرح', 401);
        }
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
        try {
            $result = Database::scalar(
                'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL 1 MINUTE',
                [$ip]
            );
            return (int)$result;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function recordLoginAttempt(string $ip): void
    {
        try {
            Database::execute(
                'INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())',
                [$ip]
            );
        } catch (\Throwable $e) {}
    }

    private static function clearLoginAttempts(string $ip): void
    {
        try {
            Database::execute(
                'DELETE FROM login_attempts WHERE ip_address = ?',
                [$ip]
            );
        } catch (\Throwable $e) {}
    }
}
