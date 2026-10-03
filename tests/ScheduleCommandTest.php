<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests;

use ADT\BackgroundQueue\Entity\Enums\ModeEnum;
use ADT\LogMover\Console\ScheduleCommand;
use ADT\LogMover\LogMover;
use ADT\LogMover\Tests\Fixtures\TestBackgroundQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * log-mover:schedule - what cron runs every minute.
 */
final class ScheduleCommandTest extends TestCase
{
	public function testPublishesRecurringJobWhenThereIsNone(): void
	{
		$queue = new TestBackgroundQueue();

		$tester = new CommandTester(new ScheduleCommand($queue));
		$tester->execute([]);

		self::assertSame(Command::SUCCESS, $tester->getStatusCode());
		self::assertSame([[LogMover::QUEUE_CALLBACK, LogMover::QUEUE_CALLBACK, ModeEnum::RECURRING]], $queue->published);
	}

	public function testDoesNothingWhileAJobIsUnfinished(): void
	{
		// waiting, running or being retried - a second one would only duplicate the work
		$queue = new TestBackgroundQueue(unfinished: [LogMover::QUEUE_CALLBACK]);

		new CommandTester(new ScheduleCommand($queue))->execute([]);

		self::assertSame([], $queue->published);
	}

	public function testFailsWithoutQueue(): void
	{
		$tester = new CommandTester(new ScheduleCommand());
		$tester->execute([]);

		self::assertSame(Command::FAILURE, $tester->getStatusCode());
		self::assertStringContainsString('log-mover:move', $tester->getDisplay());
	}
}
