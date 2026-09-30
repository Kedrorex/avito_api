<?php

/**
 * Выбор боевого фида и перезапись опубликованного файла.
 * Сеть не вызывается.
 *
 * php scripts/test_yandex_disk_upload.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Services\YandexDiskAuth;
use App\Services\YandexDiskFeedUploader;
use App\Services\YandexDiskGateway;
use App\Support\EnvFile;

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

final class FakeYandexDisk implements YandexDiskGateway
{
    /** @var list<array{path: string, query: array<string, scalar|null>, token: string}> */
    public array $gets = [];

    public ?string $putBody = null;

    public string $remoteMd5 = '';

    public int $remoteSize = 0;

    public function get(string $path, array $query, string $token): array
    {
        $this->gets[] = ['path' => $path, 'query' => $query, 'token' => $token];
        if ($path === '/v1/disk/resources/public') {
            return [
                'items' => [
                    [
                        'type' => 'file',
                        'name' => 'other.xml',
                        'path' => 'disk:/other.xml',
                        'public_url' => 'https://yadi.sk/d/OTHER',
                    ],
                    [
                        'type' => 'file',
                        'name' => 'avito_feed_2026-09-29.xml',
                        'path' => 'disk:/Avito/avito_feed_2026-09-29.xml',
                        'public_url' => 'https://yadi.sk/d/G_d795C343jlXQ',
                    ],
                ],
            ];
        }
        if ($path === '/v1/disk/resources/upload') {
            return ['href' => 'https://uploader.example/put', 'method' => 'PUT'];
        }

        return [];
    }

    public function putFile(string $url, string $localFile): void
    {
        if ($url !== 'https://uploader.example/put') {
            throw new RuntimeException('не тот адрес записи');
        }
        $body = file_get_contents($localFile);
        if ($body === false) {
            throw new RuntimeException('пусто');
        }
        $this->putBody = $body;
        $this->remoteMd5 = md5($body);
        $this->remoteSize = strlen($body);
    }

    public function publicResource(string $publicUrl): array
    {
        return [
            'name' => 'avito_feed_2026-09-29.xml',
            'size' => $this->remoteSize,
            'md5' => $this->remoteMd5,
            'public_url' => $publicUrl,
        ];
    }
}

check(
    YandexDiskFeedUploader::publicId('https://disk.yandex.ru/d/G_d795C343jlXQ')
        === YandexDiskFeedUploader::publicId('https://yadi.sk/d/G_d795C343jlXQ'),
    'короткая и полная ссылка указывают на один документ'
);

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avito_feed_pick_' . uniqid();
mkdir($dir);
file_put_contents($dir . '/avito_feed_keep_2026-09-30.xml', '<Ads></Ads>');
file_put_contents($dir . '/avito_feed_inactive_2026-09-30.xml', '<Ads></Ads>');
file_put_contents($dir . '/avito_feed_2026-09-29.xml', '<Ads>old</Ads>');
sleep(1);
file_put_contents($dir . '/avito_feed_2026-09-30.xml', '<Ads>new</Ads>');
$picked = YandexDiskFeedUploader::latestProductionFeed($dir);
check($picked !== null && basename($picked) === 'avito_feed_2026-09-30.xml', 'в облако берётся последний боевой файл');

$source = $dir . '/avito_feed_2026-09-30.xml';
$fake = new FakeYandexDisk();
$uploader = new YandexDiskFeedUploader(
    [
        'feed' => [
            'yandex_disk_token' => 'token',
            'yandex_disk_public_url' => 'https://disk.yandex.ru/d/G_d795C343jlXQ',
            'yandex_disk_path' => '',
        ],
    ],
    $fake,
    1,
    static function (): void {
    }
);
$result = $uploader->upload($source);

check($result['path'] === 'disk:/Avito/avito_feed_2026-09-29.xml', 'перезаписывается опубликованный документ, а не соседний файл');
check(($fake->gets[1]['query']['overwrite'] ?? '') === 'true', 'запись идёт с перезаписью');
check($fake->putBody === '<Ads>new</Ads>', 'на Диск уходит содержимое локального фида');
check($result['name'] === 'avito_feed_2026-09-29.xml', 'имя на Диске остаётся именем опубликованного файла');
check($result['public_url'] === 'https://disk.yandex.ru/d/G_d795C343jlXQ', 'публичная ссылка не подменяется');

$envPath = $dir . '/cabinet.env';
file_put_contents($envPath, "AVITO_CLIENT_ID=keep\r\nYANDEX_DISK_PUBLIC_URL=https://disk.yandex.ru/d/OLD\r\nYANDEX_DISK_TOKEN=secret\r\n");
EnvFile::set($envPath, 'YANDEX_DISK_PUBLIC_URL', 'https://disk.yandex.ru/d/NEWKEY');
$saved = (string) file_get_contents($envPath);
check(str_contains($saved, 'YANDEX_DISK_PUBLIC_URL=https://disk.yandex.ru/d/NEWKEY'), 'новая ссылка записана в env кабинета');
check(str_contains($saved, 'AVITO_CLIENT_ID=keep') && str_contains($saved, 'YANDEX_DISK_TOKEN=secret'), 'остальные строки env не затронуты');
check(YandexDiskFeedUploader::publicId('https://disk.yandex.ru/d/NEWKEY') === 'NEWKEY', 'загрузка возьмёт код из новой ссылки');

$rejected = false;
try {
    YandexDiskFeedUploader::assertPublicUrl('https://example.com/feed.xml');
} catch (\InvalidArgumentException) {
    $rejected = true;
}
check($rejected, 'адрес не с Яндекс.Диска не сохраняется');

$authUrl = YandexDiskAuth::authorizeUrl('app-id');
check(str_contains($authUrl, 'client_id=app-id'), 'ссылка входа содержит Client ID приложения');
check(!str_contains($authUrl, 'secret'), 'секрет приложения в ссылку входа не попадает');

foreach (glob($dir . '/*') ?: [] as $file) {
    unlink($file);
}
rmdir($dir);

if ($failures !== []) {
    echo "\n  FAILED: " . count($failures) . "\n";
    exit(1);
}

echo "\n  All checks passed\n";
exit(0);
