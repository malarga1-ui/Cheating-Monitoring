<?php
/**
 * Audit trail endpoints — account-scoped, guarded by requireAccountAdmin
 * (the university account holder or an admin staff member).
 */
final class AuditController
{
    /** GET /api/audit — recent activity for the current account. */
    public static function list(): void
    {
        Auth::requireAccountAdmin();
        $accountId = Auth::accountId();
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));

        try {
            $rows = Database::fetchAll(
                'SELECT id, actor_type, actor_id, actor_name, action,
                        target_type, target_id, details, created_at
                   FROM audit_log
                  WHERE account_id = ?
               ORDER BY id DESC
                  LIMIT ' . $limit,
                [$accountId]
            );
        } catch (\Throwable $e) {
            Response::ok([]);
            return;
        }

        Response::ok(array_map(function (array $r): array {
            $r['id'] = (int)$r['id'];
            $r['actor_id'] = $r['actor_id'] === null ? null : (int)$r['actor_id'];
            $r['target_id'] = $r['target_id'] === null ? null : (int)$r['target_id'];
            $r['details'] = $r['details'] === null ? null : json_decode($r['details'], true);
            return $r;
        }, $rows));
    }
}
