<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests;

use ADT\LogMover\Console\MoveCommand;
use ADT\LogMover\Console\PrintSchemaCommand;
use ADT\LogMover\DI\LogMoverExtension;
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

		$configFile = $tempDir . '/' . md5($key) . '.neon';
		file_put_contents($configFile, $services . "\n" . $config);

		$class = new ContainerLoader($tempDir, true)->load(static function (Compiler $compiler) use ($configFile): void {
			$compiler->addExtension('logMover', new LogMoverExtension());
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
