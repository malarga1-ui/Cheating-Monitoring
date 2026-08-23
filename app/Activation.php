<?php
/**
 * License / trial activation.
 *
 * The platform ships in three states:
 *   - unactivated : fresh install — admin must start a trial or enter a key
 *   - trial       : time-limited, tracked in DB, auto-expires
 *   - active      : unlocked with a valid license key
 *
 * License keys are HMAC-SHA256 over the site domain with a private secret
 * (config: license.secret). Generate one with:
 *   php scripts/generate_license.php https://your-domain.com
 */
final class Activation
{
    /** Keep in sync with the `activation` table enum. */
    private const STATUSES = ['unactivated', 'trial', 'active'];

    public static function trialDays(): int
    {
        $d = (int)em_config('license.trial_days', 30);
        return $d > 0 ? $d : 30;
    }

    /** Current activation state (auto-expires stale trials). */
    public static function status(): array
    {
        $row = Database::fetchOne('SELECT * FROM activation WHERE id = 1');
        if (!$row) {
            return self::makeResponse('unactivated', 0, null, '');
        }

        $status = (string)$row['status'];
        $trialEnds = !empty($row['trial_ends_at']) ? strtotime((string)$row['trial_ends_at']) : null;

        if ($status === 'trial' && $trialEnds !== null && $trialEnds < time()) {
            Database::execute(
                'UPDATE activation SET status = "unactivated", trial_started_at = NULL, trial_ends_at = NULL WHERE id = 1'
            );
            $status = 'unactivated';
            $trialEnds = null;
        }

        $remaining = ($status === 'trial' && $trialEnds !== null)
            ? (int)ceil(($trialEnds - time()) / 86400)
            : 0;

        return self::makeResponse($status, max(0, $remaining), $row['trial_ends_at'], (string)$row['license_key']);
    }

    public static function isActive(): bool
    {
        return self::status()['status'] === 'active';
    }

    /** Start (or extend?) a trial. Returns false if already licensed. */
    public static function startTrial(?int $days = null): bool
    {
        if (self::isActive()) {
            return false;
        }
        $days = $days ?? self::trialDays();
        Database::execute(
            'UPDATE activation
             SET status = "trial", trial_started_at = NOW(),
                 trial_ends_at = DATE_ADD(NOW(), INTERVAL ? DAY), license_key = ""
             WHERE id = 1',
            [$days]
        );
        return true;
    }

    /** Store a valid license key. Returns false if the key is invalid. */
    public static function activate(string $key): bool
    {
        if (!self::verifyKey($key)) {
            return false;
        }
        Database::execute(
            'UPDATE activation SET status = "active", license_key = ?, activated_at = NOW() WHERE id = 1',
            [$key]
        );
        return true;
    }

    /** Build a key for a domain (used by generate_license.php + verifyKey). */
    public static function generateKey(string $domain, ?string $secret = null): string
    {
        $secret = $secret ?? (string)em_config('license.secret', '');
        $raw = strtoupper(substr(hash_hmac('sha256', self::normalizeDomain($domain), $secret), 0, 20));
        return 'EM-' . implode('-', str_split($raw, 4));
    }

    /** Check a user-supplied key against this site's domain. */
    public static function verifyKey(string $key): bool
    {
        $base = (string)em_config('app.base_url', '');
        $domain = $base !== '' ? (string)(parse_url($base, PHP_URL_HOST) ?? $base) : 'localhost';

        $expected = str_replace('-', '', self::generateKey($domain));
        $given = str_replace('-', '', strtoupper(trim($key)));

        return $given !== '' && $expected !== '' && hash_equals($expected, $given);
    }

    /** Normalise a domain or full URL down to its host (lowercased). */
    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $host = parse_url($domain, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        return $domain;
    }

    private static function makeResponse(string $status, int $remaining, $trialEnds, string $key): array
    {
        return [
            'status'         => in_array($status, self::STATUSES, true) ? $status : 'unactivated',
            'activated'      => $status === 'active',
            'remaining_days' => $remaining,
            'trial_ends_at'  => $trialEnds,
            'license_key'    => $key,
        ];
    }
}
