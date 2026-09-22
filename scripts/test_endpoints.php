<?php
/**
 * Тест: какие endpoints работают для получения деталей
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$config = require __DIR__ . '/../config/avito.php';

$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

$testId = 8461416467;

echo "=== Тест endpoints для item {$testId} ===\n\n";

// Тест 1: /core/v1/items/{id} через getFullItem
echo "1. getFullItem (GET /core/v1/items/{$testId})\n";
try {
    $result = $apiClient->getFullItem($testId);
    if ($result !== null) {
        echo "  OK: keys=" . implode(', ', array_keys($result)) . "\n";
    } else {
        echo "  NULL response\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// Тест 2: /core/v1/accounts/{user_id}/items/{id} через getItemDetail
echo "\n2. getItemDetail (GET /core/v1/accounts/{$config['avito']['user_id']}/items/{$testId})\n";
try {
    $result = $apiClient->getItemDetail($testId);
    if ($result !== null) {
        echo "  OK: keys=" . implode(', ', array_keys($result)) . "\n";
    } else {
        echo "  NULL response\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// Тест 3: получаем список
echo "\n3. listItems (GET /core/v1/items?status=active&per_page=1&page=1)\n";
try {
    $result = $apiClient->listItems(['active'], 1, 1);
    $resources = $result['resources'] ?? [];
    if (!empty($resources)) {
        $item = $resources[0];
        echo "  OK: keys=" . implode(', ', array_keys($item)) . "\n";
        echo "  id={$item['id']}\n";
        echo "  title=" . ($item['title'] ?? 'N/A') . "\n";
        
        foreach (['contact_block', 'brand', 'oem_number', 'description', 'images', 'category_params'] as $field) {
            if (isset($item[$field])) {
                $val = $item[$field];
                if (is_array($val)) {
                    echo "  {$field}: [array, " . count($val) . "]\n";
                } else {
                    echo "  {$field}: {$val}\n";
                }
            } else {
                echo "  {$field}: NOT IN LIST\n";
            }
        }
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
