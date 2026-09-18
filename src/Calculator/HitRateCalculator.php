<?php

declare(strict_types=1);

namespace MySQLTuner\Calculator;

class HitRateCalculator
{
    /**
     * Percentage of requests served without falling through to the slower path.
     *
     * Clamped to 0-100. On a freshly started server the counters are not yet consistent with each
     * other - Threads_created counts background threads created before the first client connection
     * is recorded - so the raw formula could report a hit rate of -100%.
     */
    public static function hitRate(int $reads, int $readRequests): ?float
    {
        if ($readRequests <= 0) {
            return null;
        }

        $rate = (1 - $reads / $readRequests) * 100;

        return round(max(0.0, min(100.0, $rate)), 2);
    }

    public static function innodbBufferPoolHitRate(int $poolReads, int $poolReadRequests): ?float
    {
        return self::hitRate($poolReads, $poolReadRequests);
    }

    public static function keyBufferHitRate(int $keyReads, int $keyReadRequests): ?float
    {
        return self::hitRate($keyReads, $keyReadRequests);
    }

    public static function threadCacheHitRate(int $threadsCreated, int $connections): ?float
    {
        return self::hitRate($threadsCreated, $connections);
    }

    /**
     * Query cache efficiency.
     *
     * Retained for callers analysing MySQL 5.7 and earlier; the query cache was removed in
     * MySQL 8.0.3.
     */
    public static function queryCacheHitRate(int $hits, int $inserts, int $notCached): ?float
    {
        $total = $hits + $inserts + $notCached;

        if ($total <= 0) {
            return null;
        }

        return round($hits / $total * 100, 2);
    }
}
