<?php
/**
 * test_stats.php — Тестирование API статистики
 *
 * Аналог Python test_stats.py
 *
 * Запуск: php test_stats.php
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

// Получаем статистику
$dateTo = date('Y-m-d', strtotime('-1 day'));
$dateFrom = date('Y-m-d', strtotime('-30 days'));

echo "\n--- Stats request ---\n";
echo "dateFrom: {$dateFrom}\n";
echo "dateTo: {$dateTo}\n";
echo "user_id: " . env('AVITO_USER_ID') . "\n";

sleep(3);

$stats = $apiClient->getStats([$itemId], $dateFrom, $dateTo);

echo "\n--- Stats response ---\n";
echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
