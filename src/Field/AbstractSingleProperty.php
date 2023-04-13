<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Nemo64\RestToSql\Options;

abstract readonly class AbstractSingleProperty implements PropertyInterface
{
    /** access @see getPropertyName instead */
    private string $name;
    public ?string $description;
    public ?string $select;
    public bool $nullable;
    public bool $searchable;
    public bool $sortable;
    public bool $unique;
    public bool $indexed;

    public function __construct(Options $data)
    {
        $this->name = $data['name'];
        $this->description = $data['description'] ?? null;
        $this->select = $data['select'] ?? null;
        $this->nullable = $data['nullable'] ?? false;
        $this->searchable = $data['searchable'] ?? false;
        $this->sortable = $data['sortable'] ?? false;
        $this->unique = $data['unique'] ?? false;
        // TODO figure out if that is a good assumption
        $this->indexed = $data['indexed'] ?? ($this->searchable || $this->sortable || $this->unique);

        if ($this->unique && !$this->indexed) {
            throw new \RuntimeException("A unique field has to be indexed.");
        }
    }

    final public function getPropertyName(): string
    {
        return $this->name;
    }

    abstract protected function getDoctrineType(): string;

    protected function getDoctrineOptions(): array
    {
        return [
            'notNull' => !$this->nullable,
        ];
    }

    final public function applySqlFieldSchema(Table $table): void
    {
        if ($this->select !== null) {
            return;
        }

        $table->addColumn(
            name: $this->getPropertyName(),
            typeName: $this->getDoctrineType(),
            options: $this->getDoctrineOptions(),
        );

        if ($this->unique) {
            $table->addUniqueIndex([$this->getPropertyName()]);
        } else if ($this->indexed) {
            $table->addIndex([$this->getPropertyName()]);
        }
    }

    public function getOpenApiSchema(array &$components): array
    {
        $result = [
            'type' => 'string',
        ];

        if ($this->description !== null) {
            $result['description'] = $this->description;
        } else if ($this->select !== null) {
            $result['description'] = $this->select;
        }

        if ($this->nullable) {
            $result['nullable'] = true;
        }

        if ($this->select !== null) {
            $result['readOnly'] = true;
        }

        return $result;
    }

    /**
     * {@see getSelectExpression} has to return the value formatted for the api.
     * E.g. a date has to be formatted.
     *
     * This method only runs the minimal sql query to get the value.
     * Use this in {@see applyFilters} for the internal value comparison.
     */
    final protected function getRawSelectExpression(string $alias): ?string
    {
        if ($this->select) {
            return preg_replace('#\bthis\b#', $alias, $this->select);
        }

        return "$alias.{$this->getPropertyName()}";
    }

    /**
     * Allows to modify a value from the database to the api.
     * Or better: allows to write the expression the database has to run to get the value for the api.
     * The inverse to {@see normalizeDatabaseValue}.
     */
    public function getSelectExpression(Connection $connection, string $alias): ?string
    {
        return $this->getRawSelectExpression($alias);
    }

    /**
     * Allows to modify a value before writing it to the database.
     * The inverse to {@see getSelectExpression}.
     * The value must work as dbal parameter with the type returned by {@see getDoctrineType}.
     */
    protected function normalizeDatabaseValue(Connection $connection, mixed $value): mixed
    {
        return $value;
    }

    final public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array
    {
        if ($this->select) {
            return [];
        }

        if ($old !== $new) {
            return [
                $this->getPropertyName() => [
                    $this->normalizeDatabaseValue($connection, $new),
                    $this->getDoctrineType(),
                ]
            ];
        }

        return [];
    }


    protected function getOpenApiSearchParameters(array &$components, string $propertyPath): array
    {
        $filterName = $propertyPath . '[]';
        return [
            [
                'name' => $filterName,
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                'description' => <<<DESCRIPTION
                    You can specify a range with a comma. For example `?$filterName=1,5` will select all values between 1 and 5.  
                    If you omit one value, it will be interpreted as infinity. For example `?$filterName=,5` will select all values smaller than 5.  
                    You can also specify multiple ranges. For example `?$filterName=1,5&$filterName=10,15` will select all values between 1 and 5 and between 10 and 15.
                    DESCRIPTION,
            ],
        ];
    }

    protected function getOpenApiSortParameters(array &$components, string $propertyPath): array
    {
        return []; // TODO implement sort filters
    }

    final public function getOpenApiParameters(array &$components, string $propertyPath): array
    {
        $parameters = [];

        if ($this->searchable) {
            array_push($parameters, ...$this->getOpenApiSearchParameters($components, $propertyPath));
        }

        if ($this->sortable) {
            array_push($parameters, ...$this->getOpenApiSortParameters($components, $propertyPath));
        }

        return $parameters;
    }

    protected function applySearchFilters(QueryBuilder $queryBuilder, string $selectExpression, string $propertyPath, array $query): void
    {
        if (!isset($query[$propertyPath])) {
            return;
        }

        $values = array_values((array)$query[$propertyPath]);
        $expressions = [];

        foreach ($values as $index => $value) {
            $range = explode(',', $value);
            if (count($range) === 1) {
                $expressions[] = "$selectExpression >= :{$propertyPath}_{$index}";
                $queryBuilder->setParameter("{$propertyPath}_{$index}", $this->normalizeDatabaseValue($queryBuilder->getConnection(), $range[0]), $this->getDoctrineType());
            } else if (!empty($range[0]) && !empty($range[1])) {
                $expressions[] = "$selectExpression BETWEEN :{$propertyPath}_{$index}_min AND :{$propertyPath}_{$index}_max";
                $queryBuilder->setParameter("{$propertyPath}_{$index}_min", $this->normalizeDatabaseValue($queryBuilder->getConnection(), $range[0]), $this->getDoctrineType());
                $queryBuilder->setParameter("{$propertyPath}_{$index}_max", $this->normalizeDatabaseValue($queryBuilder->getConnection(), $range[1]), $this->getDoctrineType());
            } else if (!empty($range[0])) {
                $expressions[] = "$selectExpression >= :{$propertyPath}_{$index}_min";
                $queryBuilder->setParameter("{$propertyPath}_{$index}_min", $this->normalizeDatabaseValue($queryBuilder->getConnection(), $range[0]), $this->getDoctrineType());
            } else if (!empty($range[1])) {
                $expressions[] = "$selectExpression <= :{$propertyPath}_{$index}_max";
                $queryBuilder->setParameter("{$propertyPath}_{$index}_max", $this->normalizeDatabaseValue($queryBuilder->getConnection(), $range[1]), $this->getDoctrineType());
            }
        }

        if (count($expressions) > 0) {
            // TODO this might not work with a select expression. There we might have to use having when a group expression is used
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$expressions));
        }
    }

    protected function applySortFilters(QueryBuilder $queryBuilder, string $selectExpression, string $propertyPath, array $query): void
    {
        // TODO implement sort filters
    }

    final public function applyFilters(QueryBuilder $queryBuilder, string $alias, string $propertyPath, array $query): void
    {
        $selectExpression = $this->getRawSelectExpression($alias);

        if ($this->searchable) {
            $this->applySearchFilters($queryBuilder, $selectExpression, $propertyPath, $query);
        }

        if ($this->sortable) {
            $this->applySortFilters($queryBuilder, $selectExpression, $propertyPath, $query);
        }
    }
}