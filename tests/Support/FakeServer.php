<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Support;

/**
 * Builds a FakeQueryRunner that answers every query the collectors issue.
 *
 * Defaults describe a healthy, settled MySQL 8 server so a test only has to state the one thing it
 * cares about.
 */
final class FakeServer
{
    /** @var array<string, string> */
    private array $status = [
        'Uptime' => '86400',
        'Innodb_buffer_pool_reads' => '100',
        'Innodb_buffer_pool_read_requests' => '100000',
        'Key_reads' => '1',
        'Key_read_requests' => '1000',
        'Threads_created' => '5',
        'Connections' => '1000',
        'Max_used_connections' => '10',
    ];

    /** @var array<string, string> */
    private array $variables = [
        'version' => '8.0.36',
        'innodb_buffer_pool_size' => '134217728',
        'max_connections' => '151',
    ];

    private bool $isLocal = true;

    /** @var list<string> */
    private array $databases = ['app'];

    private int $anonymousUsers = 0;
    private int $emptyPasswordUsers = 0;
    private int $wildcardHostUsers = 0;
    private int $rootEmptyPassword = 0;
    private bool $testDatabase = false;

    private bool $denyMysqlUser = false;
    private bool $dropPasswordColumn = true;

    public static function healthy(): self
    {
        return new self();
    }

    /** @param array<string, string> $status */
    public function withStatus(array $status): self
    {
        $this->status = [...$this->status, ...$status];

        return $this;
    }

    /** @param array<string, string> $variables */
    public function withVariables(array $variables): self
    {
        $this->variables = [...$this->variables, ...$variables];

        return $this;
    }

    public function remote(): self
    {
        $this->isLocal = false;

        return $this;
    }

    public function withAnonymousUsers(int $count): self
    {
        $this->anonymousUsers = $count;

        return $this;
    }

    public function withEmptyPasswordUsers(int $count): self
    {
        $this->emptyPasswordUsers = $count;

        return $this;
    }

    public function denyingMysqlUser(): self
    {
        $this->denyMysqlUser = true;

        return $this;
    }

    public function build(): FakeQueryRunner
    {
        $runner = new FakeQueryRunner($this->isLocal);

        // Anchored on the version-comment suffix so these do not also swallow SHOW REPLICA STATUS.
        $runner->whenMatches('#\*/ STATUS$#', $this->variableRows($this->status));
        $runner->whenMatches('#\*/ VARIABLES$#', $this->variableRows($this->variables));

        // No replica rows: an empty result is how a standalone server answers.
        $runner->whenMatches('/SHOW REPLICA STATUS/', []);
        $runner->whenMatches('/SHOW SLAVE STATUS/', []);

        $runner->whenMatches('/information_schema\.ENGINES/', [
            ['ENGINE' => 'InnoDB', 'SUPPORT' => 'DEFAULT'],
        ]);

        $runner->whenMatches(
            '/information_schema\.SCHEMATA WHERE SCHEMA_NAME NOT IN/',
            array_map(static fn(string $db): array => ['SCHEMA_NAME' => $db], $this->databases),
        );

        $runner->whenMatches('/COUNT\(\*\).*engine = \'InnoDB\'/s', [['cnt' => 10]]);
        $runner->whenMatches('/COUNT\(\*\).*engine = \'MyISAM\'/s', [['cnt' => 0]]);
        $runner->whenMatches('/Data_free > 0/', [['cnt' => 0]]);
        $runner->whenMatches('/COUNT\(\*\).*table_type = \'BASE TABLE\'/s', [['cnt' => 10]]);
        $runner->whenMatches('/SUM\(index_length\)/', [['total' => 1024]]);
        $runner->whenMatches('/SUM\(data_length \+ index_length\)/', [['total' => 20480]]);

        if ($this->denyMysqlUser) {
            $runner->whenMatchesThrow('/mysql\.user/', FakeQueryRunner::denied());
        } else {
            $runner->whenMatches('/mysql\.user WHERE user = \'\'/', [['cnt' => $this->anonymousUsers]]);
            $runner->whenMatches('/WHERE authentication_string = \'\'/', [['cnt' => $this->emptyPasswordUsers]]);

            if ($this->dropPasswordColumn) {
                // MySQL 8 has no mysql.user.password column.
                $runner->whenMatchesThrow('/WHERE password = \'\'/', FakeQueryRunner::missing());
            } else {
                $runner->whenMatches('/WHERE password = \'\'/', [['cnt' => 0]]);
            }

            $runner->whenMatches('/host = \'%\'/', [['cnt' => $this->wildcardHostUsers]]);
            $runner->whenMatches('/user = \'root\'/', [['cnt' => $this->rootEmptyPassword]]);
        }

        $runner->whenMatches(
            '/SCHEMA_NAME = \'test\'/',
            $this->testDatabase ? [['SCHEMA_NAME' => 'test']] : [],
        );

        return $runner;
    }

    /**
     * @param array<string, string> $pairs
     * @return list<array<string, mixed>>
     */
    private function variableRows(array $pairs): array
    {
        $rows = [];
        foreach ($pairs as $name => $value) {
            $rows[] = ['Variable_name' => $name, 'Value' => $value];
        }

        return $rows;
    }
}
