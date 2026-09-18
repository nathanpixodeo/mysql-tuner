<?php

declare(strict_types=1);

namespace MySQLTuner\Tests\Unit\Database;

use MySQLTuner\Database\ConnectionLocality;
use PHPUnit\Framework\TestCase;

final class ConnectionLocalityTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: bool}> */
    public static function statusProvider(): iterable
    {
        yield 'unix socket' => ['Localhost via UNIX socket', true];
        yield 'loopback tcp' => ['127.0.0.1 via TCP/IP', true];
        yield 'ipv6 loopback' => ['::1 via TCP/IP', true];
        yield 'named pipe' => ['Named pipe', true];
        yield 'remote tcp' => ['db.example.com via TCP/IP', false];
        yield 'remote ip' => ['10.0.0.7 via TCP/IP', false];
        yield 'unknown' => [null, false];
        yield 'empty' => ['', false];
    }

    /** @dataProvider statusProvider */
    public function testInterpretsConnectionStatus(?string $status, bool $expected): void
    {
        self::assertSame($expected, ConnectionLocality::isLocal($status));
    }

    /**
     * Unknown must mean remote: suppressing advice on a local server costs a recommendation,
     * whereas emitting it for a remote server produces a wrong one.
     */
    public function testUnrecognisedStatusIsTreatedAsRemote(): void
    {
        self::assertFalse(ConnectionLocality::isLocal('something nobody has seen before'));
    }

    public function testHostShortcut(): void
    {
        self::assertTrue(ConnectionLocality::isLocalHost('localhost'));
        self::assertTrue(ConnectionLocality::isLocalHost('127.0.0.1'));
        self::assertTrue(ConnectionLocality::isLocalHost(null));
        self::assertFalse(ConnectionLocality::isLocalHost('db.example.com'));
    }
}
