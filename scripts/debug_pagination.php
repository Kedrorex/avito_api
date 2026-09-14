<?php
/**
 * debug_pagination.php — Проверка пагинации API.
 * Показывает, сколько страниц и сколько items на каждой.
 */

declare(strict_types=1);

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

$statuses = $config['avito']['item_statuses'] ?? ['active', 'removed', 'old', 'blocked', 'rejected'];

echo "=== Pagination Debug ===\n";
echo "Statuses: " . implode(', ', $statuses) . "\n\n";

$page = 1;
$totalFetched = 0;
$maxPages = 100; // защита от бесконечного цикла

do {
    $result = $apiClient->listItems($statuses, $page, 100);
    $resources = $result['resources'] ?? [];
    $total = (int) ($result['total'] ?? 0);
    
    $count = count($resources);
    echo "Page {$page}: {$count} items | total field: {$total} | cumulative: " . ($totalFetched + $count) . "\n";
    
    if ($count === 0) {
        echo "\n[STOP] Empty page at {$page}\n";
        break;
    }
    
    $totalFetched += $count;
    $page++;
    
    if ($page > $maxPages) {
        echo "\n[STOP] Max pages reached ({$maxPages})\n";
        break;
    }
    
    sleep(8);
} while (true);

echo "\nTotal items fetched: {$totalFetched}\n";
echo "Pages fetched: " . ($page - 1) . "\n";

// Покажем статусы первых 3 items
echo "\n=== Sample items (first 3) ===\n";
$allItems = [];
$page = 1;
do {
    $result = $apiClient->listItems($statuses, $page, 100);
    $resources = $result['resources'] ?? [];
    if ($resources === []) break;
    $allItems = array_merge($allItems, $resources);
    $page++;
    sleep(8);
    if ($page > 3) break; // только первые 3 страницы
} while (true);

foreach (array_slice($allItems, 0, 3) as $i => $item) {
    echo ($i + 1) . ". id={$item['id']} number=" . ($item['number'] ?? 'NULL') 
        . " status={$item['status']} title=" . mb_substr($item['title'] ?? 'N/A', 0, 40) . "\n";
}
