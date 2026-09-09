<?php
$pdo = new PDO('sqlite:data/avito.db');
$pdo->exec('PRAGMA foreign_keys = ON');

echo "=== Tables ===\n";
foreach ($pdo->query('SELECT name FROM sqlite_master WHERE type="table"') as $r) {
    echo "  " . $r['name'] . "\n";
}

echo "\n=== physical_ads count ===\n";
echo "  " . $pdo->query('SELECT COUNT(*) FROM physical_ads')->fetchColumn() . "\n";

echo "\n=== Statuses ===\n";
foreach ($pdo->query('SELECT status, COUNT(*) as cnt FROM physical_ads GROUP BY status') as $r) {
    echo "  " . $r['status'] . ": " . $r['cnt'] . "\n";
}

echo "\n=== stats count ===\n";
echo "  " . $pdo->query('SELECT COUNT(*) FROM stats')->fetchColumn() . "\n";

echo "\n=== Sample physical_ads (first 3) ===\n";
foreach ($pdo->query('SELECT * FROM physical_ads LIMIT 3') as $r) {
    echo "  id={$r['id']} avito_id={$r['avito_id']} status={$r['status']}\n";
    echo "    logical_key={$r['logical_key']}\n";
    echo "    published_at={$r['published_at']}\n";
    $md = json_decode($r['master_data'] ?? '{}', true);
    echo "    master_data: title=" . ($md['title'] ?? 'N/A') . " number=" . ($md['number'] ?? 'N/A') . "\n";
}

echo "\n=== Sample stats (first 5) ===\n";
foreach ($pdo->query('SELECT * FROM stats LIMIT 5') as $r) {
    echo "  physical_ad_id={$r['physical_ad_id']} date={$r['date']} views={$r['views']} contacts={$r['contacts']}\n";
}

echo "\n=== Stats per ad ===\n";
foreach ($pdo->query('SELECT physical_ad_id, COUNT(*) as days, MIN(date) as first_date, MAX(date) as last_date FROM stats GROUP BY physical_ad_id') as $r) {
    echo "  ad_id={$r['physical_ad_id']} days={$r['days']} period={$r['first_date']} — {$r['last_date']}\n";
}

echo "\nDone.\n";
