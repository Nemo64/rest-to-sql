<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;

interface FieldInterface
{
    public function __construct(array $data);

    public static function getTypeName(): string;

    public function getFieldName(): string;

    public function applySqlFieldSchema(Table $table): void;

    /**
     * @return array
     * @see https://swagger.io/specification/#schema-object
     */
    public function getOpenApiFieldSchema(): array;

    /**
     * @return array
     * @see https://swagger.io/specification/#schema-object
     */
    public function getOpenApiFilterParameters(string $propertyPath): array;

    public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void;

    /**
     * Returns the expression that is used to select the field.
     * It must return a JSON data type or at least a single value that can be a value in the JSON_OBJECT function.
     *
     * @param Connection $connection
     * @param string $alias
     * @return string
     */
    public function getSelectExpression(Connection $connection, string $alias): string;

    public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array;
}