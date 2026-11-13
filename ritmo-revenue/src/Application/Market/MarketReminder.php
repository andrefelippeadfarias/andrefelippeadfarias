<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\MarketGaps;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Pricing\Ladder\LadderConfig;
use Ritmo\Domain\Pricing\Ladder\RulerPoint;
use Ritmo\Domain\Support\Days;

/**
 * What the full run tells about the market (market spec 1.7), as data for the alerts the
 * integration raises: whether the newest price that covers the coming nights is older than
 * market.stale_after_days (market_stale; the pace always reads the market anchors), and which of
 * the next nights an enabled sales ladder would price without a value on the ruler (market_gap).
 * Reads stored data only; it raises nothing and writes nothing.
 */
final class MarketReminder
{
    public const GAP_NIGHTS = 30;

    public function __construct(
        private readonly MarketFreshness $freshness,
        private readonly MarketRulerAssembler $ruler,
    ) {
    }

    /**
     * @return array{stale: bool, age_days: int|null, newest: DateTimeImmutable|null, gaps: list<array{night: DateTimeImmutable, missing: list<RulerPoint>}>}
     */
    public function check(Property $property, RuleSet $rules, MarketConfig $config, DateTimeImmutable $today): array
    {
        $fresh = $this->freshness->check($property->id, $today, $property->timezone, $config);

        return [
            'stale' => $fresh['stale'],
            'age_days' => $fresh['age_days'],
            'newest' => $fresh['newest'],
            'gaps' => $this->gaps($property, $rules, $today),
        ];
    }

    /**
     * @return list<array{night: DateTimeImmutable, missing: list<RulerPoint>}>
     */
    private function gaps(Property $property, RuleSet $rules, DateTimeImmutable $today): array
    {
        try {
            $strategies = LadderConfig::fromArray((array) ($rules->toArray()['ladder'] ?? []))->activeStrategies();
        } catch (InvalidConfig) {
            return [];
        }
        if ($strategies === []) {
            return [];
        }
        $last = Days::add($today, self::GAP_NIGHTS - 1);
        $nights = [];
        for ($night = $today; $night <= $last; $night = Days::add($night, 1)) {
            $nights[] = ['night' => $night, 'type' => $rules->nightType($night)->value];
        }

        return MarketGaps::find($strategies, $this->ruler->of($property, $rules, $today, $last), $nights, $today);
    }
}
