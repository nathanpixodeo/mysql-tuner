<?php

use MySQLTuner\Report\ConsoleFormatter;
use MySQLTuner\Report\Report;
use MySQLTuner\Recommendation\Recommendation;
use PHPUnit\Framework\TestCase;

class ConsoleFormatterTest extends TestCase
{
    public function testFormatEmptyReport(): void
    {
        $report = new Report(
            recommendations: [],
            version: '8.0.32',
            uptime: 86400,
            score: 100,
        );

        $formatter = new ConsoleFormatter(noColor: true);
        $output = $formatter->format($report);

        $this->assertStringContainsString('MySQL Tuner Report', $output);
        $this->assertStringContainsString('8.0.32', $output);
        $this->assertStringContainsString('1d 0s', $output);
        $this->assertStringContainsString('100/100', $output);
    }

    public function testFormatWithRecommendations(): void
    {
        $recs = [
            Recommendation::ok('test_ok', 'Everything is fine', '42'),
            Recommendation::warn('test_warn', 'High memory usage', 'Increase buffer pool', '80%'),
        ];

        $report = new Report(
            recommendations: $recs,
            version: '8.0.32',
            uptime: 3600,
            score: 60,
        );

        $formatter = new ConsoleFormatter(noColor: true);
        $output = $formatter->format($report);

        $this->assertStringContainsString('Everything is fine', $output);
        $this->assertStringContainsString('High memory usage', $output);
        $this->assertStringContainsString('Increase buffer pool', $output);
    }

    public function testFormatUptime(): void
    {
        $this->assertSame('0s', ConsoleFormatter::formatUptime(0));
        $this->assertSame('30s', ConsoleFormatter::formatUptime(30));
        $this->assertSame('5m 0s', ConsoleFormatter::formatUptime(300));
        $this->assertSame('1h 0s', ConsoleFormatter::formatUptime(3600));
        $this->assertSame('1d 0s', ConsoleFormatter::formatUptime(86400));
        $this->assertSame('7d 12h 30m 15s', ConsoleFormatter::formatUptime(7 * 86400 + 12 * 3600 + 30 * 60 + 15));
    }
}
