<?php
/**
 * test_item_stats.php — Сбор статистики по объявлению по номеру
 *
 * Ищет объявление по номеру (например: 8012519823) и собирает статистику.
 *
 * Запуск: php test_item_stats.php [номер_объявления]
 *   php test_item_stats.php 8012519823
 *   php test_item_stats.php
 *
 * Если номер не указан — ищет по умолчанию 8012519823
 */

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

// Номер/ID объявления (из аргумента или по умолчанию)
// Это может быть номер (8012519823) или ID (8236865670)
$itemNumber = $argv[1] ?? '8012519823';

$config = require $rootDir . '/config/avito.php';
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

function printOk($msg) { echo "  [OK]   {$msg}\n"; }
function printFail($msg) { echo "  [FAIL] {$msg}\n"; }
function printSkip($msg) { echo "  [SKIP] {$msg}\n"; }
function printStep($msg) {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  STEP: {$msg}\n";
    echo str_repeat('=', 60) . "\n";
}

// ===== Шаг 1: Поиск объявления =====
printStep("Search item: {$itemNumber}");

// Ждём чтобы не получить 429
sleep(70);

// Сначала пробуем как ID
$item = $apiClient->getItemById((int) $itemNumber);

if (empty($item)) {
    echo "\n  [INFO] Not found by ID. Trying number search...\n";
    sleep(65);
    $item = $apiClient->searchItemByNumber($itemNumber);
}

if (!$item) {
    printFail("Item with number/ID {$itemNumber} not found");
    echo "\n  Possible reasons:\n";
    echo "    - Number/ID is incorrect\n";
    echo "    - Item does not belong to this account\n";
    echo "    - Item has been deleted\n";
    exit(1);
}

$itemId = (int) ($item['id'] ?? 0);
printOk("Found item: ID={$itemId}");

echo "\n  --- Item Info ---\n";
echo "  ID:         " . ($item['id'] ?? '?') . "\n";
echo "  Number:     " . ($item['number'] ?? '?') . "\n";
echo "  Title:      " . ($item['title'] ?? '?') . "\n";
echo "  Status:     " . ($item['status'] ?? '?') . "\n";
echo "  Price:      " . ($item['price']['amount'] ?? '?') . " " . ($item['price']['currency'] ?? '') . "\n";
echo "  Category:   " . ($item['category']['name'] ?? '?') . "\n";
echo "  Created:    " . ($item['created_at'] ?? '?') . "\n";
echo "  Updated:    " . ($item['updated_at'] ?? '?') . "\n";
if (!empty($item['location'])) {
    echo "  Location:   " . ($item['location']['name'] ?? '?') . "\n";
}

// ===== Шаг 2: Детальная информация =====
printStep("2. Get item details");

$userId = env('AVITO_USER_ID');
if ($userId) {
    $detail = $apiClient->getItemDetail($itemId);
    if (!empty($detail)) {
        printOk("Details retrieved");
        echo "\n  --- Details ---\n";
        echo "  Start:      " . ($detail['start_time'] ?? '?') . "\n";
        echo "  Finish:     " . ($detail['finish_time'] ?? '?') . "\n";
        echo "  Views:      " . ($detail['views'] ?? '?') . "\n";
        echo "  Contacts:   " . ($detail['contacts'] ?? '?') . "\n";
        if (!empty($detail['vas'])) {
            echo "  VAS:        " . json_encode($detail['vas'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    } else {
        printSkip("No details available (user_id may not be set)");
    }
} else {
    printSkip("AVITO_USER_ID not set — details unavailable");
}

// ===== Шаг 3: Статистика =====
printStep("3. Get statistics");

// Ждём после шага 2 чтобы не получить 429
sleep(65);

// Статистика: от даты создания до вчера
$itemStartTime = $detail['start_time'] ?? null;
if ($itemStartTime) {
    $dateFrom = date('Y-m-d', strtotime($itemStartTime));
} else {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}
$dateTo = date('Y-m-d', strtotime('-1 day'));

echo "\n  Period: {$dateFrom} — {$dateTo}\n";

$statsItems = $apiClient->getStatsV2([$itemId], $dateFrom, $dateTo, 'item');

if (empty($statsItems)) {
    printSkip("Stats empty — no data available");
    echo "\n  Item created: " . ($detail['start_time'] ?? 'unknown') . "\n";
    echo "  This is normal if the item has no views or is very new.\n";
} else {
    printOk("Stats retrieved: " . count($statsItems) . " item(s)");

    foreach ($statsItems as $statItem) {
        $iid = $statItem['itemId'] ?? '?';
        $stats = $statItem['stats'] ?? [];
        $totalViews = 0;
        $totalContacts = 0;
        $totalFavorites = 0;

        echo "\n  --- Stats for itemId={$iid} ---\n";
        echo "  Records: " . count($stats) . "\n\n";

        if (!empty($stats)) {
            echo "  " . str_pad('Date', 12)
                . str_pad('Views', 10)
                . str_pad('Uniq Views', 12)
                . str_pad('Contacts', 10)
                . str_pad('Favs', 8) . "\n";
            echo "  " . str_repeat('-', 52) . "\n";

            foreach ($stats as $s) {
                $views = (int) ($s['uniqViews'] ?? $s['views'] ?? 0);
                $contacts = (int) ($s['uniqContacts'] ?? $s['contacts'] ?? 0);
                $favorites = (int) ($s['uniqFavorites'] ?? $s['favorites'] ?? 0);

                $totalViews += $views;
                $totalContacts += $contacts;
                $totalFavorites += $favorites;

                echo "  " . str_pad($s['date'] ?? '?', 12)
                    . str_pad((string) $views, 10)
                    . str_pad((string) ($s['uniqViews'] ?? $s['views'] ?? '-'), 12)
                    . str_pad((string) $contacts, 10)
                    . str_pad((string) $favorites, 8) . "\n";
            }

            echo "  " . str_repeat('-', 52) . "\n";
            echo "  TOTAL: views={$totalViews}, contacts={$totalContacts}, favorites={$totalFavorites}\n";
        }
    }
}

// ===== Шаг 4: Статистика за разные периоды =====
printStep("4. Stats totals");

// grouping 'totals' — общие значения за период
sleep(65); // ждём после шага 3

$statsTotal = $apiClient->getStatsV2([$itemId], $dateFrom, $dateTo, 'totals');

if (!empty($statsTotal)) {
    printOk("Totals retrieved");
    foreach ($statsTotal as $st) {
        $data = $st['stats'][0] ?? [];
        echo "\n  --- Totals ---\n";
        echo "  Views:          " . ($data['views'] ?? $data['uniqViews'] ?? 0) . "\n";
        echo "  Uniq Views:     " . ($data['uniqViews'] ?? $data['views'] ?? 0) . "\n";
        echo "  Contacts:       " . ($data['contacts'] ?? $data['uniqContacts'] ?? 0) . "\n";
        echo "  Uniq Contacts:  " . ($data['uniqContacts'] ?? $data['contacts'] ?? 0) . "\n";
        echo "  Favorites:      " . ($data['favorites'] ?? $data['uniqFavorites'] ?? 0) . "\n";
        echo "  Uniq Favorites: " . ($data['uniqFavorites'] ?? $data['favorites'] ?? 0) . "\n";
    }
} else {
    printSkip("Totals empty");
}

// ===== Итог =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "  SUMMARY\n";
echo str_repeat('=', 60) . "\n";
echo "  Item number: {$itemNumber}\n";
echo "  Item ID:     {$itemId}\n";
echo "  Title:       " . ($item['title'] ?? 'N/A') . "\n";
echo "  Status:      " . ($item['status'] ?? 'N/A') . "\n";
echo str_repeat('=', 60) . "\n";
