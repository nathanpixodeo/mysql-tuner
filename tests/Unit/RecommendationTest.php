<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RecommendationTest extends TestCase
{
    public function testCreateOkRecommendation(): void
    {
        $rec = Recommendation::ok('test_metric', 'Everything is fine', currentValue: '42');

        self::assertSame('test_metric', $rec->metric);
        self::assertSame('Everything is fine', $rec->summary);
        self::assertSame(Severity::OK, $rec->severity);
        self::assertSame('42', $rec->currentValue);
    }

    public function testCreateWarningRecommendation(): void
    {
        $rec = Recommendation::warn('test_warn', 'Something needs attention', 'Increase value', '42');

        self::assertSame(Severity::WARNING, $rec->severity);
        self::assertSame('Increase value', $rec->suggestion);
        self::assertSame('42', $rec->currentValue);
    }

    public function testCreateCriticalRecommendation(): void
    {
        $rec = Recommendation::critical('test_crit', 'Critical issue', 'Fix immediately', '0');

        self::assertSame(Severity::CRITICAL, $rec->severity);
        self::assertSame('Fix immediately', $rec->suggestion);
    }

    public function testInfoCanCarryASuggestion(): void
    {
        $rec = Recommendation::info('test_info', 'Worth knowing', 'Do this', '3');

        self::assertSame(Severity::INFO, $rec->severity);
        self::assertSame('Do this', $rec->suggestion);
        self::assertSame('3', $rec->currentValue);
    }

    /**
     * Locks the shape rather than the symptom.
     *
     * `info()` used to take (metric, summary, currentValue, detail, group) while its three siblings
     * took (metric, summary, suggestion, currentValue, ...). Two call sites passed arguments in the
     * sibling order, so advice text was rendered as the current value. Asserting that the
     * positional parameters agree stops that returning by a different route.
     */
    public function testAllFactoriesShareTheSamePositionalOrder(): void
    {
        $expected = null;

        foreach (['ok', 'info', 'warn', 'critical'] as $factory) {
            $names = array_map(
                static fn(\ReflectionParameter $p): string => $p->getName(),
                (new ReflectionMethod(Recommendation::class, $factory))->getParameters(),
            );

            $expected ??= $names;

            self::assertSame($expected, $names, "Recommendation::{$factory}() parameter order diverged");
        }

        self::assertSame(
            ['metric', 'summary', 'suggestion', 'currentValue', 'detail', 'group'],
            $expected,
        );
    }

    public function testSeverityWeights(): void
    {
        self::assertSame(0, Severity::OK->weight());
        self::assertSame(1, Severity::INFO->weight());
        self::assertSame(3, Severity::WARNING->weight());
        self::assertSame(5, Severity::CRITICAL->weight());
    }

    public function testToArray(): void
    {
        $rec = new Recommendation('metric1', 'Summary text', Severity::WARNING, '42', 'Suggestion', 'Detail', 'group1');
        $arr = $rec->toArray();

        self::assertSame('metric1', $arr['metric']);
        self::assertSame('WARNING', $arr['severity']);
        self::assertSame('Summary text', $arr['summary']);
        self::assertSame('42', $arr['current_value']);
        self::assertSame('Suggestion', $arr['suggestion']);
        self::assertSame('Detail', $arr['detail']);
        self::assertSame('group1', $arr['group']);
    }
}
