<?php
/**
 * test_integration.php — Интеграционное тестирование Avito API
 *
 * Аналог Python test_integration.py
 *
 * Запуск: php test_integration.php [-v|--verbose]
 */

$rootDir = dirname(__DIR__);
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$dotenv = \Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

$verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

function printOk($msg) { echo "  [OK]   {$msg}\n"; }
function printFail($msg) { echo "  [FAIL] {$msg}\n"; }
function printSkip($msg) { echo "  [SKIP] {$msg}\n"; }
function printStep($msg) {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  STEP: {$msg}\n";
    echo str_repeat('=', 60) . "\n";
}

$results = [];

// ===== 0. Проверка .env =====
printStep('0. Check .env');

$clientId = env('AVITO_CLIENT_ID');
$clientSecret = env('AVITO_CLIENT_SECRET');

$envOk = true;
if (!$clientId || $clientId === 'your_client_id') {
    printFail('AVITO_CLIENT_ID not set');
    $envOk = false;
} else {
    printOk("AVITO_CLIENT_ID = " . substr($clientId, 0, 6) . "...");
}

if (!$clientSecret || $clientSecret === 'your_client_secret') {
    printFail('AVITO_CLIENT_SECRET not set');
    $envOk = false;
} else {
    printOk('AVITO_CLIENT_SECRET = set');
}

$results['env'] = $envOk;
if (!$envOk) {
    echo "\n  Create .env: AVITO_CLIENT_ID=..., AVITO_CLIENT_SECRET=...\n";
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  Results\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($results as $key => $ok) {
        echo "  [SKIP] {$key}\n";
    }
    exit(1);
}

// ===== 1. Авторизация =====
printStep('Authorization');

try {
    $config = require $rootDir . '/config/avito.php';
    $apiClient = new \App\Services\AvitoAPIClient($config['avito']);
    printOk("Token: " . substr($apiClient->getToken() ?? '', 0, 20) . "...");
    $results['auth'] = true;
} catch (\Exception $e) {
    printFail("Error: " . $e->getMessage());
    $results['auth'] = false;
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  Results\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($results as $key => $ok) {
        echo "  [FAIL] {$key}\n";
    }
    exit(1);
}

// ===== 2. Полное количество активных =====
printStep('1. Total active count');

try {
    $total = 0;
    $page = 1;
    $pageSize = 100;

    while (true) {
        $items = $apiClient->listItems('active', $pageSize, $page);
        if (empty($items)) {
            break;
        }
        $total += count($items);
        $page++;
        if (count($items) < $pageSize) {
            break;
        }
    }

    echo "\n  Pages: " . ($page - 1) . ", per page: {$pageSize}\n";
    echo "  TOTAL active: {$total}\n";

    if ($total === 0) {
        printFail('No ads found');
        $results['total_count'] = false;
    } else {
        printOk("Total active: {$total}");
        $results['total_count'] = true;
    }
} catch (\Exception $e) {
    printFail("Error: " . $e->getMessage());
    $results['total_count'] = false;
}

// ===== 3. Статистика по первому объявлению =====
printStep('2. Stats for first ad');

try {
    $items = $apiClient->listItems('active', 1, 1);
    if (empty($items)) {
        printSkip('No active ads');
        $results['first_item_stats'] = true;
    } else {
        $first = $items[0];
        $itemId = (int) $first['id'];

        echo "\n  --- First ad ---\n";
        echo "  ID:          {$itemId}\n";
        echo "  Status:      " . ($first['status'] ?? '?') . "\n";
        echo "  Title:       " . ($first['title'] ?? '?') . "\n";
        echo "  Price:       " . ($first['price'] ?? '?') . "\n";
        echo "  Address:     " . ($first['address'] ?? '?') . "\n";
        echo "  URL:         " . ($first['url'] ?? '?') . "\n";

        // Details
        $userId = env('AVITO_USER_ID');
        if ($userId) {
            $detail = $apiClient->getItemDetail($itemId);
            if (!empty($detail)) {
                echo "\n  --- Details ---\n";
                echo "  Start:       " . ($detail['start_time'] ?? '?') . "\n";
                echo "  Finish:      " . ($detail['finish_time'] ?? '?') . "\n";
                echo "  VAS:         " . json_encode($detail['vas'] ?? []) . "\n";
            }
        }

        // Stats
        $dateTo = date('Y-m-d', strtotime('-1 day'));
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $statsItems = $apiClient->getStats([$itemId], $dateFrom, $dateTo);

        if (!empty($statsItems)) {
            foreach ($statsItems as $statItem) {
                $iid = $statItem['itemId'] ?? '?';
                $stats = $statItem['stats'] ?? [];
                echo "\n  --- Stats for itemId={$iid} ---\n";
                echo "  Records: " . count($stats) . "\n";
                foreach (array_slice($stats, 0, 5) as $s) {
                    echo "    date=" . ($s['date'] ?? '?')
                        . "  views=" . ($s['uniqViews'] ?? $s['views'] ?? '-')
                        . "  contacts=" . ($s['uniqContacts'] ?? $s['contacts'] ?? '-')
                        . "  favorites=" . ($s['uniqFavorites'] ?? $s['favorites'] ?? '-') . "\n";
                }
            }
            printOk("Stats retrieved: " . count($statsItems) . " ads");
            $results['first_item_stats'] = true;
        } else {
            printSkip('Stats empty (ad created recently)');
            printOk('This is normal — stats accumulate over time');
            $results['first_item_stats'] = true;
        }
    }
} catch (\Exception $e) {
    printFail("Error: " . $e->getMessage());
    $results['first_item_stats'] = false;
}

// ===== 4. Структура 10 объявлений =====
printStep('3. Structure of 10 ads');

try {
    $items = $apiClient->listItems('active', 10, 1);
    if (empty($items)) {
        printSkip('No ads');
        $results['10_items_structure'] = true;
    } else {
        printOk("Got " . count($items) . " ads");

        $allKeys = [];
        foreach ($items as $item) {
            $allKeys = array_merge($allKeys, array_keys($item));
        }
        $allKeys = array_unique($allKeys);

        echo "\n  --- All fields (" . count($allKeys) . ") ---\n";
        sort($allKeys);
        foreach ($allKeys as $key) {
            echo "    {$key}\n";
        }

        if ($verbose) {
            echo "\n  --- Details ---\n";
            foreach ($items as $i => $item) {
                echo "\n  --- #" . ($i + 1) . " ---\n";
                echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            }
        }

        $results['10_items_structure'] = true;
    }
} catch (\Exception $e) {
    printFail("Error: " . $e->getMessage());
    $results['10_items_structure'] = false;
}

// ===== 5. Распределение по статусам =====
printStep('4. Status breakdown');

try {
    $statusCounts = ['active' => 0];

    // Count active
    $page = 1;
    $pageSize = 100;
    while (true) {
        $items = $apiClient->listItems('active', $pageSize, $page);
        if (empty($items)) {
            break;
        }
        $statusCounts['active'] += count($items);
        $page++;
        if (count($items) < $pageSize) {
            break;
        }
    }

    // Other statuses
    foreach (['removed', 'old', 'blocked', 'rejected'] as $status) {
        $items = $apiClient->listItems($status, 1, 1);
        $statusCounts[$status] = count($items);
    }

    echo "\n  --- Breakdown ---\n";
    echo str_pad('Status', 15) . str_pad('Count', 10) . str_pad('%', 10) . "\n";
    echo str_repeat('-', 35) . "\n";

    $total = array_sum($statusCounts);
    foreach ($statusCounts as $status => $count) {
        $pct = $total > 0 ? ($count / $total * 100) : 0;
        echo str_pad($status, 15) . str_pad((string) $count, 10) . str_pad(number_format($pct, 1), 10) . "\n";
    }

    echo str_repeat('-', 35) . "\n";
    echo str_pad('TOTAL', 15) . str_pad((string) $total, 10) . str_pad('100.0', 10) . "\n";

    if ($total === 0) {
        printFail('No ads found');
        $results['status_breakdown'] = false;
    } else {
        printOk("Total: {$total}");
        $results['status_breakdown'] = true;
    }
} catch (\Exception $e) {
    printFail("Error: " . $e->getMessage());
    $results['status_breakdown'] = false;
}

// ===== Итоги =====
echo "\n" . str_repeat('=', 60) . "\n";
echo "  RESULTS\n";
echo str_repeat('=', 60) . "\n";

$labels = [
    'env' => '.env credentials',
    'auth' => 'Authorization',
    'total_count' => 'Total ad count',
    'first_item_stats' => 'First ad stats',
    '10_items_structure' => '10 ads structure',
    'status_breakdown' => 'Status breakdown',
];

$totalTests = count($results);
$passed = 0;
$failed = 0;

foreach ($results as $key => $ok) {
    $label = $labels[$key] ?? $key;
    if ($ok === true) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "\n  Total: {$passed}/{$totalTests} passed, {$failed} failed\n";

if ($failed === 0 && $passed > 0) {
    echo "\n  [OK] All tests passed!\n";
} else {
    echo "\n  [WARN] There are issues.\n";
}

echo str_repeat('=', 60) . "\n";
