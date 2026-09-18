<?php

declare(strict_types=1);

namespace MySQLTuner\Collector;

use InvalidArgumentException;
use MySQLTuner\Database\Exception\QueryFailed;
use MySQLTuner\Database\QueryRunner;

final class SchemaCollector implements CollectorInterface
{
    private const SYSTEM_SCHEMAS = ['mysql', 'performance_schema', 'information_schema', 'sys'];

    /** Engine names this collector is willing to interpolate into SQL. */
    private const KNOWN_ENGINES = ['InnoDB', 'MyISAM', 'MEMORY', 'ARCHIVE', 'CSV', 'NDB'];

    /** @var array<string, string> metric => why it could not be answered */
    private array $skipped = [];

    public function __construct(
        private readonly QueryRunner $runner,
    ) {}

    public function collect(): array
    {
        $this->skipped = [];

        $databases = $this->getNonSystemDatabases();

        if ($databases === []) {
            return $this->emptyResult($databases);
        }

        try {
            $dbList = implode(',', array_map(
                fn(string $db): string => $this->runner->quote($db),
                $databases,
            ));
        } catch (QueryFailed $e) {
            $this->skipped['schema'] = 'could not quote database names: ' . $e->getMessage();

            return $this->emptyResult($databases);
        }

        return [
            'total_tables' => $this->count('total_tables', "table_type = 'BASE TABLE'", $dbList),
            'innodb_tables' => $this->countEngineTables($dbList, 'InnoDB'),
            'myisam_tables' => $this->countEngineTables($dbList, 'MyISAM'),
            'fragmented_tables' => $this->count('fragmented_tables', "Data_free > 0 AND table_type = 'BASE TABLE'", $dbList),
            'total_index_size' => $this->sum('total_index_size', 'index_length', $dbList),
            'total_data_size' => $this->sum('total_data_size', 'data_length + index_length', $dbList),
            'databases' => $databases,
            'checks_skipped' => $this->skipped,
        ];
    }

    /**
     * @param list<string> $databases
     * @return array<string, mixed>
     */
    private function emptyResult(array $databases): array
    {
        return [
            'total_tables' => 0,
            'innodb_tables' => 0,
            'myisam_tables' => 0,
            'fragmented_tables' => 0,
            'total_index_size' => null,
            'total_data_size' => null,
            'databases' => $databases,
            'checks_skipped' => $this->skipped,
        ];
    }

    /** @return list<string> */
    private function getNonSystemDatabases(): array
    {
        $excluded = implode("', '", self::SYSTEM_SCHEMAS);

        try {
            $rows = $this->runner->fetchAll(
                "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('{$excluded}')",
            );
        } catch (QueryFailed $e) {
            $this->skipped['databases'] = 'could not list schemas: ' . $e->getMessage();

            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $name = $row['SCHEMA_NAME'] ?? $row['schema_name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function countEngineTables(string $dbList, string $engine): ?int
    {
        // The engine name is interpolated, so it may only ever be one of ours.
        if (!in_array($engine, self::KNOWN_ENGINES, true)) {
            throw new InvalidArgumentException("Unsupported storage engine: {$engine}");
        }

        return $this->count(
            strtolower($engine) . '_tables',
            "engine = '{$engine}' AND table_type = 'BASE TABLE'",
            $dbList,
        );
    }

    private function count(string $metric, string $where, string $dbList): ?int
    {
        try {
            return (int) $this->runner->fetchValue(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES"
                . " WHERE table_schema IN ({$dbList}) AND {$where}",
            );
        } catch (QueryFailed $e) {
            $this->skipped[$metric] = $e->getMessage();

            return null;
        }
    }

    private function sum(string $metric, string $expression, string $dbList): ?int
    {
        try {
            return (int) $this->runner->fetchValue(
                "SELECT COALESCE(SUM({$expression}), 0) AS total FROM information_schema.TABLES"
                . " WHERE table_schema IN ({$dbList})",
            );
        } catch (QueryFailed $e) {
            $this->skipped[$metric] = $e->getMessage();

            return null;
        }
    }
}
