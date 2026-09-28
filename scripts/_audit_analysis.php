<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/env_helper.php';
\Dotenv\Dotenv::createImmutable($root)->safeLoad();
$config = require $root . '/config/avito.php';

$pdo = new PDO($config['database']['dsn'], null, null, $config['database']['options']);
$repo = new \App\Repositories\ItemRepository($pdo);
$analysis = new \App\Services\AnalysisService($repo, $config);

// Pick an ad that genuinely has views in the window the rules look at
$dateTo = date('Y-m-d', strtotime('-1 day'));
$from10 = date('Y-m-d', strtotime('-10 days'));
echo "rule windows end at {$dateTo}; 10-day window starts {$from10}\n";

$rows = $pdo->query("SELECT physical_ad_id, SUM(uniq_views) uv, SUM(uniq_contacts) uc, COUNT(*) n
    FROM statistics_2026_09 WHERE date >= '{$from10}' AND date <= '{$dateTo}' AND physical_ad_id <> 999999
    GROUP BY physical_ad_id ORDER BY uv DESC LIMIT 5")->fetchAll();

echo "\n=== top ads by real uniq_views in the 10-day rule window ===\n";
foreach ($rows as $r) {
    $ad = $pdo->query("SELECT id, avito_id, status FROM physical_ads WHERE id={$r['physical_ad_id']}")->fetch();
    if ($ad === false) { echo "  ad {$r['physical_ad_id']} MISSING in physical_ads\n"; continue; }
    $a = $analysis->analyze($ad);
    echo sprintf(
        "  ad=%s avito=%s | DB real uniq_views=%s uniq_contacts=%s rows=%s\n"
        . "      AnalysisService -> total_views=%s total_contacts=%s total_favorites=%s stats_days=%s candidate=%s rules=[%s]\n",
        $ad['id'], $ad['avito_id'], $r['uv'], $r['uc'], $r['n'],
        $a['total_views'], $a['total_contacts'], $a['total_favorites'], $a['stats_days'],
        $a['is_candidate'] ? 'YES' : 'no', implode(',', $a['matched_rules'])
    );
}

// An ad with no stats at all
echo "\n=== ad with zero stats rows ===\n";
$ad = $pdo->query("SELECT p.id, p.avito_id, p.status FROM physical_ads p
    LEFT JOIN (SELECT DISTINCT physical_ad_id pid FROM statistics_2026_09) s ON s.pid = p.id
    WHERE s.pid IS NULL LIMIT 1")->fetch();
$a = $analysis->analyze($ad);
echo sprintf("  ad=%s avito=%s -> stats_days=%s candidate=%s rules=[%s]\n",
    $ad['id'], $ad['avito_id'], $a['stats_days'], $a['is_candidate'] ? 'YES' : 'no', implode(',', $a['matched_rules']));

// How many ads would be candidates, buggy vs. corrected reading of the columns
echo "\n=== candidate count: current code vs. reading uniq_* columns ===\n";
$total = (int) $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status='active'")->fetchColumn();
$buggy = $total; // views column is always 0 -> every active ad matches zero_contacts
$corrected = (int) $pdo->query("
    SELECT COUNT(*) FROM physical_ads p WHERE p.status='active' AND NOT EXISTS (
        SELECT 1 FROM statistics_2026_09 s
        WHERE s.physical_ad_id = p.id AND s.date >= '" . date('Y-m-d', strtotime('-5 days')) . "' AND s.date <= '{$dateTo}'
          AND (s.uniq_views > 0 OR s.uniq_contacts > 0 OR s.uniq_favorites > 0)
    )")->fetchColumn();
echo "  active ads:                                  {$total}\n";
echo "  candidates as current code computes them:    {$buggy}  (views/contacts/favorites always read 0)\n";
echo "  candidates if uniq_* were used (5-day rule): {$corrected}\n";
echo "  rows already stored in republish_candidates: " . $pdo->query("SELECT COUNT(*) FROM republish_candidates_2026_09")->fetchColumn() . "\n";
