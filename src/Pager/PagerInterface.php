<?php

namespace Nemo64\RestToSql\Pager;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\QueryBuilder;

interface PagerInterface
{
    public function getOpenApiParameters(): array;

    public function getOpenApiSchema(array &$components, array $objectSchema): array;

    /**
     * @throws DbalException
     */
    public function createResponse(QueryBuilder $queryBuilder, array $query): \Iterator;
}