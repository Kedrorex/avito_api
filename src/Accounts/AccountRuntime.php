<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Controllers\AvitoController;
use App\Repositories\ItemRepository;
use App\Services\AvitoAPIClient;
use App\Services\RepublisherService;
use PDO;

/**
 * Сборка одного прогона: свои ключи, свой PDO, свой каталог фида.
 * После close() соединение с файлом базы отпускается до следующего кабинета.
 */
final class AccountRuntime
{
    private ?PDO $pdo;
    private ?AvitoAPIClient $apiClient;
    private ?ItemRepository $repository;
    private ?RepublisherService $republisher;
    private ?AvitoController $controller;
    private bool $closed = false;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        private readonly array $config,
        PDO $pdo,
        AvitoAPIClient $apiClient,
        ItemRepository $repository,
        RepublisherService $republisher,
        AvitoController $controller,
    ) {
        $this->pdo = $pdo;
        $this->apiClient = $apiClient;
        $this->repository = $repository;
        $this->republisher = $republisher;
        $this->controller = $controller;
    }

    /**
     * @param array<string, mixed> $baseConfig
     */
    public static function open(AccountContext $context, array $baseConfig): self
    {
        if ($context->clientId === '' || $context->clientSecret === '' || $context->userId === '') {
            throw new \RuntimeException(
                'Заполните AVITO_CLIENT_ID, AVITO_CLIENT_SECRET и AVITO_USER_ID в ' . $context->envFile
            );
        }

        $config = self::overlay($context, $baseConfig);
        $dbDir = dirname($context->dbPath);
        if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
            throw new \RuntimeException("Не удалось создать {$dbDir}");
        }
        if (!is_dir($context->feedDir) && !mkdir($context->feedDir, 0755, true) && !is_dir($context->feedDir)) {
            throw new \RuntimeException("Не удалось создать {$context->feedDir}");
        }

        return self::connect($config);
    }

    /**
     * Текущий одноаккаунтный запуск: корневой .env, data/avito.db, fid/.
     *
     * @param array<string, mixed> $config
     */
    public static function openLegacy(array $config): self
    {
        return self::connect($config);
    }

    /**
     * Подмена ключей, файла базы, каталога фида и каталога скачанной выгрузки.
     *
     * @param array<string, mixed> $baseConfig
     * @return array<string, mixed>
     */
    public static function overlay(AccountContext $context, array $baseConfig): array
    {
        $config = $baseConfig;
        $config['avito']['client_id'] = $context->clientId;
        $config['avito']['client_secret'] = $context->clientSecret;
        $config['avito']['user_id'] = $context->userId;
        if ($context->maxDailyRepub !== null) {
            $config['avito']['max_daily_repub'] = $context->maxDailyRepub;
        }
        $config['database']['dsn'] = 'sqlite:' . $context->dbPath;
        $config['feed']['output_dir'] = $context->feedDir;
        $config['feed']['autoload_download_dir'] = $context->feedDir . DIRECTORY_SEPARATOR . 'autoload';
        $config['feed']['env_file'] = $context->envFile;
        $config['feed']['yandex_disk_token'] = $context->yandexDiskToken;
        $config['feed']['yandex_disk_public_url'] = $context->yandexDiskPublicUrl;
        $config['feed']['yandex_disk_path'] = $context->yandexDiskPath;
        $config['feed']['yandex_disk_client_id'] = $context->yandexDiskClientId;
        $config['feed']['yandex_disk_client_secret'] = $context->yandexDiskClientSecret;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{db: string, feed: string, autoload: string}
     */
    public static function pathsFromConfig(array $config): array
    {
        $dsn = (string) ($config['database']['dsn'] ?? '');
        $db = str_starts_with($dsn, 'sqlite:') ? substr($dsn, 7) : $dsn;
        $feed = (string) ($config['feed']['output_dir'] ?? '');
        $autoload = (string) ($config['feed']['autoload_download_dir'] ?? '');
        if ($autoload === '' && $feed !== '') {
            $autoload = $feed . DIRECTORY_SEPARATOR . 'autoload';
        }

        return [
            'db' => $db,
            'feed' => $feed,
            'autoload' => $autoload,
        ];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->controller = null;
        $this->republisher = null;
        $this->repository = null;
        $this->apiClient = null;
        if ($this->pdo !== null) {
            try {
                $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable) {
                // Соединение всё равно отпускаем: следующий кабинет не должен ждать этот файл.
            }
            $this->pdo = null;
        }
        $this->closed = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $this->assertOpen();

        return $this->config;
    }

    public function pdo(): PDO
    {
        $this->assertOpen();
        if ($this->pdo === null) {
            throw new \RuntimeException('Соединение с базой уже закрыто.');
        }

        return $this->pdo;
    }

    public function apiClient(): AvitoAPIClient
    {
        $this->assertOpen();
        if ($this->apiClient === null) {
            throw new \RuntimeException('Клиент API уже закрыт.');
        }

        return $this->apiClient;
    }

    public function repository(): ItemRepository
    {
        $this->assertOpen();
        if ($this->repository === null) {
            throw new \RuntimeException('Репозиторий уже закрыт.');
        }

        return $this->repository;
    }

    public function controller(): AvitoController
    {
        $this->assertOpen();
        if ($this->controller === null) {
            throw new \RuntimeException('Контроллер уже закрыт.');
        }

        return $this->controller;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connect(array $config): self
    {
        $pdo = new PDO(
            (string) $config['database']['dsn'],
            null,
            null,
            $config['database']['options'] ?? []
        );
        $apiClient = new AvitoAPIClient($config['avito']);
        $repository = new ItemRepository($pdo);
        $republisher = new RepublisherService($apiClient, $repository, $config['avito']);
        $controller = new AvitoController($apiClient, $repository, $republisher, $pdo, $config);

        return new self($config, $pdo, $apiClient, $repository, $republisher, $controller);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Прогон аккаунта уже закрыт.');
        }
    }
}
