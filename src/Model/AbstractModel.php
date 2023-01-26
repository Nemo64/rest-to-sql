<?php

namespace Nemo64\RestToSql\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Nemo64\RestToSql\Field\AutoIncrementIdField;
use Nemo64\RestToSql\Field\FieldInterface;
use Nemo64\RestToSql\Types;
use RuntimeException;

abstract readonly class AbstractModel implements ModelInterface
{
    /** @var FieldInterface[] */
    private array $fields;
    public string $name;
    public string $idField;
    public ?string $parentField;
    public ?ModelInterface $parent;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->parent = $data['parent'] ?? null;
        $this->idField = $data['id_field'] ?? 'id';
        $this->parentField = $this->parent !== null ? $data['parent_field'] ?? 'parent' : null;

        // TODO allow overwrite of the id
        $field = new AutoIncrementIdField(['name' => $this->idField, 'searchable' => true]);
        $fields[$field->getFieldName()] = $field;

        foreach ($data['fields'] as $name => $field) {
            $type = Types::getType($field['type']);
            $options = $field + ['name' => $name, 'parent' => $this];
            $field = new $type($options);
            $fields[$field->getFieldName()] = $field;
        }

        $this->fields = $fields;
    }

    public function getFieldName(): string
    {
        return $this->name;
    }

    public function getPropertyPath(): array
    {
        if ($this->parent === null) {
            return [];
        }

        return [...$this->parent->getPropertyPath(), $this->name];
    }

    public function getTableName(): string
    {
        return $this->parent !== null
            ? "{$this->parent->getTableName()}_$this->name"
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

    /** @return FieldInterface[] */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function applyOpenApiComponents(array &$schema): void
    {
        $schema['components']['schemas'][$this->getTableName()] = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($this->getFields() as $field) {
            if ($field instanceof ModelInterface) {
                $field->applyOpenApiComponents($schema);
            }

            $schema['components']['schemas'][$this->getTableName()]['properties'][$field->getFieldName()] = $field->getOpenApiFieldSchema();
        }
    }

    public function applySqlFieldSchema(Table $table): void
    {
    }

    public function getOpenApiFilterParameters(string $propertyPath): array
    {
        // TODO it should be possible to propagate the filters using joins in the conditions
        if (!empty($propertyPath)) {
            return [];
        }

        $parameters = [];
        foreach ($this->getFields() as $field) {
            $fieldPropertyPath = ltrim("$propertyPath.{$field->getFieldName()}", '.');
            foreach ($field->getOpenApiFilterParameters($fieldPropertyPath) as $parameter) {
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

        foreach ($this->getFields() as $field) {
            $fieldPropertyPath = ltrim("$propertyPath.{$field->getFieldName()}", '.');
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


    public function getOpenApiFieldSchema(): array
    {
        return [
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/' . $this->getTableName()],
        ];
    }
}