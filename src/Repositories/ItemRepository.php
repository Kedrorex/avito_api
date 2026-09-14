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
        $logicalKey = 'avito:' . ((string) ($item['number'] ?? '' ?: $avitoId));
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
                $name = $current->format('statistics_Y_m');
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

        // Строим UNION ALL запрос для суммирования views из всех секций
        $unionParts = [];
        foreach ($partitions as $partition) {
            $unionParts[] = "SELECT views FROM {$partition} WHERE physical_ad_id = pa.id AND date >= :dateFrom AND date <= :dateTo";
        }
        $unionSql = implode(' UNION ALL ', $unionParts);

        $sql = "
            SELECT pa.id, pa.logical_key, pa.avito_id, pa.published_at, pa.master_data
            FROM physical_ads pa
            WHERE pa.status = 'active'
              AND pa.published_at IS NOT NULL
              AND julianday('now') - julianday(pa.published_at) <= :days
              AND (
                  SELECT COALESCE(SUM(s.views), 0)
                  FROM ({$unionSql}) s
              ) = 0
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':days' => $days,
            ':dateFrom' => $dateFrom,
            ':dateTo' => $dateTo,
        ]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Фильтруем: приводим id к int
        return array_map(function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'logical_key' => (string) ($row['logical_key'] ?? ''),
                'avito_id' => (string) ($row['avito_id'] ?? ''),
                'published_at' => (string) ($row['published_at'] ?? ''),
                'master_data' => (string) ($row['master_data'] ?? ''),
            ];
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
     *     added_at: string
     * }>
     */
    public function getCandidates(): array
    {
        $stmt = $this->pdo->query("SELECT partition_name FROM candidates_meta ORDER BY partition_name");
        $partitions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $allCandidates = [];
        foreach ($partitions as $partition) {
            $exists = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='{$partition}'"
            )->fetchColumn();

            if ((int) $exists === 0) {
                continue;
            }

            $stmt = $this->pdo->prepare("SELECT * FROM {$partition} ORDER BY added_at DESC");
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
}
