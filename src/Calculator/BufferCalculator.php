<?php

namespace MySQLTuner\Calculator;

class BufferCalculator
{
    public const BYTES_IN_GB = 1073741824;

    public static function recommendedInnodbPoolSize(?int $totalMemoryGb): int
    {
        if ($totalMemoryGb === null || $totalMemoryGb <= 0) {
            return 0;
        }

        $memory = $totalMemoryGb * self::BYTES_IN_GB;

        return (int) match (true) {
            $totalMemoryGb <= 1 => $memory / 4,
            $totalMemoryGb <= 4 => (int) ($memory * 0.5),
            $totalMemoryGb <= 8 => (int) ($memory * 0.6),
            $totalMemoryGb <= 32 => (int) ($memory * 0.7),
            default => (int) ($memory * 0.75),
        };
    }

    public static function recommendedKeyBufferSize(?int $totalMemoryGb): int
    {
        if ($totalMemoryGb === null || $totalMemoryGb <= 0) {
            return 0;
        }

        return (int) match (true) {
            $totalMemoryGb <= 1 => 32 * 1024 * 1024,
            $totalMemoryGb <= 4 => 64 * 1024 * 1024,
            $totalMemoryGb <= 8 => 128 * 1024 * 1024,
            $totalMemoryGb <= 32 => 256 * 1024 * 1024,
            default => 512 * 1024 * 1024,
        };
    }

    public static function recommendedTmpTableSize(?int $totalMemoryGb): int
    {
        if ($totalMemoryGb === null || $totalMemoryGb <= 0) {
            return 0;
        }

        return match (true) {
            $totalMemoryGb <= 1 => 32 * 1024 * 1024,
            $totalMemoryGb <= 4 => 64 * 1024 * 1024,
            default => 128 * 1024 * 1024,
        };
    }

    public static function recommendedMaxConnections(?int $totalMemoryGb): int
    {
        if ($totalMemoryGb === null || $totalMemoryGb <= 0) {
            return 100;
        }

        return match (true) {
            $totalMemoryGb <= 1 => 50,
            $totalMemoryGb <= 4 => 150,
            $totalMemoryGb <= 8 => 300,
            $totalMemoryGb <= 32 => 500,
            default => 1000,
        };
    }
}
