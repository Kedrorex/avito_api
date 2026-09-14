<?php
/**
 * test_item.php — Тестирование: статистика 10 объявлений
 *
 * Получает первые 10 активных объявлений и собирает статистику по каждому.
 *
 * Запуск: php test_item.php [количество]
 *   php test_item.php
 *   php test_item.php 10
 *   php test_item.php 5
 */

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

// Количество объявлений (по умолчанию 10)
$count = (int) ($argv[1] ?? 10);
if ($count <= 0 || $count > 100) {
    $count = 10;
}

function printOk($msg) { echo "  [OK]   {$msg}\n"; }
function printFail($msg) { echo "  [FAIL] {$msg}\n"; }
function printSkip($msg) { echo "  [SKIP] {$msg}\n"; }
function printStep($msg) {
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  {$msg}\n";
    echo str_repeat('=', 70) . "\n";
}

// ===== Шаг 1: Получение списка активных объявлений =====
printStep("STEP 1: Get active items (up to {$count})");

try {
    $items = $apiClient->getAllItems(['active'], 100);
} catch (\Throwable $e) {
    printFail("Failed to get items: " . $e->getMessage());
    exit(1);
}

if (empty($items)) {
    printFail("No active items found");
    exit(1);
}

echo "\n  Total active items: " . count($items) . "\n";

// Берём первые N
$selected = array_slice($items, 0, $count);
echo "  Selected for testing: " . count($selected) . "\n";

// ===== Шаг 2: Информация по каждому объявлению =====
printStep("STEP 2: Item details");

$itemData = []; // [id => [info + stats]]

foreach ($selected as $index => $item) {
    $itemId = (int) ($item['id'] ?? 0);
    $itemNumber = $item['number'] ?? '?';
    $title = $item['title'] ?? 'N/A';
    $status = $item['status'] ?? '?';
    $price = $item['price'] ?? null;
        if ($price !== null && $price > 0) {
            $price = number_format($price, 0, '', ' ') . ' RUB';
        } else {
            $price = 'Не указана';
        }
    $category = $item['category']['name'] ?? '?';
    $createdAt = $item['created_at'] ?? '?';

    $num = $index + 1;
    echo "\n  [{$num}/{$count}] ID={$itemId}  Number={$itemNumber}\n";
    echo "      Title:     {$title}\n";
    echo "      Status:    {$status}\n";
    echo "      Price:     {$price}\n";
    echo "      Category:  {$category}\n";
    echo "      Created:   {$createdAt}\n";

    $itemData[$itemId] = [
        'number' => $itemNumber,
        'title' => $title,
        'status' => $status,
        'price' => $price,
        'category' => $category,
        'created_at' => $createdAt,
    ];

    // Rate limit: 8 сек между запросами
    if ($index < $count - 1) {
        echo "      Waiting 8s (rate limit)...\n";
        sleep(8);
    }
}

// ===== Шаг 3: Статистика по каждому объявлению =====
printStep("STEP 3: Collect statistics (last 30 days)");

// Период: последние 30 полных дней
$dateFrom = date('Y-m-d', strtotime('-30 days'));
$dateTo = date('Y-m-d', strtotime('-1 day'));

echo "\n  Period: {$dateFrom} — {$dateTo}\n\n";

$allStats = []; // [itemId => [daily stats]]

foreach ($selected as $index => $item) {
    $itemId = (int) ($item['id'] ?? 0);
    $num = $index + 1;

    echo "  [{$num}/{$count}] Fetching stats for ID={$itemId}... ";

    try {
        $stats = $apiClient->getStatsV2([$itemId], $dateFrom, $dateTo, 'item');

        if (empty($stats)) {
            echo "[EMPTY]\n";
            $itemData[$itemId]['stats'] = [];
            $itemData[$itemId]['totals'] = [
                'views' => 0, 'uniqViews' => 0,
                'contacts' => 0, 'uniqContacts' => 0,
                'favorites' => 0, 'uniqFavorites' => 0,
            ];
        } else {
            echo "[OK] " . count($stats[0]['stats'] ?? []) . " records\n";

            $dailyStats = $stats[0]['stats'] ?? [];
            $itemData[$itemId]['stats'] = $dailyStats;

            // Суммируем totals
            $totals = [
                'views' => 0, 'uniqViews' => 0,
                'contacts' => 0, 'uniqContacts' => 0,
                'favorites' => 0, 'uniqFavorites' => 0,
            ];

            foreach ($dailyStats as $s) {
                $totals['views'] += (int) ($s['views'] ?? 0);
                $totals['uniqViews'] += (int) ($s['uniqViews'] ?? 0);
                $totals['contacts'] += (int) ($s['contacts'] ?? 0);
                $totals['uniqContacts'] += (int) ($s['uniqContacts'] ?? 0);
                $totals['favorites'] += (int) ($s['favorites'] ?? 0);
                $totals['uniqFavorites'] += (int) ($s['uniqFavorites'] ?? 0);
            }

            $itemData[$itemId]['totals'] = $totals;
        }
    } catch (\Throwable $e) {
        echo "[ERROR: " . $e->getMessage() . "]\n";
        $itemData[$itemId]['stats'] = [];
        $itemData[$itemId]['totals'] = [
            'views' => 0, 'uniqViews' => 0,
            'contacts' => 0, 'uniqContacts' => 0,
            'favorites' => 0, 'uniqFavorites' => 0,
        ];
    }

    // Rate limit: 10 сек между запросами
    if ($index < $count - 1) {
        sleep(10);
    }
}

// ===== Шаг 4: Итоговая таблица =====
printStep("STEP 4: Summary table");

echo "\n";
echo str_pad('ID', 10)
    . str_pad('Number', 14)
    . str_pad('Title', 25)
    . str_pad('Views', 8)
    . str_pad('Uniq V', 8)
    . str_pad('Contacts', 10)
    . str_pad('Favs', 8) . "\n";
echo str_repeat('-', 91) . "\n";

$sumViews = 0; $sumContacts = 0; $sumFavs = 0;

foreach ($itemData as $itemId => $data) {
    $t = $data['totals'];
    $sumViews += $t['views'];
    $sumContacts += $t['contacts'];
    $sumFavs += $t['favorites'];

    $titleShort = mb_substr($data['title'] ?? '', 0, 25);
    echo str_pad((string) $itemId, 10)
        . str_pad($data['number'] ?? '?', 14)
        . str_pad($titleShort, 25)
        . str_pad((string) $t['views'], 8)
        . str_pad((string) $t['uniqViews'], 8)
        . str_pad((string) $t['contacts'], 10)
        . str_pad((string) $t['favorites'], 8) . "\n";
}

echo str_repeat('-', 91) . "\n";
echo str_pad('TOTAL', 48)
    . str_pad((string) $sumViews, 8)
    . str_pad('', 8)
    . str_pad((string) $sumContacts, 10)
    . str_pad((string) $sumFavs, 8) . "\n";

// ===== Шаг 5: Детальная статистика по каждому =====
printStep("STEP 5: Detailed stats per item");

foreach ($itemData as $itemId => $data) {
    $t = $data['totals'];
    $daily = $data['stats'];

    echo "\n  --- Item ID={$itemId} ({$data['number']}) ---\n";
    echo "  Title: {$data['title']}\n";
    echo "  Status: {$data['status']} | Price: {$data['price']} | Category: {$data['category']}\n";
    echo "  Period: {$dateFrom} — {$dateTo}\n\n";

    if (!empty($daily)) {
        echo "  " . str_pad('Date', 12)
            . str_pad('Views', 8)
            . str_pad('Uniq V', 8)
            . str_pad('Contacts', 10)
            . str_pad('Favs', 8) . "\n";
        echo "  " . str_repeat('-', 46) . "\n";

        foreach (array_slice($daily, -10) as $s) { // последние 10 дней
            echo "  " . str_pad($s['date'] ?? '?', 12)
                . str_pad((string) ($s['views'] ?? 0), 8)
                . str_pad((string) ($s['uniqViews'] ?? 0), 8)
                . str_pad((string) ($s['contacts'] ?? 0), 10)
                . str_pad((string) ($s['favorites'] ?? 0), 8) . "\n";
        }

        echo "  " . str_repeat('-', 46) . "\n";
    }

    echo "\n  TOTALS: views={$t['views']} (uniq={$t['uniqViews']}), "
        . "contacts={$t['contacts']} (uniq={$t['uniqContacts']}), "
        . "favorites={$t['favorites']} (uniq={$t['uniqFavorites']})\n";
}

// ===== Итог =====
echo "\n" . str_repeat('=', 70) . "\n";
echo "  TEST COMPLETE\n";
echo str_repeat('=', 70) . "\n";
echo "  Items tested:   {$count}\n";
echo "  Total views:    {$sumViews}\n";
echo "  Total contacts: {$sumContacts}\n";
echo "  Total favorites:{$sumFavs}\n";
echo "  Period:         {$dateFrom} — {$dateTo}\n";
echo str_repeat('=', 70) . "\n";
