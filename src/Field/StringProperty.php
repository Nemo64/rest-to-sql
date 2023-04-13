<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Nemo64\RestToSql\Options;

readonly class StringProperty extends AbstractSingleProperty
{
    public ?string $default;
    public ?string $example;
    private ?array $enum;
    public int $minLength;
    public ?int $maxLength;

    public function __construct(Options $data)
    {
        parent::__construct($data);
        $this->default = $data['default'] ?? null;
        $this->example = $data['example'] ?? null;

        $this->enum = isset($data['enum']) ? array_map('strval', iterator_to_array($data['enum'])) : null;
        if ($this->enum !== null && $this->default !== null && !in_array($this->default, $this->enum, true)) {
            throw new \InvalidArgumentException("The default value {$this->default} is not in the enum.");
        }

        $this->minLength = $data['minLength'] ?? 0;
        $this->maxLength = $data['maxLength'] ?? ($this->select !== null ? null : 50);

    }

    public static function getTypeName(): string
    {
        return 'string';
    }

    protected function getDoctrineType(): string
    {
        if ($this->indexed || $this->searchable) {
            return Types::STRING;
        }

        if ($this->default !== null) {
            return Types::STRING;
        }

        if ($this->maxLength <= 255) {
            return Types::STRING;
        }

        return Types::TEXT;
    }

    protected function getDoctrineOptions(): array
    {
        $options = parent::getDoctrineOptions();
        $options['length'] = $this->maxLength;
        $options['default'] = $this->default;
        return $options;
    }

    public function getOpenApiSchema(array &$components): array
    {
        $schema = parent::getOpenApiSchema($components);

        if ($this->enum !== null) {
            $schema['enum'] = $this->enum;
        } else {
            if ($this->minLength > 0) {
                $schema['minLength'] = $this->minLength;
            }
            if ($this->maxLength !== null) {
                $schema['maxLength'] = $this->maxLength;
            }
        }

        if ($this->default !== null) {
            $schema['default'] = $this->default;
        }

        if ($this->example !== null) {
            $schema['example'] = $this->example;
        }

        return $schema;
    }

    protected function getOpenApiSearchParameters(array &$components, string $propertyPath): array
    {
        return [
            [
                'name' => $propertyPath . "[]",
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    protected function applySearchFilters(QueryBuilder $queryBuilder, string $selectExpression, string $propertyPath, array $query): void
    {
        if (!isset($query[$propertyPath])) {
            return;
        }

        $values = array_filter((array)$query[$propertyPath], 'strlen');
        if (count($values) === 1) {
            $queryBuilder->andWhere("$selectExpression = :$propertyPath");
            $queryBuilder->setParameter($propertyPath, reset($values), $this->getDoctrineType());
        } else if (count($values) > 1) {
            $queryBuilder->andWhere("$selectExpression IN (:$propertyPath)");
            $queryBuilder->setParameter($propertyPath, $values, Connection::PARAM_STR_ARRAY);
        }
    }
}