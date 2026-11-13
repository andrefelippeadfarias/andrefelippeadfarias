<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Write\Channel\ArrivalReport;
use Ritmo\Domain\Write\Channel\ChannelArrival;
use Ritmo\Domain\Write\Channel\PushTimes;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;

/**
 * Marks the written pace steps that reached the channels: the room's last push (listing
 * directory) after the step AND the PMS price of the night inside the step (PMS inventory read
 * after that directory, so after the push). Run by every run that reads both; reads only.
 *
 * Reference: pace.marcar_envio, pace.envios_da_coleta; task rule 9(j) (divergence C3).
 */
final class DeliveryService
{
    public function __construct(
        private readonly WrittenStepRepository $steps,
        private readonly PricingConnector $pricing,
        private readonly PmsConnector $pms,
        private readonly ChannelArrival $arrival = new ChannelArrival(),
    ) {
    }

    /**
     * @param array<string, RoomType> $rooms room code => room type of the property
     *
     * @return ArrivalReport by step-ledger row id; the delivered steps are marked
     */
    public function confirm(string $propertyId, array $rooms, DateTimeImmutable $now): ArrivalReport
    {
        $pending = array_filter($this->steps->pending($propertyId), static fn (StepRecord $s): bool => isset($rooms[$s->roomCode]));
        if ($pending === []) {
            return new ArrivalReport([], [], []);
        }
        $listingRooms = [];
        $bases = [];
        foreach ($rooms as $room) {
            $listingRooms[$room->pricingRef] = $room->code;
            $bases[$room->code] = $room->base;
        }
        $pushed = PushTimes::fromListings($listingRooms, $this->pricing->listListings());
        $report = $this->arrival->check($pending, $pushed->pushedAt, $this->pmsPrices($pending, $rooms), $bases, $now);
        $this->steps->markDelivered($report->delivered);

        return $report;
    }

    /**
     * @param array<int, StepRecord>  $pending
     * @param array<string, RoomType> $rooms
     *
     * @return array<string, float|null> "ROOM|YYYY-MM-DD" => price the PMS holds
     */
    private function pmsPrices(array $pending, array $rooms): array
    {
        $nights = array_map(static fn (StepRecord $s): DateTimeImmutable => $s->night, $pending);
        $codes = array_values(array_unique(array_map(static fn (StepRecord $s): string => $s->roomCode, $pending)));
        $refs = array_map(static fn (string $code): string => $rooms[$code]->pmsRef, $codes);
        $byRef = array_combine($refs, $codes);
        $prices = [];
        foreach ($this->pms->readInventory($refs, min($nights), max($nights), true) as $ref => $inventory) {
            foreach ($inventory as $night) {
                $prices[PlanItem::keyOf($byRef[$ref], $night->date)] = $night->pmsPrice;
            }
        }

        return $prices;
    }
}
