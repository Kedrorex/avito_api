#!/usr/bin/env php
<?php

declare(strict_types=1);

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

\Dotenv\Dotenv::createImmutable($rootDir)->safeLoad();

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

$itemNumber = 8012519823;

echo "=== Получение объявления ===\n";
echo "ID: {$itemNumber}\n\n";

try {
    // 1. Получаем информацию об объявлении
    $item = $apiClient->getItemById($itemNumber);
    
    echo "ID: " . ($item['id'] ?? 'N/A') . "\n";
    echo "Title: " . ($item['title'] ?? 'N/A') . "\n";
    echo "Status: " . ($item['status'] ?? 'N/A') . "\n";
    echo "Price: " . ($item['price']['amount'] ?? 'N/A') . "\n";
    echo "Created: " . ($item['created_at'] ?? 'N/A') . "\n\n";
    
    // 2. Получаем статистику за последние 30 дней
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d', strtotime('-1 day'));
    
    echo "=== Статистика ({$dateFrom} — {$dateTo}) ===\n\n";
    
    // Группировка по дням
    $statsByDay = $apiClient->getStatsV2([$itemNumber], $dateFrom, $dateTo, 'item');
    
    if (empty($statsByDay)) {
        echo "Нет данных по статистике.\n";
    } else {
        foreach ($statsByDay as $statItem) {
            $itemId = $statItem['itemId'] ?? '?';
            $stats = $statItem['stats'] ?? [];
            
            echo "itemId: {$itemId}\n";
            echo str_repeat('-', 70) . "\n";
            printf("%-12s %8s %12s %8s %12s %8s %12s\n", 
                'Date', 'Views', 'UniqViews', 'Contacts', 'UniqContacts', 'Favs', 'UniqFavs');
            echo str_repeat('-', 70) . "\n";
            
            $totalViews = 0;
            $totalContacts = 0;
            $totalFavs = 0;
            
            foreach ($stats as $s) {
                $views = (int) ($s['views'] ?? 0);
                $uniqViews = (int) ($s['uniqViews'] ?? 0);
                $contacts = (int) ($s['contacts'] ?? 0);
                $uniqContacts = (int) ($s['uniqContacts'] ?? 0);
                $favs = (int) ($s['favorites'] ?? 0);
                $uniqFavs = (int) ($s['uniqFavorites'] ?? 0);
                
                $totalViews += $views;
                $totalContacts += $contacts;
                $totalFavs += $favs;
                
                printf("%-12s %8d %12d %8d %12d %8d %12d\n",
                    $s['date'] ?? '?',
                    $views, $uniqViews,
                    $contacts, $uniqContacts,
                    $favs, $uniqFavs
                );
            }
            
            echo str_repeat('-', 70) . "\n";
            echo "TOTAL: views={$totalViews}, contacts={$totalContacts}, favorites={$totalFavs}\n";
        }
    }
    
    // 3. Общие totals за период
    echo "\n=== Totals за весь период ===\n\n";
    
    $statsTotals = $apiClient->getStatsV2([$itemNumber], $dateFrom, $dateTo, 'totals');
    
    if (empty($statsTotals)) {
        echo "Нет данных по totals.\n";
    } else {
        foreach ($statsTotals as $statItem) {
            $totals = $statItem['stats'][0] ?? [];
            
            echo "Views:          " . ($totals['views'] ?? 0) . "\n";
            echo "Unique views:   " . ($totals['uniqViews'] ?? 0) . "\n";
            echo "Contacts:       " . ($totals['contacts'] ?? 0) . "\n";
            echo "Unique contacts:" . ($totals['uniqContacts'] ?? 0) . "\n";
            echo "Favorites:      " . ($totals['favorites'] ?? 0) . "\n";
            echo "Unique favorites:" . ($totals['uniqFavorites'] ?? 0) . "\n";
        }
    }
    
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
