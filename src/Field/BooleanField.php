<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

readonly class BooleanField extends AbstractSingleField
{
    public ?bool $default;
    public bool $searchable;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->default = $data['default'] ?? null;
        $this->searchable = $data['searchable'] ?? false;
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

    public function getSelectExpression(Connection $connection, string $alias): string
    {
        $condition = parent::getSelectExpression($connection, $alias);

        if ($connection->getDatabasePlatform() instanceof SqlitePlatform) {
            $condition = "CASE WHEN $condition THEN JSON('true') ELSE JSON('false') END";
        }

        return $condition;
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

    public function getOpenApiFilterParameters(string $propertyPath): array
    {
        $result = [];

        $openApiFieldSchema = $this->getOpenApiFieldSchema();
        unset($openApiFieldSchema['readOnly'], $openApiFieldSchema['writeOnly']);

        if ($this->searchable) {
            $result[] = [
                'name' => $propertyPath,
                'in' => 'query',
                'required' => false,
                'schema' => $openApiFieldSchema,
            ];
        }

        return $result;
    }

    public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void
    {
        if ($this->searchable && isset($query[$propertyPath])) {
            $value = match (strtolower($query[$propertyPath])) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => throw new \InvalidArgumentException("The value {$query[$propertyPath]} is not a valid boolean value."),
            };
            $queryBuilder->andWhere("$alias.$this->name = :$this->name");
            $queryBuilder->setParameter($this->name, $value, $this->getDoctrineType());
        }
    }
}