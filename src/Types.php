<?php

namespace Nemo64\RestToSql;

use Nemo64\RestToSql\Field;
use Nemo64\RestToSql\Model;

class Types
{
    private static array $types = [];

    public static function registerType(string $class): void
    {
        if (!is_a($class, Field\FieldInterface::class, true)) {
            throw new \RuntimeException("The class $class does not implement " . Field\FieldInterface::class);
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

Types::registerType(Field\AutoIncrementIdField::class);
Types::registerType(Field\BooleanField::class);
Types::registerType(Field\DateTimeField::class);
Types::registerType(Field\IntegerField::class);
Types::registerType(Field\StringField::class);

Types::registerType(Model\TableModel::class);
Types::registerType(Model\ViewModel::class);
