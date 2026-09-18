<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Collector;

use MySQLTuner\Collector\SecurityCollector;
use MySQLTuner\Tests\Support\FakeQueryRunner;
use PHPUnit\Framework\TestCase;

final class SecurityCollectorTest extends TestCase
{
    private function runner(): FakeQueryRunner
    {
        return (new FakeQueryRunner())
            ->whenMatches('/user = \'\'/', [['cnt' => 2]])
            ->whenMatches('/authentication_string = \'\'$|authentication_string = \'\'\z/', [['cnt' => 1]])
            ->whenMatches('/WHERE authentication_string = \'\'/', [['cnt' => 1]])
            ->whenMatches('/WHERE password = \'\'/', [['cnt' => 0]])
            ->whenMatches('/host = \'%\'/', [['cnt' => 3]])
            ->whenMatches('/SCHEMA_NAME = \'test\'/', [['SCHEMA_NAME' => 'test']])
            ->whenMatches('/user = \'root\'/', [['cnt' => 1]]);
    }

    public function testReadsCountsFromTheServer(): void
    {
        $result = (new SecurityCollector($this->runner()))->collect();

        self::assertSame(2, $result['anonymous_users']);
        self::assertSame(3, $result['users_without_host']);
        self::assertTrue($result['test_database_exists']);
        self::assertTrue($result['have_root_empty_password']);
        self::assertSame([], $result['checks_skipped']);
    }

    /**
     * The regression that mattered most: mysql.user.password was dropped in MySQL 5.7.6, so the
     * old single-statement query threw errno 1054 on every MySQL 8 server, was swallowed, and the
     * check reported clean.
     */
    public function testEmptyPasswordProbeStillWorksWhenThePasswordColumnIsMissing(): void
    {
        $runner = (new FakeQueryRunner())
            ->whenMatches('/user = \'\'/', [['cnt' => 0]])
            ->whenMatches('/WHERE authentication_string = \'\'/', [['cnt' => 4]])
            ->whenMatchesThrow('/WHERE password = \'\'/', FakeQueryRunner::missing())
            ->whenMatches('/host = \'%\'/', [['cnt' => 0]])
            ->whenMatches('/SCHEMA_NAME = \'test\'/', [])
            ->whenMatches('/user = \'root\'/', [['cnt' => 0]]);

        $result = (new SecurityCollector($runner))->collect();

        self::assertSame(4, $result['empty_password_users'], 'a missing legacy column must not hide the finding');
        self::assertArrayNotHasKey('empty_password_users', $result['checks_skipped']);
    }

    public function testDeniedProbeReturnsNullNotZeroAndNotMinusOne(): void
    {
        $runner = (new FakeQueryRunner())
            ->whenMatchesThrow('/mysql\.user/', FakeQueryRunner::denied())
            ->whenMatches('/SCHEMA_NAME = \'test\'/', []);

        $result = (new SecurityCollector($runner))->collect();

        self::assertNull($result['anonymous_users']);
        self::assertNull($result['users_without_host']);
        self::assertNull($result['have_root_empty_password']);
        self::assertNotSame(0, $result['anonymous_users']);
        self::assertNotSame(-1, $result['anonymous_users']);
    }

    public function testDenialIsRecordedWithItsReason(): void
    {
        $runner = (new FakeQueryRunner())
            ->whenMatchesThrow('/mysql\.user/', FakeQueryRunner::denied())
            ->whenMatches('/SCHEMA_NAME = \'test\'/', []);

        $skipped = (new SecurityCollector($runner))->collect()['checks_skipped'];

        self::assertArrayHasKey('anonymous_users', $skipped);
        self::assertStringContainsString('privileges', $skipped['anonymous_users']);
    }

    public function testBothCredentialColumnsMissingIsReportedAsUnknown(): void
    {
        $runner = (new FakeQueryRunner())
            ->whenMatches('/user = \'\'/', [['cnt' => 0]])
            ->whenMatchesThrow('/WHERE authentication_string = \'\'/', FakeQueryRunner::missing())
            ->whenMatchesThrow('/WHERE password = \'\'/', FakeQueryRunner::missing())
            ->whenMatches('/host = \'%\'/', [['cnt' => 0]])
            ->whenMatches('/SCHEMA_NAME = \'test\'/', [])
            ->whenMatches('/user = \'root\'/', [['cnt' => 0]]);

        $result = (new SecurityCollector($runner))->collect();

        self::assertNull($result['empty_password_users']);
        self::assertArrayHasKey('empty_password_users', $result['checks_skipped']);
    }

    public function testMissingTestDatabaseIsFalseNotNull(): void
    {
        $result = (new SecurityCollector(
            (new FakeQueryRunner())
                ->whenMatches('/user = \'\'/', [['cnt' => 0]])
                ->whenMatches('/WHERE authentication_string = \'\'/', [['cnt' => 0]])
                ->whenMatches('/WHERE password = \'\'/', [['cnt' => 0]])
                ->whenMatches('/host = \'%\'/', [['cnt' => 0]])
                ->whenMatches('/SCHEMA_NAME = \'test\'/', [])
                ->whenMatches('/user = \'root\'/', [['cnt' => 0]]),
        ))->collect();

        self::assertFalse($result['test_database_exists'], 'absent is a real answer, not an unknown');
    }
}
