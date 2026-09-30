<?php

/**
 * Расчёт следующего запуска читает время из .env и не обращается к планировщику ОС.
 *
 * php scripts/test_run_schedule.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Cli\RunLock;
use App\Cli\RunScheduler;

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

check(RunScheduler::parseTime('9:05') === '09:05', '9:05 становится 09:05');
check(RunScheduler::parseTime('"18:00"') === '18:00', 'кавычки вокруг времени снимаются');

$thrown = false;
try {
    RunScheduler::parseTime('25:00');
} catch (\InvalidArgumentException) {
    $thrown = true;
}
check($thrown, 'час 25 отклоняется');

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avito-schedule-' . bin2hex(random_bytes(4));
mkdir($temp);
$env = $temp . DIRECTORY_SEPARATOR . '.env';
file_put_contents($env, "AVITO_CLIENT_ID=secret-value\nAVITO_CLIENT_SECRET=other-secret\n");

$scheduler = new RunScheduler($temp, $env);

try {
    ob_start();
    $bad = $scheduler->handle(['25:00']);
    ob_end_clean();
    check($bad === 1, 'неверное время завершается с ошибкой');

    ob_start();
    $written = $scheduler->handle(['9:05']);
    ob_end_clean();
    check($written === 0, 'запись времени завершается нулём');
    $saved = (string) file_get_contents($env);
    check(str_contains($saved, 'AVITO_CLIENT_ID=secret-value'), 'секрет кабинета остаётся');
    check(str_contains($saved, 'AVITO_CLIENT_SECRET=other-secret'), 'второй секрет остаётся');
    check(str_contains($saved, 'RUN_AT=09:05'), 'время записано в RUN_AT');
    check(substr_count($saved, 'Меняйте эту строку') === 1, 'подсказка в .env одна');

    file_put_contents($env, str_replace('RUN_AT=09:05', 'RUN_AT=19:10', $saved));
    $day = new DateTimeImmutable('2026-09-30 19:09:00');
    check(!$scheduler->claimIfDue($day), 'до часа из файла прогон не берётся');
    check($scheduler->claimIfDue($day->modify('+1 minute')), 'в указанную минуту прогон берётся');
    check(!$scheduler->claimIfDue($day->modify('+2 minutes')), 'повтор в тот же день не берётся');

    file_put_contents($env, str_replace('RUN_AT=19:10', 'RUN_AT=21:00', (string) file_get_contents($env)));
    check(!$scheduler->claimIfDue(new DateTimeImmutable('2026-09-30 21:00:00')), 'после уже случившегося прогона новый час ждёт завтра');
    check($scheduler->claimIfDue(new DateTimeImmutable('2026-10-01 21:00:00')), 'на следующий день новый час срабатывает');

    $past = $temp . '-past';
    mkdir($past);
    $pastEnv = $past . DIRECTORY_SEPARATOR . '.env';
    file_put_contents($pastEnv, "RUN_AT=08:00\n");
    $pastScheduler = new RunScheduler($past, $pastEnv);
    $noon = new DateTimeImmutable('2026-09-30 12:00:00');
    check(!$pastScheduler->claimIfDue($noon), 'час, который сегодня уже прошёл, сегодня не запускает');
    check($pastScheduler->claimIfDue(new DateTimeImmutable('2026-10-01 08:00:00')), 'прошедший час запускает на следующий день');

    $moment = new DateTimeImmutable('2026-09-30 17:50:00');
    $waiting = new RunScheduler(
        $temp,
        $env,
        static function () use (&$moment): DateTimeImmutable {
            return $moment;
        },
        static function () use (&$moment): void {
            $moment = $moment->modify('+15 minutes');
        },
    );
    file_put_contents($env, str_replace('RUN_AT=21:00', 'RUN_AT=18:00', (string) file_get_contents($env)));
    $runs = 0;
    ob_start();
    $waiting->wait(static function () use (&$runs): void {
        $runs++;
    }, 6);
    $waitOut = (string) ob_get_clean();
    check($runs === 1, 'цикл выполняет код один раз, когда час наступил');
    check(str_contains($waitOut, 'Жду 18:00'), 'процесс сообщает, какого часа ждёт');
    check(str_contains($waitOut, 'Цикл закончен'), 'после выполнения процесс остаётся ждать');

    ob_start();
    $off = $scheduler->handle(['off']);
    ob_end_clean();
    check($off === 0, 'off завершается нулём');
    check(str_contains((string) file_get_contents($env), 'RUN_AT=18:00'), 'off не стирает время');
    check(str_contains((string) file_get_contents($env), 'secret-value'), 'секрет на месте');

    $db = $temp . DIRECTORY_SEPARATOR . 'cabinet' . DIRECTORY_SEPARATOR . 'avito.db';
    $lock = RunLock::forDatabase('sqlite:' . $db);
    $other = RunLock::forDatabase('sqlite:' . $db);
    check($lock->acquire(), 'первая блокировка взята');
    check(!$other->acquire(), 'второй прогон не берёт ту же блокировку');
    $lock->release();
    check($other->acquire(), 'после завершения блокировка свободна');
    $other->release();
} finally {
    rrmdir($temp);
    if (isset($past)) {
        rrmdir($past);
    }
}

if ($failures !== []) {
    echo "\nFAIL " . count($failures) . "\n";
    exit(1);
}

echo "\nOK\n";
exit(0);
