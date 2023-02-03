<?php

namespace Nemo64\RestToSql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Nemo64\RestToSql\Exception\ApiRelatedException;
use Nemo64\RestToSql\Exception\BadRequestException;
use Nemo64\RestToSql\Exception\InternalServerErrorException;
use Nemo64\RestToSql\Exception\MethodNotAllowedException;
use Nemo64\RestToSql\Exception\NotFoundException;
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
        $modelsOptions = new Options($models);
        foreach ($modelsOptions as $name => $options) {
            $type = Types::getType($options['type']);
            $options['name'] ??= $name;
            $this->models[$name] = new $type($options);
        }
        $modelsOptions->throwForUnusedOptions();
    }

    public static function fromEnvironment(): self
    {
        $databaseUrl = getenv('DATABASE_URL') ?: 'sqlite:///rest-to-sql.db';
        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'url' => $databaseUrl,
            'charset' => 'utf8mb4',
        ]);

        $schemaGlob = getenv('REST_TO_SQL_MODEL_PATH') ?: '{src,tests,model,schema,config}/{*,*/*,*/*/*}/*.model.yaml';
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
            $path = "$this->apiPathPrefix/{$model->getPropertyName()}";

            if ($model->canSelect()) {
                $schema['paths'][$path]['get'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => $model->getOpenApiFilterParameters(''),
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $model->getModelName()]]],
                            ],
                        ],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];

                $schema['paths'][$path . '/{id}']['get'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getModelName()]],
                            ],
                        ],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }

            if ($model->canInsert()) {
                $schema['paths'][$path]['post'] = [
                    'tags' => [$model->getModelName()],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getModelName()]],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getModelName()]],
                            ],
                        ],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }

            if ($model->canUpdate()) {
                $schema['paths'][$path . '/{id}']['patch'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getModelName()]],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getModelName()]],
                            ],
                        ],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }

            if ($model->canDelete()) {
                $schema['paths'][$path . '/{id}']['delete'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $model->getModelName()]],
                        ],
                    ],
                    'responses' => [
                        '204' => [],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/error']]]],
                    ],
                ];
            }
        }
    }

    /**
     * @throws NotFoundException
     */
    private function getModel(string $modelName)
    {
        $model = $this->models[$modelName] ?? null;
        if ($model === null) {
            throw new NotFoundException("No model found for name '$modelName'");
        }

        return $model;
    }

    /**
     * @throws ApiRelatedException
     */
    public function list(string $modelName, array $query): \Iterator
    {
        try {
            $model = $this->getModel($modelName);
            $queryBuilder = $model->createSelectQueryBuilder($this->connection, $query);
            return $queryBuilder->executeQuery()->iterateColumn();
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws ApiRelatedException
     */
    public function get(string $modelName, string|int $id): string
    {
        try {
            $model = $this->getModel($modelName);
            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            $data = $queryBuilder->executeQuery()->fetchOne();
            if ($data === false) {
                throw new NotFoundException("No $modelName found for id '$id'");
            }

            return $data;
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws ApiRelatedException
     */
    public function post(string $modelName, array $data): array
    {
        try {
            $model = $this->getModel($modelName);

            $this->connection->beginTransaction();
            try {
                [$id] = $model->executeUpdates($this->connection, null, [], [$data]);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }

            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            return [$queryBuilder->executeQuery()->fetchOne(), $id];
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws ApiRelatedException
     */
    public function patch(string $modelName, string|int $id, array $data): string
    {
        try {
            $model = $this->getModel($modelName);
            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            $this->connection->beginTransaction();
            try {
                $existingData = $queryBuilder->executeQuery()->fetchOne();
                if ($existingData === false) {
                    throw new NotFoundException("No $modelName found for id '$id'");
                }

                $existingData = json_decode($existingData, true, 512, JSON_THROW_ON_ERROR);
                $data['id'] = $id;
                $model->executeUpdates($this->connection, null, [$existingData], [$data]);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }

            return $queryBuilder->executeQuery()->fetchOne();
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }


    /**
     * @throws ApiRelatedException
     */
    public function delete(string $modelName, string|int $id): void
    {
        try {
            $model = $this->getModel($modelName);
            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            $this->connection->beginTransaction();
            try {
                $existingData = $queryBuilder->executeQuery()->fetchOne();
                if ($existingData === false) {
                    throw new NotFoundException("No $modelName found for id '$id'");
                }

                $existingData = json_decode($existingData, true, 512, JSON_THROW_ON_ERROR);
                $model->executeUpdates($this->connection, null, [$existingData], []);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $path = substr($request->getUri()->getPath(), strlen($this->apiPathPrefix));
            $pathSegments = explode('/', trim($path, '/'), 2);
            $method = $request->getMethod();
            $model = $this->getModel($pathSegments[0] ?? '');
            $id = $pathSegments[1] ?? null;

            if ($method === 'GET' && $id === null && $model->canSelect()) {
                $iterator = $this->list($model->getModelName(), $request->getQueryParams());
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: call_user_func(static function () use ($iterator) {
                        yield '[';
                        if ($iterator->valid()) {
                            yield $iterator->current();
                            $iterator->next();
                        }
                        while ($iterator->valid()) {
                            yield ',';
                            yield $iterator->current();
                            $iterator->next();
                        }
                        yield ']';
                    }),
                );
            }

            if ($method === 'GET' && $id !== null && $model->canSelect()) {
                $response = $this->get($model->getModelName(), $id);
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: $response,
                );
            }

            if ($method === 'POST' && $id === null && $model->canInsert()) {
                $body = json_decode($request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                [$response, $id] = $this->post($model->getModelName(), $body);
                return new Response(
                    status: 201,
                    headers: [
                        'Content-Type' => 'application/json',
                        'Location' => "$this->apiPathPrefix/{$model->getModelName()}/$id"
                    ],
                    body: $response,
                );
            }

            if ($method === 'PATCH' && $id !== null && $model->canUpdate()) {
                $body = json_decode($request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $response = $this->patch($model->getModelName(), $id, $body);
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: $response,
                );
            }

            if ($method === 'DELETE' && $id !== null && $model->canDelete()) {
                $this->delete($model->getModelName(), $id);
                return new Response(status: 204);
            }

            return new Response(
                status: 405,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['message' => "Method $method not allowed"], JSON_THROW_ON_ERROR),
            );
        } catch (\JsonException $e) {
            return new Response(
                status: 400,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
            );
        } catch (ApiRelatedException $e) {
            return new Response(
                status: $e->getStatusCode(),
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['message' => $e->getMessage()], JSON_THROW_ON_ERROR),
            );
        }
    }
}