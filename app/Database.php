<?php
/**
 * Database abstraction over PDO (MySQL).
 */
final class Database
{
    private static ?PDO $pdo = null;

    private function __construct() {}

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $cfg = em_config('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['database'],
                $cfg['charset']
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], $options);
            // The application is UTC-based (config app.timezone = 'UTC', PHP date/gmdate
            // assume UTC, and event_time/audit_log are written as UTC). Normalize the
            // MySQL session so NOW()/CURRENT_TIMESTAMP are also UTC, otherwise timestamp
            // comparisons in rate-limiting, the live dashboard and trial expiry drift by
            // the server's local offset. Offset syntax is safe even without tz tables.
            try {
                self::$pdo->exec("SET time_zone = '+00:00'");
            } catch (Throwable $e) {
                // Ignore — some very old hosts reject session vars, but the per-query
                // rate-limit/dashboard fixes already keep things internally consistent.
            }
        }
        return self::$pdo;
    }

    /** Run a statement and return affected row count. */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Fetch all rows. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Fetch one row or null. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch a single scalar value. */
    public static function scalar(string $sql, array $params = [])
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }

    /** Wrap a callback in a transaction. */
    public static function transaction(callable $fn)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ensure a column exists on a table without failing or depending on MySQL version syntax.
     */
    public static function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $db = self::connection();
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch()) {
                $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (\Throwable $e) {
            // Ignore if table doesn't exist or permissions issue
        }
    }
}
