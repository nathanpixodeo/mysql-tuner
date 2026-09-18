<?php

declare(strict_types=1);

namespace MySQLTuner\Collector;

use MySQLTuner\Database\Exception\QueryFailed;
use MySQLTuner\Database\QueryRunner;

final class MySQLCollector implements CollectorInterface
{
    /** @var array<string, string> metric => why it could not be answered */
    private array $skipped = [];

    public function __construct(
        private readonly QueryRunner $runner,
    ) {}

    public function collect(): array
    {
        $this->skipped = [];

        $status = $this->fetchVariables('SHOW /*!50000 GLOBAL */ STATUS');
        $variables = $this->fetchVariables('SHOW /*!50000 GLOBAL */ VARIABLES');

        // Status counters and configuration variables share one namespace here. They do not collide
        // in practice (counters are capitalised, variables are not), but make the winner explicit.
        $metrics = [...$status, ...$variables];

        $replica = $this->collectReplicaStatus();
        if ($replica !== null) {
            $metrics['slave_status'] = $replica;
        }

        $metrics = [...$metrics, ...$this->collectEngineMetrics()];

        // Always present, matching the other collectors, so callers never have to special-case
        // its absence.
        $metrics['checks_skipped'] = $this->skipped;

        return $metrics;
    }

    /** @return array<string, string> */
    private function fetchVariables(string $sql): array
    {
        $result = [];

        // Not guarded: without SHOW GLOBAL STATUS there is no report to produce, so a failure here
        // should reach the caller rather than yield a confidently empty analysis.
        foreach ($this->runner->fetchAll($sql) as $row) {
            $name = $row['Variable_name'] ?? $row['variable_name'] ?? '';
            $value = $row['Value'] ?? $row['value'] ?? '';

            if (is_string($name) && $name !== '') {
                $result[$name] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $result;
    }

    /**
     * Replication status, normalised across the naming change.
     *
     * MySQL 8.0.22 introduced SHOW REPLICA STATUS and 8.4 removed SHOW SLAVE STATUS entirely, while
     * MariaDB still only offers the older spelling. Try the modern statement first and fall back,
     * then normalise the columns so downstream code has one set of names to read.
     *
     * @return array<string, mixed>|null
     */
    private function collectReplicaStatus(): ?array
    {
        $lastFailure = null;

        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $sql) {
            try {
                $row = $this->runner->fetchOne($sql);
            } catch (QueryFailed $e) {
                $lastFailure = $e;

                continue;
            }

            // An empty result means the server is simply not a replica: not a failure.
            if ($row === null) {
                return null;
            }

            return $this->normaliseReplicaRow($row);
        }

        if ($lastFailure !== null) {
            $this->skipped['replication'] = 'could not read replica status: ' . $lastFailure->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseReplicaRow(array $row): array
    {
        $aliases = [
            'Slave_IO_Running' => 'Replica_IO_Running',
            'Slave_SQL_Running' => 'Replica_SQL_Running',
            'Seconds_Behind_Master' => 'Seconds_Behind_Source',
        ];

        foreach ($aliases as $legacy => $modern) {
            if (!array_key_exists($legacy, $row) && array_key_exists($modern, $row)) {
                $row[$legacy] = $row[$modern];
            }
        }

        return $row;
    }

    /** @return array<string, string> */
    private function collectEngineMetrics(): array
    {
        $engines = [];

        try {
            $rows = $this->runner->fetchAll('SELECT ENGINE, SUPPORT FROM information_schema.ENGINES');
        } catch (QueryFailed $e) {
            $this->skipped['engines'] = 'could not read information_schema.ENGINES: ' . $e->getMessage();

            return [];
        }

        foreach ($rows as $row) {
            $engine = $row['ENGINE'] ?? null;
            $support = $row['SUPPORT'] ?? null;

            if (is_string($engine) && is_string($support)) {
                $engines['engine_' . strtolower($engine)] = $support;
            }
        }

        return $engines;
    }
}
