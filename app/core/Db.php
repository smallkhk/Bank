<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

final class Db
{
    private static ?PDO $pdo = null;
    /** @var callable[] work to run once the current transaction commits */
    private static array $afterCommit = [];

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = config('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn ($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        return self::query("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
    }

    /**
     * Run $fn inside a database transaction. Nested calls join the outer transaction.
     * Any exception rolls back everything — partial financial operations are impossible.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::$afterCommit = []; // rolled back: nothing it promised may happen
            throw $e;
        }
        $queued = self::$afterCommit;
        self::$afterCommit = [];
        foreach ($queued as $cb) {
            try {
                $cb();
            } catch (Throwable $e) {
                ErrorHandler::log($e); // side effects (email/SMS) must never undo a committed operation
            }
        }
        return $result;
    }

    /** Run $cb after the current transaction commits (immediately if none is open). */
    public static function afterCommit(callable $cb): void
    {
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            self::$afterCommit[] = $cb;
            return;
        }
        $cb();
    }
}
