<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use DateTimeImmutable;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Config\PriceBand;
use Ritmo\Domain\Relations\Port\NightCalendar;
use Ritmo\Domain\Relations\Port\PriceBandSource;
use Ritmo\Domain\Relations\Port\SentBand;
use Ritmo\Domain\Relations\Port\StrategyView;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The market bands of the configuration ("price_bands.items"), converted to the sent plan with the
 * property's price factors and rounded half to even to whole currency.
 */
final class ConfiguredPriceBands implements PriceBandSource
{
    /** @var array<string, RoomType> */
    private array $rooms = [];

    /**
     * @param list<PriceBand> $bands
     * @param list<RoomType>  $rooms
     */
    public function __construct(
        private readonly array $bands,
        array $rooms,
        private readonly PriceFactor $factors,
        private readonly NightCalendar $calendar,
        private readonly StrategyView $strategy,
    ) {
        foreach ($rooms as $room) {
            $this->rooms[$room->code] = $room;
        }
    }

    public function sentBand(string $room, DateTimeImmutable $night): ?SentBand
    {
        $band = $this->bandOf($room);
        $type = $this->calendar->nightType($night);
        if ($band === null || !isset($this->rooms[$room]) || !in_array($type, $band->nightTypes, true)) {
            return null;
        }
        if ($band->skipOnStrategyNights && $this->strategy->governs($room, $night)) {
            return null;
        }
        $toSent = fn (float $amount): float => (float) Num::roundInt($this->factors->toSent($amount, $band->pricePlan, $this->rooms[$room], $type));

        return new SentBand($toSent($band->minAmount), $toSent($band->maxAmount));
    }

    public function lastNight(string $room, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $band = $this->bandOf($room);

        return $band === null ? null : Days::add($today, $band->maxDaysOut);
    }

    private function bandOf(string $room): ?PriceBand
    {
        foreach ($this->bands as $band) {
            if ($band->enabled && $band->room === $room) {
                return $band;
            }
        }

        return null;
    }
}
