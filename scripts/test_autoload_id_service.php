<?php

/**
 * Проверка AutoloadIdSyncService без обращения к Avito.
 *
 * php scripts/test_autoload_id_service.php
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

/** Подставной источник Id: отдаёт заготовленные ответы и запоминает запросы. */
final class FakeAutoloadIdProvider implements App\Services\AutoloadIdProvider
{
    /** @var list<list<int|string>> */
    public array $calls = [];

    /** @param array<string, ?string> $adIds avito_id => ad_id */
    public function __construct(private array $adIds, private ?\Throwable $error = null)
    {
    }

    public function getAdIdsByAvitoIds(array $avitoIds): array
    {
        $this->calls[] = $avitoIds;

        if ($this->error !== null) {
            throw $this->error;
        }

        $items = [];
        foreach ($avitoIds as $avitoId) {
            $avitoId = (string) $avitoId;
            if (array_key_exists($avitoId, $this->adIds)) {
                $items[] = ['avito_id' => $avitoId, 'ad_id' => $this->adIds[$avitoId]];
            }
        }

        return $items;
    }
}

function makeRepository(int $adCount): array
{
    $dbPath = sys_get_temp_dir() . '/avito_autoload_service_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $repository = new App\Repositories\ItemRepository($pdo);

    for ($i = 1; $i <= $adCount; $i++) {
        $repository->upsertFromApiItem([
            'id' => (string) (2000 + $i),
            'title' => "Объявление {$i}",
            'status' => 'active',
        ]);
    }

    return [$repository, $dbPath];
}

$config = ['autoload_id_batch_size' => 2, 'autoload_request_delay_seconds' => 0];

echo "\n--- Пакеты и запись ---\n";
[$repository, $dbPath] = makeRepository(5);
$provider = new FakeAutoloadIdProvider([
    '2001' => '07.09_1',
    '2002' => '07.09_2',
    '2003' => null,
    '2004' => '07.09_4',
    '2005' => '07.09_5',
]);
$service = new App\Services\AutoloadIdSyncService($provider, $repository, $config);
$result = $service->sync(false);

check($result['requested'] === 5, "в очередь попали 5 объявлений (requested={$result['requested']})");
check(count($provider->calls) === 3, 'пять номеров разбиты на 3 пакета по 2 (' . count($provider->calls) . ')');
check(array_map('count', $provider->calls) === [2, 2, 1], 'размеры пакетов 2,2,1');
check($result['updated'] === 4, "записаны 4 идентификатора (updated={$result['updated']})");
check($result['missing'] === 1, "объявление без ad_id посчитано отдельно (missing={$result['missing']})");
check($result['error'] === '', 'ошибок нет');
check(($repository->getByAvitoId('2001')['unique_id'] ?? '') === '07.09_1', 'первый Id записан в БД');
check(($repository->getByAvitoId('2003')['unique_id'] ?? null) === '', 'объявление без ad_id осталось с пустым unique_id');
unlink($dbPath);

echo "\n--- Повторный запуск ---\n";
[$repository, $dbPath] = makeRepository(2);
$provider = new FakeAutoloadIdProvider(['2001' => '07.09_1', '2002' => '07.09_2']);
$service = new App\Services\AutoloadIdSyncService($provider, $repository, $config);
$service->sync(false);
$second = $service->sync(false);

check($second['requested'] === 0, "второй прогон ничего не запрашивает (requested={$second['requested']})");
check(count($provider->calls) === 1, 'повторных обращений к API нет (' . count($provider->calls) . ')');

$full = $service->sync(true);
check($full['requested'] === 2, "флаг --all сверяет уже заполненные (requested={$full['requested']})");
check($full['updated'] === 0, 'сверка не переписывает совпадающие Id');
unlink($dbPath);

echo "\n--- Нет доступа к Автозагрузке ---\n";
[$repository, $dbPath] = makeRepository(3);
$provider = new FakeAutoloadIdProvider([], new RuntimeException('Avito API returned HTTP 403: forbidden'));
$service = new App\Services\AutoloadIdSyncService($provider, $repository, $config);
$failed = $service->sync(false);

check(str_contains($failed['error'], 'autoload'), 'подсказка про право autoload в кабинете');
check($failed['updated'] === 0, 'при ошибке ничего не записано');
check(count($provider->calls) === 1, 'после ошибки остальные пакеты не отправляются');
check(($repository->getByAvitoId('2001')['unique_id'] ?? null) === '', 'данные в БД не испорчены');
unlink($dbPath);

if ($failures !== []) {
    echo "\nПровалено: " . count($failures) . "\n";
    exit(1);
}

echo "\nВсе проверки прошли\n";
exit(0);
