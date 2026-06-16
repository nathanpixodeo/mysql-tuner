<?php

namespace MySQLTuner\Recommendation;

enum Severity: string
{
    case OK = 'OK';
    case WARNING = 'WARNING';
    case CRITICAL = 'CRITICAL';
    case INFO = 'INFO';

    public function weight(): int
    {
        return match ($this) {
            self::OK => 0,
            self::INFO => 1,
            self::WARNING => 3,
            self::CRITICAL => 5,
        };
    }
}
