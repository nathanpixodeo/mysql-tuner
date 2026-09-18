# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **The CLI could not run when installed as a Composer dependency.** `bin/mysql-tuner` resolved the
  autoloader through a single hardcoded `__DIR__ . '/../vendor/autoload.php'`, which does not exist
  in an installed package, so `vendor/bin/mysql-tuner` died with a fatal error and exit code 255.
  It now probes `$GLOBALS['_composer_autoload_path']`, the standalone-clone path and the installed
  dependency path, and exits 3 with a clear message if none resolve.
- **The package declared support for PHP 8.1 but could not parse on it.** Two classes use
  `readonly class`, which is PHP 8.2 and later. The floor is now `>=8.2`.
- `ext-pdo_mysql` is now a declared requirement. Composer previously installed the package cleanly
  onto hosts that had no MySQL driver and therefore could not run it.
- `--version` and the help banner reported `v1.0.0` on a `v1.0.1` release. Both now read one
  `MYSQL_TUNER_VERSION` constant.
- Removed six rules targeting server variables that no longer exist, so they silently never matched:
  `query_cache_type`, `query_cache_size` (removed in MySQL 8.0.3, and the whole `query_cache` group
  with them), `old_passwords` (5.7.5), `have_ssl` (8.0.26), `validate_password_policy` (renamed in
  8.0) and `expire_logs_days` (removed in 8.0).
- Dropped an unreachable error hint that matched on `ext-pdo`; a missing driver reports
  `could not find driver`.
- Corrected two `?int` return types in `SchemaCollector` that never return null.

### Added

- `--password-file <path>`, `--password-stdin` and the `MYSQL_TUNER_PASSWORD` environment variable,
  so the password no longer has to appear in the process list and shell history. Precedence is
  file, then stdin, then environment, then `-p`.
- `-V` as a short alias for `--version`. Note that `-h` is bound to `--host`, so there is no short
  flag for `--help`; the help text now says so.
- A connect timeout (`PDO::ATTR_TIMEOUT`), so an unreachable host fails fast instead of hanging on
  the TCP default.
- A "Required Privileges" section in the README with the exact `GRANT` statements each collector
  needs, and a warning that missing grants currently empty those sections rather than reporting an
  error.
- `LICENSE` (MIT), `CHANGELOG.md`, and a `.gitattributes` that keeps `tests/` and CI config out of
  the distributed archive.

### Changed

- Invalid input is rejected instead of ignored. Unknown options, leftover positional arguments, a
  non-numeric `--port`, a `--host` containing `;` or `=` (previously a DSN-injection vector), a
  missing `--socket`, and repeated options now each exit 3 with a one-line message. Running with no
  arguments prints usage and exits 3 rather than silently connecting to `root@127.0.0.1` with an
  empty password.
- The top-level handler catches `Throwable`, not just `PDOException`, so a malformed rules file
  reports a clean error instead of an uncaught fatal and exit code 255.
- CI is split into `static`, `tests` and `package-smoke` jobs. The lint step now actually fails on a
  syntax error (`find -exec` swallowed the exit status, and never matched the extensionless
  `bin/mysql-tuner`), PHPStan is no longer forced green with `|| echo`, the matrix is PHP 8.2/8.3/8.4
  plus Windows, and `package-smoke` executes the binary from a real installed-dependency layout.

## [1.0.1] - 2026-06-17

### Fixed

- SchemaCollector produced doubly-quoted SQL identifiers because `PDO::quote()` output was wrapped in another pair of quotes.

## [1.0.0] - 2026-06-17

### Added

- Initial release: MySQL/MariaDB metric collection, JSON-defined tuning rules, console and JSON report formatters.

[Unreleased]: https://github.com/nathanpixodeo/mysql-tuner/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/nathanpixodeo/mysql-tuner/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nathanpixodeo/mysql-tuner/releases/tag/v1.0.0
