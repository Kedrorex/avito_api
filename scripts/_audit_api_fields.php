<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/env_helper.php';
\Dotenv\Dotenv::createImmutable($root)->safeLoad();
$config = require $root . '/config/avito.php';

$client = new \App\Services\AvitoAPIClient($config['avito']);

// private requestJson -> accessible for this audit only
$ref = new ReflectionMethod($client, 'requestJson');
$ref->setAccessible(true);

$userId = trim((string) $config['avito']['user_id']);
$path = sprintf('/stats/v1/accounts/%s/items', rawurlencode($userId));

$itemId = (int) ($argv[1] ?? 8076616350);
$dateFrom = '2026-09-06';
$dateTo   = '2026-09-20';

function dump(string $label, array $resp): void {
    echo "\n########## {$label} ##########\n";
    echo substr(json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 4000) . "\n";
    $items = $resp['result']['items'] ?? $resp['items'] ?? [];
    $keys = [];
    foreach ($items as $it) {
        foreach ($it['stats'] ?? [] as $s) { $keys = array_merge($keys, array_keys($s)); }
    }
    echo "--> keys present in stats rows: " . implode(', ', array_values(array_unique($keys))) . "\n";
}

// A) exactly what our code sends today
$payloadA = [
    'itemIds'  => [$itemId],
    'dateFrom' => $dateFrom,
    'dateTo'   => $dateTo,
    'grouping' => 'item',
];
try { dump('A: current payload (grouping=item)', $ref->invoke($client, 'POST', $path, $payloadA)); }
catch (Throwable $e) { echo "\nA FAILED: " . $e->getMessage() . "\n"; }

sleep(65);

// B) documented payload with explicit fields + periodGrouping
$payloadB = [
    'itemIds'        => [$itemId],
    'dateFrom'       => $dateFrom,
    'dateTo'         => $dateTo,
    'fields'         => ['uniqViews', 'uniqContacts', 'uniqFavorites', 'views', 'contacts', 'favorites'],
    'periodGrouping' => 'day',
];
try { dump('B: fields + periodGrouping=day', $ref->invoke($client, 'POST', $path, $payloadB)); }
catch (Throwable $e) { echo "\nB FAILED: " . $e->getMessage() . "\n"; }
