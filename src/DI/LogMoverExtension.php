<?php

declare(strict_types=1);

namespace ADT\LogMover\DI;

use ADT\LogMover\Console\MoveCommand;
use ADT\LogMover\Console\PrintSchemaCommand;
use ADT\LogMover\Console\ScheduleCommand;
use ADT\LogMover\LogMover;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\InvalidConfigurationException;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Registers LogMover and its commands. With adt/background-queue in the project it also
 * registers the queue callback and log-mover:schedule - the project then only adds that
 * command to cron, it writes no job class and no callback of its own.
 *
 * ```neon
 * extensions:
 *     logMover: ADT\LogMover\DI\LogMoverExtension
 *
 * logMover:
 *     target: @nettrine.dbal.connections.logdb.connection
 *     tables:
 *         - {entity: App\Model\Entities\RequestLogBody, hot: '1 month', retention: '1 month'}
 *         - {entity: App\Model\Entities\RequestLog, hot: '3 months', retention: '6 months'}
 * ```
 */
class LogMoverExtension extends CompilerExtension
{
	/** adt/background-queue is optional - referenced by name so the class need not exist */
	private const string QUEUE_CLASS = 'ADT\\BackgroundQueue\\BackgroundQueue';

	public function getConfigSchema(): Schema
	{
		$service = Expect::anyOf(Expect::string(), Expect::type(Statement::class));

		return Expect::structure([
			// DBAL connection to the target storage
			'target' => (clone $service)->required(),
			// Where the entity metadata comes from. Defaults to the autowired
			// EntityManagerInterface - set it when there are several.
			'entityManager' => (clone $service)->nullable()->default(null),
			// The source connection. Defaults to the entity manager's connection - set it when
			// the log entities are mapped in a different entity manager than the one that owns
			// the source tables (e.g. the entities are mapped in the log storage's manager).
			'source' => (clone $service)->nullable()->default(null),
			// What is moved. Order matters where tables are bound by ON DELETE CASCADE in the
			// source: the child (body) before the parent, see README.
			'tables' => Expect::listOf(Expect::structure([
				'entity' => Expect::string()->required(),
				// target table name, defaults to the source one
				'table' => Expect::string()->nullable()->default(null),
				// only for log-mover:print-schema, the move itself does not use them:
				// `hot` = boundary of the operational and archive tier (TimescaleDB compression),
				// `retention` = after how long a record disappears from the target
				'hot' => Expect::string()->nullable()->default(null),
				'retention' => Expect::string()->nullable()->default(null),
				// May the application READ the table in the target? The move does not need it,
				// so `false` means it gets INSERT only - the case of an audit trail.
				'readable' => Expect::bool()->default(true),
			])->castTo('array'))->min(1),
			// adt/background-queue integration (callback + log-mover:schedule).
			'queue' => Expect::structure([
				// null = on when the project has a BackgroundQueue service, true = require it
				'enabled' => Expect::bool()->nullable()->default(null),
				// queue name and priority of the callback, as in backgroundQueue.callbacks -
				// validated the same way here, because the callback is added after the
				// backgroundQueue extension has validated its own config
				'name' => Expect::string()->nullable()->default(null),
				'priority' => Expect::int()->min(1)->nullable()->default(null),
			]),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->config;
		$em = $config->entityManager ?? Reference::fromType(EntityManagerInterface::class);

		$builder->addDefinition($this->prefix('logMover'))
			->setFactory(LogMover::class, [
				'em' => $em,
				'targetConnection' => $config->target,
				'config' => $config->tables,
				'sourceConnection' => $config->source,
			]);

		$builder->addDefinition($this->prefix('moveCommand'))
			->setFactory(MoveCommand::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('printSchemaCommand'))
			->setFactory(PrintSchemaCommand::class, [
				'em' => $em,
				'targetConnection' => $config->target,
				'config' => $config->tables,
			])
			->setAutowired(false);

		// Here, not in beforeCompile: contributte/console collects commands in its own
		// beforeCompile, and which extension goes first depends on the project config.
		// The queue is an optional argument, so a project with adt/background-queue
		// installed but not registered still compiles.
		if ($config->queue->enabled !== false && class_exists(self::QUEUE_CLASS)) {
			$builder->addDefinition($this->prefix('scheduleCommand'))
				->setFactory(ScheduleCommand::class)
				->setAutowired(false);
		}
	}

	/**
	 * The queue callback goes straight into the BackgroundQueue service's `config` argument.
	 * Callbacks normally come from the backgroundQueue section of the project config, which
	 * another extension cannot add to - but by now that extension has turned the section
	 * into the service definition, and the definition can be completed.
	 */
	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		$queue = $this->config->queue;

		if ($queue->enabled === false) {
			return;
		}

		$queueClass = 'ADT\BackgroundQueue\BackgroundQueue';
		$name = class_exists($queueClass) ? $builder->getByType($queueClass) : null;
		$definition = $name !== null ? $builder->getDefinition($name) : null;

		if (!$definition instanceof ServiceDefinition) {
			if ($queue->enabled === true) {
				throw new InvalidConfigurationException('logMover.queue.enabled requires adt/background-queue with a registered BackgroundQueue service.');
			}

			return;
		}

		$factory = $definition->getFactory();
		$arguments = $factory->arguments;
		$key = array_key_exists('config', $arguments) ? 'config' : 0;
		$queueConfig = $arguments[$key] ?? [];

		if (isset($queueConfig['callbacks'][LogMover::QUEUE_CALLBACK])) {
			throw new InvalidConfigurationException(sprintf('Background queue callback "%s" is already defined; logMover registers it itself - remove it from backgroundQueue.callbacks.', LogMover::QUEUE_CALLBACK));
		}

		$queueConfig['callbacks'][LogMover::QUEUE_CALLBACK] = [
			'callback' => [new Reference($this->prefix('logMover')), 'moveAllOrFail'],
			'queue' => $queue->name,
			'priority' => $queue->priority,
		];
		$arguments[$key] = $queueConfig;
		$definition->setArguments($arguments);
	}
}
