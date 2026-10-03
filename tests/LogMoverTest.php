<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests;

use ADT\LogMover\LogMover;
use ADT\LogMover\Tests\Fixtures\SourceConnection;
use ADT\LogMover\Tests\Fixtures\TargetConnection;
use ADT\LogMover\Tests\Fixtures\TestAuditLog;
use ADT\LogMover\Tests\Fixtures\TestEntityManager;
use ADT\LogMover\Tests\Fixtures\TestRequestLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

/**
 * Moving logs into the separate storage.
 *
 * An audit trail is the only copy of the record of what happened in the system, so the
 * order of operations is what matters here: write to the target first, only then delete
 * from the source. And when the write fails, nothing may disappear from the source -
 * otherwise nobody could trace the loss, because the record of it was in exactly what
 * disappeared.
 */
final class LogMoverTest extends TestCase
{
	public function testRecordIsMovedWithItsOriginalId(): void
	{
		// The id travels along so that the record can be traced back and references between
		// moved tables keep pointing at the right row. Requires one target database per source.
		$target = new TargetConnection();

		$this->runMover(new SourceConnection([[self::auditRow(42)]]), $target);

		self::assertCount(1, $target->inserted);
		self::assertSame(42, $target->inserted[0]['id']);
		self::assertSame('login', $target->inserted[0]['action']);
	}

	public function testTimeIsSentWithExplicitUtcZone(): void
	{
		// The target may have a column with a zone; without an offset it would read the value
		// in its own zone and the records would stop lining up with other logs - silently.
		$target = new TargetConnection();

		$this->runMover(new SourceConnection([[self::auditRow(1)]]), $target);

		self::assertSame('2026-03-01 12:00:00.123+00:00', $target->inserted[0]['created_at']);
	}

	public function testSourceIsDeletedOnlyAfterTheWrite(): void
	{
		$source = new SourceConnection([[self::auditRow(1), self::auditRow(2)]]);
		$target = new TargetConnection();

		$this->runMover($source, $target);

		self::assertSame(['begin', 'commit'], $target->transactions);
		self::assertSame([[1, 2]], $source->deleted);
	}

	public function testNothingDisappearsFromSourceWhenTheWriteFails(): void
	{
		// This is what the whole thing is about: a lost audit record shows nowhere, because
		// the record of the loss was in exactly what disappeared.
		$source = new SourceConnection([[self::auditRow(1)]]);
		$target = new TargetConnection(failOnInsert: true);

		[, $errors] = $this->runMover($source, $target);

		self::assertSame([], $source->deleted);
		self::assertSame(['begin', 'rollback'], $target->transactions);
		// the error goes back to the caller - the command prints it, a job fails on it
		self::assertSame('audit_log', $errors);
	}

	public function testAlreadyMovedRecordIsNotWrittenTwiceOnlyCleanedFromSource(): void
	{
		// The state after a run interrupted between the write and the delete.
		$source = new SourceConnection([[self::auditRow(7), self::auditRow(8)]]);
		$target = new TargetConnection(alreadyMoved: [7]);

		[$moved] = $this->runMover($source, $target);

		self::assertCount(1, $target->inserted);
		self::assertSame(8, $target->inserted[0]['id']);
		// both must be deleted: seven is already in the target
		self::assertSame([[7, 8]], $source->deleted);
		// seven was already in the target, so it does not count as moved
		self::assertSame(1, $moved);
		// and it was found out without reading the target - the application has INSERT only there
		self::assertStringContainsString('ON CONFLICT DO NOTHING', end($target->statements));
	}

	public function testMovesInBatchesWhileThereIsSomething(): void
	{
		$source = new SourceConnection([[self::auditRow(1)], [self::auditRow(2)], []]);
		$target = new TargetConnection();

		[$moved] = $this->runMover($source, $target);

		self::assertCount(2, $target->inserted);
		self::assertSame([[1], [2]], $source->deleted);
		self::assertSame(2, $moved);
	}

	public function testLimitStopsTheRunEarly(): void
	{
		// So that a nightly move does not lock the database for hours when things pile up.
		$source = new SourceConnection([[self::auditRow(1)], [self::auditRow(2)], [self::auditRow(3)]]);
		$target = new TargetConnection();

		$this->runMover($source, $target, limit: 1);

		self::assertCount(1, $target->inserted);
	}

	public function testCountingWaitingRecordsDoesNotTouchTheData(): void
	{
		$source = new SourceConnection(count: 128);
		$target = new TargetConnection();

		$mover = $this->createMover($source, $target);

		self::assertSame(128, $mover->countWaiting(TestAuditLog::class));
		self::assertSame([], $target->inserted);
		self::assertSame([], $source->deleted);
	}

	public function testAllConfiguredTablesAreMoved(): void
	{
		$source = new SourceConnection([[self::auditRow(1)], [], [self::auditRow(2)], []]);
		$target = new TargetConnection();
		$config = [
			['entity' => TestAuditLog::class],
			['entity' => TestRequestLog::class, 'table' => 'request_log_archive'],
		];

		$result = $this->createMover($source, $target, $config)->moveAll();

		self::assertCount(2, $target->inserted);
		self::assertSame([], $result['errors']);
		// the result is keyed by the source table, each has its own
		self::assertSame(['audit_log' => 1, 'request_log' => 1], $result['moved']);
		self::assertStringContainsString('INSERT INTO request_log_archive', $target->statements[1]);
	}

	public function testUnavailableTableBreaksOnlyItsOwnRow(): void
	{
		$source = new SourceConnection([[self::auditRow(1)]]);
		$target = new TargetConnection(failOnInsert: true);

		$result = $this->createMover($source, $target)->moveAll();

		self::assertSame(['audit_log'], array_keys($result['errors']));
		self::assertSame([], $source->deleted);
	}

	public function testMySqlTargetHandlesDuplicateWithoutInsertIgnore(): void
	{
		// INSERT IGNORE also swallows a truncated value or a mismatched type, so something
		// that is not in the target would get deleted from the source.
		$source = new SourceConnection([[self::auditRow(1)], []]);
		$target = new TargetConnection(platform: new MySQLPlatform());

		$this->createMover($source, $target)->moveAll();

		self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $target->statements[0]);
		self::assertStringNotContainsString('INSERT IGNORE', $target->statements[0]);
	}

	public function testSourceIsNotReadAsAWholeBatch(): void
	{
		// Regression: `SELECT *` for a whole batch pulled a thousand rows into memory at once.
		// A log record can be megabytes, so the queue consumer died on memory_limit.
		$source = new SourceConnection([[self::auditRow(1), self::auditRow(2)], []]);

		$this->createMover($source, new TargetConnection())->moveAll();

		self::assertStringContainsString('SELECT id FROM audit_log', $source->queries[0]);
		self::assertStringNotContainsString('SELECT * FROM audit_log ORDER BY', implode("\n", $source->queries));
		// each row separately, so memory use does not depend on table width
		self::assertStringContainsString('SELECT * FROM audit_log WHERE id = ?', $source->queries[1]);
		self::assertStringContainsString('SELECT * FROM audit_log WHERE id = ?', $source->queries[2]);
	}

	public function testSourceConnectionCanDifferFromTheEntityManagersOne(): void
	{
		// A project can map its log entities in the log storage's entity manager - then the
		// metadata comes from there, but the rows from the application database.
		$emConnection = new SourceConnection([[self::auditRow(99)]]);
		$source = new SourceConnection([[self::auditRow(1)]]);
		$target = new TargetConnection();

		$em = $this->createEntityManager($emConnection);
		(new LogMover($em, $target, [['entity' => TestAuditLog::class]], $source))->moveAll();

		self::assertSame(1, $target->inserted[0]['id']);
		self::assertSame([], $emConnection->queries);
	}

	public function testEndToEndOnRealDatabases(): void
	{
		// Stubs above check the order of operations; this checks the SQL actually runs and
		// a repeated run after an interruption really does not duplicate anything.
		$source = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$target = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

		$source->executeStatement('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, action TEXT NOT NULL, created_at TEXT NOT NULL)');
		$target->executeStatement('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, action TEXT NOT NULL, created_at TEXT NOT NULL)');
		foreach ([1, 2, 3] as $_id) {
			$source->insert('audit_log', ['id' => $_id, 'action' => 'login', 'created_at' => '2026-03-01 12:00:00']);
		}
		// as if a previous run had written #1 and been killed before deleting it
		$target->insert('audit_log', ['id' => 1, 'action' => 'login', 'created_at' => '2026-03-01 12:00:00+00:00']);

		$result = $this->createMover($source, $target)->moveAll(batchSize: 2);

		self::assertSame([], $result['errors']);
		self::assertSame(['audit_log' => 2], $result['moved']);
		self::assertSame(0, (int) $source->fetchOne('SELECT COUNT(*) FROM audit_log'));
		self::assertSame([1, 2, 3], array_map('intval', $target->fetchFirstColumn('SELECT id FROM audit_log ORDER BY id')));
		self::assertSame('2026-03-01 12:00:00+00:00', $target->fetchOne('SELECT created_at FROM audit_log WHERE id = 3'));
	}

	/** @return array{0: int, 1: string} number moved and the failed tables */
	private function runMover(Connection $source, Connection $target, int $limit = 0): array
	{
		$result = $this->createMover($source, $target)->moveAll(LogMover::BATCH_SIZE, $limit);

		return [array_sum($result['moved']), implode(', ', array_keys($result['errors']))];
	}

	/** @param list<array<string, mixed>>|null $config */
	private function createMover(Connection $source, Connection $target, ?array $config = null): LogMover
	{
		return new LogMover($this->createEntityManager($source), $target, $config ?? [['entity' => TestAuditLog::class]]);
	}

	private function createEntityManager(Connection $connection): TestEntityManager
	{
		$em = new TestEntityManager($connection);
		$em->getClassMetadata(TestAuditLog::class)->setPrimaryTable(['name' => 'audit_log']);
		$em->getClassMetadata(TestRequestLog::class)->setPrimaryTable(['name' => 'request_log']);

		return $em;
	}

	/** @return array<string, mixed> */
	private static function auditRow(int $id): array
	{
		return [
			'id' => $id,
			'action' => 'login',
			'created_at' => '2026-03-01 12:00:00.123',
			'correlation_id' => null,
		];
	}
}
