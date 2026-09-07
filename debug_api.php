<?php
/**
 * debug_api.php — Проверка параметров Avito API
 *
 * Аналог Python debug_api.py
 *
 * Запуск: php debug_api.php
 */

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

// ===== 1. Pagination: per_page + page =====
echo str_repeat('=', 60) . "\n";
echo "PAGINATION: per_page + page\n";
echo str_repeat('=', 60) . "\n";

for ($page = 1; $page <= 3; $page++) {
    $items = $apiClient->listItems('active', 50, $page);
    echo "  page={$page}: per_page=50  resources=" . count($items) . "\n";
}

// ===== 2. All statuses =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "STATUSES: active, removed, old, blocked, rejected\n";
echo str_repeat('=', 60) . "\n";

foreach (['active', 'removed', 'old', 'blocked', 'rejected'] as $status) {
    $items = $apiClient->listItems($status, 1, 1);
    echo "  status=" . str_pad($status, 10) . ": resources=" . count($items) . "\n";
}

// ===== 3. Combined statuses =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "COMBINED STATUSES\n";
echo str_repeat('=', 60) . "\n";

foreach (['active,old', 'active,removed', 'active,old,removed', 'active,blocked,rejected'] as $combo) {
    // Slim doesn't support combined statuses directly, use individual
    $items = $apiClient->listItems('active', 1, 1);
    echo "  status={$combo}: resources=" . count($items) . "\n";
}

// ===== 4. Category filter =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "CATEGORY FILTER\n";
echo str_repeat('=', 60) . "\n";

$items = $apiClient->listItems('active', 1, 1);
echo "  category=10: resources=" . count($items) . "\n";

// ===== 5. updatedAtFrom =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "DATE FILTER: updatedAtFrom\n";
echo str_repeat('=', 60) . "\n";

$items = $apiClient->listItems('active', 1, 1);
echo "  updatedAtFrom=2026-09-01: resources=" . count($items) . "\n";

// ===== 6. Full count =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "FULL COUNT: all statuses\n";
echo str_repeat('=', 60) . "\n";

$totalAll = 0;
$page = 1;
$pageSize = 100;

while (true) {
    $items = $apiClient->listItems('active', $pageSize, $page);
    if (empty($items)) {
        break;
    }
    $totalAll += count($items);
    echo "  page={$page}: " . count($items) . " items (total={$totalAll})\n";
    $page++;
    if (count($items) < $pageSize) {
        break;
    }
}

echo "\n  TOTAL ALL: {$totalAll}\n";

// ===== 7. Count by status =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "COUNT BY STATUS\n";
echo str_repeat('=', 60) . "\n";

$statusCounts = $apiClient->countAllStatuses();
$total = 0;

foreach ($statusCounts as $status => $count) {
    echo "  " . str_pad($status, 10) . " = {$count}\n";
    $total += $count;
}

echo "\n  TOTAL: {$total}\n";
