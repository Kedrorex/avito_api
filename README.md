# Avito Republisher — PHP

PHP-приложение для взаимодействия с Avito API: синхронизация объявлений, сбор статистики, анализ производительности, автоматическое переопубликование слабых объявлений и генерация TSV-фида для Avito AutoLoad.

## Быстрый старт

### 1. Установка зависимостей

```powershell
php composer.phar install
```

### 2. Настройка .env

Создайте в корне файл `.env` (НЕ добавляйте его в Git):

```dotenv
AVITO_CLIENT_ID=your_client_id
AVITO_CLIENT_SECRET=your_client_secret
AVITO_USER_ID=your_avito_account_id
```

### 3. Проверка подключения

```powershell
# Проверка интеграционных тестов (требует валидных credentials)
php tests/test_integration.php

# Проверка синтаксиса всех ключевых файлов
php -l src/Services/AvitoAPIClient.php
php -l src/Repositories/ItemRepository.php
php -l src/Controllers/AvitoController.php
```

---

## CLI-команды

Все команды запускаются через `php index.php <команда> [аргументы]`.

### Полный пайплайн

| Команда | Описание | Пример |
|---|---|---|
| `run` | Синхронизация, статистика, очередь кандидатов и фид. Снятие в фиде — не больше 70 за день | `php index.php run` |
| `run-test [count]` | Тот же цикл, что `run`. Без числа — снятие из очереди, очередь не списывается. С числом — столько первых объявлений каталога на снятие, без отбора кандидатов | `php index.php run-test 5` |

Параметры пайплайна читаются из `config/avito.php`:
- `stats_days` — дней для сбора статистики (по умолчанию: 3)
- `max_daily_repub` — максимальное число републикаций в день (по умолчанию: 70)

> `run` и `feed` пишут один и тот же файл фида. Через API (`republish-all`) объявления по-прежнему снимаются только при явном количестве.

### Синхронизация и данные

| Команда | Описание | Пример |
|---|---|---|
| `sync` | Синхронизация объявлений всех статусов с Avito API (JSON) | `php index.php sync` |
| `active` | Список всех активных объявлений в БД (JSON) | `php index.php active` |
| `status-counts` | Подсчёт объявлений по статусам | `php index.php status-counts` |
| `item <id>` | Детальная информация об элементе из Avito API | `php index.php item 1234567890` |

### Статистика

| Команда | Описание | Пример |
|---|---|---|
| `collect-stats [days]` | Сбор дневной статистики всех объявлений в SQLite (1–270 дней, по умолчанию 30) | `php index.php collect-stats`<br>`php index.php collect-stats 7` |
| `stats [from] [to]` | Получение статистики за период из API (JSON). Даты в формате YYYY-MM-DD | `php index.php stats 2024-01-01 2024-01-31` |
| `ad <id>` | Просмотр сохранённого объявления и его статистики из SQLite | `php index.php ad 1234567890` |

### Республикация и кандидаты

| Команда | Описание | Пример |
|---|---|---|
| `republish <id>` | Республикация конкретного объявления по локальному ID | `php index.php republish 42` |
| `republish-all <count>` | Массовая републикация N кандидатов (с подтверждением) | `php index.php republish-all 20` |
| `collect-candidates [days]` | Сбор объявлений с нулевыми просмотрами за N дней в очередь кандидатов | `php index.php collect-candidates`<br>`php index.php collect-candidates 7` |
| `show-candidates` | Вывод списка всех кандидатов на републикацию | `php index.php show-candidates` |

> ⚠️ **ВАЖНО: Республикация требует явного разрешения**
> 
> Автоматическая републикация ВСЕХ кандидатов **запрещена**.
> Команда `run()` только собирает статистику и находит кандидатов — НЕ републикует.
> 
> Для републикации нужно ЯВНО указать количество:
> ```powershell
> # Республикация ровно 20 объявлений (с запросом подтверждения)
> php index.php republish-all 20
> 
> # Республикация 70 объявлений (максимум по лимиту)
> php index.php republish-all 70
> ```
> 
> Система запросит подтверждение перед выполнением:
> ```
>   Подтвердите републикацию 20 объявлений? (yes/no): yes
> ```

**Важно:** Параметр `days` в `collect-candidates` имеет приоритет над `config['avito']['candidate_days']`. Это позволяет динамически задавать окно анализа:

```powershell
# Пример: найти объявления с 0 просмотрами за 7 дней
php index.php collect-candidates 7

# Пример: использовать значение из конфига (candidate_days = 4)
php index.php collect-candidates
```

### Анализ

| Команда | Описание | Пример |
|---|---|---|
| `analyze` | Анализ всех активных объявлений по правилам из конфига | `php index.php analyze` |
| `analyze-report` | Отчёт по анализу с разбивкой по правилам | `php index.php analyze-report` |

Правила анализа настраиваются в `config/avito.php` → `analysis_thresholds`:

```php
'analysis_thresholds' => [
    [
        'name' => 'zero_contacts',
        'days' => 5,           // Окно анализа в днях
        'max_views' => 0,      // Макс. просмотров
        'max_contacts' => 0,   // Макс. контактов
        'max_favorites' => 0,  // Макс. добавлений в избранное
    ],
    [
        'name' => 'low_views',
        'days' => 10,
        'max_views' => 1,
        'max_contacts' => 0,
        'max_favorites' => 0,
    ],
],
```

Объявление считается кандидатом, если попадает **хотя бы в одно** правило.

### Фид (Avito AutoLoad)

Файл фида: `fid/avito_feed_YYYY-MM-DD.xml` (Avito AutoLoad, `formatVersion=3`). Это полный фид автозагрузки, не список одних кандидатов. Объявления, которых в файле нет, Авито снимет. В кабинет загружается этот XML.

Состав строк, если в каталоге 100 объявлений и в очереди 10 кандидатов:

1. Сверху 10 строк со статусом `removed` — снятие этих кандидатов. Данные берутся из `physical_ads`, не из строки очереди.
2. Ниже те же 100 объявлений со статусом `active`, **включая этих 10 кандидатов**.

Итого 110 строк. Кандидаты читаются из очереди `republish_candidates_*` (сначала самые старые по `added_at`). За день снимается не больше `max_daily_repub` (70). Счётчик дня лежит в `app_meta` (`feed_repub_YYYY-MM-DD`) и складывается с републикациями через API. После записи файла эта порция удаляется из очереди. Остальные кандидаты ждут следующего дня и в сегодняшнем файле идут только в блоке `active`.

Каталог — объявления со статусом `active` и `low_perf`. Попадание в очередь не выкидывает объявление из каталога.

| Команда | Описание | Пример |
|---|---|---|
| `feed` | Фид: снятие до 70 из очереди сверху, затем весь каталог | `php index.php feed` |
| `feed-keep [count]` | XML только из первых N объявлений каталога. Остальных в файле нет, Авито их снимет. По умолчанию 10 | `php index.php feed-keep`<br>`php index.php feed-keep 10` |
| `feed-inactive [count]` | Неактивные из кабинета Avito (`removed`, `old`) + текущие `active`. Все как `active` | `php index.php feed-inactive`<br>`php index.php feed-inactive 100`<br>`php index.php feed-inactive --only-inactive` |
| `feed-only` | То же без синхронизации списка с API. Сначала добирает `unique_id` | `php index.php feed-only` |
| `republish-feeds <count>` | Тот же файл. `count` ограничивает только блок снятия (1..70) | `php index.php republish-feeds 10` |
| `feed-info` | Информация о последней генерации фида | `php index.php feed-info` |
| `run-test [count]` | Полный цикл `run` для проверки файла на Авито. Без числа снятие из очереди, даже если лимит дня занят. С числом — первые N каталога на снятие, без сверки кандидатов по avito_id. Очередь и счётчик дня не меняются | `php index.php run-test`<br>`php index.php run-test 5` |

`feed-inactive` забирает из кабинета Avito объявления `removed` и `old`, плюс текущие `active`, и пишет `fid/avito_feed_inactive_YYYY-MM-DD.xml` со статусом `active`. Id автозагрузки подтягивается через `/autoload/v2/items/ad_ids`. Если загрузить только этот файл, Авито снимет объявления, которых в нём нет.

`run-test` пишет тот же `fid/avito_feed_YYYY-MM-DD.xml`. Загрузка файла на Авито снимает и снова публикует объявления из верхнего блока. `php index.php run-test 5` кладёт в этот блок первые 5 объявлений каталога и не смотрит, есть ли они в очереди кандидатов. Очередь и дневной счётчик при этом не меняются.

---

## HTTP-режим

Запустить встроенный PHP-сервер:

```powershell
php -S localhost:8080 index.php
```

| Метод | Путь | Описание |
|---|---|---|
| `POST` | `/run` | Полный пайплайн (аналог `php index.php run`) |
| `GET` | `/active` | Список активных объявлений (JSON) |
| `POST` | `/sync` | Синхронизация с Avito API (JSON) |
| `GET` | `/stats?dateFrom=...&dateTo=...` | Статистика за период (JSON) |
| `POST` | `/republish/{id}` | Республикация объявления (JSON) |
| `GET` | `/status-counts` | Подсчёт по статусам (JSON) |
| `GET` | `/item/{id}` | Детали элемента из API (JSON) |
| `POST` | `/collect-candidates` | Сбор кандидатов (JSON) |
| `GET` | `/candidates` | Список кандидатов (JSON) |
| `DELETE` | `/candidates/{id}` | Удалить кандидата (JSON) |
| `POST` | `/feed/generate` | Генерация фида (JSON) |
| `GET` | `/feed/info` | Информация о фиде (JSON) |

---

## Конфигурация

### config/avito.php

Основные параметры:

```php
'avito' => [
    // Rate limiting
    'rate_limit_delay' => 8.0,              // Задержка между запросами (сек)
    'stats_request_delay_seconds' => 8,     // Задержка для stats API
    'request_timeout' => 120,               // Таймаут HTTP-запросов (сек)
    
    // Лимиты
    'max_daily_repub' => 70,                // Макс. републикаций в день
    'stats_days' => 3,                      // Дней для сбора статистики в run()
    
    // Кандидаты
    'candidate_days' => 4,                  // Дней по умолчанию для collect-candidates
    
    // Retry
    'max_retries' => 3,                     // Макс. попыток при ошибке
    'retry_delay_base' => 65,               // Задержка между повторными попытками (сек)
],

'analysis_thresholds' => [
    // Правила анализа — настраиваются без изменения кода
],
```

### Динамические параметры (days)

| Параметр | Где настраивается | Приоритет |
|---|---|---|
| `collect-stats [days]` | CLI аргумент | CLI > config default (30) |
| `collect-candidates [days]` | CLI аргумент | **CLI > config['candidate_days']** |
| `analysis_thresholds[].days` | config/avito.php | Конфиг (безопасное изменение без кода) |
| `stats_days` | config/avito.php | Используется в `run()` |

---

## База данных

Файл: `data/avito.db` (SQLite)

### Схема

- **`physical_ads`** — поколения объявлений
- **`stats`** — legacy таблица статистики (обратная совместимость)
- **`statistics_YYYY_MM`** — партиционированная статистика по месяцам
- **`republish_candidates_YYYY_MM`** — кандидаты на републикацию
- **`stats_meta` / `candidates_meta`** — метаданные партиций

### Безопасность

- `PRAGMA foreign_keys = ON` включён автоматически при инициализации репозитория
- Имена партиций валидируются через regex `^(statistics|republish_candidates)_\d{4}_\d{2}$` перед использованием в SQL
- Все пользовательские данные экранируются через prepared statements

### Резервное копирование

```powershell
Copy-Item data\avito.db data\avito.backup.db
```

---

## Лимиты Avito API

| Endpoint | Лимит | Текущая настройка |
|---|---|---|
| `GET /core/v1/items` | ~25 req/min | 8 сек (~7.5 req/min) ✅ |
| `POST /stats/v1/...` | ~1 req/min на пакет | 8 сек между пакетами ✅ |
| Макс. дней на запрос stats | 270 | Разбивка по месяцам ✅ |
| Макс. items в пакете stats | 200 | 200 ✅ |

Сбор просит поля `views`, `contacts`, `favorites` и их уникальные пары, группировка по дням. Без списка полей Авито не присылает избранное: в базе оно записывалось как 0, хотя в кабинете добавления были. Кабинет сравнивать с суммой тех дней, которые уже собраны (`collect-stats`), а не с коротким окном `run` (`stats_days`, по умолчанию 3). Старые строки обновятся при следующем сборе за тот же период.

Тайминги консервативные и безопасные для Avito API.

---

## Документация

- [Обзор архитектуры](docs/ARCHITECTURE.md)
- [Работа с SQLite](docs/SQLITE.md)
- [Карта API-запросов](maps.md) — историческая справка; актуальный код в `src/Services/AvitoAPIClient.php`

---

## Известные ограничения

- `test_item.php` — диагностический сценарий, не записывает данные в БД
- `maps.md` — устарела, актуальная информация в `src/Services/AvitoAPIClient.php`
- SQLite поддерживает одну запись одновременно — не запускайте несколько инстансов одновременно
- HTTP-режим использует блокирующие `sleep()` — не подходит для production без очереди задач
