<?php

declare(strict_types=1);

namespace MySQLTuner\Rules;

use InvalidArgumentException;
use MySQLTuner\Recommendation\Severity;

readonly class Rule
{
    public const TYPES = ['int', 'float', 'string', 'bool'];

    public function __construct(
        public string $key,
        public string $type,
        public Severity $severity,
        public string $summary,
        public Condition $condition,
        public mixed $threshold,
        public ?string $suggestion = null,
        public ?float $maxPerGB = null,
        public ?int $uptimeMin = null,
    ) {}

    /**
     * @param array<string, mixed> $raw
     * @throws InvalidArgumentException when the rule is not usable
     */
    public static function fromArray(array $raw): self
    {
        foreach (['key', 'summary', 'condition', 'threshold'] as $required) {
            // Previously these were read unguarded, so a rule missing one threw a TypeError from
            // deep inside the constructor with no indication of which rule was at fault.
            if (!array_key_exists($required, $raw)) {
                throw new InvalidArgumentException("Rule is missing the required field '{$required}'.");
            }
        }

        $key = $raw['key'];
        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException("Rule field 'key' must be a non-empty string.");
        }

        $summary = $raw['summary'];
        if (!is_string($summary) || $summary === '') {
            throw new InvalidArgumentException("Rule '{$key}': 'summary' must be a non-empty string.");
        }

        $type = $raw['type'] ?? 'int';
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Rule '{$key}': unsupported type '" . (is_scalar($type) ? (string) $type : gettype($type))
                . "'. Expected one of: " . implode(', ', self::TYPES) . '.',
            );
        }

        $rawCondition = $raw['condition'];
        $condition = is_string($rawCondition) ? Condition::tryFrom($rawCondition) : null;
        if ($condition === null) {
            throw new InvalidArgumentException(
                "Rule '{$key}': unsupported condition '"
                . (is_scalar($rawCondition) ? (string) $rawCondition : gettype($rawCondition)) . "'.",
            );
        }

        $rawSeverity = $raw['severity'] ?? 'WARNING';
        $severity = is_string($rawSeverity) ? Severity::tryFrom($rawSeverity) : null;
        if ($severity === null) {
            throw new InvalidArgumentException(
                "Rule '{$key}': unsupported severity '"
                . (is_scalar($rawSeverity) ? (string) $rawSeverity : gettype($rawSeverity)) . "'.",
            );
        }

        if ($condition->isStringOnly() && $type !== 'string') {
            throw new InvalidArgumentException(
                "Rule '{$key}': condition '{$condition->value}' requires type 'string', got '{$type}'.",
            );
        }

        if ($condition === Condition::REGEX) {
            self::assertValidPattern($key, $raw['threshold']);
        }

        $suggestion = $raw['suggestion'] ?? null;
        if ($suggestion !== null && !is_string($suggestion)) {
            throw new InvalidArgumentException("Rule '{$key}': 'suggestion' must be a string when present.");
        }

        return new self(
            key: $key,
            type: $type,
            severity: $severity,
            summary: $summary,
            condition: $condition,
            threshold: $raw['threshold'],
            suggestion: $suggestion,
            maxPerGB: isset($raw['max_per_gb']) ? (float) $raw['max_per_gb'] : null,
            uptimeMin: isset($raw['uptime_min']) ? (int) $raw['uptime_min'] : null,
        );
    }

    public function evaluate(mixed $currentValue): bool
    {
        $value = $this->cast($currentValue);
        $threshold = $this->cast($this->threshold);

        return match ($this->condition) {
            Condition::LT => $value < $threshold,
            Condition::GT => $value > $threshold,
            Condition::LTE => $value <= $threshold,
            Condition::GTE => $value >= $threshold,
            // Strict: both operands have been cast to the rule's declared type, so there is no
            // reason to fall back on PHP's string-to-number juggling.
            Condition::EQ => $value === $threshold,
            Condition::NEQ => $value !== $threshold,
            Condition::CONTAINS => is_string($value) && is_string($threshold) && str_contains($value, $threshold),
            Condition::NOT_CONTAINS => is_string($value) && is_string($threshold) && !str_contains($value, $threshold),
            Condition::REGEX => is_string($value) && is_string($threshold) && preg_match($threshold, $value) === 1,
        };
    }

    /**
     * Coerce a value to the rule's declared type.
     *
     * Applied to the threshold as well as the current value. The threshold used to skip the 'bool'
     * arm, leaving a rule with `"type": "bool"` comparing a real boolean against the string "OFF",
     * which could never match.
     */
    private function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'int' => is_scalar($value) ? (int) $value : 0,
            'float' => is_scalar($value) ? (float) $value : 0.0,
            'string' => is_scalar($value) ? (string) $value : '',
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    private static function assertValidPattern(string $key, mixed $pattern): void
    {
        if (!is_string($pattern)) {
            throw new InvalidArgumentException("Rule '{$key}': a regex threshold must be a string.");
        }

        // A malformed pattern would otherwise emit a PHP warning at evaluation time and silently
        // evaluate false, so validate it while we still know which rule to blame.
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Rule '{$key}': '{$pattern}' is not a valid regular expression.");
        }
    }
}
