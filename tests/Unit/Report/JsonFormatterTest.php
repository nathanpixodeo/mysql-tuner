<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Report;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Report\JsonFormatter;
use MySQLTuner\Report\Report;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    /**
     * @param list<Recommendation> $recs
     * @param array<string, mixed> $system
     * @return array<string, mixed>
     */
    private function decode(array $recs = [], array $system = []): array
    {
        $json = (new JsonFormatter())->format(new Report(
            recommendations: $recs,
            version: '8.0.32',
            uptime: 90061,
            score: 75,
            systemMetrics: $system,
        ));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTopLevelShapeIsStable(): void
    {
        $data = $this->decode();

        // Automation consumes these key names, so pin them.
        self::assertSame(
            ['version', 'uptime', 'uptime_human', 'score', 'summary', 'system', 'recommendations'],
            array_keys($data),
        );
    }

    public function testSummaryCountsEachSeverity(): void
    {
        $data = $this->decode([
            Recommendation::ok('a', 'fine'),
            Recommendation::info('b', 'fyi'),
            Recommendation::warn('c', 'meh'),
            Recommendation::critical('d', 'bad'),
        ]);

        self::assertSame(
            ['ok' => 1, 'info' => 1, 'warnings' => 1, 'critical' => 1, 'total' => 4],
            $data['summary'],
        );
    }

    public function testRecommendationShape(): void
    {
        $data = $this->decode([
            Recommendation::warn('metric', 'Summary', 'Suggestion', '42', 'Detail', 'group'),
        ]);

        self::assertSame([
            'metric' => 'metric',
            'severity' => 'WARNING',
            'summary' => 'Summary',
            'current_value' => '42',
            'suggestion' => 'Suggestion',
            'detail' => 'Detail',
            'group' => 'group',
        ], $data['recommendations'][0]);
    }

    /**
     * System metrics were rendered on the console but omitted from JSON, so the two formatters
     * disagreed about what had been collected.
     */
    public function testSystemMetricsAreIncluded(): void
    {
        $data = $this->decode(system: ['os_type' => 'Linux', 'cpu_cores' => 8]);

        self::assertSame(8, $data['system']['cpu_cores']);
    }

    public function testUptimeIsAlsoRenderedForHumans(): void
    {
        self::assertSame('1d 1h 1m 1s', $this->decode()['uptime_human']);
    }

    /**
     * Server variables can hold bytes that are not valid UTF-8. json_encode then returns false,
     * which the declared string return type turned into a TypeError.
     */
    public function testInvalidUtf8DoesNotBreakEncoding(): void
    {
        $data = $this->decode([
            Recommendation::warn('init_connect', "bad \xB1\x31 bytes", null, "\xB1\x31"),
        ]);

        self::assertCount(1, $data['recommendations']);
        self::assertSame('init_connect', $data['recommendations'][0]['metric']);
    }

    public function testCompactModeIsStillValidJson(): void
    {
        $json = (new JsonFormatter())->format(
            new Report(recommendations: [], version: '8.0', uptime: 1, score: 100),
            pretty: false,
        );

        self::assertStringNotContainsString("\n", $json);
        self::assertIsArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
