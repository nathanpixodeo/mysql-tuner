<?php

namespace MySQLTuner\Rules;

readonly class Rule
{
    public function __construct(
        public string $key,
        public string $type,
        public string $severity,
        public string $summary,
        public string $condition,
        public mixed  $threshold,
        public ?string $suggestion = null,
        public ?float  $maxPerGB = null,
        public ?int    $uptimeMin = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            key: $raw['key'],
            type: $raw['type'] ?? 'int',
            severity: $raw['severity'] ?? 'WARNING',
            summary: $raw['summary'],
            condition: $raw['condition'],
            threshold: $raw['threshold'],
            suggestion: $raw['suggestion'] ?? null,
            maxPerGB: $raw['max_per_gb'] ?? null,
            uptimeMin: $raw['uptime_min'] ?? null,
        );
    }

    public function evaluate(mixed $currentValue): bool
    {
        $value = match ($this->type) {
            'int' => (int) $currentValue,
            'float' => (float) $currentValue,
            'string' => (string) $currentValue,
            'bool' => filter_var($currentValue, FILTER_VALIDATE_BOOLEAN),
            default => $currentValue,
        };

        $threshold = match ($this->type) {
            'int' => (int) $this->threshold,
            'float' => (float) $this->threshold,
            default => $this->threshold,
        };

        return match ($this->condition) {
            'lt' => $value < $threshold,
            'gt' => $value > $threshold,
            'lte' => $value <= $threshold,
            'gte' => $value >= $threshold,
            'eq' => $value == $threshold,
            'neq' => $value != $threshold,
            'contains' => is_string($value) && is_string($threshold) && str_contains($value, $threshold),
            'not_contains' => is_string($value) && is_string($threshold) && !str_contains($value, $threshold),
            'regex' => is_string($value) && is_string($threshold) && preg_match($threshold, $value) === 1,
            default => false,
        };
    }
}
