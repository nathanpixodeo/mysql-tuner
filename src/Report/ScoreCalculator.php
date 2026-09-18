<?php

declare(strict_types=1);

namespace MySQLTuner\Report;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;

/**
 * The 0-100 health score.
 *
 * Extracted from Analyzer so it can be tested without a database, which is why it went unverified
 * while it was the single number users read first.
 */
final class ScoreCalculator
{
    /** @param list<Recommendation> $recommendations */
    public static function calculate(array $recommendations): int
    {
        $actionable = array_values(array_filter(
            $recommendations,
            // OK findings carry weight 0. Counting them in the denominator inflated the score by an
            // amount that varied with how many counters the server happened to populate, so two
            // runs against different servers were not comparable. One CRITICAL alone scored 0; the
            // same CRITICAL beside one OK scored 50.
            static fn(Recommendation $r): bool => $r->severity !== Severity::OK,
        ));

        if ($actionable === []) {
            return 100;
        }

        $totalWeight = array_sum(array_map(
            static fn(Recommendation $r): int => $r->severity->weight(),
            $actionable,
        ));

        $maxWeight = count($actionable) * Severity::CRITICAL->weight();

        return max(0, min(100, (int) round((1 - $totalWeight / $maxWeight) * 100)));
    }
}
