<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pdo = new PDO('sqlite:' . $root . '/data/avito.db', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== rows where views>0 OR contacts>0 OR favorites>0 OR price>0 ===\n";
foreach ($pdo->query("SELECT * FROM statistics_2026_09 WHERE views>0 OR contacts>0 OR favorites>0 OR price>0")->fetchAll() as $r) {
    echo '  ' . json_encode($r) . "\n";
}

echo "\n=== rows per date ===\n";
foreach ($pdo->query("SELECT date, COUNT(*) c, COUNT(DISTINCT physical_ad_id) ads, SUM(uniq_views) uv FROM statistics_2026_09 GROUP BY date ORDER BY date")->fetchAll() as $r) {
    echo sprintf("  %s  rows=%-6s ads=%-6s uniq_views=%s\n", $r['date'], $r['c'], $r['ads'], $r['uv']);
}

echo "\n=== distribution: how many dates per ad ===\n";
foreach ($pdo->query("SELECT n, COUNT(*) ads FROM (SELECT physical_ad_id, COUNT(*) n FROM statistics_2026_09 GROUP BY physical_ad_id) GROUP BY n ORDER BY n")->fetchAll() as $r) {
    echo sprintf("  %-4s dates -> %s ads\n", $r['n'], $r['ads']);
}

echo "\n=== coverage vs physical_ads ===\n";
$total = $pdo->query('SELECT COUNT(*) FROM physical_ads')->fetchColumn();
$withStats = $pdo->query('SELECT COUNT(DISTINCT physical_ad_id) FROM statistics_2026_09')->fetchColumn();
echo "  physical_ads: {$total}\n  ads with >=1 stat row: {$withStats}\n";
foreach ($pdo->query("SELECT p.status, COUNT(*) total, SUM(CASE WHEN s.physical_ad_id IS NULL THEN 0 ELSE 1 END) with_stats
    FROM physical_ads p LEFT JOIN (SELECT DISTINCT physical_ad_id FROM statistics_2026_09) s ON s.physical_ad_id=p.id
    GROUP BY p.status ORDER BY total DESC")->fetchAll() as $r) {
    echo sprintf("  %-12s total=%-6s with_stats=%s\n", $r['status'], $r['total'], $r['with_stats']);
}

echo "\n=== physical_ads.price / master_data price ===\n";
$r = $pdo->query("SELECT COUNT(*) total, SUM(CASE WHEN price>0 THEN 1 ELSE 0 END) price_gt0 FROM physical_ads")->fetch();
echo '  ' . json_encode($r) . "\n";
$r = $pdo->query("SELECT COUNT(*) md_price_gt0 FROM physical_ads WHERE json_extract(master_data,'$.price') > 0")->fetch();
echo '  ' . json_encode($r) . "\n";

echo "\n=== stats_old ===\n";
$cols = $pdo->query("PRAGMA table_info(stats_old)")->fetchAll();
echo '  cols: ' . implode(', ', array_column($cols, 'name')) . "\n";
foreach ($pdo->query("SELECT * FROM stats_old LIMIT 3")->fetchAll() as $r) { echo '  ' . json_encode($r) . "\n"; }
$r = $pdo->query("SELECT MIN(date) a, MAX(date) b, COUNT(DISTINCT physical_ad_id) ads FROM stats_old")->fetch();
echo '  ' . json_encode($r) . "\n";
$overlap = $pdo->query("SELECT COUNT(*) FROM stats_old o JOIN statistics_2026_09 n ON n.physical_ad_id=o.physical_ad_id AND n.date=o.date")->fetchColumn();
echo "  rows also present in partition: {$overlap}\n";

echo "\n=== republish_candidates_2026_09 ===\n";
$cols = $pdo->query("PRAGMA table_info(republish_candidates_2026_09)")->fetchAll();
echo '  cols: ' . implode(', ', array_column($cols, 'name')) . "\n";
foreach ($pdo->query("SELECT * FROM republish_candidates_2026_09 LIMIT 3")->fetchAll() as $r) { echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }

echo "\n=== sample ad with stats ===\n";
$adId = $pdo->query("SELECT physical_ad_id FROM statistics_2026_09 GROUP BY physical_ad_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$ad = $pdo->query("SELECT id, avito_id, status, price, published_at FROM physical_ads WHERE id={$adId}")->fetch();
echo '  ad: ' . json_encode($ad, JSON_UNESCAPED_UNICODE) . "\n";
foreach ($pdo->query("SELECT date,views,uniq_views,contacts,uniq_contacts,favorites,uniq_favorites,phone_shows,chats,price FROM statistics_2026_09 WHERE physical_ad_id={$adId} ORDER BY date")->fetchAll() as $r) {
    echo '  ' . json_encode($r) . "\n";
}
