<?php
require 'vendor/autoload.php';
require 'env_helper.php';
$config = require 'config/avito.php';
$pdo = new PDO($config['database']['dsn'], null, null, $config['database']['options']);

$stmt = $pdo->query("
    SELECT id, avito_id, unique_id, title
    FROM physical_ads
    WHERE unique_id LIKE 'avito:%'
    LIMIT 10
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Объявления с правильным unique_id:\n";
echo str_repeat('-', 100) . "\n";
foreach ($results as $row) {
    echo "id={$row['id']} avito_id={$row['avito_id']} unique_id={$row['unique_id']} title=" . ($row['title'] ?? 'N/A') . "\n";
}

$count = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE unique_id LIKE 'avito:%'")->fetchColumn();
echo "\nВсего с avito:unique_id: {$count} из " . $pdo->query("SELECT COUNT(*) FROM physical_ads")->fetchColumn() . "\n";
