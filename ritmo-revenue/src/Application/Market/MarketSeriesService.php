<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\AnchorBuilder;
use Ritmo\Domain\Market\PriceSample;
use Ritmo\Domain\Market\Series\CompetitorProfile;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\NightSeries;
use Ritmo\Domain\Market\Series\NightValue;
use Ritmo\Domain\Market\Series\NewestSamples;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Market\Series\SampleWindow;
use Ritmo\Domain\Market\Series\SeriesBuilder;
use Ritmo\Domain\Market\Series\SeriesDefinition;
use Ritmo\Domain\Market\Series\StaySample;
use Ritmo\Domain\Market\Series\StaySeries;
use Ritmo\Domain\Market\Series\StayValues;
use Ritmo\Domain\Market\Series\TypeTable;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;

/**
 * Rebuilds the market series of a property from its counting samples (spec 06, 1.4 and 1.5) and
 * feeds the market anchor (1.6, "anchors from the CSV"). Run after an import, a competitor
 * change and a change of the market section; a full run calls rebuildIfStale() first. It never
 * writes a price.
 */
final class MarketSeriesService
{
    /** Furthest night a series is stored for. */
    public const MAX_NIGHTS = 366;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PropertyRepository $properties,
        private readonly RuleSetRepository $ruleSets,
        private readonly CompetitorRepository $competitors,
        private readonly StaySampleRepository $samples,
        private readonly MarketSeriesRepository $series,
        private readonly MarketAnchorRepository $anchors,
    ) {
    }

    /**
     * @return array{nights: int, values: int, anchors: int}
     *
     * @throws MarketRefused when the property does not exist
     */
    public function rebuild(string $propertyId): array
    {
        $property = $this->properties->find($propertyId) ?? throw new MarketRefused(MarketRefused::PROPERTY_UNKNOWN);
        $rules = $this->ruleSets->current() ?? RuleSet::defaults();
        $config = MarketSettings::of($rules);
        $today = $this->clock->today($property->timezone);
        $window = new SampleWindow($today, $this->clock->now()->modify(sprintf('-%d days', $config->maxSampleAgeDays)));
        $samples = $this->samples->samples($propertyId, $today, $window->capturedSince);
        $catalogue = $this->competitors->profiles($propertyId);
        $typeOf = static function (DateTimeImmutable $checkIn, int $nights) use ($rules): ?string {
            $type = $rules->nightType($checkIn)->value;
            for ($i = 1; $i < $nights; ++$i) {
                if ($rules->nightType(Days::add($checkIn, $i))->value !== $type) {
                    return null;
                }
            }

            return $type;
        };
        $built = (new SeriesBuilder($config, $catalogue, $typeOf))->build($samples, $window);
        $values = $this->nightValues($built, $config, $rules, $today);

        return $this->db->transaction(function () use ($property, $values, $samples, $catalogue, $config, $rules, $today, $window): array {
            $stored = $this->series->replace($property->id, $values, $this->inputsHash($property, $config, $today), $this->clock->now());
            $anchors = $this->feedAnchors($property, $samples, $catalogue, $config, $rules, $today, $window);

            return ['nights' => count($values), 'values' => $stored, 'anchors' => $anchors];
        });
    }

    /**
     * Rebuilds when the inputs changed since the last build (new samples, a competitor change,
     * the market section, another day).
     *
     * @return bool true when it rebuilt
     */
    public function rebuildIfStale(string $propertyId): bool
    {
        $property = $this->properties->find($propertyId);
        if ($property === null) {
            return false;
        }
        $config = MarketSettings::of($this->ruleSets->current());
        if ($this->series->inputsHash($propertyId) === $this->inputsHash($property, $config, $this->clock->today($property->timezone))) {
            return false;
        }
        $this->rebuild($propertyId);

        return true;
    }

    /**
     * The value of every series on every night of the next year (a kind of night without any
     * measured stay has no value: a gap of the ruler).
     *
     * @param array<string, list<StaySeries>> $built
     *
     * @return array<string, array<string, NightValue>> night => series => value
     */
    private function nightValues(array $built, MarketConfig $config, RuleSet $rules, DateTimeImmutable $today): array
    {
        $nightSeries = new NightSeries($config->nightMinCompetitors, $config->neighbourDays);
        $byType = [];
        $tables = [];
        foreach ($built as $name => $stays) {
            foreach ($stays as $stay) {
                $byType[$name][$stay->type][] = $stay;
            }
            foreach ($byType[$name] ?? [] as $type => $sameType) {
                $tables[$name][$type] = TypeTable::of($sameType, $config->percentiles, $config->smallSampleStays, $config->smallSampleCompetitors);
            }
        }
        $values = [];
        for ($i = 0; $i < self::MAX_NIGHTS; ++$i) {
            $night = Days::add($today, $i);
            $type = $rules->nightType($night)->value;
            foreach ($byType as $name => $types) {
                $value = isset($types[$type]) ? $nightSeries->of($night, $types[$type], $tables[$name][$type]) : null;
                if ($value !== null) {
                    $values[Days::key($night)][$name] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Every counting comparable value becomes one price sample of each night of its stay; the
     * anchors of those nights are built and merged with the anchors in force.
     *
     * @param list<StaySample>                                         $samples
     * @param array<int, CompetitorProfile>  $catalogue
     */
    private function feedAnchors(Property $property, array $samples, array $catalogue, MarketConfig $config, RuleSet $rules, DateTimeImmutable $today, SampleWindow $window): int
    {
        $comparable = new SeriesDefinition('comparable', RoomBasis::Comparable);
        $byStay = [];
        foreach (NewestSamples::of(array_values(array_filter($samples, $window->admits(...)))) as $sample) {
            $byStay[$sample->stayKey()][] = $sample;
        }
        $nightSamples = [];
        foreach ($byStay as $stay) {
            $captured = [];
            foreach ($stay as $sample) {
                $captured[$sample->competitorId] = max($captured[$sample->competitorId] ?? $sample->capturedAt, $sample->capturedAt);
            }
            foreach (StayValues::byCompetitor($stay, $catalogue, $comparable, $config->defaultFactor, $config->estimatedFactorCap) as $competitorId => $value) {
                for ($i = 0; $i < $stay[0]->nights; ++$i) {
                    $nightSamples[] = new PriceSample(Days::add($stay[0]->checkIn, $i), $value, Days::localDate($captured[$competitorId], $property->timezone), 'c:' . $competitorId);
                }
            }
        }
        if ($nightSamples === []) {
            return 0;
        }
        $builder = new AnchorBuilder($rules);
        $built = $builder->build($nightSamples);
        $keys = array_keys($built);
        $merged = $builder->merge($this->anchors->range($property->id, Days::date($keys[0]), Days::date($keys[count($keys) - 1])), $built, $today);
        $replaced = array_filter($built, static fn (MarketAnchor $anchor, string $night): bool => $merged[$night] === $anchor, ARRAY_FILTER_USE_BOTH);
        $this->anchors->saveMany($property->id, array_values($replaced));

        return count($replaced);
    }

    private function inputsHash(Property $property, MarketConfig $config, DateTimeImmutable $today): string
    {
        return hash('sha256', implode('|', [
            $this->samples->lastId($property->id),
            $this->competitors->lastChange($property->id)?->format(DATE_ATOM) ?? '',
            hash('sha256', serialize($config)),
            Days::key($today),
        ]));
    }
}
