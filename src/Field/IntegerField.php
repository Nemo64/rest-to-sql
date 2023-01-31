<?php

namespace Nemo64\RestToSql\Field;

use Doctrine\DBAL\Types\Types;

readonly class IntegerField extends AbstractSingleField
{
    public ?int $default;
    public ?int $example;
    public int $minimum;
    public int $maximum;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->default = $data['default'] ?? null;
        $this->example = $data['example'] ?? null;
        $this->minimum = $data['minimum'] ?? -2147483648;
        $this->maximum = $data['maximum'] ?? 2147483647;
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
        $schema = parent::getOpenApiFieldSchema();
        $schema['type'] = 'integer';
        $schema['format'] = match ($this->getDoctrineType()) {
            Types::BIGINT => 'int64',
            default => 'int32',
            // there is no int16 in the openapi spec
        };

        if ($this->minimum !== -2147483648 || empty($schema['readOnly'])) {
            $schema['minimum'] = $this->minimum;
        }

        if ($this->maximum !== 2147483647 || empty($schema['readOnly'])) {
            $schema['maximum'] = $this->maximum;
        }

        if ($this->default !== null) {
            $schema['default'] = $this->default;
        }

        if ($this->example !== null) {
            $schema['example'] = $this->example;
        }

        return $schema;
    }
}