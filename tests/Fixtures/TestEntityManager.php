<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;

/**
 * An entity manager that only hands out metadata and a connection - all LogMover and
 * PrintSchemaCommand need from it. Metadata is built by hand in the tests, so they need
 * neither a mapping driver nor a database.
 */
final class TestEntityManager extends EntityManager
{
	/** @var array<class-string, ClassMetadata> */
	private array $metadata = [];

	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct(private readonly ?Connection $testConnection = null)
	{
	}

	public function getConnection(): Connection
	{
		return $this->testConnection ?? throw new \LogicException('No connection given to TestEntityManager.');
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @return ClassMetadata<T>
	 */
	public function getClassMetadata(string $className): ClassMetadata
	{
		if (!isset($this->metadata[$className])) {
			$metadata = new ClassMetadata($className);
			$metadata->initializeReflection(new RuntimeReflectionService());
			$this->metadata[$className] = $metadata;
		}

		return $this->metadata[$className];
	}
}
