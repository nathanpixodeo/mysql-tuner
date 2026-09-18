<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Rules;

use MySQLTuner\Rules\Condition;
use MySQLTuner\Rules\Rule;
use PHPUnit\Framework\TestCase;

/**
 * Validates every rule this package ships.
 *
 * Nothing used to load these 400-odd lines of JSON, so a typo shipped silently: an unrecognised
 * condition simply made the rule never fire, which is indistinguishable from a passing check.
 */
final class RuleFilesTest extends TestCase
{
    private const ALLOWED_FIELDS = [
        'key', 'type', 'severity', 'summary', 'condition', 'threshold',
        'suggestion', 'max_per_gb', 'uptime_min',
    ];

    private static function rulesDir(): string
    {
        return dirname(__DIR__, 3) . '/rules';
    }

    /** @return iterable<string, array{0: string}> */
    public static function ruleFileProvider(): iterable
    {
        foreach (glob(self::rulesDir() . '/*.json') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function ruleProvider(): iterable
    {
        foreach (glob(self::rulesDir() . '/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);

            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $group => $rules) {
                foreach ((array) $rules as $i => $raw) {
                    $key = is_array($raw) ? ($raw['key'] ?? '?') : '?';
                    // Name the case so a failure identifies the offending rule directly.
                    yield sprintf('%s %s[%d] %s', basename($path), $group, $i, (string) $key) => [$path, (array) $raw];
                }
            }
        }
    }

    public function testAtLeastOneRuleFileIsShipped(): void
    {
        self::assertNotEmpty(glob(self::rulesDir() . '/*.json') ?: []);
    }

    /** @dataProvider ruleFileProvider */
    public function testFileIsValidJsonMappingGroupsToLists(string $path): void
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($data);

        foreach ($data as $group => $rules) {
            self::assertIsString($group);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $group);
            self::assertIsArray($rules);
        }
    }

    /** @dataProvider ruleFileProvider */
    public function testRuleKeysAreUniqueWithinAFile(string $path): void
    {
        $data = (array) json_decode((string) file_get_contents($path), true);
        $keys = [];

        foreach ($data as $rules) {
            foreach ((array) $rules as $raw) {
                $keys[] = is_array($raw) ? (string) ($raw['key'] ?? '') : '';
            }
        }

        self::assertSame(array_values(array_unique($keys)), $keys);
    }

    /**
     * @dataProvider ruleProvider
     * @param array<string, mixed> $raw
     */
    public function testRuleParses(string $path, array $raw): void
    {
        $rule = Rule::fromArray($raw);

        self::assertNotSame('', $rule->key);
        self::assertNotSame('', $rule->summary);
    }

    /**
     * @dataProvider ruleProvider
     * @param array<string, mixed> $raw
     */
    public function testRuleUsesOnlyRecognisedFields(string $path, array $raw): void
    {
        // Catches a misspelled optional field such as "sugestion", which `?? null` would swallow.
        $unknown = array_diff(array_keys($raw), self::ALLOWED_FIELDS);

        self::assertSame([], array_values($unknown));
    }

    /**
     * @dataProvider ruleProvider
     * @param array<string, mixed> $raw
     */
    public function testStringOnlyConditionsUseTheStringType(string $path, array $raw): void
    {
        $condition = Condition::tryFrom((string) ($raw['condition'] ?? ''));

        self::assertNotNull($condition);

        if ($condition->isStringOnly()) {
            // With a numeric type these operators return false forever - a silently dead rule,
            // which is worse than a crash because nothing ever reports it.
            self::assertSame('string', $raw['type'] ?? 'int');
        }
    }

    /**
     * @dataProvider ruleProvider
     * @param array<string, mixed> $raw
     */
    public function testEvaluatingARuleAgainstItsOwnThresholdDoesNotError(string $path, array $raw): void
    {
        // The subject is that evaluation completes without throwing or emitting a warning;
        // `failOnWarning` in phpunit.xml.dist turns a bad regex or type mismatch into a failure.
        $this->expectNotToPerformAssertions();

        Rule::fromArray($raw)->evaluate($raw['threshold']);
    }

    /**
     * @dataProvider ruleProvider
     * @param array<string, mixed> $raw
     */
    public function testMaxPerGbIsNotUsedUntilItIsImplemented(string $path, array $raw): void
    {
        // Rule parses the field but nothing reads it. Documented here so a rule author does not
        // rely on behaviour that does not exist.
        self::assertArrayNotHasKey('max_per_gb', $raw);
    }
}
