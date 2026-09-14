<?php
$pdo = new PDO('sqlite:data/avito.db');
$pdo->exec('PRAGMA foreign_keys = ON');

echo "=== Tables ===\n";
foreach ($pdo->query('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name') as $r) {
    echo "  " . $r['name'] . "\n";
}

echo "\n=== physical_ads count ===\n";
echo "  " . $pdo->query('SELECT COUNT(*) FROM physical_ads')->fetchColumn() . "\n";

echo "\n=== Statuses ===\n";
foreach ($pdo->query('SELECT status, COUNT(*) as cnt FROM physical_ads GROUP BY status') as $r) {
    echo "  " . $r['status'] . ": " . $r['cnt'] . "\n";
}

// ===== Старая таблица stats =====
$oldExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stats'")->fetchColumn();
if ($oldExists > 0) {
    echo "\n=== stats (old table) ===\n";
    echo "  " . $pdo->query('SELECT COUNT(*) FROM stats')->fetchColumn() . " rows\n";

    echo "\n=== Sample stats (old, first 5) ===\n";
    foreach ($pdo->query('SELECT * FROM stats LIMIT 5') as $r) {
        echo "  physical_ad_id={$r['physical_ad_id']} date={$r['date']} views={$r['views']} contacts={$r['contacts']}\n";
    }
}

// ===== stats_old =====
$oldExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stats_old'")->fetchColumn();
if ($oldExists > 0) {
    echo "\n=== stats_old (migrated) ===\n";
    echo "  " . $pdo->query('SELECT COUNT(*) FROM stats_old')->fetchColumn() . " rows\n";
}

// ===== Секции =====
echo "\n=== Partitions (statistics_YYYY_MM) ===\n";
$partitions = [];
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'statistics_%' ORDER BY name") as $r) {
    $partitions[] = $r['name'];
}

if ($partitions === []) {
    echo "  No partition tables found\n";
} else {
    foreach ($partitions as $p) {
        $cnt = $pdo->query("SELECT COUNT(*) FROM {$p}")->fetchColumn();
        echo "  {$p}: {$cnt} rows\n";
    }
}

// ===== stats_meta =====
$metaExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stats_meta'")->fetchColumn();
if ($metaExists > 0) {
    echo "\n=== stats_meta ===\n";
    foreach ($pdo->query("SELECT partition_name, created_at, record_count FROM stats_meta ORDER BY partition_name") as $r) {
        echo "  {$r['partition_name']}: {$r['record_count']} records (created: {$r['created_at']})\n";
    }

    $totalPartitionRecords = (int) $pdo->query("SELECT COALESCE(SUM(record_count), 0) FROM stats_meta")->fetchColumn();
    echo "  TOTAL: {$totalPartitionRecords} records across " . count($partitions) . " partitions\n";
}

// ===== Sample partition data =====
if ($partitions !== []) {
    $latestPartition = end($partitions);
    echo "\n=== Sample from {$latestPartition} (first 5) ===\n";
    foreach ($pdo->query("SELECT * FROM {$latestPartition} LIMIT 5") as $r) {
        echo "  physical_ad_id={$r['physical_ad_id']} date={$r['date']} views={$r['views']} contacts={$r['contacts']} phone_shows={$r['phone_shows']} chats={$r['chats']} price={$r['price']}\n";
    }
}

// ===== Stats per ad (from partitions) =====
if ($partitions !== []) {
    echo "\n=== Stats per ad (from partitions) ===\n";
    $unionParts = [];
    foreach ($partitions as $p) {
        $unionParts[] = "SELECT physical_ad_id, COUNT(*) as days, MIN(date) as first_date, MAX(date) as last_date FROM {$p} GROUP BY physical_ad_id";
    }
    $unionSql = implode(' UNION ALL ', $unionParts);
    $stmt = $pdo->query("
        SELECT physical_ad_id, SUM(days) as total_days, MIN(first_date) as first_date, MAX(last_date) as last_date
        FROM ({$unionSql})
        GROUP BY physical_ad_id
        ORDER BY total_days DESC
        LIMIT 10
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  ad_id={$r['physical_ad_id']} days={$r['total_days']} period={$r['first_date']} — {$r['last_date']}\n";
    }
}

echo "\nDone.\n";
