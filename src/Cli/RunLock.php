<?php

declare(strict_types=1);

namespace App\Cli;

/**
 * Один одновременный прогон на файл базы.
 * Блокировка снимается, когда процесс завершается, в том числе аварийно.
 */
final class RunLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public static function forDatabase(string $dsn): self
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            throw new \RuntimeException('Не удалось определить файл базы для блокировки запуска');
        }
        $db = substr($dsn, 7);
        if ($db === '') {
            throw new \RuntimeException('Не удалось определить файл базы для блокировки запуска');
        }

        return new self(dirname($db) . DIRECTORY_SEPARATOR . 'schedule' . DIRECTORY_SEPARATOR . 'run.lock');
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать {$dir}");
        }
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть {$this->path}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
