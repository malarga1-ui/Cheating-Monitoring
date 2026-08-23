<?php
/**
 * Audit: lightweight accountability trail for account-level security actions
 * (staff management, API key rotation, staff sign-ins). Logging must never
 * break the primary operation — failures are silently ignored.
 */
final class Audit
{
    /**
     * Insert an audit entry for the current session's account.
     *
     * @param string      $action      snake_case action id (e.g. staff.create)
     * @param string|null $targetType  e.g. 'staff' | 'account'
     * @param int|null    $targetId
     * @param array       $details     JSON-able detail map
     * @param array|null  $actor       optional [actor_type, actor_id, actor_name]
     *                                 override (used before a session exists).
     * @param int|null    $accountId   explicit tenant override (used when no
     *                                 authenticated session exists yet).
     */
    public static function log(string $action, ?string $targetType = null, ?int $targetId = null, array $details = [], ?array $actor = null, ?int $accountId = null): void
    {
        Auth::start();
        if ($accountId === null) {
            $accountId = Auth::accountId();
        }
        if ($accountId <= 0) {
            return; // platform-owner / system sessions have no tenant scope
        }

        if ($actor === null) {
            $actor = self::actorFromSession();
        }

        try {
            Database::execute(
                'INSERT INTO audit_log
                    (account_id, actor_type, actor_id, actor_name, action,
                     target_type, target_id, details, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3))',
                [
                    $accountId,
                    (string)($actor[0] ?? 'account'),
                    isset($actor[1]) ? (int)$actor[1] : null,
                    em_truncate((string)($actor[2] ?? ''), 250),
                    $action,
                    $targetType,
                    $targetId,
                    json_encode($details, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            // Never let audit logging break the primary request.
        }
    }

    private static function actorFromSession(): array
    {
        if (Auth::isStaff()) {
            $name = trim(($_SESSION['staff_name'] ?? '') . ' (' . ($_SESSION['staff_username'] ?? '') . ')');
            return ['staff', Auth::staffId(), $name];
        }
        $name = (string)($_SESSION['org_name'] ?? '');
        $email = (string)($_SESSION['email'] ?? '');
        return ['account', null, $name . ($email !== '' ? " ($email)" : '')];
    }
}
