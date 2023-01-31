<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

readonly class AutoIncrementIdField implements FieldInterface
{
    private string $name;

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
            name: $this->getFieldName(),
            typeName: Types::INTEGER,
            options: ['unsigned' => true, 'autoincrement' => true],
        );
        $table->setPrimaryKey([$this->getFieldName()]);
    }

    public function getSelectExpression(Connection $connection, string $alias): string
    {
        return "$alias.{$this->getFieldName()}";
    }

    public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array
    {
        return []; // auto increment fields are not updatable
    }

    public function getOpenApiFieldSchema(): array
    {
        return [
            'type' => 'integer',
            'format' => 'int32',
            'readOnly' => true,
        ];
    }

    public function getOpenApiFilterParameters(string $propertyPath): array
    {
        $result = [];

        $result[] = [
            'name' => $propertyPath . '[]',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'format' => 'int32']],
        ];

        return $result;
    }

    public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void
    {
        if (!isset($query[$propertyPath])) {
            return;
        }

        $values = array_filter((array)$query[$propertyPath], 'is_numeric');
        if (count($values) === 1) {
            $queryBuilder->andWhere("$alias.{$this->getFieldName()} = :$propertyPath");
            $queryBuilder->setParameter($propertyPath, reset($values), Types::INTEGER);
        } else if (count($values) > 1) {
            $queryBuilder->andWhere("$alias.{$this->getFieldName()} IN (:$propertyPath)");
            $queryBuilder->setParameter($propertyPath, $values, Connection::PARAM_INT_ARRAY);
        }
    }
}