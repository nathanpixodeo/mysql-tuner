# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-09-18

The 1.0.x releases could not run in their own documented configuration and reported a clean security
audit on every MySQL 8 server. Both are fixed here.

### Security

- **The empty-password check never ran on MySQL 5.7.6 or later.** `SecurityCollector` queried
  `mysql.user WHERE authentication_string = '' OR password = ''`, but the `password` column was
  dropped in 5.7.6. The statement threw errno 1054 on every modern server, the error was swallowed
  into a `-1` sentinel, and `Analyzer` tested `> 0` — so the finding was silently discarded. Each
  credential column is now probed separately and a missing one is skipped rather than failing the
  whole check. Verified against MySQL 9.1.0: a server with an empty-password user now reports it.
- **A refused probe is no longer reported as a clean result.** All five security probes returned
  `-1` on `PDOException`, which read as "nothing found". They now return `null` and the analyzer
  emits a WARNING naming the missing grant, so a least-privilege monitoring account — the setup the
  README recommends — can no longer produce a report that looks audited when it was not.

### Fixed

- **The CLI could not run when installed as a Composer dependency.** `bin/mysql-tuner` resolved the
  autoloader through a single hardcoded `__DIR__ . '/../vendor/autoload.php'`, which does not exist
  in an installed package, so `vendor/bin/mysql-tuner` died with a fatal error and exit code 255.
- **The package declared support for PHP 8.1 but could not parse on it.** Two classes use
  `readonly class`, which is PHP 8.2 and later. The floor is now `>=8.2`.
- `ext-pdo_mysql` is now a declared requirement. Composer previously installed the package cleanly
  onto hosts that had no MySQL driver.
- Security findings were rendered twice: once in the "Security Issues" section and again by the
  generic per-group loop, which had no skip for the group it had just printed.
- `analyze()` was not idempotent. `RuleSet::loadForVersion()` appended to a persistent array, so a
  second call on the same instance duplicated every rule and changed the health score.
- A rule declaring `uptime_min` was skipped unconditionally instead of being skipped only while the
  server had not been up long enough, so such a rule could never fire.
- `Recommendation::info()` took its arguments in a different order from `warn()` and `critical()`,
  and two call sites used the sibling order — rendering advice text in the "current value" slot. All
  four factories now share one signature.
- The health score counted zero-weight OK findings in its denominator, so it moved with how many
  counters a server happened to populate and was not comparable between runs.
- Cache hit rates could be negative on a freshly started server, printing
  `Thread cache hit rate: -100%`. Rates are clamped, and counter-derived advice is now withheld
  entirely below one hour of uptime with an explanation instead.
- CPU core detection reported one core fewer than the machine had: `/proc/cpuinfo` was counted with
  `substr_count("\nprocessor\t:")`, which misses the first entry and assumes a tab.
- `shell_exec('nproc 2>/dev/null')` ran outside the Linux guard, spawning a doomed `cmd.exe` on
  Windows on every run and writing to stderr. It has been removed.
- `JsonFormatter::format()` could return `false` from `json_encode` against a declared `string`
  return type when a server value contained invalid UTF-8.
- `SHOW SLAVE STATUS` was removed in MySQL 8.4; replication checks silently vanished there. The
  collector now tries `SHOW REPLICA STATUS` first and normalises the column names.
- `--version` and the help banner reported `v1.0.0` on a `v1.0.1` release.
- Removed six rules targeting server variables that no longer exist and so could never match:
  `query_cache_type`, `query_cache_size` (removed in MySQL 8.0.3, with the whole `query_cache`
  group), `old_passwords` (5.7.5), `have_ssl` (8.0.26), `validate_password_policy` (renamed in 8.0)
  and `expire_logs_days` (removed in 8.0).
- Corrected two `?int` return types in `SchemaCollector` that never return null.

### Added

- A `QueryRunner` seam (`src/Database/`) that classifies a failed query as denied, missing, or
  broken. This is what makes "could not check" distinguishable from "nothing to report".
- Remote-server detection. System metrics are read from the local `/proc`, so against a remote host
  they are withheld and an explanatory INFO is emitted, instead of sizing `innodb_buffer_pool_size`
  from the operator's own machine. Disk usage now measures the server's `datadir` rather than this
  process's temp directory.
- `--password-file <path>`, `--password-stdin` and the `MYSQL_TUNER_PASSWORD` environment variable,
  so the password need not appear in `ps` output or shell history. Precedence: file, stdin,
  environment, then `-p`.
- `-V` as a short alias for `--version`. `-h` is bound to `--host`, so there is no short flag for
  `--help`; the help text now says so.
- A `Condition` enum, so an unrecognised operator, severity, type or malformed regex is rejected
  when the rule file is parsed rather than silently disabling the rule forever.
- `declare(strict_types=1)` across the codebase.
- A connect timeout, so an unreachable host fails fast.
- System metrics in the JSON output, which previously disagreed with the console rendering.
- `LICENSE` (MIT), `CHANGELOG.md`, and a `.gitattributes` with `export-ignore` and `eol=lf`.
- A "Required Privileges" section in the README with the `GRANT` statements each collector needs.

### Changed

- Invalid input is rejected instead of ignored: unknown options, stray positional arguments, a
  non-numeric `--port`, a `--host` containing `;` or `=` (a DSN-injection vector), a missing
  `--socket`, and repeated options each exit 3 with a one-line message. Running with no arguments
  prints usage and exits 3 rather than silently connecting to `root@127.0.0.1` with an empty
  password.
- The top-level handler catches `Throwable`, so a malformed rules file reports a clean error rather
  than an uncaught fatal and exit code 255. A rules directory containing no rule files is an error
  instead of yielding an empty, healthy-looking report.
- Colour output honours `NO_COLOR` and is disabled when stdout is not a terminal, so redirecting to
  a file no longer captures raw escape sequences.
- The test suite went from 18 tests to 292, covering the analyzer, all four collectors, the rule
  loader and every shipped rule file. PHPStan runs at level 6 over `src/`, `tests/` and the binary
  with no suppressions and no baseline.
- CI is split into `static`, `tests` and `package-smoke`. The lint step now actually fails on a
  syntax error (`find -exec` swallowed the exit status and never matched the extensionless
  `bin/mysql-tuner`), PHPStan is no longer forced green with `|| echo`, the matrix is PHP
  8.2/8.3/8.4 plus Windows, and `package-smoke` executes the binary from a real
  installed-dependency layout.

## [1.0.1] - 2026-06-17

### Fixed

- SchemaCollector produced doubly-quoted SQL identifiers because `PDO::quote()` output was wrapped
  in another pair of quotes.

## [1.0.0] - 2026-06-17

### Added

- Initial release: MySQL/MariaDB metric collection, JSON-defined tuning rules, console and JSON
  report formatters.

[Unreleased]: https://github.com/nathanpixodeo/mysql-tuner/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/nathanpixodeo/mysql-tuner/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nathanpixodeo/mysql-tuner/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nathanpixodeo/mysql-tuner/releases/tag/v1.0.0
