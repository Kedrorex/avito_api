<?php
/**
 * migrate_stats.php — Миграция данных из старой таблицы stats в секции statistics_YYYY_MM.
 *
 * Алгоритм:
 * 1. Прочитать все записи из stats
 * 2. Сгруппировать по месяцу (из date)
 * 3. Создать statistics_YYYY_MM для каждого месяца
 * 4. Вставить данные в соответствующие секции
 * 5. Обновить stats_meta
 * 6. После подтверждения — переименовать stats → stats_old
 *
 * Запуск:
 *   php migrate_stats.php [--dry-run] [--confirm]
 *   php migrate_stats.php              # dry-run (только показать что будет сделано)
 *   php migrate_stats.php --confirm    # выполнить миграцию
 */

declare(strict_types=1);

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';
require $rootDir . '/env_helper.php';

$config = require $rootDir . '/config/avito.php';

$pdo = new PDO(
    $config['database']['dsn'],
    null,
    null,
    $config['database']['options']
);
$pdo->exec('PRAGMA foreign_keys = ON');

$dryRun = in_array('--dry-run', $argv, true);
$confirm = in_array('--confirm', $argv, true);

function printStep(string $msg): void {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "  {$msg}\n";
    echo str_repeat('=', 80) . "\n";
}

function printInfo(string $msg): void { echo "  [INFO] {$msg}\n"; }
function printOk(string $msg): void { echo "  [OK]   {$msg}\n"; }
function printWarn(string $msg): void { echo "  [WARN] {$msg}\n"; }
function printFail(string $msg): void { echo "  [FAIL] {$msg}\n"; }

// ===== Проверка старой таблицы =====
printStep('CHECK: Old stats table');

$oldExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stats'"
)->fetchColumn();

if ($oldExists === 0) {
    printInfo("Old table 'stats' does not exist. Nothing to migrate.");
    exit(0);
}

$oldCount = (int) $pdo->query('SELECT COUNT(*) FROM stats')->fetchColumn();
printInfo("Old table 'stats' exists with {$oldCount} records");

// ===== Создание stats_meta =====
printStep('CREATE: stats_meta table');

$metaExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stats_meta'"
)->fetchColumn();

if ($metaExists === 0) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stats_meta (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            partition_name TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            record_count INTEGER DEFAULT 0
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stats_meta_partition ON stats_meta(partition_name)');
    printOk("Created stats_meta table");
} else {
    printInfo("stats_meta table already exists");
}

// ===== Группировка по месяцам =====
printStep('GROUP: Records by month');

$stmt = $pdo->query("
    SELECT 
        SUBSTR(date, 1, 7) as year_month,
        COUNT(*) as cnt,
        MIN(date) as first_date,
        MAX(date) as last_date
    FROM stats
    GROUP BY SUBSTR(date, 1, 7)
    ORDER BY year_month
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

printInfo("Found " . count($groups) . " month(s) to migrate");
foreach ($groups as $g) {
    echo "    {$g['year_month']}: {$g['cnt']} records ({$g['first_date']} — {$g['last_date']})\n";
}

// ===== Создание секций =====
printStep('CREATE: Partition tables');

$partitions = [];
foreach ($groups as $g) {
    $ym = $g['year_month'];
    $parts = explode('-', $ym);
    $year = $parts[0];
    $month = $parts[1];
    $partitionName = "statistics_{$year}_{$month}";
    $partitions[$partitionName] = $g['cnt'];

    if ($dryRun) {
        printInfo("[DRY-RUN] Would create table {$partitionName} with {$g['cnt']} records");
        continue;
    }

    // Создаём таблицу секции
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$partitionName} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            physical_ad_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            views INTEGER DEFAULT 0,
            uniq_views INTEGER DEFAULT 0,
            contacts INTEGER DEFAULT 0,
            uniq_contacts INTEGER DEFAULT 0,
            favorites INTEGER DEFAULT 0,
            uniq_favorites INTEGER DEFAULT 0,
            phone_shows INTEGER DEFAULT 0,
            chats INTEGER DEFAULT 0,
            price INTEGER DEFAULT 0,
            UNIQUE(physical_ad_id, date)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$partitionName}_partition ON {$partitionName}(physical_ad_id, date)");

    // Запись в stats_meta
    $pdo->prepare(
        "INSERT OR IGNORE INTO stats_meta (partition_name) VALUES (:name)"
    )->execute([':name' => $partitionName]);

    printOk("Created partition {$partitionName}");
}

// ===== Миграция данных =====
printStep('MIGRATE: Moving data to partitions');

$totalMigrated = 0;
$totalErrors = 0;

foreach ($groups as $g) {
    $ym = $g['year_month'];
    $parts = explode('-', $ym);
    $year = $parts[0];
    $month = $parts[1];
    $partitionName = "statistics_{$year}_{$month}";

    echo "\n  Migrating {$partitionName}... ";

    if ($dryRun) {
        printInfo("[DRY-RUN] Would migrate {$g['cnt']} records to {$partitionName}");
        $totalMigrated += $g['cnt'];
        continue;
    }

    // Читаем данные для этого месяца
    $stmt = $pdo->prepare("
        SELECT physical_ad_id, date, views, uniq_views, contacts, uniq_contacts, favorites, uniq_favorites
        FROM stats WHERE SUBSTR(date, 1, 7) = :ym
        ORDER BY date
    ");
    $stmt->execute([':ym' => $ym]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $migrated = 0;
    $errors = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $sql = "INSERT OR IGNORE INTO {$partitionName}
                (physical_ad_id, date, views, uniq_views, contacts, uniq_contacts, favorites, uniq_favorites)
                VALUES (:pad_id, :date, :views, :uniq_views, :contacts, :uniq_contacts, :favorites, :uniq_favorites)";

            try {
                $pdo->prepare($sql)->execute([
                    ':pad_id' => (int) $row['physical_ad_id'],
                    ':date' => $row['date'],
                    ':views' => (int) ($row['views'] ?? 0),
                    ':uniq_views' => (int) ($row['uniq_views'] ?? 0),
                    ':contacts' => (int) ($row['contacts'] ?? 0),
                    ':uniq_contacts' => (int) ($row['uniq_contacts'] ?? 0),
                    ':favorites' => (int) ($row['favorites'] ?? 0),
                    ':uniq_favorites' => (int) ($row['uniq_favorites'] ?? 0),
                ]);
                $migrated++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }
        $pdo->commit();

        // Обновляем record_count в stats_meta
        $pdo->prepare(
            "UPDATE stats_meta SET record_count = :cnt WHERE partition_name = :name"
        )->execute([':cnt' => $migrated, ':name' => $partitionName]);

        $totalMigrated += $migrated;
        $totalErrors += $errors;

        echo "OK ({$migrated} migrated, {$errors} errors)\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        printFail("Transaction failed: {$e->getMessage()}");
        $totalErrors += count($rows);
    }
}

// ===== Обновление stats_meta =====
printStep('UPDATE: stats_meta table');

if ($dryRun) {
    printInfo("[DRY-RUN] Would update stats_meta with partition counts");
} else {
    $stmt = $pdo->query("SELECT partition_name, record_count FROM stats_meta ORDER BY partition_name");
    echo "  Partitions registered:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "    {$row['partition_name']}: {$row['record_count']} records\n";
    }
    printOk("stats_meta updated");
}

// ===== Итог =====
printStep('RESULTS');

echo "\n  Total records to migrate: {$oldCount}\n";
echo "  Total migrated:           {$totalMigrated}\n";
echo "  Total errors:             {$totalErrors}\n";
echo "  Partitions created:       " . count($partitions) . "\n";

if ($dryRun) {
    echo "\n  This was a DRY RUN. No data was modified.\n";
    echo "  To execute migration, run: php migrate_stats.php --confirm\n";
} elseif (!$confirm) {
    echo "\n  Migration completed, but old table 'stats' is still intact.\n";
    echo "  Please verify the data and then run:\n";
    echo "    php migrate_stats.php --confirm\n";
    echo "  This will rename 'stats' to 'stats_old'.\n";
} else {
    // Переименовываем старую таблицу
    echo "\n  Renaming 'stats' to 'stats_old'...\n";
    try {
        $pdo->exec("ALTER TABLE stats RENAME TO stats_old");
        printOk("Old table renamed to 'stats_old'");
    } catch (\Throwable $e) {
        printFail("Failed to rename: {$e->getMessage()}");
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "  DONE\n";
echo str_repeat('=', 80) . "\n";
