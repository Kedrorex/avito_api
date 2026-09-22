<?php
/**
 * test_all_pages.php — Проверка, что getAllItems загружает ВСЕ страницы.
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

echo "Loading ALL items from API (statuses: " . implode(', ', $statuses) . ")...\n\n";

$items = $apiClient->getAllItems($statuses, 100, function(int $page, int $loaded, int $total): void {
    echo "  Page {$page}: loaded {$loaded} items (API total field: {$total})\n";
});

echo "\n=== RESULT ===\n";
echo "Total items fetched: " . count($items) . "\n";
echo "Pages: " . ceil(count($items) / 100) . "\n";

// Подсчёт по статусам
$statusCounts = [];
foreach ($items as $item) {
    $status = $item['status'] ?? 'unknown';
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
}

echo "\nBy status:\n";
foreach ($statusCounts as $status => $count) {
    echo "  {$status}: {$count}\n";
}

// Проверка number
$withNumber = 0;
$withoutNumber = 0;
foreach ($items as $item) {
    if (!empty($item['number'])) {
        $withNumber++;
    } else {
        $withoutNumber++;
    }
}
echo "\nWith 'number' field: {$withNumber}\n";
echo "Without 'number' field: {$withoutNumber}\n";
