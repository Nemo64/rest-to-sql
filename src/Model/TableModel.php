<?php

namespace Nemo64\RestToSql\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

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
        $table = $schema->createTable($this->getTableName());

        // TODO I'm not sure if this belongs here
        if ($this->parent !== null) {
            $table->addColumn('parent', Types::INTEGER, ['unsigned' => true]);
            $table->addForeignKeyConstraint(
                foreignTable: $this->parent->getTableName(),
                localColumnNames: [$this->parentField],
                foreignColumnNames: [$this->parent->idField],
                options: ['onDelete' => 'CASCADE'],
            );
        }

        foreach ($this->getFields() as $field) {
            if ($field instanceof ModelInterface) {
                $field->applySqlTableSchema($schema);
            }

            $field->applySqlFieldSchema($table);
        }
    }

    public function createSelectQueryBuilder(Connection $connection, array $query = []): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->from($this->getTableName());
        $this->applyFilters($queryBuilder, $this->getTableName(), '', $query);

        if ($this->parent instanceof self) {
            $queryBuilder->andWhere("{$this->getTableName()}.$this->parentField = {$this->parent->getTableName()}.{$this->parent->idField}");
        }

        $select = [];
        foreach ($this->getFields() as $field) {
            /** @noinspection NullPointerExceptionInspection */
            $select[] = $connection->getDatabasePlatform()->quoteStringLiteral($field->getFieldName());
            $select[] = $field->getSelectExpression($connection, $this->getTableName());
        }
        $queryBuilder->select('JSON_OBJECT(' . implode(', ', $select) . ')');

        // TODO security

        return $queryBuilder;
    }

    public function executeUpdates(Connection $connection, mixed $parentId, array $oldRecords, array $newRecords): array
    {
        try {
            $finalIds = [];
            $oldRecords = array_column($oldRecords, column_key: null, index_key: $this->idField);

            foreach ($newRecords as $newRecord) {
                if (isset($newRecord[$this->idField], $oldRecords[$newRecord[$this->idField]])) {
                    $oldRecord = $oldRecords[$newRecord[$this->idField]];
                    unset($oldRecords[$newRecord[$this->idField]]);
                }

                $fields = [];
                foreach ($this->getFields() as $key => $field) {
                    if (array_key_exists($key, $newRecord)) {
                        $fields += $field->getUpdateValues($connection, $oldRecord[$key] ?? null, $newRecord[$key]);
                    }
                }

                if (isset($oldRecord[$this->idField])) {
                    $finalIds[] = $id = $oldRecord[$this->idField];
                    if (count($fields) > 0) {
                        $connection->update($this->getTableName(), $fields, [$this->idField => $id]);
                    }

                    $this->executeSubUpdates($connection, $id, $oldRecord, $newRecord);
                } else {
                    if ($parentId !== null) {
                        $fields[$this->parentField] = $parentId;
                    }

                    $connection->insert($this->getTableName(), $fields);
                    $finalIds[] = $id = $connection->lastInsertId();
                    $this->executeSubUpdates($connection, $id, null, $newRecord);
                }
            }

            foreach ($oldRecords as $oldRecord) {
                $this->executeSubUpdates($connection, $oldRecord[$this->idField], $oldRecord, null);
                $connection->delete($this->getTableName(), [$this->idField => $oldRecord[$this->idField]]);
            }

            return $finalIds;
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to update ' . $this->getTableName() . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private function executeSubUpdates(Connection $connection, mixed $parentId, ?array $oldRecord, ?array $newRecord): void
    {
        foreach ($this->getFields() as $key => $field) {
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