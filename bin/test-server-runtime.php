<?php

require __DIR__ . '/../vendor/autoload.php';

$restToSql = \Nemo64\RestToSql\RestToSql::fromEnvironment();

if ($_SERVER['REQUEST_URI'] === '/') {
    header('Location: ' . $restToSql->apiPathPrefix);
    exit;
}

if ($_SERVER['REQUEST_URI'] === $restToSql->apiPathPrefix) {
    include __DIR__ . '/swagger-dump';
    exit;
}

$response = $restToSql->handle(\GuzzleHttp\Psr7\ServerRequest::fromGlobals());
header(sprintf(
    'HTTP/%s %s %s',
    $response->getProtocolVersion(),
    $response->getStatusCode(),
    $response->getReasonPhrase(),
));

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}

\GuzzleHttp\Psr7\Utils::copyToStream(
    $response->getBody(),
    \GuzzleHttp\Psr7\Utils::streamFor(fopen('php://output', 'wb'))
);
