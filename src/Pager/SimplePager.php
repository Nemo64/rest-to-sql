<?php

namespace Nemo64\RestToSql\Pager;

use Doctrine\DBAL\Query\QueryBuilder;

readonly class SimplePager implements PagerInterface
{
    public function __construct(
        public int $defaultLimit = 100,
    ) {

    }

    public function getOpenApiParameters(): array
    {
        return [
            [
                'name' => 'limit',
                'in' => 'query',
                'description' => 'The maximum number of items to return.',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => $this->defaultLimit],
            ],
            [
                'name' => 'offset',
                'in' => 'query',
                'description' => 'The number of items to skip.',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ]
        ];
    }

    public function getOpenApiSchema(array $objectSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => $objectSchema],
                'total' => ['type' => 'integer'],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
            ],
        ];
    }

    public function createResponse(QueryBuilder $queryBuilder, array $query): \Iterator
    {
        $limit = isset($query['limit']) ? (int)$query['limit'] : $this->defaultLimit;
        $offset = isset($query['offset']) ? (int)$query['offset'] : 0;

        $pageQueryBuilder = clone $queryBuilder;
        $pageQueryBuilder->setMaxResults($limit);
        $pageQueryBuilder->setFirstResult($offset);
        $result = $pageQueryBuilder->executeQuery();
        $rowCount = 0;

        yield '{"items":[';
        if (($jsonObject = $result->fetchOne()) !== false) {
            yield $jsonObject;
            $rowCount++;
        }
        while (($jsonObject = $result->fetchOne()) !== false) {
            yield ',';
            yield $jsonObject;
            $rowCount++;
        }
        yield "],";

        // count the total number of items
        // the server might still be transferring the data to the client
        // this gives us some time to run the count query

        if (($offset === 0 || $rowCount > 0) && $rowCount < $offset + $limit) {
            $total = $offset + $rowCount;
        } else {
            $from = $queryBuilder->getQueryPart('from')[0];
            $alias = $from['alias'] ?? $from['table'];
            $countQueryBuilder = clone $queryBuilder;
            $countQueryBuilder->select("COUNT(DISTINCT $alias.id)");
            $countQueryBuilder->setMaxResults(null);
            $countQueryBuilder->setFirstResult(0);
            $total = $countQueryBuilder->executeQuery()->fetchOne();
        }

        yield "\"total\":$total,\"limit\":$limit,\"offset\":$offset}";
    }
}