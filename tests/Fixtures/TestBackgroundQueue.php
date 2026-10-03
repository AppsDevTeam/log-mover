<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests\Fixtures;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\Enums\ModeEnum;

/** A queue that only remembers what was published - no database, no broker. */
final class TestBackgroundQueue extends BackgroundQueue
{
	/** @var list<array{0: string, 1: ?string, 2: ModeEnum}> */
	public array $published = [];

	/**
	 * @param list<string> $unfinished identifiers that have an unfinished job
	 * @noinspection PhpMissingParentConstructorInspection
	 */
	public function __construct(private readonly array $unfinished = [])
	{
	}

	public function getUnfinishedJobIdentifiers(array $identifiers = [], bool $excludeProcessing = false): array
	{
		return array_values(array_intersect($identifiers, $this->unfinished));
	}

	public function publish(
		string $callbackName,
		?array $parameters = null,
		?string $serialGroup = null,
		?string $identifier = null,
		ModeEnum $mode = ModeEnum::NORMAL,
		?int $postponeBy = null,
		?int $priority = null,
		?int $coalesceThreshold = null,
	): void {
		$this->published[] = [$callbackName, $identifier, $mode];
	}
}
