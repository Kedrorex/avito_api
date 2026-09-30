<?php

/**
 * Два временных кабинета: свои базы, свои фиды, свои счётчики.
 * API Авито не вызывается.
 *
 * php scripts/test_account_isolation.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/env_helper.php';

use App\Accounts\AccountCli;
use App\Accounts\AccountCode;
use App\Accounts\AccountRegistry;
use App\Accounts\AccountRuntime;

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

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

$thrown = false;
try {
    AccountCode::assertValid('../evil');
} catch (\InvalidArgumentException) {
    $thrown = true;
}
check($thrown, 'код с .. отклоняется');

$thrown = false;
try {
    AccountCode::assertValid('add');
} catch (\InvalidArgumentException) {
    $thrown = true;
}
check($thrown, 'код add зарезервирован');

$projectConfig = require $root . '/config/avito.php';
$legacy = AccountRuntime::pathsFromConfig($projectConfig);
$legacyDb = str_replace('\\', '/', $legacy['db']);
$legacyFeed = str_replace('\\', '/', $legacy['feed']);
check(str_ends_with($legacyDb, 'data/avito.db'), 'корневой запуск смотрит в data/avito.db');
check(basename($legacyFeed) === 'fid', 'корневой фид остаётся в fid/');
$legacyAutoload = str_replace('\\', '/', $legacy['autoload']);
check(str_ends_with($legacyAutoload, 'fid/autoload'), 'корневая выгрузка Автозагрузки лежит в fid/autoload');
check(AccountCli::handles('feed') === false, 'feed без префикса не идёт в реестр');
check(AccountCli::handles('collect-stats') === false, 'collect-stats без префикса не идёт в реестр');
check(AccountCli::handles('account') === true && AccountCli::handles('accounts') === true, 'account и accounts идут в реестр');

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avito_accounts_' . uniqid();
mkdir($sandbox, 0755, true);

try {
    $registry = AccountRegistry::open($sandbox);
    $registry->add('account-a');
    $registry->add('account-b');

    $duplicate = false;
    try {
        $registry->add('account-a');
    } catch (\RuntimeException) {
        $duplicate = true;
    }
    check($duplicate, 'повторный код не создаёт второй контур');

    $env = static function (string $code, string $secret, string $userId) use ($sandbox): void {
        file_put_contents(
            $sandbox . "/config/accounts/{$code}.env",
            "AVITO_CLIENT_ID=test\nAVITO_CLIENT_SECRET={$secret}\nAVITO_USER_ID={$userId}\n"
        );
    };
    $env('account-a', 'secret-a', '101');
    $env('account-b', 'secret-b', '202');

    $contextA = $registry->loadContext('account-a');
    $beforeDsn = $projectConfig['database']['dsn'];
    $beforeFeed = $projectConfig['feed']['output_dir'];
    $overA = AccountRuntime::overlay($contextA, $projectConfig);
    check($projectConfig['database']['dsn'] === $beforeDsn, 'наложение не меняет DSN корневого конфига');
    check($projectConfig['feed']['output_dir'] === $beforeFeed, 'наложение не меняет каталог корневого фида');
    check(
        str_contains(str_replace('\\', '/', (string) $overA['feed']['autoload_download_dir']), 'fid/account-a/autoload'),
        'выгрузка кабинета A лежит в его каталоге'
    );
    check(
        str_contains(str_replace('\\', '/', $overA['database']['dsn']), 'data/accounts/account-a/avito.db'),
        'DSN кабинета A указывает на его файл'
    );
    check(
        str_contains(str_replace('\\', '/', (string) $overA['feed']['output_dir']), 'fid/account-a'),
        'фид кабинета A пишется в его каталог'
    );

    $withCloud = $projectConfig;
    $withCloud['feed']['yandex_disk_public_url'] = 'https://disk.yandex.ru/d/ROOTKEY';
    $withCloud['feed']['yandex_disk_token'] = 'root-token';
    $withCloud['feed']['yandex_disk_path'] = 'disk:/root.xml';
    $withCloud['feed']['yandex_disk_client_id'] = 'root-app';
    $withCloud['feed']['yandex_disk_client_secret'] = 'root-secret';
    $cleared = AccountRuntime::overlay($contextA, $withCloud);
    check(($cleared['feed']['yandex_disk_public_url'] ?? '') === '', 'чужая ссылка Диска не переходит в кабинет');
    check(($cleared['feed']['yandex_disk_token'] ?? '') === '', 'чужой токен Диска не переходит в кабинет');
    check(($cleared['feed']['yandex_disk_path'] ?? '') === '', 'чужой путь Диска не переходит в кабинет');
    check(($cleared['feed']['yandex_disk_client_id'] ?? '') === '', 'чужой Client ID Диска не переходит в кабинет');
    check(($cleared['feed']['yandex_disk_client_secret'] ?? '') === '', 'чужой секрет Диска не переходит в кабинет');
    check($cleared['feed']['env_file'] === $contextA->envFile, 'ссылка кабинета пишется в его env, не в корневой');

    file_put_contents(
        $sandbox . '/config/accounts/account-a.env',
        "AVITO_CLIENT_ID=test\nAVITO_CLIENT_SECRET=secret-a\nAVITO_USER_ID=101\n"
        . "YANDEX_DISK_TOKEN=token-a\nYANDEX_DISK_PUBLIC_URL=https://disk.yandex.ru/d/AAAA\nYANDEX_DISK_PATH=disk:/a.xml\n"
    );
    $contextCloud = $registry->loadContext('account-a');
    $ownCloud = AccountRuntime::overlay($contextCloud, $withCloud);
    check($ownCloud['feed']['yandex_disk_public_url'] === 'https://disk.yandex.ru/d/AAAA', 'кабинет берёт свою ссылку Диска');
    check($ownCloud['feed']['yandex_disk_token'] === 'token-a', 'кабинет берёт свой токен Диска');
    check($ownCloud['feed']['yandex_disk_path'] === 'disk:/a.xml', 'кабинет берёт свой путь на Диске');
    $contextA = $registry->loadContext('account-a');

    $today = date('Y-m-d');
    $metaKey = 'feed_repub_' . $today;

    $fill = static function (AccountRuntime $runtime, string $avitoId, string $uniqueId, string $title, string $counter): string {
        $runtime->repository()->upsertFromApiItem([
            'id' => $avitoId,
            'title' => $title,
            'status' => 'active',
            'price' => 1000,
            'description' => 'Описание ' . $title,
            'images' => [['url' => 'https://img/' . $uniqueId . '.jpg']],
        ]);
        $runtime->repository()->applyAutoloadIds([$avitoId => $uniqueId]);
        $runtime->repository()->setMeta('feed_repub_' . date('Y-m-d'), $counter);
        $result = (new App\Services\FeedGeneratorService(
            $runtime->repository(),
            $runtime->apiClient(),
            $runtime->config()
        ))->generate(false, true);

        return (string) ($result['file'] ?? '');
    };

    $runtimeA = AccountRuntime::open($contextA, $projectConfig);
    $fileA = $fill($runtimeA, '111', 'only-a', 'Товар A', '7');
    $runtimeA->close();

    $contextB = $registry->loadContext('account-b');
    $runtimeB = AccountRuntime::open($contextB, $projectConfig);
    $fileB = $fill($runtimeB, '222', 'only-b', 'Товар B', '2');
    $countB = $runtimeB->repository()->getByUniqueId('only-a');
    $seenB = $runtimeB->repository()->getByAvitoId('111');
    $runtimeB->close();
    $registry->close();

    check($countB === null && $seenB === null, 'объявление кабинета A не видно в базе B');
    check(is_file($fileA) && is_file($fileB), 'у каждого кабинета появился свой XML');

    $xmlA = (string) file_get_contents($fileA);
    $xmlB = (string) file_get_contents($fileB);
    $dirA = str_replace('\\', '/', dirname($fileA));
    $dirB = str_replace('\\', '/', dirname($fileB));
    check(str_contains($dirA, '/fid/account-a'), 'файл A лежит в fid/account-a');
    check(str_contains($dirB, '/fid/account-b'), 'файл B лежит в fid/account-b');
    check(!str_contains($dirA, '/fid/account-b'), 'файл A не попадает в каталог B');
    check(str_contains($xmlA, 'only-a') && !str_contains($xmlA, 'only-b'), 'фид A содержит только свой Id');
    check(str_contains($xmlB, 'only-b') && !str_contains($xmlA, 'only-b') && !str_contains($xmlB, 'only-a'), 'фид B содержит только свой Id');

    $readMeta = static function (string $dbPath, string $key): ?string {
        $pdo = new PDO('sqlite:' . $dbPath);
        $stmt = $pdo->prepare('SELECT value FROM app_meta WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        $pdo = null;

        return $value === false ? null : (string) $value;
    };
    $dbA = $sandbox . '/data/accounts/account-a/avito.db';
    $dbB = $sandbox . '/data/accounts/account-b/avito.db';
    check($readMeta($dbA, $metaKey) === '7', 'счётчик кабинета A остался 7');
    check($readMeta($dbB, $metaKey) === '2', 'счётчик кабинета B остался 2');

    $countIn = static function (string $dbPath, string $uniqueId): int {
        $pdo = new PDO('sqlite:' . $dbPath);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM physical_ads WHERE unique_id = :id');
        $stmt->execute([':id' => $uniqueId]);
        $count = (int) $stmt->fetchColumn();
        $pdo = null;

        return $count;
    };
    check($countIn($dbA, 'only-a') === 1 && $countIn($dbA, 'only-b') === 0, 'в базе A только объявление A');
    check($countIn($dbB, 'only-b') === 1 && $countIn($dbB, 'only-a') === 0, 'в базе B только объявление B');

    $control = (string) file_get_contents($sandbox . '/data/control.db');
    check(!str_contains($control, 'secret-a') && !str_contains($control, 'secret-b'), 'секреты не записаны в control.db');

    $guard = AccountRegistry::open($sandbox);
    $pdo = new PDO('sqlite:' . $sandbox . '/data/control.db');
    $pdo->prepare('UPDATE accounts SET db_path = :path WHERE code = :code')->execute([
        ':path' => $dbB,
        ':code' => 'account-a',
    ]);
    $pdo = null;
    $escaped = false;
    try {
        $guard->loadContext('account-a');
    } catch (\RuntimeException) {
        $escaped = true;
    }
    $guard->close();
    check($escaped, 'чужой файл базы не открывается из реестра');
} finally {
    rrmdir($sandbox);
}

if ($failures !== []) {
    echo "\nFAILED: " . count($failures) . "\n";
    exit(1);
}

echo "\nВсе проверки прошли\n";
exit(0);
