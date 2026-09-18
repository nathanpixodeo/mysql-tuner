<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit;

use MySQLTuner\Calculator\BufferCalculator;
use MySQLTuner\Calculator\HitRateCalculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function testInnodbBufferPoolHitRate(): void
    {
        self::assertSame(99.0, HitRateCalculator::innodbBufferPoolHitRate(100, 10000));
        self::assertSame(50.0, HitRateCalculator::innodbBufferPoolHitRate(5000, 10000));
        self::assertNull(HitRateCalculator::innodbBufferPoolHitRate(0, 0));
    }

    public function testKeyBufferHitRate(): void
    {
        self::assertSame(99.5, HitRateCalculator::keyBufferHitRate(50, 10000));
    }

    public function testThreadCacheHitRate(): void
    {
        self::assertSame(99.0, HitRateCalculator::threadCacheHitRate(10, 1000));
    }

    public function testHitRateIsNullWithoutRequests(): void
    {
        self::assertNull(HitRateCalculator::hitRate(5, 0));
        self::assertNull(HitRateCalculator::hitRate(0, -1));
    }

    public function testPerfectHitRateIsOneHundred(): void
    {
        self::assertSame(100.0, HitRateCalculator::hitRate(0, 1000));
    }

    /**
     * On a freshly started server Threads_created counts background threads created before the
     * first client connection is recorded, so the raw formula went negative and the report showed
     * "Thread cache hit rate: -100%".
     */
    public function testHitRateIsClampedWhenReadsExceedRequests(): void
    {
        self::assertSame(0.0, HitRateCalculator::hitRate(2, 1));
        self::assertSame(0.0, HitRateCalculator::threadCacheHitRate(151, 3));
    }

    public function testQueryCacheHitRate(): void
    {
        self::assertSame(50.0, HitRateCalculator::queryCacheHitRate(50, 25, 25));
        self::assertNull(HitRateCalculator::queryCacheHitRate(0, 0, 0));
    }

    public function testRecommendedInnodbPoolSize(): void
    {
        self::assertEqualsWithDelta(256 * 1024 * 1024, BufferCalculator::recommendedInnodbPoolSize(1), 1);
        self::assertEqualsWithDelta((int) (8 * 1073741824 * 0.6), BufferCalculator::recommendedInnodbPoolSize(8), 1);
        self::assertSame(0, BufferCalculator::recommendedInnodbPoolSize(null));
        self::assertSame(0, BufferCalculator::recommendedInnodbPoolSize(0));
    }

    public function testRecommendedMaxConnections(): void
    {
        self::assertSame(50, BufferCalculator::recommendedMaxConnections(1));
        self::assertSame(150, BufferCalculator::recommendedMaxConnections(4));
        self::assertSame(300, BufferCalculator::recommendedMaxConnections(8));
        self::assertSame(1000, BufferCalculator::recommendedMaxConnections(64));
        self::assertSame(100, BufferCalculator::recommendedMaxConnections(null));
    }

    public function testRecommendedKeyBufferAndTmpTableSize(): void
    {
        self::assertSame(32 * 1024 * 1024, BufferCalculator::recommendedKeyBufferSize(1));
        self::assertSame(512 * 1024 * 1024, BufferCalculator::recommendedKeyBufferSize(64));
        self::assertSame(0, BufferCalculator::recommendedKeyBufferSize(null));

        self::assertSame(32 * 1024 * 1024, BufferCalculator::recommendedTmpTableSize(1));
        self::assertSame(128 * 1024 * 1024, BufferCalculator::recommendedTmpTableSize(64));
        self::assertSame(0, BufferCalculator::recommendedTmpTableSize(null));
    }
}
