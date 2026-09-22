<?php
/**
 * Тест: debug syncDetailsFromApi
 * Запуск: php scripts/test_sync_details.php
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$config = require __DIR__ . '/../config/avito.php';

$pdo = new PDO(
    $config['database']['dsn'],
    null,
    null,
    $config['database']['options']
);

$apiClient = new \App\Services\AvitoAPIClient($config['avito']);
$repository = new \App\Repositories\ItemRepository($pdo);

echo "=== Тест syncDetailsFromApi ===\n\n";

// 1. Берём 3 активных объявления
echo "1. Берём 3 активных объявления...\n";
$active = $repository->getActive();
if (empty($active)) {
    echo "  ERROR: нет активных объявлений\n";
    exit(1);
}
$active = array_slice($active, 0, 3);

foreach ($active as $ad) {
    echo "  ID={$ad['id']} avito_id={$ad['avito_id']} phone=" . ($ad['phone'] ?: 'EMPTY') . "\n";
}

// 2. Тестируем getFullItem на одном
echo "\n2. Тестируем getFullItem на первом...\n";
$first = $active[0];
$avitoId = (int) $first['avito_id'];
echo "  Запрашиваем item {$avitoId}...\n";

try {
    $detail = $apiClient->getFullItem($avitoId);
    echo "  Response keys: " . implode(', ', array_keys($detail)) . "\n";
    echo "  detail['id'] = " . ($detail['id'] ?? 'NOT SET') . "\n";
    echo "  detail['title'] = " . ($detail['title'] ?? 'NOT SET') . "\n";
    
    if (isset($detail['contact_block'])) {
        echo "  contact_block: " . json_encode($detail['contact_block'], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "  contact_block: NOT SET\n";
    }
    
    if (isset($detail['brand'])) {
        echo "  brand: " . json_encode($detail['brand'], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "  brand: NOT SET\n";
    }
    
    if (isset($detail['oem_number'])) {
        echo "  oem_number: {$detail['oem_number']}\n";
    } else {
        echo "  oem_number: NOT SET\n";
    }
    
    if (isset($detail['category_params'])) {
        echo "  category_params: " . json_encode($detail['category_params'], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "  category_params: NOT SET\n";
    }
    
    if (isset($detail['description'])) {
        echo "  description (first 100): " . substr(strip_tags($detail['description']), 0, 100) . "...\n";
    } else {
        echo "  description: NOT SET\n";
    }
    
    if (isset($detail['images'])) {
        echo "  images count: " . count($detail['images']) . "\n";
    } else {
        echo "  images: NOT SET\n";
    }
    
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 3. Тестируем syncDetailsFromApi
echo "\n3. Тестируем syncDetailsFromApi...\n";
$ids = array_map(fn($ad) => (int) $ad['avito_id'], $active);
echo "  IDs: " . implode(', ', $ids) . "\n";

$updated = $repository->syncDetailsFromApi($ids, fn($id) => $apiClient->getFullItem($id), 0.5);
echo "  Updated: {$updated}\n";

// 4. Проверяем что записалось
echo "\n4. Проверяем БД...\n";
foreach ($active as $ad) {
    $reloaded = $repository->getByAvitoId((string) $ad['avito_id']);
    echo "  avito_id={$reloaded['avito_id']} phone=" . ($reloaded['phone'] ?: 'EMPTY')
        . " brand=" . ($reloaded['brand'] ?: 'EMPTY')
        . " oem=" . ($reloaded['oem_number'] ?: 'EMPTY')
        . " desc=" . ($reloaded['description'] ? 'YES' : 'EMPTY')
        . " images=" . ($reloaded['images'] ? 'YES' : 'EMPTY')
        . " cat_params=" . ($reloaded['category_params'] ? 'YES' : 'EMPTY') . "\n";
}
