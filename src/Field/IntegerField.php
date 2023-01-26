<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

readonly class IntegerField extends AbstractSingleField
{
    public int $minimum;
    public int $maximum;
    public ?int $default;
    public bool $searchable;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->minimum = $data['minimum'] ?? -2147483648;
        $this->maximum = $data['maximum'] ?? 2147483647;
        $this->default = $data['default'] ?? null;
        $this->searchable = $data['searchable'] ?? false;
    }

    public static function getTypeName(): string
    {
        return 'integer';
    }

    protected function getDoctrineType(): string
    {
        return match (true) {
            ($this->maximum <= 32767 && $this->minimum >= -32768) => Types::SMALLINT,
            ($this->maximum <= 2147483647 && $this->minimum >= -2147483648) => Types::INTEGER,
            default => Types::BIGINT,
        };
    }

    protected function getDoctrineOptions(): array
    {
        $options = parent::getDoctrineOptions();
        $options['unsigned'] = $this->minimum >= 0;
        $options['default'] = $this->default;
        return $options;
    }

    public function getOpenApiFieldSchema(): array
    {
        $result = parent::getOpenApiFieldSchema();
        $result['type'] = 'integer';

        $result['minimum'] = $this->minimum;
        $result['maximum'] = $this->maximum;

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
            $queryBuilder->andWhere("$alias.$this->name = :$propertyPath");
            $queryBuilder->setParameter($propertyPath, $query[$propertyPath], $this->getDoctrineType());
        }
    }
}