<?php

namespace Nemo64\RestToSql;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Nemo64\RestToSql\Exception\ApiRelatedException;

interface RestToSqlInterface
{
    /**
     * The open api schema that describes this rest api.
     */
    public function getOpenApiSchema(string $pathPrefix = "/api"): array;

    /**
     * Returns the Database schema that works for this rest api.
     */
    public function getDatabaseSchema(): Schema;

    /**
     * Programmatic access to create a select query.
     *
     * @throws ApiRelatedException
     */
    public function createSelectQueryBuilder(string $modelName, array $query): QueryBuilder;

    /**
     * @throws ApiRelatedException
     */
    public function list(string $modelName, array $query): \Iterator;

    /**
     * @throws ApiRelatedException
     */
    public function get(string $modelName, string|int $id): string;

    /**
     * @throws ApiRelatedException
     */
    public function post(string $modelName, array $data): string;

    /**
     * @throws ApiRelatedException
     */
    public function patch(string $modelName, string|int $id, array $data): string;

    /**
     * @throws ApiRelatedException
     */
    public function delete(string $modelName, string|int $id): void;
}