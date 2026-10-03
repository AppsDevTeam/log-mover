# ADT Log Mover

Moves log tables from the application database into a separate log storage
(typically TimescaleDB) and prints the SQL that creates that storage from your
Doctrine entities.

```
composer require adt/log-mover
```

## Why

Log tables in the application are only a transfer station. Logs are kept far longer
than it makes sense to burden the operational database with, and an audit trail must
moreover live **elsewhere than the system it reports on** — whoever gets into the
application must not be able to rewrite the records of what they did there.

- **A record carries its id.** It can be traced back by it, the mover recognises by it
  what has already been moved, and references between moved tables
  (`request_log_body.request_log_id`) keep pointing at the right row.
- **Write first, delete second.** Only what is provably in the target is deleted from
  the source. When the write fails, nothing disappears. A run interrupted between the
  write and the delete is harmless: the next one hits the duplicate key
  (`ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY UPDATE id = id`) and skips it.
- **The target is never read.** The application needs only `INSERT` there — essential
  for an audit trail that must not be readable from the application.
- **Memory does not grow with table width.** Ids are read in batches, rows one by one;
  a log row can be megabytes.
- **Time goes over in UTC with an explicit offset**, so a `TIMESTAMPTZ` target column
  cannot misread it in its own zone.

## Configuration

```neon
extensions:
	logMover: ADT\LogMover\DI\LogMoverExtension

logMover:
	target: @nettrine.dbal.connections.logdb.connection
	tables:
		# a body before its header - see "Order" below
		- {entity: App\Model\Entities\RequestLogBody, hot: '1 month', retention: '1 month'}
		- {entity: App\Model\Entities\RequestLog, hot: '3 months', retention: '6 months'}
		- {entity: App\Model\Entities\AuditLog, hot: '3 months', retention: '13 months', readable: false}
		- {entity: App\Model\Entities\DeviceLog, table: device_log_archive}
```

| Key | |
|---|---|
| `target` | DBAL connection to the log storage. Required. |
| `entityManager` | Where the entity metadata comes from. Defaults to the autowired `EntityManagerInterface`; set it when you have several. |
| `source` | Source connection. Defaults to the entity manager's connection. Set it when your log entities are mapped in a different manager than the one owning the source tables — e.g. they are mapped in the log storage's manager so that the administration can read them from there. |
| `tables[].entity` | Log entity. Its table name is the source table. |
| `tables[].table` | Target table name, defaults to the source one. |
| `tables[].hot`, `retention` | Only for `log-mover:print-schema`: TimescaleDB compression boundary and retention. |
| `tables[].readable` | `false` = the application gets `INSERT` only in the target (audit trail). Default `true`. |

### Order

The list is moved top to bottom. Where tables are bound by `ON DELETE CASCADE` in the
source (`request_log_body` → `request_log`), put the **child first**: once the parent is
moved and deleted, the cascade takes its body with it. A narrow window remains — a body
written after its step whose parent makes it into the same run loses its payload (the
request metadata stays).

### A log row must be immutable

A move hits a table where a request record is written first and its response appended
later in the middle — the moved row can no longer be found in the source and cannot be
completed. Solve it on the write side (two separate rows linked by a correlation id),
not by postponing the move.

## Running

```bash
php bin/console log-mover:move              # --dry-run, --batch-size, --limit
```

In production run it from a queue every minute rather than from cron — `LogMover` is a
service:

```php
$result = $this->logMover->moveAll();
if ($result['errors']) {
	// fail the job so it is retried and somebody notices
	throw new RuntimeException(implode('; ', array_map(fn ($e) => $e->getMessage(), $result['errors'])));
}
```

An unavailable or broken table fails only its own row; the others are moved.

## Creating the target storage

```bash
php bin/console log-mover:print-schema
```

Prints SQL to run **by hand** — the application has no access to the target server, and
that is the whole point: the users and the database (passwords deliberately left out),
`CREATE TABLE` for each configured table, and on PostgreSQL a TimescaleDB hypertable with
compression and retention policies where `hot`/`retention` are set.

- **Two users.** The owner creates the schema and owns the retention policies; the
  application gets `INSERT` everywhere and `SELECT` only where `readable`. No `UPDATE`,
  `DELETE`, `DROP` or `ALTER` — only the retention policy deletes.
- **Derived from the entities**, so it does not drift from the source.
- **No foreign keys.** TimescaleDB does not allow one pointing at a hypertable, and a body
  may arrive before its header anyway.
- **The id is not generated** — the mover brings it.
- **Time is `TIMESTAMP(6) WITH TIME ZONE`.** DBAL would declare `TIMESTAMP(0)` and
  silently round away the milliseconds a request logger writes.
- **JSONB** where the entity mapping asks for it (`options: ['jsonb' => true]`).
- On a hypertable the primary key is `(id, created_at)` — TimescaleDB requires the
  partitioning column in every unique key.

## One target per source

Ids come from the source database, so two sources in one target table would collide.
Every project (and every stage) has its own target database.

## Tests

```
composer test
```
