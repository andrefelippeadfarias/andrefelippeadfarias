<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\AnchorRuler;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\RulerNight;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\LadderReferenceRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;

/**
 * The market ruler the sales ladder reads in a run (spec 06, 1.6): per night its type, the stored
 * percentiles of every configured series, the anchor series "market" (25, 50, 75, 90, 100), the
 * configured public factor of the property for the night's type (MK-D3) and the reference sent
 * prices of the strategies that cap their opening by them. A night with no series and no anchor
 * has no ruler night (a gap: the ladder does not price it).
 */
final class MarketRulerAssembler
{
    public function __construct(
        private readonly MarketSeriesRepository $series,
        private readonly MarketAnchorRepository $anchors,
        private readonly LadderReferenceRepository $references,
    ) {
    }

    public function of(Property $property, RuleSet $rules, DateTimeImmutable $from, DateTimeImmutable $to): MarketRuler
    {
        $series = $this->series->range($property->id, $from, $to);
        $anchors = $this->anchors->range($property->id, $from, $to);
        $references = $this->referencePrices($property, $rules, $from, $to);
        $nights = [];
        for ($night = Days::normalize($from); $night <= $to; $night = Days::add($night, 1)) {
            $key = Days::key($night);
            $points = [];
            foreach ($series[$key] ?? [] as $name => $value) {
                $points[$name] = array_map('floatval', $value->values);
            }
            $anchor = $anchors[$key] ?? null;
            if ($anchor !== null && $anchor->n > 0) {
                $points[AnchorRuler::SERIES] = ['25' => $anchor->p25, '50' => $anchor->p50, '75' => $anchor->p75, '90' => $anchor->p90, '100' => $anchor->max];
            }
            if ($points === []) {
                continue;
            }
            $type = $rules->nightType($night);
            $nights[] = new RulerNight($night, $type->value, $points, [$property->id => self::publicFactor($rules, $property, $type)], $references[$key] ?? []);
        }

        return new MarketRuler($nights);
    }

    private static function publicFactor(RuleSet $rules, Property $property, NightType $type): float
    {
        return (float) $rules->priceFactor($property->id, PricePlan::PublicRate, $type);
    }

    /**
     * @return array<string, array<string, float>> night => room => reference sent price (the first strategy citing a room wins)
     */
    private function referencePrices(Property $property, RuleSet $rules, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $prices = [];
        foreach ((array) ($rules->toArray()['ladder']['strategies'] ?? []) as $strategy) {
            $id = is_array($strategy) ? (string) ($strategy['id'] ?? '') : '';
            if ($id === '' || !self::capsByReference($strategy)) {
                continue;
            }
            foreach ($this->references->latest($property->id, $id, $from, $to) as $night => $rooms) {
                $prices[$night] = ($prices[$night] ?? []) + $rooms;
            }
        }

        return $prices;
    }

    /**
     * @param array<string, mixed> $strategy
     */
    private static function capsByReference(array $strategy): bool
    {
        foreach ((array) ($strategy['rules'] ?? []) as $rule) {
            if (is_array($rule) && (($rule['opening']['cap'] ?? 'none') === 'reference_snapshot')) {
                return true;
            }
        }

        return false;
    }
}
