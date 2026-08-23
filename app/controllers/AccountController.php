<?php
/**
 * SaaS account endpoints: registration (auto trial), status, api_secret
 * management, the future activation flow, and the owner overview.
 */
final class AccountController
{
    public static function register(): void
    {
        Auth::start();

        // Rate limiting: max 3 registrations per hour per IP
        $ip = em_rate_limit_ip();
        $recent = (int)Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL 1 HOUR',
            [$ip]
        );
        if ($recent >= 10) {
            Response::error('تم حظر هذا العنوان مؤقتاً — حاول لاحقاً', 429);
        }

        $body = em_body_json() ?? [];
        $email = (string)($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');
        $orgName = em_truncate((string)($body['org_name'] ?? ''), 190);
        $username = em_truncate((string)($body['username'] ?? ''), 190);

        try {
            $account = Accounts::register($email, $password, $orgName, $username);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }

        // Auto login so the user lands directly in their dashboard.
        Auth::attempt($account['email'], $password);

        Response::ok([
            'user' => Auth::user(),
            'status' => Accounts::status((int)$account['id']),
            'api_secret' => $account['api_secret'],
            'csrf' => Auth::csrfToken(),
        ]);
    }

    public static function me(): void
    {
        Auth::requireLogin();
        $id = Auth::accountId();
        $account = Accounts::findById($id);
        if ($account === null) {
            Response::error('الحساب غير موجود', 404);
        }

        // Supervisors must never see the API secret / license key.
        $secret = Auth::isSupervisor() ? '' : $account['api_secret'];

        Response::ok([
            'user' => Auth::user(),
            'status' => Accounts::status($id),
            'api_secret' => $secret,
            'trial_days' => Accounts::trialDays(),
        ]);
    }

    public static function rotateSecret(): void
    {
        Auth::requireAccountAdmin();
        Auth::guardStateChangingRequest();
        $secret = Accounts::rotateSecret(Auth::accountId());
        Audit::log('account.secret.rotate', 'account', Auth::accountId());
        Response::ok(['api_secret' => $secret]);
    }

    /**
     * Future purchase activation. Purchase is intentionally not enabled yet.
     */
    public static function activate(): void
    {
        Response::error('خيار الشراء سيتوفر قريباً — تابع استخدام النسخة التجريبية', 501);
    }

    public static function setSiteDomain(): void
    {
        Auth::requireLogin();
        Auth::guardStateChangingRequest();
        $accountId = Auth::accountId();
        $body = em_body_json() ?? [];
        $url = (string)($body['site_domain'] ?? '');
        if ($url === '') {
            Response::error('أدخل رابط الموقع', 422);
        }
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://#i', '', $url);
        $url = rtrim($url, '/');
        Accounts::updateSiteDomain($accountId, $url);
        Audit::log('account.domain.set', 'account', $accountId, ['site_domain' => $url]);
        Response::ok(['site_domain' => $url]);
    }

    /** Owner overview: list all accounts. */
    public static function listAll(): void
    {
        Auth::requireOwner();
        Response::ok(['accounts' => Accounts::all()]);
    }
}
