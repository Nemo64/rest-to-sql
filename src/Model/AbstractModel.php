<?php

namespace Nemo64\RestToSql\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Nemo64\RestToSql\Field\AutoIncrementIdProperty;
use Nemo64\RestToSql\Field\PropertyInterface;
use Nemo64\RestToSql\Options;
use Nemo64\RestToSql\Types;
use RuntimeException;

abstract readonly class AbstractModel implements ModelInterface
{
    /** @var PropertyInterface[] */
    public array $properties;
    public string $name;
    public string $idProperty;
    public ?string $parentProperty;
    public ?ModelInterface $parent;

    public function __construct(Options $data, ?ModelInterface $parent = null)
    {
        $this->name = $data['name'];
        $this->parent = $parent;

        $this->idProperty = $data['id_property'] ?? 'id';
        $this->parentProperty = $this->parent !== null ? $data['parent_field'] ?? 'parent' : null;

        // TODO allow overwrite of the id
        $property = new AutoIncrementIdProperty(new Options(['name' => $this->idProperty, 'searchable' => true]));
        $fields[$property->getPropertyName()] = $property;

        foreach ($data['properties'] as $name => $property) {
            $type = Types::getType($property['type']);
            $property['name'] ??= $name;
            /** @var PropertyInterface $property */
            $property = new $type($property, $this);
            $fields[$property->getPropertyName()] = $property;
        }

        $this->properties = $fields;
    }

    public function getPropertyName(): string
    {
        return $this->name;
    }

    public function getModelName(): string
    {
        return $this->parent !== null
            ? "{$this->parent->getModelName()}_$this->name"
            : $this->name;
    }

    public function canSelect(): bool
    {
        return true;
    }

    public function canInsert(): bool
    {
        return false;
    }

    public function canUpdate(): bool
    {
        return false;
    }

    public function canDelete(): bool
    {
        return false;
    }

    public function getOpenApiSchema(array &$components): array
    {
        $components['schemas'][$this->getModelName()] = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($this->properties as $field) {
            $fieldSchema = $field->getOpenApiSchema($components);
            $components['schemas'][$this->getModelName()]['properties'][$field->getPropertyName()] = $fieldSchema;
        }

        return [
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/' . $this->getModelName()],
        ];
    }

    public function applySqlFieldSchema(Table $table): void
    {
    }

    public function getOpenApiParameters(array &$components, string $propertyPath): array
    {
        // TODO it should be possible to propagate the filters using joins in the conditions
        if (!empty($propertyPath)) {
            return [];
        }

        $parameters = [];
        foreach ($this->properties as $field) {
            $fieldPropertyPath = ltrim("$propertyPath.{$field->getPropertyName()}", '.');
            foreach ($field->getOpenApiParameters($components, $fieldPropertyPath) as $parameter) {
                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void
    {
        // TODO it should be possible to propagate the filters using joins
        if (!empty($propertyPath)) {
            return;
        }

        foreach ($this->properties as $field) {
            $fieldPropertyPath = ltrim("$propertyPath.{$field->getPropertyName()}", '.');
            $field->applyFilters($queryBuilder, $alias, $fieldPropertyPath, $query);
        }
    }

    public function getSelectExpression(Connection $connection, string $alias): string
    {
        $queryBuilder = $this->createSelectQueryBuilder($connection);
        $databasePlatform = $connection->getDatabasePlatform();
        if ($databasePlatform instanceof MySQLPlatform) {
            $queryBuilder->select("JSON_ARRAYAGG({$queryBuilder->getQueryPart('select')[0]})");
        } else if ($databasePlatform instanceof SqlitePlatform) {
            $queryBuilder->select("JSON_GROUP_ARRAY({$queryBuilder->getQueryPart('select')[0]})");
        } else if ($databasePlatform instanceof PostgreSqlPlatform) {
            $queryBuilder->select("JSON_AGG({$queryBuilder->getQueryPart('select')[0]})");
        } else {
            throw new RuntimeException('Unsupported database platform: ' . get_class($databasePlatform));
        }

        // TODO figure out a way to make this work
        if ($queryBuilder->getQueryPart('groupBy')) {
            throw new RuntimeException('Cannot use a view with a groupBy in a select expression as a field yet.');
        }

        return "COALESCE(({$queryBuilder->getSQL()}), JSON_ARRAY())";
    }

    public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array
    {
        return [];
    }
}