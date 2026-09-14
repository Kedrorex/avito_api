<?php
/**
 * check_item_date.php — Проверка даты создания объявления
 *
 * Аналог Python check_item_date.py
 *
 * Запуск: php check_item_date.php
 */

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

// Получаем первое объявление
echo "Getting first active ad...\n";
$items = $apiClient->listItems('active', 1, 1);

if (empty($items)) {
    echo "No ads found\n";
    exit(1);
}

$first = $items[0];
$itemId = (int) $first['id'];

echo "Item ID: {$itemId}\n";
echo "Title: " . ($first['title'] ?? 'N/A') . "\n";
echo "Status: " . ($first['status'] ?? 'N/A') . "\n";
echo "Price: " . ($first['price'] ?? 'N/A') . "\n";

// Получаем детальную информацию
echo "\n--- Detail ---\n";
$detail = $apiClient->getItemDetail($itemId);
echo json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
