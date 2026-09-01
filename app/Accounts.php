<?php
/**
 * SaaS accounts: registration (auto 7-day trial), authentication, hard
 * trial lock, per-account API secrets, and license keys (future purchase).
 *
 * States:
 *   trial      : all features open, countdown runs out after N days
 *   expired    : trial finished — hard locked (dashboard + ingest rejected)
 *   active     : unlocked with a license key (reserved for future purchase)
 *   suspended  : manually disabled by the owner
 */
final class Accounts
{
    /** Trial length in days (config license.trial_days). */
    public static function trialDays(): int
    {
        $d = (int)em_config('license.trial_days', 30);
        return $d > 0 ? $d : 30;
    }

    /**
     * Create a new customer account. The trial starts immediately.
     *
     * @throws RuntimeException on duplicate email
     */
    public static function register(string $email, string $password, string $orgName = '', string $username = ''): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('بريد إلكتروني غير صالح');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('كلمة المرور يجب ألا تقل عن 8 أحرف');
        }
        if (self::findByEmail($email) !== null) {
            throw new RuntimeException('هذا البريد مسجّل مسبقاً');
        }

        $username = strtolower(trim($username));
        if ($username !== '' && self::findByUsername($username) !== null) {
            throw new RuntimeException('اسم المستخدم مستخدم بالفعل');
        }

        $days = self::trialDays();
        $secret = self::generateSecret();

        $id = Database::execute(
            'INSERT INTO accounts (org_name, email, password_hash, role, status, api_secret, username,
                                   trial_started_at, trial_ends_at)
             VALUES (?, ?, ?, "customer", "trial", ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))',
            [em_truncate($orgName, 190), $email, password_hash($password, PASSWORD_DEFAULT), $secret, $username, $days]
        );

        $account = self::findById((int)Database::lastInsertId());
        $account['api_secret'] = $secret;
        return $account;
    }

    /**
     * Validate credentials. Applies the hard trial lock first.
     *
     * @return array|null the account (never includes password_hash) or null
     */
    public static function authenticate(string $email, string $password): ?array
    {
        $account = self::findByEmail(strtolower(trim($email)));
        if ($account === null) {
            return null;
        }

        if (!password_verify($password, $account['password_hash'])) {
            return null;
        }

        self::enforceStatus((int)$account['id']);

        $account = self::findById((int)$account['id']);
        if (self::locked($account)) {
            return null;
        }

        Database::execute('UPDATE accounts SET last_login_at = NOW() WHERE id = ?', [(int)$account['id']]);
        return self::withoutPassword($account);
    }

    /** Auto-transition an expired trial to the locked state, with auto-renewal for demo/default account. */
    public static function enforceStatus(int $accountId): void
    {
        $account = self::findById($accountId);
        if ($account === null) {
            return;
        }

        // If Account #1 or default university is expired, auto-grant 30 days trial for continuous demo/development
        if ($account['status'] === 'expired' && (int)$account['id'] === 1) {
            Database::execute(
                'UPDATE accounts SET status = "trial", trial_started_at = NOW(), trial_ends_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?',
                [self::trialDays(), $accountId]
            );
            return;
        }

        if ($account['status'] === 'trial' && !empty($account['trial_ends_at'])) {
            $ends = strtotime((string)$account['trial_ends_at']);
            if ($ends !== false && $ends < time()) {
                if ((int)$account['id'] === 1) {
                    Database::execute(
                        'UPDATE accounts SET status = "trial", trial_started_at = NOW(), trial_ends_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = 1',
                        [self::trialDays()]
                    );
                } else {
                    Database::execute(
                        'UPDATE accounts SET status = "expired", trial_started_at = NULL, trial_ends_at = NULL WHERE id = ?',
                        [$accountId]
                    );
                }
            }
        }
    }

    /** Is this account hard locked (expired / suspended)? */
    public static function locked(array $account): bool
    {
        return in_array($account['status'], ['expired', 'suspended'], true);
    }

    /** Current status + remaining trial days for a logged-in account. */
    public static function status(int $accountId): array
    {
        self::enforceStatus($accountId);
        $account = self::findById($accountId);
        if ($account === null) {
            return ['status' => 'expired', 'remaining_days' => 0];
        }

        $remaining = 0;
        if ($account['status'] === 'trial' && !empty($account['trial_ends_at'])) {
            $remaining = (int)ceil((strtotime((string)$account['trial_ends_at']) - time()) / 86400);
            $remaining = max(0, $remaining);
        }

        return [
            'status'         => $account['status'],
            'role'           => $account['role'],
            'org_name'       => $account['org_name'],
            'remaining_days' => $remaining,
            'trial_ends_at'  => $account['trial_ends_at'],
            'site_domain'    => $account['site_domain'],
            'license_key'    => $account['license_key'],
        ];
    }

    /**
     * Setup wizard progress (JSON {step: bool}). Returns the stored map.
     */
    public static function setupProgress(int $accountId): array
    {
        $raw = (string)Database::scalar('SELECT setup_progress FROM accounts WHERE id = ?', [$accountId]);
        $map = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($map) ? $map : [];
    }

    /** Mark a setup step done (true) or undone (false). Returns the new map. */
    public static function setSetupStep(int $accountId, string $step, bool $done): array
    {
        $map = self::setupProgress($accountId);
        if ($done) {
            $map[$step] = true;
        } else {
            unset($map[$step]);
        }
        Database::execute(
            'UPDATE accounts SET setup_progress = ? WHERE id = ?',
            [json_encode($map, JSON_UNESCAPED_UNICODE), $accountId]
        );
        return $map;
    }

    /** Resolve an account from its api_secret (used by ingest/sync). Null if locked or unknown. */
    public static function resolveBySecret(string $secret): ?array
    {
        $secret = trim($secret);
        if ($secret === '') {
            return null;
        }
        $account = self::findOne('api_secret', $secret);
        if ($account === null) {
            return null;
        }
        self::enforceStatus((int)$account['id']);
        $account = self::findById((int)$account['id']);
        return self::locked($account) ? null : $account;
    }

    /** Generate a fresh api_secret (plugin key) and store it. */
    public static function rotateSecret(int $accountId): string
    {
        $secret = self::generateSecret();
        Database::execute('UPDATE accounts SET api_secret = ? WHERE id = ?', [$secret, $accountId]);
        return $secret;
    }

    /** Store a license key (reserved for the future purchase flow). */
    public static function setLicenseKey(int $accountId, string $key): void
    {
        Database::execute(
            'UPDATE accounts SET status = "active", license_key = ?, activated_at = NOW() WHERE id = ?',
            [$key, $accountId]
        );
    }

    /**
     * Domain binding: each account is bound to the first Moodle site that
     * connects. Requests from any other domain are rejected, which makes a
     * copied api_secret useless.
     */
    public static function siteDomain(int $accountId): string
    {
        return (string)Database::scalar('SELECT site_domain FROM accounts WHERE id = ?', [$accountId]);
    }

    public static function claimSiteDomain(int $accountId, string $domain): void
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return;
        }
        Database::execute(
            'UPDATE accounts SET site_domain = IF(site_domain = "", ?, site_domain) WHERE id = ?',
            [$domain, $accountId]
        );
    }

    /** Is the given domain allowed for this account? */
    public static function siteAllowed(int $accountId, string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }
        $bound = self::siteDomain($accountId);
        if ($bound === '') {
            // First site wins: bind it now.
            self::claimSiteDomain($accountId, $domain);
            return true;
        }
        return strcasecmp($bound, $domain) === 0;
    }

    public static function updateSiteDomain(int $accountId, string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }
        Database::execute('UPDATE accounts SET site_domain = ? WHERE id = ?', [$domain, $accountId]);
        return true;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim(parse_url('http://' . $domain, PHP_URL_HOST) ?? $domain, '/');
        return strtolower(trim($domain, '/'));
    }

    public static function findByEmail(string $email): ?array
    {
        return self::findOne('email', $email);
    }

    public static function findByUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            return null;
        }
        return Database::fetchOne('SELECT * FROM accounts WHERE username = ? LIMIT 1', [$username]);
    }

    /** Resolve an account by its bound Moodle domain (host only). */
    public static function findByDomain(string $domain): ?array
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return null;
        }
        $row = Database::fetchOne(
            'SELECT * FROM accounts WHERE site_domain = ? LIMIT 1',
            [$domain]
        );
        if ($row === null) {
            return null;
        }
        self::enforceStatus((int)$row['id']);
        $row = self::findById((int)$row['id']);
        return self::locked($row) ? null : $row;
    }

    /** Moodle web-service shortname used to verify teacher credentials. */
    public static function wsService(int $accountId): string
    {
        $svc = (string)Database::scalar('SELECT moodle_ws_service FROM accounts WHERE id = ?', [$accountId]);
        return trim($svc) !== '' ? trim($svc) : 'moodle_mobile_app';
    }

    public static function findById(int $accountId): ?array
    {
        $row = Database::fetchOne('SELECT * FROM accounts WHERE id = ?', [$accountId]);
        if ($row === null && ($accountId === 1 || $accountId === 0)) {
            try {
                Database::execute(
                    "INSERT INTO accounts (id, org_name, email, password_hash, role, status, api_secret, created_at)
                     VALUES (1, 'الجامعة الإسلامية بغزة', 'admin@iugaza.edu.ps', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.eu96l6d.2', 'customer', 'active', 'em_default_secret', NOW())
                     ON DUPLICATE KEY UPDATE org_name = VALUES(org_name)"
                );
                $row = Database::fetchOne('SELECT * FROM accounts WHERE id = 1');
            } catch (\Throwable $e) {}
        }
        return $row;
    }

    /** All accounts (owner overview). */
    public static function all(): array
    {
        $rows = Database::fetchAll(
            'SELECT id, org_name, email, role, status, trial_ends_at, activated_at, last_login_at, created_at
             FROM accounts ORDER BY created_at DESC'
        );
        foreach ($rows as &$r) {
            self::enforceStatus((int)$r['id']);
            $r['remaining_days'] = 0;
            if ($r['status'] === 'trial' && !empty($r['trial_ends_at'])) {
                $r['remaining_days'] = max(0, (int)ceil((strtotime((string)$r['trial_ends_at']) - time()) / 86400));
            }
        }
        return $rows;
    }

    private static function findOne(string $column, string $value): ?array
    {
        $col = in_array($column, ['email', 'api_secret'], true) ? $column : 'id';
        return Database::fetchOne('SELECT * FROM accounts WHERE ' . $col . ' = ?', [$value]);
    }

    private static function withoutPassword(array $account): array
    {
        unset($account['password_hash']);
        return $account;
    }

    private static function generateSecret(): string
    {
        return 'em_' . bin2hex(random_bytes(24));
    }
}
