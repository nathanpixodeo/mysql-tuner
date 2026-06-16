<?php

namespace MySQLTuner\Report;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;

class Report
{
    /** @param list<Recommendation> $recommendations @param array<string, mixed> $systemMetrics */
    public function __construct(
        public readonly array  $recommendations,
        public readonly string $version,
        public readonly int    $uptime,
        public readonly int    $score,
        public readonly array  $systemMetrics = [],
    ) {}

    public function countBySeverity(Severity $severity): int
    {
        return count(array_filter(
            $this->recommendations,
            fn(Recommendation $r) => $r->severity === $severity,
        ));
    }

    public function criticalCount(): int
    {
        return $this->countBySeverity(Severity::CRITICAL);
    }

    public function warningCount(): int
    {
        return $this->countBySeverity(Severity::WARNING);
    }

    public function okCount(): int
    {
        return $this->countBySeverity(Severity::OK);
    }

    public function infoCount(): int
    {
        return $this->countBySeverity(Severity::INFO);
    }

    /** @return list<Recommendation> */
    public function byGroup(string $group): array
    {
        return array_values(array_filter(
            $this->recommendations,
            fn(Recommendation $r) => $r->group === $group,
        ));
    }

    /** @return array<string, list<Recommendation>> */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->recommendations as $rec) {
            $group = $rec->group ?? 'general';
            $groups[$group][] = $rec;
        }
        return $groups;
    }
}
