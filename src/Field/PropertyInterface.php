<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Nemo64\RestToSql\Options;

interface PropertyInterface
{
    public function __construct(Options $data);

    public static function getTypeName(): string;

    public function getPropertyName(): string;

    public function applySqlFieldSchema(Table $table): void;

    /**
     * @param array $components Gives access to the components portion of the schema.
     * @return array The openapi/json schema of the field.
     * @see https://swagger.io/specification/#schema-object
     */
    public function getOpenApiSchema(array &$components): array;

    /**
     * @param array $components Gives access to the components portion of the schema.
     * @param string $propertyPath The property path to the field. This is used to generate the filter parameters.
     * @return array
     * @see https://swagger.io/specification/#schema-object
     */
    public function getOpenApiParameters(array &$components, string $propertyPath): array;

    /**
     * @param QueryBuilder $queryBuilder The query builder that is used to build the query.
     * @param string $alias The alias of the table that is used in the query.
     * @param string $propertyPath The property path to the field. This is used to generate the filter parameters.
     * @param array $query The raw query parameters from the request.
     * @return void
     */
    public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void;

    /**
     * Returns the expression that is used to select the field.
     * It must return a JSON data type or at least a single value that can be a value in the JSON_OBJECT function.
     *
     * @param Connection $connection
     * @param string $alias
     * @return string|null
     */
    public function getSelectExpression(Connection $connection, string $alias): ?string;

    /**
     * @param Connection $connection
     * @param mixed $old
     * @param mixed $new
     * @return array [property => [value, dbal-type], ...]
     * @todo there should be direct support for update queries here
     */
    public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array;
}