<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Types\Types;

readonly class DateField extends AbstractSingleField
{
    public ?string $default;
    public ?string $example;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->default = $data['default'] ?? null;
        $this->example = $data['example'] ?? null;
    }

    protected function getDoctrineType(): string
    {
        return Types::DATE_IMMUTABLE;
    }

    public static function getTypeName(): string
    {
        return 'date';
    }

    public function getOpenApiFieldSchema(): array
    {
        $schema = parent::getOpenApiFieldSchema();
        $schema['format'] = 'date';

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
            $expression = "DATE_FORMAT($expression, '%Y-%m-%d')";
        } else if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $expression = "TO_CHAR($expression, 'YYYY-MM-DD')";
        } else if ($connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $expression = "strftime('%Y-%m-%d', $expression)";
        } else {
            throw new \RuntimeException("Unsupported database platform");
        }
        return $expression;
    }

    protected function normalizeDatabaseValue(Connection $connection, mixed $value): \DateTimeImmutable
    {
        // no timezone conversion here, as we just want to pass the date though as is
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}