<?php

namespace Nemo64\RestToSql;

use Nemo64\RestToSql\Field;
use Nemo64\RestToSql\Model;

class Types
{
    private static array $types = [];

    public static function registerType(string $class): void
    {
        if (!is_a($class, Field\PropertyInterface::class, true)) {
            throw new \RuntimeException("The class $class does not implement " . Field\PropertyInterface::class);
        }

        self::$types[$class::getTypeName()] = $class;
    }

    public static function getType(string $type): string
    {
        $class = self::$types[$type] ?? null;
        if ($class === null) {
            throw new \RuntimeException("The field type $type is not registered.");
        }

        return $class;
    }
}

Types::registerType(Field\AutoIncrementIdProperty::class);
Types::registerType(Field\BooleanProperty::class);
Types::registerType(Field\DateTimeProperty::class);
Types::registerType(Field\IntegerProperty::class);
Types::registerType(Field\StringProperty::class);

Types::registerType(Model\TableModel::class);
Types::registerType(Model\ViewModel::class);
