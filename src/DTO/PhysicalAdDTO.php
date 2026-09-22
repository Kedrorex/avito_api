<?php

namespace App\DTO;

/**
 * Физическое объявление (поколение)
 */
class PhysicalAdDTO
{
    public function __construct(
        public readonly int         $id,
        public readonly string      $logical_key,
        public readonly ?string     $avito_id = null,
        public readonly ?string     $unique_id = null,
        public readonly string      $status = 'draft',
        public readonly ?string     $published_at = null,
        public readonly ?string     $deactivated_at = null,
        public readonly ?string     $old_avito_id = null,
        public readonly array       $master_data = [],
        /** @var StatsDTO[] */
        public readonly array       $stats = [],
    ) {
    }

    /**
     * Создать DTO из данных БД
     */
    public static function fromDb(array $row, array $stats = []): self
    {
        return new self(
            id: (int) $row['id'],
            logical_key: (string) $row['logical_key'],
            avito_id: $row['avito_id'] ?: null,
            unique_id: $row['unique_id'] ?: null,
            status: (string) $row['status'],
            published_at: $row['published_at'] ?: null,
            deactivated_at: $row['deactivated_at'] ?: null,
            old_avito_id: $row['old_avito_id'] ?: null,
            master_data: $row['master_data'] ? json_decode($row['master_data'], true) : [],
            stats: array_map(fn(array $s) => StatsDTO::fromArray($s), $stats),
        );
    }
}
