<?php
// ============================================================
// db.php — MariaDB PDO Connection Wrapper
// ============================================================

require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $pdo = null;

    public static function connect(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ];

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('[AvionDash DB] Connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Check server logs.');
        }

        return self::$pdo;
    }

    /**
     * Execute a parameterised query, return all rows as associative arrays.
     */
    public static function query(string $sql, array $params = []): array {
        try {
            $pdo  = self::connect();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[AvionDash DB] Query error: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw new RuntimeException('Query execution failed.');
        }
    }

    /**
     * Return a single scalar value from the first column of the first row.
     */
    public static function scalar(string $sql, array $params = []): mixed {
        $rows = self::query($sql, $params);
        if (empty($rows)) return null;
        return reset($rows[0]);
    }

    /**
     * Check whether a SQL string is read-only (SELECT only).
     * Used by the Query Runner to block write statements.
     */
    public static function isReadOnly(string $sql): bool {
        $norm = strtoupper(trim(preg_replace('/\s+/', ' ', $sql)));

        if (!preg_match('/^(SELECT|WITH)\s/', $norm)) {
            return false;
        }

        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE',
            'ALTER', 'CREATE', 'REPLACE', 'LOAD', 'CALL',
            'EXEC', 'EXECUTE', 'GRANT', 'REVOKE',
        ];

        foreach ($forbidden as $kw) {
            if (str_contains($norm, $kw)) {
                return false;
            }
        }
        return true;
    }

    public static function close(): void {
        self::$pdo = null;
    }
}
