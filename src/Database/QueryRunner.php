<?php

declare(strict_types=1);

namespace MySQLTuner\Database;

use MySQLTuner\Database\Exception\QueryFailed;

/**
 * The narrow database surface the collectors depend on.
 *
 * This exists so a failed probe can be classified rather than swallowed. Reporting "0 anonymous
 * users" when the query was actually refused is a security false negative, so every implementation
 * must translate driver errors into the typed exceptions in this namespace.
 */
interface QueryRunner
{
    /**
     * @return list<array<string, mixed>>
     * @throws QueryFailed
     */
    public function fetchAll(string $sql): array;

    /**
     * @return array<string, mixed>|null null when the result set is empty
     * @throws QueryFailed
     */
    public function fetchOne(string $sql): ?array;

    /**
     * First column of the first row, or null when the result set is empty.
     *
     * @throws QueryFailed
     */
    public function fetchValue(string $sql): mixed;

    /** @throws QueryFailed */
    public function quote(string $value): string;

    /**
     * Whether the server is on the same machine as this process.
     *
     * System metrics are read from the local /proc, so they only describe the database server when
     * the connection is local. Implementations return false when they cannot tell.
     */
    public function isLocal(): bool;
}
