# SQLite: инструкция для этого проекта

SQLite — это не отдельный сервер. Каталог одного кабинета лежит в одном файле. PHP обращается к нему через встроенное расширение `pdo_sqlite`, которое уже включено в данном окружении.

Файлов может быть несколько, и они не смешиваются:

| Файл | Содержимое |
| --- | --- |
| `data/avito.db` | Объявления и статистика корневого кабинета. Команды без `account` открывают только его. |
| `data/accounts/{code}/avito.db` | То же для кабинета из реестра. Появляется при первой команде `php index.php account {code} ...`, не при `account add`. |
| `data/control.db` | Реестр: код, пути к базе и фиду, путь к env, включён ли кабинет. Ключей API и объявлений нет. |

Схема ниже относится к каждому `avito.db`. В `control.db` этих таблиц нет. Колонки `account_id` в каталоге нет: изоляция — это отдельный файл, а не фильтр в SQL.

## Где находится база и как к ней подключается код

Путь задан в `config/avito.php`:

```php
'dsn' => 'sqlite:' . __DIR__ . '/../data/avito.db',
```

Базовое подключение в PHP:

```php
$config = require __DIR__ . '/config/avito.php';
$pdo = new PDO(
    $config['database']['dsn'],
    null,
    null,
    $config['database']['options']
);
$pdo->exec('PRAGMA foreign_keys = ON');
$repository = new \App\Repositories\ItemRepository($pdo);
```

Важно: `ItemRepository` сам создаёт таблицы при первом создании объекта. `PRAGMA foreign_keys = ON` нужно включать на каждом PDO-подключении, потому что SQLite по умолчанию может не применять внешние ключи.

## Схема данных

### `physical_ads`

| Поле | Значение |
| --- | --- |
| `id` | Локальный целочисленный ID физического поколения объявления. |
| `logical_key` | Общий ключ, связывающий поколения одного логического объявления. |
| `avito_id` | ID объявления в Avito API. |
| `status` | Живые объявления: `active`, `low_perf`. Новое поколение без номера Авито тоже лежит здесь. |
| `published_at` / `deactivated_at` | Момент публикации. `deactivated_at` у живой строки обычно пустой. |
| `old_avito_id` | ID прежнего объявления при переопубликации. |
| `master_data` | JSON с описательными данными (заголовок, цена, адрес и т. п.). |

Каталог не хранит снятые поколения. После перепубликации или пропажи из списка API строка уходит в `deleted_ads`.

### `deleted_ads`

Те же поля, что у `physical_ads` (`logical_key`, `avito_id`, `unique_id`, `status`, даты, `old_avito_id`, `master_data` и колонки фида), плюс:

| Поле | Значение |
| --- | --- |
| `id` | Id архивной строки, не совпадает с id каталога. |
| `physical_ad_id` | Прежний `physical_ads.id`. По нему по-прежнему находится дневная статистика. |
| `removed_at` | Когда строку убрали из каталога. |
| `status` | Для перепубликации — `deactivated`. Для снятия синхронизацией — статус, который был в каталоге. |

### `stats`

| Поле | Значение |
| --- | --- |
| `id` | Локальный ID строки. |
| `physical_ad_id` | Id поколения. После архивации строка остаётся и стыкуется с `deleted_ads.physical_ad_id`. |
| `date` | Дата статистики в формате `YYYY-MM-DD`. |
| `views`, `uniq_views` | Все и уникальные просмотры. |
| `contacts`, `uniq_contacts` | Все и уникальные контакты. |
| `favorites`, `uniq_favorites` | Все и уникальные добавления в избранное. |

Есть ограничение `UNIQUE(physical_ad_id, date)`: в базе может быть только одна дневная запись для объявления. Метод `saveStats()` использует SQLite UPSERT, поэтому повторный импорт этой же даты обновляет показатели свежими данными.

Таблица `stats` — старое хранилище. Дневной сбор и `collect-stats` пишут в месячные секции. Чтение может смотреть и старую таблицу, и секции.

### `statistics_YYYY_MM`

Одна таблица на месяц, имя вроде `statistics_2026_09`. Появляется при первой записи дня этого месяца. Состав строки тот же, что у `stats`: `physical_ad_id`, `date`, просмотры, контакты, избранное и их уникальные пары, плюс `phone_shows`, `chats`, `price`. Ограничение то же: `UNIQUE(physical_ad_id, date)`.

Имена секций лежат в `stats_meta`. В SQL попадает только имя, которое подходит под `statistics_YYYY_MM`.

После архивации объявления дневные строки остаются. Они стыкуются с `deleted_ads.physical_ad_id`, а не с новым `deleted_ads.id`.

### `republish_candidates_YYYY_MM`

Очередь на замену `Id`, тоже по месяцам. Строка хранит `physical_ad_id`, `avito_id`, `logical_key` и `added_at`. Повторно тот же `physical_ad_id` в секцию не добавляется. Имена секций — в `candidates_meta`.

Само присутствие в очереди объявление из каталога не убирает. После боевой записи порция из очереди уходит, старое поколение архивируется.

### `app_meta`

Пары ключ–значение одного кабинета. Дневной счётчик замен — ключ `feed_repub_YYYY-MM-DD`. Здесь же служебные отметки импорта, не секреты API.

## Повседневная работа без `sqlite3.exe`

Консольная программа `sqlite3` в системе сейчас не установлена, но это не мешает: используйте PHP и PDO. Команда ниже выводит строки как JSON и не меняет базу.

```powershell
php -r '$pdo = new PDO("sqlite:data/avito.db"); foreach ($pdo->query("SELECT * FROM physical_ads LIMIT 20") as $row) { echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL; }'
```

Полезные диагностические запросы:

```powershell
# Сколько объявлений по статусам
php -r '$pdo = new PDO("sqlite:data/avito.db"); foreach ($pdo->query("SELECT status, COUNT(*) AS count FROM physical_ads GROUP BY status") as $row) { echo $row["status"].": ".$row["count"].PHP_EOL; }'

# Сколько дневных строк в секции текущего месяца. Имя таблицы — statistics_ГГГГ_ММ
php -r '$t = "statistics_" . date("Y_m"); $pdo = new PDO("sqlite:data/avito.db"); $sql = "SELECT physical_ad_id, COUNT(*) AS days, MIN(date) AS first_day, MAX(date) AS last_day FROM $t GROUP BY physical_ad_id"; foreach ($pdo->query($sql) as $row) { echo json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL; }'

# Последние 30 строк вместе с ID Avito и заголовком из master_data
php -r '$pdo = new PDO("sqlite:data/avito.db"); $sql = "SELECT p.avito_id, p.master_data, s.date, s.views, s.contacts, s.favorites FROM stats s JOIN physical_ads p ON p.id=s.physical_ad_id ORDER BY s.date DESC LIMIT 30"; foreach ($pdo->query($sql) as $row) { echo json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL; }'
```

Для визуального просмотра можно установить любой SQLite-клиент (например, DB Browser for SQLite), открыть `data/avito.db` и пользоваться только режимом просмотра, пока импорт не проверен. Закрывайте программу перед записью из PHP: SQLite поддерживает несколько читателей, но запись выполняется одним процессом за раз.

## Как правильно записывать статистику

В проекте уже есть метод:

```php
$repository->saveStats($physicalAdId, $dailyStats);
```

`$dailyStats` — это массив строк ответа API вида:

```php
[
    [
        'date' => '2026-09-07',
        'views' => 12,
        'uniqViews' => 10,
        'contacts' => 2,
        'uniqContacts' => 2,
        'favorites' => 1,
        'uniqFavorites' => 1,
    ],
]
```

Перед этим у статистики обязательно должен быть существующий `physical_ads.id`, соответствующий `avito_id` из ответа API. Не сохраняйте показатели в запись «наугад» по порядку массива.

Для импорта нескольких объявлений используйте транзакцию. Это делает импорт атомарным: либо сохранятся все обработанные строки, либо ни одна при ошибке.

```php
$pdo->beginTransaction();
try {
    foreach ($statsByAvitoId as $avitoId => $dailyStats) {
        $ad = $repository->getByAvitoId((string) $avitoId);
        if ($ad === null) {
            continue; // либо записать в журнал и обработать отдельно
        }
        $repository->saveStats((int) $ad['id'], $dailyStats);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

## Безопасность и резервные копии

- Не храните секреты Avito в SQLite и не добавляйте `.env` и `config/accounts/*.env` в Git.
- Перед изменением схемы или массовой записью скопируйте файл того кабинета, который меняете: `Copy-Item data\avito.db data\avito.backup.db` или `Copy-Item data\accounts\shop-2\avito.db data\accounts\shop-2\avito.backup.db`.
- Не копируйте файл базы в момент активной записи. Сначала дождитесь завершения скрипта.
- Не удаляйте `avito.db` кабинета для «очистки», если нужны история и результаты. Для теста лучше отдельный код аккаунта или отдельный файл и свой DSN. `control.db` каталог не хранит.

## Минимальные проверки после первого импорта

1. Количество активных объявлений в `physical_ads` соответствует числу полученных из API.
2. У каждой записи есть `avito_id`; без него статистику нельзя сопоставить.
3. В секции `statistics_YYYY_MM` нет двух строк с одинаковыми `(physical_ad_id, date)`.
4. Повторный запуск того же периода не увеличивает число строк, а обновляет значения.
5. Сумма дней, которые уже собраны, сходится с кабинетом за тот же диапазон. В короткий прогон `run` попадает только `stats_days`, по умолчанию 3 дня, не вся история объявления.
