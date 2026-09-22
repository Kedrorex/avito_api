<?php
/**
 * Тест: есть ли endpoint для получения полных данных объявления
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$config = require __DIR__ . '/../config/avito.php';

$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

$testId = 8461416467;
$userId = $config['avito']['user_id'];

echo "=== Поиск endpoint для полных данных ===\n\n";

// Пробуем разные варианты
$endpoints = [
    "/core/v1/accounts/{$userId}/items/{$testId}/full",
    "/core/v1/accounts/{$userId}/items/{$testId}/details",
    "/items/v1/items/{$testId}",
    "/items/v1/accounts/{$userId}/items/{$testId}",
    "/core/v2/items/{$testId}",
];

foreach ($endpoints as $ep) {
    echo "GET {$ep}\n";
    try {
        $result = $apiClient->getItemDetailRaw($testId); // это уже вернёт autoload данные
        echo "  getItemDetail: " . json_encode(array_keys($result), JSON_UNESCAPED_UNICODE) . "\n";
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Попробуем получить images
echo "=== Images endpoint ===\n";
try {
    $images = $apiClient->getImages($testId);
    echo "  getImages: " . count($images) . " images\n";
    if (!empty($images)) {
        echo "  first: " . json_encode($images[0], JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
