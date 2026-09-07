# 📍 Структура проекта Avito API

## 🔑 Главный файл для запросов

### `src/Services/AvitoAPIClient.php`

**Это центральный файл.** Все запросы к Avito API идут через этот класс.

```
src/
└── Services/
    └── AvitoAPIClient.php   ← ВОТ ЗДЕСЬ РЕДАКТИРУЮТ ЗАПРОСЫ
```

---

## 📋 Основные методы (строки)

### 🔹 Авторизация
- **`refreshToken()`** — строка ~52
  - Запрос: `POST /token/`
  - Тело: `grant_type`, `client_id`, `client_secret`

### 🔹 Объявления (core API v1)
- **`listItems()`** — строка ~175
  - Запрос: `GET /core/v1/items`
  - Параметры: `status`, `per_page`, `page`
  
- **`getItemById()`** — строка ~228
  - Запрос: `GET /core/v1/items?id={id}`

- **`searchItemByNumber()`** — строка ~254
  - Запрос: `GET /core/v1/items`
  - Параметры: `id` или перебор всех

- **`getItemDetail()`** — строка ~320
  - Запрос: `GET /core/v1/accounts/{user_id}/items/{item_id}/`

- **`deactivateItem()`** — строка ~343
  - Запрос: `POST /core/v1/items/{item_id}/deactivate`

- **`updatePrice()`** — строка ~357
  - Запрос: `PATCH /core/v1/items/{item_id}`
  - Тело: `{"price": 123}`

### 🔹 Статистика (stats API v2)
- **`getStatsV2()`** — строка ~375
  - Запрос: `POST /stats/v2/accounts/{user_id}/items`
  - Тело:
    ```json
    {
      "dateFrom": "2026-01-01",
      "dateTo": "2026-01-31",
      "grouping": "day|week|month|item|totals",
      "limit": 1000,
      "metrics": ["views", "contacts", "uniqViews", ...],
      "offset": 0
    }
    ```

### 🔹 Баланс и операции
- (если нужно добавить — смотри Swagger в `swager_avito/swagger.json`)

---

## 🛠 Как добавить новый запрос

### Шаг 1: Найди базовый URL
Все запросы идут от: `https://api.avito.ru`

### Шаг 2: Добавь метод в AvitoAPIClient

```php
/**
 * Описание нового метода
 */
public function newMethodName(int $itemId, string $someParam): array
{
    try {
        $resp = $this->request('METHOD', '/path/to/endpoint', [
            'query' => [    // для GET параметров в URL
                'param1' => $value1,
            ],
            // ИЛИ
            'json' => [     // для POST/PUT/PATCH тела запроса
                'field1' => $value1,
                'field2' => $value2,
            ],
        ]);

        if ($resp->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode((string) $resp->getBody(), true);
        // Парсируй ответ под структуру API
        return $data['result'] ?? [];
    } catch (\Exception $e) {
        echo "[WARN] newMethodName error: " . $e->getMessage() . "\n";
        return [];
    }
}
```

### Шаг 3: Вызови метод в тестовом скрипте

```php
$apiClient = new \App\Services\AvitoAPIClient($config['avito']);
$result = $apiClient->newMethodName($itemId, $param);
```

---

## ⚙️ Конфигурация

### `config/avito.php`
- `client_id` — из `.env`
- `client_secret` — из `.env`
- `user_id` — из `.env`
- `api_base_url` — `https://api.avito.ru`
- `rate_limit_delay` — задержка между запросами (8 сек)

### `.env`
```
AVITO_CLIENT_ID=your_client_id
AVITO_CLIENT_SECRET=your_client_secret
AVITO_USER_ID=your_user_id
```

---

## 📚 Swagger API

Полная документация всех эндпоинтов:
- `swager_avito/swagger.json` — основной Swagger
- `swager_avito/swagger (1).json` ... `(8).json` — дополнительные

---

## 🧪 Тестовые скрипты

| Файл | Назначение |
|------|-----------|
| `test_item_stats.php` | Поиск по номеру + статистика |
| `test_integration.php` | Полный интеграционный тест |
| `test_stats.php` | Тест статистики (старый v1 API) |
| `debug_api.php` | Отладка параметров API |
| `count_active.php` | Подсчёт активных объявлений |
| `check_item_date.php` | Проверка даты объявления |
| `test_env.php` | Проверка .env |

---

## ⚠️ Важные ограничения

| Ограничение | Значение |
|-------------|---------|
| Rate limit (core) | 25 запросов/мин |
| Rate limit (stats v2) | 1 запрос/мин |
| Max items per request | 200 |
| Max depth (stats) | 270 дней |
| Max limit (stats v2) | 1000 |

---

## 🔄 Структура ответа API

### Core API v1 — список объявлений:
```json
{
  "resources": [
    {
      "id": 1234567890,
      "number": "8012519823",
      "title": "Название",
      "status": "active",
      "price": {"amount": 1000, "currency": "RUB"},
      "category": {"name": "Категория"},
      "created_at": "2026-01-01T00:00:00"
    }
  ]
}
```

### Stats v2 API — статистика:
```json
{
  "result": {
    "groupings": [
      {
        "groupingKey": {"itemId": 1234567890},
        "data": {
          "views": 100,
          "uniqViews": 80,
          "contacts": 10,
          "uniqContacts": 8,
          "favorites": 5,
          "uniqFavorites": 4
        }
      }
    ]
  }
}
```
