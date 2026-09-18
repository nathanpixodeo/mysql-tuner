<?php

declare(strict_types=1);

namespace MySQLTuner\Rules;

/**
 * The comparisons a rule may use.
 *
 * An enum rather than a string so an unrecognised operator fails when the rule file is parsed.
 * Previously a typo fell through to `default => false`, which disabled the rule permanently and
 * looked exactly like a check that passed.
 */
enum Condition: string
{
    case LT = 'lt';
    case GT = 'gt';
    case LTE = 'lte';
    case GTE = 'gte';
    case EQ = 'eq';
    case NEQ = 'neq';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case REGEX = 'regex';

    /** Operators that only make sense against a string value. */
    public function isStringOnly(): bool
    {
        return match ($this) {
            self::CONTAINS, self::NOT_CONTAINS, self::REGEX => true,
            default => false,
        };
    }
}
