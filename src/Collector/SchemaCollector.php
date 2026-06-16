<?php

namespace MySQLTuner\Collector;

use PDO;

class SchemaCollector implements CollectorInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function collect(): array
    {
        $databases = $this->getNonSystemDatabases();
        $dbList = implode("','", array_map([$this->pdo, 'quote'], $databases));

        return [
            'total_tables' => $this->countTotalTables($dbList),
            'innodb_tables' => $this->countEngineTables($dbList, 'InnoDB'),
            'myisam_tables' => $this->countEngineTables($dbList, 'MyISAM'),
            'fragmented_tables' => $this->countFragmentedTables($dbList),
            'total_index_size' => $this->getIndexSize($dbList),
            'total_data_size' => $this->getDataSize($dbList),
            'databases' => $databases,
        ];
    }

    /** @return array<int, string> */
    private function getNonSystemDatabases(): array
    {
        $stmt = $this->pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql', 'performance_schema', 'information_schema', 'sys')");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function countTotalTables(string $dbList): int
    {
        if (empty($dbList)) return 0;
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema IN ('{$dbList}') AND table_type = 'BASE TABLE'");
        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    private function countEngineTables(string $dbList, string $engine): int
    {
        if (empty($dbList)) return 0;
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema IN ('{$dbList}') AND engine = '{$engine}' AND table_type = 'BASE TABLE'");
        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    private function countFragmentedTables(string $dbList): int
    {
        if (empty($dbList)) return 0;
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema IN ('{$dbList}') AND Data_free > 0 AND table_type = 'BASE TABLE'");
        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    private function getIndexSize(string $dbList): ?int
    {
        if (empty($dbList)) return null;
        $stmt = $this->pdo->query("SELECT COALESCE(SUM(index_length), 0) FROM information_schema.TABLES WHERE table_schema IN ('{$dbList}')");
        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    private function getDataSize(string $dbList): ?int
    {
        if (empty($dbList)) return null;
        $stmt = $this->pdo->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema IN ('{$dbList}')");
        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }
}
