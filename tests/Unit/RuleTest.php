<?php

use MySQLTuner\Rules\Rule;
use PHPUnit\Framework\TestCase;

class RuleTest extends TestCase
{
    public function testRuleIntegerEvaluation(): void
    {
        $rule = Rule::fromArray([
            'key' => 'test_int',
            'type' => 'int',
            'severity' => 'WARNING',
            'summary' => 'Test rule',
            'condition' => 'gt',
            'threshold' => 100,
        ]);

        $this->assertTrue($rule->evaluate(101));
        $this->assertFalse($rule->evaluate(99));
        $this->assertFalse($rule->evaluate(100));

        $ruleLt = Rule::fromArray([
            'key' => 'test_lt',
            'type' => 'int',
            'severity' => 'WARNING',
            'summary' => 'Test less than',
            'condition' => 'lt',
            'threshold' => 50,
        ]);

        $this->assertTrue($ruleLt->evaluate(10));
        $this->assertFalse($ruleLt->evaluate(50));
        $this->assertFalse($ruleLt->evaluate(100));
    }

    public function testRuleStringEvaluation(): void
    {
        $rule = Rule::fromArray([
            'key' => 'test_str',
            'type' => 'string',
            'severity' => 'INFO',
            'summary' => 'Test string',
            'condition' => 'neq',
            'threshold' => 'ON',
        ]);

        $this->assertTrue($rule->evaluate('OFF'));
        $this->assertFalse($rule->evaluate('ON'));
    }

    public function testRuleContainsCondition(): void
    {
        $rule = Rule::fromArray([
            'key' => 'test_contains',
            'type' => 'string',
            'severity' => 'INFO',
            'summary' => 'Test contains',
            'condition' => 'contains',
            'threshold' => 'MariaDB',
        ]);

        $this->assertTrue($rule->evaluate('10.5.18-MariaDB-log'));
        $this->assertFalse($rule->evaluate('8.0.32'));
    }

    public function testRuleRegexCondition(): void
    {
        $rule = Rule::fromArray([
            'key' => 'test_regex',
            'type' => 'string',
            'severity' => 'INFO',
            'summary' => 'Test regex',
            'condition' => 'regex',
            'threshold' => '/^5\.\d+/',
        ]);

        $this->assertTrue($rule->evaluate('5.7.42'));
        $this->assertFalse($rule->evaluate('8.0.32'));
    }
}
