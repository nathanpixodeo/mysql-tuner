<?php

declare(strict_types=1);

namespace MySQLTuner\Recommendation;

readonly class Recommendation
{
    public function __construct(
        public string $metric,
        public string $summary,
        public Severity $severity,
        public ?string $currentValue = null,
        public ?string $suggestion = null,
        public ?string $detail = null,
        public ?string $group = null,
    ) {}

    /**
     * The four factories below share one positional order:
     *
     *     (metric, summary, suggestion, currentValue, detail, group)
     *
     * `info()` used to take (metric, summary, currentValue, detail, group) instead, and two call
     * sites passed it arguments in the `warn()` order - so the advice text was rendered in the
     * "current value" slot. Keeping the signatures aligned removes the trap rather than fixing the
     * two call sites and waiting for a third.
     */
    public static function ok(
        string $metric,
        string $summary,
        ?string $suggestion = null,
        ?string $currentValue = null,
        ?string $detail = null,
        ?string $group = null,
    ): self {
        return new self($metric, $summary, Severity::OK, $currentValue, $suggestion, $detail, $group);
    }

    public static function info(
        string $metric,
        string $summary,
        ?string $suggestion = null,
        ?string $currentValue = null,
        ?string $detail = null,
        ?string $group = null,
    ): self {
        return new self($metric, $summary, Severity::INFO, $currentValue, $suggestion, $detail, $group);
    }

    public static function warn(
        string $metric,
        string $summary,
        ?string $suggestion = null,
        ?string $currentValue = null,
        ?string $detail = null,
        ?string $group = null,
    ): self {
        return new self($metric, $summary, Severity::WARNING, $currentValue, $suggestion, $detail, $group);
    }

    public static function critical(
        string $metric,
        string $summary,
        ?string $suggestion = null,
        ?string $currentValue = null,
        ?string $detail = null,
        ?string $group = null,
    ): self {
        return new self($metric, $summary, Severity::CRITICAL, $currentValue, $suggestion, $detail, $group);
    }

    /** @return array<string, scalar|null> */
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
