<?php
/**
 * count_active.php — Подсчёт активных объявлений
 *
 * Аналог Python count_active.py
 *
 * Запуск: php count_active.php
 */

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

echo "Counting active ads...\n";

$total = 0;
$page = 1;
$pageSize = 100;

while (true) {
    $items = $apiClient->listItems('active', $pageSize, $page);
    if (empty($items)) {
        break;
    }
    $total += count($items);
    echo "page {$page}: " . count($items) . " items (total={$total})\n";
    $page++;
    if (count($items) < $pageSize) {
        break;
    }
}

echo "\nTOTAL active: {$total}\n";
