<?php

namespace MySQLTuner;

use MySQLTuner\Calculator\BufferCalculator;
use MySQLTuner\Calculator\HitRateCalculator;
use MySQLTuner\Collector\CollectorInterface;
use MySQLTuner\Collector\MySQLCollector;
use MySQLTuner\Collector\SchemaCollector;
use MySQLTuner\Collector\SecurityCollector;
use MySQLTuner\Collector\SystemCollector;
use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;
use MySQLTuner\Report\Report;
use MySQLTuner\Report\ConsoleFormatter;
use MySQLTuner\Rules\RuleSet;
use PDO;

class Analyzer
{
    private readonly MySQLCollector $mysqlCollector;
    private readonly SchemaCollector $schemaCollector;
    private readonly SecurityCollector $securityCollector;
    private readonly SystemCollector $systemCollector;
    private readonly RuleSet $ruleSet;

    public function __construct(
        private readonly PDO $pdo,
        ?RuleSet $ruleSet = null,
    ) {
        $this->mysqlCollector = new MySQLCollector($pdo);
        $this->schemaCollector = new SchemaCollector($pdo);
        $this->securityCollector = new SecurityCollector($pdo);
        $this->systemCollector = new SystemCollector();
        $this->ruleSet = $ruleSet ?? new RuleSet(__DIR__ . '/../rules');
    }

    public function analyze(): Report
    {
        $mysql = $this->mysqlCollector->collect();
        $schema = $this->schemaCollector->collect();
        $security = $this->securityCollector->collect();
        $system = $this->systemCollector->collect();

        $version = $mysql['version'] ?? 'unknown';
        $uptime = (int) ($mysql['Uptime'] ?? 0);

        $this->ruleSet->loadForVersion($version);

        $recommendations = [];

        $recommendations = array_merge(
            $recommendations,
            $this->applyFileRules($mysql, $this->ruleSet),
        );

        $recommendations = array_merge(
            $recommendations,
            $this->securityRecommendations($security),
        );

        $recommendations = array_merge(
            $recommendations,
            $this->hitRateRecommendations($mysql, $uptime),
        );

        $recommendations = array_merge(
            $recommendations,
            $this->memoryRecommendations($mysql, $system),
        );

        $recommendations = array_merge(
            $recommendations,
            $this->schemaRecommendations($schema, $system),
        );

        $recommendations = array_merge(
            $recommendations,
            $this->replicationRecommendations($mysql),
        );

        $score = $this->calculateScore($recommendations);

        return new Report(
            recommendations: $recommendations,
            version: $version,
            uptime: $uptime,
            score: $score,
            systemMetrics: $system,
        );
    }

    /** @param array<string, mixed> $metrics @return list<Recommendation> */
    private function applyFileRules(array $metrics, RuleSet $ruleSet): array
    {
        $recommendations = [];

        foreach ($ruleSet->getRules() as $rule) {
            if (!array_key_exists($rule->key, $metrics)) {
                continue;
            }

            $current = $metrics[$rule->key];

            if ($rule->uptimeMin !== null) {
                continue;
            }

            if (!$rule->evaluate($current)) {
                continue;
            }

            $currentStr = is_scalar($current) ? (string) $current : json_encode($current);
            $group = $this->findGroupForKey($ruleSet, $rule->key);

            $recommendations[] = new Recommendation(
                metric: $rule->key,
                summary: $rule->summary,
                severity: Severity::from($rule->severity),
                currentValue: $currentStr,
                suggestion: $rule->suggestion,
                group: $group,
            );
        }

        return $recommendations;
    }

    /** @param array<string, mixed> $mysql @return list<Recommendation> */
    private function hitRateRecommendations(array $mysql, int $uptime): array
    {
        $recs = [];

        if ($uptime < 3600) {
            $recs[] = Recommendation::info(
                'uptime',
                'Server uptime is less than 1 hour. Some recommendations may not be accurate.',
                "Uptime: " . ConsoleFormatter::formatUptime($uptime),
                detail: 'Wait at least 24 hours of uptime for meaningful recommendations.',
            );
        }

        $poolReads = (int) ($mysql['Innodb_buffer_pool_reads'] ?? 0);
        $poolReadRequests = (int) ($mysql['Innodb_buffer_pool_read_requests'] ?? 0);
        if ($poolReadRequests > 0) {
            $hitRate = HitRateCalculator::innodbBufferPoolHitRate($poolReads, $poolReadRequests);
            if ($hitRate !== null) {
                $recs[] = new Recommendation(
                    metric: 'Innodb_buffer_pool_reads',
                    summary: "InnoDB buffer pool hit rate: {$hitRate}%",
                    severity: $hitRate < 95 ? Severity::CRITICAL : ($hitRate < 99 ? Severity::WARNING : Severity::OK),
                    currentValue: "{$hitRate}%",
                    suggestion: $hitRate < 99 ? 'Increase innodb_buffer_pool_size' : null,
                    group: 'innodb',
                );
            }
        }

        $keyReads = (int) ($mysql['Key_reads'] ?? 0);
        $keyReadRequests = (int) ($mysql['Key_read_requests'] ?? 0);
        if ($keyReadRequests > 0) {
            $hitRate = HitRateCalculator::keyBufferHitRate($keyReads, $keyReadRequests);
            if ($hitRate !== null) {
                $recs[] = new Recommendation(
                    metric: 'Key_reads',
                    summary: "MyISAM key buffer hit rate: {$hitRate}%",
                    severity: $hitRate < 95 ? Severity::WARNING : Severity::OK,
                    currentValue: "{$hitRate}%",
                    suggestion: $hitRate < 95 ? 'Increase key_buffer_size' : null,
                    group: 'myisam',
                );
            }
        }

        $threadsCreated = (int) ($mysql['Threads_created'] ?? 0);
        $connections = (int) ($mysql['Connections'] ?? 0);
        if ($connections > 0) {
            $hitRate = HitRateCalculator::threadCacheHitRate($threadsCreated, $connections);
            if ($hitRate !== null) {
                $recs[] = new Recommendation(
                    metric: 'Threads_created',
                    summary: "Thread cache hit rate: {$hitRate}%",
                    severity: $hitRate < 90 ? Severity::WARNING : ($hitRate < 98 ? Severity::INFO : Severity::OK),
                    currentValue: "{$hitRate}% (created: {$threadsCreated}, total: {$connections})",
                    suggestion: $hitRate < 90 ? 'Increase thread_cache_size' : null,
                    group: 'threads',
                );
            }
        }

        return $recs;
    }

    /** @param array<string, mixed> $mysql @param array<string, mixed> $system @return list<Recommendation> */
    private function memoryRecommendations(array $mysql, array $system): array
    {
        $recs = [];

        $totalMemory = $system['total_memory'] ?? null;
        if ($totalMemory === null) {
            return $recs;
        }

        $totalMemoryGb = (int) ($totalMemory / BufferCalculator::BYTES_IN_GB);
        if ($totalMemoryGb < 1) {
            $totalMemoryGb = 1;
        }

        $currentPoolSize = (int) ($mysql['innodb_buffer_pool_size'] ?? 0);
        $recommendedPool = BufferCalculator::recommendedInnodbPoolSize($totalMemoryGb);

        if ($currentPoolSize > 0 && $recommendedPool > 0 && $currentPoolSize < $recommendedPool) {
            $recs[] = new Recommendation(
                metric: 'innodb_buffer_pool_size',
                summary: 'InnoDB buffer pool size may be too small',
                severity: ($currentPoolSize < $recommendedPool / 2) ? Severity::CRITICAL : Severity::WARNING,
                currentValue: $this->formatBytes($currentPoolSize),
                suggestion: "Consider increasing to {$this->formatBytes($recommendedPool)} based on system memory ({$totalMemoryGb}GB)",
                group: 'memory',
            );
        }

        $maxConnections = (int) ($mysql['max_connections'] ?? 100);
        $recommendedConn = BufferCalculator::recommendedMaxConnections($totalMemoryGb);
        $maxUsed = (int) ($mysql['Max_used_connections'] ?? 0);

        if ($maxUsed > 0 && $maxUsed >= $maxConnections * 0.85) {
            $recs[] = new Recommendation(
                metric: 'max_connections',
                summary: "Max_used_connections ({$maxUsed}) is close to max_connections ({$maxConnections})",
                severity: Severity::WARNING,
                currentValue: "{$maxUsed} / {$maxConnections}",
                suggestion: "Increase max_connections (recommended: {$recommendedConn})",
                group: 'connection',
            );
        }

        return $recs;
    }

    /** @param array<string, mixed> $security @return list<Recommendation> */
    private function securityRecommendations(array $security): array
    {
        $recs = [];

        $anon = $security['anonymous_users'] ?? 0;
        if ($anon > 0) {
            $recs[] = Recommendation::critical(
                'anonymous_users',
                "{$anon} anonymous user(s) found",
                "DROP USER ''@'localhost'; DROP USER ''@'%';",
                (string) $anon,
                group: 'security',
            );
        }

        $emptyPwd = $security['empty_password_users'] ?? 0;
        if ($emptyPwd > 0) {
            $recs[] = Recommendation::critical(
                'empty_password_users',
                "{$emptyPwd} user(s) with empty password",
                'SET PASSWORD FOR user@host = PASSWORD(\'strong_password\');',
                (string) $emptyPwd,
                group: 'security',
            );
        }

        if ($security['have_root_empty_password'] ?? false) {
            $recs[] = Recommendation::critical(
                'root_empty_password',
                'Root user has an empty password',
                'ALTER USER root@localhost IDENTIFIED BY \'strong_password\';',
                group: 'security',
            );
        }

        if ($security['test_database_exists'] ?? false) {
            $recs[] = Recommendation::warn(
                'test_database',
                'Test database still exists',
                'DROP DATABASE test;',
                group: 'security',
            );
        }

        $wildHost = $security['users_without_host'] ?? 0;
        if ($wildHost > 0) {
            $recs[] = Recommendation::warn(
                'users_without_host',
                "{$wildHost} user(s) with host '%' (any host)",
                'Restrict users to specific hosts',
                (string) $wildHost,
                group: 'security',
            );
        }

        return $recs;
    }

    /** @param array<string, mixed> $schema @param array<string, mixed> $system @return list<Recommendation> */
    private function schemaRecommendations(array $schema, array $system): array
    {
        $recs = [];

        if ($schema['myisam_tables'] > 0) {
            $recs[] = Recommendation::info(
                'myisam_tables',
                "{$schema['myisam_tables']} MyISAM table(s) detected",
                'Consider migrating to InnoDB for better crash recovery and row-level locking',
                (string) $schema['myisam_tables'],
                group: 'schema',
            );
        }

        if ($schema['fragmented_tables'] > 0) {
            $recs[] = Recommendation::info(
                'fragmented_tables',
                "{$schema['fragmented_tables']} fragmented table(s) detected",
                "Run OPTIMIZE TABLE on fragmented tables",
                (string) $schema['fragmented_tables'],
                group: 'schema',
            );
        }

        $totalSize = $schema['total_data_size'] ?? 0;
        if ($totalSize > 0) {
            $recs[] = Recommendation::ok(
                'total_data_size',
                "Total data size: " . $this->formatBytes($totalSize),
                $this->formatBytes($totalSize),
            );
        }

        return $recs;
    }

    /** @param array<string, mixed> $mysql @return list<Recommendation> */
    private function replicationRecommendations(array $mysql): array
    {
        $recs = [];

        if (isset($mysql['slave_status']) && is_array($mysql['slave_status'])) {
            $slave = $mysql['slave_status'];

            $ioRunning = $slave['Slave_IO_Running'] ?? 'No';
            $sqlRunning = $slave['Slave_SQL_Running'] ?? 'No';

            if ($ioRunning !== 'Yes') {
                $recs[] = Recommendation::critical(
                    'Slave_IO_Running',
                    'Replication IO thread is not running',
                    'Check network connectivity and master status',
                    $ioRunning,
                    group: 'replication',
                );
            }

            if ($sqlRunning !== 'Yes') {
                $recs[] = Recommendation::critical(
                    'Slave_SQL_Running',
                    'Replication SQL thread is not running',
                    'Check SHOW SLAVE STATUS\\G for errors',
                    $sqlRunning,
                    group: 'replication',
                );
            }

            $secondsBehind = $slave['Seconds_Behind_Master'] ?? null;
            if ($secondsBehind !== null && $secondsBehind > 30) {
                $recs[] = Recommendation::warn(
                    'Seconds_Behind_Master',
                    "Replication lag: {$secondsBehind} seconds behind master",
                    'Check slave performance or increase resources',
                    "{$secondsBehind}s",
                    group: 'replication',
                );
            }
        }

        return $recs;
    }

    /** @param list<Recommendation> $recommendations */
    private function calculateScore(array $recommendations): int
    {
        $totalWeight = array_sum(array_map(
            fn(Recommendation $r) => $r->severity->weight(),
            $recommendations,
        ));

        $maxWeight = count($recommendations) * 5;
        if ($maxWeight === 0) {
            return 100;
        }

        return max(0, min(100, (int) round((1 - $totalWeight / $maxWeight) * 100)));
    }

    private function findGroupForKey(RuleSet $ruleSet, string $key): ?string
    {
        foreach ($ruleSet->getGroups() as $group) {
            foreach ($ruleSet->getRules($group) as $rule) {
                if ($rule->key === $key) {
                    return $group;
                }
            }
        }
        return null;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.2f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }
        return "{$bytes} B";
    }
}
