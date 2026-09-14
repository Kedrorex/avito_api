#!/usr/bin/env php
<?php

declare(strict_types=1);

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

\Dotenv\Dotenv::createImmutable($rootDir)->safeLoad();

$clientId = env('AVITO_CLIENT_ID');
$clientSecret = env('AVITO_CLIENT_SECRET');
$userId = env('AVITO_USER_ID');

use Avito\OAuth2\Client\Provider\Avito;
use GuzzleHttp\Client;

$provider = new Avito([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
]);

echo "=== Получение токена ===\n";
$token = $provider->getAccessToken('client_credentials');
echo "Token: " . substr($token->getToken(), 0, 30) . "...\n\n";

$headers = $provider->getHeaders($token);
$headers['Accept'] = 'application/json';

$client = new Client();

// Пробую разные endpoint'ы
$tests = [
    'GET /core/v1/items?id=8012519823' => [
        'method' => 'GET',
        'url' => 'https://api.avito.ru/core/v1/items',
        'query' => ['id' => '8012519823'],
    ],
    'GET /core/v1/items (first 5)' => [
        'method' => 'GET',
        'url' => 'https://api.avito.ru/core/v1/items',
        'query' => ['per_page' => 5, 'page' => 1],
    ],
    'GET /core/v1/items/8012519823' => [
        'method' => 'GET',
        'url' => 'https://api.avito.ru/core/v1/items/8012519823',
        'query' => [],
    ],
];

foreach ($tests as $name => $test) {
    echo "=== {$name} ===\n";
    try {
        $response = $client->request($test['method'], $test['url'], [
            'headers' => $headers,
            'query' => $test['query'],
        ]);
        echo "Status: " . $response->getStatusCode() . "\n";
        $body = json_decode($response->getBody(), true);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
