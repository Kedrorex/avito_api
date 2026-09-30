<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Cli\CommandDispatcher;

/**
 * Команды реестра и последовательный прогон включённых кабинетов.
 */
final class AccountCli
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $config,
    ) {
    }

    public static function handles(string $command): bool
    {
        return $command === 'account' || $command === 'accounts';
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        $registry = AccountRegistry::open($this->projectRoot);
        try {
            if ($command === 'accounts') {
                return $this->runEnabled($registry, $argv[2] ?? 'run', array_slice($argv, 3));
            }

            return $this->runAccount($registry, $argv);
        } finally {
            $registry->close();
        }
    }

    /**
     * @param list<string> $argv
     */
    private function runAccount(AccountRegistry $registry, array $argv): int
    {
        $sub = $argv[2] ?? '';
        if ($sub === '' || $sub === 'help' || $sub === '--help') {
            $this->printUsage();

            return $sub === '' ? 1 : 0;
        }

        if ($sub === 'list') {
            $this->printList($registry);

            return 0;
        }

        if ($sub === 'add') {
            $code = $argv[3] ?? '';
            if ($code === '') {
                echo "Usage: php index.php account add <code>\n";

                return 1;
            }

            try {
                $row = $registry->add($code);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                echo '  [ERROR] ' . $e->getMessage() . "\n";

                return 1;
            }

            echo "Аккаунт {$row['code']} добавлен.\n";
            echo '  База:  ' . $registry->relative((string) $row['db_path']) . "\n";
            echo '  Фид:   ' . $registry->relative((string) $row['feed_dir']) . "\n";
            echo '  Ключи: ' . $registry->relative((string) $row['env_file']) . "\n";
            echo "Заполните ключи Авито и ссылку Яндекс.Диска в env-файле. Файл базы создастся при первой команде.\n";
            echo "  Дальше: php index.php account {$row['code']} disk-auth\n";

            return 0;
        }

        $task = $argv[3] ?? 'run';
        $args = array_slice($argv, 4);

        try {
            $context = $registry->loadContext($sub);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            echo '  [ERROR] ' . $e->getMessage() . "\n";

            return 1;
        }

        echo "=== {$context->code} ===\n";

        return $this->runOne($context, $task, $args);
    }

    /**
     * @param list<string> $args
     */
    private function runEnabled(AccountRegistry $registry, string $task, array $args): int
    {
        $rows = $registry->enabled();
        if ($rows === []) {
            echo "Нет включённых аккаунтов.\n";

            return 0;
        }

        $failed = 0;
        $dispatcher = new CommandDispatcher();
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            echo "\n=== {$code} ===\n";
            try {
                $context = $registry->loadContext($code);
                $runtime = AccountRuntime::open($context, $this->config);
            } catch (\Throwable $e) {
                echo '  [ERROR] ' . $e->getMessage() . "\n";
                $failed++;
                continue;
            }

            try {
                $status = $dispatcher->dispatch($task, $args, $runtime);
                if ($status !== 0) {
                    $failed++;
                }
            } catch (\Throwable $e) {
                echo '  [ERROR] ' . $e->getMessage() . "\n";
                $failed++;
            } finally {
                $runtime->close();
            }
        }

        $ok = count($rows) - $failed;
        echo "\nГотово: {$ok}, ошибок: {$failed}\n";

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @param list<string> $args
     */
    private function runOne(AccountContext $context, string $task, array $args): int
    {
        try {
            $runtime = AccountRuntime::open($context, $this->config);
        } catch (\Throwable $e) {
            echo '  [ERROR] ' . $e->getMessage() . "\n";

            return 1;
        }

        try {
            return (new CommandDispatcher())->dispatch($task, $args, $runtime);
        } catch (\Throwable $e) {
            echo '  [ERROR] ' . $e->getMessage() . "\n";

            return 1;
        } finally {
            $runtime->close();
        }
    }

    private function printList(AccountRegistry $registry): void
    {
        $rows = $registry->list();
        if ($rows === []) {
            echo "Реестр пуст. Добавьте кабинет: php index.php account add <code>\n";

            return;
        }

        foreach ($rows as $row) {
            $enabled = (int) $row['enabled'] === 1 ? 'yes' : 'no';
            $userId = (string) ($row['user_id'] ?? '');
            if ($userId === '') {
                $userId = '-';
            }
            echo $row['code'] . "  enabled={$enabled}  user_id={$userId}\n";
            echo '  база: ' . $registry->relative((string) $row['db_path']) . "\n";
            echo '  фид:  ' . $registry->relative((string) $row['feed_dir']) . "\n";
        }
    }

    private function printUsage(): void
    {
        echo "Usage:\n";
        echo "  php index.php account list\n";
        echo "  php index.php account add <code>\n";
        echo "  php index.php account <code> <command> [args]\n";
        echo "  php index.php accounts <command> [args]\n";
    }
}
