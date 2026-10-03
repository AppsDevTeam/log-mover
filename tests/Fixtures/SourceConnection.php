<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests\Fixtures;

use Doctrine\DBAL\Connection;

/** Source connection: returns prepared batches and remembers what was deleted. */
final class SourceConnection extends Connection
{
	/** @var list<list<int>> */
	public array $deleted = [];

	/** @var list<string> */
	public array $queries = [];

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	/**
	 * @param list<list<array<string, mixed>>> $batches
	 * @noinspection PhpMissingParentConstructorInspection
	 */
	public function __construct(private array $batches = [], private readonly int $count = 0)
	{
	}

	/** A batch is read as ids first, then row by row - the stub has to work the same way. */
	public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
	{
		$this->queries[] = $query;

		$batch = array_shift($this->batches) ?? [];
		$this->rows = [];
		foreach ($batch as $_row) {
			$this->rows[(int) $_row['id']] = $_row;
		}

		return array_column($batch, 'id');
	}

	public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
	{
		$this->queries[] = $query;

		return $this->rows[(int) $params[0]] ?? false;
	}

	public function fetchOne(string $query, array $params = [], array $types = []): mixed
	{
		$this->queries[] = $query;

		return $this->count;
	}

	public function executeStatement(string $sql, array $params = [], array $types = []): int|string
	{
		$this->deleted[] = $params[0];

		return count($params[0]);
	}
}
