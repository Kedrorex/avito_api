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
     * Создать таблицы, если не существуют
     */
    private function init(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS physical_ads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                logical_key TEXT NOT NULL,
                avito_id TEXT,
                status TEXT NOT NULL DEFAULT 'draft',
                published_at DATETIME,
                deactivated_at DATETIME,
                old_avito_id TEXT,
                master_data TEXT
            )
        ");

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

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_physical_ads_avito_id ON physical_ads(avito_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_stats_physical_ad_date ON stats(physical_ad_id, date)');
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
        return $row ?: null;
    }

    /** Получить физическое объявление по локальному ID. */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM physical_ads WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
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

        if (empty($fields)) {
            return true;
        }

        $sql = "UPDATE physical_ads SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Синхронизация с API: обновить существующие или создать новые
     */
    public function syncFromApi(array $items): int
    {
        $created = 0;
        foreach ($items as $item) {
            if ($this->upsertFromApiItem($item)) {
                $created++;
            }
        }

        return $created;
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

        $existing = $this->getByAvitoId($avitoId);
        $status = (string) ($item['status'] ?? 'active');
        $publishedAt = $this->formatApiDate($item['created_at'] ?? null);
        $logicalKey = 'avito:' . ((string) ($item['number'] ?: $avitoId));
        $masterData = [
            'title' => (string) ($item['title'] ?? ''),
            'number' => (string) ($item['number'] ?? ''),
            'price' => $item['price'] ?? null,
            'category' => $item['category'] ?? [],
            'location' => $item['location'] ?? ($item['address'] ?? null),
            'url' => $item['url'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
        ];

        // API списка (/core/v1/items) не возвращает "number" — только "id".
        // Сохраняем id в master_data как fallback.
        if ($masterData['number'] === '') {
            $masterData['number'] = $avitoId;
        }

        if ($existing !== null) {
            $this->updatePhysical((int) $existing['id'], [
                'status' => $status,
                'published_at' => $existing['published_at'] ?: $publishedAt,
                'master_data' => $masterData,
            ]);
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO physical_ads (logical_key, avito_id, status, published_at, master_data) '
            . 'VALUES (:logical_key, :avito_id, :status, :published_at, :master_data)'
        );
        $stmt->execute([
            ':logical_key' => $logicalKey,
            ':avito_id' => $avitoId,
            ':status' => $status,
            ':published_at' => $publishedAt,
            ':master_data' => json_encode($masterData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }

    /**
     * Сохранить статистику для объявления
     */
    public function saveStats(int $physicalAdId, array $stats): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO stats
            (physical_ad_id, date, views, uniq_views, contacts, uniq_contacts, favorites, uniq_favorites)
            VALUES (:pad_id, :date, :views, :uniq_views, :contacts, :uniq_contacts, :favorites, :uniq_favorites)
            ON CONFLICT(physical_ad_id, date) DO UPDATE SET
                views = excluded.views,
                uniq_views = excluded.uniq_views,
                contacts = excluded.contacts,
                uniq_contacts = excluded.uniq_contacts,
                favorites = excluded.favorites,
                uniq_favorites = excluded.uniq_favorites
        ");

        foreach ($stats as $stat) {
            $stmt->execute([
                ':pad_id' => $physicalAdId,
                ':date' => $stat['date'],
                ':views' => (int) ($stat['views'] ?? 0),
                ':uniq_views' => (int) ($stat['uniqViews'] ?? 0),
                ':contacts' => (int) ($stat['contacts'] ?? 0),
                ':uniq_contacts' => (int) ($stat['uniqContacts'] ?? 0),
                ':favorites' => (int) ($stat['favorites'] ?? 0),
                ':uniq_favorites' => (int) ($stat['uniqFavorites'] ?? 0),
            ]);
        }
    }

    /**
     * Получить статистику для объявления
     */
    public function getStats(int $physicalAdId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM stats WHERE physical_ad_id = :id ORDER BY date
        ");
        $stmt->execute([':id' => $physicalAdId]);
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
}
