<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Report\ConsoleFormatter;
use MySQLTuner\Report\Report;
use PHPUnit\Framework\TestCase;

final class ConsoleFormatterTest extends TestCase
{
    /** @param list<Recommendation> $recs */
    private function render(array $recs, int $score = 100): string
    {
        return (new ConsoleFormatter(noColor: true))->format(new Report(
            recommendations: $recs,
            version: '8.0.32',
            uptime: 86400,
            score: $score,
        ));
    }

    public function testFormatEmptyReport(): void
    {
        $output = $this->render([]);

        self::assertStringContainsString('MySQL Tuner Report', $output);
        self::assertStringContainsString('8.0.32', $output);
        self::assertStringContainsString('1d 0s', $output);
        self::assertStringContainsString('100/100', $output);
    }

    public function testFormatWithRecommendations(): void
    {
        $output = $this->render([
            Recommendation::ok('test_ok', 'Everything is fine', currentValue: '42'),
            Recommendation::warn('test_warn', 'High memory usage', 'Increase buffer pool', '80%'),
        ], 60);

        self::assertStringContainsString('High memory usage', $output);
        self::assertStringContainsString('Increase buffer pool', $output);
    }

    /**
     * Security findings were rendered by the dedicated section and then again by the generic
     * per-group loop, which had no skip for the group it had already printed.
     */
    public function testSecurityFindingAppearsExactlyOnce(): void
    {
        $output = $this->render([
            Recommendation::critical('anonymous_users', '2 anonymous user(s) found', 'DROP USER', '2', group: 'security'),
            Recommendation::warn('slow_queries', 'Slow queries detected', 'Tune indexes', '12', group: 'performance'),
        ], 40);

        self::assertSame(1, substr_count($output, '2 anonymous user(s) found'));
        self::assertStringContainsString('Security Issues', $output);
    }

    public function testNonSecurityGroupsStillRender(): void
    {
        $output = $this->render([
            Recommendation::warn('slow_queries', 'Slow queries detected', 'Tune indexes', '12', group: 'performance'),
        ], 70);

        self::assertStringContainsString('Slow queries detected', $output);
        self::assertStringContainsString('performance', $output);
    }

    public function testGroupsContainingOnlyOkFindingsAreOmitted(): void
    {
        $output = $this->render([
            Recommendation::ok('all_good', 'Nothing to do here', group: 'innodb'),
        ]);

        self::assertStringNotContainsString('Nothing to do here', $output);
    }

    public function testNoColorProducesNoEscapeSequences(): void
    {
        $output = $this->render([
            Recommendation::critical('x', 'Bad thing', 'Fix it', '1', group: 'security'),
        ], 0);

        self::assertStringNotContainsString("\033[", $output);
    }

    public function testRemoteHostIsStatedInTheSystemSection(): void
    {
        $output = (new ConsoleFormatter(noColor: true))->format(new Report(
            recommendations: [],
            version: '8.0.32',
            uptime: 86400,
            score: 100,
            systemMetrics: ['os_type' => 'Linux', 'is_local' => false],
        ));

        self::assertStringContainsString('remote', $output);
    }

    public function testFormatUptime(): void
    {
        self::assertSame('0s', ConsoleFormatter::formatUptime(0));
        self::assertSame('30s', ConsoleFormatter::formatUptime(30));
        self::assertSame('5m 0s', ConsoleFormatter::formatUptime(300));
        self::assertSame('1h 0s', ConsoleFormatter::formatUptime(3600));
        self::assertSame('1d 0s', ConsoleFormatter::formatUptime(86400));
        self::assertSame('7d 12h 30m 15s', ConsoleFormatter::formatUptime(7 * 86400 + 12 * 3600 + 30 * 60 + 15));
    }
}
