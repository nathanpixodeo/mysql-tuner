<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit;

use InvalidArgumentException;
use MySQLTuner\Recommendation\Severity;
use MySQLTuner\Rules\Condition;
use MySQLTuner\Rules\Rule;
use PHPUnit\Framework\TestCase;

final class RuleTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides = []): Rule
    {
        return Rule::fromArray([
            'key' => 'test_metric',
            'type' => 'int',
            'severity' => 'WARNING',
            'summary' => 'Test rule',
            'condition' => 'gt',
            'threshold' => 100,
            ...$overrides,
        ]);
    }

    public function testIntegerComparisons(): void
    {
        $gt = $this->rule(['condition' => 'gt', 'threshold' => 100]);
        self::assertTrue($gt->evaluate(101));
        self::assertFalse($gt->evaluate(100));
        self::assertFalse($gt->evaluate(99));

        $lt = $this->rule(['condition' => 'lt', 'threshold' => 50]);
        self::assertTrue($lt->evaluate(10));
        self::assertFalse($lt->evaluate(50));

        $gte = $this->rule(['condition' => 'gte', 'threshold' => 50]);
        self::assertTrue($gte->evaluate(50));
        self::assertFalse($gte->evaluate(49));

        $lte = $this->rule(['condition' => 'lte', 'threshold' => 50]);
        self::assertTrue($lte->evaluate(50));
        self::assertFalse($lte->evaluate(51));
    }

    public function testEqualityComparisons(): void
    {
        $eq = $this->rule(['condition' => 'eq', 'threshold' => 42]);
        self::assertTrue($eq->evaluate(42));
        self::assertTrue($eq->evaluate('42'), 'the value is cast to the declared type first');
        self::assertFalse($eq->evaluate(43));

        $neq = $this->rule(['type' => 'string', 'condition' => 'neq', 'threshold' => 'ON']);
        self::assertTrue($neq->evaluate('OFF'));
        self::assertFalse($neq->evaluate('ON'));
    }

    public function testStringConditions(): void
    {
        $contains = $this->rule(['type' => 'string', 'condition' => 'contains', 'threshold' => 'MariaDB']);
        self::assertTrue($contains->evaluate('10.5.18-MariaDB-log'));
        self::assertFalse($contains->evaluate('8.0.32'));

        $notContains = $this->rule(['type' => 'string', 'condition' => 'not_contains', 'threshold' => 'MariaDB']);
        self::assertTrue($notContains->evaluate('8.0.32'));
        self::assertFalse($notContains->evaluate('10.5.18-MariaDB-log'));

        $regex = $this->rule(['type' => 'string', 'condition' => 'regex', 'threshold' => '/^5\.\d+/']);
        self::assertTrue($regex->evaluate('5.7.42'));
        self::assertFalse($regex->evaluate('8.0.32'));
    }

    /**
     * The threshold used to skip the 'bool' branch, so a boolean value was compared against the
     * raw string "OFF" and such a rule could never match.
     */
    public function testBooleanTypeCastsBothSides(): void
    {
        $rule = $this->rule(['type' => 'bool', 'condition' => 'eq', 'threshold' => 'OFF']);

        self::assertTrue($rule->evaluate('OFF'));
        self::assertTrue($rule->evaluate(false));
        self::assertFalse($rule->evaluate('ON'));
    }

    public function testFloatType(): void
    {
        $rule = $this->rule(['type' => 'float', 'condition' => 'gt', 'threshold' => 1.5]);

        self::assertTrue($rule->evaluate('2.0'));
        self::assertFalse($rule->evaluate('1.5'));
    }

    public function testSeverityAndConditionAreParsedIntoEnums(): void
    {
        $rule = $this->rule(['severity' => 'CRITICAL', 'condition' => 'lt']);

        self::assertSame(Severity::CRITICAL, $rule->severity);
        self::assertSame(Condition::LT, $rule->condition);
    }

    /**
     * An unknown operator used to fall through to `default => false`, permanently disabling the
     * rule while looking exactly like a check that passed.
     */
    public function testUnknownConditionIsRejectedAtParseTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule(['condition' => 'greaterthan']);
    }

    public function testUnknownSeverityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule(['severity' => 'warning']);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule(['type' => 'integer']);
    }

    public function testMissingRequiredFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rule::fromArray(['key' => 'x', 'condition' => 'gt', 'threshold' => 1]);
    }

    public function testStringOnlyConditionWithANumericTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule(['type' => 'int', 'condition' => 'contains', 'threshold' => 'x']);
    }

    /**
     * A malformed pattern would otherwise emit a PHP warning mid-report and silently evaluate
     * false.
     */
    public function testInvalidRegexIsRejectedAtParseTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rule(['type' => 'string', 'condition' => 'regex', 'threshold' => '/unterminated']);
    }

    public function testUptimeMinAndMaxPerGbAreParsed(): void
    {
        $rule = $this->rule(['uptime_min' => 3600, 'max_per_gb' => 1.5]);

        self::assertSame(3600, $rule->uptimeMin);
        self::assertSame(1.5, $rule->maxPerGB);
    }
}
