<?php

declare(strict_types=1);

namespace MySQLTuner\Database\Exception;

use RuntimeException;
use Throwable;

/**
 * A query did not complete. Subclasses narrow the reason so callers can react differently to
 * "you may not look at this" versus "this object no longer exists on this server version".
 */
class QueryFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $sql,
        public readonly ?int $driverCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
