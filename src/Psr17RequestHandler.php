<?php

namespace Nemo64\RestToSql;

use GuzzleHttp\Psr7\Response;
use Nemo64\RestToSql\Exception\ApiRelatedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

readonly class Psr17RequestHandler implements RequestHandlerInterface
{
    public function __construct(
        public RestToSqlInterface $restToSql,
        public string             $apiPathPrefix = '/api',
    ) {

    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $path = substr($request->getUri()->getPath(), strlen($this->apiPathPrefix));
            $pathSegments = explode('/', trim($path, '/'), 2);
            $method = $request->getMethod();
            $modelName = $pathSegments[0] ?? '';
            $id = $pathSegments[1] ?? null;

            if ($method === 'GET' && $id === null) {
                $iterator = $this->restToSql->list($modelName, $request->getQueryParams());
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: $iterator,
                );
            }

            if ($method === 'GET' && $id !== null) {
                $response = $this->restToSql->get($modelName, $id);
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: $response,
                );
            }

            if ($method === 'POST' && $id === null) {
                $body = json_decode($request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $response = $this->restToSql->post($modelName, $body);
                return new Response(
                    status: 201,
                    headers: ['Content-Type' => 'application/json'], // TODO: add location header
                    body: $response,
                );
            }

            if ($method === 'PATCH' && $id !== null) {
                $body = json_decode($request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $response = $this->restToSql->patch($modelName, $id, $body);
                return new Response(
                    status: 200,
                    headers: ['Content-Type' => 'application/json'],
                    body: $response,
                );
            }

            if ($method === 'DELETE' && $id !== null) {
                $this->restToSql->delete($modelName, $id);
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