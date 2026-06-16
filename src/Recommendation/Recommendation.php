<?php

namespace MySQLTuner\Recommendation;

readonly class Recommendation
{
    public function __construct(
        public string      $metric,
        public string      $summary,
        public Severity    $severity,
        public ?string     $currentValue = null,
        public ?string     $suggestion = null,
        public ?string     $detail = null,
        public ?string     $group = null,
    ) {}

    public static function ok(string $metric, string $summary, ?string $currentValue = null, ?string $group = null): self
    {
        return new self($metric, $summary, Severity::OK, $currentValue, group: $group);
    }

    public static function warn(string $metric, string $summary, ?string $suggestion = null, ?string $currentValue = null, ?string $group = null): self
    {
        return new self($metric, $summary, Severity::WARNING, $currentValue, $suggestion, group: $group);
    }

    public static function critical(string $metric, string $summary, ?string $suggestion = null, ?string $currentValue = null, ?string $group = null): self
    {
        return new self($metric, $summary, Severity::CRITICAL, $currentValue, $suggestion, group: $group);
    }

    public static function info(string $metric, string $summary, ?string $currentValue = null, ?string $detail = null, ?string $group = null): self
    {
        return new self($metric, $summary, Severity::INFO, $currentValue, detail: $detail, group: $group);
    }

    /** @return array<string, scalar|array|null> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'current_value' => $this->currentValue,
            'suggestion' => $this->suggestion,
            'detail' => $this->detail,
            'group' => $this->group,
        ];
    }
}
