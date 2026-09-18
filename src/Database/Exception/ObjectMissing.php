<?php

declare(strict_types=1);

namespace MySQLTuner\Database\Exception;

/**
 * A table or column the query referenced does not exist on this server.
 *
 * This is how a version difference surfaces: mysql.user.password was dropped in MySQL 5.7.6, and
 * SHOW SLAVE STATUS was removed in MySQL 8.4. Both are expected on modern servers and must lead to
 * a fallback or an explicit "not checked", never to a silent zero.
 */
final class ObjectMissing extends QueryFailed
{
}
