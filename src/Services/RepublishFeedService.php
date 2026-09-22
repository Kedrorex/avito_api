<?php

namespace App\Services;

use App\Repositories\ItemRepository;
use App\Services\AvitoAPIClient;

/**
 * Сервис генерации CSV для переопубликования через Avito AutoLoad
 *
 * Логика:
 * 1. Генерирует ОДИН фид с mixed operations
 * 2. Сначала все remove (снять с публикации)
 * 3. Потом все update (вновь включить с полными данными)
 *
 * Формат: CSV UTF-8 (разделитель ,)
 */
class RepublishFeedService
{
    private ItemRepository $repository;
    private AvitoAPIClient $apiClient;
    private array $config;
    private string $outputDir;

    /**
     * @param array{
     *     feed?: array{
     *         output_dir?: string,
     *         category?: string,
     *         default_condition?: string,
     *         default_views?: string,
     *         default_ad_type?: string,
     *         default_product_type?: string,
     *         default_part_type?: string,
     *         default_engine_type?: string,
     *         company_name?: string,
     *         email?: string,
     *         ...
     *     }
     * } $config
     */
    public function __construct(
        ItemRepository $repository,
        AvitoAPIClient $apiClient,
        array $config
    ) {
        $this->repository = $repository;
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->outputDir = $config['feed']['output_dir'] ?? __DIR__ . '/../../fid';
    }

    /**
     * Сгенерировать ОДИН фид для переопубликования
     *
     * @param list<array{physical_ad_id: int, avito_id: string, logical_key: string, added_at: string}> $candidates
     * @param int $count Количество кандидатов для включения в фид (до 70)
     *
     * @return array{
     *     feed_file: string,
     *     count: int,
     *     candidates: list<array>
     * }
     */
    public function generate(array $candidates, int $count): array
    {
        if (empty($candidates)) {
            echo "  Нет кандидатов для генерации фида\n";
            return [
                'feed_file' => '',
                'count' => 0,
                'candidates' => [],
            ];
        }

        // Берём первые $count кандидатов
        $selected = array_slice($candidates, 0, $count);

        // Получаем полные данные объявлений
        $ads = [];
        foreach ($selected as $candidate) {
            $ad = $this->repository->getById((int) $candidate['physical_ad_id']);
            if ($ad !== null) {
                $ads[] = $ad;
            }
        }

        if (empty($ads)) {
            echo "  Нет данных об объявлениях для фида\n";
            return [
                'feed_file' => '',
                'count' => 0,
                'candidates' => [],
            ];
        }

        // Создаём директорию если не существует
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        // Формируем имя файла с датой
        $date = date('Y-m-d');
        $filename = "avito_feed_repub_{$date}.csv";
        $filepath = $this->outputDir . '/' . $filename;

        // Заголовки фида
        $headers = $this->getHeaders();

        $handle = fopen($filepath, 'w', false);
        if ($handle === false) {
            throw new \RuntimeException("Не удалось создать файл фида: {$filepath}");
        }

        // UTF-8 BOM
        fwrite($handle, "\xEF\xBB\xBF");

        // Записываем заголовки (CSV)
        fputcsv($handle, $headers, ',');

        // Записываем строки: сначала remove, потом update
        foreach ($ads as $ad) {
            // Строка 1: remove (снять с публикации)
            $removeRow = $this->buildRemoveRow($ad);
            if ($removeRow !== []) {
                fputcsv($handle, $removeRow, ',');
            }

            // Строка 2: update (вновь включить с полными данными)
            $updateRow = $this->buildUpdateRow($ad);
            if ($updateRow !== []) {
                fputcsv($handle, $updateRow, ',');
            }
        }

        fclose($handle);

        echo "  ============================================\n";
        echo "  ФИД СГЕНЕРИРОВАН:\n";
        echo "    Кандидатов:    " . count($ads) . "\n";
        echo "    Файл:          " . basename($filepath) . "\n";
        echo "    Строк:         " . (count($ads) * 2) . " (remove + update)\n";
        echo "  ============================================\n";

        return [
            'feed_file' => $filepath,
            'count' => count($ads),
            'candidates' => $ads,
        ];
    }

    /**
     * Заголовки фида (CSV) — 26 столбцов по эталону
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
     * Построить строку для операции remove (снять с публикации)
     */
    private function buildRemoveRow(array $ad): array
    {
        $uniqueId = $ad['unique_id'] ?: ($ad['avito_id'] ?? '');
        $avitoId = (string) ($ad['avito_id'] ?? '');

        if ($uniqueId === '') {
            return [];
        }

        // Телефон — из колонки БД
        $phone = (string) ($ad['phone'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone !== '' && !str_starts_with($phone, '7')) {
            $phone = '7' . $phone;
        }

        // Адрес — из колонки БД
        $address = (string) ($ad['location'] ?? '');

        // Категория
        $category = $ad['category'] ?? [];
        $categoryName = is_array($category) ? ($category['name'] ?? '') : '';

        // Категорийные параметры — из колонки БД
        $catParams = $this->parseCategoryParams($ad);
        $condition = $catParams['Состояние'] ?: ($this->config['feed']['default_condition'] ?? 'new');

        // AvitoStatus для remove
        $avitoStatus = 'removed';

        // Company info
        $companyName = $this->config['feed']['company_name'] ?? '';
        $email = $this->config['feed']['email'] ?? '';
        $avitoDateEnd = $this->generateAvitoDateEnd();

        return [
            $uniqueId,                                    // 1. Уникальный идентификатор
            $this->config['feed']['default_views'] ?? '', // 2. Способ размещения
            $avitoId,                                     // 3. Номер объявления на Авито
            $phone,                                       // 4. Номер телефона
            $address,                                     // 5. Адрес
            '',                                           // 6. Способ связи
            $categoryName ?: 'Запчасти и аксессуары',    // 7. Категория
            '',                                           // 8. Описание объявления
            '',                                           // 9. Ссылки на фото
            '',                                           // 10. Название объявления
            0,                                            // 11. Цена
            '',                                           // 12. Вид товара
            '',                                           // 13. Вид объявления
            '',                                           // 14. Тип товара
            '',                                           // 15. Вид запчасти
            '',                                           // 16. Тип детали двигателя
            $condition,                                   // 17. Состояние
            '',                                           // 18. Происхождение
            '',                                           // 19. Доступность
            '',                                           // 20. Производитель
            '',                                           // 21. Номер детали OEM
            '',                                           // 22. TypeID
            $avitoDateEnd,                                // 23. AvitoDateEnd
            $avitoStatus,                                 // 24. AvitoStatus
            $companyName,                                 // 25. Название компании
            $email,                                       // 26. Почта
        ];
    }

    /**
     * Построить строку для операции update (вновь включить с полными данными)
     */
    private function buildUpdateRow(array $ad): array
    {
        $uniqueId = $ad['unique_id'] ?: ($ad['avito_id'] ?? '');
        $avitoId = (string) ($ad['avito_id'] ?? '');

        if ($uniqueId === '') {
            return [];
        }

        // Название — из колонки БД
        $title = (string) ($ad['title'] ?? '');

        // Цена — из колонки БД
        $price = (int) ($ad['price'] ?? 0);

        // Телефон — из колонки БД
        $phone = (string) ($ad['phone'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone !== '' && !str_starts_with($phone, '7')) {
            $phone = '7' . $phone;
        }

        // Адрес — из колонки БД
        $address = (string) ($ad['location'] ?? '');

        // Контактный метод — из колонки БД
        $contactMethod = (string) ($ad['contact_method'] ?? '');

        // Категория
        $category = $ad['category'] ?? [];
        $categoryName = is_array($category) ? ($category['name'] ?? '') : '';

        // Описание — из колонки БД
        $description = (string) ($ad['description'] ?? '');
        if ($description === '') {
            $description = $title;
        }

        // Изображения — из колонки БД
        $images = $this->parseImages($ad);

        // Категорийные параметры — из колонки БД
        $catParams = $this->parseCategoryParams($ad);
        $productType = $catParams['Вид товара'] ?? '';
        $partType = $catParams['Вид запчасти'] ?? '';
        $engineType = $catParams['Тип детали двигателя'] ?? '';
        $condition = $catParams['Состояние'] ?? ($this->config['feed']['default_condition'] ?? 'new');

        if ($productType === '') $productType = $this->config['feed']['default_product_type'] ?? '';
        if ($partType === '') $partType = $this->config['feed']['default_part_type'] ?? '';
        if ($engineType === '') $engineType = $this->config['feed']['default_engine_type'] ?? '';

        // Производитель и OEM — из колонок БД
        $brand = (string) ($ad['brand'] ?? '');
        $oem = (string) ($ad['oem_number'] ?? '');

        // Происхождение и доступность
        $origin = $catParams['Происхождение'] ?? '';
        $availability = $catParams['Доступность'] ?? '';
        $typeId = $catParams['TypeID'] ?? '';

        // Company info
        $companyName = $this->config['feed']['company_name'] ?? '';
        $email = $this->config['feed']['email'] ?? '';
        $avitoDateEnd = $this->generateAvitoDateEnd();

        return [
            $uniqueId,                                    // 1. Уникальный идентификатор
            $this->config['feed']['default_views'] ?? '', // 2. Способ размещения
            $avitoId,                                     // 3. Номер объявления на Авито
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
            'active',                                     // 24. AvitoStatus
            $companyName,                                 // 25. Название компании
            $email,                                       // 26. Почта
        ];
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

        $result = [];
        foreach ($params as $key => $value) {
            $result[(string) $key] = (string) $value;
        }

        return $result;
    }

    private function generateAvitoDateEnd(): string
    {
        $endDate = new \DateTimeImmutable('+30 days');
        $day = $endDate->format('d');
        $month = $endDate->format('m');
        $yearShort = (int) $endDate->format('y');
        return "{$day}.{$month}_{$yearShort}";
    }

    /**
     * Получить список кандидатов с полными данными
     *
     * @return list<array{physical_ad_id: int, avito_id: string, logical_key: string, added_at: string}>
     */
    public function getCandidates(): array
    {
        return $this->repository->getCandidates();
    }

    /**
     * Получить количество кандидатов
     */
    public function getCandidateCount(): int
    {
        return $this->repository->getCandidateCountForMonth(
            (int) date('Y'),
            (int) date('m')
        );
    }
}
