<?php

namespace Nemo64\RestToSql\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Nemo64\RestToSql\Field\FieldInterface;

interface ModelInterface extends FieldInterface
{
    public function getTableName(): string;

    public function canSelect(): bool;

    public function canInsert(): bool;

    public function canUpdate(): bool;

    public function canDelete(): bool;

    /** @return FieldInterface[] */
    public function getFields(): array;

    public function applyOpenApiComponents(array &$schema): void;

    public function applySqlTableSchema(Schema $schema): void;

    public function createSelectQueryBuilder(Connection $connection, array $query = []): QueryBuilder;

    public function executeUpdates(Connection $connection, mixed $parentId, array $oldRecords, array $newRecords): array;
}