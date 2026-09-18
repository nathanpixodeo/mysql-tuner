<?php

declare(strict_types=1);

namespace MySQLTuner\Database;

use MySQLTuner\Database\Exception\ObjectMissing;
use MySQLTuner\Database\Exception\QueryDenied;
use MySQLTuner\Database\Exception\QueryFailed;
use PDO;
use PDOException;
use PDOStatement;

final class PdoQueryRunner implements QueryRunner
{
    /** Server error codes that mean "you are not allowed", not "there is nothing here". */
    private const DENIED_CODES = [1044, 1045, 1142, 1143, 1227, 1370];

    /** Server error codes that mean the table or column does not exist on this version. */
    private const MISSING_CODES = [1054, 1109, 1146];

    private ?bool $isLocal = null;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        // The collectors rely on exceptions to classify failures. In ERRMODE_SILENT, query()
        // returns false instead of throwing and every probe would fatal on a method call to bool.
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function fetchAll(string $sql): array
    {
        $stmt = $this->run($sql);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function fetchOne(string $sql): ?array
    {
        $row = $this->run($sql)->fetch(PDO::FETCH_ASSOC);

        // PDO returns false for an empty result set; null is the honest representation.
        return is_array($row) ? $row : null;
    }

    public function fetchValue(string $sql): mixed
    {
        $row = $this->fetchOne($sql);

        if ($row === null || $row === []) {
            return null;
        }

        return reset($row);
    }

    public function quote(string $value): string
    {
        $quoted = $this->pdo->quote($value);

        if ($quoted === false) {
            // Letting this through would build `IN (,'other')`, a syntax error at a distance.
            throw new QueryFailed(
                'The PDO driver refused to quote a value.',
                sql: '',
            );
        }

        return $quoted;
    }

    public function isLocal(): bool
    {
        if ($this->isLocal !== null) {
            return $this->isLocal;
        }

        try {
            $status = $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            $status = is_string($status) ? $status : null;
        } catch (PDOException) {
            // Some drivers do not expose this attribute; fall back to the safe answer.
            $status = null;
        }

        return $this->isLocal = ConnectionLocality::isLocal($status);
    }

    private function run(string $sql): PDOStatement
    {
        try {
            $stmt = $this->pdo->query($sql);
        } catch (PDOException $e) {
            throw $this->classify($e, $sql);
        }

        if (!$stmt instanceof PDOStatement) {
            throw new QueryFailed('The query returned no statement handle.', sql: $sql);
        }

        return $stmt;
    }

    private function classify(PDOException $e, string $sql): QueryFailed
    {
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        $message = $e->getMessage();

        if ($code !== null && in_array($code, self::DENIED_CODES, true)) {
            return new QueryDenied($message, sql: $sql, driverCode: $code, previous: $e);
        }

        if ($code !== null && in_array($code, self::MISSING_CODES, true)) {
            return new ObjectMissing($message, sql: $sql, driverCode: $code, previous: $e);
        }

        return new QueryFailed($message, sql: $sql, driverCode: $code, previous: $e);
    }
}
