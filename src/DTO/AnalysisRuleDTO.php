<?php

namespace App\DTO;

/**
 * Правило анализа для определения кандидатов на переопубликовку
 */
class AnalysisRuleDTO
{
    public function __construct(
        public readonly string $name,
        public readonly int    $days,
        public readonly int    $maxViews,
        public readonly int    $maxContacts,
        public readonly int    $maxFavorites,
    ) {
    }

    /**
     * Создать из массива конфигурации
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? 'default'),
            days: (int) ($data['days'] ?? 5),
            maxViews: (int) ($data['max_views'] ?? 0),
            maxContacts: (int) ($data['max_contacts'] ?? 0),
            maxFavorites: (int) ($data['max_favorites'] ?? 0),
        );
    }
}
