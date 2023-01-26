<?php

namespace Nemo64\RestToSql\Model;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;

readonly class ViewModel extends AbstractModel
{
    public string $from;
    public ?string $groupBy;
    public ?string $where;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->from = $data['from'];
        $this->where = $data['where'] ?? null;
        $this->groupBy = $data['groupBy'] ?? null;
    }

    public static function getTypeName(): string
    {
        return 'view';
    }

    public function applySqlTableSchema(Schema $schema): void
    {
    }

    public function createSelectQueryBuilder(Connection $connection, array $query = []): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->from($this->applyPlaceholders($this->from), $this->getTableName());
        $this->applyFilters($queryBuilder, $this->getTableName(), '', $query);
        if ($this->groupBy !== null) {
            $queryBuilder->groupBy($this->applyPlaceholders($this->groupBy));
        }
        if ($this->where !== null) {
            $queryBuilder->where($this->applyPlaceholders($this->where));
        }

        $select = [];
        foreach ($this->getFields() as $field) {
            /** @noinspection NullPointerExceptionInspection */
            $select[] = $connection->getDatabasePlatform()->quoteStringLiteral($field->getFieldName());
            $select[] = $field->getSelectExpression($connection, $this->getTableName());
        }
        $queryBuilder->select('JSON_OBJECT(' . implode(', ', $select) . ')');

        return $queryBuilder;
    }

    public function executeUpdates(Connection $connection, mixed $parentId, array $oldRecords, array $newRecords): array
    {
        return [];
    }

    private function applyPlaceholders(string $string): string
    {
        if ($this->parent !== null) {
            return preg_replace(
                ['#\bthis\b#', '#\bparent\b#'],
                [$this->getTableName(), $this->parent->getTableName()],
                $string
            );
        }

        return preg_replace('#\bthis\b#', $this->getTableName(), $string);
    }
}