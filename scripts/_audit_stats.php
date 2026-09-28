<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pdo = new PDO('sqlite:' . $root . '/data/avito.db', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== TABLES ===\n";
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    echo sprintf("  %-28s %s rows\n", $t, $cnt);
}

echo "\n=== stats_meta ===\n";
try {
    foreach ($pdo->query("SELECT * FROM stats_meta")->fetchAll() as $r) {
        echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) { echo '  ERR ' . $e->getMessage() . "\n"; }

$partitions = array_values(array_filter($tables, fn($t) => str_starts_with($t, 'statistics_')));
if (in_array('stats', $tables, true)) { $partitions[] = 'stats'; }

foreach ($partitions as $p) {
    echo "\n=== {$p} ===\n";
    $cols = $pdo->query("PRAGMA table_info(\"{$p}\")")->fetchAll();
    echo '  cols: ' . implode(', ', array_column($cols, 'name')) . "\n";
    $numeric = ['views','uniq_views','contacts','uniq_contacts','favorites','uniq_favorites','phone_shows','chats','price'];
    $numeric = array_values(array_intersect($numeric, array_column($cols, 'name')));
    $sel = [];
    foreach ($numeric as $c) {
        $sel[] = "SUM({$c}) AS sum_{$c}";
        $sel[] = "SUM(CASE WHEN {$c} > 0 THEN 1 ELSE 0 END) AS nz_{$c}";
    }
    $sel[] = 'COUNT(*) AS rows_total';
    $sel[] = 'COUNT(DISTINCT physical_ad_id) AS ads';
    $sel[] = 'MIN(date) AS d_min';
    $sel[] = 'MAX(date) AS d_max';
    $row = $pdo->query('SELECT ' . implode(', ', $sel) . " FROM \"{$p}\"")->fetch();
    foreach ($row as $k => $v) {
        echo sprintf("  %-22s %s\n", $k, var_export($v, true));
    }
}
