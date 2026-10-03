# CLAUDE.md

Guidance for Claude Code (claude.ai/code) when working in this repository.

## What this is

`adt/log-mover` is a PHP library (PHP >=8.4) that moves log tables from the application database into a separate log storage (typically TimescaleDB), keeping the source ids, and prints the target schema derived from Doctrine entities. It was extracted from `adt/fancyadmin` (`ADT\FancyAdmin\Model\Log\LogMover`, `fancyadmin:move-logs`, `fancyadmin:print-log-schema`) and must not depend on fancyadmin.

User-facing documentation is in `README.md`.

## Conventions

- **Everything is in English** - code comments, docblocks, README, test names, test data, command output and the printed SQL comments. Commit messages follow the ADT convention and are in Czech.
- **Tests use PHPUnit, not Nette Tester**, same as `adt/request-logger` - so the package can later move to Codeception with a mechanical change.
- Tests are `TestCase` classes in `tests/` (namespace `ADT\LogMover\Tests`), fixtures one class per file in `tests/Fixtures/`, loaded via `autoload-dev`.

## Commands

```bash
composer install
composer test                                          # vendor/bin/phpunit
vendor/bin/phpunit --filter testNothingDisappearsFromSourceWhenTheWriteFails
```

Tests need no database server - order-of-operations tests use stub connections, the end-to-end test and the DI tests use SQLite (`pdo_sqlite`).

## Things that are easy to break

- **Order of operations.** Write to the target in a transaction, commit, only then delete from the source - and only the ids of that batch. Never delete on failure.
- **Duplicates are targeted explicitly.** `ON CONFLICT DO NOTHING` (PostgreSQL, SQLite) / `ON DUPLICATE KEY UPDATE id = id` (MySQL). Never `INSERT IGNORE` - it swallows truncation and type errors too, and the row would then be deleted from the source without being in the target.
- **The target is never read.** Applications may have INSERT-only privileges there (audit trail).
- **Ids first, rows one by one.** Reading a whole batch with `SELECT *` overflowed the memory of queue consumers on wide log rows.
- **`created_at` gets `+00:00`.** Sources write UTC without a zone; a `TIMESTAMPTZ` target would otherwise read it in its own zone.
- **Printed time precision.** DBAL declares PostgreSQL timestamps as `TIMESTAMP(0)`; the schema printout overrides it with a full column definition (`TIMESTAMP(6) WITH TIME ZONE`), which means it has to add `NOT NULL` itself.
- **One target per source.** Ids come from the source; the printout and README say so - keep it that way.
- **Queue integration is optional.** `adt/background-queue` is only `suggest`/`require-dev`; the extension references its class by name and registers `log-mover:schedule` in `loadConfiguration` (contributte/console collects commands in its own `beforeCompile`, which may run first), while the queue callback is injected into the `BackgroundQueue` service's `config` argument in `beforeCompile`.
