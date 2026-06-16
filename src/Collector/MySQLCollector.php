<?php

namespace MySQLTuner\Collector;

use PDO;

class MySQLCollector implements CollectorInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function collect(): array
    {
        $status = $this->fetch('SHOW /*!50000 GLOBAL */ STATUS');
        $variables = $this->fetch('SHOW /*!50000 GLOBAL */ VARIABLES');
        $slave = $this->tryFetch('SHOW SLAVE STATUS');

        $metrics = [...$status, ...$variables];

        if ($slave !== null && isset($slave[0])) {
            $metrics['slave_status'] = $slave[0];
        }

        $engineMetrics = $this->collectEngineMetrics();
        $metrics = [...$metrics, ...$engineMetrics];

        return $metrics;
    }

    /** @return array<string, string> */
    private function fetch(string $query): array
    {
        $stmt = $this->pdo->query($query);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $name = $row['Variable_name'] ?? $row['variable_name'] ?? '';
            $value = $row['Value'] ?? $row['value'] ?? '';
            $result[$name] = $value;
        }
        return $result;
    }

    /** @return list<array<string, string|null>>|null */
    private function tryFetch(string $query): ?array
    {
        try {
            $stmt = $this->pdo->query($query);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function collectEngineMetrics(): array
    {
        $engines = [];
        try {
            $stmt = $this->pdo->query('SELECT ENGINE, SUPPORT FROM information_schema.ENGINES');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $engines['engine_' . strtolower($row['ENGINE'])] = $row['SUPPORT'];
            }
        } catch (\PDOException) {
        }
        return $engines;
    }
}
