<?php

namespace App\Services;

use App\Repositories\ItemRepository;
use App\Support\XlsxReader;

/**
 * Импорт файла выгрузки Автозагрузки в БД.
 *
 * Файл берётся по ссылке из отчёта Авито (GET /autoload/v4/uploads),
 * поэтому скачивать его из кабинета руками не нужно.
 *
 * Поля вроде фото, описания, производителя и OEM есть только в этом файле:
 * API объявлений их не отдаёт.
 */
class AutoloadFeedImportService
{
    private const META_LAST_UPLOAD = 'autoload_last_upload_id';

    private const KEY_UNIQUE_ID = 'Уникальный идентификатор объявления';
    private const KEY_AVITO_ID = 'Номер объявления на Авито';

    /** Заголовок файла => колонка БД. */
    private const COLUMN_MAP = [
        self::KEY_UNIQUE_ID => 'unique_id',
        'Id' => 'unique_id',
        'Номер телефона' => 'phone',
        'ContactPhone' => 'phone',
        'Способ связи' => 'contact_method',
        'ContactMethod' => 'contact_method',
        'Адрес' => 'location',
        'Address' => 'location',
        'Addresses' => 'location',
        'Описание объявления' => 'description',
        'Description' => 'description',
        'Ссылки на фото' => 'images',
        'ImageUrls' => 'images',
        'Название объявления' => 'title',
        'Title' => 'title',
        'Цена' => 'price',
        'Price' => 'price',
        'Производитель' => 'brand',
        'Brand' => 'brand',
        'Номер детали OEM' => 'oem_number',
        'OEM' => 'oem_number',
    ];

    /** Заголовки, которые складываются в category_params. */
    private const CATEGORY_KEYS = [
        'Вид товара',
        'Вид объявления',
        'Тип товара',
        'Вид запчасти',
        'Тип детали двигателя',
        'Состояние',
        'Происхождение',
        'Доступность',
        'TypeID',
        'Марка',
        'Включая НДС',
        'Авто для которых подходит запчасть',
        'WholesaleTypePredict2Param',
        'GoodsType',
        'AdType',
        'ProductType',
        'SparePartType',
        'EngineSparePartType',
        'Condition',
        'Originality',
        'Availability',
        'NDS',
        'WholesaleType',
    ];

    private const ALLOWED_COLUMNS = [
        'unique_id',
        'phone',
        'contact_method',
        'location',
        'description',
        'images',
        'title',
        'price',
        'brand',
        'oem_number',
        'category_params',
    ];

    private AvitoAPIClient $apiClient;
    private ItemRepository $repository;
    private array $config;
    private string $downloadDir;

    public function __construct(AvitoAPIClient $apiClient, ItemRepository $repository, array $config)
    {
        $this->apiClient = $apiClient;
        $this->repository = $repository;
        $this->config = $config;
        $outputDir = $config['feed']['output_dir'] ?? __DIR__ . '/../../fid';
        $this->downloadDir = $config['feed']['autoload_download_dir'] ?? ($outputDir . '/autoload');
    }

    /**
     * Скачать последнюю выгрузку и записать её в БД.
     *
     * @return array{
     *     status: string,
     *     upload_id: string,
     *     file: string,
     *     rows: int,
     *     updated: int,
     *     missing: int
     * }
     */
    public function syncFromApi(bool $force = false): array
    {
        $empty = [
            'status' => 'skipped',
            'upload_id' => '',
            'file' => '',
            'rows' => 0,
            'updated' => 0,
            'missing' => 0,
        ];

        try {
            $upload = $this->apiClient->getLatestUploadWithFeed();
        } catch (\Throwable $e) {
            echo "  [ERROR] " . $this->explainError($e) . "\n";
            $empty['status'] = 'error';
            return $empty;
        }

        if ($upload === null) {
            echo "  В отчётах Автозагрузки нет ссылки на файл\n";
            return $empty;
        }

        $uploadId = $upload['upload_id'];
        echo "  Выгрузка #{$uploadId} от {$upload['started_at']} ({$upload['status']})\n";

        $lastImported = $this->repository->getMeta(self::META_LAST_UPLOAD);
        if (!$force && $lastImported === $uploadId) {
            echo "  Эта выгрузка уже импортирована — пропуск (--force чтобы повторить)\n";
            $empty['upload_id'] = $uploadId;
            return $empty;
        }

        try {
            $downloaded = $this->apiClient->downloadFeedContent($upload['feed_urls'][0]['url'], $this->downloadDir);
        } catch (\Throwable $e) {
            echo "  [ERROR] " . $this->explainError($e) . "\n";
            $empty['status'] = 'error';
            $empty['upload_id'] = $uploadId;
            return $empty;
        }

        echo "  Файл: {$downloaded['filename']} (" . $this->formatSize($downloaded['bytes']) . ")\n";
        flush();

        $result = $this->importFile($downloaded['path']);
        $result['upload_id'] = $uploadId;

        if ($result['status'] === 'ok') {
            $this->repository->setMeta(self::META_LAST_UPLOAD, $uploadId);
        }

        return $result;
    }

    /**
     * Дописать пустые «Производитель» и «Номер детали OEM» из файла кабинета.
     *
     * Полный импорт пропускает уже скачанную выгрузку, поэтому новые поколения
     * и строки, которые не совпали по номеру, остаются без этих полей.
     * Пишутся только пустые колонки.
     *
     * @return array{missing: int, updated: int, still_empty: int}
     */
    public function fillMissingBrandOem(): array
    {
        $result = ['missing' => 0, 'updated' => 0, 'still_empty' => 0];
        $ads = $this->repository->listAdsMissingBrandOrOem();
        $result['missing'] = count($ads);
        if ($ads === []) {
            echo "  Производитель и OEM заполнены у всех активных\n";
            return $result;
        }

        echo "  Пустой производитель или OEM: {$result['missing']}\n";
        flush();

        $path = $this->resolveCabinetFeedFile();
        if ($path === null) {
            $result['still_empty'] = $result['missing'];
            return $result;
        }

        echo "  Производитель и OEM из {$path}\n";
        flush();
        $index = $this->brandOemIndex($path);
        $indexed = max(count($index['by_avito']), count($index['by_unique']));
        echo "  В файле строк с производителем или OEM: {$indexed}\n";

        $this->repository->beginWriteTransaction();
        try {
            foreach ($ads as $ad) {
                $found = $this->lookupBrandOem($index, $ad);
                $update = [];
                if ($this->isBlank($ad['brand'] ?? null) && ($found['brand'] ?? '') !== '') {
                    $update['brand'] = $found['brand'];
                }
                if ($this->isBlank($ad['oem_number'] ?? null) && ($found['oem'] ?? '') !== '') {
                    $update['oem_number'] = $found['oem'];
                }
                if ($update === []) {
                    $result['still_empty']++;
                    continue;
                }

                $this->repository->updatePhysical((int) $ad['id'], $update);
                $result['updated']++;
            }
            $this->repository->commit();
        } catch (\Throwable $e) {
            $this->repository->rollBack();
            throw $e;
        }

        echo "  Дописано объявлений: {$result['updated']}\n";
        if ($result['still_empty'] > 0) {
            echo "  В кабинете нет данных: {$result['still_empty']}\n";
        }

        return $result;
    }

    /**
     * Импортировать файл с диска: XLSX или CSV.
     *
     * @param bool $dryRun Только разобрать файл и показать сводку, без записи в БД
     * @return array{status: string, upload_id: string, file: string, rows: int, updated: int, missing: int}
     */
    public function importFile(string $path, bool $dryRun = false, ?array $onlyColumns = null): array
    {
        $result = [
            'status' => 'ok',
            'upload_id' => '',
            'file' => $path,
            'rows' => 0,
            'updated' => 0,
            'missing' => 0,
        ];

        if (!is_file($path)) {
            echo "  Файл не найден: {$path}\n";
            $result['status'] = 'error';
            return $result;
        }

        $rows = $this->collectRows($path);

        if ($rows === []) {
            echo "  В файле не найдено строк с номерами объявлений\n";
            $result['status'] = 'error';
            return $result;
        }

        $result['rows'] = count($rows);
        echo "  Строк с данными: " . count($rows) . "\n";
        flush();

        if ($dryRun) {
            $this->printDryRun($rows);
            $result['status'] = 'dry-run';
            return $result;
        }

        $allowed = $onlyColumns ?? self::ALLOWED_COLUMNS;
        $applied = $this->repository->updateFeedColumnsByAvitoId($rows, $allowed);
        $result['updated'] = $applied['updated'];
        $result['missing'] = $applied['missing'];

        echo "  Обновлено объявлений: {$applied['updated']}\n";
        echo "  Поколениям дописаны производитель, OEM, описание и фото: " . ($applied['copied'] ?? 0) . "\n";
        echo "  Нет в БД: {$applied['missing']}\n";

        return $result;
    }

    /**
     * Описание и ссылки на фото по Id автозагрузки.
     * HTML описания сохраняется: в фиде он уходит в CDATA как в исходном файле.
     *
     * @return array<string, array{description: string, images: string}>
     */
    public function listingContentByUniqueId(string $path): array
    {
        $index = [];
        foreach ($this->collectRows($path) as $lookupKey => $columns) {
            $uniqueId = trim((string) ($columns['unique_id'] ?? ''));
            if ($uniqueId === '' && !ctype_digit((string) $lookupKey)) {
                $uniqueId = trim((string) $lookupKey);
            }
            if ($uniqueId === '') {
                continue;
            }

            $description = trim((string) ($columns['description'] ?? ''));
            $images = trim((string) ($columns['images'] ?? ''));
            if ($description === '' && ($images === '' || $images === '[]')) {
                continue;
            }

            $index[$uniqueId] = [
                'description' => $description,
                'images' => $images,
            ];
        }

        return $index;
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    private function collectRows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'xlsx') {
            return $this->rowsFromXlsx($path);
        }
        if ($this->fileStartsWithAds($path)) {
            return $this->rowsFromXml($path);
        }

        return $this->rowsFromCsv($path);
    }

    /**
     * Сводка разбора без записи в БД.
     *
     * @param array<string, array<string, string|int>> $rows
     */
    private function printDryRun(array $rows): void
    {
        $counts = [];
        foreach ($rows as $columns) {
            foreach ($columns as $column => $value) {
                if ($value !== '' && $value !== null) {
                    $counts[$column] = ($counts[$column] ?? 0) + 1;
                }
            }
        }
        ksort($counts);

        echo "  Заполненность колонок в файле:\n";
        foreach ($counts as $column => $count) {
            printf("    %-16s %d\n", $column, $count);
        }

        $firstId = array_key_first($rows);
        if ($firstId === null) {
            return;
        }

        echo "  Пример (avito_id={$firstId}):\n";
        foreach ($rows[$firstId] as $column => $value) {
            $text = (string) $value;
            if (mb_strlen($text) > 90) {
                $text = mb_substr($text, 0, 90) . '…';
            }
            printf("    %-16s %s\n", $column, $text);
        }
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    private function rowsFromXlsx(string $path): array
    {
        $reader = new XlsxReader($path);
        $collected = [];

        foreach ($reader->sheets() as $name => $sheet) {
            if ($sheet['hidden']) {
                continue;
            }

            $headers = null;
            $sheetRows = 0;

            foreach ($reader->rows($sheet['target']) as $row) {
                if ($row === []) {
                    continue;
                }

                if ($headers === null) {
                    $candidate = $this->headerMap($row);
                    if ($candidate !== null) {
                        $headers = $candidate;
                    }
                    continue;
                }

                $parsed = $this->mapRow($row, $headers);
                if ($parsed === null) {
                    continue;
                }

                [$avitoId, $columns] = $parsed;
                $collected[$avitoId] = $columns;
                $sheetRows++;
            }

            if ($sheetRows > 0) {
                echo "  Лист «{$name}»: {$sheetRows} строк\n";
                flush();
            }
        }

        return $collected;
    }

    /**
     * Файл отчёта Авито иногда сохранён как .xml, но внутри это CSV.
     * Настоящий фид начинается с корня Ads.
     */
    private function fileStartsWithAds(string $path): bool
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 400);
        fclose($handle);
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head;

        return preg_match('/<\?xml[^>]*\?>\s*<Ads\b|<\s*Ads\b/u', $head) === 1;
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    private function rowsFromXml(string $path): array
    {
        $reader = new \XMLReader();
        if (!$reader->open($path, 'UTF-8')) {
            return [];
        }

        $collected = [];
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'Ad') {
                continue;
            }
            $outer = $reader->readOuterXml();
            if ($outer === '') {
                continue;
            }
            $ad = simplexml_load_string($outer);
            if ($ad === false) {
                continue;
            }

            $avitoId = trim((string) $ad->AvitoId);
            $uniqueId = trim((string) $ad->Id);
            if ($avitoId !== '' && !ctype_digit($avitoId)) {
                $avitoId = '';
            }
            if ($avitoId === '' && $uniqueId === '') {
                continue;
            }
            if ($this->isTemplateHintRow($uniqueId, $avitoId)) {
                continue;
            }

            $columns = [];
            $this->putFeedColumn($columns, 'unique_id', $uniqueId);
            $this->putFeedColumn($columns, 'phone', (string) $ad->ContactPhone);
            $this->putFeedColumn($columns, 'contact_method', (string) $ad->ContactMethod);
            $this->putFeedColumn($columns, 'location', (string) $ad->Address);
            $this->putFeedColumn($columns, 'description', trim((string) $ad->Description));
            $this->putFeedColumn($columns, 'title', (string) $ad->Title);
            $price = preg_replace('/[^0-9]/', '', (string) $ad->Price);
            if ($price !== '') {
                $columns['price'] = (int) $price;
            }
            $this->putFeedColumn($columns, 'brand', (string) $ad->Brand);
            $this->putFeedColumn($columns, 'oem_number', (string) $ad->OEM);

            $images = [];
            foreach ($ad->Images->Image ?? [] as $image) {
                $url = trim((string) ($image['url'] ?? ''));
                if ($url !== '') {
                    $images[] = $url;
                }
            }
            if ($images !== []) {
                $columns['images'] = json_encode($images, JSON_UNESCAPED_UNICODE);
            }

            $categoryParams = [];
            foreach ([
                'GoodsType' => 'Вид товара',
                'AdType' => 'Вид объявления',
                'ProductType' => 'Тип товара',
                'SparePartType' => 'Вид запчасти',
                'EngineSparePartType' => 'Тип детали двигателя',
                'Condition' => 'Состояние',
                'Originality' => 'Происхождение',
                'Availability' => 'Доступность',
            ] as $element => $name) {
                $value = trim((string) $ad->{$element});
                if ($value !== '') {
                    $categoryParams[$name] = $value;
                }
            }
            if ($categoryParams !== []) {
                $columns['category_params'] = json_encode($categoryParams, JSON_UNESCAPED_UNICODE);
            }

            if ($columns === []) {
                continue;
            }

            $collected[$avitoId !== '' ? $avitoId : $uniqueId] = $columns;
        }

        $reader->close();

        return $collected;
    }

    /**
     * @param array<string, string|int> $columns
     */
    private function putFeedColumn(array &$columns, string $column, string $value): void
    {
        $value = trim($value);
        if ($value !== '') {
            $columns[$column] = $value;
        }
    }

    /**
     * @return array<string, array<string, string|int>>
     */
    private function rowsFromCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $delimiter = $this->detectCsvDelimiter($path);
        $headers = null;
        $collected = [];

        while (($line = fgets($handle)) !== false) {
            $row = $this->parseAutoloadCsvLine($line, $delimiter);
            if ($row === []) {
                continue;
            }

            if ($headers === null) {
                $candidate = $this->headerMap($row);
                if ($candidate !== null) {
                    $headers = $candidate;
                }
                continue;
            }

            $parsed = $this->mapRow($row, $headers);
            if ($parsed === null) {
                continue;
            }

            [$avitoId, $columns] = $parsed;
            $collected[$avitoId] = $columns;
        }

        fclose($handle);

        return $collected;
    }

    /**
     * Строка автозагрузки часто целиком в кавычках и заканчивается на `;;`.
     *
     * @return list<string>
     */
    private function parseAutoloadCsvLine(string $line, string $delimiter): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        $line = preg_replace('/;;\s*$/', '', $line) ?? $line;
        if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
            $line = str_replace('""', '"', substr($line, 1, -1));
            $delimiter = ',';
        }

        $row = str_getcsv($line, $delimiter);
        if ($row === false) {
            return [];
        }

        return array_map(static fn($value): string => trim((string) $value), $row);
    }

    private function detectCsvDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ',';
        }

        $sample = (string) fread($handle, 8192);
        fclose($handle);

        return substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    }

    /**
     * Карта «заголовок => индекс колонки», если строка похожа на шапку.
     *
     * @param list<string> $row
     * @return array<string, int>|null
     */
    private function headerMap(array $row): ?array
    {
        $map = [];
        foreach ($row as $index => $value) {
            $value = trim($value);
            if ($value !== '') {
                $map[$value] = $index;
            }
        }

        $hasRussian = isset($map[self::KEY_UNIQUE_ID], $map[self::KEY_AVITO_ID]);
        $hasEnglishUnique = isset($map['Id']);

        if (!$hasRussian && !$hasEnglishUnique) {
            return null;
        }

        if (!isset($map[self::KEY_UNIQUE_ID]) && isset($map['Id'])) {
            $map[self::KEY_UNIQUE_ID] = $map['Id'];
        }
        if (!isset($map[self::KEY_AVITO_ID]) && isset($map['AvitoId'])) {
            $map[self::KEY_AVITO_ID] = $map['AvitoId'];
        }

        return $map;
    }

    /**
     * @param list<string> $row
     * @param array<string, int> $headers
     * @return array{0: string, 1: array<string, string|int>}|null
     */
    private function mapRow(array $row, array $headers): ?array
    {
        $avitoId = '';
        if (isset($headers[self::KEY_AVITO_ID])) {
            $avitoId = trim($row[$headers[self::KEY_AVITO_ID]] ?? '');
            if ($avitoId !== '' && !ctype_digit($avitoId)) {
                $avitoId = '';
            }
        }

        $uniqueId = trim($row[$headers[self::KEY_UNIQUE_ID]] ?? '');
        if ($avitoId === '' && $uniqueId === '') {
            return null;
        }

        if ($this->isTemplateHintRow($uniqueId, $avitoId)) {
            return null;
        }

        $columns = [];
        foreach (self::COLUMN_MAP as $header => $column) {
            if (!isset($headers[$header])) {
                continue;
            }
            $value = trim($row[$headers[$header]] ?? '');
            if ($value === '') {
                continue;
            }

            $columns[$column] = match ($column) {
                'description' => trim($value),
                'images' => $this->encodeImages($value),
                'price' => (int) preg_replace('/[^0-9]/', '', $value),
                default => $value,
            };
        }

        $categoryParams = [];
        foreach (self::CATEGORY_KEYS as $key) {
            if (!isset($headers[$key])) {
                continue;
            }
            $value = trim($row[$headers[$key]] ?? '');
            if ($value !== '') {
                $categoryParams[$key] = $value;
            }
        }
        if ($categoryParams !== []) {
            $columns['category_params'] = json_encode($categoryParams, JSON_UNESCAPED_UNICODE);
        }

        if ($columns === []) {
            return null;
        }

        $lookupKey = $avitoId !== '' ? $avitoId : $uniqueId;

        return [$lookupKey, $columns];
    }

    /** Строки «Обязательный / Необязательный» под шапкой англ. шаблона Автозагрузки. */
    private function isTemplateHintRow(string $uniqueId, string $avitoId): bool
    {
        $hints = [
            'Обязательный',
            'Необязательный',
            'Подробнее о параметре',
            'Id',
            'Address',
        ];

        return in_array($uniqueId, $hints, true)
            || in_array($avitoId, $hints, true)
            || str_starts_with($uniqueId, 'Подробнее')
            || str_starts_with($uniqueId, 'Может быть обязательным');
    }

    private function encodeImages(string $value): string
    {
        $urls = array_values(array_filter(array_map('trim', explode('|', $value)), static fn($u) => $u !== ''));
        if ($urls === []) {
            return '';
        }

        return (string) json_encode($urls, JSON_UNESCAPED_UNICODE);
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' МБ';
        }

        return round($bytes / 1024) . ' КБ';
    }

    /**
     * Файл последней выгрузки кабинета. Если его уже скачали — берём с диска.
     */
    private function resolveCabinetFeedFile(): ?string
    {
        $local = $this->newestLocalFeed();
        if ($local !== null) {
            return $local;
        }

        try {
            $upload = $this->apiClient->getLatestUploadWithFeed();
        } catch (\Throwable $e) {
            echo "  [ERROR] " . $this->explainError($e) . "\n";
            return null;
        }

        if ($upload === null) {
            echo "  В отчётах Автозагрузки нет ссылки на файл\n";
            return null;
        }

        try {
            $downloaded = $this->apiClient->downloadFeedContent($upload['feed_urls'][0]['url'], $this->downloadDir);
        } catch (\Throwable $e) {
            echo "  [ERROR] " . $this->explainError($e) . "\n";
            return null;
        }

        echo "  Файл из кабинета: {$downloaded['filename']} (" . $this->formatSize($downloaded['bytes']) . ")\n";

        return $downloaded['path'];
    }

    private function newestLocalFeed(): ?string
    {
        if (!is_dir($this->downloadDir)) {
            return null;
        }

        $files = array_merge(
            glob($this->downloadDir . '/*.xlsx') ?: [],
            glob($this->downloadDir . '/*.csv') ?: [],
            glob($this->downloadDir . '/*.xml') ?: []
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
     * @return array{
     *     by_avito: array<string, array{brand: string, oem: string}>,
     *     by_unique: array<string, array{brand: string, oem: string}>
     * }
     */
    private function brandOemIndex(string $path): array
    {
        $byAvito = [];
        $byUnique = [];
        foreach ($this->collectRows($path) as $lookupKey => $columns) {
            $entry = [
                'brand' => trim((string) ($columns['brand'] ?? '')),
                'oem' => trim((string) ($columns['oem_number'] ?? '')),
            ];
            if ($entry['brand'] === '' && $entry['oem'] === '') {
                continue;
            }

            $uniqueId = trim((string) ($columns['unique_id'] ?? ''));
            if ($uniqueId === '' && !ctype_digit((string) $lookupKey)) {
                $uniqueId = trim((string) $lookupKey);
            }
            if (ctype_digit((string) $lookupKey)) {
                $byAvito[(string) $lookupKey] = $this->mergeBrandOem($byAvito[(string) $lookupKey] ?? null, $entry);
            }
            if ($uniqueId !== '') {
                $byUnique[$uniqueId] = $this->mergeBrandOem($byUnique[$uniqueId] ?? null, $entry);
            }
        }

        return ['by_avito' => $byAvito, 'by_unique' => $byUnique];
    }

    /**
     * Своё объявление, затем базовый Id без -vN, затем номер предыдущего поколения.
     *
     * @param array{
     *     by_avito: array<string, array{brand: string, oem: string}>,
     *     by_unique: array<string, array{brand: string, oem: string}>
     * } $index
     * @param array<string, mixed> $ad
     * @return array{brand: string, oem: string}
     */
    private function lookupBrandOem(array $index, array $ad): array
    {
        $found = ['brand' => '', 'oem' => ''];
        $avitoId = trim((string) ($ad['avito_id'] ?? ''));
        $oldAvitoId = trim((string) ($ad['old_avito_id'] ?? ''));
        $uniqueId = trim((string) ($ad['unique_id'] ?? ''));
        $baseId = preg_replace('/-v\d+$/', '', $uniqueId) ?? $uniqueId;

        $candidates = [];
        if ($avitoId !== '' && isset($index['by_avito'][$avitoId])) {
            $candidates[] = $index['by_avito'][$avitoId];
        }
        if ($uniqueId !== '' && isset($index['by_unique'][$uniqueId])) {
            $candidates[] = $index['by_unique'][$uniqueId];
        }
        if ($baseId !== '' && $baseId !== $uniqueId && isset($index['by_unique'][$baseId])) {
            $candidates[] = $index['by_unique'][$baseId];
        }
        if ($oldAvitoId !== '' && isset($index['by_avito'][$oldAvitoId])) {
            $candidates[] = $index['by_avito'][$oldAvitoId];
        }

        foreach ($candidates as $candidate) {
            $found = $this->mergeBrandOem($found, $candidate);
        }

        return $found;
    }

    /**
     * @param array{brand: string, oem: string}|null $current
     * @param array{brand: string, oem: string} $incoming
     * @return array{brand: string, oem: string}
     */
    private function mergeBrandOem(?array $current, array $incoming): array
    {
        $brand = trim((string) ($current['brand'] ?? ''));
        $oem = trim((string) ($current['oem'] ?? ''));
        if ($brand === '') {
            $brand = $incoming['brand'];
        }
        if ($oem === '') {
            $oem = $incoming['oem'];
        }

        return ['brand' => $brand, 'oem' => $oem];
    }

    private function isBlank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function explainError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, '403')) {
            return 'Нет доступа к Автозагрузке (HTTP 403). Включите право autoload у приложения.';
        }

        return $message;
    }
}
