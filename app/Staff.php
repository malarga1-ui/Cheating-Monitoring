<?php
/**
 * Staff members (users of one university account):
 *   role = 'admin'       -> full access within the account
 *   role = 'supervisor'  -> limited to the courses in course_access
 *
 * Staff never use the accounts table: the account holder (customer login)
 * creates them and assigns courses. Passwords are hashed per staff member.
 */
final class Staff
{
    public static function findById(int $accountId, int $staffId): ?array
    {
        return Database::fetchOne(
            'SELECT id, account_id, username, fullname, email, role, is_active, last_login_at, created_at
               FROM users
              WHERE id = ? AND account_id = ?',
            [$staffId, $accountId]
        );
    }

    public static function findByUsername(int $accountId, string $username): ?array
    {
        $username = trim($username);
        return Database::fetchOne(
            'SELECT * FROM users
              WHERE (account_id = ? OR account_id = 0)
                AND (username = ? OR LOWER(username) = LOWER(?) OR email = ? OR LOWER(email) = LOWER(?))
              ORDER BY (account_id = ?) DESC
              LIMIT 1',
            [$accountId, $username, $username, $username, $username, $accountId]
        );
    }

    /** Verify a staff login. Returns the sanitised row or null. */
    public static function authenticate(int $accountId, string $username, string $password): ?array
    {
        $user = self::findByUsername($accountId, trim($username));
        if ($user === null || (int)$user['is_active'] !== 1) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int)$user['id']]);
        unset($user['password_hash']);
        return $user;
    }

    /** All staff of an account (newest last). */
    public static function list(int $accountId): array
    {
        return Database::fetchAll(
            'SELECT u.id, u.username, u.fullname, u.email, u.role, u.is_active, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM course_access ca
                      WHERE ca.user_id = u.id) AS courses_count
               FROM users u
              WHERE u.account_id = ?
              ORDER BY u.created_at ASC',
            [$accountId]
        );
    }

    /** @throws RuntimeException on validation failure / duplicates */
    public static function create(int $accountId, array $data): array
    {
        $username = trim((string)($data['username'] ?? ''));
        $fullname = em_truncate(trim((string)($data['fullname'] ?? '')), 190);
        $email    = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $role     = (($data['role'] ?? 'supervisor') === 'admin') ? 'admin' : 'supervisor';

        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
            throw new RuntimeException('اسم المستخدم مطلوب (3-64 حرفاً: أحرف وأرقام و_ . -)');
        }
        if ($fullname === '') {
            throw new RuntimeException('الاسم الكامل مطلوب');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('بريد إلكتروني غير صالح');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('كلمة المرور يجب ألا تقل عن 8 أحرف');
        }
        if (self::findByUsername($accountId, $username) !== null) {
            throw new RuntimeException('اسم المستخدم مستخدم مسبقاً في هذه الجامعة');
        }

        Database::execute(
            'INSERT INTO users (account_id, username, fullname, email, password_hash, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$accountId, $username, $fullname, $email, password_hash($password, PASSWORD_DEFAULT), $role]
        );

        return self::findById($accountId, (int)Database::lastInsertId()) ?? [];
    }

    /** @throws RuntimeException on validation failure / missing staff */
    public static function update(int $accountId, int $staffId, array $data): array
    {
        if (self::findById($accountId, $staffId) === null) {
            throw new RuntimeException('الموظف غير موجود', 404);
        }

        $fields = [];
        $params = [];

        if (isset($data['fullname'])) {
            $fullname = em_truncate(trim((string)$data['fullname']), 190);
            if ($fullname === '') {
                throw new RuntimeException('الاسم الكامل مطلوب');
            }
            $fields[] = 'fullname = ?';
            $params[] = $fullname;
        }

        if (isset($data['email'])) {
            $email = strtolower(trim((string)$data['email']));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('بريد إلكتروني غير صالح');
            }
            $fields[] = 'email = ?';
            $params[] = $email;
        }

        if (isset($data['role'])) {
            $fields[] = 'role = ?';
            $params[] = ($data['role'] === 'admin') ? 'admin' : 'supervisor';
        }

        if (!empty($data['password'])) {
            if (strlen((string)$data['password']) < 8) {
                throw new RuntimeException('كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف');
            }
            $fields[] = 'password_hash = ?';
            $params[] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }

        if ($fields !== []) {
            $params[] = $accountId;
            $params[] = $staffId;
            Database::execute(
                'UPDATE users SET ' . implode(', ', $fields) . ' WHERE account_id = ? AND id = ?',
                $params
            );
        }

        return self::findById($accountId, $staffId) ?? [];
    }

    public static function setActive(int $accountId, int $staffId, bool $active): void
    {
        Database::execute(
            'UPDATE users SET is_active = ? WHERE account_id = ? AND id = ?',
            [$active ? 1 : 0, $accountId, $staffId]
        );
    }

    public static function remove(int $accountId, int $staffId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM course_access WHERE account_id = ? AND user_id = ?')
                ->execute([$accountId, $staffId]);
            $pdo->prepare('DELETE FROM users WHERE account_id = ? AND id = ?')
                ->execute([$accountId, $staffId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Course ids granted to a supervisor (empty = no courses). */
    public static function courseIds(int $accountId, int $staffId): array
    {
        $rows = Database::fetchAll(
            'SELECT moodle_course_id FROM course_access WHERE account_id = ? AND user_id = ?',
            [$accountId, $staffId]
        );
        return array_map(fn($r) => (int)$r['moodle_course_id'], $rows);
    }

    /** Replace the full set of granted courses for a supervisor. */
    public static function setCourses(int $accountId, int $staffId, array $courseIds): void
    {
        $courseIds = array_values(array_unique(array_filter(array_map('intval', $courseIds))));

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM course_access WHERE account_id = ? AND user_id = ?')
                ->execute([$accountId, $staffId]);
            $stmt = $pdo->prepare(
                'INSERT INTO course_access (account_id, user_id, moodle_course_id) VALUES (?, ?, ?)'
            );
            foreach ($courseIds as $cid) {
                $stmt->execute([$accountId, $staffId, $cid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
