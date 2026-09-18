<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Support;

use LogicException;
use MySQLTuner\Database\Exception\QueryFailed;
use MySQLTuner\Database\QueryRunner;
use Throwable;

/**
 * An in-memory QueryRunner driven by SQL patterns.
 *
 * An unmatched query throws rather than returning an empty result. A silent [] would let a newly
 * added production query look like a server with nothing to report, and every test would still
 * pass - which is the class of bug this whole seam exists to catch.
 */
final class FakeQueryRunner implements QueryRunner
{
    /** @var list<array{pattern: string, rows: list<array<string, mixed>>|null, error: Throwable|null}> */
    private array $stubs = [];

    /** @var list<string> */
    private array $issued = [];

    public function __construct(
        private readonly bool $isLocal = true,
    ) {}

    /** @param list<array<string, mixed>> $rows */
    public function whenMatches(string $pattern, array $rows): self
    {
        $this->stubs[] = ['pattern' => $pattern, 'rows' => $rows, 'error' => null];

        return $this;
    }

    public function whenMatchesThrow(string $pattern, Throwable $error): self
    {
        $this->stubs[] = ['pattern' => $pattern, 'rows' => null, 'error' => $error];

        return $this;
    }

    /** @return list<string> */
    public function issuedQueries(): array
    {
        return $this->issued;
    }

    public function fetchAll(string $sql): array
    {
        $this->issued[] = $sql;

        foreach ($this->stubs as $stub) {
            if (preg_match($stub['pattern'], $sql) === 1) {
                if ($stub['error'] !== null) {
                    throw $stub['error'];
                }

                return $stub['rows'] ?? [];
            }
        }

        throw new LogicException("FakeQueryRunner: no stub matches SQL: {$sql}");
    }

    public function fetchOne(string $sql): ?array
    {
        $rows = $this->fetchAll($sql);

        return $rows[0] ?? null;
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
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function isLocal(): bool
    {
        return $this->isLocal;
    }

    public static function denied(string $message = 'Access denied'): QueryFailed
    {
        return new \MySQLTuner\Database\Exception\QueryDenied($message, sql: '', driverCode: 1142);
    }

    public static function missing(string $message = "Unknown column 'password'"): QueryFailed
    {
        return new \MySQLTuner\Database\Exception\ObjectMissing($message, sql: '', driverCode: 1054);
    }
}
