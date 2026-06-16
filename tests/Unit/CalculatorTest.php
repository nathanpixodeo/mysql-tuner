<?php

use MySQLTuner\Calculator\BufferCalculator;
use MySQLTuner\Calculator\HitRateCalculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function testInnodbBufferPoolHitRate(): void
    {
        $hitRate = HitRateCalculator::innodbBufferPoolHitRate(100, 10000);
        $this->assertSame(99.0, $hitRate);

        $hitRate = HitRateCalculator::innodbBufferPoolHitRate(5000, 10000);
        $this->assertSame(50.0, $hitRate);

        $this->assertNull(HitRateCalculator::innodbBufferPoolHitRate(0, 0));
    }

    public function testKeyBufferHitRate(): void
    {
        $hitRate = HitRateCalculator::keyBufferHitRate(50, 10000);
        $this->assertSame(99.5, $hitRate);
    }

    public function testThreadCacheHitRate(): void
    {
        $hitRate = HitRateCalculator::threadCacheHitRate(10, 1000);
        $this->assertSame(99.0, $hitRate);
    }

    public function testRecommendedInnodbPoolSize(): void
    {
        $size1Gb = BufferCalculator::recommendedInnodbPoolSize(1);
        $this->assertEqualsWithDelta(256 * 1024 * 1024, $size1Gb, 1);

        $size8Gb = BufferCalculator::recommendedInnodbPoolSize(8);
        $expected8 = (int) (8 * 1073741824 * 0.6);
        $this->assertEqualsWithDelta($expected8, $size8Gb, 1);

        $this->assertSame(0, BufferCalculator::recommendedInnodbPoolSize(null));
        $this->assertSame(0, BufferCalculator::recommendedInnodbPoolSize(0));
    }

    public function testRecommendedMaxConnections(): void
    {
        $this->assertSame(50, BufferCalculator::recommendedMaxConnections(1));
        $this->assertSame(150, BufferCalculator::recommendedMaxConnections(4));
        $this->assertSame(300, BufferCalculator::recommendedMaxConnections(8));
        $this->assertSame(1000, BufferCalculator::recommendedMaxConnections(64));
    }
}
