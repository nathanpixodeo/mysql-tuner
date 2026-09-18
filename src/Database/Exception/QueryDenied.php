<?php

declare(strict_types=1);

namespace MySQLTuner\Database\Exception;

/**
 * The server refused the query for lack of privileges.
 *
 * Reported rather than swallowed: a monitoring account without SELECT on mysql.user must not
 * produce a report that looks identical to a server with nothing to find.
 */
final class QueryDenied extends QueryFailed
{
}
