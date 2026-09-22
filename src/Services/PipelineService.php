<?php

namespace App\Services;

use App\Repositories\ItemRepository;
use App\Services\AvitoAPIClient;
use App\Services\FeedGeneratorService;
use App\Services\RepublisherService;

/**
 * Оркестратор полного пайплайна Avito
 * 
 * Объединяет все шаги в единую систему с отслеживанием статистики:
 * 1. SYNC — синхронизация с API
 * 2. IMPORT — импорт из AutoLoad CSV (только если есть новые объявления)
 * 3. STATS — сбор статистики
 * 4. CANDIDATES — поиск слабых объявлений
 * 5. FEED — генерация CSV фида
 * 
 * В конце выводит итоговый отчёт со всеми метриками.
 */
class PipelineService
{
    private ItemRepository $repository;
    private AvitoAPIClient $apiClient;
    private RepublisherService $republisher;
    private array $config;
    private string $outputDir;
    
    /**
     * Статистика пайплайна
     */
    private array $pipelineStats = [
        'new_ads' => 0,
        'updated_ads' => 0,
        'removed_ads' => 0,
        'removed_ids' => [],
        'created_ids' => [],
        'imported_ads' => 0,
        'import_skipped' => false,
        'import_reason' => '',
        'stats_days_collected' => 0,
        'stats_ads_processed' => 0,
        'candidates_found' => 0,
        'feed_generated' => false,
        'feed_file' => '',
        'feed_count' => 0,
        'stale_file_detected' => false,
        'stale_file_missing_ads' => [],
        'timing' => [
            'sync' => 0,
            'import' => 0,
            'stats' => 0,
            'candidates' => 0,
            'feed' => 0,
        ],
    ];

    public function __construct(
        ItemRepository $repository,
        AvitoAPIClient $apiClient,
        RepublisherService $republisher,
        array $config
    ) {
        $this->repository = $repository;
        $this->apiClient = $apiClient;
        $this->republisher = $republisher;
        $this->config = $config;
        $this->outputDir = $config['feed']['output_dir'] ?? __DIR__ . '/../../fid';
    }

    /**
     * Запустить полный пайплайн
     * 
     * @param bool $cli Режим CLI (true) или HTTP (false)
     * @param int $statsDays Дней для сбора статистики
     * @param string|null $autoloadCsvPath Путь к AutoLoad CSV (опционально)
     */
    public function run(bool $cli = true, int $statsDays = 3, ?string $autoloadCsvPath = null): void
    {
        echo str_repeat('=', 70) . "\n";
        echo "  Avito Pipeline — Запуск\n";
        echo str_repeat('=', 70) . "\n";
        flush();

        // Сброс статистики
        $this->pipelineStats = [
            'new_ads' => 0,
            'updated_ads' => 0,
            'removed_ads' => 0,
            'removed_ids' => [],
            'created_ids' => [],
            'imported_ads' => 0,
            'import_skipped' => false,
            'import_reason' => '',
            'stats_days_collected' => 0,
            'stats_ads_processed' => 0,
            'candidates_found' => 0,
            'feed_generated' => false,
            'feed_file' => '',
            'feed_count' => 0,
            'stale_file_detected' => false,
            'stale_file_missing_ads' => [],
            'timing' => [
                'sync' => 0,
                'import' => 0,
                'stats' => 0,
                'candidates' => 0,
                'feed' => 0,
            ],
        ];

        $pipelineStart = microtime(true);

        // Шаг 1: SYNC
        $stepStart = microtime(true);
        $this->stepSync($cli);
        $this->pipelineStats['timing']['sync'] = round(microtime(true) - $stepStart, 2);

        // Шаг 2: IMPORT (только если есть новые объявления)
        $stepStart = microtime(true);
        $this->stepImport($cli, $autoloadCsvPath);
        $this->pipelineStats['timing']['import'] = round(microtime(true) - $stepStart, 2);

        // Шаг 3: STATS
        $stepStart = microtime(true);
        $this->stepStats($cli, $statsDays);
        $this->pipelineStats['timing']['stats'] = round(microtime(true) - $stepStart, 2);

        // Шаг 4: CANDIDATES
        $stepStart = microtime(true);
        $this->stepCandidates($cli);
        $this->pipelineStats['timing']['candidates'] = round(microtime(true) - $stepStart, 2);

        // Шаг 5: FEED
        $stepStart = microtime(true);
        $this->stepFeed($cli);
        $this->pipelineStats['timing']['feed'] = round(microtime(true) - $stepStart, 2);

        // Итоговый отчёт
        $this->printSummary($cli);
    }

    /**
     * Шаг 1: SYNC — Синхронизация с API
     */
    private function stepSync(bool $cli): void
    {
        echo "\n" . str_repeat('-', 60) . "\n";
        echo "  ШАГ 1: SYNC — Синхронизация с API\n";
        echo str_repeat('-', 60) . "\n";
        flush();

        try {
            $items = $this->apiClient->getAllItems(['active'], 100, function(int $page, int $fetched, int $total): void {
                echo "    Загружено {$fetched} из {$total} (страница {$page})...\n";
                flush();
            });

            $syncResult = $this->repository->syncFromApi($items);

            $this->pipelineStats['new_ads'] = $syncResult['created'];
            $this->pipelineStats['updated_ads'] = $syncResult['updated'];
            $this->pipelineStats['removed_ads'] = $syncResult['removed'];
            $this->pipelineStats['removed_ids'] = $syncResult['removed_ids'] ?? [];
            $this->pipelineStats['created_ids'] = $syncResult['created_ids'] ?? [];

            echo "    ✓ Создано новых: {$syncResult['created']}\n";
            echo "    ✓ Обновлено: {$syncResult['updated']}\n";
            echo "    ✓ Удалено (снято с публикации): {$syncResult['removed']}\n";

            if (!empty($syncResult['removed_ids'])) {
                echo "    Удалённые avito_id: " . implode(', ', $syncResult['removed_ids']) . "\n";
            }

            // Active ads считаются в stepFeed — здесь лишний запрос к БД не нужен

        } catch (\Throwable $e) {
            echo "    [ERROR] Ошибка синхронизации: " . $e->getMessage() . "\n";
            if ($cli) {
                echo "    [ERROR] Stack trace: " . $e->getTraceAsString() . "\n";
            }
        }
    }

    /**
     * Шаг 2: IMPORT — Импорт из AutoLoad CSV
     * 
     * Логика:
     * - Импортируем только если появились новые объявления (new_ads > 0)
     * - Проверяем что файл содержит данные для НОВЫХ объявлений (защита от старых файлов)
     * - Если файл не содержит новые avito_id — предупреждаем пользователя
     */
    private function stepImport(bool $cli, ?string $autoloadCsvPath): void
    {
        // Определяем путь к файлу
        if ($autoloadCsvPath === null) {
            $autoloadCsvPath = $this->config['feed']['autoload_source'] ?? __DIR__ . '/../../fid/Рабочий образец.csv';
        }

        // Проверяем нужно ли импортировать
        if ($this->pipelineStats['new_ads'] <= 0) {
            $this->pipelineStats['import_skipped'] = true;
            $this->pipelineStats['import_reason'] = 'Нет новых объявлений';
            echo "\n  Новых объявлений нет — импорт AutoLoad CSV пропускается\n";
            return;
        }

        echo "\n" . str_repeat('-', 60) . "\n";
        echo "  ШАГ 2: IMPORT — Импорт из AutoLoad CSV\n";
        echo str_repeat('-', 60) . "\n";
        flush();

        // Проверяем существование файла
        if (!file_exists($autoloadCsvPath)) {
            $this->pipelineStats['import_skipped'] = true;
            $this->pipelineStats['import_reason'] = 'Файл не найден';
            echo "  AutoLoad CSV не найден: {$autoloadCsvPath}\n";
            echo "  Скачайте фид из Avito (Настройки → Автовыгрузка → Скачать фид)\n";
            echo "  и положите в: {$autoloadCsvPath}\n";
            return;
        }

        echo "  Файл найден: {$autoloadCsvPath}\n";
        echo "  Появились новые объявления: {$this->pipelineStats['new_ads']}\n";
        echo "  Импортировать данные из AutoLoad CSV? [y/N] ";

        $handle = fopen('php://stdin', 'r');
        $response = trim(fgets($handle)) ?? '';
        fclose($handle);

        if (strtolower($response) !== 'y') {
            $this->pipelineStats['import_skipped'] = true;
            $this->pipelineStats['import_reason'] = 'Отмена пользователем';
            echo "  Импорт пропущен\n";
            return;
        }

        // ВАЖНО: Проверяем что файл содержит данные для новых объявлений
        $newAvitoIds = $this->getNewAvitoIdsFromSync();
        $missingInFile = $this->validateAutoloadFile($autoloadCsvPath, $newAvitoIds);

        if (!empty($missingInFile)) {
            $this->pipelineStats['stale_file_detected'] = true;
            $this->pipelineStats['stale_file_missing_ads'] = $missingInFile;

            echo "\n  [WARNING] Обнаружен СТАРЫЙ файл AutoLoad CSV!\n";
            echo "  Файл не содержит данные для " . count($missingInFile) . " новых объявлений:\n";
            foreach (array_slice($missingInFile, 0, 5) as $avitoId) {
                echo "    - avito_id: {$avitoId}\n";
            }
            if (count($missingInFile) > 5) {
                echo "    ... и ещё " . (count($missingInFile) - 5) . "\n";
            }
            echo "\n  Это может быть старый файл с прошлой недели/месяца.\n";
            echo "  Новые объявления не получат данные (phone, description, images, brand, oem).\n";
            echo "  Скачайте актуальный фид из Avito AutoLoad.\n";
            echo "  Импорт пропущен.\n";

            $this->pipelineStats['import_skipped'] = true;
            $this->pipelineStats['import_reason'] = 'Обнаружен старый файл (не содержит новые ads)';
            return;
        }

        // Файл валиден — импортируем
        $imported = $this->importAutoloadFeed($autoloadCsvPath);
        $this->pipelineStats['imported_ads'] = $imported;

        echo "  ✓ Импортировано: {$imported} объявлений\n";
    }

    /**
     * Получить avito_id новых объявлений из последнего sync
     */
    private function getNewAvitoIdsFromSync(): array
    {
        return $this->pipelineStats['created_ids'] ?? [];
    }

    /**
     * Валидировать AutoLoad CSV — проверить что файл содержит данные для новых объявлений
     * 
     * @return list<string> Список avito_id которые НЕ нашлись в файле
     */
    private function validateAutoloadFile(string $filePath, array $newAvitoIds): array
    {
        if (empty($newAvitoIds)) {
            return []; // Нет новых объявлений — проверка не нужна
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return $newAvitoIds;
        }

        // Убираем BOM
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }

        $lines = explode("\n", $raw);
        $lines = array_map('rtrim', $lines);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));

        if (count($lines) < 8) {
            return $newAvitoIds;
        }

        // Строка 2 — заголовки
        $headers = str_getcsv($lines[1], ';');

        // Ищем колонку "Номер объявления на Авито"
        $headerMap = [];
        foreach ($headers as $i => $h) {
            $headerMap[trim($h)] = $i;
        }

        $avitoIdCol = $headerMap['Номер объявления на Авито'] ?? null;
        if ($avitoIdCol === null) {
            return $newAvitoIds; // Не можем найти колонку — пропускаем валидацию
        }

        // Строки 8+ — данные
        $dataLines = array_slice($lines, 7);

        // Собираем все avito_id из файла
        $fileAvitoIds = [];
        foreach ($dataLines as $line) {
            $row = str_getcsv($line, ';');
            $avitoId = trim($row[$avitoIdCol] ?? '');
            if ($avitoId !== '') {
                $fileAvitoIds[] = $avitoId;
            }
        }

        // Проверяем какие новые объявления отсутствуют в файле
        $missing = [];
        foreach ($newAvitoIds as $newId) {
            if (!in_array($newId, $fileAvitoIds, true)) {
                $missing[] = $newId;
            }
        }

        return $missing;
    }

    /**
     * Шаг 3: STATS — Сбор статистики
     */
    private function stepStats(bool $cli, int $statsDays): void
    {
        echo "\n" . str_repeat('-', 60) . "\n";
        echo "  ШАГ 3: COLLECT STATS — Сбор статистики\n";
        echo str_repeat('-', 60) . "\n";
        flush();

        try {
            $result = $this->republisher->collectAllActiveStats($statsDays);

            $this->pipelineStats['stats_days_collected'] = $result['days'] ?? $statsDays;
            $this->pipelineStats['stats_ads_processed'] = $result['items'] ?? 0;

            echo "  Период: {$result['date_from']} — {$result['date_to']}\n";
            echo "  Загружено ads: {$result['items']}\n";
            echo "  Создано новых в БД: {$result['created']}\n";
            echo "  Сохранено записей статистики: {$result['saved_items']}\n";
            echo "  Ошибок батчей: {$result['failed_batches']}\n";

        } catch (\Throwable $e) {
            echo "  [ERROR] Ошибка сбора статистики: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Шаг 4: CANDIDATES — Поиск слабых объявлений и запись в republish_candidates_*
     */
    private function stepCandidates(bool $cli): void
    {
        echo "\n" . str_repeat('-', 60) . "\n";
        echo "  ШАГ 4: FIND CANDIDATES — Поиск слабых объявлений\n";
        echo str_repeat('-', 60) . "\n";
        flush();

        try {
            // collectCandidates() находит ads с 0 просмотрами, записывает в republish_candidates_*,
            // устанавливает статус low_perf и возвращает статистику
            $result = $this->republisher->collectCandidates();

            $this->pipelineStats['candidates_found'] = $result['added'];

            echo "  Найдено кандидатов: {$result['found']}\n";
            echo "  Добавлено в таблицу: {$result['added']}\n";
            echo "  Уже были в таблице: {$result['skipped']}\n";

        } catch (\Throwable $e) {
            echo "  [ERROR] Ошибка поиска кандидатов: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Шаг 5: FEED — Генерация CSV фида
     */
    private function stepFeed(bool $cli): void
    {
        echo "\n" . str_repeat('-', 60) . "\n";
        echo "  ШАГ 5: GENERATE FEED — Генерация CSV фида\n";
        echo str_repeat('-', 60) . "\n";
        flush();

        try {
            $feedGenerator = new FeedGeneratorService(
                $this->repository,
                $this->apiClient,
                $this->config
            );
            
            $result = $feedGenerator->generate(true); // priorityMode = true

            $this->pipelineStats['feed_generated'] = !empty($result['file']);
            $this->pipelineStats['feed_file'] = $result['file'] ?? '';
            $this->pipelineStats['feed_count'] = $result['count'] ?? 0;

            if ($result['count'] > 0) {
                echo "  ✓ Фид сгенерирован: {$result['file']}\n";
                echo "    Строк: {$result['count']} объявлений\n";
                echo "    Столбцов: " . count($result['headers']) . "\n";
            } else {
                echo "  Нет активных объявлений для выгрузки\n";
            }

        } catch (\Throwable $e) {
            echo "  [ERROR] Ошибка генерации фида: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Импортировать данные из AutoLoad CSV
     * 
     * @return int Количество обновлённых записей
     */
    private function importAutoloadFeed(string $filePath): int
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return 0;
        }

        // Убираем BOM
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }

        $lines = explode("\n", $raw);
        $lines = array_map('rtrim', $lines);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));

        if (count($lines) < 8) {
            return 0;
        }

        // Строка 2 — заголовки
        $headers = str_getcsv($lines[1], ';');

        // Строки 8+ — данные
        $dataLines = array_slice($lines, 7);

        // Маппинг заголовков
        $headerMap = [];
        foreach ($headers as $i => $h) {
            $headerMap[trim($h)] = $i;
        }

        // Проверяем наличие обязательных колонок
        $requiredCols = [
            'Номер объявления на Авито',
            'Номер телефона',
            'Описание объявления',
            'Ссылки на фото',
            'Производитель',
            'Номер детали OEM',
            'Название объявления',
            'Адрес',
            'Цена',
        ];

        foreach ($requiredCols as $col) {
            if (!isset($headerMap[$col])) {
                echo "  [WARNING] Отсутствует колонка в CSV: {$col}\n";
            }
        }

        // Подготавливаем SQL запрос
        $stmt = $this->repository->getConnection()->prepare("
            UPDATE physical_ads SET 
                phone = :phone,
                description = :description,
                images = :images,
                brand = :brand,
                oem_number = :oem_number,
                title = :title,
                location = :location,
                price = :price
            WHERE avito_id = :avito_id
        ");

        $updated = 0;
        foreach ($dataLines as $line) {
            $row = str_getcsv($line, ';');

            $avitoId = trim($row[$headerMap['Номер объявления на Авито']] ?? '');
            if ($avitoId === '') continue;

            // Ищем в БД
            $existing = $this->repository->getByAvitoId($avitoId);
            if ($existing === null) continue;

            $phone = trim($row[$headerMap['Номер телефона']] ?? '');
            $description = strip_tags(trim($row[$headerMap['Описание объявления']] ?? ''));
            $imagesRaw = trim($row[$headerMap['Ссылки на фото']] ?? '');
            $images = $imagesRaw !== '' ? json_encode(explode('|', $imagesRaw), JSON_UNESCAPED_UNICODE) : '';
            $brand = trim($row[$headerMap['Производитель']] ?? '');
            $oem = trim($row[$headerMap['Номер детали OEM']] ?? '');
            $title = trim($row[$headerMap['Название объявления']] ?? '');
            $address = trim($row[$headerMap['Адрес']] ?? '');
            $price = (int) ($row[$headerMap['Цена']] ?? 0);

            // Обновляем через repository
            $this->repository->updatePhysical((int) $existing['id'], [
                'phone' => $phone,
                'description' => $description,
                'images' => $images,
                'brand' => $brand,
                'oem_number' => $oem,
                'title' => $title,
                'location' => $address,
                'price' => $price,
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * Вывести итоговый отчёт по пайплайну
     */
    private function printSummary(bool $cli): void
    {
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "  ИТОГОВЫЙ ОТЧЁТ ПАПЛАЙНА\n";
        echo str_repeat('=', 70) . "\n";

        // Sync
        echo "\n  📊 SYNC:\n";
        echo "    Новых объявлений:    {$this->pipelineStats['new_ads']}\n";
        echo "    Обновлено:          {$this->pipelineStats['updated_ads']}\n";
        echo "    Удалено:            {$this->pipelineStats['removed_ads']}\n";

        if (!empty($this->pipelineStats['removed_ids'])) {
            echo "    Удалённые ID:\n";
            foreach ($this->pipelineStats['removed_ids'] as $id) {
                echo "      - {$id}\n";
            }
        }

        // Import
        echo "\n  📥 IMPORT:\n";
        if ($this->pipelineStats['import_skipped']) {
            echo "    Пропущен: {$this->pipelineStats['import_reason']}\n";
        } else {
            echo "    Импортировано: {$this->pipelineStats['imported_ads']} объявлений\n";
        }

        // Stale file warning
        if ($this->pipelineStats['stale_file_detected']) {
            echo "\n  ⚠️  ВНИМАНИЕ: Обнаружен СТАРЫЙ файл AutoLoad CSV!\n";
            echo "    Не найдено в файле: " . count($this->pipelineStats['stale_file_missing_ads']) . " объявлений\n";
            echo "    Новые объявления не получат данные (phone, description, images, brand, oem)\n";
        }

        // Stats
        echo "\n  📈 STATS:\n";
        echo "    Дней собрано:      {$this->pipelineStats['stats_days_collected']}\n";
        echo "    Ads обработано:    {$this->pipelineStats['stats_ads_processed']}\n";

        // Candidates
        echo "\n  🎯 CANDIDATES:\n";
        echo "    Найдено:           {$this->pipelineStats['candidates_found']}\n";

        // Feed
        echo "\n  📄 FEED:\n";
        if ($this->pipelineStats['feed_generated']) {
            echo "    Сгенерирован:      Да\n";
            echo "    Файл:              {$this->pipelineStats['feed_file']}\n";
            echo "    Строк:             {$this->pipelineStats['feed_count']}\n";
        } else {
            echo "    Сгенерирован:      Нет\n";
        }

        // Timing
        echo "\n  ⏱ ВРЕМЯ ВЫПОЛНЕНИЯ:\n";
        $timing = $this->pipelineStats['timing'];
        echo "    SYNC:             {$timing['sync']}s\n";
        echo "    IMPORT:           {$timing['import']}s\n";
        echo "    STATS:            {$timing['stats']}s\n";
        echo "    CANDIDATES:       {$timing['candidates']}s\n";
        echo "    FEED:             {$timing['feed']}s\n";
        $totalTime = round(array_sum($timing), 2);
        echo "    ──────────────────────────\n";
        echo "    ВСЕГО:            {$totalTime}s\n";

        // Итого
        echo "\n" . str_repeat('-', 70) . "\n";
        echo "  ВСЕГО:\n";
        $totalChanges = $this->pipelineStats['new_ads'] 
                      + $this->pipelineStats['updated_ads'] 
                      + $this->pipelineStats['removed_ads'];
        echo "    Изменений в БД:   {$totalChanges}\n";
        echo "    Новых + Обновлено + Удалено\n";
        echo str_repeat('=', 70) . "\n";
        echo "  ✅ Pipeline завершён за {$totalTime}s\n";
        echo str_repeat('=', 70) . "\n";
        flush();
    }

    /**
     * Получить итоговую статистику (для HTTP API)
     * 
     * @return array Полная статистика пайплайна
     */
    public function getStats(): array
    {
        return $this->pipelineStats;
    }
}