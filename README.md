# MySQL Tuner

A PHP-based MySQL / MariaDB performance analyzer that collects server metrics and provides actionable tuning recommendations. Think `MySQLTuner-perl`, but installable via Composer, fully featured, and production-ready.

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
composer require nathan-com/mysql-tuner
```

### Manual (standalone)

```bash
git clone https://github.com/nathan-com/mysql-tuner.git
cd mysql-tuner
composer install
```

## Usage

### Basic

```bash
vendor/bin/mysql-tuner -u root -p 'your_password'
```

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

```
  MySQL Tuner Report
────────────────────────────────────────────────────────────
  Version: 8.0.32
  Uptime: 14d 6h 23m 12s
  Health Score: 72/100

  Security Issues
  [EE] 3 user(s) with empty password
       suggestion: SET PASSWORD FOR user@host = PASSWORD('strong_password');
  [!!] Root user has an empty password
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
| 3 | Error (connection failed, etc.) |

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
    ├── RecommendationTest.php
    ├── CalculatorTest.php
    └── RuleTest.php
    └── ConsoleFormatterTest.php
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

- PHP 8.1+
- `ext-pdo` + `ext-pdo_mysql`
- `ext-json`

## CI

GitHub Actions runs on push/PR to `main`:
- PHP 8.1, 8.2, 8.3
- `composer validate --strict`
- PHP syntax check
- PHPUnit tests
- PHPStan analysis

## Roadmap

- [ ] Percona Server / MariaDB 10.x/11.x specific rules
- [ ] `information_schema` index analysis (duplicate/missing indexes)
- [ ] Galera Cluster health checks
- [ ] Performance schema integration
- [ ] Prometheus/OpenMetrics exporter mode

## License

MIT
