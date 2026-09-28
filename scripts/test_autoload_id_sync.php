<?php

/**
 * Локальная проверка синхронизации unique_id без обращения к Avito.
 *
 * php scripts/test_autoload_id_sync.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$failures = [];

function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "  OK  {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "  FAIL {$message}\n";
}

$dbPath = sys_get_temp_dir() . '/avito_autoload_id_test.sqlite';
if (is_file($dbPath)) {
    unlink($dbPath);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$repository = new App\Repositories\ItemRepository($pdo);

$repository->upsertFromApiItem([
    'id' => '1001',
    'title' => 'С фидовым id',
    'status' => 'active',
    'uniqueId' => '07.09_42',
    'price' => 1000,
]);
$repository->upsertFromApiItem([
    'id' => '1002',
    'title' => 'Пустой id',
    'status' => 'active',
]);
$repository->upsertFromApiItem([
    'id' => '1003',
    'title' => 'Id равен номеру',
    'status' => 'active',
    'uniqueId' => '1003',
]);
$repository->upsertFromApiItem([
    'id' => '1004',
    'title' => 'Снято',
    'status' => 'removed',
]);

$repository->upsertFromApiItem([
    'id' => '1001',
    'title' => 'С фидовым id',
    'status' => 'active',
    'price' => 1000,
]);

$kept = $repository->getByAvitoId('1001');
check(($kept['unique_id'] ?? '') === '07.09_42', 'повторная синхронизация не стирает unique_id');
check(($kept['title'] ?? '') === 'С фидовым id', 'повторная синхронизация не стирает название');

$missing = $repository->listAvitoIdsForUniqueSync(false);
sort($missing);
check($missing === ['1002', '1003'], 'в запрос попадают только пустой Id и Id, равный номеру: ' . implode(',', $missing));

$all = $repository->listAvitoIdsForUniqueSync(true);
sort($all);
check($all === ['1001', '1002', '1003'], 'полный обход берёт active и не берёт removed: ' . implode(',', $all));

$updated = $repository->applyAutoloadIds([
    '1002' => '07.09_2',
    '1003' => '07.09_3',
    '1001' => '07.09_42',
]);
check($updated === 2, "обновлены две строки, уже верный Id не перезаписан (updated={$updated})");

$empty = $repository->getByAvitoId('1002');
$same = $repository->getByAvitoId('1003');
check(($empty['unique_id'] ?? '') === '07.09_2', 'пустому объявлению записан ad_id');
check(($same['unique_id'] ?? '') === '07.09_3', 'номеру Авито записан ad_id из автозагрузки');

$missingAfter = $repository->listAvitoIdsForUniqueSync(false);
check($missingAfter === [], 'после записи очередь пустая: ' . implode(',', $missingAfter));

$unchanged = $repository->applyAutoloadIds(['1002' => '07.09_2']);
check($unchanged === 0, 'повторная запись того же ad_id ничего не меняет');

unlink($dbPath);

if ($failures !== []) {
    echo "\nПровалено: " . count($failures) . "\n";
    exit(1);
}

echo "\nВсе проверки прошли\n";
exit(0);
