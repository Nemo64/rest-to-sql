<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;

abstract readonly class AbstractSingleField implements FieldInterface
{
    public string $name;
    public bool $nullable;
    public ?string $select;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->nullable = $data['nullable'] ?? false;
        $this->select = $data['select'] ?? null;
    }

    public function getFieldName(): string
    {
        return $this->name;
    }

    abstract protected function getDoctrineType(): string;

    protected function getDoctrineOptions(): array {
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
            name: $this->name,
            typeName: $this->getDoctrineType(),
            options: $this->getDoctrineOptions(),
        );
    }

    public function getOpenApiFieldSchema(): array
    {
        $result = [
            'type' => 'string',
        ];

        if ($this->nullable) {
            $result['nullable'] = true;
        }

        if ($this->select) {
            $result['readOnly'] = true;
            $result['description'] = $this->select;
        }

        return $result;
    }

    public function getSelectExpression(Connection $connection, string $alias): string
    {
        if ($this->select) {
            return preg_replace('#\bthis\b#', $alias, $this->select);
        }

        return "$alias.$this->name";
    }

    public function getUpdateValues(Connection $connection, mixed $old, mixed $new): array
    {
        if ($this->select) {
            return [];
        }

        if ($old !== $new) {
            return [$this->getFieldName() => $new];
        }

        return [];
    }
}