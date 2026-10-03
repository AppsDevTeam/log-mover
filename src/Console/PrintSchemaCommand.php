<?php

declare(strict_types=1);

namespace ADT\LogMover\Console;

use ADT\LogMover\LogMover;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the SQL that creates the target log storage (see log-mover:move).
 *
 * The schema is derived from the entities, so it does not drift from the source - a column
 * added to a log shows up in the next printout too. Hand-written SQL next to the entities
 * drifts and nobody notices until the move fails on an unknown column.
 *
 * IT IS NOT A MIGRATION. The printout is a script someone reads and runs by hand on a server
 * the application has no access to - which is the whole point of a separate storage. Creating
 * the users and the database is part of it for the same reason, even though the application
 * cannot do it itself.
 *
 * @phpstan-import-type TableConfig from LogMover
 */
#[AsCommand(name: 'log-mover:print-schema', description: 'Prints SQL that creates the target log storage')]
class PrintSchemaCommand extends Command
{
	/**
	 * @param list<TableConfig> $config
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly Connection $targetConnection,
		private readonly array $config,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$platform = $this->targetConnection->getDatabasePlatform();
		$isPostgres = $platform instanceof PostgreSQLPlatform;

		$output->writeln($this->header());
		$output->writeln($this->createDatabase($isPostgres));

		foreach ($this->config as $_entry) {
			$meta = $this->em->getClassMetadata($_entry['entity']);
			$targetTable = ($_entry['table'] ?? null) ?? $meta->getTableName();
			$timescale = $isPostgres && (($_entry['hot'] ?? null) !== null || ($_entry['retention'] ?? null) !== null);

			$schema = new Schema();
			$this->buildTable($meta, $schema->createTable($targetTable), $timescale, $isPostgres);

			$output->writeln('');
			$output->writeln('-- ' . $targetTable);
			foreach ($schema->toSql($platform) as $_sql) {
				$output->writeln($_sql . ';');
			}

			if ($timescale) {
				$output->writeln($this->timescale($targetTable, $_entry['hot'] ?? null, $_entry['retention'] ?? null));
			}
		}

		$params = $this->targetConnection->getParams();
		$output->writeln($this->grants(
			$isPostgres,
			($params['dbname'] ?? '') ?: '<database>',
			($params['user'] ?? '') ?: '<user>',
		));

		return self::SUCCESS;
	}

	/**
	 * The table is built from the entity metadata, not from hand-written SQL - that would
	 * drift from the schema and nobody would notice until the move fails on an unknown column.
	 *
	 * FOREIGN KEYS ARE NOT CARRIED OVER: tables in the target are independent of each other
	 * and the move goes in batches, so a request body could arrive before its header and
	 * a foreign key would reject it. TimescaleDB does not even allow a foreign key pointing
	 * at a hypertable. The referencing column stays, only without enforcement.
	 *
	 * THE ID IS NOT GENERATED. In the source it is auto_increment, here it is not - the mover
	 * brings the value and recognises by it what it has already moved. If the target assigned
	 * it itself, the link to the source would be lost and a repeated run after an interruption
	 * would write the same thing twice.
	 */
	private function buildTable(ClassMetadata $meta, Table $table, bool $timescale, bool $isPostgres): void
	{
		foreach ($meta->getFieldNames() as $_field) {
			$mapping = $meta->getFieldMapping($_field);
			$notNull = !($mapping['nullable'] ?? false);

			$table->addColumn($meta->getColumnName($_field), $this->resolveType($mapping['type'], $isPostgres), array_filter([
				'notnull' => $notNull,
				'length' => $mapping['length'] ?? null,
				'precision' => $mapping['precision'] ?? null,
				'scale' => $mapping['scale'] ?? null,
				'columnDefinition' => $this->resolveColumnDefinition($mapping['type'], $notNull, $isPostgres),
			], static fn ($value) => $value !== null));
		}

		// an owning toOne association is a column with an id in the source; only the id is left here
		foreach ($meta->getAssociationMappings() as $_association) {
			if (!($_association['isOwningSide'] ?? false) || !isset($_association['joinColumns'])) {
				continue;
			}

			foreach ($_association['joinColumns'] as $_joinColumn) {
				$table->addColumn($_joinColumn['name'], 'bigint', ['notnull' => !($_joinColumn['nullable'] ?? true)]);
			}
		}

		// A hypertable requires the partitioning column in every unique key, so created_at
		// joins the id. Without partitioning the id alone is enough.
		$primary = $meta->getIdentifierColumnNames();
		$table->setPrimaryKey($timescale && $table->hasColumn('created_at') ? [...$primary, 'created_at'] : $primary);

		foreach ($meta->table['indexes'] ?? [] as $_name => $_index) {
			$columns = $_index['columns'] ?? array_map($meta->getColumnName(...), $_index['fields'] ?? []);
			if ($columns) {
				$table->addIndex($columns, is_string($_name) ? $_name : null);
			}
		}
	}

	/**
	 * Time is stored WITH A ZONE in the target, even though the source has it without one.
	 *
	 * Source logs are in UTC, but the column does not say so - so the mover sends the value
	 * with an explicit offset. If the target took it into a column without a zone, it would
	 * drop the offset and after a while nobody could tell from the data what it is in; with
	 * a zone it is unambiguous even a year later. MySQL has no zone on DATETIME, so the
	 * original type stays there.
	 *
	 * JSON is always JSONB on PostgreSQL. Incidents are investigated by searching inside
	 * payloads ("every request whose body carried terminal X") - JSONB can be indexed (GIN)
	 * and queried with `@>` without re-parsing every row of the biggest table. What JSONB
	 * drops - original key order, whitespace, duplicate keys - is already gone by the time
	 * a log row is written: the payload went through json_decode, the sanitizer and
	 * json_encode. Only the key order shown in a detail changes.
	 */
	private function resolveType(string $type, bool $isPostgres): string
	{
		if (!$isPostgres) {
			return $type;
		}

		return match (true) {
			$type === Types::DATETIME_MUTABLE => Types::DATETIMETZ_MUTABLE,
			$type === Types::DATETIME_IMMUTABLE => Types::DATETIMETZ_IMMUTABLE,
			$type === Types::JSON => Type::hasType('jsonb') ? 'jsonb' : $type,
			default => $type,
		};
	}

	/**
	 * Full sub-second precision for time on PostgreSQL.
	 *
	 * DBAL declares a PostgreSQL timestamp as TIMESTAMP(0), which rounds to whole seconds.
	 * Logs are written with milliseconds so that requests within one second can be ordered;
	 * the target would silently lose that. DBAL has no option for the precision, hence the
	 * whole column definition - and with it NOT NULL, which DBAL then does not add itself.
	 */
	private function resolveColumnDefinition(string $type, bool $notNull, bool $isPostgres): ?string
	{
		if (!$isPostgres || !in_array($type, [Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE], true)) {
			return null;
		}

		return 'TIMESTAMP(6) WITH TIME ZONE' . ($notNull ? ' NOT NULL' : '');
	}

	private function timescale(string $table, ?string $hot, ?string $retention): string
	{
		$sql = ["SELECT create_hypertable('$table', 'created_at');"];

		if ($hot !== null) {
			// the boundary between the operational and the archive tier: compressed data can
			// still be read, but with a delay for decompression
			$sql[] = "ALTER TABLE $table SET (timescaledb.compress, timescaledb.compress_orderby = 'created_at DESC');";
			$sql[] = "SELECT add_compression_policy('$table', INTERVAL '$hot');";
		}

		if ($retention !== null) {
			$sql[] = "SELECT add_retention_policy('$table', INTERVAL '$retention');";
		}

		return implode("\n", $sql);
	}

	/**
	 * TWO USERS, not one.
	 *
	 * The owner creates the tables and owns the retention policies. The application gets an
	 * account that can only READ AND WRITE - no UPDATE, DELETE, DROP or ALTER. Without that
	 * the whole separate storage makes no sense: whoever gets into the application could
	 * rewrite the records of what they did there, which is exactly the property the storage
	 * is supposed to guarantee.
	 */
	private function createDatabase(bool $isPostgres): string
	{
		// an empty string is common in the configuration (the stage fills the value in),
		// hence ?: instead of ?? - otherwise CREATE DATABASE "" would stay in the printout
		// and nobody might notice
		$params = $this->targetConnection->getParams();
		$dbname = ($params['dbname'] ?? '') ?: '<database>';
		$user = ($params['user'] ?? '') ?: '<user>';

		if (!$isPostgres) {
			return implode("\n", [
				"CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;",
				'',
				'-- schema owner - creates the tables, the application does not know its credentials',
				"CREATE USER '<owner>'@'%' IDENTIFIED BY '<owner password>';",
				"GRANT ALL PRIVILEGES ON `$dbname`.* TO '<owner>'@'%';",
				'',
				'-- application - read and write only',
				"CREATE USER '$user'@'%' IDENTIFIED BY '<password>';",
				"GRANT SELECT, INSERT ON `$dbname`.* TO '$user'@'%';",
				'',
				'-- Continue as <owner>, connected to this database.',
			]);
		}

		return implode("\n", [
			'-- schema owner - creates the tables and owns the retention policies,',
			'-- the application does not know its credentials',
			'CREATE USER "<owner>" WITH PASSWORD \'<owner password>\';',
			"CREATE DATABASE \"$dbname\" WITH OWNER = \"<owner>\" ENCODING = 'UTF8' TEMPLATE = template0;",
			'',
			'-- application - gets its privileges after the tables, see the end of the printout',
			"CREATE USER \"$user\" WITH PASSWORD '<password>';",
			'',
			'-- Continue connected to THIS database. The extension is created by a superuser',
			'-- (the owner lacks that privilege), the rest runs as the owner - so that it owns',
			'-- the tables and the retention policies.',
			'',
			// the extension is created in the database where it is used, not in the one
			// CREATE DATABASE was run from
			'CREATE EXTENSION IF NOT EXISTS timescaledb;',
			'SET ROLE "<owner>";',
		]);
	}

	/**
	 * Application privileges. After the tables - a grant on a non-existent table fails.
	 *
	 * Listed table by table, not for the whole schema: the move does not read the target,
	 * so a table with `readable: false` (an audit trail) gets INSERT only and cannot be read
	 * from the application even with full access to it.
	 *
	 * Hypertable chunks are created at runtime; TimescaleDB passes the parent's privileges
	 * on to them. A column privilege (`GRANT SELECT (id)`) would not help - PostgreSQL
	 * rejects it on a compressed hypertable.
	 */
	private function grants(bool $isPostgres, string $dbname, string $user): string
	{
		if (!$isPostgres) {
			// MySQL handles privileges at CREATE USER already, nothing to add here
			return '';
		}

		$sql = [
			'',
			'-- Application privileges: write, and read only where needed. No UPDATE or DELETE -',
			'-- only the retention policy may delete, so that a moved record cannot be removed',
			'-- from the place it came from.',
			"GRANT CONNECT ON DATABASE \"$dbname\" TO \"$user\";",
			"GRANT USAGE ON SCHEMA public TO \"$user\";",
		];

		foreach ($this->config as $_entry) {
			$table = ($_entry['table'] ?? null) ?? $this->em->getClassMetadata($_entry['entity'])->getTableName();

			// the user name is quoted: databases are often named after the project and
			// a dash in an unquoted identifier does not pass
			$sql[] = ($_entry['readable'] ?? true)
				? "GRANT SELECT, INSERT ON $table TO \"$user\";"
				: "GRANT INSERT ON $table TO \"$user\";   -- the application must not read it";
		}

		$sql[] = 'REVOKE CREATE ON SCHEMA public FROM PUBLIC;';

		return implode("\n", $sql);
	}

	private function header(): string
	{
		return implode("\n", [
			'-- Target log storage, generated from the entities by log-mover:print-schema.',
			'--',
			'-- The database ALWAYS belongs to EXACTLY ONE source: a record carries its id from',
			'-- the source database, so two sources in one table would collide.',
			'--',
			'-- Fill in the passwords, they are deliberately left out. There are two users: the',
			'-- owner, who creates the schema, and the application, which can only read and',
			'-- write - no UPDATE, DELETE, DROP or ALTER. Only the retention policy may delete,',
			'-- so that a moved record cannot be removed from the place it came from.',
			'',
		]);
	}
}
