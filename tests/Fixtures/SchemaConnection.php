<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Target connection for the schema printout; the test picks the platform to see the dialect. */
final class SchemaConnection extends Connection
{
	/**
	 * @param array<string, mixed> $connectionParams
	 * @noinspection PhpMissingParentConstructorInspection
	 */
	public function __construct(
		private readonly array $connectionParams = ['dbname' => 'pokladna_cashdesk', 'user' => 'pokladna'],
		private readonly ?AbstractPlatform $platform = null,
	) {
	}

	public function getDatabasePlatform(): AbstractPlatform
	{
		return $this->platform ?? new PostgreSQLPlatform();
	}

	public function getParams(): array
	{
		return $this->connectionParams;
	}
}
