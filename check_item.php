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

echo "=== Токен ===\n";
$token = $provider->getAccessToken('client_credentials');
echo "OK\n\n";

$headers = $provider->getHeaders($token);
$headers['Accept'] = 'application/json';

$client = new Client();

$itemId = '08.06_134';

echo "=== Поиск объявления: {$itemId} ===\n\n";

// 1. Пробую через query param id
echo "1. GET /core/v1/items?id=08.06_134\n";
try {
    $response = $client->request('GET', 'https://api.avito.ru/core/v1/items', [
        'headers' => $headers,
        'query' => ['id' => $itemId],
    ]);
    echo "Status: " . $response->getStatusCode() . "\n";
    $body = json_decode((string) $response->getBody(), true);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
echo "\n";

// 2. Получу список всех активных и поищу там
echo "2. Список активных (первые 10) — ищу {$itemId}\n";
try {
    $response = $client->request('GET', 'https://api.avito.ru/core/v1/items', [
        'headers' => $headers,
        'query' => ['per_page' => 10, 'page' => 1],
    ]);
    $body = json_decode((string) $response->getBody(), true);
    $resources = $body['resources'] ?? [];
    echo "Всего на странице: " . count($resources) . "\n\n";
    
    foreach ($resources as $item) {
        echo "  ID: " . ($item['id'] ?? 'N/A') . " | Title: " . ($item['title'] ?? 'N/A') . "\n";
        if ($item['id'] === $itemId || ($item['number'] ?? '') === $itemId) {
            echo "  >>> MATCH!\n";
        }
    }
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Статистика через stats API
echo "3. Статистика для {$itemId}\n";
$dateFrom = date('Y-m-d', strtotime('-30 days'));
$dateTo = date('Y-m-d', strtotime('-1 day'));

echo "   Period: {$dateFrom} — {$dateTo}\n\n";

echo "   3a. Grouping=item\n";
try {
    $response = $client->request('POST', 'https://api.avito.ru/stats/v1/accounts/' . $userId . '/items', [
        'headers' => $headers + ['Content-Type' => 'application/json'],
        'json' => [
            'itemIds' => [$itemId],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'grouping' => 'item',
        ],
    ]);
    echo "Status: " . $response->getStatusCode() . "\n";
    $body = json_decode((string) $response->getBody(), true);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "   3b. Grouping=totals\n";
try {
    $response = $client->request('POST', 'https://api.avito.ru/stats/v1/accounts/' . $userId . '/items', [
        'headers' => $headers + ['Content-Type' => 'application/json'],
        'json' => [
            'itemIds' => [$itemId],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'grouping' => 'totals',
        ],
    ]);
    echo "Status: " . $response->getStatusCode() . "\n";
    $body = json_decode((string) $response->getBody(), true);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
echo "\n";
