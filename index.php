<?php
/**
 * Точка входа Avito Republisher
 *
 * Запуск:
 *   CLI:  php index.php run
 *         php index.php run-r
 *         php index.php schedule
 *   HTTP: php -S localhost:8080 index.php
 */

declare(strict_types=1);

use Slim\Factory\AppFactory;

// Загрузка зависимостей
require __DIR__ . '/vendor/autoload.php';

$cli = php_sapi_name() === 'cli';
$argv = $_SERVER['argv'] ?? [];
$command = $cli ? (string) ($argv[1] ?? 'run') : '';

require __DIR__ . '/env_helper.php';

// Загрузка .env
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Загрузка конфигурации
$config = require __DIR__ . '/config/avito.php';

// Создание Slim приложения
$app = AppFactory::create();

// Middleware
$app->addRoutingMiddleware();

// Регистрация маршрутов
$routes = require __DIR__ . '/routes/api.php';
$routes($app);

// Error handler
$app->addErrorMiddleware(true, true, true);

// ==================== CLI или HTTP ====================

if ($cli) {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    if ($command === 'schedule') {
        $scheduleArgs = array_slice($argv, 2);
        $scheduleHead = trim($scheduleArgs[0] ?? '');
        $wantsLoop = !isset($scheduleArgs[1]) && ($scheduleHead === '' || $scheduleHead === 'on' || $scheduleHead === 'enable');
        if (!$wantsLoop) {
            exit((new \App\Cli\RunScheduler(__DIR__))->handle($scheduleArgs));
        }
    }

    if (\App\Accounts\AccountCli::handles($command)) {
        exit((new \App\Accounts\AccountCli(__DIR__, $config))->run($argv));
    }

    try {
        $runtime = \App\Accounts\AccountRuntime::openLegacy($config);
    } catch (\RuntimeException $e) {
        echo "  [ERROR] " . $e->getMessage() . "\n";
        echo "  Создайте .env в корне проекта с AVITO_CLIENT_ID, AVITO_CLIENT_SECRET и AVITO_USER_ID.\n";
        exit(1);
    }

    if ($command === 'schedule') {
        $dsn = (string) ($runtime->config()['database']['dsn'] ?? '');
        try {
            (new \App\Cli\RunScheduler(__DIR__))->wait(static function () use ($runtime, $dsn): void {
                $lock = \App\Cli\RunLock::forDatabase($dsn);
                if (!$lock->acquire()) {
                    echo "  Пропуск: предыдущий php index.php run ещё выполняется\n";

                    return;
                }
                try {
                    $controller = $runtime->controller();
                    $controller->run();
                    if (!$controller->cloudPublished()) {
                        echo "  [ERROR] Фид в облако не отправлен\n";
                    }
                } finally {
                    $lock->release();
                }
            });
        } catch (\Throwable $e) {
            echo '  [ERROR] ' . $e->getMessage() . "\n";
            $runtime->close();
            exit(1);
        }
        $runtime->close();
        exit(0);
    }

    try {
        $status = (new \App\Cli\CommandDispatcher())->dispatch($command, array_slice($argv, 2), $runtime);
    } finally {
        $runtime->close();
    }
    exit($status);
} else {
    // HTTP режим
    $app->run();
}
