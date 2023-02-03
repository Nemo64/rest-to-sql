<?php

namespace Nemo64\RestToSql\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Nemo64\RestToSql\Exception\ApiRelatedException;
use Nemo64\RestToSql\Field\PropertyInterface;

interface ModelInterface extends PropertyInterface
{
    public function getModelName(): string;

    public function canSelect(): bool;

    public function canInsert(): bool;

    public function canUpdate(): bool;

    public function canDelete(): bool;

    /** @return PropertyInterface[] */
    public function getProperties(): array;

    public function applyOpenApiComponents(array &$schema): void;

    public function applySqlTableSchema(Schema $schema): void;

    /**
     * @throws ApiRelatedException
     */
    public function createSelectQueryBuilder(Connection $connection, array $query = []): QueryBuilder;

    /**
     * @throws ApiRelatedException
     */
    public function executeUpdates(Connection $connection, mixed $parentId, array $oldRecords, array $newRecords): array;
}