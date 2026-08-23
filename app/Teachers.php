<?php
/**
 * Teacher records (auto-synced from Moodle role assignments). Used for the
 * teacher portal login and scoping.
 */
final class Teachers
{
    public static function findById(int $teacherId): ?array
    {
        return Database::fetchOne('SELECT * FROM teachers WHERE moodle_teacher_id = ?', [$teacherId]);
    }

    public static function findByAccountAndId(int $accountId, int $teacherId): ?array
    {
        return Database::fetchOne(
            'SELECT t.*, a.org_name
               FROM teachers t
               JOIN accounts a ON a.id = t.account_id
              WHERE t.account_id = ? AND t.moodle_teacher_id = ?',
            [$accountId, $teacherId]
        );
    }

    /**
     * Find a teacher within an account by username, falling back to the
     * Moodle user id. Returns null when unknown or login disabled.
     */
    public static function findLoginCandidate(int $accountId, ?int $moodleUserId, string $username): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        if ($moodleUserId !== null && $moodleUserId > 0) {
            $row = Database::fetchOne(
                'SELECT * FROM teachers WHERE account_id = ? AND moodle_teacher_id = ?',
                [$accountId, $moodleUserId]
            );
            if ($row !== null) {
                return (int)$row['login_enabled'] === 1 ? $row : null;
            }
        }
        $username = trim($username);
        if ($username === '') {
            return null;
        }
        $row = Database::fetchOne(
            'SELECT * FROM teachers WHERE account_id = ? AND username = ? ORDER BY moodle_teacher_id ASC LIMIT 1',
            [$accountId, $username]
        );
        if ($row === null || (int)$row['login_enabled'] !== 1) {
            return null;
        }
        return $row;
    }

    /** The teacher's course ids within an account (empty array = none). */
    public static function courseIds(int $accountId, int $teacherId): array
    {
        $rows = Database::fetchAll(
            'SELECT moodle_course_id FROM course_teachers WHERE account_id = ? AND moodle_teacher_id = ?',
            [$accountId, $teacherId]
        );
        return array_map(fn($r) => (int)$r['moodle_course_id'], $rows);
    }

    /**
     * Authenticate a teacher using platform-side password (not Moodle).
     * Returns the teacher row (without password_hash) on success, null on failure.
     */
    public static function authenticate(int $accountId, string $username, string $password): ?array
    {
        if ($accountId <= 0 || $username === '' || $password === '') {
            return null;
        }
        $row = Database::fetchOne(
            'SELECT * FROM teachers WHERE account_id = ? AND username = ? ORDER BY moodle_teacher_id ASC LIMIT 1',
            [$accountId, $username]
        );
        if ($row === null) {
            return null;
        }
        if ((int)($row['login_enabled'] ?? 1) !== 1) {
            return null;
        }
        $hash = (string)($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }
        Database::execute(
            'UPDATE teachers SET last_seen_at = NOW() WHERE account_id = ? AND moodle_teacher_id = ?',
            [$accountId, (int)$row['moodle_teacher_id']]
        );
        unset($row['password_hash']);
        return $row;
    }

    /**
     * Change the teacher's password and mark first login as done.
     */
    public static function changePassword(int $accountId, int $teacherId, string $newPassword): bool
    {
        if (strlen($newPassword) < 6) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::execute(
            'UPDATE teachers SET password_hash = ?, is_first_login = 0
             WHERE account_id = ? AND moodle_teacher_id = ?',
            [$hash, $accountId, $teacherId]
        );
        return true;
    }

    /**
     * Check if the teacher must change their password on next login.
     */
    public static function mustChangePassword(array $teacher): bool
    {
        return (int)($teacher['is_first_login'] ?? 1) === 1;
    }

    /**
     * Generate a default password for a new teacher.
     * Pattern: {username}@915
     */
    public static function defaultPassword(string $username): string
    {
        return trim($username) . '@915';
    }

    /**
     * Set the default password hash for a teacher (used during sync).
     * Scoped by account_id to prevent cross-account password overwrites.
     */
    public static function setDefaultPassword(string $username, int $moodleTeacherId, int $accountId = 0): void
    {
        if (trim($username) === '') {
            $username = 'user' . $moodleTeacherId;
        }
        $default = self::defaultPassword($username);
        $hash = password_hash($default, PASSWORD_DEFAULT);
        if ($accountId > 0) {
            Database::execute(
                'UPDATE teachers SET password_hash = ? WHERE account_id = ? AND moodle_teacher_id = ? AND (password_hash = "" OR password_hash IS NULL)',
                [$hash, $accountId, $moodleTeacherId]
            );
        } else {
            Database::execute(
                'UPDATE teachers SET password_hash = ? WHERE moodle_teacher_id = ? AND (password_hash = "" OR password_hash IS NULL)',
                [$hash, $moodleTeacherId]
            );
        }
    }
}
