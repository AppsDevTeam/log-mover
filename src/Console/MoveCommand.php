<?php

declare(strict_types=1);

namespace ADT\LogMover\Console;

use ADT\LogMover\LogMover;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual move of logs into the separate storage - LogMover does the work, this is a wrapper.
 *
 * In production the move usually runs from a queue so that it goes every minute; this
 * command is for one-off runs and for `--dry-run`, which tells how much is waiting.
 *
 * No command lock: concurrent runs are safe. Both read the same ids, the second write
 * stops on the duplicate key and both delete the same rows from the source.
 */
#[AsCommand(name: 'log-mover:move', description: 'Moves logs into the separate log storage')]
class MoveCommand extends Command
{
	public function __construct(private readonly LogMover $logMover)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be moved');
		$this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch', (string) LogMover::BATCH_SIZE);
		$this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many rows per table and run (0 = unlimited)', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$tables = $this->logMover->getTables();

		if (!$tables) {
			$io->warning('The `logMover` configuration is empty, there is nothing to move.');

			return self::SUCCESS;
		}

		if ($input->getOption('dry-run')) {
			$rows = [];
			foreach ($tables as $_entry) {
				$rows[] = [
					$this->logMover->getSourceTable($_entry['entity']),
					$this->logMover->countWaiting($_entry['entity']),
				];
			}

			$io->table(['source', 'waiting'], $rows);

			return self::SUCCESS;
		}

		$result = $this->logMover->moveAll(
			max(1, (int) $input->getOption('batch-size')),
			max(0, (int) $input->getOption('limit')),
		);

		$rows = [];
		foreach ($result['moved'] as $_table => $_count) {
			$rows[] = [$_table, $_count];
		}
		foreach ($result['errors'] as $_table => $_error) {
			$io->error($_table . ': ' . $_error->getMessage());
			$rows[] = [$_table, 'ERROR'];
		}

		$io->table(['source', 'moved'], $rows);

		return $result['errors'] ? self::FAILURE : self::SUCCESS;
	}
}
