<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests;

use ADT\LogMover\Console\PrintSchemaCommand;
use ADT\LogMover\Tests\Fixtures\JsonSubtype;
use ADT\LogMover\Tests\Fixtures\SchemaConnection;
use ADT\LogMover\Tests\Fixtures\TestAuditLog;
use ADT\LogMover\Tests\Fixtures\TestEntityManager;
use ADT\LogMover\Tests\Fixtures\TestRequestLog;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Printing the SQL that creates the target log storage.
 *
 * The schema is derived from the entities so that it does not drift from the source -
 * hand-written SQL next to the entities drifts and nobody notices until the move fails
 * on an unknown column.
 */
final class PrintSchemaCommandTest extends TestCase
{
	public function testCreatesUsersAndDatabaseFromTheConnection(): void
	{
		// The application has no access to the target server - hence a printout to run by hand.
		$sql = $this->printSchema([]);

		// the extension after CREATE DATABASE: it is created in the database where it is used
		self::assertGreaterThan(strpos($sql, 'CREATE DATABASE'), strpos($sql, 'CREATE EXTENSION IF NOT EXISTS timescaledb'));
		self::assertStringContainsString('CREATE USER "pokladna"', $sql);
		self::assertStringContainsString('CREATE DATABASE "pokladna_cashdesk"', $sql);
		// the password is not printed
		self::assertStringContainsString('<password>', $sql);
	}

	public function testTimeHasAZoneInPostgresTarget(): void
	{
		// The source is UTC but the column does not say so; the mover sends an offset, so the
		// target has to accept it - otherwise it drops it and later nobody knows what it is in.
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertStringContainsString('WITH TIME ZONE', $sql);
		self::assertStringNotContainsString('WITHOUT TIME ZONE', $sql);
	}

	public function testTimeKeepsSubSecondPrecisionInPostgresTarget(): void
	{
		// Regression: DBAL declares TIMESTAMP(0), so milliseconds written by the request
		// logger were silently rounded away and requests within a second lost their order.
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertStringContainsString('created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL', $sql);
		self::assertStringNotContainsString('TIMESTAMP(0)', $sql);
	}

	public function testNullableTimeStaysNullable(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertMatchesRegularExpression('~processed_at TIMESTAMP\(6\) WITH TIME ZONE(,|\s*\))~', $sql);
	}

	public function testJsonIsJsonbInPostgresTarget(): void
	{
		// Payloads are searched during incidents; JSONB can be indexed and queried without
		// re-parsing every row. Regardless of whether the entity asks for it - the request
		// logger trait maps plain `json`.
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertStringContainsString('payload JSONB', $sql);
		self::assertStringContainsString('created_by JSONB', $sql);
		self::assertDoesNotMatchRegularExpression('~ JSON(,|\s|\))~', $sql);
	}

	public function testCustomJsonTypeIsJsonbToo(): void
	{
		// Regression: doctrine-loggable maps change_log.change_set with its own type built
		// on JsonType, and only the exact `json` type used to become JSONB.
		if (!Type::hasType('test_json_subtype')) {
			Type::addType('test_json_subtype', JsonSubtype::class);
		}

		$sql = $this->printSchema([['entity' => TestAuditLog::class]], extraField: ['fieldName' => 'changeSet', 'type' => 'test_json_subtype', 'columnName' => 'change_set']);

		self::assertStringContainsString('change_set JSONB NOT NULL', $sql);
	}

	public function testJsonStaysJsonInMySqlTarget(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class]], new SchemaConnection(platform: new MySQLPlatform()));

		self::assertStringContainsString('created_by JSON', $sql);
		self::assertStringNotContainsString('JSONB', $sql);
	}

	public function testEmptyDatabaseNameIsVisibleInThePrintout(): void
	{
		// The configuration commonly has an empty value (the stage fills it in);
		// CREATE DATABASE "" might go unnoticed.
		$sql = $this->printSchema([], new SchemaConnection(['dbname' => '', 'user' => '']));

		self::assertStringContainsString('<database>', $sql);
		self::assertStringContainsString('<user>', $sql);
	}

	public function testTableIsDerivedFromTheEntityInTheTargetDialect(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertStringContainsString('CREATE TABLE audit_log', $sql);
		self::assertStringContainsString('action', $sql);
		self::assertStringContainsString('correlation_id', $sql);
		// PostgreSQL, not MySQL: no backticks and no AUTO_INCREMENT
		self::assertStringNotContainsString('`', $sql);
		self::assertStringNotContainsString('AUTO_INCREMENT', $sql);
	}

	public function testIdIsNotGeneratedInTheTarget(): void
	{
		// If the target assigned it itself, the link to the source would be lost and a repeated
		// run after an interruption would write the same thing twice.
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertStringNotContainsString('SERIAL', strtoupper($sql));
		self::assertStringNotContainsString('GENERATED BY', strtoupper($sql));
		self::assertStringNotContainsString('GENERATED ALWAYS', strtoupper($sql));
	}

	public function testTargetTableCanBeRenamed(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class, 'table' => 'audit_log_archive']]);

		self::assertStringContainsString('CREATE TABLE audit_log_archive', $sql);
	}

	public function testDurationsAddHypertableCompressionAndRetention(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class, 'hot' => '3 months', 'retention' => '13 months']]);

		self::assertStringContainsString("create_hypertable('audit_log', 'created_at')", $sql);
		self::assertStringContainsString("add_compression_policy('audit_log', INTERVAL '3 months')", $sql);
		self::assertStringContainsString("add_retention_policy('audit_log', INTERVAL '13 months')", $sql);
	}

	public function testHypertablePrimaryKeyIncludesThePartitioningColumn(): void
	{
		// TimescaleDB refuses to partition the table otherwise.
		$sql = $this->printSchema([['entity' => TestAuditLog::class, 'hot' => '3 months']]);

		self::assertMatchesRegularExpression('~PRIMARY KEY\s*\(id, created_at\)~', $sql);
	}

	public function testWithoutDurationsNoHypertable(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class]]);

		self::assertStringNotContainsString('create_hypertable', $sql);
		self::assertMatchesRegularExpression('~PRIMARY KEY\s*\(id\)~', $sql);
	}

	public function testOwningAssociationBecomesAPlainIdColumnWithoutForeignKey(): void
	{
		// TimescaleDB does not allow a foreign key pointing at a hypertable, and a body may
		// arrive before its header anyway.
		$sql = $this->printSchema([['entity' => TestRequestLog::class]]);

		self::assertStringContainsString('parent_id BIGINT NOT NULL', $sql);
		self::assertStringNotContainsString('FOREIGN KEY', $sql);
	}

	public function testUnreadableTableGetsInsertOnly(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class, 'readable' => false]]);

		self::assertStringContainsString('GRANT INSERT ON audit_log TO "pokladna";', $sql);
		self::assertStringNotContainsString('GRANT SELECT, INSERT ON audit_log', $sql);
	}

	public function testMySqlTargetKeepsItsDialect(): void
	{
		$sql = $this->printSchema([['entity' => TestAuditLog::class]], new SchemaConnection(platform: new MySQLPlatform()));

		self::assertStringContainsString('CREATE DATABASE `pokladna_cashdesk`', $sql);
		self::assertStringContainsString('DATETIME', $sql);
		self::assertStringNotContainsString('TIME ZONE', $sql);
	}

	/** @param list<array<string, mixed>> $tables */
	/** @param array<string, mixed>|null $extraField additional field mapped on TestAuditLog */
	private function printSchema(array $tables, ?SchemaConnection $target = null, ?array $extraField = null): string
	{
		// the command reads only metadata, it does not touch the source database
		$em = new TestEntityManager();

		$meta = $em->getClassMetadata(TestAuditLog::class);
		$meta->setPrimaryTable(['name' => 'audit_log']);
		$meta->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
		$meta->mapField(['fieldName' => 'id', 'type' => 'bigint', 'id' => true]);
		$meta->mapField(['fieldName' => 'action', 'type' => 'string', 'length' => 255]);
		$meta->mapField(['fieldName' => 'createdAt', 'type' => 'datetime_immutable', 'columnName' => 'created_at']);
		$meta->mapField(['fieldName' => 'processedAt', 'type' => 'datetime_immutable', 'columnName' => 'processed_at', 'nullable' => true]);
		$meta->mapField(['fieldName' => 'correlationId', 'type' => 'string', 'length' => 255, 'nullable' => true, 'columnName' => 'correlation_id']);
		$meta->mapField(['fieldName' => 'createdBy', 'type' => 'json', 'nullable' => true, 'columnName' => 'created_by']);
		$meta->mapField(['fieldName' => 'payload', 'type' => 'json', 'nullable' => true, 'options' => ['jsonb' => true]]);
		if ($extraField !== null) {
			$meta->mapField($extraField);
		}
		$meta->table['indexes'] = ['audit_log_action' => ['columns' => ['action']]];

		$meta = $em->getClassMetadata(TestRequestLog::class);
		$meta->setPrimaryTable(['name' => 'request_log_body']);
		$meta->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
		$meta->mapField(['fieldName' => 'id', 'type' => 'bigint', 'id' => true]);
		$meta->mapField(['fieldName' => 'createdAt', 'type' => 'datetime_immutable', 'columnName' => 'created_at']);
		$meta->mapOneToOne([
			'fieldName' => 'parent',
			'targetEntity' => TestAuditLog::class,
			'joinColumns' => [['name' => 'parent_id', 'referencedColumnName' => 'id', 'nullable' => false]],
		]);

		$tester = new CommandTester(new PrintSchemaCommand($em, $target ?? new SchemaConnection(), $tables));
		$tester->execute([]);

		return $tester->getDisplay();
	}
}
