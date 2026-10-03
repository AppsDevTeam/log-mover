<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests;

use ADT\LogMover\Console\MoveCommand;
use ADT\LogMover\Console\PrintSchemaCommand;
use ADT\LogMover\Console\ScheduleCommand;
use ADT\LogMover\DI\LogMoverExtension;
use ADT\BackgroundQueue\BackgroundQueue;
use ADT\LogMover\LogMover;
use ADT\LogMover\Tests\Fixtures\TestAuditLog;
use ADT\LogMover\Tests\Fixtures\TestEntityManager;
use Doctrine\DBAL\Connection;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The Nette DI extension - what a project actually writes into its configuration.
 */
final class LogMoverExtensionTest extends TestCase
{
	private static int $counter = 0;

	public function testRegistersMoverAndBothCommands(): void
	{
		$container = $this->createContainer(<<<'NEON'
			logMover:
				target: @target
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog, hot: '3 months', retention: '13 months'}
			NEON);

		$mover = $container->getByType(LogMover::class);

		self::assertEquals([[
			'entity' => TestAuditLog::class,
			'table' => null,
			'hot' => '3 months',
			'retention' => '13 months',
			'readable' => true,
		]], $mover->getTables());
		self::assertInstanceOf(MoveCommand::class, $container->getService('logMover.moveCommand'));
		self::assertInstanceOf(PrintSchemaCommand::class, $container->getService('logMover.printSchemaCommand'));
	}

	public function testSourceDefaultsToTheEntityManagersConnection(): void
	{
		$container = $this->createContainer(<<<'NEON'
			logMover:
				target: @target
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);

		self::assertSame($container->getService('appConnection'), $this->sourceOf($container->getByType(LogMover::class)));
	}

	public function testSourceAndEntityManagerCanBeSetExplicitly(): void
	{
		// The case of a project mapping its log entities in the log storage's entity manager:
		// metadata from there, rows from the application database.
		$container = $this->createContainer(<<<'NEON'
			logMover:
				target: @target
				entityManager: @logEm
				source: @appConnection
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);

		self::assertSame($container->getService('appConnection'), $this->sourceOf($container->getByType(LogMover::class)));
	}

	public function testTablesAreRequired(): void
	{
		// Half a configuration is worse than none: the move would have nothing to move.
		$this->expectException(InvalidConfigurationException::class);

		$this->createContainer(<<<'NEON'
			logMover:
				target: @target
				tables: []
			NEON);
	}

	public function testRegistersQueueCallbackAndScheduleCommand(): void
	{
		// The project adds log-mover:schedule to cron and configures nothing else - no job
		// class, no entry in backgroundQueue.callbacks.
		$container = $this->createContainer(self::QUEUE_SERVICE . <<<'NEON'
			logMover:
				target: @target
				queue:
					name: logs
					priority: 5
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);

		$callback = $this->queueConfigOf($container->getByType(BackgroundQueue::class))['callbacks'][LogMover::QUEUE_CALLBACK];

		self::assertSame([$container->getByType(LogMover::class), 'moveAllOrFail'], $callback['callback']);
		self::assertSame('logs', $callback['queue']);
		self::assertSame(5, $callback['priority']);
		// the project's own callbacks stay
		self::assertArrayHasKey('sendEmail', $this->queueConfigOf($container->getByType(BackgroundQueue::class))['callbacks']);
		self::assertInstanceOf(ScheduleCommand::class, $container->getService('logMover.scheduleCommand'));
	}

	public function testWithoutQueueServiceTheMoverStillCompiles(): void
	{
		// adt/background-queue is installed (dev dependency here) but not registered.
		$container = $this->createContainer(<<<'NEON'
			logMover:
				target: @target
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);

		self::assertInstanceOf(LogMover::class, $container->getByType(LogMover::class));
	}

	public function testQueueCanBeTurnedOff(): void
	{
		$container = $this->createContainer(self::QUEUE_SERVICE . <<<'NEON'
			logMover:
				target: @target
				queue:
					enabled: false
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);

		self::assertArrayNotHasKey(LogMover::QUEUE_CALLBACK, $this->queueConfigOf($container->getByType(BackgroundQueue::class))['callbacks']);
		self::assertFalse($container->hasService('logMover.scheduleCommand'));
	}

	public function testRequiredQueueWithoutServiceFailsTheCompilation(): void
	{
		$this->expectException(InvalidConfigurationException::class);

		$this->createContainer(<<<'NEON'
			logMover:
				target: @target
				queue:
					enabled: true
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);
	}

	public function testCallbackDefinedByTheProjectTooFailsTheCompilation(): void
	{
		// Two definitions of one callback - one would silently win.
		$this->expectException(InvalidConfigurationException::class);
		$this->expectExceptionMessage('already defined');

		$this->createContainer(str_replace('callbacks: [sendEmail: [@appConnection, close]]', 'callbacks: [sendEmail: [@appConnection, close], logMover: [@appConnection, close]]', self::QUEUE_SERVICE) . <<<'NEON'
			logMover:
				target: @target
				tables:
					- {entity: ADT\LogMover\Tests\Fixtures\TestAuditLog}
			NEON);
	}

	/** A BackgroundQueue service registered the way background-queue-nette does - by `config`. */
	private const string QUEUE_SERVICE = <<<'NEON'
		services:
			queue: ADT\BackgroundQueue\BackgroundQueue(config: [callbacks: [sendEmail: [@appConnection, close]], connection: @appConnection, logger: null, producer: null])

		NEON;

	/** @return array<string, mixed> */
	private function queueConfigOf(BackgroundQueue $queue): array
	{
		return new ReflectionProperty(BackgroundQueue::class, 'config')->getValue($queue);
	}

	private function createContainer(string $config): Container
	{
		$services = <<<'NEON'
			services:
				appConnection:
					create: Doctrine\DBAL\DriverManager::getConnection([driver: pdo_sqlite, memory: true])
					autowired: false
				target:
					create: Doctrine\DBAL\DriverManager::getConnection([driver: pdo_sqlite, memory: true])
					autowired: false
				em: ADT\LogMover\Tests\Fixtures\TestEntityManager(@appConnection)
				logEm:
					create: ADT\LogMover\Tests\Fixtures\TestEntityManager(@target)
					autowired: false
			NEON;

		$tempDir = sys_get_temp_dir() . '/log-mover-tests';
		@mkdir($tempDir);
		$key = __CLASS__ . getmypid() . (++self::$counter);

		// two files: both may have a `services` section, Nette merges them
		$servicesFile = $tempDir . '/' . md5($key) . '.services.neon';
		$configFile = $tempDir . '/' . md5($key) . '.neon';
		file_put_contents($servicesFile, $services);
		file_put_contents($configFile, $config);

		$class = new ContainerLoader($tempDir, true)->load(static function (Compiler $compiler) use ($servicesFile, $configFile): void {
			$compiler->addExtension('logMover', new LogMoverExtension());
			$compiler->loadConfig($servicesFile);
			$compiler->loadConfig($configFile);
		}, $key);

		/** @var Container $container */
		$container = new $class();
		// the test entity manager knows no table names until told
		foreach (['em', 'logEm'] as $_name) {
			/** @var TestEntityManager $em */
			$em = $container->getService($_name);
			$em->getClassMetadata(TestAuditLog::class)->setPrimaryTable(['name' => 'audit_log']);
		}

		return $container;
	}

	private function sourceOf(LogMover $mover): Connection
	{
		return new ReflectionProperty(LogMover::class, 'sourceConnection')->getValue($mover);
	}
}
