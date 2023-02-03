<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Nemo64\RestToSql\Options;

readonly class BooleanProperty extends AbstractSingleProperty
{
    public ?bool $default;

    public function __construct(Options $data)
    {
        parent::__construct($data);
        $this->default = $data['default'] ?? null;
    }

    public static function getTypeName(): string
    {
        return 'boolean';
    }

    protected function getDoctrineType(): string
    {
        return Types::BOOLEAN;
    }

    protected function getDoctrineOptions(): array
    {
        $options = parent::getDoctrineOptions();
        $options['default'] = $this->default;
        return $options;
    }

    public function getSelectExpression(Connection $connection, string $alias): ?string
    {
        $condition = parent::getSelectExpression($connection, $alias);
        if ($condition === null) {
            return null;
        }

        // sqlite does not have a boolean type, so we have to convert it to json
        if ($connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $condition = "CASE WHEN $condition THEN JSON('true') ELSE JSON('false') END";
        }

        return $condition;
    }

    protected function normalizeDatabaseValue(Connection $connection, mixed $value): bool
    {
        return (bool)$value;
    }

    public function getOpenApiFieldSchema(): array
    {
        $result = parent::getOpenApiFieldSchema();
        $result['type'] = 'boolean';

        if ($this->default !== null) {
            $result['default'] = $this->default;
        }

        return $result;
    }

    protected function getOpenApiSearchParameters(string $propertyPath): array
    {
        return [
            [
                'name' => $propertyPath,
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'boolean'],
            ],
        ];
    }

    protected function applySearchFilters(QueryBuilder $queryBuilder, string $selectExpression, string $propertyPath, array $query): void
    {
        if (!isset($query[$propertyPath]) || !is_string($query[$propertyPath])) {
            return;
        }

        $value = match (strtolower($query[$propertyPath])) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => throw new \InvalidArgumentException("The value {$query[$propertyPath]} is not a valid boolean value."),
        };

        $queryBuilder->andWhere("$selectExpression = :$propertyPath");
        $queryBuilder->setParameter($propertyPath, $value, ParameterType::BOOLEAN);
    }
}