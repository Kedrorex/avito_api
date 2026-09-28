<?php
$p = new PDO('sqlite:' . dirname(__DIR__) . '/data/avito.db');
echo "id=999999 present in physical_ads: " . $p->query('SELECT COUNT(*) FROM physical_ads WHERE id=999999')->fetchColumn() . "\n";
echo "stat rows owned by 999999:         " . $p->query('SELECT COUNT(*) FROM statistics_2026_09 WHERE physical_ad_id=999999')->fetchColumn() . "\n";
echo "orphan stat rows (no parent ad):   " . $p->query('SELECT COUNT(*) FROM statistics_2026_09 s LEFT JOIN physical_ads p ON p.id=s.physical_ad_id WHERE p.id IS NULL')->fetchColumn() . "\n";
