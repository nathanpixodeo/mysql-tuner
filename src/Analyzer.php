<?php

declare(strict_types=1);

namespace MySQLTuner;

use MySQLTuner\Calculator\BufferCalculator;
use MySQLTuner\Calculator\HitRateCalculator;
use MySQLTuner\Collector\MySQLCollector;
use MySQLTuner\Collector\SchemaCollector;
use MySQLTuner\Collector\SecurityCollector;
use MySQLTuner\Collector\SystemCollector;
use MySQLTuner\Database\PdoQueryRunner;
use MySQLTuner\Database\QueryRunner;
use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;
use MySQLTuner\Report\ConsoleFormatter;
use MySQLTuner\Report\Report;
use MySQLTuner\Report\ScoreCalculator;
use MySQLTuner\Rules\RuleSet;
use PDO;

class Analyzer
{
    /**
     * Counter-derived advice needs a settled server. Below this, rate calculations describe the
     * warm-up rather than the workload.
     */
    private const MIN_UPTIME_FOR_RATES = 3600;

    private readonly MySQLCollector $mysqlCollector;
    private readonly SchemaCollector $schemaCollector;
    private readonly SecurityCollector $securityCollector;
    private readonly RuleSet $ruleSet;

    public function __construct(
        private readonly QueryRunner $runner,
        ?RuleSet $ruleSet = null,
    ) {
        $this->mysqlCollector = new MySQLCollector($runner);
        $this->schemaCollector = new SchemaCollector($runner);
        $this->securityCollector = new SecurityCollector($runner);
        $this->ruleSet = $ruleSet ?? new RuleSet(__DIR__ . '/../rules');
    }

    /**
     * Build from a PDO connection.
     *
     * Kept so existing callers doing `new Analyzer($pdo)` keep working; they are routed through the
     * query runner that the collectors now depend on.
     */
    public static function fromPdo(PDO $pdo, ?RuleSet $ruleSet = null): self
    {
        return new self(new PdoQueryRunner($pdo), $ruleSet);
    }

    public function analyze(): Report
    {
        $mysql = $this->mysqlCollector->collect();
        $schema = $this->schemaCollector->collect();
        $security = $this->securityCollector->collect();
        $system = $this->collectSystem($mysql);

        $version = is_string($mysql['version'] ?? null) ? $mysql['version'] : 'unknown';
        $uptime = (int) ($mysql['Uptime'] ?? 0);

        $this->ruleSet->loadForVersion($version);

        $recommendations = [
            ...$this->applyFileRules($mysql, $uptime),
            ...$this->securityRecommendations($security),
            ...$this->hitRateRecommendations($mysql, $uptime),
            ...$this->memoryRecommendations($mysql, $system),
            ...$this->schemaRecommendations($schema),
            ...$this->replicationRecommendations($mysql),
            ...$this->collectionWarnings($mysql, $schema),
        ];

        return new Report(
            recommendations: $recommendations,
            version: $version,
            uptime: $uptime,
            score: ScoreCalculator::calculate($recommendations),
            systemMetrics: $system,
        );
    }

    /**
     * @param array<string, mixed> $mysql
     * @return array<string, mixed>
     */
    private function collectSystem(array $mysql): array
    {
        $isLocal = $this->runner->isLocal();

        // Prefer the server's own data directory over this process's temp directory, so the disk
        // figures describe where the data actually lives.
        $dataDir = is_string($mysql['datadir'] ?? null) && is_dir($mysql['datadir'])
            ? $mysql['datadir']
            : null;

        return (new SystemCollector(isLocal: $isLocal, diskPath: $dataDir))->collect();
    }

    /**
     * @param array<string, mixed> $metrics
     * @return list<Recommendation>
     */
    private function applyFileRules(array $metrics, int $uptime): array
    {
        $recommendations = [];
        $groupIndex = $this->ruleSet->groupIndex();

        foreach ($this->ruleSet->getRules() as $rule) {
            if (!array_key_exists($rule->key, $metrics)) {
                continue;
            }

            // Honour the documented meaning: skip only while the server has not been up long
            // enough. This used to skip such a rule unconditionally, so any rule declaring
            // uptime_min never fired at all.
            if ($rule->uptimeMin !== null && $uptime < $rule->uptimeMin) {
                continue;
            }

            $current = $metrics[$rule->key];

            if (!$rule->evaluate($current)) {
                continue;
            }

            $recommendations[] = new Recommendation(
                metric: $rule->key,
                summary: $rule->summary,
                severity: $rule->severity,
                currentValue: $this->stringify($current),
                suggestion: $rule->suggestion,
                group: $groupIndex[$rule->key] ?? 'general',
            );
        }

        return $recommendations;
    }

    /**
     * @param array<string, mixed> $mysql
     * @return list<Recommendation>
     */
    private function hitRateRecommendations(array $mysql, int $uptime): array
    {
        if ($uptime < self::MIN_UPTIME_FOR_RATES) {
            // Report the reason instead of the numbers. Clamping alone would hide the anomaly
            // behind a plausible-looking percentage.
            return [Recommendation::info(
                'uptime',
                'Server uptime is under 1 hour, so cache hit rates were not evaluated',
                'Re-run after at least 24 hours of representative traffic.',
                ConsoleFormatter::formatUptime($uptime),
                group: 'general',
            )];
        }

        $recs = [];

        $poolRequests = (int) ($mysql['Innodb_buffer_pool_read_requests'] ?? 0);
        $hitRate = HitRateCalculator::innodbBufferPoolHitRate(
            (int) ($mysql['Innodb_buffer_pool_reads'] ?? 0),
            $poolRequests,
        );
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

        $keyRequests = (int) ($mysql['Key_read_requests'] ?? 0);
        $hitRate = HitRateCalculator::keyBufferHitRate(
            (int) ($mysql['Key_reads'] ?? 0),
            $keyRequests,
        );
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

        $threadsCreated = (int) ($mysql['Threads_created'] ?? 0);
        $connections = (int) ($mysql['Connections'] ?? 0);
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

        return $recs;
    }

    /**
     * @param array<string, mixed> $mysql
     * @param array<string, mixed> $system
     * @return list<Recommendation>
     */
    private function memoryRecommendations(array $mysql, array $system): array
    {
        if (($system['is_local'] ?? false) !== true) {
            // The /proc figures describe this machine, not the database host. Sizing a buffer pool
            // from the operator's laptop is worse than saying nothing.
            return [Recommendation::info(
                'system_memory',
                'Memory-based recommendations were skipped because the server is not local',
                'Run the tuner on the database host to size innodb_buffer_pool_size against its RAM.',
                group: 'memory',
            )];
        }

        $totalMemory = $system['total_memory'] ?? null;
        if (!is_int($totalMemory) || $totalMemory <= 0) {
            return [];
        }

        $recs = [];
        $totalMemoryGb = max(1, (int) ($totalMemory / BufferCalculator::BYTES_IN_GB));

        $currentPoolSize = (int) ($mysql['innodb_buffer_pool_size'] ?? 0);
        $recommendedPool = BufferCalculator::recommendedInnodbPoolSize($totalMemoryGb);

        if ($currentPoolSize > 0 && $recommendedPool > 0 && $currentPoolSize < $recommendedPool) {
            $recs[] = new Recommendation(
                metric: 'innodb_buffer_pool_size',
                summary: 'InnoDB buffer pool size may be too small',
                severity: $currentPoolSize < intdiv($recommendedPool, 2) ? Severity::CRITICAL : Severity::WARNING,
                currentValue: $this->formatBytes($currentPoolSize),
                suggestion: "Consider increasing to {$this->formatBytes($recommendedPool)}"
                    . " based on system memory ({$totalMemoryGb}GB)",
                group: 'memory',
            );
        }

        $maxConnections = (int) ($mysql['max_connections'] ?? 100);
        $maxUsed = (int) ($mysql['Max_used_connections'] ?? 0);

        if ($maxUsed > 0 && $maxConnections > 0 && $maxUsed >= $maxConnections * 0.85) {
            $recommendedConn = BufferCalculator::recommendedMaxConnections($totalMemoryGb);
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

    /**
     * @param array<string, mixed> $security
     * @return list<Recommendation>
     */
    private function securityRecommendations(array $security): array
    {
        $recs = [];

        // A skipped probe is reported, never treated as a clean result. The previous code used -1
        // as an error sentinel and then tested `> 0`, so a monitoring account without SELECT on
        // mysql.user - and every MySQL 8 server, whose mysql.user has no `password` column - got a
        // report indistinguishable from a server with nothing to find.
        $skipped = is_array($security['checks_skipped'] ?? null) ? $security['checks_skipped'] : [];
        foreach ($skipped as $metric => $reason) {
            $recs[] = Recommendation::warn(
                (string) $metric,
                'Security check "' . $metric . '" could not be completed',
                is_string($reason) ? $reason : null,
                'not checked',
                group: 'security',
            );
        }

        $anon = $security['anonymous_users'] ?? null;
        if (is_int($anon) && $anon > 0) {
            $recs[] = Recommendation::critical(
                'anonymous_users',
                "{$anon} anonymous user(s) found",
                "DROP USER ''@'localhost'; DROP USER ''@'%';",
                (string) $anon,
                group: 'security',
            );
        }

        $emptyPwd = $security['empty_password_users'] ?? null;
        if (is_int($emptyPwd) && $emptyPwd > 0) {
            $recs[] = Recommendation::critical(
                'empty_password_users',
                "{$emptyPwd} user(s) with an empty password",
                "ALTER USER 'user'@'host' IDENTIFIED BY 'strong_password';",
                (string) $emptyPwd,
                group: 'security',
            );
        }

        if (($security['have_root_empty_password'] ?? null) === true) {
            $recs[] = Recommendation::critical(
                'root_empty_password',
                'Root user has an empty password',
                "ALTER USER 'root'@'localhost' IDENTIFIED BY 'strong_password';",
                group: 'security',
            );
        }

        if (($security['test_database_exists'] ?? null) === true) {
            $recs[] = Recommendation::warn(
                'test_database',
                'Test database still exists',
                'DROP DATABASE test;',
                group: 'security',
            );
        }

        $wildHost = $security['users_without_host'] ?? null;
        if (is_int($wildHost) && $wildHost > 0) {
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

    /**
     * @param array<string, mixed> $schema
     * @return list<Recommendation>
     */
    private function schemaRecommendations(array $schema): array
    {
        $recs = [];

        $myisam = $schema['myisam_tables'] ?? null;
        if (is_int($myisam) && $myisam > 0) {
            $recs[] = Recommendation::info(
                'myisam_tables',
                "{$myisam} MyISAM table(s) detected",
                'Consider migrating to InnoDB for crash recovery and row-level locking',
                (string) $myisam,
                group: 'schema',
            );
        }

        $fragmented = $schema['fragmented_tables'] ?? null;
        if (is_int($fragmented) && $fragmented > 0) {
            $recs[] = Recommendation::info(
                'fragmented_tables',
                "{$fragmented} fragmented table(s) detected",
                'Run OPTIMIZE TABLE on the fragmented tables',
                (string) $fragmented,
                group: 'schema',
            );
        }

        $totalSize = $schema['total_data_size'] ?? null;
        if (is_int($totalSize) && $totalSize > 0) {
            $recs[] = Recommendation::ok(
                'total_data_size',
                'Total data size: ' . $this->formatBytes($totalSize),
                currentValue: $this->formatBytes($totalSize),
                group: 'schema',
            );
        }

        return $recs;
    }

    /**
     * @param array<string, mixed> $mysql
     * @return list<Recommendation>
     */
    private function replicationRecommendations(array $mysql): array
    {
        $slave = $mysql['slave_status'] ?? null;

        if (!is_array($slave)) {
            return [];
        }

        $recs = [];

        $ioRunning = $this->stringify($slave['Slave_IO_Running'] ?? 'No');
        if ($ioRunning !== 'Yes') {
            $recs[] = Recommendation::critical(
                'Slave_IO_Running',
                'Replication IO thread is not running',
                'Check network connectivity and the source server status',
                $ioRunning,
                group: 'replication',
            );
        }

        $sqlRunning = $this->stringify($slave['Slave_SQL_Running'] ?? 'No');
        if ($sqlRunning !== 'Yes') {
            $recs[] = Recommendation::critical(
                'Slave_SQL_Running',
                'Replication SQL thread is not running',
                'Inspect SHOW REPLICA STATUS for the last error',
                $sqlRunning,
                group: 'replication',
            );
        }

        $secondsBehind = $slave['Seconds_Behind_Master'] ?? null;
        if (is_numeric($secondsBehind) && (int) $secondsBehind > 30) {
            $secondsBehind = (int) $secondsBehind;
            $recs[] = Recommendation::warn(
                'Seconds_Behind_Master',
                "Replication lag: {$secondsBehind} seconds behind the source",
                'Check replica performance or increase its resources',
                "{$secondsBehind}s",
                group: 'replication',
            );
        }

        return $recs;
    }

    /**
     * Surface collection failures that are not security-specific.
     *
     * Without this a denied information_schema query simply produced a shorter report.
     *
     * @param array<string, mixed> $mysql
     * @param array<string, mixed> $schema
     * @return list<Recommendation>
     */
    private function collectionWarnings(array $mysql, array $schema): array
    {
        $recs = [];

        foreach (['server' => $mysql, 'schema' => $schema] as $source => $metrics) {
            $skipped = is_array($metrics['checks_skipped'] ?? null) ? $metrics['checks_skipped'] : [];

            foreach ($skipped as $metric => $reason) {
                $recs[] = Recommendation::info(
                    (string) $metric,
                    ucfirst($source) . ' metric "' . $metric . '" could not be collected',
                    is_string($reason) ? $reason : null,
                    'not checked',
                    group: 'general',
                );
            }
        }

        return $recs;
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '' : $encoded;
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
