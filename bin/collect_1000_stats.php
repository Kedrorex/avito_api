<?php
/**
 * collect_1000_stats.php — Сбор статистики для 1000+ объявлений всех статусов в SQLite.
 *
 * Алгоритм:
 * 1. Загружаем объявления ВСЕХ статусов (active, removed, old, blocked, rejected)
 * 2. Сохраняем/обновляем их в physical_ads
 * 3. Разбиваем на пакеты по 200
 * 4. Для каждого пакета запрашиваем статистику за N дней
 * 5. Сохраняем в statistics_YYYY_MM с транзакцией
 *
 * Запуск:
 *   php collect_1000_stats.php [days]
 *   php collect_1000_stats.php        # 30 дней (по умолчанию)
 *   php collect_1000_stats.php 7      # 7 дней
 *   php collect_1000_stats.php 1      # 1 день (быстрый тест)
 */

declare(strict_types=1);

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$config = require $rootDir . '/config/avito.php';

$days = isset($argv[1]) ? (int) $argv[1] : 30;
if ($days < 1 || $days > 270) {
    echo "Usage: php collect_1000_stats.php [days: 1..270]\n";
    echo "Default: 30 days\n";
    exit(1);
}

$apiClient = new \App\Services\AvitoAPIClient($config['avito']);

// Создаём PDO и репозиторий
$pdo = new PDO(
    $config['database']['dsn'],
    null,
    null,
    $config['database']['options']
);
$pdo->exec('PRAGMA foreign_keys = ON');

$repo = new \App\Repositories\ItemRepository($pdo);

// ===== Утилиты =====
function printOk($msg) { echo "  [OK]   {$msg}\n"; }
function printFail($msg) { echo "  [FAIL] {$msg}\n"; }
function printStep($msg) {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "  {$msg}\n";
    echo str_repeat('=', 80) . "\n";
}

// ===== Шаг 1: Загрузка объявлений всех статусов =====
printStep("STEP 1: Loading advertisements (all statuses, up to {$days} days stats)");

$statuses = $config['avito']['item_statuses'] ?? ['active', 'removed', 'old', 'blocked', 'rejected'];
echo "\n  Statuses: " . implode(', ', $statuses) . "\n";
echo "  Period: {$days} days\n";

$page = 1;
$totalFetched = 0;
$allItems = [];

do {
    $result = $apiClient->listItems($statuses, $page, 100);
    $resources = $result['resources'] ?? [];
    $total = (int) ($result['total'] ?? 0);

    if ($resources === []) {
        break;
    }

    $allItems = array_merge($allItems, $resources);
    $totalFetched += count($resources);

    echo "  Page {$page}: fetched " . count($resources) . " (total: {$totalFetched})\n";

    // API не возвращает корректный total — продолжаем пока есть страницы
    if ($page >= 500) {
        break;
    }

    $page++;
    sleep(8); // rate limit: 25 req/min for GET /core/v1/items
} while (true);

echo "\n  Total loaded: " . count($allItems) . " advertisements\n";

if (count($allItems) === 0) {
    printFail("No advertisements found");
    exit(1);
}

// ===== Шаг 2: Сохранение в БД =====
printStep("STEP 2: Saving to database");

$created = $repo->syncFromApi($allItems);
echo "  Created new: {$created}\n";
echo "  Updated existing: " . (count($allItems) - $created) . "\n";

// Статистика по статусам в БД
echo "\n  Statuses in DB:\n";
foreach ($statuses as $status) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM physical_ads WHERE status = :status");
    $stmt->execute([':status' => $status]);
    $cnt = $stmt->fetchColumn();
    echo "    {$status}: {$cnt}\n";
}

// ===== Шаг 3: Сбор статистики =====
printStep("STEP 3: Collecting statistics");

$dateTo = date('Y-m-d', strtotime('-1 day'));
$dateFrom = date('Y-m-d', strtotime("-{$days} days"));
echo "\n  Period: {$dateFrom} — {$dateTo}\n";

$delaySeconds = (int) ($config['avito']['stats_request_delay_seconds'] ?? 65);
$maxRetries = (int) ($config['avito']['max_retries'] ?? 3);
$retryDelay = (int) ($config['avito']['retry_delay_base'] ?? 65);

// Пакеты по 1000 (максимум API для stats)
$batches = array_chunk($allItems, 1000);
$totalBatches = count($batches);
$savedItems = 0;
$failedBatches = 0;
$totalStatsRecords = 0;

foreach ($batches as $batchNumber => $batch) {
    $displayBatch = $batchNumber + 1;
    $itemIds = array_values(array_filter(array_map(
        static fn(array $item): int => (int) ($item['id'] ?? 0),
        $batch
    )));

    if ($itemIds === []) {
        continue;
    }

    echo "\n  Batch {$displayBatch}/{$totalBatches}: " . count($itemIds) . " ads...\n";

    $retryCount = 0;
    $statsList = null;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        try {
            $statsList = $apiClient->getStatsV2($itemIds, $dateFrom, $dateTo, 'item');
            break; // success
        } catch (\Throwable $e) {
            $retryCount++;
            if ($retryCount > $maxRetries) {
                echo "    [ERROR] Failed after {$maxRetries} retries: {$e->getMessage()}\n";
                $failedBatches++;
                break;
            }
            echo "    [WARN] Retry {$retryCount}/{$maxRetries} in {$retryDelay}s...\n";
            sleep($retryDelay);
        }
    }

    if ($statsList === null || $statsList === []) {
        continue;
    }

    // Группируем статистику по itemId
    $statsByItemId = [];
    foreach ($statsList as $statItem) {
        $itemId = (string) ($statItem['itemId'] ?? '');
        if ($itemId !== '') {
            $statsByItemId[$itemId] = $statItem['stats'] ?? [];
        }
    }

    // Сохраняем в БД в транзакции
    try {
        $pdo->beginTransaction();
        $batchSaved = 0;
        foreach ($itemIds as $itemId) {
            $ad = $repo->getByAvitoId((string) $itemId);
            if ($ad === null) {
                continue;
            }

            // Получаем price из master_data
            $price = 0;
            if (!empty($ad['master_data'])) {
                $masterData = json_decode($ad['master_data'], true);
                $price = (int) ($masterData['price'] ?? 0);
            }

            if (!isset($statsByItemId[(string) $itemId]) || $statsByItemId[(string) $itemId] === []) {
                // Сохраняем пустую статистику — это нормально для новых объявлений
                $repo->saveStats((int) $ad['id'], [], $price);
                $batchSaved++;
                continue;
            }
            $repo->saveStats((int) $ad['id'], $statsByItemId[(string) $itemId], $price);
            $batchSaved++;
            $totalStatsRecords += count($statsByItemId[(string) $itemId]);
        }
        $pdo->commit();
        $savedItems += $batchSaved;
        echo "    Saved stats for {$batchSaved} ads\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        $failedBatches++;
        echo "    [ERROR] Transaction failed: {$e->getMessage()}\n";
    }

    // Пауза между запросами
    if ($batchNumber < $totalBatches - 1 && $delaySeconds > 0) {
        echo "    Waiting {$delaySeconds}s...\n";
        sleep($delaySeconds);
    }
}

// ===== Итог =====
printStep("RESULTS");

echo "\n  Advertisements loaded:  " . count($allItems) . "\n";
echo "  Created in DB:          {$created}\n";
echo "  Saved ads with stats:   {$savedItems}\n";
echo "  Total stats records:    {$totalStatsRecords}\n";
echo "  Failed batches:         {$failedBatches}\n";
echo "  Period:                 {$dateFrom} — {$dateTo}\n";

// Проверка БД
echo "\n  Verification:\n";
$totalInDb = $pdo->query('SELECT COUNT(*) FROM physical_ads')->fetchColumn();
$totalStats = $pdo->query('SELECT COUNT(*) FROM stats')->fetchColumn();
$adsWithStats = $pdo->query('SELECT COUNT(DISTINCT physical_ad_id) FROM stats')->fetchColumn();

echo "    physical_ads total:   {$totalInDb}\n";
echo "    stats rows (old):     {$totalStats}\n";
echo "    ads with stats (old): {$adsWithStats}\n";

// Проверяем секции
$partitionCount = (int) $pdo->query("SELECT COUNT(*) FROM stats_meta")->fetchColumn();
echo "    partitions created:   {$partitionCount}\n";

if ($partitionCount > 0) {
    $partitionStats = $pdo->query("SELECT SUM(record_count) FROM stats_meta")->fetchColumn();
    echo "    partition records:    {$partitionStats}\n";
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "  DONE\n";
echo str_repeat('=', 80) . "\n";
