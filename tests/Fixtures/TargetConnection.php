<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use RuntimeException;

/**
 * Target connection: writes into memory, can pretend already moved records and a failure.
 *
 * The mover only writes to the target (it gets nothing back but the number of affected
 * rows), so a duplicate is pretended by returning zero - exactly what the database
 * returns on ON CONFLICT.
 */
final class TargetConnection extends Connection
{
	/** @var list<array<string, mixed>> */
	public array $inserted = [];

	/** @var list<string> */
	public array $statements = [];

	/** @var list<string> */
	public array $transactions = [];

	/**
	 * @param list<int> $alreadyMoved
	 * @noinspection PhpMissingParentConstructorInspection
	 */
	public function __construct(
		private readonly array $alreadyMoved = [],
		private readonly bool $failOnInsert = false,
		private ?AbstractPlatform $platform = null,
	) {
	}

	public function getDatabasePlatform(): AbstractPlatform
	{
		return $this->platform ??= new PostgreSQLPlatform();
	}

	public function executeStatement(string $sql, array $params = [], array $types = []): int|string
	{
		if ($this->failOnInsert) {
			throw new RuntimeException('target is unavailable');
		}

		$this->statements[] = $sql;

		// the first column is the id - if it is already in the target, the database drops
		// the write and returns zero
		$row = array_combine(self::insertedColumns($sql), $params);
		if (in_array((int) $row['id'], $this->alreadyMoved, true)) {
			return 0;
		}

		$this->inserted[] = $row;

		return 1;
	}

	public function beginTransaction(): void
	{
		$this->transactions[] = 'begin';
	}

	public function commit(): void
	{
		$this->transactions[] = 'commit';
	}

	public function rollBack(): void
	{
		$this->transactions[] = 'rollback';
	}

	/** @return list<string> column names from a generated INSERT */
	private static function insertedColumns(string $sql): array
	{
		preg_match('/\((.*?)\) VALUES/', $sql, $matches);

		return array_map(static fn (string $column) => trim($column, ' "`'), explode(',', $matches[1]));
	}
}
