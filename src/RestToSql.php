<?php

namespace Nemo64\RestToSql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Nemo64\RestToSql\Model\ModelInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RestToSql implements RequestHandlerInterface
{
    /** @var ModelInterface[] */
    private array $models = [];

    public function __construct(
        public readonly Connection $connection,
        array                      $models,
        public readonly string     $apiPathPrefix = '/api',
    ) {
        foreach ($models as $name => $options) {
            $type = Types::getType($options['type']);
            $this->models[$name] = new $type($options + ['name' => $name]);
        }
    }

    public static function fromEnvironment(): self
    {
        $databaseUrl = getenv('DATABASE_URL') ?: 'sqlite:///rest-to-sql.db';
        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'url' => $databaseUrl,
            'charset' => 'utf8mb4',
        ]);

        $schemaGlob = getenv('REST_TO_SQL_MODEL_PATH') ?: '{src,test,model,schema,config}/{*,*/*,*/*/*}/*.model.yaml';
        if (!str_contains($schemaGlob, '.yaml') && !str_contains($schemaGlob, '.yml')) {
            echo "Warning: The schema file should have the .yml extension. The search pattern $schemaGlob does not restrict that.", PHP_EOL;
        }

        $models = [];
        foreach (glob($schemaGlob, GLOB_BRACE) as $file) {
            $models += \Symfony\Component\Yaml\Yaml::parseFile($file);
        }

        return new \Nemo64\RestToSql\RestToSql(
            connection: $connection,
            models: $models
        );
    }

    public function applySqlTableSchema(Schema $schema): void
    {
        foreach ($this->models as $model) {
            $model->applySqlTableSchema($schema);
        }
    }

    public function applyOpenApiSchema(array &$schema): void
    {
        $schema['components']['schemas'] = [
            'error' => [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'description' => 'The error message.',
                        'type' => 'string',
                    ],
                ],
            ],
        ];

        foreach ($this->models as $model) {
            $model->applyOpenApiComponents($schema);
            $path = "$this->apiPathPrefix/{$model->getFieldName()}";

            if ($model->canSelect()) {
                $schema['paths'][$path]['get'] = [
                    'tags' => [$model->getTableName()],
                    'parameters' => $model->getOpenApiFilterParameters(''),
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $model->getTableName()]]],
                            ],
                        ],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];

                $schema['paths'][$path . '/{id}']['get'] = [
                    'tags' => [$model->getTableName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getTableName()]],
                            ],
                        ],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }

            if ($model->canInsert()) {
                $schema['paths'][$path]['post'] = [
                    'tags' => [$model->getTableName()],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getTableName()]],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getTableName()]],
                            ],
                        ],
                        '400' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }

            if ($model->canUpdate()) {
                $schema['paths'][$path . '/{id}']['patch'] = [
                    'tags' => [$model->getTableName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getTableName()]],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getTableName()]],
                            ],
                        ],
                        '400' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }

            if ($model->canDelete()) {
                $schema['paths'][$path . '/{id}']['delete'] = [
                    'tags' => [$model->getTableName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getTableName()]],
                        ],
                    ],
                    'responses' => [
                        '204' => [],
                        '400' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }
        }
    }

    public function createSelectQueryBuilder(string $path, array $query): ?QueryBuilder
    {
        [$model, $id] = $this->getModelForPath($path);
        if ($model === null) {
            return null;
        }

        if (!empty($id)) {
            $query['id'] = $id;
        }

        return $model->createSelectQueryBuilder($this->connection, $query);
    }

    public function executeSelect(string $path, array $query = []): string|\Traversable|null
    {
        [$model, $id] = $this->getModelForPath($path);
        if ($model === null) {
            return null;
        }

        if ($id !== null) {
            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            $result = $queryBuilder->setMaxResults(1)->fetchOne();
            return $result !== false ? $result : null;
        }

        $queryBuilder = $model->createSelectQueryBuilder($this->connection, $query);
        return $queryBuilder->executeQuery()->iterateColumn();
    }

    public function executeUpdate(string $path, ?array $newRecord, ?bool $expectUpdate = null): array
    {
        [$model, $id] = $this->getModelForPath($path);
        if ($model === null) {
            return [];
        }

        if ($expectUpdate === true && $id === null) {
            throw new \RuntimeException('An update expects an id in the path.');
        }

        if ($expectUpdate === false && $id !== null) {
            throw new \RuntimeException('An insert does not expect an id in the path.');
        }

        if ($newRecord !== null && $id !== null) {
            $newRecord['id'] = $id;
        }

        return $this->connection->transactional(function ($connection) use ($model, $id, $newRecord) {
            if ($id === null) {
                return $model->executeUpdates(
                    connection: $connection,
                    parentId: null,
                    oldRecords: [],
                    newRecords: $newRecord === null ? [] : [$newRecord]
                );
            }

            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            $oldRecord = $queryBuilder->setMaxResults(1)->fetchOne();
            if ($oldRecord === false) {
                throw new \RuntimeException('Record not found.');
            }

            return $model->executeUpdates(
                connection: $connection,
                parentId: null,
                oldRecords: [json_decode($oldRecord, true, 512, JSON_THROW_ON_ERROR)],
                newRecords: $newRecord === null ? [] : [$newRecord]
            );
        });
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($request->getMethod() === 'POST') {
                $newRecord = json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
                $ids = $this->executeUpdate($request->getUri()->getPath(), $newRecord, expectUpdate: false);
                $newPath = $request->getUri()->getPath() . '/' . reset($ids);
                $result = $this->executeSelect($newPath);
                return new Response(
                    status: 201,
                    headers: ['Content-Type' => 'application/json', 'Location' => $newPath],
                    body: $result,
                );
            }

            if ($request->getMethod() === 'PATCH') {
                $newRecord = json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
                $this->executeUpdate($request->getUri()->getPath(), $newRecord, expectUpdate: true);
                $result = $this->executeSelect($request->getUri()->getPath());
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: $result,
                );
            }

            if ($request->getMethod() === 'DELETE') {
                $this->executeUpdate($request->getUri()->getPath(), null, expectUpdate: true);
                return new Response(
                    status: 204,
                );
            }

            if ($request->getMethod() === 'GET') {
                $result = $this->executeSelect($request->getUri()->getPath(), $request->getQueryParams());
                return match (gettype($result)) {
                    'NULL' => new Response(
                        status: 404,
                        headers: ['Content-Type' => 'application/json'],
                        body: json_encode(['message' => 'The requested sub-resource was not found.'], JSON_THROW_ON_ERROR),
                    ),
                    'string' => new Response(
                        status: 200,
                        headers: ['Content-Type' => 'application/json'],
                        body: $result,
                    ),
                    default => new Response(
                        status: 200,
                        headers: ['Content-Type' => 'application/json'],
                        body: Utils::streamFor(call_user_func(static function () use ($result) {
                            yield '[';
                            $first = true;
                            foreach ($result as $row) {
                                if ($first) {
                                    $first = false;
                                } else {
                                    yield ',';
                                }
                                yield $row;
                            }
                            yield ']';
                        })),
                    ),
                };
            }

            return new Response(
                status: 405,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['message' => 'Method not allowed.'], JSON_THROW_ON_ERROR),
            );
        } catch (\JsonException $e) {
            return new Response(
                status: 400,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['message' => $e->getMessage()], JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * @param string $path
     * @return null|array{ModelInterface, string|null}
     */
    private function getModelForPath(string $path): ?array
    {
        if (!str_starts_with($path, $this->apiPathPrefix)) {
            return null;
        }

        $path = substr($path, strlen($this->apiPathPrefix) + 1);
        $pathItems = explode('/', $path);
        $usedPathIndex = 0;

        $model = $this->models[$pathItems[0]] ?? null;
        if ($model === null) {
            return null;
        }

        foreach (array_slice($pathItems, 0, -1) as $index => $pathItem) {
            $subModel = $model->getFields()[$pathItem] ?? null;
            if ($subModel instanceof ModelInterface) {
                $model = $subModel;
                $usedPathIndex = $index;
            } else {
                break;
            }
        }

        $id = implode('/', array_slice($pathItems, $usedPathIndex + 1));
        return [$model, $id === '' ? null : $id];
    }


}