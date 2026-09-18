<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Rules;

use MySQLTuner\Rules\RuleSet;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuleSetTest extends TestCase
{
    private function rulesDir(): string
    {
        return dirname(__DIR__, 3) . '/rules';
    }

    public function testLoadsRulesForAKnownVersion(): void
    {
        $set = new RuleSet($this->rulesDir());
        $set->loadForVersion('8.0.36');

        self::assertNotEmpty($set->getRules());
        self::assertNotEmpty($set->getGroups());
    }

    /**
     * The rule map used to be appended to rather than rebuilt, so a second analyze() on the same
     * instance doubled every rule and changed the health score.
     */
    public function testLoadingTwiceDoesNotDuplicateRules(): void
    {
        $set = new RuleSet($this->rulesDir());

        $set->loadForVersion('8.0.36');
        $first = count($set->getRules());

        $set->loadForVersion('8.0.36');

        self::assertSame($first, count($set->getRules()));
    }

    public function testLoadingADifferentVersionReplacesRatherThanAccumulates(): void
    {
        $reused = new RuleSet($this->rulesDir());
        $reused->loadForVersion('8.0.36');
        $reused->loadForVersion('5.7.44');

        $fresh = new RuleSet($this->rulesDir());
        $fresh->loadForVersion('5.7.44');

        // Switching versions on one instance must leave exactly the same rule set as loading that
        // version on a new instance - no leftovers from the previous load.
        self::assertSame(count($fresh->getRules()), count($reused->getRules()));
        self::assertSame($fresh->getGroups(), $reused->getGroups());
    }

    public function testFlatCacheIsRebuiltAfterReload(): void
    {
        $set = new RuleSet($this->rulesDir());

        $set->loadForVersion('8.0.36');
        $before = $set->getRules();

        $set->loadForVersion('8.0.36');

        self::assertEquals($before, $set->getRules());
    }

    /**
     * MariaDB reports "10.11.6-MariaDB", which starts with digits, so it resolves through the
     * numeric branch and falls back to the generic rule set. Pinned so the fallback is a decision
     * rather than an accident.
     */
    public function testMariaDbVersionFallsBackToTheGenericRules(): void
    {
        $set = new RuleSet($this->rulesDir());
        $set->loadForVersion('10.11.6-MariaDB');

        self::assertNotEmpty($set->getRules());
    }

    public function testUnknownVersionStillLoadsTheGenericRules(): void
    {
        $set = new RuleSet($this->rulesDir());
        $set->loadForVersion('not-a-version');

        self::assertNotEmpty($set->getRules());
    }

    /**
     * A mistyped --rules directory used to yield zero rules and a report that looked like a clean
     * server.
     */
    public function testAnEmptyRulesDirectoryIsAnError(): void
    {
        $empty = sys_get_temp_dir() . '/mysql-tuner-empty-' . uniqid('', true);
        mkdir($empty);

        try {
            $this->expectException(RuntimeException::class);
            (new RuleSet($empty))->loadForVersion('8.0.36');
        } finally {
            rmdir($empty);
        }
    }

    public function testGroupIndexMapsEveryRuleKeyToItsGroup(): void
    {
        $set = new RuleSet($this->rulesDir());
        $set->loadForVersion('8.0.36');

        $index = $set->groupIndex();

        foreach ($set->getRules() as $rule) {
            self::assertArrayHasKey($rule->key, $index);
            self::assertContains($index[$rule->key], $set->getGroups());
        }
    }

    public function testNoGroupIsEmpty(): void
    {
        $set = new RuleSet($this->rulesDir());
        $set->loadForVersion('8.0.36');

        foreach ($set->getGroups() as $group) {
            self::assertNotEmpty($set->getRules($group), "group '{$group}' is empty");
        }
    }
}
