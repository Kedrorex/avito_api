<?php

declare(strict_types=1);

namespace App\Cli;

use App\Support\EnvFile;

/**
 * Процесс ждёт час из .env и выполняет тот же цикл, что php index.php run.
 * Час хранится только в RUN_AT. Планировщик операционной системы не используется.
 */
final class RunScheduler
{
    private readonly string $envFile;

    /**
     * @param null|callable(): \DateTimeImmutable $clock
     * @param null|callable(): void $sleeper
     */
    public function __construct(
        private readonly string $projectRoot,
        ?string $envFile = null,
        private readonly mixed $clock = null,
        private readonly mixed $sleeper = null,
    ) {
        $this->envFile = $envFile ?? $projectRoot . DIRECTORY_SEPARATOR . '.env';
    }

    /**
     * @param list<string> $args
     */
    public function handle(array $args): int
    {
        $arg = trim($args[0] ?? '');
        if (isset($args[1])) {
            $this->usage();

            return 1;
        }

        try {
            if ($arg === '' || $arg === 'status') {
                return $this->show();
            }
            if ($arg === 'off' || $arg === 'disable' || $arg === 'stop') {
                echo "  Ожидание останавливается вместе с процессом php index.php schedule.\n";
                echo "  Время в .env сохранено.\n";

                return 0;
            }

            return $this->setTime(self::parseTime($arg));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            echo '  [ERROR] ' . $e->getMessage() . "\n";

            return 1;
        }
    }

    /**
     * Ждёт RUN_AT, вызывает $run, затем снова ждёт.
     * $maxTicks ограничивает число пробуждений в тесте. В работе процесс не выходит сам.
     */
    public function wait(callable $run, ?int $maxTicks = null): void
    {
        $announced = null;
        $emptyAnnounced = false;
        $errorAnnounced = null;
        $ticks = 0;
        while ($maxTicks === null || $ticks < $maxTicks) {
            $ticks++;
            try {
                $time = $this->readTime();
            } catch (\InvalidArgumentException $e) {
                if ($errorAnnounced !== $e->getMessage()) {
                    echo '  [ERROR] ' . $e->getMessage() . "\n";
                    $errorAnnounced = $e->getMessage();
                }
                $this->pause();
                continue;
            }
            $errorAnnounced = null;
            if ($time === '') {
                if (!$emptyAnnounced) {
                    echo "  В .env ключ RUN_AT пуст. Впишите локальное время ЧЧ:ММ.\n";
                    $emptyAnnounced = true;
                }
                $announced = null;
                $this->pause();
                continue;
            }
            $emptyAnnounced = false;
            if ($announced !== $time) {
                echo "  Жду {$time}. Время меняется в .env, ключ RUN_AT.\n";
                $announced = $time;
            }
            if ($this->claimIfDue()) {
                echo "  Старт цикла php index.php run\n";
                try {
                    $run();
                } catch (\Throwable $e) {
                    echo '  [ERROR] ' . $e->getMessage() . "\n";
                }
                echo "  Цикл закончен. Дальше жду следующий день.\n";
            }
            $this->pause();
        }
    }

    /**
     * Забрать сегодняшний запуск, если час из .env уже наступил.
     * Смена RUN_AT на время, которое сегодня уже прошло, ждёт следующего дня.
     */
    public function claimIfDue(?\DateTimeImmutable $now = null): bool
    {
        $now ??= $this->currentTime();
        try {
            $time = $this->readTime();
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ($time === '') {
            return false;
        }

        $path = $this->scheduleDir() . DIRECTORY_SEPARATOR . 'state.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть {$path}");
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Не удалось заблокировать {$path}");
            }
            $raw = stream_get_contents($handle);
            $state = $this->decodeState(is_string($raw) ? $raw : '');
            $today = $now->format('Y-m-d');
            $hm = $now->format('H:i');
            $changed = false;
            $due = false;

            if ($state['time'] !== $time) {
                $state['time'] = $time;
                if ($state['last_run'] === $today || $hm > $time) {
                    $state['skip_until'] = $today;
                } else {
                    $state['skip_until'] = '';
                }
                $changed = true;
            } elseif ($state['last_run'] !== $today && $state['skip_until'] !== $today && $hm >= $time) {
                $state['last_run'] = $today;
                $changed = true;
                $due = true;
            }

            if ($changed) {
                $this->writeState($handle, $state);
            }

            return $due;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function parseTime(string $raw): string
    {
        $raw = trim($raw);
        if (
            (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
            || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
        ) {
            $raw = substr($raw, 1, -1);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $matches) !== 1) {
            throw new \InvalidArgumentException('Время укажите как ЧЧ:ММ');
        }
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            throw new \InvalidArgumentException('Время укажите как ЧЧ:ММ');
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function show(): int
    {
        $time = $this->readTime();
        if ($time === '') {
            echo "  В .env ключ RUN_AT пуст. Впишите локальное время ЧЧ:ММ.\n";
        } else {
            echo "  Время в .env: {$time}\n";
        }
        echo "  Ждать и выполнять цикл: php index.php schedule\n";

        return 0;
    }

    private function setTime(string $time): int
    {
        $this->requireEnv();
        $this->ensureRunAtKey();
        EnvFile::set($this->envFile, 'RUN_AT', $time);
        echo "  В .env записано RUN_AT={$time}\n";
        echo "  Дальше это время меняется в той же строке .env.\n";

        return 0;
    }

    private function readTime(): string
    {
        if (!is_file($this->envFile)) {
            return '';
        }
        $raw = file_get_contents($this->envFile);
        if ($raw === false) {
            throw new \RuntimeException('Не удалось прочитать ' . $this->envFile);
        }
        if (preg_match('/^RUN_AT=(.*)$/m', $raw, $matches) !== 1) {
            return '';
        }
        $value = trim($matches[1]);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($value === '') {
            return '';
        }

        return self::parseTime($value);
    }

    private function requireEnv(): void
    {
        if (!is_file($this->envFile)) {
            throw new \RuntimeException('Нет файла ' . $this->envFile);
        }
    }

    private function ensureRunAtKey(): void
    {
        $raw = file_get_contents($this->envFile);
        if ($raw === false) {
            throw new \RuntimeException('Не удалось прочитать ' . $this->envFile);
        }
        if (preg_match('/^RUN_AT=/m', $raw) === 1) {
            return;
        }
        $newline = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $hint = '# Локальное время ежедневного php index.php run, формат ЧЧ:ММ. Меняйте эту строку: процесс php index.php schedule читает её сам.';
        $body = rtrim($raw, "\r\n") . $newline . $hint . $newline;
        if (file_put_contents($this->envFile, $body) === false) {
            throw new \RuntimeException('Не удалось записать ' . $this->envFile);
        }
        EnvFile::set($this->envFile, 'RUN_AT', '');
    }

    /**
     * @return array{time: string, last_run: string, skip_until: string}
     */
    private function decodeState(string $raw): array
    {
        $empty = ['time' => '', 'last_run' => '', 'skip_until' => ''];
        if (trim($raw) === '') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }

        return [
            'time' => is_string($data['time'] ?? null) ? $data['time'] : '',
            'last_run' => is_string($data['last_run'] ?? null) ? $data['last_run'] : '',
            'skip_until' => is_string($data['skip_until'] ?? null) ? $data['skip_until'] : '',
        ];
    }

    /**
     * @param resource $handle
     * @param array{time: string, last_run: string, skip_until: string} $state
     */
    private function writeState($handle, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) === false) {
            throw new \RuntimeException('Не удалось записать состояние автозапуска');
        }
        fflush($handle);
    }

    private function scheduleDir(): string
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'schedule';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать {$dir}");
        }

        return $dir;
    }

    private function currentTime(): \DateTimeImmutable
    {
        if ($this->clock === null) {
            return new \DateTimeImmutable('now');
        }
        $now = ($this->clock)();
        if (!$now instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Часы автозапуска вернули неверное время');
        }

        return $now;
    }

    private function pause(): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)();

            return;
        }
        sleep(20);
    }

    private function usage(): void
    {
        echo "Usage: php index.php schedule [status|off|ЧЧ:ММ]\n";
        echo "  php index.php schedule        ждать RUN_AT и выполнять цикл run\n";
        echo "  php index.php schedule status показать время из .env\n";
        echo "  Время меняется в .env, ключ RUN_AT.\n";
    }
}
