#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\AvitoAPIClient;

$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Dependencies are not installed. Run: php composer.phar install\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
require $root . '/env_helper.php';

\Dotenv\Dotenv::createImmutable($root)->safeLoad();
$config = require $root . '/config/avito.php';

fwrite(STDOUT, "Avito: statistics for an advertisement\n");
fwrite(STDOUT, "Advertisement number (ID): ");
$itemNumber = trim((string) fgets(STDIN));

if (!preg_match('/^[1-9][0-9]*$/', $itemNumber)) {
    fwrite(STDERR, "Error: enter a positive numeric advertisement ID.\n");
    exit(1);
}

$dateFrom = date('Y-m-d', strtotime('-30 days'));
$dateTo = date('Y-m-d', strtotime('-1 day'));

try {
    $client = new AvitoAPIClient($config['avito']);
    $item = $client->getItemById((int) $itemNumber);
    $statistics = $client->getStatsV2([(int) $itemNumber], $dateFrom, $dateTo, 'totals');
    $totals = $statistics[0]['stats'][0] ?? [];

    fwrite(STDOUT, "\nAdvertisement\n");
    fwrite(STDOUT, 'ID: ' . ($item['id'] ?? $itemNumber) . "\n");
    fwrite(STDOUT, 'Title: ' . ($item['title'] ?? 'not provided') . "\n");
    fwrite(STDOUT, 'Status: ' . ($item['status'] ?? 'not provided') . "\n");
    fwrite(STDOUT, "Period: {$dateFrom} — {$dateTo}\n\n");
    fwrite(STDOUT, "Statistics\n");
    foreach ([
        'Views' => 'views',
        'Unique views' => 'uniqViews',
        'Contacts' => 'contacts',
        'Unique contacts' => 'uniqContacts',
        'Favorites' => 'favorites',
        'Unique favorites' => 'uniqFavorites',
    ] as $label => $key) {
        fwrite(STDOUT, sprintf("%-18s %s\n", $label . ':', $totals[$key] ?? 0));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
