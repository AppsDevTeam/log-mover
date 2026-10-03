<?php

declare(strict_types=1);

namespace ADT\LogMover;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Moves log tables from the application database into a separate log storage.
 *
 * Log tables in the application are only a transfer station. Logs are kept far longer
 * than it makes sense to burden the operational database with, and an audit trail must
 * moreover live ELSEWHERE than the system it reports on - whoever gets into the
 * application must not be able to rewrite the records of what they did there.
 *
 * A RECORD CARRIES ITS ID. It can be traced back by it, the mover recognises by it what
 * has already been moved, and references between moved tables (request_log_body ->
 * request_log) keep pointing at the right row. The flip side: a target database ALWAYS
 * belongs to exactly one source - ids from two sources would collide.
 *
 * A LOG ROW MUST BE IMMUTABLE. A move hits a table where a request record is written first
 * and its response appended later in the middle - the moved row can no longer be found in
 * the source and cannot be completed. That is solved on the write side (two separate rows
 * linked by a correlation id), not by postponing the move.
 *
 * ORDER OF OPERATIONS: write to the target first, only then delete from the source, and
 * delete only what has really been written. If a run is interrupted between the write and
 * the delete, the records stay in both places - the next run hits a duplicate key on them
 * and skips them. The opposite order, or deleting "what made it", means a loss nobody can
 * trace, because the record of it was in exactly what disappeared.
 *
 * MEMORY DOES NOT GROW WITH TABLE WIDTH. Rows are read from the source one at a time,
 * not as a whole batch with a single `SELECT *` - a log record can be megabytes (a request
 * body, a whole XML response) and a thousand of those at once overflow the memory_limit
 * of a queue consumer.
 *
 * THE TARGET IS NEVER READ. The application therefore needs only INSERT on target tables -
 * essential for an audit trail, which must not be readable from the application at all.
 *
 * @phpstan-type TableConfig array{entity: class-string, table?: string|null, hot?: string|null, retention?: string|null, readable?: bool}
 */
class LogMover
{
	public const int BATCH_SIZE = 1000;

	private readonly Connection $sourceConnection;

	/**
	 * @param EntityManagerInterface $em only for metadata - which table an entity maps to
	 * @param list<TableConfig> $config
	 * @param Connection|null $sourceConnection defaults to the entity manager's connection;
	 *        set it when the log entities are mapped in a different entity manager than
	 *        the one owning the source tables (e.g. entities live in the log storage EM)
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly Connection $targetConnection,
		private readonly array $config,
		?Connection $sourceConnection = null,
	) {
		$this->sourceConnection = $sourceConnection ?? $em->getConnection();
	}

	/** @return list<TableConfig> */
	public function getTables(): array
	{
		return $this->config;
	}

	/**
	 * Moves all configured tables.
	 *
	 * An unavailable or broken table does not stop the others - otherwise a single typo in
	 * the configuration would stop everything. The caller learns from `errors` what failed:
	 * the command prints it, a queue job fails on it so that it gets retried.
	 *
	 * @return array{moved: array<string, int>, errors: array<string, Throwable>}
	 */
	public function moveAll(int $batchSize = self::BATCH_SIZE, int $limit = 0): array
	{
		$moved = [];
		$errors = [];

		foreach ($this->config as $_entry) {
			$sourceTable = $this->getSourceTable($_entry['entity']);

			try {
				$moved[$sourceTable] = $this->move($_entry['entity'], $batchSize, $limit);
			} catch (Throwable $e) {
				$errors[$sourceTable] = $e;
			}
		}

		return ['moved' => $moved, 'errors' => $errors];
	}

	/**
	 * @param class-string $entityClass
	 * @throws Throwable
	 */
	public function move(string $entityClass, int $batchSize = self::BATCH_SIZE, int $limit = 0): int
	{
		$sourceTable = $this->getSourceTable($entityClass);
		$targetTable = $this->getTargetTable($entityClass);
		$source = $this->sourceConnection;
		$moved = 0;
		$processed = 0;

		while (true) {
			// IDS FIRST, rows one by one afterwards. `SELECT *` for the whole batch looks
			// cheaper (one query instead of a thousand), but a log row can be megabytes -
			// a request body, a whole XML response - and the driver keeps the entire result
			// in memory. A thousand such rows reliably overflow a queue consumer's
			// memory_limit. This way memory use is the same for a narrow and a wide table.
			//
			// oldest first: if a run ends early, what stays in the source is the newer part,
			// which is the easiest to look up anyway
			$ids = array_map('intval', $source->fetchFirstColumn(
				"SELECT id FROM $sourceTable ORDER BY id ASC LIMIT $batchSize",
			));
			if (!$ids) {
				break;
			}

			$this->targetConnection->beginTransaction();
			try {
				$written = 0;
				foreach ($ids as $_id) {
					$row = $source->fetchAssociative("SELECT * FROM $sourceTable WHERE id = ?", [$_id]);
					if ($row === false) {
						// gone in the meantime - nobody has to delete it any more
						continue;
					}

					$written += $this->insertIgnoringDuplicates($targetTable, $this->toTargetRow($row));
					unset($row);
				}
				$this->targetConnection->commit();
			} catch (Throwable $e) {
				$this->targetConnection->rollBack();

				// nothing is deleted from the source: what was not written must not disappear
				throw $e;
			}

			// only now, and only what is provably in the target: the write either went
			// through or stopped on a duplicate, which means the record was already there
			$source->executeStatement(
				"DELETE FROM $sourceTable WHERE id IN (?)",
				[$ids],
				[ArrayParameterType::INTEGER],
			);

			$moved += $written;
			$processed += count($ids);

			if ($limit > 0 && $processed >= $limit) {
				break;
			}
		}

		return $moved;
	}

	/** @param class-string $entityClass */
	public function countWaiting(string $entityClass): int
	{
		$table = $this->getSourceTable($entityClass);

		return (int) $this->sourceConnection->fetchOne("SELECT COUNT(*) FROM $table");
	}

	/** @param class-string $entityClass */
	public function getSourceTable(string $entityClass): string
	{
		return $this->em->getClassMetadata($entityClass)->getTableName();
	}

	/** @param class-string $entityClass */
	public function getTargetTable(string $entityClass): string
	{
		foreach ($this->config as $_entry) {
			if ($_entry['entity'] === $entityClass) {
				return ($_entry['table'] ?? null) ?? $this->getSourceTable($entityClass);
			}
		}

		return $this->getSourceTable($entityClass);
	}

	/**
	 * Writes a row and silently skips one that is already in the target.
	 *
	 * WITHOUT READING THE TARGET. Checking first which ids are already there would require
	 * SELECT on the target table - and for an audit trail that is exactly what must be ruled
	 * out. This way INSERT is enough.
	 *
	 * It targets the duplicate key, it does not swallow errors: MySQL's `INSERT IGNORE` also
	 * swallows a truncated value or a mismatched type, so something that is not in the target
	 * would get deleted from the source. Hence the duplicate is targeted explicitly.
	 *
	 * @param array<string, mixed> $row
	 * @return int 1 = written, 0 = was already in the target
	 */
	private function insertIgnoringDuplicates(string $targetTable, array $row): int
	{
		$platform = $this->targetConnection->getDatabasePlatform();
		$columns = array_map($platform->quoteSingleIdentifier(...), array_keys($row));
		$placeholders = implode(', ', array_fill(0, count($row), '?'));

		$sql = 'INSERT INTO ' . $targetTable . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
		$sql .= $platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform
			? ' ON CONFLICT DO NOTHING'
			// not INSERT IGNORE: `id = id` touches only the duplicate, other errors bubble up
			: ' ON DUPLICATE KEY UPDATE ' . $columns[0] . ' = ' . $columns[0];

		return (int) $this->targetConnection->executeStatement($sql, array_values($row));
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function toTargetRow(array $row): array
	{
		// Time comes from the source without a zone, but in UTC. The target may have a column
		// with a zone - it would then read a value without an offset in its own zone and the
		// records would shift by a few hours. Silently: nothing fails, they just stop lining
		// up with the other logs.
		if (isset($row['created_at']) && is_string($row['created_at'])) {
			$row['created_at'] = rtrim($row['created_at']) . '+00:00';
		}

		return $row;
	}
}
