<?php

declare(strict_types=1);

namespace ADT\LogMover\Tests\Fixtures;

use Doctrine\DBAL\Types\JsonType;

/** A custom type built on JsonType, like doctrine-loggable's change_set. */
final class JsonSubtype extends JsonType
{
}
