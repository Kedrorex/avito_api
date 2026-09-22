<?php

namespace App\Services;

use App\Repositories\ItemRepository;
use App\Services\AvitoAPIClient;

/**
 * Генератор фида для Avito AutoLoad (CSV)
 *
 * Формат: CSV UTF-8 (разделитель ,)
 * Категория: Транспорт - Запчасти и аксессуары - Запчасти - Для автомобилей - Двигатель
 *
 * 26 столбцов по эталону: fid/Рабочий образец.csv
 * Данные читаются из колонок БД physical_ads
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
     * Сгенерировать CSV файл с объявлениями
     *
     * @param bool $priorityMode Если true — кандидаты на переопубликовку идут первыми
     *
     * @return array{file: string, count: int, headers: string[]}
     */
    public function generate(bool $priorityMode = true): array
    {
        $activeAds = $this->repository->getActive();

        if (empty($activeAds)) {
            echo "  Нет активных объявлений для выгрузки\n";
            return ['file' => '', 'count' => 0, 'headers' => []];
        }

        $date = date('Y-m-d');
        $filename = "avito_feed_{$date}.csv";
        $filepath = $this->outputDir . '/' . $filename;

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $headers = $this->getHeaders();

        $handle = fopen($filepath, 'w', false);
        if ($handle === false) {
            throw new \RuntimeException("Не удалось создать файл фида: {$filepath}");
        }

        // UTF-8 BOM
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $headers, ',');

        // 1. Сначала кандидаты (AvitoStatus=removed — деактивация)
        $candidates = $this->getCandidates();
        
        // Ограничиваем количество кандидатов по лимиту
        if ($this->maxCandidates > 0 && count($candidates) > $this->maxCandidates) {
            $candidates = array_slice($candidates, 0, $this->maxCandidates);
        }
        
        $candidateAvitoIds = [];
        $count = 0;
        foreach ($candidates as $candidate) {
            $row = $this->buildRow($candidate, 'removed');
            if ($row !== null) {
                fputcsv($handle, $row, ',');
                $count++;
                $candidateAvitoIds[] = (string) ($candidate['avito_id'] ?? '');
            }
        }

        // 2. Потом все active (AvitoStatus=active — активация/обновление)
        foreach ($activeAds as $ad) {
            // Пропускаем кандидатов — они уже добавлены выше
            if (in_array((string) ($ad['avito_id'] ?? ''), $candidateAvitoIds, true)) {
                continue;
            }
            $row = $this->buildRow($ad, 'active');
            if ($row !== null) {
                fputcsv($handle, $row, ',');
                $count++;
            }
        }

        fclose($handle);

        echo "  Файл создан: {$filepath} ({$count} строк: " . count($candidates) . " remove + " . ($count - count($candidates)) . " active)\n";

        return [
            'file' => $filepath,
            'count' => $count,
            'headers' => $headers,
            'candidate_avito_ids' => $candidateAvitoIds,
        ];
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
     * Сформировать строку CSV для одного объявления
     * Данные читаются из колонок БД
     *
     * @param array $ad Данные объявления
     * @param string $avitoStatus Явный статус Avito ('active'/'removed') — если пустой, определяется автоматически
     */
    private function buildRow(array $ad, string $avitoStatus = ''): ?array
    {
        // 1. Уникальный идентификатор
        $uniqueId = $ad['unique_id'] ?? '';
        if ($uniqueId === '') {
            $uniqueId = $ad['avito_id'] ?? "ad_{$ad['id']}";
        }

        // 2. Способ размещения
        $placementMethod = $this->config['feed']['default_views'] ?? 'Package';

        // 3. Номер объявления на Авито
        $avitoNumber = (string) ($ad['avito_id'] ?? '');

        // 4. Номер телефона — из колонки БД
        $phone = (string) ($ad['phone'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone !== '' && !str_starts_with($phone, '7')) {
            $phone = '7' . $phone;
        }

        // 5. Адрес — из колонки БД
        $address = (string) ($ad['location'] ?? '');

        // 6. Способ связи — из колонки БД
        $contactMethod = (string) ($ad['contact_method'] ?? '');

        // 7. Категория
        $category = $ad['category'] ?? [];
        $categoryName = is_array($category) ? ($category['name'] ?? '') : '';

        // 8. Описание — из колонки БД
        $description = (string) ($ad['description'] ?? '');
        if ($description === '') {
            // Fallback: собираем из title + location
            $title = (string) ($ad['title'] ?? '');
            $parts = [$title];
            if ($address !== '') {
                $parts[] = "Местоположение: {$address}";
            }
            $description = implode("\n", $parts);
        }

        // 9. Ссылки на фото — из колонки БД (JSON array)
        $images = $this->parseImages($ad);

        // 10. Название объявления — из колонки БД
        $title = (string) ($ad['title'] ?? '');

        // 11. Цена — из колонки БД
        $price = (int) ($ad['price'] ?? 0);

        // 12-17. Категорийные параметры — из колонки БД (JSON)
        $catParams = $this->parseCategoryParams($ad);
        $productType = $catParams['Вид товара'] ?? '';
        $partType = $catParams['Вид запчасти'] ?? '';
        $engineType = $catParams['Тип детали двигателя'] ?? '';
        $condition = $catParams['Состояние'] ?? ($this->config['feed']['default_condition'] ?? 'new');

        // Дефолты если пустые
        if ($productType === '') $productType = $this->config['feed']['default_product_type'] ?? '';
        if ($partType === '') $partType = $this->config['feed']['default_part_type'] ?? '';
        if ($engineType === '') $engineType = $this->config['feed']['default_engine_type'] ?? '';

        // 18. Происхождение
        $origin = $catParams['Происхождение'] ?? '';

        // 19. Доступность
        $availability = $catParams['Доступность'] ?? '';

        // 20. Производитель — из колонки БД
        $brand = (string) ($ad['brand'] ?? '');

        // 21. Номер детали OEM — из колонки БД
        $oem = (string) ($ad['oem_number'] ?? '');

        // 22. TypeID
        $typeId = $catParams['TypeID'] ?? '';

        // 23. AvitoDateEnd
        $avitoDateEnd = $this->generateAvitoDateEnd();

        // 24. AvitoStatus — если передан явно, используем его, иначе определяем автоматически
        if ($avitoStatus === '') {
            $avitoStatus = $this->getAvitoStatus($ad);
        }

        // 25. Название компании
        $companyName = $this->config['feed']['company_name'] ?? '';

        // 26. Почта
        $email = $this->config['feed']['email'] ?? '';

        return [
            $uniqueId,                                    // 1. Уникальный идентификатор
            $placementMethod,                             // 2. Способ размещения
            $avitoNumber,                                 // 3. Номер объявления на Авито
            $phone,                                       // 4. Номер телефона
            $address,                                     // 5. Адрес
            $contactMethod,                               // 6. Способ связи
            $categoryName ?: 'Запчасти и аксессуары',    // 7. Категория
            $description,                                 // 8. Описание объявления
            implode('|', $images),                        // 9. Ссылки на фото
            $title,                                       // 10. Название объявления
            $price,                                       // 11. Цена
            $productType,                                 // 12. Вид товара
            $this->config['feed']['default_ad_type'] ?? '', // 13. Вид объявления
            $productType,                                 // 14. Тип товара
            $partType,                                    // 15. Вид запчасти
            $engineType,                                  // 16. Тип детали двигателя
            $condition,                                   // 17. Состояние
            $origin,                                      // 18. Происхождение
            $availability,                                // 19. Доступность
            $brand,                                       // 20. Производитель
            $oem,                                         // 21. Номер детали OEM
            $typeId,                                      // 22. TypeID
            $avitoDateEnd,                                // 23. AvitoDateEnd
            $avitoStatus,                                 // 24. AvitoStatus
            $companyName,                                 // 25. Название компании
            $email,                                       // 26. Почта
        ];
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

        $files = glob($feedDir . '/avito_feed_*.csv');
        if (empty($files)) {
            return [];
        }

        usort($files, function ($a, $b) {
            return strcmp(basename($b), basename($a));
        });

        $lastFile = $files[0];
        $basename = basename($lastFile, '.csv');

        if (preg_match('/avito_feed_(\d{4}-\d{2}-\d{2})/', $basename, $matches)) {
            $date = $matches[1];
        } else {
            $date = date('Y-m-d', filemtime($lastFile));
        }

        $lineCount = count(file($lastFile)) - 1;

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
}
