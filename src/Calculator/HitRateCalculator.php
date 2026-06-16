<?php

namespace MySQLTuner\Calculator;

class HitRateCalculator
{
    public static function hitRate(int $reads, int $readRequests): ?float
    {
        if ($readRequests === 0) {
            return null;
        }
        return round((1 - $reads / max($readRequests, 1)) * 100, 2);
    }

    public static function innodbBufferPoolHitRate(int $poolReads, int $poolReadRequests): ?float
    {
        return self::hitRate($poolReads, $poolReadRequests);
    }

    public static function keyBufferHitRate(int $keyReads, int $keyReadRequests): ?float
    {
        return self::hitRate($keyReads, $keyReadRequests);
    }

    public static function queryCacheHitRate(int $hits, int $inserts, int $notCached): ?float
    {
        $total = $hits + $inserts + $notCached;
        if ($total === 0) {
            return null;
        }
        return round($hits / $total * 100, 2);
    }

    public static function threadCacheHitRate(int $threadsCreated, int $connections): ?float
    {
        return self::hitRate($threadsCreated, $connections);
    }

    public static function tableCacheHitRate(int $openedTables, int $openTableDefinitions): ?float
    {
        return self::hitRate($openedTables, $openTableDefinitions);
    }
}
