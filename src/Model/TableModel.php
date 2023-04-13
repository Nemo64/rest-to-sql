<?php

namespace Nemo64\RestToSql\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Nemo64\RestToSql\Exception\InternalServerErrorException;

readonly class TableModel extends AbstractModel
{
    public static function getTypeName(): string
    {
        return 'table';
    }

    public function canInsert(): bool
    {
        return true;
    }

    public function canUpdate(): bool
    {
        return true;
    }

    public function canDelete(): bool
    {
        return true;
    }

    public function applySqlTableSchema(Schema $schema): void
    {
        $table = $schema->createTable($this->getModelName());

        // TODO I'm not sure if this belongs here
        if ($this->parent !== null) {
            $table->addColumn($this->parentProperty, Types::INTEGER, ['unsigned' => true]);
            $table->addForeignKeyConstraint(
                foreignTable: $this->parent->getModelName(),
                localColumnNames: [$this->parentProperty],
                foreignColumnNames: [$this->parent->idProperty],
                options: ['onDelete' => 'CASCADE'],
            );
        }

        foreach ($this->properties as $field) {
            if ($field instanceof ModelInterface) {
                $field->applySqlTableSchema($schema);
            }

            $field->applySqlFieldSchema($table);
        }
    }

    public function createSelectQueryBuilder(Connection $connection, array $query = []): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->from($this->getModelName());
        $this->applyFilters($queryBuilder, $this->getModelName(), '', $query);

        if ($this->parent instanceof self) {
            $queryBuilder->andWhere("{$this->getModelName()}.$this->parentProperty = {$this->parent->getModelName()}.{$this->parent->idProperty}");
        }

        $select = [];
        foreach ($this->properties as $field) {
            /** @noinspection NullPointerExceptionInspection */
            $select[] = $connection->getDatabasePlatform()->quoteStringLiteral($field->getPropertyName());
            $select[] = $field->getSelectExpression($connection, $this->getModelName());
        }
        $queryBuilder->select('JSON_OBJECT(' . implode(', ', $select) . ')');

        // TODO security

        return $queryBuilder;
    }

    public function executeUpdates(Connection $connection, mixed $parentId, array $oldRecords, array $newRecords): array
    {
        $finalIds = [];
        $oldRecords = array_column($oldRecords, column_key: null, index_key: $this->idProperty);

        foreach ($newRecords as $newRecord) {
            if (isset($newRecord[$this->idProperty], $oldRecords[$newRecord[$this->idProperty]])) {
                $oldRecord = $oldRecords[$newRecord[$this->idProperty]];
                unset($oldRecords[$newRecord[$this->idProperty]]);
            }

            $fields = [];
            foreach ($this->properties as $key => $field) {
                if (array_key_exists($key, $newRecord)) {
                    $fields += $field->getUpdateValues($connection, $oldRecord[$key] ?? null, $newRecord[$key]);
                }
            }

            if (isset($oldRecord[$this->idProperty])) {
                try {
                    $finalIds[] = $id = $oldRecord[$this->idProperty];
                    if (count($fields) > 0) {
                        $connection->update(
                            table: $this->getModelName(),
                            data: array_combine(array_keys($fields), array_column($fields, 0)),
                            criteria: [$this->idProperty => $id],
                            types: array_combine(array_keys($fields), array_column($fields, 1)),
                        );
                    }

                    $this->executeSubUpdates($connection, $id, $oldRecord, $newRecord);
                } catch (Exception $e) {
                    throw new InternalServerErrorException("Failed to update {$this->getModelName()}: {$e->getMessage()}", 0, $e);
                }
            } else {
                try {
                    if ($parentId !== null) {
                        $fields[$this->parentProperty] = [$parentId, ParameterType::INTEGER];
                    }

                    $connection->insert(
                        table: $this->getModelName(),
                        data: array_combine(array_keys($fields), array_column($fields, 0)),
                        types: array_combine(array_keys($fields), array_column($fields, 1)),
                    );

                    $finalIds[] = $id = $connection->lastInsertId();
                    $this->executeSubUpdates($connection, $id, null, $newRecord);
                } catch (Exception $e) {
                    throw new InternalServerErrorException("Failed to insert {$this->getModelName()}: {$e->getMessage()}", 0, $e);
                }
            }
        }

        foreach ($oldRecords as $oldRecord) {
            try {
                $this->executeSubUpdates($connection, $oldRecord[$this->idProperty], $oldRecord, null);
                $connection->delete($this->getModelName(), [$this->idProperty => $oldRecord[$this->idProperty]]);
            } catch (Exception $e) {
                throw new InternalServerErrorException("Failed to delete {$this->getModelName()}: {$e->getMessage()}", 0, $e);
            }
        }

        return $finalIds;
    }

    private function executeSubUpdates(Connection $connection, mixed $parentId, ?array $oldRecord, ?array $newRecord): void
    {
        foreach ($this->properties as $key => $field) {
            // TODO checking for ModelInterface breaks recursion and will prevent embeddable types
            if (!$field instanceof ModelInterface) {
                continue;
            }

            if (!isset($oldRecord[$key]) && !isset($newRecord[$key])) {
                continue;
            }

            $field->executeUpdates($connection, $parentId, $oldRecord[$key] ?? [], $newRecord[$key] ?? []);
        }
    }
}