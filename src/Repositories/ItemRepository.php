<?php

namespace App\Repositories;

use PDO;
use PDOException;

/**
 * Репозиторий для работы с SQLite БД
 * Таблицы: physical_ads, stats
 */
class ItemRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        // Включаем проверку внешних ключей (SQLite отключает по умолчанию)
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        // WAL-режим: позволяет читать и писать параллельно без блокировок
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        // Уменьшаем синхронизацию для скорости
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->init();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Валидировать имя партиции — защита от SQL injection.
     */
    private function validatePartitionName(string $name): void
    {
        if (!preg_match('/^(statistics|republish_candidates)_(\d{4})_(\d{2})$/', $name)) {
            throw new \InvalidArgumentException("Invalid partition name: {$name}");
        }
    }

    /**
     * Миграция: добавить поле unique_id в существующую таблицу physical_ads
     */
    private function migrateUniqueId(): void
    {
        // Проверяем, существует ли колонка unique_id
        try {
            $columns = $this->pdo->query("PRAGMA table_info(physical_ads)")->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_map('strtolower', array_column($columns, 'name'));
            if (in_array('unique_id', $columnNames, true)) {
                return; // Поле уже существует
            }
        } catch (\PDOException $e) {
            // Таблица может не существовать — игнорируем
            return;
        }

        // Добавляем колонку (тихо, без предупреждений)
        try {
            $this->pdo->exec("ALTER TABLE physical_ads ADD COLUMN unique_id TEXT");
        } catch (\PDOException $e) {
            // Игнорируем ошибку дублирования
        }
    }

    /**
     * Миграция: добавить колонки для полей фида
     */
    private function migrateFeedColumns(): void
    {
        $columns = $this->pdo->query("PRAGMA table_info(physical_ads)")->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map('strtolower', array_column($columns, 'name'));

        $newColumns = [
            'phone' => 'TEXT',
            'contact_method' => 'TEXT',
            'brand' => 'TEXT',
            'oem_number' => 'TEXT',
            'images' => 'TEXT',
            'title' => 'TEXT',
            'description' => 'TEXT',
            'location' => 'TEXT',
            'price' => 'INTEGER DEFAULT 0',
            'category_params' => 'TEXT',
        ];

        foreach ($newColumns as $name => $type) {
            if (in_array($name, $columnNames, true)) {
                continue; // Уже существует
            }
            try {
                $this->pdo->exec("ALTER TABLE physical_ads ADD COLUMN {$name} {$type}");
            } catch (\PDOException $e) {
                // Игнорируем ошибки дублирования
            }
        }
    }

    /**
     * Миграция: создать таблицу удалённых объявлений
     */
    private function migrateDeletedAdsTable(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS deleted_ads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    avito_id TEXT NOT NULL,
                    unique_id TEXT,
                    master_data TEXT,
                    removed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    title TEXT,
                    price INTEGER,
                    location TEXT
                )
            ");
        } catch (\PDOException $e) {
            // Игнорируем ошибки создания
        }
    }

    /**
     * Создать таблицы, если не существуют.
     *
     * Обратная совместимость: старая таблица stats сохраняется.
     * getStats() читает из обеих (старой + секций).
     */
    private function init(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS physical_ads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                logical_key TEXT NOT NULL,
                avito_id TEXT,
                unique_id TEXT,
                status TEXT NOT NULL DEFAULT 'draft',
                published_at DATETIME,
                deactivated_at DATETIME,
                old_avito_id TEXT,
                master_data TEXT
            )
        ");

        // Старая таблица stats — оставляем для обратной совместимости
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                physical_ad_id INTEGER NOT NULL,
                date TEXT NOT NULL,
                views INTEGER DEFAULT 0,
                uniq_views INTEGER DEFAULT 0,
                contacts INTEGER DEFAULT 0,
                uniq_contacts INTEGER DEFAULT 0,
                favorites INTEGER DEFAULT 0,
                uniq_favorites INTEGER DEFAULT 0,
                FOREIGN KEY (physical_ad_id) REFERENCES physical_ads(id),
                UNIQUE(physical_ad_id, date)
            )
        ");

        // Метаданные секций
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS stats_meta (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                partition_name TEXT NOT NULL UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                record_count INTEGER DEFAULT 0
            )
        ");

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_physical_ads_avito_id ON physical_ads(avito_id)');

        // Миграция: добавляем unique_id если поле не существует
        $this->migrateUniqueId();

        // Миграция: добавляем колонки для полей фида
        $this->migrateFeedColumns();

        // Миграция: создаём таблицу удалённых объявлений
        $this->migrateDeletedAdsTable();

        // Индекс создаём ПОСЛЕ миграции
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_physical_ads_unique_id ON physical_ads(unique_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_stats_physical_ad_date ON stats(physical_ad_id, date)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_stats_meta_partition ON stats_meta(partition_name)');

        // Инициализация таблиц кандидатов
        $this->initCandidatesTables();
    }

    /**
     * Создать таблицы кандидатов, если не существуют.
     */
    private function initCandidatesTables(): void
    {
        // candidates_meta — аналог stats_meta для кандидатов
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS candidates_meta (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                partition_name TEXT NOT NULL UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                record_count INTEGER DEFAULT 0
            )
        ");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_candidates_meta_partition ON candidates_meta(partition_name)');

        // Создаём секцию для текущего месяца
        $currentPartition = $this->getCandidatePartitionName(date('Y-m-d'));
        $this->ensureCandidatePartitionExists($currentPartition);
    }

    /**
     * Получить активные объявления
     */
    public function getActive(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM physical_ads WHERE status = 'active'");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить все объявления
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM physical_ads");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить по logical_key
     */
    public function getByLogicalKey(string $logicalKey): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM physical_ads WHERE logical_key = :key");
        $stmt->execute([':key' => $logicalKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить по avito_id
     */
    public function getByAvitoId(string $avitoId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM physical_ads WHERE avito_id = :id");
        $stmt->execute([':id' => $avitoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row ?: null;
    }

    /** Получить физическое объявление по локальному ID. */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM physical_ads WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row ?: null;
    }

    /** Получить по unique_id (из Avito AutoLoad). */
    public function getByUniqueId(string $uniqueId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM physical_ads WHERE unique_id = :id");
        $stmt->execute([':id' => $uniqueId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row ?: null;
    }

    /**
     * Создать новое физическое объявление
     */
    public function createPhysical(string $logicalKey, array $masterData = [], string $status = 'active'): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO physical_ads (logical_key, status, master_data)
            VALUES (:key, :status, :master_data)
        ");
        $stmt->execute([
            ':key' => $logicalKey,
            ':status' => $status,
            ':master_data' => json_encode($masterData),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Обновить статус и данные объявления
     */
    public function updatePhysical(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['status'])) {
            $fields[] = "status = :status";
            $params[':status'] = $data['status'];
        }
        if (isset($data['avito_id'])) {
            $fields[] = "avito_id = :avito_id";
            $params[':avito_id'] = $data['avito_id'];
        }
        if (isset($data['unique_id'])) {
            $fields[] = "unique_id = :unique_id";
            $params[':unique_id'] = $data['unique_id'];
        }
        if (isset($data['published_at'])) {
            $fields[] = "published_at = :published_at";
            $params[':published_at'] = $data['published_at'];
        }
        if (isset($data['deactivated_at'])) {
            $fields[] = "deactivated_at = :deactivated_at";
            $params[':deactivated_at'] = $data['deactivated_at'];
        }
        if (isset($data['old_avito_id'])) {
            $fields[] = "old_avito_id = :old_avito_id";
            $params[':old_avito_id'] = $data['old_avito_id'];
        }
        if (isset($data['master_data'])) {
            $fields[] = "master_data = :master_data";
            $params[':master_data'] = json_encode($data['master_data']);
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = :phone";
            $params[':phone'] = $data['phone'];
        }
        if (isset($data['contact_method'])) {
            $fields[] = "contact_method = :contact_method";
            $params[':contact_method'] = $data['contact_method'];
        }
        if (isset($data['brand'])) {
            $fields[] = "brand = :brand";
            $params[':brand'] = $data['brand'];
        }
        if (isset($data['oem_number'])) {
            $fields[] = "oem_number = :oem_number";
            $params[':oem_number'] = $data['oem_number'];
        }
        if (isset($data['images'])) {
            $fields[] = "images = :images";
            $params[':images'] = $data['images'];
        }
        if (isset($data['title'])) {
            $fields[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['description'])) {
            $fields[] = "description = :description";
            $params[':description'] = $data['description'];
        }
        if (isset($data['location'])) {
            $fields[] = "location = :location";
            $params[':location'] = $data['location'];
        }
        if (isset($data['price'])) {
            $fields[] = "price = :price";
            $params[':price'] = (int) $data['price'];
        }
        if (isset($data['category_params'])) {
            $fields[] = "category_params = :category_params";
            $params[':category_params'] = $data['category_params'];
        }

        if (empty($fields)) {
            return true;
        }

        $sql = "UPDATE physical_ads SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Синхронизация с API: сравнение сайта vs БД
     *
     * @return array{created: int, updated: int, removed: int, removed_ids: list<string>}
     */
    public function syncFromApi(array $items): array
    {
        $this->pdo->beginTransaction();
        try {
            // 1. Получаем все avito_id из API
            $apiIds = [];
            foreach ($items as $item) {
                $apiIds[(string) ($item['id'] ?? '')] = $item;
            }

            // 2. Получаем все avito_id из БД (active + low_perf + old)
            $dbAds = $this->pdo->query("SELECT avito_id, status FROM physical_ads WHERE status IN ('active', 'low_perf', 'old')")->fetchAll(PDO::FETCH_ASSOC);
            $dbIds = [];
            foreach ($dbAds as $ad) {
                $dbIds[(string) ($ad['avito_id'] ?? '')] = $ad['status'] ?? '';
            }

            // 3. Находим удалённые объявления (есть в БД, нет на сайте)
            $removedIds = [];
            foreach ($dbIds as $avitoId => $status) {
                if (!isset($apiIds[$avitoId])) {
                    // Удаляем из БД и переносим в deleted_ads
                    $existing = $this->getByAvitoId($avitoId);
                    if ($existing !== null) {
                        $physicalAdId = (int) $existing['id'];
                        $masterData = $existing['master_data'] ? json_decode($existing['master_data'], true) : [];
                        
                        // Сначала удаляем связанные записи статистики (FK constraint)
                        $this->deleteStatsForAd($physicalAdId);

                        $stmt = $this->pdo->prepare("
                            INSERT INTO deleted_ads (avito_id, unique_id, master_data, removed_at, title, price, location)
                            VALUES (:avito_id, :unique_id, :master_data, :removed_at, :title, :price, :location)
                        ");
                        $stmt->execute([
                            ':avito_id' => $avitoId,
                            ':unique_id' => $existing['unique_id'] ?? null,
                            ':master_data' => $existing['master_data'] ?? null,
                            ':removed_at' => date('Y-m-d H:i:s'),
                            ':title' => $masterData['title'] ?? '',
                            ':price' => $masterData['price'] ?? 0,
                            ':location' => $masterData['location'] ?? '',
                        ]);

                        // Удаляем из основной таблицы
                        $this->pdo->prepare("DELETE FROM physical_ads WHERE avito_id = :avito_id")
                            ->execute([':avito_id' => $avitoId]);
                        
                        $removedIds[] = $avitoId;
                    }
                }
            }

            // 4. Обновляем/создаём объявления
            $created = 0;
            $updated = 0;
            $createdIds = [];
            foreach ($apiIds as $avitoId => $item) {
                $existing = $this->getByAvitoId($avitoId);
                if ($existing !== null) {
                    // Обновляем
                    $this->upsertFromApiItem($item);
                    $updated++;
                } else {
                    // Создаём новое
                    $this->upsertFromApiItem($item);
                    $created++;
                    $createdIds[] = $avitoId;
                }
            }

            $this->pdo->commit();
            return [
                'created' => $created,
                'updated' => $updated,
                'removed' => count($removedIds),
                'removed_ids' => $removedIds,
                'created_ids' => $createdIds,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Обновить детали объявлений из API (детальный запрос)
     *
     * Заполняет поля которые НЕ приходят из /core/v1/items:
     * contact_block, brand, oem_number, category_params, description, images
     *
     * @param list<int> $itemIds
     * @param callable $fetchItem callback(int $itemId): ?array
     * @param float $delayBetweenRequests Задержка между запросами в секундах (по умолчанию 0.15 = 400 req/min)
     */
    public function syncDetailsFromApi(array $itemIds, callable $fetchItem, float $delayBetweenRequests = 0.15): int
    {
        if ($itemIds === []) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $updated = 0;
            foreach ($itemIds as $avitoId) {
                // Получаем детальную информацию
                $detail = $fetchItem($avitoId);
                if ($detail === null || !isset($detail['id'])) {
                    if ($delayBetweenRequests > 0) {
                        usleep((int) ($delayBetweenRequests * 1000000));
                    }
                    continue;
                }

                $existing = $this->getByAvitoId((string) $avitoId);
                if ($existing === null) {
                    continue;
                }

                // Извлекаем данные из detail
                $phone = '';
                $contactMethod = '';
                if (isset($detail['contact_block']) && is_array($detail['contact_block'])) {
                    $phone = (string) ($detail['contact_block']['phone'] ?? '');
                    $call = $detail['contact_block']['call'] ?? [];
                    $message = $detail['contact_block']['message'] ?? [];
                    $methods = [];
                    if (!empty($call)) $methods[] = 'по телефону';
                    if (!empty($message)) $methods[] = 'в сообщениях';
                    if ($methods !== []) {
                        $contactMethod = implode(' и ', $methods);
                    }
                }

                $brand = '';
                if (isset($detail['brand']) && is_array($detail['brand'])) {
                    $brand = (string) ($detail['brand']['name'] ?? $detail['brand']['id'] ?? '');
                } elseif (isset($detail['brand'])) {
                    $brand = (string) $detail['brand'];
                }

                $oemNumber = (string) ($detail['oem_number'] ?? '');

                $categoryParams = [];
                if (isset($detail['category_params']) && is_array($detail['category_params'])) {
                    foreach ($detail['category_params'] as $param) {
                        if (is_array($param)) {
                            $name = (string) ($param['name'] ?? '');
                            $value = (string) ($param['value'] ?? '');
                            if ($name !== '' && $value !== '') {
                                $categoryParams[$name] = $value;
                            }
                        }
                    }
                }
                $categoryParamsJson = $categoryParams !== [] ? json_encode($categoryParams, JSON_UNESCAPED_UNICODE) : '';

                $description = '';
                if (isset($detail['description'])) {
                    $description = strip_tags($detail['description']);
                }

                $images = [];
                if (isset($detail['images']) && is_array($detail['images'])) {
                    foreach ($detail['images'] as $img) {
                        if (is_array($img)) {
                            $url = $img['url'] ?? $img['thumb_url'] ?? '';
                            if ($url !== '') {
                                $images[] = $url;
                            }
                        }
                    }
                }
                $imagesJson = $images !== [] ? json_encode($images, JSON_UNESCAPED_UNICODE) : '';

                // Обновляем только если есть данные
                $fields = [];
                $params = [':id' => (int) $existing['id']];

                if ($phone !== '') {
                    $fields[] = "phone = :phone";
                    $params[':phone'] = $phone;
                }
                if ($contactMethod !== '') {
                    $fields[] = "contact_method = :contact_method";
                    $params[':contact_method'] = $contactMethod;
                }
                if ($brand !== '') {
                    $fields[] = "brand = :brand";
                    $params[':brand'] = $brand;
                }
                if ($oemNumber !== '') {
                    $fields[] = "oem_number = :oem_number";
                    $params[':oem_number'] = $oemNumber;
                }
                if ($categoryParamsJson !== '') {
                    $fields[] = "category_params = :category_params";
                    $params[':category_params'] = $categoryParamsJson;
                }
                if ($description !== '') {
                    $fields[] = "description = :description";
                    $params[':description'] = $description;
                }
                if ($imagesJson !== '') {
                    $fields[] = "images = :images";
                    $params[':images'] = $imagesJson;
                }

                if ($fields !== []) {
                    $sql = "UPDATE physical_ads SET " . implode(', ', $fields) . " WHERE id = :id";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    $updated++;
                }

                // Задержка между запросами к API
                if ($delayBetweenRequests > 0) {
                    usleep((int) ($delayBetweenRequests * 1000000));
                }
            }
            $this->pdo->commit();
            return $updated;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Создать или обновить локальную запись по данным списка объявлений Avito.
     *
     * @return bool true, если создана новая запись
     */
    public function upsertFromApiItem(array $item): bool
    {
        $avitoId = trim((string) ($item['id'] ?? ''));
        if ($avitoId === '') {
            throw new \InvalidArgumentException('Avito item must contain id.');
        }

        $uniqueId = trim((string) ($item['uniqueId'] ?? ''));
        $existing = $this->getByAvitoId($avitoId);
        $status = (string) ($item['status'] ?? 'active');
        $publishedAt = $this->formatApiDate($item['created_at'] ?? null);
        $logicalKey = 'avito:' . ((string) ($item['number'] ?? '' ?: $avitoId));

        // Extract price (handle nested amount/currency fields)
        $price = null;
        if (is_array($item['price'] ?? null)) {
            $price = (int) ($item['price']['amount'] ?? 0);
        } elseif (is_numeric($item['price'] ?? null)) {
            $price = (int) $item['price'];
        }

        // Extract phone from contact_block or phone field
        $phone = '';
        if (isset($item['contact_block']) && is_array($item['contact_block'])) {
            $phone = (string) ($item['contact_block']['phone'] ?? '');
        } elseif (isset($item['phone'])) {
            $phone = (string) $item['phone'];
        }

        // Extract contact method from contact_block
        $contactMethod = '';
        if (isset($item['contact_block']) && is_array($item['contact_block'])) {
            $contactBlock = $item['contact_block'];
            $call = $contactBlock['call'] ?? [];
            $message = $contactBlock['message'] ?? [];
            $methods = [];
            if (!empty($call)) $methods[] = 'по телефону';
            if (!empty($message)) $methods[] = 'в сообщениях';
            if ($methods !== []) {
                $contactMethod = implode(' и ', $methods);
            }
        }

        // Extract brand/manufacturer
        $brand = '';
        $oemNumber = '';
        if (isset($item['brand']) && is_array($item['brand'])) {
            $brand = (string) ($item['brand']['name'] ?? $item['brand']['id'] ?? '');
        } elseif (isset($item['brand'])) {
            $brand = (string) $item['brand'];
        }
        if (isset($item['oem_number'])) {
            $oemNumber = (string) $item['oem_number'];
        }

        // Extract category parameters (вид товара, вид объявления, тип товара, etc.)
        $categoryParams = [];
        if (isset($item['category_params']) && is_array($item['category_params'])) {
            foreach ($item['category_params'] as $param) {
                if (is_array($param)) {
                    $paramName = (string) ($param['name'] ?? '');
                    $paramValue = (string) ($param['value'] ?? '');
                    if ($paramName !== '' && $paramValue !== '') {
                        $categoryParams[$paramName] = $paramValue;
                    }
                }
            }
        }

        // Extract location details
        $location = $item['location'] ?? ($item['address'] ?? null);
        $address = $item['address'] ?? null;
        $latitude = '';
        $longitude = '';
        if (isset($item['coordinates']) && is_array($item['coordinates'])) {
            $latitude = (string) ($item['coordinates']['latitude'] ?? '');
            $longitude = (string) ($item['coordinates']['longitude'] ?? '');
        }

        $masterData = [
            // Basic fields
            'title' => (string) ($item['title'] ?? ''),
            'number' => (string) ($item['number'] ?? ''),
            'price' => $price,
            'category' => $item['category'] ?? [],
            'location' => $location,
            'address' => $address,
            'url' => $item['url'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'unique_id' => $uniqueId,
            // Contact fields
            'phone' => $phone,
            'contact_method' => $contactMethod,
            // Product fields
            'brand' => $brand,
            'oem_number' => $oemNumber,
            // Coordinates
            'latitude' => $latitude,
            'longitude' => $longitude,
            // Category-specific parameters
            'category_params' => $categoryParams,
            // Full API response (for future use)
            '_full_api' => $item,
        ];

        // API списка (/core/v1/items) не возвращает "number" — только "id".
        // Сохраняем id в master_data как fallback.
        if ($masterData['number'] === '') {
            $masterData['number'] = $avitoId;
        }

        // Images — сохраняем как JSON array
        $images = [];
        if (isset($item['images']) && is_array($item['images'])) {
            foreach ($item['images'] as $img) {
                if (is_array($img)) {
                    $url = $img['url'] ?? $img['thumb_url'] ?? '';
                    if ($url !== '') {
                        $images[] = $url;
                    }
                }
            }
        }
        $imagesJson = $images !== [] ? json_encode($images, JSON_UNESCAPED_UNICODE) : '';

        // Category params — сохраняем как JSON
        $categoryParamsJson = $categoryParams !== [] ? json_encode($categoryParams, JSON_UNESCAPED_UNICODE) : '';

        // Description — извлекаем из _full_api
        $description = '';
        if (isset($item['description']) && is_string($item['description'])) {
            $description = strip_tags($item['description']);
        }

        if ($existing !== null) {
            $this->updatePhysical((int) $existing['id'], [
                'status' => $status,
                'published_at' => $existing['published_at'] ?: $publishedAt,
                'master_data' => $masterData,
                'unique_id' => $uniqueId,
                'phone' => $phone,
                'contact_method' => $contactMethod,
                'brand' => $brand,
                'oem_number' => $oemNumber,
                'images' => $imagesJson,
                'title' => (string) ($item['title'] ?? ''),
                'description' => $description,
                'location' => (string) $location,
                'price' => $price ?? 0,
                'category_params' => $categoryParamsJson,
            ]);
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO physical_ads (logical_key, avito_id, unique_id, status, published_at, master_data, '
            . 'phone, contact_method, brand, oem_number, images, title, description, location, price, category_params) '
            . 'VALUES (:logical_key, :avito_id, :unique_id, :status, :published_at, :master_data, '
            . ':phone, :contact_method, :brand, :oem_number, :images, :title, :description, :location, :price, :category_params)'
        );
        $stmt->execute([
            ':logical_key' => $logicalKey,
            ':avito_id' => $avitoId,
            ':unique_id' => $uniqueId,
            ':status' => $status,
            ':published_at' => $publishedAt,
            ':master_data' => json_encode($masterData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':phone' => $phone,
            ':contact_method' => $contactMethod,
            ':brand' => $brand,
            ':oem_number' => $oemNumber,
            ':images' => $imagesJson,
            ':title' => (string) ($item['title'] ?? ''),
            ':description' => $description,
            ':location' => (string) $location,
            ':price' => $price ?? 0,
            ':category_params' => $categoryParamsJson,
        ]);

        return true;
    }

    /**
     * Сохранить статистику для объявления в секцию по месяцу.
     *
     * @param int   $physicalAdId ID объявления в physical_ads
     * @param array $stats        массив ['date' => 'Y-m-d', ...]
     * @param int   $price        текущая цена объявления
     */
    public function saveStats(int $physicalAdId, array $stats, int $price = 0): void
    {
        // Группируем записи по месяцам
        $byPartition = [];
        foreach ($stats as $stat) {
            $date = $stat['date'] ?? '';
            if ($date === '') {
                continue;
            }
            $partitionName = $this->getPartitionName($date);
            $byPartition[$partitionName][] = $stat;
        }

        foreach ($byPartition as $partitionName => $partitionStats) {
            $this->ensurePartitionExists($partitionName);

            $stmt = $this->pdo->prepare("
                INSERT INTO {$partitionName}
                (physical_ad_id, date, views, uniq_views, contacts, uniq_contacts,
                 favorites, uniq_favorites, phone_shows, chats, price)
                VALUES (:pad_id, :date, :views, :uniq_views, :contacts, :uniq_contacts,
                        :favorites, :uniq_favorites, :phone_shows, :chats, :price)
                ON CONFLICT(physical_ad_id, date) DO UPDATE SET
                    views = excluded.views,
                    uniq_views = excluded.uniq_views,
                    contacts = excluded.contacts,
                    uniq_contacts = excluded.uniq_contacts,
                    favorites = excluded.favorites,
                    uniq_favorites = excluded.uniq_favorites,
                    phone_shows = excluded.phone_shows,
                    chats = excluded.chats,
                    price = excluded.price
            ");

            foreach ($partitionStats as $stat) {
                $stmt->execute([
                    ':pad_id' => $physicalAdId,
                    ':date' => $stat['date'],
                    ':views' => (int) ($stat['views'] ?? 0),
                    ':uniq_views' => (int) ($stat['uniqViews'] ?? 0),
                    ':contacts' => (int) ($stat['contacts'] ?? 0),
                    ':uniq_contacts' => (int) ($stat['uniqContacts'] ?? 0),
                    ':favorites' => (int) ($stat['favorites'] ?? 0),
                    ':uniq_favorites' => (int) ($stat['uniqFavorites'] ?? 0),
                    ':phone_shows' => (int) ($stat['contactsShowPhone'] ?? 0),
                    ':chats' => (int) ($stat['contactsMessenger'] ?? 0),
                    ':price' => $price,
                ]);
            }

            // Обновляем record_count в stats_meta (общий count для секции)
            $count = $this->pdo->query(
                "SELECT COUNT(*) FROM {$partitionName}"
            )->fetchColumn();
            $this->pdo->prepare(
                "UPDATE stats_meta SET record_count = :cnt WHERE partition_name = :name"
            )->execute([':cnt' => (int) $count, ':name' => $partitionName]);
        }
    }

    /**
     * Получить статистику для объявления.
     *
     * Читает из секций + старой таблицы stats для обратной совместимости.
     */
    public function getStats(int $physicalAdId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $allStats = [];

        // Читаем из секций
        if ($dateFrom !== null && $dateTo !== null) {
            $partitions = $this->getPartitionNamesForPeriod($dateFrom, $dateTo);
        } else {
            $stmt = $this->pdo->query("SELECT partition_name FROM stats_meta ORDER BY partition_name");
            $partitions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($partitions as $partition) {
            // Валидация имени партиции — защита от SQL injection
            try {
                $this->validatePartitionName($partition);
            } catch (\InvalidArgumentException $e) {
                fwrite(STDERR, "  [WARN] Skipping invalid partition: {$partition}\n");
                continue;
            }

            // Проверяем существование таблицы (могла быть удалена)
            $exists = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partition}'"
            )->fetchColumn();
            if ((int) $exists === 0) {
                continue;
            }

            $sql = "SELECT * FROM {$partition} WHERE physical_ad_id = :id";
            $params = ['id' => $physicalAdId];

            if ($dateFrom !== null) {
                $sql .= " AND date >= :dateFrom";
                $params['dateFrom'] = $dateFrom;
            }
            if ($dateTo !== null) {
                $sql .= " AND date <= :dateTo";
                $params['dateTo'] = $dateTo;
            }

            $sql .= " ORDER BY date";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $allStats = array_merge($allStats, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // Обратная совместимость: читаем из старой таблицы stats
        $oldStats = $this->getOldStats($physicalAdId, $dateFrom, $dateTo);
        if ($oldStats !== []) {
            $allStats = array_merge($allStats, $oldStats);
        }

        // Убираем дубликаты (по date), оставляем последние
        $unique = [];
        foreach ($allStats as $stat) {
            $key = $stat['date'] ?? '';
            if ($key !== '') {
                $unique[$key] = $stat;
            }
        }
        ksort($unique);

        return array_values($unique);
    }

    /**
     * Получить статистику за последние N дней.
     *
     * Читает из секций + старой таблицы stats для обратной совместимости.
     */
    public function getStatsForLastDays(int $physicalAdId, int $days): array
    {
        $dateTo = date('Y-m-d', strtotime('-1 day'));
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        return $this->getStats($physicalAdId, $dateFrom, $dateTo);
    }

    /**
     * Получить статистику из старой таблицы stats (обратная совместимость).
     */
    private function getOldStats(int $physicalAdId, ?string $dateFrom, ?string $dateTo): array
    {
        $sql = "SELECT * FROM stats WHERE physical_ad_id = :id";
        $params = ['id' => $physicalAdId];

        if ($dateFrom !== null) {
            $sql .= " AND date >= :dateFrom";
            $params['dateFrom'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $sql .= " AND date <= :dateTo";
            $params['dateTo'] = $dateTo;
        }

        $sql .= " ORDER BY date";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Определить имя секции по дате.
     */
    public function getPartitionName(string $date): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            // Fallback: берём из первой части строки
            $parts = explode('-', substr($date, 0, 10));
            $year = $parts[0] ?? date('Y');
            $month = $parts[1] ?? date('m');
            return sprintf('statistics_%s_%s', $year, $month);
        }
        return sprintf('statistics_%s_%s', $dt->format('Y'), $dt->format('m'));
    }

    /**
     * Убедиться, что секция существует, создать если нет.
     */
    public function ensurePartitionExists(string $partitionName): void
    {
        // Валидация имени партиции — защита от SQL injection
        $this->validatePartitionName($partitionName);

        // Проверяем, существует ли таблица
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partitionName}'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            $this->pdo->exec("
                CREATE TABLE {$partitionName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    physical_ad_id INTEGER NOT NULL,
                    date TEXT NOT NULL,
                    views INTEGER DEFAULT 0,
                    uniq_views INTEGER DEFAULT 0,
                    contacts INTEGER DEFAULT 0,
                    uniq_contacts INTEGER DEFAULT 0,
                    favorites INTEGER DEFAULT 0,
                    uniq_favorites INTEGER DEFAULT 0,
                    phone_shows INTEGER DEFAULT 0,
                    chats INTEGER DEFAULT 0,
                    price INTEGER DEFAULT 0,
                    UNIQUE(physical_ad_id, date)
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$partitionName}_partition ON {$partitionName}(physical_ad_id, date)");

            // Запись в stats_meta
            $this->pdo->prepare(
                "INSERT OR IGNORE INTO stats_meta (partition_name) VALUES (:name)"
            )->execute([':name' => $partitionName]);
        }
    }

    /**
     * Получить список имён секций для периода.
     */
    public function getPartitionNamesForPeriod(string $dateFrom, string $dateTo): array
    {
        $chunks = $this->splitPeriodIntoMonths($dateFrom, $dateTo);
        $partitions = [];
        foreach ($chunks as [$chunkFrom, $chunkTo]) {
            $fromDt = \DateTimeImmutable::createFromFormat('Y-m-d', $chunkFrom);
            $toDt = \DateTimeImmutable::createFromFormat('Y-m-d', $chunkTo);
            if ($fromDt === false || $toDt === false) {
                continue;
            }
            $current = clone $fromDt;
            while ($current <= $toDt) {
                $name = 'statistics_' . $current->format('Y_m');
                if (!in_array($name, $partitions, true)) {
                    $partitions[] = $name;
                }
                $current = $current->modify('first day of next month');
            }
        }
        return $partitions;
    }

    /**
     * Разбить период на чанки по месяцам.
     *
     * @return list<array{0:string, 1:string}>
     */
    private function splitPeriodIntoMonths(string $dateFrom, string $dateTo): array
    {
        $chunks = [];
        $current = new \DateTimeImmutable($dateFrom);
        $end = new \DateTimeImmutable($dateTo);

        while ($current <= $end) {
            $lastDay = (clone $current)->modify('last day of this month');
            $chunkEnd = $lastDay < $end ? $lastDay : $end;
            $chunks[] = [$current->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
            $current = $chunkEnd->modify('+1 day');
        }

        return $chunks;
    }

    /**
     * Миграция данных из старой таблицы stats в секции.
     *
     * @return array{migrated: int, partitions: int, errors: int}
     */
    public function migrateOldStats(): array
    {
        // Проверяем, существует ли старая таблица
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='stats'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            return ['migrated' => 0, 'partitions' => 0, 'errors' => 0];
        }

        $stmt = $this->pdo->query("
            SELECT physical_ad_id, date, views, uniq_views, contacts, uniq_contacts, favorites, uniq_favorites
            FROM stats ORDER BY date
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $migrated = 0;
        $partitions = [];
        $errors = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $partitionName = $this->getPartitionName($row['date']);
                if (!isset($partitions[$partitionName])) {
                    $partitions[$partitionName] = 0;
                }

                $this->ensurePartitionExists($partitionName);

                $sql = "INSERT OR IGNORE INTO {$partitionName}
                    (physical_ad_id, date, views, uniq_views, contacts, uniq_contacts, favorites, uniq_favorites)
                    VALUES (:pad_id, :date, :views, :uniq_views, :contacts, :uniq_contacts, :favorites, :uniq_favorites)";

                try {
                    $this->pdo->prepare($sql)->execute([
                        ':pad_id' => (int) $row['physical_ad_id'],
                        ':date' => $row['date'],
                        ':views' => (int) ($row['views'] ?? 0),
                        ':uniq_views' => (int) ($row['uniq_views'] ?? 0),
                        ':contacts' => (int) ($row['contacts'] ?? 0),
                        ':uniq_contacts' => (int) ($row['uniq_contacts'] ?? 0),
                        ':favorites' => (int) ($row['favorites'] ?? 0),
                        ':uniq_favorites' => (int) ($row['uniq_favorites'] ?? 0),
                    ]);
                    $migrated++;
                    $partitions[$partitionName]++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            // Обновляем record_count в stats_meta
            foreach ($partitions as $partitionName => $count) {
                $this->pdo->prepare(
                    "UPDATE stats_meta SET record_count = :cnt WHERE partition_name = :name"
                )->execute([':cnt' => $count, ':name' => $partitionName]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'migrated' => $migrated,
            'partitions' => count($partitions),
            'errors' => $errors,
        ];
    }

    /**
     * Получить статистику для объявления из конкретной секции.
     */
    public function getStatsForAdInPartition(int $adId, int $year, int $month): array
    {
        $partitionName = sprintf('statistics_%04d_%02d', $year, $month);
        $this->validatePartitionName($partitionName);

        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partitionName}'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM {$partitionName}
            WHERE physical_ad_id = :id
            ORDER BY date
        ");
        $stmt->execute([':id' => $adId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function formatApiDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Получить дневной счётчик републикаций
     */
    public function getDailyRepubCount(string $today): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM physical_ads
            WHERE old_avito_id IS NOT NULL
            AND deactivated_at LIKE :date
        ");
        $stmt->execute([':date' => $today . '%']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Записать републикацию
     */
    public function logRepub(int $physicalAdId, string $oldAvitoId): void
    {
        $this->updatePhysical($physicalAdId, [
            'old_avito_id' => $oldAvitoId,
        ]);
    }

    // ==================== Candidates (republish module) ====================

    /**
     * Получить имя секции кандидатов по дате.
     */
    public function getCandidatePartitionName(string $date): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            $parts = explode('-', substr($date, 0, 10));
            $year = $parts[0] ?? date('Y');
            $month = $parts[1] ?? date('m');
            return sprintf('republish_candidates_%s_%s', $year, $month);
        }
        return sprintf('republish_candidates_%s_%s', $dt->format('Y'), $dt->format('m'));
    }

    /**
     * Убедиться, что секция кандидатов существует, создать если нет.
     */
    public function ensureCandidatePartitionExists(string $partitionName): void
    {
        // Валидация имени партиции — защита от SQL injection
        $this->validatePartitionName($partitionName);

        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partitionName}'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            $this->pdo->exec("
                CREATE TABLE {$partitionName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    physical_ad_id INTEGER NOT NULL,
                    avito_id TEXT NOT NULL,
                    logical_key TEXT NOT NULL,
                    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(physical_ad_id)
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$partitionName}_physical_ad ON {$partitionName}(physical_ad_id)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$partitionName}_avito ON {$partitionName}(avito_id)");

            // Запись в candidates_meta
            $this->pdo->prepare(
                "INSERT OR IGNORE INTO candidates_meta (partition_name) VALUES (:name)"
            )->execute([':name' => $partitionName]);
        }
    }

    /**
     * Найти объявления-кандидаты с суммарно 0 просмотров за N дней.
     *
     * @return list<array{
     *     id: int,
     *     logical_key: string,
     *     avito_id: string,
     *     published_at: string,
     *     master_data: string
     * }>
     */
    public function findZeroViewCandidates(int $days = 4): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
        $dateTo = date('Y-m-d', strtotime('-1 day'));

        // Получаем список секций статистики за период
        $partitions = $this->getPartitionNamesForPeriod($dateFrom, $dateTo);

        if (empty($partitions)) {
            return [];
        }

        // Строим UNION ALL запрос для суммирования uniq_views из всех секций.
        // Примечание: Avito API возвращает views=0 всегда, реальные данные — в uniqViews.
        // Поэтому для определения «нулевых» объявлений используем uniq_views.
        $unionParts = [];
        foreach ($partitions as $partition) {
            $unionParts[] = "SELECT uniq_views FROM {$partition} WHERE physical_ad_id = pa.id AND date >= :dateFrom AND date <= :dateTo";
        }
        $unionSql = implode(' UNION ALL ', $unionParts);

        $sql = "
            SELECT pa.*
            FROM physical_ads pa
            WHERE pa.status = 'active'
              AND (
                  SELECT COALESCE(SUM(s.uniq_views), 0)
                  FROM ({$unionSql}) s
              ) = 0
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':dateFrom' => $dateFrom,
            ':dateTo' => $dateTo,
        ]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Приводим id к int
        return array_map(function ($row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            return $row;
        }, $results);
    }

    /**
     * Добавить кандидата в таблицу (секция по текущему месяцу).
     */
    public function addCandidate(int $physicalAdId, string $avitoId, string $logicalKey): bool
    {
        $partitionName = $this->getCandidatePartitionName(date('Y-m-d'));
        $this->ensureCandidatePartitionExists($partitionName);

        $sql = "INSERT OR IGNORE INTO {$partitionName}
            (physical_ad_id, avito_id, logical_key)
            VALUES (:pad_id, :avito_id, :logical_key)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':pad_id' => $physicalAdId,
            ':avito_id' => $avitoId,
            ':logical_key' => $logicalKey,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Удалить кандидата из всех секций.
     */
    public function removeCandidate(int $physicalAdId): void
    {
        // Получаем все секции кандидатов
        $stmt = $this->pdo->query("SELECT partition_name FROM candidates_meta ORDER BY partition_name");
        $partitions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($partitions as $partition) {
            // Валидация имени партиции
            try {
                $this->validatePartitionName($partition);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $exists = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partition}'"
            )->fetchColumn();

            if ((int) $exists > 0) {
                $this->pdo->prepare("DELETE FROM {$partition} WHERE physical_ad_id = :id")
                    ->execute([':id' => $physicalAdId]);
            }
        }
    }

    /**
     * Получить всех кандидатов из всех секций.
     *
     * @return list<array{
     *     id: int,
     *     physical_ad_id: int,
     *     avito_id: string,
     *     logical_key: string,
     *     added_at: string,
     *     unique_id: string
     * }>
     */
    public function getCandidates(): array
    {
        $stmt = $this->pdo->query("SELECT partition_name FROM candidates_meta ORDER BY partition_name");
        $partitions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $allCandidates = [];
        foreach ($partitions as $partition) {
            // Валидация имени партиции
            try {
                $this->validatePartitionName($partition);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $exists = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partition}'"
            )->fetchColumn();

            if ((int) $exists === 0) {
                continue;
            }

            // JOIN с physical_ads для получения unique_id
            $sql = "SELECT c.*, p.unique_id 
                    FROM {$partition} c 
                    LEFT JOIN physical_ads p ON c.physical_ad_id = p.id 
                    ORDER BY c.added_at DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $allCandidates = array_merge($allCandidates, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        return $allCandidates;
    }

    /**
     * Подсчёт кандидатов за месяц.
     */
    public function getCandidateCountForMonth(int $year, int $month): int
    {
        $partitionName = sprintf('republish_candidates_%04d_%02d', $year, $month);
        $this->validatePartitionName($partitionName);

        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partitionName}'"
        )->fetchColumn();

        if ((int) $exists === 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$partitionName}");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Удалить все связанные записи статистики для объявления (старая таблица + partition tables)
     * Вызывается перед удалением объявления из physical_ads для обхода FK constraint.
     */
    private function deleteStatsForAd(int $physicalAdId): void
    {
        // Удаляем из старой таблицы stats
        $this->pdo->prepare("DELETE FROM stats WHERE physical_ad_id = :id")
            ->execute([':id' => $physicalAdId]);

        // Удаляем из stats_old (тоже имеет FK)
        $this->pdo->prepare("DELETE FROM stats_old WHERE physical_ad_id = :id")
            ->execute([':id' => $physicalAdId]);

        // Удаляем из всех partition таблиц статистики
        $stmt = $this->pdo->query("SELECT partition_name FROM stats_meta ORDER BY partition_name");
        $partitions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($partitions as $partition) {
            try {
                $this->validatePartitionName($partition);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $exists = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partition}'"
            )->fetchColumn();

            if ((int) $exists > 0) {
                $this->pdo->prepare("DELETE FROM {$partition} WHERE physical_ad_id = :id")
                    ->execute([':id' => $physicalAdId]);
            }
        }

        // Удаляем из partition таблиц кандидатов
        $stmt = $this->pdo->query("SELECT partition_name FROM candidates_meta ORDER BY partition_name");
        $candidatePartitions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($candidatePartitions as $partition) {
            try {
                $this->validatePartitionName($partition);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            $exists = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partition}'"
            )->fetchColumn();

            if ((int) $exists > 0) {
                $this->pdo->prepare("DELETE FROM {$partition} WHERE physical_ad_id = :id")
                    ->execute([':id' => $physicalAdId]);
            }
        }
    }
}
