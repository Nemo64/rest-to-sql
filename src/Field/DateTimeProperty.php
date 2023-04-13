<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Types\Types;
use Nemo64\RestToSql\Options;

readonly class DateTimeProperty extends AbstractSingleProperty
{
    public ?string $default;
    public ?string $example;

    public function __construct(Options $data)
    {
        parent::__construct($data);
        $this->default = $data['default'] ?? null;
        $this->example = $data['example'] ?? null;
    }

    protected function getDoctrineType(): string
    {
        return Types::DATETIME_IMMUTABLE;
    }

    public static function getTypeName(): string
    {
        return 'date-time';
    }

    public function getOpenApiSchema(array &$components): array
    {
        $schema = parent::getOpenApiSchema($components);
        $schema['format'] = 'date-time';

        if ($this->default !== null) {
            $schema['default'] = $this->default;
        }

        if ($this->example !== null) {
            $schema['example'] = $this->example;
        }

        return $schema;
    }

    public function getSelectExpression(Connection $connection, string $alias): ?string
    {
        $expression = parent::getSelectExpression($connection, $alias);
        if ($connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $expression = "DATE_FORMAT($expression, '%Y-%m-%dT%TZ')";
        } else if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $expression = "TO_CHAR($expression, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";
        } else if ($connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $expression = "strftime('%Y-%m-%dT%H:%M:%SZ', $expression)";
        } else {
            throw new \RuntimeException("Unsupported database platform");
        }
        return $expression;
    }

    protected function normalizeDatabaseValue(Connection $connection, mixed $value): \DateTimeImmutable
    {
        $dateTime = new \DateTimeImmutable($value);
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        return $dateTime;
    }
}