<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market\Series;

use DateTimeImmutable;
use Ritmo\Domain\Market\Series\ComparableFactor;
use Ritmo\Domain\Market\Series\CompetitorProfile;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Market\Series\SampleWindow;
use Ritmo\Domain\Market\Series\StaySample;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * Turns the inputs of the market goldens (tests/fixtures/golden/m4/market) into the plugin's
 * values: the reference catalogue becomes competitor profiles (ids from 1, "peer" tag for a
 * rating below 9.0, "medido" = measured, "incerto" = estimated), every sweep hotel row becomes a
 * cheapest stay sample with its total, captured 2026-11-10, seen from the frozen today 2026-11-13.
 */
final class MarketGoldenInputs
{
    public const FOLDER = 'golden/m4/market';

    public const OWN_ID = 1000;

    public const UNKNOWN_ID = 2000;

    private function __construct()
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function cases(string $function): array
    {
        return Fixtures::json(self::FOLDER . '/' . $function . '.json')['cases'];
    }

    /**
     * @param array{r_hidro_padrao: float, hoteis: list<array<string, mixed>>} $catalog
     *
     * @return array<int, CompetitorProfile> by id, the own listing included
     */
    public static function catalogue(array $catalog): array
    {
        $profiles = [];
        foreach ($catalog['hoteis'] as $index => $hotel) {
            $id = $index + 1;
            $profiles[$id] = new CompetitorProfile(
                $id,
                (string) $hotel['slug'],
                (float) $hotel['nota'] < 9.0 ? ['peer'] : [],
                isset($hotel['r_hidro']) ? (float) $hotel['r_hidro'] : null,
                self::source($hotel),
            );
        }
        $profiles[self::OWN_ID] = new CompetitorProfile(self::OWN_ID, 'own-hotel', [], null, '', true, true);

        return $profiles;
    }

    /**
     * @param array<string, mixed> $hotel
     */
    public static function source(array $hotel): string
    {
        $source = (string) ($hotel['r_hidro_fonte'] ?? '');

        return match (true) {
            str_contains($source, 'incerto') => ComparableFactor::ESTIMATED,
            str_contains($source, 'medido') => ComparableFactor::MEASURED,
            default => '',
        };
    }

    /**
     * @param array<int, CompetitorProfile> $catalogue
     *
     * @return array<string, int> code => id
     */
    public static function ids(array $catalogue): array
    {
        $ids = [];
        foreach ($catalogue as $id => $profile) {
            $ids[$profile->code] = $id;
        }

        return $ids;
    }

    /**
     * The samples of one sweep stay: one cheapest row per hotel with a price.
     *
     * @param array<string, mixed> $stay
     * @param array<string, int>   $ids  code => id
     *
     * @return list<StaySample>
     */
    public static function samples(array $stay, array $ids, int $sequence = 0): array
    {
        $checkIn = Days::date((string) $stay['checkin']);
        $nights = Days::diff($checkIn, Days::date((string) $stay['checkout']));
        $samples = [];
        foreach ($stay['hoteis'] as $line => $hotel) {
            if (empty($hotel['preco_total'])) {
                continue;
            }
            $total = (float) $hotel['preco_total'];
            $samples[] = new StaySample($ids[$hotel['slug']] ?? self::UNKNOWN_ID, $checkIn, $nights, RoomBasis::Cheapest, $total / $nights, $total, self::captured(), true, $sequence + $line);
        }

        return $samples;
    }

    /**
     * The samples of both sweep files, dropping the second file's repeat of a stay the first
     * already has (MK-X1 applied in the test only: the reference keeps the first file's stay).
     *
     * @param array<string, int> $ids
     *
     * @return list<StaySample>
     */
    public static function sweepSamples(array $ids): array
    {
        $samples = [];
        $seen = [];
        foreach ([1, 2] as $file) {
            foreach (Fixtures::json(self::FOLDER . '/frozen/sweep_' . $file . '.json')['estadias'] as $index => $stay) {
                $key = $stay['checkin'] . '/' . $stay['checkout'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $samples = [...$samples, ...self::samples($stay, $ids, ($file * 1000 + $index) * 100)];
            }
        }

        return $samples;
    }

    /**
     * The construir configuration: percentiles 25/50/65/75/90 and the two series of the
     * reference (cheapest = "lista", comparable = "hidro").
     */
    public static function config(int $minCompetitors): MarketConfig
    {
        return MarketConfig::fromArray([
            'series' => [['name' => 'cheapest', 'basis' => 'cheapest', 'tags' => []], ['name' => 'comparable', 'basis' => 'comparable', 'tags' => []]],
            'percentiles' => [25, 50, 65, 75, 90],
            'min_competitors_per_stay' => $minCompetitors,
            'night_min_competitors' => 40,
            'neighbour_days' => 3,
            'comparable' => ['default_factor' => 1.395, 'estimated_factor_cap' => 2.0],
            'bands' => [],
        ]);
    }

    public static function window(): SampleWindow
    {
        return new SampleWindow(Days::date('2026-11-13'), new DateTimeImmutable('2026-10-14T13:00:00Z'));
    }

    public static function captured(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10T12:00:00Z');
    }
}
