<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit;

use MySQLTuner\Analyzer;
use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;
use MySQLTuner\Rules\RuleSet;
use MySQLTuner\Tests\Support\FakeServer;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private function ruleSet(): RuleSet
    {
        return new RuleSet(__DIR__ . '/../../rules');
    }

    /** @param list<Recommendation> $recs */
    private function find(array $recs, string $metric): ?Recommendation
    {
        foreach ($recs as $rec) {
            if ($rec->metric === $metric) {
                return $rec;
            }
        }

        return null;
    }

    public function testProducesAReportFromAHealthyServer(): void
    {
        $report = (new Analyzer(FakeServer::healthy()->build(), $this->ruleSet()))->analyze();

        self::assertSame('8.0.36', $report->version);
        self::assertSame(86400, $report->uptime);
        self::assertGreaterThan(0, count($report->recommendations));
    }

    /**
     * Calling analyze() twice used to duplicate every rule, because loadForVersion() appended to a
     * persistent array that was never reset.
     */
    public function testAnalyzingTwiceProducesTheSameReport(): void
    {
        $analyzer = new Analyzer(FakeServer::healthy()->build(), $this->ruleSet());

        $first = $analyzer->analyze();
        $second = $analyzer->analyze();

        self::assertCount(count($first->recommendations), $second->recommendations);
        self::assertSame($first->score, $second->score);
    }

    /**
     * The headline security regression: a denied probe must never read as a clean bill of health.
     */
    public function testDeniedSecurityProbesProduceWarningsNotSilence(): void
    {
        $report = (new Analyzer(FakeServer::healthy()->denyingMysqlUser()->build(), $this->ruleSet()))
            ->analyze();

        $security = $report->byGroup('security');

        self::assertNotEmpty($security, 'a server we could not audit must not look audited');

        $summaries = array_map(static fn(Recommendation $r): string => $r->summary, $security);
        $joined = implode("\n", $summaries);

        self::assertStringContainsString('could not be completed', $joined);
    }

    public function testNoSentinelValueLeaksIntoRecommendationText(): void
    {
        $report = (new Analyzer(FakeServer::healthy()->denyingMysqlUser()->build(), $this->ruleSet()))
            ->analyze();

        foreach ($report->recommendations as $rec) {
            self::assertStringNotContainsString('-1', (string) $rec->currentValue);
        }
    }

    public function testEmptyPasswordUsersAreReportedOnAServerWithoutThePasswordColumn(): void
    {
        $report = (new Analyzer(
            FakeServer::healthy()->withEmptyPasswordUsers(2)->build(),
            $this->ruleSet(),
        ))->analyze();

        $rec = $this->find($report->recommendations, 'empty_password_users');

        self::assertNotNull($rec);
        self::assertSame(Severity::CRITICAL, $rec->severity);
        self::assertSame('2', $rec->currentValue);
    }

    public function testAnonymousUsersAreReportedAsCritical(): void
    {
        $report = (new Analyzer(
            FakeServer::healthy()->withAnonymousUsers(3)->build(),
            $this->ruleSet(),
        ))->analyze();

        $rec = $this->find($report->recommendations, 'anonymous_users');

        self::assertNotNull($rec);
        self::assertSame(Severity::CRITICAL, $rec->severity);
    }

    /**
     * System metrics describe the machine running the tool, so against a remote server they must
     * not be turned into buffer-pool advice.
     */
    public function testRemoteServerSuppressesMemoryRecommendations(): void
    {
        $report = (new Analyzer(FakeServer::healthy()->remote()->build(), $this->ruleSet()))->analyze();

        self::assertFalse($report->systemMetrics['is_local']);

        // A static rule file may still comment on innodb_buffer_pool_size; what must not appear is
        // advice computed from this machine's RAM.
        foreach ($report->recommendations as $rec) {
            self::assertStringNotContainsString('based on system memory', (string) $rec->suggestion);
        }

        $notice = $this->find($report->recommendations, 'system_memory');
        self::assertNotNull($notice);
        self::assertStringContainsString('not local', $notice->summary);
    }

    public function testCacheHitRatesAreSkippedOnAFreshlyStartedServer(): void
    {
        $report = (new Analyzer(
            FakeServer::healthy()->withStatus(['Uptime' => '47', 'Threads_created' => '151', 'Connections' => '3'])->build(),
            $this->ruleSet(),
        ))->analyze();

        // Static rules may still mention Threads_created; the computed hit-rate line must not
        // appear, because on a 47-second-old server it describes the warm-up, not the workload.
        foreach ($report->recommendations as $rec) {
            self::assertStringNotContainsString('Thread cache hit rate', $rec->summary);
        }

        $notice = $this->find($report->recommendations, 'uptime');
        self::assertNotNull($notice);
        self::assertStringContainsString('under 1 hour', $notice->summary);
    }

    public function testNoRecommendationReportsANegativeHitRate(): void
    {
        $report = (new Analyzer(
            FakeServer::healthy()->withStatus(['Threads_created' => '5000', 'Connections' => '10'])->build(),
            $this->ruleSet(),
        ))->analyze();

        foreach ($report->recommendations as $rec) {
            self::assertStringNotContainsString('-', (string) $rec->currentValue);
        }
    }

    public function testEveryRecommendationBelongsToAGroup(): void
    {
        $report = (new Analyzer(FakeServer::healthy()->build(), $this->ruleSet()))->analyze();

        foreach ($report->recommendations as $rec) {
            self::assertNotNull($rec->group, "recommendation '{$rec->metric}' has no group");
        }
    }

    public function testSchemaInfoRecommendationCarriesTheCountNotTheAdviceText(): void
    {
        $report = (new Analyzer(FakeServer::healthy()->build(), $this->ruleSet()))->analyze();

        $rec = $this->find($report->recommendations, 'total_data_size');

        self::assertNotNull($rec);
        // The current value must be the measurement; advice belongs in `suggestion`.
        self::assertStringContainsString('KB', (string) $rec->currentValue);
    }
}
