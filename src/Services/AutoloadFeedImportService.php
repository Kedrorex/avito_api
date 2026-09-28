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
     * Импортировать файл с диска: XLSX или CSV.
     *
     * @param bool $dryRun Только разобрать файл и показать сводку, без записи в БД
     * @return array{status: string, upload_id: string, file: string, rows: int, updated: int, missing: int}
     */
    public function importFile(string $path, bool $dryRun = false): array
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

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $rows = $extension === 'xlsx'
            ? $this->rowsFromXlsx($path)
            : $this->rowsFromCsv($path);

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

        $applied = $this->repository->updateFeedColumnsByAvitoId($rows, self::ALLOWED_COLUMNS);
        $result['updated'] = $applied['updated'];
        $result['missing'] = $applied['missing'];

        echo "  Обновлено объявлений: {$applied['updated']}\n";
        echo "  Нет в БД: {$applied['missing']}\n";

        return $result;
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

        // Пропускаем BOM, если он есть
        fseek($handle, 0);
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            fseek($handle, 0);
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = array_map(static fn($v) => trim((string) $v), $row);

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
                'description' => trim(strip_tags($value)),
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

    private function explainError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, '403')) {
            return 'Нет доступа к Автозагрузке (HTTP 403). Включите право autoload у приложения.';
        }

        return $message;
    }
}
