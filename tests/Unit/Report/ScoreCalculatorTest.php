<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Report;

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Report\ScoreCalculator;
use PHPUnit\Framework\TestCase;

final class ScoreCalculatorTest extends TestCase
{
    public function testNoFindingsScoresOneHundred(): void
    {
        self::assertSame(100, ScoreCalculator::calculate([]));
    }

    public function testOnlyOkFindingsScoreOneHundred(): void
    {
        self::assertSame(100, ScoreCalculator::calculate([
            Recommendation::ok('a', 'fine'),
            Recommendation::ok('b', 'fine'),
        ]));
    }

    public function testASingleCriticalScoresZero(): void
    {
        self::assertSame(0, ScoreCalculator::calculate([
            Recommendation::critical('a', 'broken'),
        ]));
    }

    /**
     * The property that was broken: one CRITICAL scored 0, but the same CRITICAL next to one OK
     * scored 50, so the number moved with how many counters the server happened to populate.
     */
    public function testAddingOkFindingsDoesNotRaiseTheScore(): void
    {
        $withoutOk = [Recommendation::critical('a', 'broken')];
        $withOk = [
            Recommendation::critical('a', 'broken'),
            Recommendation::ok('b', 'fine'),
            Recommendation::ok('c', 'fine'),
            Recommendation::ok('d', 'fine'),
        ];

        self::assertSame(
            ScoreCalculator::calculate($withoutOk),
            ScoreCalculator::calculate($withOk),
        );
    }

    public function testWarningScoresBetterThanCritical(): void
    {
        $warning = ScoreCalculator::calculate([Recommendation::warn('a', 'meh')]);
        $critical = ScoreCalculator::calculate([Recommendation::critical('a', 'broken')]);

        self::assertGreaterThan($critical, $warning);
    }

    public function testInfoScoresBetterThanWarning(): void
    {
        $info = ScoreCalculator::calculate([Recommendation::info('a', 'fyi')]);
        $warning = ScoreCalculator::calculate([Recommendation::warn('a', 'meh')]);

        self::assertGreaterThan($warning, $info);
    }

    public function testScoreStaysWithinBounds(): void
    {
        $score = ScoreCalculator::calculate([
            Recommendation::critical('a', 'x'),
            Recommendation::critical('b', 'y'),
            Recommendation::info('c', 'z'),
        ]);

        self::assertGreaterThanOrEqual(0, $score);
        self::assertLessThanOrEqual(100, $score);
    }
}
