<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Перезаписывает уже опубликованный файл на Яндекс.Диске.
 * Публичная ссылка при этом не меняется: Авито продолжает забирать тот же URL.
 */
final class YandexDiskFeedUploader
{
    private ?YandexDiskGateway $gateway;

    /**
     * @param array<string, mixed> $config Полный конфиг кабинета или секция feed.
     */
    public function __construct(
        private readonly array $config,
        ?YandexDiskGateway $gateway = null,
        private readonly int $verifyAttempts = 5,
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->gateway = $gateway;
    }

    public function isConfigured(): bool
    {
        return $this->publicUrl() !== '' || $this->diskPath() !== '';
    }

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    public function publicUrl(): string
    {
        return trim((string) ($this->feed()['yandex_disk_public_url'] ?? ''));
    }

    /**
     * Боевой файл кабинета: avito_feed_YYYY-MM-DD.xml.
     * Файлы keep и inactive сюда не попадают.
     */
    public static function latestProductionFeed(string $directory): ?string
    {
        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'avito_feed_*.xml') ?: [];
        $production = [];
        foreach ($files as $file) {
            if (preg_match('/^avito_feed_\d{4}-\d{2}-\d{2}\.xml$/', basename($file)) === 1) {
                $production[] = $file;
            }
        }
        if ($production === []) {
            return null;
        }

        usort($production, static function (string $left, string $right): int {
            return filemtime($right) <=> filemtime($left);
        });

        return $production[0];
    }

    public static function publicId(string $url): string
    {
        if (preg_match('~/d/([A-Za-z0-9_-]+)~', $url, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    public static function assertPublicUrl(string $url): string
    {
        $url = trim($url);
        if (self::publicId($url) === '') {
            throw new \InvalidArgumentException(
                'Нужна публичная ссылка Яндекс.Диска, например https://disk.yandex.ru/d/...'
            );
        }

        return $url;
    }

    /**
     * @return array{path: string, public_url: string, name: string, size: int, md5: string}
     */
    public function upload(string $localFile): array
    {
        if (!is_file($localFile)) {
            throw new \RuntimeException("Нет файла фида: {$localFile}");
        }

        $token = $this->token();
        if ($token === '') {
            throw new \RuntimeException(
                'Публичная ссылка файл не перезаписывает. Впишите YANDEX_DISK_TOKEN владельца этого Диска.'
            );
        }

        $path = $this->resolveDiskPath($token);
        $upload = $this->gateway()->get('/v1/disk/resources/upload', [
            'path' => $path,
            'overwrite' => 'true',
        ], $token);
        $href = trim((string) ($upload['href'] ?? ''));
        if ($href === '') {
            throw new \RuntimeException('Яндекс.Диск не выдал адрес для записи файла.');
        }

        $this->gateway()->putFile($href, $localFile);

        $localMd5 = md5_file($localFile);
        $localSize = filesize($localFile);
        if ($localMd5 === false || $localSize === false) {
            throw new \RuntimeException("Не удалось прочитать {$localFile} после отправки.");
        }

        $remote = $this->waitUntilMatches($token, $path, $localMd5, $localSize);

        return [
            'path' => $path,
            'public_url' => $this->publicUrl() !== '' ? $this->publicUrl() : (string) ($remote['public_url'] ?? ''),
            'name' => (string) ($remote['name'] ?? basename(str_replace('\\', '/', $path))),
            'size' => (int) ($remote['size'] ?? $localSize),
            'md5' => (string) ($remote['md5'] ?? $localMd5),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function feed(): array
    {
        $feed = $this->config['feed'] ?? null;
        if (is_array($feed)) {
            return $feed;
        }

        return $this->config;
    }

    private function token(): string
    {
        return trim((string) ($this->feed()['yandex_disk_token'] ?? ''));
    }

    private function diskPath(): string
    {
        return trim((string) ($this->feed()['yandex_disk_path'] ?? ''));
    }

    private function gateway(): YandexDiskGateway
    {
        if ($this->gateway === null) {
            $timeout = (float) ($this->config['avito']['request_timeout'] ?? 120);
            $this->gateway = new YandexDiskHttp(max(5.0, $timeout));
        }

        return $this->gateway;
    }

    private function resolveDiskPath(string $token): string
    {
        $explicit = $this->diskPath();
        if ($explicit !== '') {
            return $explicit;
        }

        $publicUrl = $this->publicUrl();
        $id = self::publicId($publicUrl);
        if ($id === '') {
            throw new \RuntimeException('В YANDEX_DISK_PUBLIC_URL нет кода публикации Яндекс.Диска.');
        }

        $offset = 0;
        $limit = 100;
        do {
            $page = $this->gateway()->get('/v1/disk/resources/public', [
                'limit' => $limit,
                'offset' => $offset,
            ], $token);
            $items = $page['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }

            foreach ($items as $item) {
                if (!is_array($item) || ($item['type'] ?? '') !== 'file') {
                    continue;
                }
                $url = (string) ($item['public_url'] ?? '');
                if ($url === '' || self::publicId($url) !== $id) {
                    continue;
                }
                $path = trim((string) ($item['path'] ?? ''));
                if ($path !== '') {
                    return $path;
                }
            }

            $count = count($items);
            $offset += $limit;
        } while ($count === $limit && $offset < 5000);

        throw new \RuntimeException(
            'Среди опубликованных файлов этого Диска нет ' . $publicUrl
            . '. Токен должен быть от того же аккаунта. Если файл внутри общей папки, укажите YANDEX_DISK_PATH.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function waitUntilMatches(string $token, string $path, string $localMd5, int $localSize): array
    {
        $last = [];
        $attempts = max(1, $this->verifyAttempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->remoteMeta($token, $path);
            $remoteMd5 = strtolower(trim((string) ($last['md5'] ?? '')));
            $remoteSize = (int) ($last['size'] ?? -1);
            if ($remoteMd5 === strtolower($localMd5) && $remoteSize === $localSize) {
                return $last;
            }
            if ($attempt < $attempts) {
                $this->pause();
            }
        }

        $remoteMd5 = (string) ($last['md5'] ?? '');
        $remoteSize = (string) ($last['size'] ?? '');
        throw new \RuntimeException(
            "Файл отправлен, но на Диске другое содержимое (md5 {$remoteMd5}, размер {$remoteSize}; локально {$localMd5}, {$localSize})."
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteMeta(string $token, string $path): array
    {
        $publicUrl = $this->publicUrl();
        if ($publicUrl !== '') {
            return $this->gateway()->publicResource($publicUrl);
        }

        return $this->gateway()->get('/v1/disk/resources', ['path' => $path], $token);
    }

    private function pause(): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)();
            return;
        }

        sleep(2);
    }
}
