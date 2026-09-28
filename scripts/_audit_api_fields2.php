<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/env_helper.php';
\Dotenv\Dotenv::createImmutable($root)->safeLoad();
$config = require $root . '/config/avito.php';

$client = new \App\Services\AvitoAPIClient($config['avito']);
$ref = new ReflectionMethod($client, 'requestJson');
$ref->setAccessible(true);

$userId = trim((string) $config['avito']['user_id']);
$path = sprintf('/stats/v1/accounts/%s/items', rawurlencode($userId));

$itemId = 8076616350;
$dateFrom = '2026-09-06';
$dateTo   = '2026-09-20';

function probe(ReflectionMethod $ref, $client, string $path, string $label, array $payload): void {
    echo "\n########## {$label} ##########\n";
    echo "payload: " . json_encode($payload) . "\n";
    try {
        $resp = $ref->invoke($client, 'POST', $path, $payload);
        $items = $resp['result']['items'] ?? $resp['items'] ?? [];
        $rows = $items[0]['stats'] ?? [];
        echo "rows returned: " . count($rows) . "\n";
        $keys = [];
        foreach ($rows as $s) { $keys = array_merge($keys, array_keys($s)); }
        echo "keys: " . implode(', ', array_values(array_unique($keys))) . "\n";
        foreach ($rows as $s) {
            if (($s['date'] ?? '') === '2026-09-14' || count($rows) <= 2) {
                echo "  row: " . json_encode($s) . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}

// A2: repeat of what our code sends today — recheck uniqFavorites on 2026-09-14
probe($ref, $client, $path, 'A2 no fields (our current code)', [
    'itemIds' => [$itemId], 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'grouping' => 'item',
]);

sleep(65);

// C: are contactsShowPhone / contactsMessenger valid field names?
probe($ref, $client, $path, 'C fields incl. contactsShowPhone/contactsMessenger', [
    'itemIds' => [$itemId], 'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
    'fields' => ['views','uniqViews','contacts','uniqContacts','favorites','uniqFavorites','contactsShowPhone','contactsMessenger'],
    'periodGrouping' => 'day',
]);

sleep(65);

// D: does grouping=totals (used by bin/item-stats.php) actually aggregate?
probe($ref, $client, $path, 'D grouping=totals (bin/item-stats.php)', [
    'itemIds' => [$itemId], 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'grouping' => 'totals',
]);
