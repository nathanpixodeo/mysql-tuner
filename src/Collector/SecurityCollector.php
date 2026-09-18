<?php

declare(strict_types=1);

namespace MySQLTuner\Collector;

use MySQLTuner\Database\Exception\ObjectMissing;
use MySQLTuner\Database\Exception\QueryDenied;
use MySQLTuner\Database\Exception\QueryFailed;
use MySQLTuner\Database\QueryRunner;

/**
 * Security probes against mysql.user and information_schema.
 *
 * Every probe returns null when it could not run. Callers must render that as "not checked", never
 * as "nothing found" - the reason for each skip is collected in `checks_skipped`.
 */
final class SecurityCollector implements CollectorInterface
{
    /** @var array<string, string> metric => why it could not be answered */
    private array $skipped = [];

    public function __construct(
        private readonly QueryRunner $runner,
    ) {}

    public function collect(): array
    {
        $this->skipped = [];

        return [
            'anonymous_users' => $this->countAnonymousUsers(),
            'empty_password_users' => $this->countEmptyPasswordUsers(),
            'users_without_host' => $this->countUsersWithoutHost(),
            'test_database_exists' => $this->testDatabaseExists(),
            'have_root_empty_password' => $this->rootHasEmptyPassword(),
            'checks_skipped' => $this->skipped,
        ];
    }

    private function countAnonymousUsers(): ?int
    {
        return $this->count(
            'anonymous_users',
            "SELECT COUNT(*) AS cnt FROM mysql.user WHERE user = ''",
        );
    }

    /**
     * Users authenticating with no credential.
     *
     * The column holding the credential changed name across versions: `password` was dropped in
     * MySQL 5.7.6 and `authentication_string` does not exist before 5.7. Querying both in one
     * statement - as this collector used to - throws "unknown column" on every modern server, so
     * the check silently never ran. Each column is probed on its own and a missing one is skipped.
     */
    private function countEmptyPasswordUsers(): ?int
    {
        $total = null;

        foreach (['authentication_string', 'password'] as $column) {
            try {
                $value = $this->runner->fetchValue(
                    "SELECT COUNT(*) AS cnt FROM mysql.user WHERE {$column} = ''",
                );
            } catch (ObjectMissing) {
                // Expected: this server version does not have that column.
                continue;
            } catch (QueryFailed $e) {
                $this->skip('empty_password_users', $e);

                return null;
            }

            $total = ($total ?? 0) + (int) $value;
        }

        if ($total === null) {
            $this->skipped['empty_password_users'] =
                'neither mysql.user.authentication_string nor mysql.user.password exists on this server';
        }

        return $total;
    }

    private function countUsersWithoutHost(): ?int
    {
        return $this->count(
            'users_without_host',
            "SELECT COUNT(*) AS cnt FROM mysql.user WHERE host = '%'",
        );
    }

    private function testDatabaseExists(): ?bool
    {
        try {
            $row = $this->runner->fetchOne(
                "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'test'",
            );
        } catch (QueryFailed $e) {
            $this->skip('test_database_exists', $e);

            return null;
        }

        return $row !== null;
    }

    private function rootHasEmptyPassword(): ?bool
    {
        try {
            $value = $this->runner->fetchValue(
                "SELECT COUNT(*) AS cnt FROM mysql.user"
                . " WHERE user = 'root' AND (authentication_string = '' OR authentication_string IS NULL)",
            );
        } catch (QueryFailed $e) {
            $this->skip('have_root_empty_password', $e);

            return null;
        }

        return (int) $value > 0;
    }

    private function count(string $metric, string $sql): ?int
    {
        try {
            return (int) $this->runner->fetchValue($sql);
        } catch (QueryFailed $e) {
            $this->skip($metric, $e);

            return null;
        }
    }

    private function skip(string $metric, QueryFailed $e): void
    {
        $this->skipped[$metric] = match (true) {
            $e instanceof QueryDenied => 'insufficient privileges (GRANT SELECT ON mysql.user is required)',
            $e instanceof ObjectMissing => 'the table or column does not exist on this server version',
            default => 'the query failed: ' . $e->getMessage(),
        };
    }
}
