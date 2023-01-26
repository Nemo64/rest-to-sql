<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

readonly class AutoIncrementIdField implements FieldInterface
{
    public string $name;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
    }

    public static function getTypeName(): string
    {
        return 'auto_increment_id';
    }

    public function getFieldName(): string
    {
        return $this->name;
    }

    public function applySqlFieldSchema(Table $table): void
    {
        $table->addColumn(
            name: $this->name,
            typeName: Types::INTEGER,
            options: ['unsigned' => true, 'autoincrement' => true],
        );
    }

    public function getSelectExpression(Connection $connection, string $alias): string
    {
        return "$alias.$this->name";
    }

    public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array
    {
        return []; // auto increment fields are not updatable
    }

    public function getOpenApiFieldSchema(): array
    {
        return [
            'type' => 'integer',
            'readOnly' => true,
        ];
    }

    public function getOpenApiFilterParameters(string $propertyPath): array
    {
        $result = [];

        $result[] = [
            'name' => $propertyPath,
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'integer'],
        ];

        return $result;
    }

    public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void
    {
        if (isset($query[$propertyPath])) {
            $queryBuilder->andWhere("$alias.$this->name = :$propertyPath");
            $queryBuilder->setParameter($propertyPath, $query[$propertyPath], Types::INTEGER);
        }
    }
}