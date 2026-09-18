# MySQL Tuner

A PHP-based MySQL / MariaDB performance analyzer that collects server metrics and provides actionable tuning recommendations. Think `MySQLTuner-perl`, but installable via Composer and usable as a library, not just a script.

> **Note on MariaDB:** version-specific rule selection (`MySQLTuner\Rules\RuleSet::parseMajorVersion()`) matches the leading `major.minor` of the version string first (e.g. a MariaDB `10.11.6-MariaDB` string resolves to `10.11`), looks for a `mysql-10.11.json` rule file, and — since no version-specific MariaDB rule file ships — falls back to the generic `mysql-default.json` rules. In other words, **MariaDB servers today are analyzed with the same generic rule set as MySQL**, not MariaDB-tuned thresholds. Dedicated MariaDB rule files are on the [Roadmap](#roadmap).

## Features

- **Hit Rate Analysis** — InnoDB buffer pool, MyISAM key buffer, thread cache hit rates
- **Memory Tuning** — Recommends `innodb_buffer_pool_size`, `max_connections` based on server RAM
- **Security Audit** — Anonymous users, empty passwords, test database, wildcard hosts, root empty password
- **Schema Analysis** — MyISAM → InnoDB migration warnings, table fragmentation, total data size
- **Replication Checks** — Slave IO/SQL thread status, replication lag
- **Version-Aware Rules** — Separate rule sets for MySQL 8.x, default, security (extensible via JSON)
- **Health Score** — 0–100 weighted score based on severity of all findings
- **Rich CLI** — Color-coded output, exit codes (0=OK, 1=warnings, 2=critical), JSON mode
- **Extensible** — Add custom metrics, collectors, or rule files without modifying core code

## Installation

### Via Composer (recommended)

```bash
composer require nathanpixodeo/mysql-tuner
```

### Manual (standalone)

```bash
git clone https://github.com/nathanpixodeo/mysql-tuner.git
cd mysql-tuner
composer install
```

## Required Privileges

MySQL Tuner is meant to run as a dedicated, low-privilege `monitor` user rather than `root`. That user needs the following grants:

```sql
CREATE USER 'monitor'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT ON *.* TO 'monitor'@'localhost';
GRANT PROCESS, REPLICATION CLIENT ON *.* TO 'monitor'@'localhost';
FLUSH PRIVILEGES;
```

What each grant is for:

| Grant | Used by |
|-------|---------|
| `SELECT ON *.*` | `SecurityCollector` (`mysql.user` audit: anonymous users, empty passwords, wildcard hosts, root password) and `SchemaCollector` (`information_schema.SCHEMATA` / `information_schema.TABLES` per-database stats) |
| `PROCESS` | Full visibility into `SHOW GLOBAL STATUS` / `SHOW GLOBAL VARIABLES` (`MySQLCollector`) |
| `REPLICATION CLIENT` | `SHOW SLAVE STATUS` (`MySQLCollector`, replication checks) |

`information_schema.ENGINES` (storage engine support) requires no extra grant.

**Warning:** collectors silently swallow permission errors and return empty/zero values rather than raising an error, so a `monitor` user missing these grants will produce a report with the Security and Schema sections silently empty instead of a warning that access was denied.

## Usage

### Basic

```bash
vendor/bin/mysql-tuner -u root -p 'your_password'
```

### Supplying the password

> **Security:** `-p 'secret'` puts the password in your shell history and in the process list,
> where any other user on the box can read it via `ps auxww` or `/proc/<pid>/cmdline`.
> Prefer one of the other three channels below on any shared or production host.

Resolved in this order, highest priority first:

| Channel | Example |
|---|---|
| `--password-file <path>` | `mysql-tuner -u monitor --password-file /etc/mysql-tuner.pw` |
| `--password-stdin` | `mysql-tuner -u monitor --password-stdin < /etc/mysql-tuner.pw` |
| `MYSQL_TUNER_PASSWORD` env var | `MYSQL_TUNER_PASSWORD=secret mysql-tuner -u monitor` |
| `-p` / `--password` | `mysql-tuner -u monitor -p 'secret'` |

A password file should contain the password on a single line; the trailing newline is stripped.
Restrict it with `chmod 600`.

### Remote host

```bash
vendor/bin/mysql-tuner -u monitor -p 'pass' -h db.example.com -P 3307
```

### Unix socket

```bash
vendor/bin/mysql-tuner -u root -p 'pass' -d /var/run/mysqld/mysqld.sock
```

### JSON output (for automation / monitoring)

```bash
vendor/bin/mysql-tuner -u root -p 'pass' --json | jq .
```

### No color (CI / logs)

```bash
vendor/bin/mysql-tuner -u root -p 'pass' --no-color
```

### Custom rules directory

```bash
vendor/bin/mysql-tuner -u root -p 'pass' --rules ./my-custom-rules
```

## Output

### Console (default)

Each recommendation is prefixed with a status glyph:

| Glyph | Severity | Meaning |
|-------|----------|---------|
| `[OK]` | OK | Value is within the recommended range |
| `[--]` | INFO | Informational note, no action required |
| `[!!]` | WARNING | Worth reviewing, not urgent |
| `[EE]` | CRITICAL | Should be addressed |

```
  MySQL Tuner Report
────────────────────────────────────────────────────────────
  Version: 8.0.32
  Uptime: 14d 6h 23m 12s
  Health Score: 72/100

  Security Issues
  [EE] 3 user(s) with empty password
       suggestion: SET PASSWORD FOR user@host = PASSWORD('strong_password');
  [EE] Root user has an empty password
       suggestion: ALTER USER root@localhost IDENTIFIED BY 'strong_password';

  Performance Recommendations
  connection:
    [!!] Max_used_connections (245) is close to max_connections (300)
         suggestion: Increase max_connections (recommended: 500)
         (current: 245 / 300)

  innodb:
    [OK] InnoDB buffer pool hit rate: 99.87%
    [!!] InnoDB buffer pool size may be too small
         suggestion: Consider increasing to 5.60 GB based on system memory (8GB)
         (current: 1.00 GB)
    [--] innodb_file_per_table is not enabled
         suggestion: Enable innodb_file_per_table for better space management

────────────────────────────────────────────────────────────
  Summary: 3 OK, 2 Info, 3 Warnings, 2 Critical | Score: 72/100
────────────────────────────────────────────────────────────
```

### JSON

```json
{
    "version": "8.0.32",
    "uptime": 1234567,
    "uptime_human": "14d 6h 23m 12s",
    "score": 72,
    "summary": {
        "ok": 3,
        "info": 2,
        "warnings": 3,
        "critical": 2,
        "total": 10
    },
    "recommendations": [
        {
            "metric": "empty_password_users",
            "severity": "CRITICAL",
            "summary": "3 user(s) with empty password",
            "current_value": "3",
            "suggestion": "SET PASSWORD FOR user@host = PASSWORD('strong_password');",
            "group": "security"
        }
    ]
}
```

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | No issues |
| 1 | Warnings found |
| 2 | Critical issues found |
| 3 | Error — connection failed, invalid or unknown arguments, unreadable rules file |

Invalid input is rejected rather than ignored: an unknown option, a leftover positional argument,
a non-numeric `--port`, a `--host` containing `;` or `=`, or a missing `--socket` each print a
one-line message to stderr and exit 3. Running with no arguments prints usage and exits 3.

## Architecture

```
src/
├── Analyzer.php                 # Main orchestrator
├── Calculator/
│   ├── BufferCalculator.php     # RAM-based buffer/connection recommendations
│   └── HitRateCalculator.php    # Hit rate percentage calculations
├── Collector/
│   ├── CollectorInterface.php   # Contract for all collectors
│   ├── MySQLCollector.php       # SHOW STATUS, VARIABLES, engines, slave
│   ├── SchemaCollector.php      # information_schema analysis
│   ├── SecurityCollector.php    # mysql.user audit
│   └── SystemCollector.php      # /proc/meminfo, cpuinfo, loadavg, disk
├── Recommendation/
│   ├── Recommendation.php       # Value object with factory methods
│   └── Severity.php             # Enum: OK / INFO / WARNING / CRITICAL
├── Report/
│   ├── ConsoleFormatter.php     # Colorized CLI output
│   ├── JsonFormatter.php        # Machine-readable output
│   └── Report.php               # Aggregated result object
└── Rules/
    ├── Rule.php                 # Single rule with condition evaluation
    └── RuleSet.php              # Load & merge rules from JSON files

rules/
├── mysql-default.json           # Common rules for all versions
├── mysql-8.0.json              # MySQL 8.x specific rules
└── security.json                # Security-related rules

tests/
└── Unit/
    ├── CalculatorTest.php
    ├── ConsoleFormatterTest.php
    ├── RecommendationTest.php
    └── RuleTest.php
```

## Writing Custom Rules

Rules are defined as JSON files in `rules/`. Each file contains groups, and each group contains an array of rule objects.

```json
{
    "innodb": [
        {
            "key": "innodb_log_file_size",
            "type": "int",
            "severity": "INFO",
            "summary": "InnoDB redo log size",
            "condition": "lt",
            "threshold": 536870912,
            "suggestion": "Set innodb_log_file_size to at least 512MB"
        }
    ]
}
```

### Rule Fields

| Field | Required | Description |
|-------|----------|-------------|
| `key` | Yes | MySQL variable/status name matching `SHOW GLOBAL STATUS` or `SHOW GLOBAL VARIABLES` |
| `type` | Yes | `int`, `float`, `string`, `bool` |
| `severity` | Yes | `OK`, `INFO`, `WARNING`, `CRITICAL` |
| `summary` | Yes | Human-readable message |
| `condition` | Yes | `lt`, `gt`, `lte`, `gte`, `eq`, `neq`, `contains`, `not_contains`, `regex` |
| `threshold` | Yes | The value to compare against |
| `suggestion` | No | Recommendation text |

### Version Loading Priority

1. `mysql-{major}.{minor}.json` (e.g. `mysql-8.0.json`)
2. `mysql-default.json`
3. `security.json`

Later files do not override earlier rules with the same `key` — first match wins.

## Development

```bash
# Run tests
composer test

# Static analysis
vendor/bin/phpstan analyse

# Validate composer.json
composer validate --strict
```

## Requirements

- PHP 8.2+ (the package uses `readonly class`, which is 8.2 and later)
- `ext-pdo` + `ext-pdo_mysql`
- `ext-json`

## CI

GitHub Actions runs on push/PR to `main`, in three jobs:

| Job | What it does |
|---|---|
| `static` | `composer validate --strict`, syntax lint over `src/ bin/ tests/`, PHPStan (level 6, blocking) |
| `tests` | PHPUnit on PHP 8.2 / 8.3 / 8.4 (Ubuntu) plus PHP 8.3 on Windows, with warnings, deprecations, notices and risky tests all treated as failures |
| `package-smoke` | Installs the package into a throwaway consumer project via a `path` repository with `symlink: false`, then executes `vendor/bin/mysql-tuner` — this reproduces the real installed-dependency layout and guards the Composer autoloader resolution |

## Roadmap

- [ ] Percona Server / MariaDB 10.x/11.x specific rules
- [ ] `information_schema` index analysis (duplicate/missing indexes)
- [ ] Galera Cluster health checks
- [ ] Performance schema integration
- [ ] Prometheus/OpenMetrics exporter mode

## License

MIT
