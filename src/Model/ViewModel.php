<?php

namespace Nemo64\RestToSql\Model;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Nemo64\RestToSql\Options;

readonly class ViewModel extends AbstractModel
{
    public string $from;
    public ?string $groupBy;
    public ?string $where;

    public function __construct(Options $data)
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
        $queryBuilder->from($this->applyPlaceholders($this->from), $this->getModelName());
        $this->applyFilters($queryBuilder, $this->getModelName(), '', $query);
        if ($this->groupBy !== null) {
            $queryBuilder->groupBy($this->applyPlaceholders($this->groupBy));
        }
        if ($this->where !== null) {
            $queryBuilder->where($this->applyPlaceholders($this->where));
        }

        $select = [];
        foreach ($this->getProperties() as $field) {
            /** @noinspection NullPointerExceptionInspection */
            $select[] = $connection->getDatabasePlatform()->quoteStringLiteral($field->getPropertyName());
            $select[] = $field->getSelectExpression($connection, $this->getModelName());
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
                [$this->getModelName(), $this->parent->getModelName()],
                $string
            );
        }

        return preg_replace('#\bthis\b#', $this->getModelName(), $string);
    }
}