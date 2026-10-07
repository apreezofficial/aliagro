<?php

namespace App\Core;

use PDO;

/** PDO wrapper: one lazy connection, positional bindings only, nested transactions via savepoints. */
final class DB
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = config('database');
            if (($c['driver'] ?? 'mysql') !== 'mysql') {
                throw new \RuntimeException('Only the mysql driver is supported (DB_CONNECTION=mysql).');
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $c['host'], $c['port'], $c['database'], $c['charset']
            );
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    /** Drop the connection (tests / long-running scripts). */
    public static function disconnect(): void
    {
        self::$pdo   = null;
        self::$depth = 0;
    }

    public static function select(string $sql, array $bindings = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($bindings));
        return $stmt->fetchAll();
    }

    public static function first(string $sql, array $bindings = []): ?array
    {
        $rows = self::select($sql, $bindings);
        return $rows[0] ?? null;
    }

    /** First column of the first row. */
    public static function value(string $sql, array $bindings = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($bindings));
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Run INSERT/UPDATE/DELETE/DDL, return affected rows. */
    public static function statement(string $sql, array $bindings = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($bindings));
        return $stmt->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        self::statement("INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})", array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        return self::statement("UPDATE `{$table}` SET {$set} WHERE {$where}", [...array_values($data), ...$whereBindings]);
    }

    public static function delete(string $table, string $where, array $bindings = []): int
    {
        return self::statement("DELETE FROM `{$table}` WHERE {$where}", $bindings);
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run $callback in a transaction; commits on return, rolls back on any throwable.
     * Nested calls use savepoints.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();

        if (self::$depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp' . self::$depth);
        }
        self::$depth++;

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            self::$depth--;
            if (self::$depth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp' . self::$depth);
            }
            throw $e;
        }

        self::$depth--;
        if (self::$depth === 0) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT sp' . self::$depth);
        }
        return $result;
    }
}
