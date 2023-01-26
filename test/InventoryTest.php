<?php

namespace Nemo64\RestToSql\Test;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use GuzzleHttp\Psr7\ServerRequest;
use Nemo64\RestToSql\RestToSql;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class InventoryTest extends TestCase
{
    public function testProcess(): void
    {
        $connection = DriverManager::getConnection([
            'url' => 'sqlite:///:memory:',
        ]);
        $client = new RestToSql($connection, Yaml::parseFile(__DIR__ . '/model/inventories.model.yaml'));

        $schema = new Schema();
        $client->applySqlTableSchema($schema);
        $connection->createSchemaManager()->createSchemaObjects($schema);

        $response = $client->handle(new ServerRequest('POST', '/api/inventories', [], '{"name": "testler"}'));
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('/api/inventories/1', $response->getHeaderLine('Location'));
        $this->assertEquals('{"id":1,"name":"testler","description":null,"status":"draft","public":false,"allocations":[]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('POST', '/api/inventories', [], '{"name": "test"}'));
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('/api/inventories/2', $response->getHeaderLine('Location'));
        $this->assertEquals('{"id":2,"name":"test","description":null,"status":"draft","public":false,"allocations":[]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('GET', '/api/inventories/2'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"id":2,"name":"test","description":null,"status":"draft","public":false,"allocations":[]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('GET', '/api/inventories'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[{"id":1,"name":"testler","description":null,"status":"draft","public":false,"allocations":[]},{"id":2,"name":"test","description":null,"status":"draft","public":false,"allocations":[]}]', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('PATCH', '/api/inventories/2', [], '{"name": "test2"}'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"id":2,"name":"test2","description":null,"status":"draft","public":false,"allocations":[]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('GET', '/api/inventories/2'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"id":2,"name":"test2","description":null,"status":"draft","public":false,"allocations":[]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('DELETE', '/api/inventories/2'));
        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEquals('', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('GET', '/api/inventories/2'));
        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('{"message":"The requested sub-resource was not found."}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('GET', '/api/inventories'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('[{"id":1,"name":"testler","description":null,"status":"draft","public":false,"allocations":[]}]', (string)$response->getBody());
    }

    public function testSubResource()
    {
        $connection = DriverManager::getConnection([
            'url' => 'sqlite:///:memory:',
        ]);
        $client = new RestToSql($connection, Yaml::parseFile(__DIR__ . '/model/inventories.model.yaml'));

        $schema = new Schema();
        $client->applySqlTableSchema($schema);
        $connection->createSchemaManager()->createSchemaObjects($schema);

        $response = $client->handle(new ServerRequest('POST', '/api/inventories', [], '{"name": "test"}'));
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('/api/inventories/1', $response->getHeaderLine('Location'));
        $this->assertEquals('{"id":1,"name":"test","description":null,"status":"draft","public":false,"allocations":[]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('PATCH', '/api/inventories/1', [], '{"allocations": [{"itemName": "foo", "quantity": 1}]}'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"id":1,"name":"test","description":null,"status":"draft","public":false,"allocations":[{"id":1,"description":null,"itemName":"foo","quantity":1}]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('PATCH', '/api/inventories/1', [], '{"allocations": [{"itemName": "foo", "quantity": 2}]}'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"id":1,"name":"test","description":null,"status":"draft","public":false,"allocations":[{"id":2,"description":null,"itemName":"foo","quantity":2}]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('PATCH', '/api/inventories/1', [], '{"allocations": [{"id": 2, "itemName": "bar", "quantity": 2}]}'));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"id":1,"name":"test","description":null,"status":"draft","public":false,"allocations":[{"id":2,"description":null,"itemName":"bar","quantity":2}]}', (string)$response->getBody());

        $response = $client->handle(new ServerRequest('DELETE', '/api/inventories/1'));
        $this->assertEquals(204, $response->getStatusCode());
    }
}