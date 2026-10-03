<?php

declare(strict_types=1);

namespace ADT\LogMover\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\Enums\ModeEnum;
use ADT\LogMover\LogMover;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts the log move into adt/background-queue - run it from cron every minute.
 *
 * The job is RECURRING: once it finishes, the queue schedules it again right away (after
 * `waitingJobExpiration`, a second by default), so logs are moved within seconds, not
 * minutes. Cron is only the safety net that starts it after a deploy or after a permanent
 * failure. An unfinished job (waiting, running, being retried) blocks a new one, so there
 * is never more than one in the queue.
 *
 * Why a queue and not running log-mover:move from cron directly: a move can take longer
 * than a minute (an unavailable target, piled up records), and the queue retries a failed
 * job with a growing delay and reports it in background-queue:monitor.
 *
 * Registered by LogMoverExtension only when adt/background-queue is present; the extension
 * also registers the queue callback, so the project configures nothing else.
 */
#[AsCommand(name: 'log-mover:schedule', description: 'Puts the log move into the background queue (run from cron)')]
class ScheduleCommand extends Command
{
	public function __construct(private readonly ?BackgroundQueue $backgroundQueue = null)
	{
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if ($this->backgroundQueue === null) {
			$output->writeln('<error>No BackgroundQueue service - register adt/background-queue-nette, or run log-mover:move from cron instead.</error>');

			return self::FAILURE;
		}

		if (!$this->backgroundQueue->getUnfinishedJobIdentifiers([LogMover::QUEUE_CALLBACK])) {
			$this->backgroundQueue->publish(LogMover::QUEUE_CALLBACK, identifier: LogMover::QUEUE_CALLBACK, mode: ModeEnum::RECURRING);
		}

		return self::SUCCESS;
	}
}
