<?php

namespace App\Services;

use App\DTO\AnalysisRuleDTO;
use App\Repositories\ItemRepository;

/**
 * Сервис анализа объявлений для определения кандидатов на переопубликовку
 *
 * Использует настраиваемые пороги (analysis_thresholds) вместо хардкода.
 * Объявление считается кандидатом, если попадает хотя бы в одно правило.
 */
class AnalysisService
{
    private ItemRepository $repository;

    /** @var list<AnalysisRuleDTO> */
    private array $rules;

    /**
     * @param array{analysis_thresholds?: list<array{name?: string, days?: int, max_views?: int, max_contacts?: int, max_favorites?: int}>} $config
     */
    public function __construct(
        ItemRepository $repository,
        array $config
    ) {
        $this->repository = $repository;
        $this->rules = $this->parseRules($config);
    }

    /**
     * Проверить объявление на соответствие правилам.
     *
     * @param array{
     *     id?: int,
     *     avito_id?: string,
     *     logical_key?: string,
     *     published_at?: string,
     *     master_data?: string
     * } $ad
     *
     * @return array{
     *     is_candidate: bool,
     *     matched_rules: string[],
     *     total_views: int,
     *     total_contacts: int,
     *     total_favorites: int,
     *     stats_days: int
     * }
     */
    public function analyze(array $ad): array
    {
        $physicalAdId = (int) ($ad['id'] ?? 0);
        if ($physicalAdId === 0) {
            return [
                'is_candidate' => false,
                'matched_rules' => [],
                'total_views' => 0,
                'total_contacts' => 0,
                'total_favorites' => 0,
                'stats_days' => 0,
            ];
        }

        // Берём максимальное окно среди всех правил
        $maxDays = 0;
        foreach ($this->rules as $rule) {
            if ($rule->days > $maxDays) {
                $maxDays = $rule->days;
            }
        }

        // Получаем статистику за максимальное окно
        $stats = $this->repository->getStatsForLastDays($physicalAdId, $maxDays);

        // Суммируем по всем правилам — берём статистику за нужное окно для каждого правила
        $matchedRules = [];
        $totalViews = 0;
        $totalContacts = 0;
        $totalFavorites = 0;

        foreach ($this->rules as $rule) {
            $ruleStats = $this->repository->getStatsForLastDays($physicalAdId, $rule->days);

            $views = 0;
            $contacts = 0;
            $favorites = 0;

            foreach ($ruleStats as $s) {
                $views += (int) ($s['views'] ?? $s['uniq_views'] ?? 0);
                $contacts += (int) ($s['contacts'] ?? $s['uniq_contacts'] ?? 0);
                $favorites += (int) ($s['favorites'] ?? $s['uniq_favorites'] ?? 0);
            }

            // Проверяем правило: все три метрики <= порогов
            if ($views <= $rule->maxViews
                && $contacts <= $rule->maxContacts
                && $favorites <= $rule->maxFavorites
            ) {
                $matchedRules[] = $rule->name;
            }
        }

        // Для общих метрик используем максимальное окно
        foreach ($stats as $s) {
            $totalViews += (int) ($s['views'] ?? $s['uniq_views'] ?? 0);
            $totalContacts += (int) ($s['contacts'] ?? $s['uniq_contacts'] ?? 0);
            $totalFavorites += (int) ($s['favorites'] ?? $s['uniq_favorites'] ?? 0);
        }

        return [
            'is_candidate' => count($matchedRules) > 0,
            'matched_rules' => $matchedRules,
            'total_views' => $totalViews,
            'total_contacts' => $totalContacts,
            'total_favorites' => $totalFavorites,
            'stats_days' => count($stats),
        ];
    }

    /**
     * Найти все объявления-кандидаты.
     *
     * @return list<array{ad: array, analysis: array}>
     */
    public function findAllCandidates(): array
    {
        $activeAds = $this->repository->getActive();
        $candidates = [];

        foreach ($activeAds as $ad) {
            $analysis = $this->analyze($ad);

            if ($analysis['is_candidate']) {
                $candidates[] = [
                    'ad' => $ad,
                    'analysis' => $analysis,
                ];
            }
        }

        // Сортируем: меньше контактов → выше приоритет
        usort($candidates, function (array $a, array $b): int {
            return ($a['analysis']['total_contacts'] ?? 0)
                <=> ($b['analysis']['total_contacts'] ?? 0);
        });

        return $candidates;
    }

    /**
     * @param array{analysis_thresholds?: list<array<string, mixed>>} $config
     * @return list<AnalysisRuleDTO>
     */
    private function parseRules(array $config): array
    {
        $rawRules = $config['analysis_thresholds'] ?? [];

        if ($rawRules === []) {
            // Дефолтное правило: 0 контактов за 5 дней
            return [AnalysisRuleDTO::fromArray([
                'name' => 'default',
                'days' => 5,
                'max_views' => 0,
                'max_contacts' => 0,
                'max_favorites' => 0,
            ])];
        }

        $rules = [];
        foreach ($rawRules as $raw) {
            $rules[] = AnalysisRuleDTO::fromArray($raw);
        }

        return $rules;
    }
}
