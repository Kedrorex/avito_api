# SQLite: инструкция для этого проекта

SQLite — это не отдельный сервер. Вся база находится в одном файле: `data/avito.db`. PHP обращается к нему через встроенное расширение `pdo_sqlite`, которое уже включено в данном окружении.

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
| `status` | Например, `active`, `deactivated`, `draft`, `error`. |
| `published_at` / `deactivated_at` | Момент публикации или деактивации. |
| `old_avito_id` | ID прежнего объявления при переопубликации. |
| `master_data` | JSON с описательными данными (заголовок, цена, адрес и т. п.). |

### `stats`

| Поле | Значение |
| --- | --- |
| `id` | Локальный ID строки. |
| `physical_ad_id` | Ссылка на `physical_ads.id`. |
| `date` | Дата статистики в формате `YYYY-MM-DD`. |
| `views`, `uniq_views` | Все и уникальные просмотры. |
| `contacts`, `uniq_contacts` | Все и уникальные контакты. |
| `favorites`, `uniq_favorites` | Все и уникальные добавления в избранное. |

Есть ограничение `UNIQUE(physical_ad_id, date)`: в базе может быть только одна дневная запись для объявления. Метод `saveStats()` использует SQLite UPSERT, поэтому повторный импорт этой же даты обновляет показатели свежими данными.

## Повседневная работа без `sqlite3.exe`

Консольная программа `sqlite3` в системе сейчас не установлена, но это не мешает: используйте PHP и PDO. Команда ниже выводит строки как JSON и не меняет базу.

```powershell
php -r '$pdo = new PDO("sqlite:data/avito.db"); foreach ($pdo->query("SELECT * FROM physical_ads LIMIT 20") as $row) { echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL; }'
```

Полезные диагностические запросы:

```powershell
# Сколько объявлений по статусам
php -r '$pdo = new PDO("sqlite:data/avito.db"); foreach ($pdo->query("SELECT status, COUNT(*) AS count FROM physical_ads GROUP BY status") as $row) { echo $row["status"].": ".$row["count"].PHP_EOL; }'

# Сколько дневных строк статистики по объявлениям
php -r '$pdo = new PDO("sqlite:data/avito.db"); $sql = "SELECT physical_ad_id, COUNT(*) AS days, MIN(date) AS first_day, MAX(date) AS last_day FROM stats GROUP BY physical_ad_id"; foreach ($pdo->query($sql) as $row) { echo json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL; }'

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

- Не храните секреты Avito в SQLite и не добавляйте `.env` в Git.
- Перед изменением схемы или массовой записью сделайте копию файла: `Copy-Item data\avito.db data\avito.backup.db`.
- Не копируйте файл базы в момент активной записи. Сначала дождитесь завершения скрипта.
- Никогда не удаляйте `data/avito.db` для «очистки», если нужны история и результаты. Для теста лучше создать отдельный файл БД и передать отдельный DSN.

## Минимальные проверки после первого импорта

1. Количество активных объявлений в `physical_ads` соответствует числу полученных из API.
2. У каждой записи есть `avito_id`; без него статистику нельзя сопоставить.
3. В `stats` нет двух строк с одинаковыми `(physical_ad_id, date)`.
4. Повторный запуск того же периода не увеличивает число строк, а обновляет значения.
5. Суммы `views`, `contacts`, `favorites` для одного объявления совпадают с выводом `test_item.php` за тот же диапазон дат.
