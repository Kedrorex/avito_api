<?php

declare(strict_types=1);

namespace App\Accounts;

use PDO;

/**
 * Реестр кабинетов в data/control.db.
 * В таблице только пути и подпись. Ключи API остаются в config/accounts/{code}.env.
 */
final class AccountRegistry
{
    private ?PDO $pdo;

    private function __construct(
        PDO $pdo,
        private readonly string $root,
    ) {
        $this->pdo = $pdo;
    }

    public static function open(string $projectRoot): self
    {
        $root = realpath($projectRoot);
        if ($root === false) {
            throw new \RuntimeException("Нет каталога проекта: {$projectRoot}");
        }

        $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            throw new \RuntimeException("Не удалось создать {$dataDir}");
        }

        $pdo = new PDO(
            'sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'control.db',
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec('PRAGMA busy_timeout = 10000');

        $registry = new self($pdo, $root);
        $registry->migrate();

        return $registry;
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Создать каталоги и строку реестра. Файл базы появится при первом открытии.
     *
     * @return array<string, mixed>
     */
    public function add(string $code, string $label = ''): array
    {
        $code = AccountCode::assertValid($code);
        if ($this->find($code) !== null) {
            throw new \RuntimeException("Аккаунт {$code} уже есть в реестре.");
        }

        $dbDir = $this->root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . $code;
        $feedDir = $this->root . DIRECTORY_SEPARATOR . 'fid' . DIRECTORY_SEPARATOR . $code;
        $envDir = $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'accounts';
        $this->mkdir($dbDir);
        $this->mkdir($feedDir);
        $this->mkdir($envDir);

        $dbPath = $dbDir . DIRECTORY_SEPARATOR . 'avito.db';
        $envFile = $envDir . DIRECTORY_SEPARATOR . $code . '.env';
        $this->assertInside($dbPath, $dbDir);
        $this->assertInside($feedDir, $this->root . DIRECTORY_SEPARATOR . 'fid' . DIRECTORY_SEPARATOR . $code);
        $this->assertEnvFile($code, $envFile);

        if (!is_file($envFile)) {
            $written = file_put_contents(
                $envFile,
                "AVITO_CLIENT_ID=\nAVITO_CLIENT_SECRET=\nAVITO_USER_ID=\nYANDEX_DISK_CLIENT_ID=\nYANDEX_DISK_CLIENT_SECRET=\nYANDEX_DISK_TOKEN=\nYANDEX_DISK_PUBLIC_URL=\nYANDEX_DISK_PATH=\n"
            );
            if ($written === false) {
                throw new \RuntimeException("Не удалось записать {$envFile}");
            }
        }

        $this->pdo()->prepare(
            'INSERT INTO accounts (code, label, user_id, db_path, feed_dir, env_file, enabled, max_daily_repub, created_at)
             VALUES (:code, :label, :user_id, :db_path, :feed_dir, :env_file, 1, NULL, :created_at)'
        )->execute([
            ':code' => $code,
            ':label' => $label,
            ':user_id' => '',
            ':db_path' => $dbPath,
            ':feed_dir' => $feedDir,
            ':env_file' => $envFile,
            ':created_at' => date('c'),
        ]);

        $row = $this->find($code);
        if ($row === null) {
            throw new \RuntimeException("Аккаунт {$code} не записался в реестр.");
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $rows = $this->pdo()->query('SELECT * FROM accounts ORDER BY code')->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->refreshUserId($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabled(): array
    {
        $enabled = [];
        foreach ($this->list() as $row) {
            if ((int) $row['enabled'] === 1) {
                $enabled[] = $row;
            }
        }

        return $enabled;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        $code = AccountCode::assertValid($code);
        $stmt = $this->pdo()->prepare('SELECT * FROM accounts WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function loadContext(string $code): AccountContext
    {
        $code = AccountCode::assertValid($code);
        $row = $this->find($code);
        if ($row === null) {
            throw new \RuntimeException("Аккаунт {$code} не найден. Добавьте его: php index.php account add {$code}");
        }

        $row = $this->refreshUserId($row);
        $this->assertAccountPaths($code, (string) $row['db_path'], (string) $row['feed_dir'], (string) $row['env_file']);

        $env = $this->readEnvFile((string) $row['env_file']);
        $max = $row['max_daily_repub'];

        return new AccountContext(
            code: $code,
            label: (string) ($row['label'] ?? ''),
            userId: trim((string) ($env['AVITO_USER_ID'] ?? '')),
            clientId: trim((string) ($env['AVITO_CLIENT_ID'] ?? '')),
            clientSecret: trim((string) ($env['AVITO_CLIENT_SECRET'] ?? '')),
            dbPath: (string) $row['db_path'],
            feedDir: (string) $row['feed_dir'],
            envFile: (string) $row['env_file'],
            enabled: (int) $row['enabled'] === 1,
            maxDailyRepub: $max === null || $max === '' ? null : (int) $max,
            yandexDiskToken: trim((string) ($env['YANDEX_DISK_TOKEN'] ?? '')),
            yandexDiskPublicUrl: trim((string) ($env['YANDEX_DISK_PUBLIC_URL'] ?? '')),
            yandexDiskPath: trim((string) ($env['YANDEX_DISK_PATH'] ?? '')),
            yandexDiskClientId: trim((string) ($env['YANDEX_DISK_CLIENT_ID'] ?? '')),
            yandexDiskClientSecret: trim((string) ($env['YANDEX_DISK_CLIENT_SECRET'] ?? '')),
        );
    }

    public function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $normalized = str_replace('\\', '/', $path);
        $prefix = strtolower($root) . '/';
        if (str_starts_with(strtolower($normalized), $prefix)) {
            return substr($normalized, strlen($prefix));
        }

        return $normalized;
    }

    private function migrate(): void
    {
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS accounts (
                code TEXT PRIMARY KEY,
                label TEXT NOT NULL DEFAULT \'\',
                user_id TEXT NOT NULL DEFAULT \'\',
                db_path TEXT NOT NULL,
                feed_dir TEXT NOT NULL,
                env_file TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                max_daily_repub INTEGER,
                created_at TEXT NOT NULL
            )'
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function refreshUserId(array $row): array
    {
        $envFile = (string) ($row['env_file'] ?? '');
        if ($envFile === '' || !is_file($envFile)) {
            return $row;
        }

        $userId = trim((string) ($this->readEnvFile($envFile)['AVITO_USER_ID'] ?? ''));
        if ($userId === (string) ($row['user_id'] ?? '')) {
            return $row;
        }

        $this->pdo()->prepare('UPDATE accounts SET user_id = :user_id WHERE code = :code')->execute([
            ':user_id' => $userId,
            ':code' => (string) $row['code'],
        ]);
        $row['user_id'] = $userId;

        return $row;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $vars = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Не удалось прочитать {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $vars[$key] = $value;
        }

        return $vars;
    }

    private function assertAccountPaths(string $code, string $dbPath, string $feedDir, string $envFile): void
    {
        $dbDir = $this->root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR . $code;
        $expectedFeed = $this->root . DIRECTORY_SEPARATOR . 'fid' . DIRECTORY_SEPARATOR . $code;
        $this->assertInside($dbPath, $dbDir);
        $this->assertInside($feedDir, $expectedFeed);
        $this->assertEnvFile($code, $envFile);

        $dbName = strtolower(basename(str_replace('\\', '/', $dbPath)));
        if ($dbName !== 'avito.db') {
            throw new \RuntimeException("База аккаунта {$code} должна называться avito.db.");
        }
    }

    private function assertEnvFile(string $code, string $envFile): void
    {
        $envDir = $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'accounts';
        $this->assertInside($envFile, $envDir);
        $expected = strtolower($code . '.env');
        $actual = strtolower(basename(str_replace('\\', '/', $envFile)));
        if ($actual !== $expected) {
            throw new \RuntimeException("Файл ключей аккаунта {$code} должен быть config/accounts/{$code}.env.");
        }
    }

    private function assertInside(string $path, string $parent): void
    {
        $parentReal = realpath($parent);
        if ($parentReal === false) {
            throw new \RuntimeException("Нет каталога {$parent}");
        }

        $candidate = $path;
        if (!file_exists($candidate)) {
            $candidate = dirname($path);
        }
        $real = realpath($candidate);
        if ($real === false) {
            throw new \RuntimeException("Некорректный путь {$path}");
        }

        $normParent = $this->norm($parentReal);
        $normReal = $this->norm($real);
        if ($normReal !== $normParent && !str_starts_with($normReal, $normParent . '/')) {
            throw new \RuntimeException("Путь {$path} выходит за пределы каталога аккаунта.");
        }
    }

    private function norm(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать {$dir}");
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Реестр уже закрыт.');
        }

        return $this->pdo;
    }
}
