<?php

declare(strict_types=1);

namespace MySQLTuner\Database;

/**
 * Decides whether a connection reaches a server on this same machine.
 *
 * Kept pure and separate from PDO so it can be tested against the strings mysqlnd actually
 * produces, which differ between socket, TCP and named-pipe connections.
 */
final class ConnectionLocality
{
    /**
     * Interpret PDO::ATTR_CONNECTION_STATUS.
     *
     * Unknown strings are treated as remote. Suppressing memory advice on a local server is a
     * missing recommendation; emitting it for a remote server is a wrong one, sized from the
     * operator's laptop instead of the database host.
     */
    public static function isLocal(?string $connectionStatus): bool
    {
        if ($connectionStatus === null || trim($connectionStatus) === '') {
            return false;
        }

        $status = strtolower($connectionStatus);

        // "Localhost via UNIX socket", and Windows named pipes, are local by construction.
        if (str_contains($status, 'unix socket') || str_contains($status, 'named pipe')) {
            return true;
        }

        // "127.0.0.1 via TCP/IP" - a loopback address still means the same machine.
        foreach (['127.0.0.1', '::1', 'localhost'] as $loopback) {
            if (str_contains($status, $loopback)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide from a host string, for callers that know the target before connecting.
     */
    public static function isLocalHost(?string $host): bool
    {
        if ($host === null || trim($host) === '') {
            // No host means a socket connection was requested.
            return true;
        }

        return in_array(strtolower(trim($host)), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
