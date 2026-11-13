<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use Ritmo\Domain\Config\ValidationError;

/**
 * The ruler is exact: a sales ladder rule that cites a series the market does not build, or a
 * percentile it does not keep, would price nothing without saying so. This check refuses such a
 * rule when the rules are saved (codes ladder.series_unknown and ladder.percentile_not_measured).
 *
 * The anchor series "market" keeps 25, 50, 75, 90 and 100.
 */
final class LadderSeriesCheck
{
    public const SERIES_UNKNOWN = 'ladder.series_unknown';

    public const PERCENTILE_NOT_MEASURED = 'ladder.percentile_not_measured';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $ladder the "ladder" section of the rules
     *
     * @return list<ValidationError>
     */
    public static function errors(array $ladder, MarketConfig $market): array
    {
        $known = [SeriesDefinition::ANCHOR => MarketFields::ANCHOR_PERCENTILES];
        foreach ($market->series as $series) {
            $known[$series->name] = $market->percentiles;
        }
        $errors = [];
        foreach ((array) ($ladder['strategies'] ?? []) as $s => $strategy) {
            $strategy = is_array($strategy) ? $strategy : [];
            foreach ((array) ($strategy['rules'] ?? []) as $r => $rule) {
                foreach (['opening', 'ceiling'] as $point) {
                    $errors = [...$errors, ...self::point((array) (($rule[$point] ?? null) ?: []), "ladder.strategies.$s.rules.$r.$point", $known)];
                }
            }
            $errors = [...$errors, ...self::point((array) (($strategy['last_minute_floor'] ?? null) ?: []), "ladder.strategies.$s.last_minute_floor", $known)];
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>     $point a series and a percentile
     * @param array<string, list<int>> $known
     *
     * @return list<ValidationError>
     */
    private static function point(array $point, string $path, array $known): array
    {
        $series = $point['series'] ?? null;
        if (!is_string($series) || $series === '') {
            return [];
        }
        if (!isset($known[$series])) {
            return [new ValidationError($path . '.series', self::SERIES_UNKNOWN, sprintf('cites the series "%s", which the market does not build', $series))];
        }
        $percentile = $point['percentile'] ?? null;
        if (!is_int($percentile) && !is_float($percentile)) {
            return [];
        }
        foreach ($known[$series] as $kept) {
            if ((float) $kept === (float) $percentile) {
                return [];
            }
        }

        return [new ValidationError($path . '.percentile', self::PERCENTILE_NOT_MEASURED, sprintf('cites the percentile %s of "%s", which the market does not keep', (string) $percentile, $series))];
    }
}
