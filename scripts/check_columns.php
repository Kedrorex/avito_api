<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env_helper.php';

$pdo = new PDO(
    'sqlite:' . __DIR__ . '/../data/avito.db',
    null,
    null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== physical_ads columns ===\n";
$cols = $pdo->query("PRAGMA table_info(physical_ads)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['name']} ({$c['type']})" . PHP_EOL;
}

echo PHP_EOL . "=== Sample active ad (first) ===\n";
$row = $pdo->query("SELECT * FROM physical_ads WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    foreach ($row as $k => $v) {
        if ($v !== '' && $v !== null) {
            $display = is_string($v) && strlen($v) > 100 ? substr($v, 0, 100) . '...' : $v;
            echo "  {$k}: {$display}" . PHP_EOL;
        }
    }
} else {
    echo "  No active ads found" . PHP_EOL;
}
