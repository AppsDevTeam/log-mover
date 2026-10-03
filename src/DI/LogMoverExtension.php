<?php

declare(strict_types=1);

namespace ADT\LogMover\DI;

use ADT\LogMover\Console\MoveCommand;
use ADT\LogMover\Console\PrintSchemaCommand;
use ADT\LogMover\LogMover;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Registers LogMover as a service (it also runs from a queue, not only from the command)
 * and both commands.
 *
 * ```neon
 * extensions:
 *     logMover: ADT\LogMover\DI\LogMoverExtension
 *
 * logMover:
 *     target: @nettrine.dbal.connections.logdb.connection
 *     tables:
 *         - {entity: App\Model\Entities\RequestLogBody, hot: '7 days', retention: '1 month'}
 *         - {entity: App\Model\Entities\RequestLog, hot: '1 month', retention: '6 months'}
 * ```
 */
class LogMoverExtension extends CompilerExtension
{
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
	}
}
