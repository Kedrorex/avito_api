<?php

namespace App\Services;

use App\Repositories\ItemRepository;
use App\Services\AvitoAPIClient;

/**
 * Генератор фида для Avito AutoLoad
 *
 * Файл фида: XML formatVersion=3 (`fid/avito_feed_YYYY-MM-DD.xml`).
 * Категория: Транспорт - Запчасти и аксессуары - Запчасти - Для автомобилей - Двигатель
 *
 * Данные читаются из колонок БД physical_ads.
 *
 * Состав файла — один каталог, AvitoStatus=active:
 * сегодняшняя порция очереди republish_candidates_* (не больше max_daily_repub,
 * по умолчанию 70) пишется новым Id. Старого Id в файле нет: Авито снимает
 * объявление, которого нет в фиде, и публикует новое с нуля.
 * Кандидаты, не попавшие в порцию, остаются в очереди и в файле со старым Id.
 */
class FeedGeneratorService
{
    private ItemRepository $repository;
    private AvitoAPIClient $apiClient;
    private array $config;
    private string $outputDir;
    private int $maxCandidates;

    public function __construct(
        ItemRepository $repository,
        AvitoAPIClient $apiClient,
        array $config,
        int $maxCandidates = 0
    ) {
        $this->repository = $repository;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->outputDir = $config['feed']['output_dir'] ?? __DIR__ . '/../../fid';
        $this->maxCandidates = $maxCandidates;
    }

    /**
     * Сгенерировать XML-фид.
     *
     * @param bool $priorityMode Не используется. Оставлен, чтобы не ломать вызовы.
     * @param bool $keepQueue Тестовый фид: замена видна в файле, очередь, счётчик и строки БД не меняются
     * @param int $forcedRemoval Тест: столько первых объявлений каталога заменить в файле, без очереди и без записи в БД
     *
     * @return array{file: string, xml_file: string, csv_file: string, count: int, headers: string[]}
     */
    public function generate(bool $priorityMode = true, bool $keepQueue = false, int $forcedRemoval = 0, bool $catalogOnly = false): array
    {
        $restored = $this->repository->restoreLowPerfStatus();
        if ($restored > 0) {
            echo "  В каталог возвращено из low_perf: {$restored}\n";
        }

        $catalog = $this->repository->getFeedCatalog();

        if ($catalog === []) {
            echo "  Нет объявлений для выгрузки\n";
            return [
                'file' => '',
                'xml_file' => '',
                'csv_file' => '',
                'count' => 0,
                'headers' => [],
                'candidate_avito_ids' => [],
                'removal_ads' => [],
                'catalog_count' => 0,
            ];
        }

        $this->backfillListingContent($catalog);

        $catalogByAvitoId = [];
        foreach ($catalog as $ad) {
            $avitoId = (string) ($ad['avito_id'] ?? '');
            if ($avitoId !== '') {
                $catalogByAvitoId[$avitoId] = $ad;
            }
        }

        if ($catalogOnly) {
            $removalAds = [];
            echo "  В файле активные объявления кабинета, очередь на замену не берём\n";
        } elseif ($forcedRemoval > 0) {
            $removalAds = array_slice(array_values($catalog), 0, $forcedRemoval);
            echo "  Тест: заменить первые " . count($removalAds) . " из каталога, без очереди и без сверки avito_id\n";
            foreach ($removalAds as $ad) {
                echo "    id=" . ($ad['id'] ?? '')
                    . " avito_id=" . ($ad['avito_id'] ?? '')
                    . " unique_id=" . ($ad['unique_id'] ?? '') . "\n";
            }
        } else {
            $removalAds = $this->selectRemovalBatch($catalogByAvitoId, $keepQueue);
        }

        $persist = !$keepQueue && $forcedRemoval === 0;
        $replacedIds = [];
        foreach ($removalAds as $ad) {
            $id = (int) ($ad['id'] ?? 0);
            if ($id > 0) {
                $replacedIds[$id] = true;
            }
        }

        $reservedIds = [];
        $replacements = [];
        $candidateAvitoIds = [];
        foreach ($removalAds as $ad) {
            $replacement = $this->spawnReplacement($ad, $persist, $reservedIds);
            $replacements[] = $replacement;
            $candidateAvitoIds[] = (string) ($ad['avito_id'] ?? '');
        }

        $ads = [];
        $catalogCount = 0;
        foreach ($catalog as $ad) {
            if (isset($replacedIds[(int) ($ad['id'] ?? 0)])) {
                continue;
            }
            $data = $this->buildAdData($ad, 'active');
            if ($data === null) {
                continue;
            }
            $ads[] = $data;
            $catalogCount++;
        }

        $replacementCount = 0;
        foreach ($replacements as $ad) {
            $data = $this->buildAdData($ad, 'active');
            if ($data === null) {
                continue;
            }
            $data['avito_id'] = '';
            $ads[] = $data;
            $replacementCount++;
        }

        $this->warnMissingBrandOem($ads);

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $date = date('Y-m-d');
        $xmlPath = $this->outputDir . "/avito_feed_{$date}.xml";
        $headers = $this->getHeaders();

        $this->writeXml($xmlPath, $ads);

        if ($persist) {
            $this->consumeRemovalBatch($removalAds);
        } else {
            echo "  Тест: база не изменена. В файле старый Id уже заменён новым, очередь и дневной счётчик на месте\n";
        }

        $count = count($ads);
        echo "  XML: {$xmlPath}\n";
        echo "  Переопубликовано: {$replacementCount} (старого Id в файле нет, есть новый без AvitoId)\n";
        echo "  Остальной каталог: {$catalogCount}\n";
        echo "  Объявлений:       {$count}\n";

        return [
            'file' => $xmlPath,
            'xml_file' => $xmlPath,
            'csv_file' => '',
            'count' => $count,
            'headers' => $headers,
            'candidate_avito_ids' => $candidateAvitoIds,
            'removal_ads' => $removalAds,
            'catalog_count' => $catalogCount,
        ];
    }

    /**
     * Фид, в котором остаются только первые $keep объявлений каталога.
     * В файле их нет — Авито снимет с публикации. Очередь и дневной счётчик не меняются.
     *
     * @return array{file: string, xml_file: string, csv_file: string, count: int, headers: string[], kept: int, catalog_total: int}
     */
    public function generateKeep(int $keep = 10): array
    {
        $empty = [
            'file' => '',
            'xml_file' => '',
            'csv_file' => '',
            'count' => 0,
            'headers' => [],
            'candidate_avito_ids' => [],
            'removal_ads' => [],
            'catalog_count' => 0,
            'kept' => 0,
            'catalog_total' => 0,
        ];

        if ($keep < 1) {
            $keep = 10;
        }

        $catalog = $this->repository->getFeedCatalog();
        if ($catalog === []) {
            echo "  Нет объявлений для выгрузки\n";
            return $empty;
        }

        $this->backfillListingContent($catalog);

        $keptAds = array_slice(array_values($catalog), 0, $keep);
        $ads = [];
        foreach ($keptAds as $ad) {
            $data = $this->buildAdData($ad, 'active');
            if ($data === null) {
                continue;
            }
            $ads[] = $data;
        }

        if ($ads === []) {
            echo "  Не удалось собрать объявления, которые нужно оставить\n";
            return $empty;
        }

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $date = date('Y-m-d');
        $xmlPath = $this->outputDir . "/avito_feed_keep_{$date}.xml";
        $this->writeXml($xmlPath, $ads);

        $catalogTotal = count($catalog);
        $removed = $catalogTotal - count($ads);
        echo "  XML: {$xmlPath}\n";
        echo "  В файле остаются: " . count($ads) . "\n";
        echo "  Каталог всего:    {$catalogTotal}\n";
        echo "  Снимутся, потому что их нет в файле: {$removed}\n";
        foreach ($ads as $ad) {
            echo "    оставить avito_id=" . ($ad['avito_id'] ?? '')
                . " unique_id=" . ($ad['unique_id'] ?? '') . "\n";
        }
        echo "  Очередь и дневной счётчик не изменены.\n";
        echo "  Внимание: загрузка этого файла снимет все объявления аккаунта, которых в нём нет.\n";

        return [
            'file' => $xmlPath,
            'xml_file' => $xmlPath,
            'csv_file' => '',
            'count' => count($ads),
            'headers' => $this->getHeaders(),
            'candidate_avito_ids' => [],
            'removal_ads' => [],
            'catalog_count' => count($ads),
            'kept' => count($ads),
            'catalog_total' => $catalogTotal,
        ];
    }

    /**
     * Фид из кабинета Avito: неактивные (removed, old) + текущие active.
     * Все строки пишутся как AvitoStatus=active. Очередь републикации не трогаем.
     *
     * @return array{file: string, xml_file: string, csv_file: string, count: int, headers: string[], inactive_count: int, active_count: int}
     */
    public function generateFromCabinet(int $inactiveLimit = 0, bool $includeActive = true): array
    {
        $empty = [
            'file' => '',
            'xml_file' => '',
            'csv_file' => '',
            'count' => 0,
            'headers' => [],
            'candidate_avito_ids' => [],
            'removal_ads' => [],
            'catalog_count' => 0,
            'inactive_count' => 0,
            'active_count' => 0,
            'status_counts' => [],
        ];

        echo "  Кабинет: неактивные removed, old\n";
        flush();
        $inactive = $this->apiClient->getAllItems(
            ['removed', 'old'],
            99,
            static function (int $page, int $fetched, int $total): void {
                echo "    Неактивные: страница {$page}, уже {$fetched}" . ($total > 0 ? " из {$total}" : '') . "\n";
                flush();
            }
        );

        $statusCounts = [];
        foreach ($inactive as $item) {
            $status = (string) ($item['status'] ?? 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }
        foreach ($statusCounts as $status => $count) {
            echo "    {$status}: {$count}\n";
        }
        echo "    Неактивных всего: " . count($inactive) . "\n";

        if ($inactiveLimit > 0 && count($inactive) > $inactiveLimit) {
            $inactive = array_slice($inactive, 0, $inactiveLimit);
            echo "    Берём в фид неактивных: {$inactiveLimit}\n";
        }

        $active = [];
        if ($includeActive) {
            echo "  Кабинет: active\n";
            flush();
            $active = $this->apiClient->getAllItems(
                ['active'],
                99,
                static function (int $page, int $fetched, int $total): void {
                    echo "    Активные: страница {$page}, уже {$fetched}" . ($total > 0 ? " из {$total}" : '') . "\n";
                    flush();
                }
            );
            echo "    Активных всего: " . count($active) . "\n";
        }

        $byAvitoId = [];
        foreach (array_merge($inactive, $active) as $item) {
            $avitoId = trim((string) ($item['id'] ?? ''));
            if ($avitoId === '') {
                continue;
            }
            $byAvitoId[$avitoId] = $item;
        }

        if ($byAvitoId === []) {
            echo "  В кабинете нет объявлений для выгрузки\n";
            return $empty;
        }

        $adIdMap = $this->resolveAutoloadIds(array_keys($byAvitoId));

        $hydratedAds = [];
        foreach ($byAvitoId as $item) {
            $hydrated = $this->hydrateApiItem($item, $adIdMap);
            $hydrated['_fallback_description'] = false;
            $hydratedAds[] = $hydrated;
        }
        $this->backfillListingContent($hydratedAds);

        $ads = [];
        foreach ($hydratedAds as $hydrated) {
            $data = $this->buildAdData($hydrated, 'active');
            if ($data === null) {
                continue;
            }
            $ads[] = $data;
        }

        if ($ads === []) {
            echo "  Не удалось собрать ни одного объявления\n";
            return $empty;
        }

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $date = date('Y-m-d');
        $xmlPath = $this->outputDir . "/avito_feed_inactive_{$date}.xml";
        $headers = $this->getHeaders();

        $this->writeXml($xmlPath, $ads);

        $count = count($ads);
        echo "  XML: {$xmlPath}\n";
        echo "  AvitoStatus:   active\n";
        echo "  Неактивных:    " . count($inactive) . "\n";
        echo "  Активных:      " . count($active) . "\n";
        echo "  В файле:       {$count}\n";
        echo "  Внимание: если загрузить только этот файл, Авито снимет всё, чего в нём нет.\n";

        return [
            'file' => $xmlPath,
            'xml_file' => $xmlPath,
            'csv_file' => '',
            'count' => $count,
            'headers' => $headers,
            'candidate_avito_ids' => [],
            'removal_ads' => [],
            'catalog_count' => $count,
            'inactive_count' => count($inactive),
            'active_count' => count($active),
            'status_counts' => $statusCounts,
        ];
    }

    /**
     * @param list<string> $avitoIds
     * @return array<string, string>
     */
    private function resolveAutoloadIds(array $avitoIds): array
    {
        $batchSize = max(1, min(100, (int) ($this->config['avito']['autoload_id_batch_size'] ?? 100)));
        $delay = max(0, (int) ($this->config['avito']['autoload_request_delay_seconds'] ?? 1));
        $map = [];
        $chunks = array_chunk($avitoIds, $batchSize);
        echo "  Autoload Id: " . count($avitoIds) . " номеров, пакетов " . count($chunks) . "\n";
        flush();

        foreach ($chunks as $index => $chunk) {
            echo "    Пакет " . ($index + 1) . "/" . count($chunks) . "\n";
            flush();
            try {
                foreach ($this->apiClient->getAdIdsByAvitoIds($chunk) as $item) {
                    $avitoId = (string) ($item['avito_id'] ?? '');
                    $adId = (string) ($item['ad_id'] ?? '');
                    if ($avitoId !== '' && $adId !== '') {
                        $map[$avitoId] = $adId;
                    }
                }
            } catch (\Throwable $e) {
                echo "    [WARN] ad_ids: " . $e->getMessage() . "\n";
            }
            if ($delay > 0 && $index < count($chunks) - 1) {
                sleep($delay);
            }
        }

        echo "    Нашли Id автозагрузки: " . count($map) . "\n";
        return $map;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $adIdMap
     * @return array<string, mixed>
     */
    private function hydrateApiItem(array $item, array $adIdMap): array
    {
        $avitoId = trim((string) ($item['id'] ?? ''));
        $price = $item['price'] ?? 0;
        if (is_array($price)) {
            $price = (int) ($price['amount'] ?? 0);
        }

        $location = $this->scalarLocation($item['address'] ?? $item['location'] ?? '');
        $category = $item['category'] ?? [];
        $images = $item['images'] ?? [];

        return [
            'id' => $avitoId,
            'avito_id' => $avitoId,
            'unique_id' => $adIdMap[$avitoId] ?? (string) ($item['uniqueId'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'price' => (int) $price,
            'location' => $location,
            'description' => (string) ($item['description'] ?? ''),
            'phone' => '',
            'contact_method' => '',
            'brand' => '',
            'oem_number' => '',
            'images' => json_encode($this->normalizeImageList($images), JSON_UNESCAPED_UNICODE),
            'category' => is_array($category) ? $category : ['name' => (string) $category],
            'category_params' => '',
            'status' => 'active',
        ];
    }

    private function scalarLocation(mixed $location): string
    {
        if (is_string($location)) {
            return $location;
        }
        if (!is_array($location)) {
            return '';
        }
        foreach (['address', 'name', 'title'] as $key) {
            if (!empty($location[$key]) && is_string($location[$key])) {
                return $location[$key];
            }
        }
        return '';
    }

    /**
     * @param mixed $images
     * @return list<string>
     */
    private function normalizeImageList(mixed $images): array
    {
        if (!is_array($images)) {
            return [];
        }
        $urls = [];
        foreach ($images as $img) {
            if (is_string($img) && $img !== '') {
                $urls[] = $img;
            } elseif (is_array($img)) {
                $url = $img['url'] ?? $img['thumb_url'] ?? '';
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        return $urls;
    }

    /**
     * Новое поколение: новый unique_id, пустой avito_id, слегка другой контент.
     * Старая строка уходит из каталога только при боевой генерации.
     *
     * @param array<string, mixed> $ad
     * @param array<string, true> $reservedIds
     * @return array<string, mixed>
     */
    private function spawnReplacement(array $ad, bool $persist, array &$reservedIds): array
    {
        $variation = (new AdContentVariation())->vary(
            (string) ($ad['title'] ?? ''),
            (string) ($ad['description'] ?? ''),
            $this->parseImages($ad)
        );

        $baseId = (string) ($ad['unique_id'] ?? '');
        if ($baseId === '') {
            $baseId = (string) ($ad['avito_id'] ?? '');
        }
        if ($baseId === '') {
            $baseId = 'ad_' . (string) ($ad['id'] ?? '0');
        }
        $newUniqueId = $this->nextUniqueId($baseId, $reservedIds);
        $imagesJson = json_encode($variation['images'], JSON_UNESCAPED_UNICODE);

        $master = [];
        if (!empty($ad['master_data']) && is_string($ad['master_data'])) {
            $decoded = json_decode($ad['master_data'], true);
            if (is_array($decoded)) {
                $master = $decoded;
            }
        }
        $master['title'] = $variation['title'];
        $master['description'] = $variation['description'];
        $master['unique_id'] = $newUniqueId;
        $master['images'] = $variation['images'];

        $replacement = $ad;
        $replacement['unique_id'] = $newUniqueId;
        $replacement['avito_id'] = '';
        $replacement['title'] = $variation['title'];
        $replacement['description'] = $variation['description'];
        $replacement['images'] = $imagesJson;
        $replacement['status'] = 'active';
        $replacement['old_avito_id'] = (string) ($ad['avito_id'] ?? '');

        if (!$persist) {
            return $replacement;
        }

        $oldAvitoId = (string) ($ad['avito_id'] ?? '');
        $this->repository->beginWriteTransaction();
        try {
            $newId = $this->repository->createPhysical(
                (string) ($ad['logical_key'] ?? ''),
                $master,
                'active'
            );
            $this->repository->updatePhysical($newId, [
                'published_at' => date('Y-m-d H:i:s'),
                'old_avito_id' => $oldAvitoId !== '' ? $oldAvitoId : null,
                'unique_id' => $newUniqueId,
                'phone' => $ad['phone'] ?? null,
                'contact_method' => $ad['contact_method'] ?? null,
                'brand' => $ad['brand'] ?? null,
                'oem_number' => $ad['oem_number'] ?? null,
                'images' => $imagesJson,
                'title' => $variation['title'],
                'description' => $variation['description'],
                'location' => $ad['location'] ?? null,
                'price' => $ad['price'] ?? 0,
                'category_params' => $ad['category_params'] ?? null,
            ]);
            $oldId = (int) ($ad['id'] ?? 0);
            if ($oldId > 0 && !$this->repository->archivePhysicalAd($oldId, true)) {
                throw new \RuntimeException('Не удалось архивировать объявление ' . $oldId);
            }
            $this->repository->commit();
        } catch (\Throwable $e) {
            $this->repository->rollBack();
            throw $e;
        }

        $stored = $this->repository->getById($newId);
        if ($stored === null) {
            return $replacement;
        }
        $stored['avito_id'] = '';

        echo "  [REPUB] " . ($oldAvitoId !== '' ? $oldAvitoId : $baseId)
            . " -> Id {$newUniqueId}\n";

        return $stored;
    }

    /**
     * @param array<string, true> $reservedIds
     */
    private function nextUniqueId(string $current, array &$reservedIds): string
    {
        if (preg_match('/^(.*)-v(\d+)$/', $current, $matches) === 1) {
            $base = $matches[1];
            $version = (int) $matches[2] + 1;
        } else {
            $base = $current;
            $version = 2;
        }

        do {
            $candidate = $base . '-v' . $version;
            $version++;
        } while (isset($reservedIds[$candidate]) || $this->repository->uniqueIdExists($candidate));

        $reservedIds[$candidate] = true;

        return $candidate;
    }

    /**
     * @param list<array<string, mixed>> $ads
     */
    private function warnMissingBrandOem(array $ads): void
    {
        foreach ($ads as $ad) {
            $missing = [];
            if ((string) ($ad['brand'] ?? '') === '') {
                $missing[] = 'Производитель';
            }
            if ((string) ($ad['oem'] ?? '') === '') {
                $missing[] = 'Номер детали OEM';
            }
            if ($missing === []) {
                continue;
            }
            echo '  [WARN] Id ' . ($ad['unique_id'] ?? '')
                . ': пусто ' . implode(', ', $missing) . "\n";
        }
    }

    /**
     * Следующие кандидаты из очереди, не больше дневного лимита.
     * В файле их старый Id не пишется: вместо него идёт новое поколение.
     *
     * @param array<string, array<string, mixed>> $catalogByAvitoId
     * @param bool $ignoreDailyUsage Для тестового фида: взять полную порцию, даже если лимит дня уже занят
     * @return list<array<string, mixed>>
     */
    private function selectRemovalBatch(array $catalogByAvitoId, bool $ignoreDailyUsage = false): array
    {
        $maxDaily = (int) ($this->config['avito']['max_daily_repub'] ?? 70);
        if ($maxDaily < 1) {
            $maxDaily = 70;
        }
        $used = $this->repository->getDailyRepubCount(date('Y-m-d'));
        $slots = $ignoreDailyUsage ? $maxDaily : max(0, $maxDaily - $used);
        if ($this->maxCandidates > 0) {
            $slots = min($slots, $this->maxCandidates);
        }

        if ($ignoreDailyUsage) {
            echo "  Тестовый фид: замена до {$slots}, уже использовано сегодня {$used} — в базу не пишется и не списывается\n";
        } else {
            echo "  Лимит переопубликации сегодня: {$slots} из {$maxDaily} (уже использовано: {$used})\n";
        }
        if ($slots === 0) {
            echo "  Очередь сегодня не берём, в файле только каталог\n";
            return [];
        }

        $queue = $this->getCandidates();
        usort($queue, static function (array $a, array $b): int {
            $byTime = strcmp((string) ($a['added_at'] ?? ''), (string) ($b['added_at'] ?? ''));
            if ($byTime !== 0) {
                return $byTime;
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $batch = [];
        $seen = [];
        foreach ($queue as $candidate) {
            if (count($batch) >= $slots) {
                break;
            }
            $avitoId = (string) ($candidate['avito_id'] ?? '');
            if ($avitoId === '' || isset($seen[$avitoId]) || !isset($catalogByAvitoId[$avitoId])) {
                continue;
            }
            $seen[$avitoId] = true;
            $batch[] = $catalogByAvitoId[$avitoId];
        }

        echo "  Из очереди взято: " . count($batch) . " из " . count($queue) . "\n";

        return $batch;
    }

    /**
     * Порция, попавшая в замену, списывается с дневного лимита и уходит из очереди.
     *
     * @param list<array<string, mixed>> $removalAds
     */
    private function consumeRemovalBatch(array $removalAds): void
    {
        if ($removalAds === []) {
            return;
        }

        $today = date('Y-m-d');
        $already = (int) ($this->repository->getMeta('feed_repub_' . $today) ?? '0');
        $this->repository->setMeta('feed_repub_' . $today, (string) ($already + count($removalAds)));

        foreach ($removalAds as $ad) {
            $this->repository->removeCandidate((int) ($ad['id'] ?? 0));
        }

        echo "  Из очереди снято после фида: " . count($removalAds) . "\n";
    }

    /**
     * @param list<array> $activeAds
     * @return list<array{ad: array, analysis?: array}>
     */
    private function applyPriority(array $activeAds): array
    {
        $analysisService = new AnalysisService($this->repository, $this->config);
        $candidates = $analysisService->findAllCandidates();

        $analysisMap = [];
        foreach ($candidates as $c) {
            $avitoId = (string) ($c['ad']['avito_id'] ?? '');
            if ($avitoId !== '') {
                $analysisMap[$avitoId] = $c['analysis'];
            }
        }

        $candidateAds = [];
        $normalAds = [];
        foreach ($activeAds as $ad) {
            $avitoId = (string) ($ad['avito_id'] ?? '');
            if (isset($analysisMap[$avitoId])) {
                $candidateAds[] = ['ad' => $ad, 'analysis' => $analysisMap[$avitoId]];
            } else {
                $normalAds[] = ['ad' => $ad, 'analysis' => null];
            }
        }

        return array_merge($candidateAds, $normalAds);
    }

    /**
     * @return string[]
     */
    private function getHeaders(): array
    {
        return [
            'Уникальный идентификатор объявления',
            'Способ размещения',
            'Номер объявления на Авито',
            'Номер телефона',
            'Адрес',
            'Способ связи',
            'Категория',
            'Описание объявления',
            'Ссылки на фото',
            'Название объявления',
            'Цена',
            'Вид товара',
            'Вид объявления',
            'Тип товара',
            'Вид запчасти',
            'Тип детали двигателя',
            'Состояние',
            'Происхождение',
            'Доступность',
            'Производитель',
            'Номер детали OEM',
            'TypeID',
            'AvitoDateEnd',
            'AvitoStatus',
            'Название компании',
            'Почта',
        ];
    }

    /**
     * Дописать пустые описание и фото из файла Автозагрузки.
     * Поколение adsasdasd2395-v2 берёт текст и ссылки у adsasdasd2395.
     *
     * @param list<array<string, mixed>> $ads
     */
    private function backfillListingContent(array &$ads): void
    {
        $fromArchive = 0;
        foreach ($ads as &$ad) {
            $update = $this->repository->listingFieldsFromArchive($ad);
            if ($update === []) {
                continue;
            }
            foreach ($update as $column => $value) {
                $ad[$column] = $value;
            }
            if (array_key_exists('logical_key', $ad) && (int) ($ad['id'] ?? 0) > 0) {
                $this->repository->updatePhysical((int) $ad['id'], $update);
            }
            $fromArchive++;
        }
        unset($ad);
        if ($fromArchive > 0) {
            echo "  Из архива дописано объявлений: {$fromArchive}\n";
        }

        $missing = false;
        foreach ($ads as $ad) {
            if ($this->listingContentMissing($ad)) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $path = $this->resolveAutoloadContentFile();
        if ($path === null) {
            echo "  Нет файла Автозагрузки с описанием и фото\n";
            return;
        }

        echo "  Описание и фото из {$path}\n";
        flush();
        $index = (new AutoloadFeedImportService($this->apiClient, $this->repository, $this->config))
            ->listingContentByUniqueId($path);
        echo "  В файле объявлений с текстом или фото: " . count($index) . "\n";

        $filled = 0;
        foreach ($ads as &$ad) {
            if (!$this->listingContentMissing($ad)) {
                continue;
            }
            $content = $this->lookupListingContent($index, (string) ($ad['unique_id'] ?? ''));
            if ($content === null) {
                continue;
            }

            $update = [];
            if (trim((string) ($ad['description'] ?? '')) === '' && $content['description'] !== '') {
                $ad['description'] = $content['description'];
                $update['description'] = $content['description'];
            }
            if (!$this->hasStoredImages((string) ($ad['images'] ?? '')) && $content['images'] !== '' && $content['images'] !== '[]') {
                $ad['images'] = $content['images'];
                $update['images'] = $content['images'];
            }
            if ($update === []) {
                continue;
            }

            $this->persistListingContent($ad, $update);
            $filled++;
        }
        unset($ad);

        echo "  Дописано объявлений: {$filled}\n";
    }

    /**
     * @param array<string, mixed> $ad
     * @param array<string, string> $update
     */
    private function persistListingContent(array $ad, array $update): void
    {
        $id = 0;
        if (array_key_exists('logical_key', $ad)) {
            $id = (int) ($ad['id'] ?? 0);
        } else {
            $uniqueId = trim((string) ($ad['unique_id'] ?? ''));
            if ($uniqueId !== '') {
                $stored = $this->repository->getByUniqueId($uniqueId);
                $id = (int) ($stored['id'] ?? 0);
            }
        }
        if ($id > 0) {
            $this->repository->updatePhysical($id, $update);
        }
    }

    /**
     * @param array<string, array{description: string, images: string}> $index
     * @return array{description: string, images: string}|null
     */
    private function lookupListingContent(array $index, string $uniqueId): ?array
    {
        $uniqueId = trim($uniqueId);
        if ($uniqueId === '') {
            return null;
        }
        if (isset($index[$uniqueId])) {
            return $index[$uniqueId];
        }

        $base = preg_replace('/-v\d+$/', '', $uniqueId) ?? $uniqueId;
        if ($base !== $uniqueId && isset($index[$base])) {
            return $index[$base];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ad
     */
    private function listingContentMissing(array $ad): bool
    {
        $description = trim((string) ($ad['description'] ?? ''));

        return $description === '' || !$this->hasStoredImages((string) ($ad['images'] ?? ''));
    }

    private function hasStoredImages(string $imagesJson): bool
    {
        $imagesJson = trim($imagesJson);
        if ($imagesJson === '' || $imagesJson === '[]') {
            return false;
        }

        $decoded = json_decode($imagesJson, true);

        return is_array($decoded) && $decoded !== [];
    }

    private function resolveAutoloadContentFile(): ?string
    {
        $outputDir = (string) ($this->config['feed']['output_dir'] ?? $this->outputDir);
        $dir = (string) ($this->config['feed']['autoload_download_dir'] ?? ($outputDir . '/autoload'));
        if (!is_dir($dir)) {
            return null;
        }

        $files = array_merge(
            glob($dir . '/*.xlsx') ?: [],
            glob($dir . '/*.csv') ?: [],
            glob($dir . '/*.xml') ?: []
        );
        if ($files === []) {
            return null;
        }

        usort($files, static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    /**
     * Поля одного объявления для XML и CSV.
     *
     * @param array<string, mixed> $ad
     * @return array<string, mixed>|null
     */
    private function buildAdData(array $ad, string $avitoStatus = ''): ?array
    {
        $uniqueId = $ad['unique_id'] ?? '';
        if ($uniqueId === '') {
            $uniqueId = $ad['avito_id'] ?? "ad_{$ad['id']}";
        }

        $phone = (string) ($ad['phone'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone !== '' && !str_starts_with($phone, '7')) {
            $phone = '7' . $phone;
        }

        $address = (string) ($ad['location'] ?? '');
        $contactMethod = (string) ($ad['contact_method'] ?? '');

        $category = $ad['category'] ?? [];
        $categoryName = is_array($category) ? (string) ($category['name'] ?? '') : '';

        $description = (string) ($ad['description'] ?? '');
        if ($description === '' && ($ad['_fallback_description'] ?? true)) {
            $title = (string) ($ad['title'] ?? '');
            $parts = [$title];
            if ($address !== '') {
                $parts[] = "Местоположение: {$address}";
            }
            $description = implode("\n", $parts);
        }

        $images = $this->parseImages($ad);
        $title = (string) ($ad['title'] ?? '');
        $price = (int) ($ad['price'] ?? 0);

        $catParams = $this->parseCategoryParams($ad);
        $goodsType = (string) ($catParams['Вид товара'] ?? '');
        $productType = (string) ($catParams['Тип товара'] ?? '');
        $partType = (string) ($catParams['Вид запчасти'] ?? '');
        $engineType = (string) ($catParams['Тип детали двигателя'] ?? '');
        $condition = (string) ($catParams['Состояние'] ?? ($this->config['feed']['default_condition'] ?? 'new'));

        if ($goodsType === '') {
            $goodsType = (string) ($this->config['feed']['default_product_type'] ?? '');
        }
        if ($productType === '') {
            $productType = $goodsType;
        }
        if ($partType === '') {
            $partType = (string) ($this->config['feed']['default_part_type'] ?? '');
        }
        if ($engineType === '') {
            $engineType = (string) ($this->config['feed']['default_engine_type'] ?? '');
        }

        if ($avitoStatus === '') {
            $avitoStatus = $this->getAvitoStatus($ad);
        }

        return [
            'unique_id' => (string) $uniqueId,
            'listing_fee' => (string) ($this->config['feed']['default_views'] ?? 'Package'),
            'avito_id' => (string) ($ad['avito_id'] ?? ''),
            'phone' => $phone,
            'address' => $address,
            'contact_method' => $contactMethod,
            'category' => $categoryName !== '' ? $categoryName : 'Запчасти и аксессуары',
            'description' => $description,
            'images' => $images,
            'title' => $title,
            'price' => $price,
            'goods_type' => $goodsType,
            'ad_type' => (string) ($this->config['feed']['default_ad_type'] ?? ''),
            'product_type' => $productType,
            'spare_part_type' => $partType,
            'engine_type' => $engineType,
            'condition' => $condition,
            'origin' => (string) ($catParams['Происхождение'] ?? ''),
            'availability' => (string) ($catParams['Доступность'] ?? ''),
            'brand' => (string) ($ad['brand'] ?? ''),
            'oem' => (string) ($ad['oem_number'] ?? ''),
            'type_id' => (string) ($catParams['TypeID'] ?? $catParams['TypeId'] ?? ''),
            'date_end' => $this->generateAvitoDateEnd(),
            'avito_status' => $avitoStatus,
            'company_name' => (string) ($this->config['feed']['company_name'] ?? ''),
            'email' => (string) ($this->config['feed']['email'] ?? ''),
        ];
    }

    /**
     * XML Avito AutoLoad: <Ads formatVersion="3" target="Avito.ru">
     *
     * @param list<array<string, mixed>> $ads
     */
    private function writeXml(string $filepath, array $ads): void
    {
        if (!class_exists(\XMLWriter::class)) {
            throw new \RuntimeException('Для XML-фида нужно расширение php-xmlwriter');
        }

        $xml = new \XMLWriter();
        if (!$xml->openURI($filepath)) {
            throw new \RuntimeException("Не удалось создать XML фид: {$filepath}");
        }

        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('Ads');
        $xml->writeAttribute('formatVersion', '3');
        $xml->writeAttribute('target', 'Avito.ru');

        foreach ($ads as $ad) {
            $this->writeXmlAd($xml, $ad);
        }

        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    /**
     * @param array<string, mixed> $ad
     */
    private function writeXmlAd(\XMLWriter $xml, array $ad): void
    {
        $defaults = $this->xmlCategoryDefaults();

        $xml->startElement('Ad');
        $this->writeXmlValue($xml, 'Id', (string) $ad['unique_id']);
        $this->writeXmlValue($xml, 'ListingFee', (string) $ad['listing_fee']);
        $this->writeXmlValue($xml, 'AvitoId', (string) $ad['avito_id']);
        $this->writeXmlValue($xml, 'ContactPhone', (string) $ad['phone']);
        $this->writeXmlValue($xml, 'Address', (string) $ad['address']);
        $this->writeXmlValue($xml, 'ContactMethod', (string) $ad['contact_method']);
        $this->writeXmlValue($xml, 'Category', (string) ($ad['category'] !== '' ? $ad['category'] : $defaults['category']));

        if ((string) $ad['description'] !== '') {
            $xml->startElement('Description');
            $xml->writeCdata((string) $ad['description']);
            $xml->endElement();
        }

        $images = array_slice($ad['images'], 0, 10);
        if ($images !== []) {
            $xml->startElement('Images');
            foreach ($images as $url) {
                $xml->startElement('Image');
                $xml->writeAttribute('url', (string) $url);
                $xml->endElement();
            }
            $xml->endElement();
        }

        $this->writeXmlValue($xml, 'Title', (string) $ad['title']);
        $xml->writeElement('Price', (string) (int) $ad['price']);
        $this->writeXmlValue($xml, 'GoodsType', (string) ($ad['goods_type'] !== '' ? $ad['goods_type'] : $defaults['goods_type']));
        $this->writeXmlValue($xml, 'AdType', (string) $ad['ad_type']);
        $this->writeXmlValue($xml, 'ProductType', (string) ($ad['product_type'] !== '' ? $ad['product_type'] : $defaults['product_type']));
        $this->writeXmlValue($xml, 'SparePartType', (string) ($ad['spare_part_type'] !== '' ? $ad['spare_part_type'] : $defaults['spare_part_type']));
        $this->writeXmlValue($xml, 'EngineSparePartType', (string) $ad['engine_type']);
        $this->writeXmlValue($xml, 'Condition', $this->mapConditionForXml((string) $ad['condition']));
        $this->writeXmlValue($xml, 'Originality', (string) $ad['origin']);
        $this->writeXmlValue($xml, 'Availability', (string) $ad['availability']);
        $this->writeXmlValue($xml, 'Brand', (string) $ad['brand']);
        $this->writeXmlValue($xml, 'OEM', (string) $ad['oem']);
        $this->writeXmlValue($xml, 'TypeId', (string) $ad['type_id']);
        $this->writeXmlValue($xml, 'DateEnd', $this->toXmlDateEnd((string) $ad['date_end']));
        $this->writeXmlValue($xml, 'AvitoStatus', (string) $ad['avito_status']);
        $this->writeXmlValue($xml, 'ManagerName', (string) $ad['company_name']);
        $xml->endElement();
    }

    private function writeXmlValue(\XMLWriter $xml, string $name, string $value): void
    {
        if ($value === '') {
            return;
        }
        $xml->writeElement($name, $value);
    }

    /**
     * @return array{category: string, goods_type: string, product_type: string, spare_part_type: string}
     */
    private function xmlCategoryDefaults(): array
    {
        $path = (string) ($this->config['feed']['category'] ?? '');
        $parts = array_values(array_filter(array_map('trim', explode(' - ', $path)), static fn(string $part): bool => $part !== ''));

        return [
            'category' => $parts[1] ?? 'Запчасти и аксессуары',
            'goods_type' => $parts[2] ?? 'Запчасти',
            'product_type' => $parts[3] ?? 'Для автомобилей',
            'spare_part_type' => $parts[4] ?? 'Двигатель',
        ];
    }

    private function mapConditionForXml(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'new', 'новое' => 'Новое',
            'used', 'б/у', 'bu', 'б.у.', 'б.у' => 'Б/у',
            default => $value,
        };
    }

    /**
     * CSV хранит AvitoDateEnd как dd.MM_yy, XML DateEnd — dd.MM.yyyy.
     */
    private function toXmlDateEnd(string $csvDateEnd): string
    {
        if (preg_match('/^(\d{2})\.(\d{2})_(\d{2,4})$/', $csvDateEnd, $matches) === 1) {
            $year = $matches[3];
            if (strlen($year) === 2) {
                $year = '20' . $year;
            }
            return "{$matches[1]}.{$matches[2]}.{$year}";
        }

        return (new \DateTimeImmutable('+30 days'))->format('d.m.Y');
    }

    /**
     * Получить всех кандидатов из partition-таблиц republish_candidates_*
     *
     * @return list<array>
     */
    private function getCandidates(): array
    {
        return $this->repository->getCandidates();
    }

    /**
     * Парсить изображения из колонки БД (JSON array)
     *
     * @return list<string>
     */
    private function parseImages(array $ad): array
    {
        $imagesJson = (string) ($ad['images'] ?? '');
        if ($imagesJson === '') {
            return [];
        }

        $images = json_decode($imagesJson, true);
        if (!is_array($images)) {
            return [];
        }

        $urls = [];
        foreach ($images as $img) {
            if (is_string($img) && $img !== '') {
                $urls[] = $img;
            } elseif (is_array($img)) {
                $url = $img['url'] ?? $img['thumb_url'] ?? '';
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Парсить категорийные параметры из колонки БД (JSON object)
     *
     * @return array<string, string>
     */
    private function parseCategoryParams(array $ad): array
    {
        $paramsJson = (string) ($ad['category_params'] ?? '');
        if ($paramsJson === '') {
            return [];
        }

        $params = json_decode($paramsJson, true);
        if (!is_array($params)) {
            return [];
        }

        // Приводим все значения к string
        $result = [];
        foreach ($params as $key => $value) {
            $result[(string) $key] = (string) $value;
        }

        return $result;
    }

    /**
     * Сгенерировать AvitoDateEnd в формате dd.MM_YY
     * Пример из эталона: 13.07_176
     */
    private function generateAvitoDateEnd(): string
    {
        $endDate = new \DateTimeImmutable('+30 days');
        $day = $endDate->format('d');
        $month = $endDate->format('m');
        $yearShort = (int) $endDate->format('y');

        return "{$day}.{$month}_{$yearShort}";
    }

    /**
     * Получить AvitoStatus
     */
    private function getAvitoStatus(array $ad): string
    {
        $status = (string) ($ad['status'] ?? 'active');
        $validStatuses = ['active', 'removed', 'old', 'blocked', 'rejected'];
        return in_array($status, $validStatuses, true) ? $status : 'active';
    }

    /**
     * Получить информацию о последней генерации
     *
     * @return array{last_file?: string, last_count?: int, last_date?: string}
     */
    public function getLastGeneration(): array
    {
        $feedDir = $this->outputDir;
        if (!is_dir($feedDir)) {
            return [];
        }

        $files = glob($feedDir . '/avito_feed_*.xml') ?: [];
        if ($files === []) {
            return [];
        }

        usort($files, static function ($a, $b) {
            return strcmp(basename($b), basename($a));
        });

        $lastFile = $files[0];
        $basename = basename($lastFile);

        if (preg_match('/avito_feed_(\d{4}-\d{2}-\d{2})/', $basename, $matches)) {
            $date = $matches[1];
        } else {
            $date = date('Y-m-d', filemtime($lastFile));
        }

        $contents = (string) file_get_contents($lastFile);
        $lineCount = preg_match_all('/<Ad\b/', $contents);

        return [
            'last_file' => $lastFile,
            'last_count' => $lineCount,
            'last_date' => $date,
        ];
    }

    /**
     * Удалить кандидатов из БД после включения в фид
     *
     * @param list<string> $avitoIds Avito ID кандидатов, включённых в фид
     */
    public function removeCandidatesFromDb(array $avitoIds): void
    {
        if (empty($avitoIds)) {
            return;
        }

        $removed = 0;
        
        // Получаем ВСЕХ кандидатов из partition-таблиц
        $allCandidates = $this->repository->getCandidates();
        
        // Создаём мапу avito_id -> physical_ad_id
        $candidateMap = [];
        foreach ($allCandidates as $candidate) {
            $avitoId = (string) ($candidate['avito_id'] ?? '');
            if ($avitoId !== '') {
                $candidateMap[$avitoId] = (int) ($candidate['physical_ad_id'] ?? 0);
            }
        }
        
        // Удаляем кандидатов, которые были включены в фид
        foreach ($avitoIds as $avitoId) {
            if (isset($candidateMap[$avitoId])) {
                $physicalAdId = $candidateMap[$avitoId];
                $this->repository->removeCandidate($physicalAdId);
                $removed++;
            }
        }

        if ($removed > 0) {
            echo "  Удалено кандидатов из БД: {$removed}\n";
        } else {
            // Отладка: покажем первые 5 avito_ids из фида и из БД
            $dbAvitoIds = array_keys($candidateMap);
            echo "  [DEBUG] Avito IDs в фиде: " . implode(', ', array_slice($avitoIds, 0, 5)) . "...\n";
            echo "  [DEBUG] Avito IDs в БД: " . implode(', ', array_slice($dbAvitoIds, 0, 5)) . "...\n";
            echo "  [DEBUG] Совпадений: 0 из " . count($avitoIds) . "\n";
        }
    }

    /**
     * Перегнать существующий CSV-фид в XML того же состава.
     */
    public function convertCsvToXml(string $csvPath, ?string $xmlPath = null): string
    {
        $ads = $this->adsFromCsv($csvPath);

        if ($xmlPath === null) {
            $xmlPath = preg_replace('/\.csv$/i', '.xml', $csvPath) ?? ($csvPath . '.xml');
        }

        $this->writeXml($xmlPath, $ads);
        echo "  XML из CSV: {$xmlPath} (" . count($ads) . " объявлений)\n";

        return $xmlPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function adsFromCsv(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            throw new \RuntimeException("CSV не найден: {$csvPath}");
        }

        $raw = file($csvPath, FILE_IGNORE_NEW_LINES);
        if ($raw === false || $raw === []) {
            throw new \RuntimeException("CSV пустой: {$csvPath}");
        }

        $raw[0] = preg_replace('/^\xEF\xBB\xBF/', '', $raw[0]) ?? $raw[0];
        $headerIndex = null;
        $ads = [];

        foreach ($raw as $line) {
            $row = $this->parseCsvLine($line);
            if ($row === []) {
                continue;
            }

            if ($headerIndex === null) {
                $headerIndex = [];
                foreach ($row as $i => $header) {
                    $headerIndex[trim((string) $header)] = $i;
                }
                continue;
            }

            $get = static function (string $name) use ($row, $headerIndex): string {
                $i = $headerIndex[$name] ?? null;
                return $i === null ? '' : (string) ($row[$i] ?? '');
            };

            $uniqueId = $get('Уникальный идентификатор объявления');
            if ($uniqueId === '') {
                continue;
            }

            $imagesRaw = $get('Ссылки на фото');
            $images = $imagesRaw === '' ? [] : array_values(array_filter(explode('|', $imagesRaw)));

            $ads[] = [
                'unique_id' => $uniqueId,
                'listing_fee' => $get('Способ размещения'),
                'avito_id' => $get('Номер объявления на Авито'),
                'phone' => $get('Номер телефона'),
                'address' => $get('Адрес'),
                'contact_method' => $get('Способ связи'),
                'category' => $get('Категория'),
                'description' => $get('Описание объявления'),
                'images' => $images,
                'title' => $get('Название объявления'),
                'price' => (int) $get('Цена'),
                'goods_type' => $get('Вид товара'),
                'ad_type' => $get('Вид объявления'),
                'product_type' => $get('Тип товара'),
                'spare_part_type' => $get('Вид запчасти'),
                'engine_type' => $get('Тип детали двигателя'),
                'condition' => $get('Состояние'),
                'origin' => $get('Происхождение'),
                'availability' => $get('Доступность'),
                'brand' => $get('Производитель'),
                'oem' => $get('Номер детали OEM'),
                'type_id' => $get('TypeID'),
                'date_end' => $get('AvitoDateEnd'),
                'avito_status' => $get('AvitoStatus'),
                'company_name' => $get('Название компании'),
                'email' => $get('Почта'),
            ];
        }

        return $ads;
    }

    /**
     * Строка текущего CSV: целиком в кавычках и с хвостом `;;`.
     *
     * @return list<string>
     */
    private function parseCsvLine(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        $line = preg_replace('/;;\s*$/', '', $line) ?? $line;
        if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
            $line = str_replace('""', '"', substr($line, 1, -1));
        }

        $row = str_getcsv($line, ',');
        if ($row === false) {
            return [];
        }

        return array_map(static fn($value): string => trim((string) $value), $row);
    }
}
