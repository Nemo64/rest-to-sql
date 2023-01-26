<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

readonly class StringField extends AbstractSingleField
{
    private ?array $enum;
    public ?string $default;
    public int $minLength;
    public int $maxLength;
    public bool $searchable;

    public function __construct(array $data)
    {
        parent::__construct($data);

        $this->enum = isset($data['enum']) ? array_map('strval', $data['enum']) : null;
        $this->default = $data['default'] ?? null;
        if ($this->enum !== null && $this->default !== null && !in_array($this->default, $this->enum, true)) {
            throw new \InvalidArgumentException("The default value {$this->default} is not in the enum.");
        }

        $this->minLength = $data['minLength'] ?? 0;
        $this->maxLength = $data['maxLength'] ?? 50;

        $this->searchable = $data['searchable'] ?? false;
    }

    public static function getTypeName(): string
    {
        return 'string';
    }

    protected function getDoctrineType(): string
    {
        return Types::STRING;
    }

    protected function getDoctrineOptions(): array
    {
        $options = parent::getDoctrineOptions();
        $options['length'] = $this->maxLength;
        $options['default'] = $this->default;
        return $options;
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
            $queryBuilder->andWhere("$alias.{$this->getFieldName()} = :$propertyPath");
            $queryBuilder->setParameter($propertyPath, $query[$propertyPath], Types::STRING);
        }
    }

    public function getOpenApiFieldSchema(): array
    {
        $result = parent::getOpenApiFieldSchema();

        if ($this->enum !== null) {
            $result['enum'] = $this->enum;
        } else {
            $result['maxLength'] = $this->maxLength;
            if ($this->minLength > 0) {
                $result['minLength'] = $this->minLength;
            }
        }

        if ($this->default !== null) {
            $result['default'] = $this->default;
        }

        return $result;
    }
}