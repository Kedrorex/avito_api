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

$variation = new App\Services\AdContentVariation();
$varied = $variation->vary(
    'Насос ГУР BMW',
    "Первое предложение. Второе остаётся.",
    ['https://img/1.jpg', 'https://img/2.jpg', 'https://img/3.jpg']
);
check($varied['title'] === 'ГУР Насос BMW', 'в заголовке меняются местами первые два слова');
check($varied['description'] === 'Второе остаётся. Первое предложение.', 'первое предложение переносится в конец');
check($varied['images'] === ['https://img/2.jpg', 'https://img/3.jpg', 'https://img/1.jpg'], 'первое фото уходит в конец');

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
$candidateId = (int) $candidate['id'];
$repository->addCandidate($candidateId, '3001', $candidate['logical_key']);
$statDate = date('Y-m-d');
$pdo->prepare(
    'INSERT INTO stats (physical_ad_id, date, views, uniq_views, contacts, uniq_contacts, favorites, uniq_favorites)
     VALUES (:id, :date, 4, 0, 0, 0, 0, 0)'
)->execute([':id' => $candidateId, ':date' => $statDate]);

$config = [
    'feed' => ['output_dir' => $outputDir, 'default_views' => 'Package'],
];
$generator = new App\Services\FeedGeneratorService($repository, $apiClient, $config);
$result = $generator->generate(false);

check(($result['count'] ?? 0) === 3, "в фиде 3 объявления (count=" . ($result['count'] ?? 0) . ")");

$raw = (string) file_get_contents($result['file']);
$xml = simplexml_load_string($raw);
check($xml !== false, 'фид — XML');

$ids = [];
$avitoIds = [];
$statuses = [];
if ($xml !== false) {
    foreach ($xml->Ad as $ad) {
        $id = (string) $ad->Id;
        $ids[] = $id;
        $avitoIds[$id] = (string) $ad->AvitoId;
        $statuses[$id] = (string) $ad->AvitoStatus;
    }
}

check(!in_array('07.09_1', $ids, true), 'старый Id кандидата в файле отсутствует');
check(in_array('07.09_1-v2', $ids, true), 'кандидат выгружен новым Id');
check(($avitoIds['07.09_1-v2'] ?? 'missing') === '', 'у нового объявления нет AvitoId');
check(($statuses['07.09_1-v2'] ?? '') === 'active', 'новое объявление не помечается removed');
check(($avitoIds['07.09_2'] ?? '') === '3002', 'обычное объявление остаётся под своим Id и номером Авито');
check(($statuses['07.09_2'] ?? '') === 'active', 'обычное объявление остаётся active');
check(in_array('3003', $ids, true), 'без Id в фид подставляется номер Авито');
check(!in_array('3001', $avitoIds, true), 'старый номер Авито кандидата в файл не возвращается');

$archived = [];
foreach ($repository->getDeletedAds() as $row) {
    if ((string) ($row['avito_id'] ?? '') === '3001') {
        $archived = $row;
        break;
    }
}
$fresh = $repository->getByUniqueId('07.09_1-v2');
check($repository->getByAvitoId('3001') === null, 'старое поколение ушло из каталога');
check($archived !== [] && ($archived['status'] ?? '') === 'deactivated', 'старое поколение сохранено в архиве');
check(($archived['unique_id'] ?? '') === '07.09_1', 'в архиве сохранён прежний Id');
check(($archived['logical_key'] ?? '') === 'avito:3001', 'в архиве сохранён logical_key');
check((int) ($archived['physical_ad_id'] ?? 0) > 0, 'в архиве сохранён исходный id');
check(($archived['title'] ?? '') === 'Кандидат', 'в архиве сохранён заголовок');
check((int) ($archived['price'] ?? 0) === 5000, 'в архиве сохранена цена');
check(is_string($archived['master_data'] ?? null) && $archived['master_data'] !== '', 'в архиве сохранён master_data');
check((int) ($archived['physical_ad_id'] ?? 0) === $candidateId, 'архив ссылается на прежний id каталога');
$partition = $repository->getPartitionName($statDate);
$movedViews = (int) $pdo->query(
    "SELECT views FROM {$partition} WHERE physical_ad_id = {$candidateId}"
)->fetchColumn();
$legacyLeft = (int) $pdo->query(
    "SELECT COUNT(*) FROM stats WHERE physical_ad_id = {$candidateId}"
)->fetchColumn();
check($movedViews === 4, 'дневная статистика перенесена в секцию месяца');
check($legacyLeft === 0, 'старая stats больше не держит внешний ключ');
check($repository->uniqueIdExists('07.09_1'), 'архивный Id считается занятым');
$kept = $repository->upsertFromApiItem([
    'id' => '3001',
    'title' => 'Кандидат',
    'status' => 'active',
    'price' => 5000,
]);
check($kept === 'restored', 'активный номер кабинета возвращается в каталог');
$liveAgain = $repository->getByAvitoId('3001');
check($liveAgain !== null && ($liveAgain['status'] ?? '') === 'active', 'каталог снова содержит активный номер');
check((int) ($liveAgain['id'] ?? 0) === $candidateId, 'статистика остаётся на прежнем id');
check($repository->getByUniqueId('07.09_1-v2') === null, 'неопубликованное поколение уходит из каталога');
check($repository->getDailyRepubCount($statDate) === 1, 'дневной счётчик не суммирует фид и новое поколение');
check($fresh !== null && ($fresh['status'] ?? '') === 'active', 'новое поколение было записано до сверки с кабинетом');
check(($fresh['title'] ?? '') === 'Кандидат в наличии', 'однословный заголовок дополнен');

$plain = $repository->getByAvitoId('3002');
$repository->addCandidate((int) $plain['id'], '3002', (string) $plain['logical_key']);
$preview = $generator->generate(false, true);
$previewXml = simplexml_load_string((string) file_get_contents($preview['file']));
$previewIds = [];
if ($previewXml !== false) {
    foreach ($previewXml->Ad as $ad) {
        $previewIds[] = (string) $ad->Id;
    }
}
$plainAfter = $repository->getByAvitoId('3002');
check(in_array('07.09_2-v2', $previewIds, true) && !in_array('07.09_2', $previewIds, true), 'тестовый фид показывает новый Id и прячет старый');
check($plainAfter !== null && ($plainAfter['status'] ?? '') === 'active' && ($plainAfter['unique_id'] ?? '') === '07.09_2', 'тестовый фид не меняет строку в базе');
check($repository->getByUniqueId('07.09_2-v2') === null, 'тестовый фид не создаёт новое поколение');

$pdo->prepare(
    "INSERT INTO physical_ads (logical_key, avito_id, unique_id, status, title, price, master_data)
     VALUES ('avito:9001', '9001', 'gone-id', 'deactivated', 'Мусор', 10, '{}')"
)->execute();
$reopened = new App\Repositories\ItemRepository($pdo);
check($reopened->getByAvitoId('9001') === null, 'открытие базы вычищает deactivated');
$swept = [];
foreach ($reopened->getDeletedAds() as $row) {
    if ((string) ($row['avito_id'] ?? '') === '9001') {
        $swept = $row;
        break;
    }
}
check(($swept['title'] ?? '') === 'Мусор' && ($swept['unique_id'] ?? '') === 'gone-id', 'вычищенная строка сохранена в архиве целиком');

unset($previewXml, $generator, $repository, $reopened, $pdo);
array_map('unlink', glob($outputDir . '/*') ?: []);
rmdir($outputDir);
unlink($dbPath);

if ($failures !== []) {
    echo "\nПровалено: " . count($failures) . "\n";
    exit(1);
}

echo "\nВсе проверки прошли\n";
exit(0);
