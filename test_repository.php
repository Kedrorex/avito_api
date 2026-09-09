<?php
/**
 * Тестирование ItemRepository без обращения к Avito API
 * Проверяет:
 * 1. Создание и обновление записей
 * 2. Сохранение статистики (UPSERT)
 * 3. Получение данных
 * 4. Граничные случаи
 */

declare(strict_types=1);

$rootDir = __DIR__;
require $rootDir . '/vendor/autoload.php';

use App\Repositories\ItemRepository;

// Создаём временную БД для тестов
$tempDb = __DIR__ . '/data/avito_test_temp.db';
if (file_exists($tempDb)) {
    unlink($tempDb);
}

$pdo = new PDO('sqlite:' . $tempDb);
$pdo->exec('PRAGMA foreign_keys = ON');
$repo = new ItemRepository($pdo);

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

echo str_repeat('=', 70) . "\n";
echo "  ItemRepository Unit Tests (no API)\n";
echo str_repeat('=', 70) . "\n";

// ===== ТЕСТ 1: Создание объявления =====
echo "\n--- Test 1: Create physical ad ---\n";
$id = $repo->createPhysical('avito:test123', [
    'title' => 'Тестовый товар',
    'number' => '8000000001',
    'price' => ['amount' => 5000, 'currency' => 'RUB'],
]);
assertTest($id > 0, "Created with ID > 0 (got {$id})");

$ad = $repo->getById($id);
assertTest($ad !== null, "Found by ID");
assertTest($ad['logical_key'] === 'avito:test123', "logical_key matches");

// ===== ТЕСТ 2: Upsert — новая запись =====
echo "\n--- Test 2: Upsert new item ---\n";
$isNew = $repo->upsertFromApiItem([
    'id' => '9000000001',
    'number' => '8000000002',
    'title' => 'Новый товар',
    'status' => 'active',
    'created_at' => '2026-09-01T10:00:00',
    'price' => ['amount' => 10000, 'currency' => 'RUB'],
    'category' => ['name' => 'Автозапчасти'],
]);
assertTest($isNew === true, "Returns true for new item");

$ad = $repo->getByAvitoId('9000000001');
assertTest($ad !== null, "Found by avito_id 9000000001");
assertTest($ad['status'] === 'active', "Status is 'active'");
assertTest($ad['published_at'] === '2026-09-01 10:00:00', "published_at formatted correctly");

// ===== ТЕСТ 3: Upsert — обновление существующей =====
echo "\n--- Test 3: Upsert existing item ---\n";
$isNew = $repo->upsertFromApiItem([
    'id' => '9000000001',
    'number' => '8000000002',
    'title' => 'Обновлённый товар',
    'status' => 'closed',
    'created_at' => '2026-09-01T10:00:00',
]);
assertTest($isNew === false, "Returns false for existing item (not created)");

$ad = $repo->getByAvitoId('9000000001');
assertTest($ad['status'] === 'closed', "Status updated to 'closed'");
// published_at должен остаться прежним
assertTest($ad['published_at'] === '2026-09-01 10:00:00', "published_at preserved on update");

// ===== ТЕСТ 4: Сохранение статистики =====
echo "\n--- Test 4: Save stats (UPSERT) ---\n";
$repo->saveStats($ad['id'], [
    ['date' => '2026-09-07', 'views' => 10, 'uniqViews' => 8, 'contacts' => 2, 'uniqContacts' => 2, 'favorites' => 1, 'uniqFavorites' => 1],
    ['date' => '2026-09-08', 'views' => 15, 'uniqViews' => 12, 'contacts' => 3, 'uniqContacts' => 3, 'favorites' => 2, 'uniqFavorites' => 2],
]);

$stats = $repo->getStats($ad['id']);
assertTest(count($stats) === 2, "2 stat records saved");
assertTest($stats[0]['date'] === '2026-09-07', "First date correct");
assertTest((int)$stats[0]['views'] === 10, "Views correct for first date");
assertTest((int)$stats[0]['uniq_views'] === 8, "Uniq views correct (mapped from uniqViews)");
assertTest((int)$stats[0]['contacts'] === 2, "Contacts correct");
assertTest((int)$stats[0]['uniq_contacts'] === 2, "Uniq contacts correct (mapped from uniqContacts)");
assertTest((int)$stats[0]['favorites'] === 1, "Favorites correct");
assertTest((int)$stats[0]['uniq_favorites'] === 1, "Uniq favorites correct (mapped from uniqFavorites)");

// ===== ТЕСТ 5: UPSERT — обновление существующей даты =====
echo "\n--- Test 5: UPSERT existing date ---\n";
$repo->saveStats($ad['id'], [
    ['date' => '2026-09-07', 'views' => 20, 'uniqViews' => 15, 'contacts' => 5, 'uniqContacts' => 4, 'favorites' => 3, 'uniqFavorites' => 3],
]);

$stats = $repo->getStats($ad['id']);
assertTest(count($stats) === 2, "Still 2 records (updated, not duplicated)");
$updated = array_values(array_filter($stats, fn($s) => $s['date'] === '2026-09-07'))[0];
assertTest((int)$updated['views'] === 20, "Views updated from 10 to 20");
assertTest((int)$updated['uniq_views'] === 15, "Uniq views updated from 8 to 15");
assertTest((int)$updated['contacts'] === 5, "Contacts updated from 2 to 5");

// ===== ТЕСТ 6: getActive =====
echo "\n--- Test 6: getActive filter ---\n";
// ad from Test 1 is active (default), ad from Test 3 is closed
$active = $repo->getActive();
$activeIds = array_column($active, 'id');
assertTest(in_array($id, $activeIds), "Test ad (id={$id}) is in active list");
assertTest(!in_array($ad['id'], $activeIds), "Closed ad (id={$ad['id']}) is NOT in active list");

// ===== ТЕСТ 7: getByLogicalKey =====
echo "\n--- Test 7: getByLogicalKey ---\n";
$byKey = $repo->getByLogicalKey('avito:test123');
assertTest(count($byKey) === 1, "Found 1 record by logical_key");
assertTest((int)$byKey[0]['id'] === $id, "Correct record by logical_key");

// ===== ТЕСТ 8: saveStats с пустым массивом =====
echo "\n--- Test 8: saveStats with empty array ---\n";
try {
    $repo->saveStats($id, []);
    assertTest(true, "Empty stats array doesn't throw");
} catch (\Throwable $e) {
    assertTest(false, "Empty stats array throws: " . $e->getMessage());
}

// ===== ТЕСТ 9: saveStats с нулевыми значениями =====
echo "\n--- Test 9: saveStats with zero values ---\n";
$repo->saveStats($id, [
    ['date' => '2026-09-09', 'views' => 0, 'uniqViews' => 0, 'contacts' => 0, 'uniqContacts' => 0, 'favorites' => 0, 'uniqFavorites' => 0],
]);
$stats = $repo->getStats($id);
$today = array_values(array_filter($stats, fn($s) => $s['date'] === '2026-09-09'));
assertTest(count($today) === 1, "Zero-value stats saved");
assertTest((int)$today[0]['views'] === 0, "Zero views stored correctly");

// ===== ТЕСТ 10: getStats for non-existent ad =====
echo "\n--- Test 10: getStats for non-existent ad ---\n";
$noStats = $repo->getStats(999999);
assertTest($noStats === [], "Empty array for non-existent ad");

// ===== ИТОГ =====
echo "\n" . str_repeat('=', 70) . "\n";
echo "  RESULTS: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 70) . "\n";

// Cleanup
if (file_exists($tempDb)) {
    unlink($tempDb);
}

exit($failed > 0 ? 1 : 0);
