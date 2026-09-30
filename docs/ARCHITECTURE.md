# Как устроен проект

Код ведёт один кабинет Авито: забирает активные объявления и статистику, копит очередь слабых объявлений и пишет XML для автозагрузки. Готовый файл `run` перезаписывает на Яндекс.Диске. Авито забирает его по публичной ссылке.

Дополнительные кабинеты изолированы файлами: свой `.env`, свой `avito.db`, свой каталог фида. Общая схема описана в [SCALING.md](SCALING.md). Команды и первый запуск — в [README.md](../README.md).

## Точка входа

`index.php` в консоли открывает один контур и передаёт команду дальше.

| Команда | Куда попадает |
| --- | --- |
| `php index.php <команда>` | [AccountRuntime::openLegacy](../src/Accounts/AccountRuntime.php): корневой `.env`, `data/avito.db`, `fid/` |
| `php index.php account <code> <команда>` | [AccountCli](../src/Accounts/AccountCli.php) и реестр [AccountRegistry](../src/Accounts/AccountRegistry.php) |
| `php index.php accounts <команда>` | Те же команды по всем включённым кабинетам, по очереди |
| `php index.php schedule` | [RunScheduler](../src/Cli/RunScheduler.php) ждёт `RUN_AT` и вызывает тот же цикл, что `run` |

Разбор команд одного уже открытого контура — [CommandDispatcher](../src/Cli/CommandDispatcher.php). Пока цикл пишет в базу, [RunLock](../src/Cli/RunLock.php) не пускает второй процесс на тот же файл.

Маршруты Slim в [routes/api.php](../routes/api.php) подключаются, но контроллер для HTTP не собирается: рабочий запуск — консоль.

## Дневной цикл `run`

[AvitoController::run](../src/Controllers/AvitoController.php) делает одно и то же для корневого кабинета и для `account {code} run`:

1. Синхронизация активных объявлений кабинета в `physical_ads`. Снятых в каталоге нет: копия уходит в `deleted_ads`.
2. Импорт последней выгрузки Автозагрузки: описание, фото, производитель, OEM. Затем дописываются пустые `unique_id`.
3. Статистика за `stats_days` (по умолчанию 3) в месячные таблицы `statistics_YYYY_MM`.
4. Очередь слабых объявлений в `republish_candidates_YYYY_MM`. В дневном цикле окно — 4 дня.
5. XML каталога `active` и `low_perf`. Команда `run` в файл замену `Id` не пишет: очередь только пополняется.
6. Если это `run`, файл перезаписывается на Яндекс.Диске. `run-r` оставляет его в `fid/`.

Боевая замена `Id` — отдельные команды `feed` и `republish-feeds`. Они берут порцию очереди, не больше `max_daily_repub` (70) с учётом счётчика дня `app_meta.feed_repub_YYYY-MM-DD`. Старый `Id` в файл не попадает, новое поколение пишется без `AvitoId`. Заголовок, абзацы и порядок фото слегка меняет [AdContentVariation](../src/Services/AdContentVariation.php).

`republish` и `republish-all` снимают объявление запросом к API, это не файл автозагрузки. Снятие делает [RepublisherService](../src/Services/RepublisherService.php) через `AvitoAPIClient::deactivateItem()`.

## Кто за что отвечает

| Путь | Роль |
| --- | --- |
| [src/Services/AvitoAPIClient.php](../src/Services/AvitoAPIClient.php) | OAuth и вызовы API: список объявлений, карточка, статистика, отчёты автозагрузки, снятие. |
| [src/Repositories/ItemRepository.php](../src/Repositories/ItemRepository.php) | Единственный доступ к SQLite одного кабинета. Создаёт таблицы при открытии. |
| [src/Services/FeedGeneratorService.php](../src/Services/FeedGeneratorService.php) | Сборка XML из каталога и очереди. |
| [src/Services/RepublishFeedService.php](../src/Services/RepublishFeedService.php) | Порция замены `Id` с учётом дневного лимита. |
| [src/Services/AutoloadFeedImportService.php](../src/Services/AutoloadFeedImportService.php) | Разбор выгрузки Автозагрузки в поля объявления. |
| [src/Services/AutoloadIdSyncService.php](../src/Services/AutoloadIdSyncService.php) | Дописывает пустые Id автозагрузки. |
| [src/Services/AnalysisService.php](../src/Services/AnalysisService.php) | Правила из `analysis_thresholds` в [config/avito.php](../config/avito.php). |
| [src/Services/YandexDiskFeedUploader.php](../src/Services/YandexDiskFeedUploader.php) | Перезапись уже опубликованного файла. Токен и ссылка берутся из env открытого кабинета. |
| [src/Accounts/AccountRuntime.php](../src/Accounts/AccountRuntime.php) | На один прогон собирает клиент, PDO, репозиторий и контроллер и закрывает соединение. |
| [config/avito.php](../config/avito.php) | Общие паузы, лимиты, пороги и каталог фида. Ключи API для корневого запуска читаются из `.env`. |

Паузы между запросами списка и статистики — 8 секунд, из `config/avito.php`. Токен OAuth живёт в объекте клиента одного прогона и к следующему кабинету не переходит.

## Проверки без API

```powershell
php scripts/test_account_isolation.php
php scripts/test_autoload_id_service.php
php scripts/test_autoload_id_sync.php
php scripts/test_feed_unique_id.php
php scripts/test_run_schedule.php
php scripts/test_yandex_disk_upload.php
```

Они не ходят в Авито и не меняют рабочую базу. Схема таблиц — в [SQLITE.md](SQLITE.md).
