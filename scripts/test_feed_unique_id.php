<?php

/**
 * Проверка: unique_id из Автозагрузки попадает в фид.
 *
 * php scripts/test_feed_unique_id.php
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

// Клиент нужен генератору по сигнатуре, но при сборке фида не вызывается.
$apiClient = new App\Services\AvitoAPIClient([
    'client_id' => 'test',
    'client_secret' => 'test',
    'user_id' => '1',
]);

$dbPath = sys_get_temp_dir() . '/avito_feed_unique_' . uniqid() . '.sqlite';
$outputDir = sys_get_temp_dir() . '/avito_feed_out_' . uniqid();
mkdir($outputDir, 0755, true);

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$repository = new App\Repositories\ItemRepository($pdo);

foreach ([['3001', 'Кандидат'], ['3002', 'Обычное'], ['3003', 'Без Id']] as [$avitoId, $title]) {
    $repository->upsertFromApiItem([
        'id' => $avitoId,
        'title' => $title,
        'status' => 'active',
        'price' => 5000,
    ]);
}

// Так же, как это делает sync-unique-ids.
$repository->applyAutoloadIds(['3001' => '07.09_1', '3002' => '07.09_2']);

$candidate = $repository->getByAvitoId('3001');
$repository->addCandidate((int) $candidate['id'], '3001', $candidate['logical_key']);

$config = [
    'feed' => ['output_dir' => $outputDir, 'default_views' => 'Package'],
];
$generator = new App\Services\FeedGeneratorService($repository, $apiClient, $config);
$result = $generator->generate(false);

check(($result['count'] ?? 0) === 3, "в фиде 3 строки (count=" . ($result['count'] ?? 0) . ")");

$raw = file_get_contents($result['file']);
check(str_starts_with($raw, "\xEF\xBB\xBF"), 'файл начинается с BOM, как эталон Авито');

$raw = substr($raw, 3);
$lines = array_filter(explode("\n", $raw), fn($l) => trim($l) !== '');
$rows = array_map('str_getcsv', $lines);
$header = array_shift($rows);
$rows = array_values($rows);
$uniqueCol = array_search('Уникальный идентификатор объявления', $header, true);
$avitoCol = array_search('Номер объявления на Авито', $header, true);
$statusCol = array_search('AvitoStatus', $header, true);

check($uniqueCol === 0, 'первая колонка — уникальный идентификатор');

$byAvitoId = [];
foreach ($rows as $row) {
    $byAvitoId[$row[$avitoCol]] = $row;
}

check($rows[0][$avitoCol] === '3001', 'кандидат идёт первой строкой');
check($rows[0][$statusCol] === 'removed', 'у кандидата AvitoStatus=removed');
check($rows[0][$uniqueCol] === '07.09_1', 'кандидат выгружен под Id из Автозагрузки, а не под номером Авито');
check(($byAvitoId['3002'][$uniqueCol] ?? '') === '07.09_2', 'обычное объявление выгружено под своим Id');
check(($byAvitoId['3002'][$statusCol] ?? '') === 'active', 'обычное объявление остаётся active');
check(($byAvitoId['3003'][$uniqueCol] ?? '') === '3003', 'без Id в фид подставляется номер Авито');

$candidateRows = array_filter($rows, fn($row) => $row[$avitoCol] === '3001');
check(count($candidateRows) === 1, 'кандидат встречается в файле один раз: снят, но обратно не включён (' . count($candidateRows) . ')');

array_map('unlink', glob($outputDir . '/*'));
rmdir($outputDir);
unlink($dbPath);

if ($failures !== []) {
    echo "\nПровалено: " . count($failures) . "\n";
    exit(1);
}

echo "\nВсе проверки прошли\n";
exit(0);
