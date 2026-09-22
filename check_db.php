<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/env_helper.php';
$config = require __DIR__ . '/config/avito.php';
$pdo = new PDO($config['database']['dsn'], null, null, $config['database']['options']);

echo "=== Tables ===" . PHP_EOL;
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) echo "  - $t" . PHP_EOL;

echo PHP_EOL . "=== physical_ads sample ===" . PHP_EOL;
$stmt = $pdo->query("SELECT * FROM physical_ads LIMIT 3");
$ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($ads as $ad) {
    echo json_encode($ad, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    echo "---" . PHP_EOL;
}

echo PHP_EOL . "=== master_data sample ===" . PHP_EOL;
$stmt = $pdo->query("SELECT master_data FROM physical_ads LIMIT 1");
$row = $stmt->fetch();
if ($row && $row['master_data']) {
    echo json_encode(json_decode($row['master_data'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
