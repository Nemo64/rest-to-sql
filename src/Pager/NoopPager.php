<?php

namespace Nemo64\RestToSql\Pager;

use Doctrine\DBAL\Query\QueryBuilder;

readonly class NoopPager implements PagerInterface
{
    public function getOpenApiParameters(): array
    {
        return [];
    }

    public function getOpenApiSchema(array &$components, array $objectSchema): array
    {
        return ['type' => 'array', 'items' => $objectSchema];
    }

    public function createResponse(QueryBuilder $queryBuilder, array $query): \Iterator
    {
        $result = $queryBuilder->executeQuery();
        yield "[";
        if ($jsonObject = $result->fetchOne()) {
            yield $jsonObject;
        }
        while ($jsonObject = $result->fetchOne()) {
            yield ",";
            yield $jsonObject;
        }
        yield "]";
    }
}