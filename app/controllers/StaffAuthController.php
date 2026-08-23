<?php
/**
 * Staff authentication:
 *   POST /api/auth/staff-login  -> admin / supervisor login for a university.
 *
 * A staff member never creates an account: the university account holder
 * creates them from the dashboard. They log in with their own username +
 * password (users table), tied to the account they belong to.
 */
final class StaffAuthController
{
    /** POST /api/auth/staff-login */
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

        $staff = Staff::authenticate($accountId, $username, $password);
        if ($staff === null) {
            self::recordAttempt();
            Audit::log('auth.staff.login_failed', 'staff', null, ['username' => $username], ['staff', null, $username], $accountId);
            Response::error('اسم المستخدم أو كلمة المرور غير صحيحة، أو الحساب موقوف', 401);
        }

        $staff['org_name'] = $account['org_name'];
        Auth::attemptStaff($accountId, $staff);

        Audit::log('auth.staff.login', 'staff', (int)$staff['id'], ['username' => $username]);

        Response::ok([
            'user'   => Auth::user(),
            'status' => Accounts::status($accountId),
            'csrf'   => Auth::csrfToken(),
        ]);
    }

    /** Basic per-IP throttling for the public staff-login endpoint. */
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
}
