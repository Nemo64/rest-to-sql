<?php

namespace Nemo64\RestToSql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Schema;
use Nemo64\RestToSql\Exception\InternalServerErrorException;
use Nemo64\RestToSql\Exception\MethodNotAllowedException;
use Nemo64\RestToSql\Exception\NotFoundException;
use Nemo64\RestToSql\Model\ModelInterface;
use Nemo64\RestToSql\Pager\PagerInterface;
use Nemo64\RestToSql\Pager\SimplePager;
use Symfony\Component\Yaml\Yaml;

readonly class RestToSql implements RestToSqlInterface
{
    /** @var ModelInterface[] */
    private array $models;

    public function __construct(
        public Connection     $connection,
        array                 $models,
        public PagerInterface $pager = new SimplePager(),
    ) {
        $modelsOptions = new Options($models);
        $models = [];
        foreach ($modelsOptions as $name => $options) {
            $type = Types::getType($options['type']);
            $options['name'] ??= $name;
            $models[$name] = new $type($options);
        }
        $this->models = $models;
        $modelsOptions->throwForUnusedOptions();
    }

    public static function fromEnvironment(): self
    {
        $databaseUrl = getenv('DATABASE_URL') ?: 'sqlite:///rest-to-sql.db';
        $connection = DriverManager::getConnection([
            'url' => $databaseUrl,
            'charset' => 'utf8mb4',
        ]);

        $schemaGlob = getenv('REST_TO_SQL_MODEL_PATH') ?: '{src,tests,model,schema,config}/{*,*/*,*/*/*}/{*.model.yaml,model.yaml}';
        if (!str_contains($schemaGlob, '.yaml') && !str_contains($schemaGlob, '.yml')) {
            echo "Warning: The schema file should have the .yml extension. The search pattern $schemaGlob does not restrict that.", PHP_EOL;
        }

        $models = [];
        foreach (glob($schemaGlob, GLOB_BRACE) as $file) {
            $models += Yaml::parseFile($file);
        }

        return new self(
            connection: $connection,
            models: $models
        );
    }

    public function getOpenApiSchema(string $pathPrefix = "/api"): array
    {
        $schema = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Rest to SQL API',
                'version' => '1.0.0',
            ],
        ];

        $schema['components']['schemas']['error'] = [
            'type' => 'object',
            'description' => <<<'DESCRIPTION'
                This error object has the same properties as a JavaScript error object.
                That way, you don't have to differentiate between a JavaScript error object and a JSON error object.
                DESCRIPTION,
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The name of the error.'],
                'message' => ['type' => 'string', 'description' => 'The error message.'],
            ],
        ];

        foreach ($this->models as $model) {
            $path = "$pathPrefix/{$model->getModelName()}";
            $fieldSchema = $model->getOpenApiSchema($schema['components'])['items'] ?? null;
            if (empty($fieldSchema)) {
                throw new \RuntimeException("The model {$model->getModelName()} must return an array schema.");
            }

            if ($model->canSelect()) {
                $schema['paths'][$path]['get'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ...$model->getOpenApiParameters(''),
                        ...$this->pager->getOpenApiParameters(),
                    ],
                    'responses' => [
                        '200' => ['content' => ['application/json' => ['schema' => $this->pager->getOpenApiSchema($fieldSchema)]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                    ],
                ];

                $schema['paths']["$path/{id}"]['get'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$model->getModelName()}"]]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                    ],
                ];
            }

            if ($model->canInsert()) {
                $schema['paths'][$path]['post'] = [
                    'tags' => [$model->getModelName()],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => "#/components/schemas/{$model->getModelName()}"]],
                        ],
                    ],
                    'responses' => [
                        '201' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$model->getModelName()}"]]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                    ],
                ];
            }

            if ($model->canUpdate()) {
                $schema['paths']["$path/{id}"]['patch'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => "#/components/schemas/{$model->getModelName()}"]],
                        ],
                    ],
                    'responses' => [
                        '200' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$model->getModelName()}"]]]],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                        '422' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                    ],
                ];
            }

            if ($model->canDelete()) {
                $schema['paths']["$path/{id}"]['delete'] = [
                    'tags' => [$model->getModelName()],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'content' => [
                            'application/json' => ['schema' => ['$ref' => "#/components/schemas/{$model->getModelName()}"]],
                        ],
                    ],
                    'responses' => [
                        '204' => [],
                        '403' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                        '404' => ['content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/error"]]]],
                    ],
                ];
            }
        }

        return $schema;
    }

    public function getDatabaseSchema(): Schema
    {
        $schema = new Schema();
        foreach ($this->models as $model) {
            $model->applySqlTableSchema($schema);
        }
        return $schema;
    }

    /**
     * @throws NotFoundException
     */
    private function getModel(string $modelName): ModelInterface
    {
        $model = $this->models[$modelName] ?? null;
        if ($model === null) {
            throw new NotFoundException("No model found for name '$modelName'");
        }

        return $model;
    }

    public function createSelectQueryBuilder(string $modelName, array $query): QueryBuilder
    {
        return $this->getModel($modelName)->createSelectQueryBuilder($this->connection, $query);
    }

    public function list(string $modelName, array $query): \Iterator
    {
        $model = $this->getModel($modelName);
        if (!$model->canSelect()) {
            throw new MethodNotAllowedException();
        }

        try {
            $queryBuilder = $model->createSelectQueryBuilder($this->connection, $query);
            return $this->pager->createResponse($queryBuilder, $query);
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }

    public function get(string $modelName, string|int $id): string
    {
        $model = $this->getModel($modelName);
        if (!$model->canSelect()) {
            throw new MethodNotAllowedException();
        }

        try {
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

    public function post(string $modelName, array $data): string
    {
        $model = $this->getModel($modelName);
        if (!$model->canInsert()) {
            throw new MethodNotAllowedException();
        }

        try {
            $this->connection->beginTransaction();

            try {
                [$id] = $model->executeUpdates($this->connection, null, [], [$data]);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }

            $queryBuilder = $model->createSelectQueryBuilder($this->connection, ['id' => $id]);
            return $queryBuilder->executeQuery()->fetchOne();
        } catch (DbalException $e) {
            throw new InternalServerErrorException($e->getMessage(), previous: $e);
        }
    }

    public function patch(string $modelName, string|int $id, array $data): string
    {
        $model = $this->getModel($modelName);
        if (!$model->canUpdate()) {
            throw new MethodNotAllowedException();
        }

        try {
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


    public function delete(string $modelName, string|int $id): void
    {
        $model = $this->getModel($modelName);
        if (!$model->canDelete()) {
            throw new MethodNotAllowedException();
        }

        try {
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
}