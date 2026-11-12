<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\BatchPolicy;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Calendar\Stay\StayReason;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

final class StayBatchTest extends TestCase
{
    private const PROPERTY = ['Deluxe' => 'inn', 'Standard' => 'inn', 'Cottage' => 'annex', '101' => 'inn', '20' => 'inn'];

    public function testUrgentChangesComeFirstByNightThenTheRestByPropertyRoomAndNight(): void
    {
        $audit = [
            $this->room('Cottage', arrival: [$this->closure('Cottage', '2027-03-20')], stays: [$this->stay('Cottage', '2027-03-02')]),
            $this->room('Deluxe', arrival: [$this->closure('Deluxe', '2027-03-06')], stays: [$this->stay('Deluxe', '2027-03-06'), $this->stay('Deluxe', '2027-04-02'), $this->stay('Deluxe', '2027-03-16')],
                package: [$this->stay('Deluxe', '2027-03-15', StayReason::Package)]),
            $this->room('Standard', stays: [$this->stay('Standard', '2027-03-02'), $this->stay('Standard', '2027-03-21')]),
        ];

        $batch = (new StayBatch(new BatchPolicy(14, 100), self::PROPERTY))->single($audit, Days::date('2027-03-01'), false);

        self::assertSame([
            'Cottage 2027-03-02 stay', 'Standard 2027-03-02 stay', 'Deluxe 2027-03-06 arrival', 'Deluxe 2027-03-06 stay', 'Deluxe 2027-03-15 stay',
            'Cottage 2027-03-20 arrival', 'Deluxe 2027-03-16 stay', 'Deluxe 2027-04-02 stay', 'Standard 2027-03-21 stay',
        ], $this->labels($batch), 'urgent to 15 March by night (annex before inn), then annex, then inn by room and night');
    }

    public function testStayOnlyKeepsTheStaysForThePmsAndEachChangeOnce(): void
    {
        $pricingOnly = new StayChange('Deluxe', Days::date('2027-03-13'), ChangeTarget::Pricing, StayReason::ClosedDayOrphan, minStay: 1, minStayBefore: 2);
        $audit = [$this->room('Deluxe', arrival: [$this->closure('Deluxe', '2027-03-06')], stays: [$this->stay('Deluxe', '2027-03-04'), $this->stay('Deluxe', '2027-03-04')],
            orphans: [$pricingOnly, $this->stay('Deluxe', '2027-03-06', StayReason::ClosedDayOrphan)], package: [$this->stay('Deluxe', '2027-03-05', StayReason::Package)])];

        $batch = new StayBatch(new BatchPolicy(), self::PROPERTY);

        self::assertSame(['Deluxe 2027-03-04 stay', 'Deluxe 2027-03-06 stay'], $this->labels($batch->single($audit, Days::date('2027-03-01'), true)));
        self::assertSame(['Deluxe 2027-03-04 stay', 'Deluxe 2027-03-05 stay', 'Deluxe 2027-03-06 arrival', 'Deluxe 2027-03-06 stay'], $this->labels($batch->single($audit, Days::date('2027-03-01'), false)));
    }

    public function testRoomCodesSortAsText(): void
    {
        $audit = [$this->room('20', stays: [$this->stay('20', '2027-05-01')]), $this->room('101', stays: [$this->stay('101', '2027-05-01')])];

        self::assertSame(['101 2027-05-01 stay', '20 2027-05-01 stay'], $this->labels((new StayBatch(new BatchPolicy(), self::PROPERTY))->single($audit, Days::date('2027-03-01'), true)));
    }

    public function testTheBatchIsCutIntoBlocksOfAtMostTheRangesPerRequest(): void
    {
        $stays = [];
        for ($i = 0; $i < 250; ++$i) {
            $stays[] = $this->stay('Deluxe', Days::key(Days::add(Days::date('2027-03-01'), $i)));
        }
        $batch = new StayBatch(new BatchPolicy(14, 100), self::PROPERTY);

        $blocks = $batch->blocks($batch->single([$this->room('Deluxe', stays: $stays)], Days::date('2027-03-01'), true));

        self::assertSame([100, 100, 50], array_map('count', $blocks));
        self::assertSame([], $batch->blocks([]));
    }

    /**
     * @param list<ArrivalDepartureChange> $arrival
     * @param list<StayChange>             $stays
     * @param list<StayChange>             $orphans
     * @param list<StayChange>             $package
     */
    private function room(string $code, array $arrival = [], array $stays = [], array $orphans = [], array $package = []): RoomAudit
    {
        return new RoomAudit($code, $arrival, [], [], $orphans, $package, $stays);
    }

    private function stay(string $room, string $night, StayReason $reason = StayReason::ArrivalDay): StayChange
    {
        return new StayChange($room, Days::date($night), ChangeTarget::Pms, $reason, minStay: 2, minStayBefore: 1);
    }

    private function closure(string $room, string $night): ArrivalDepartureChange
    {
        return ArrivalDepartureChange::forPms($room, Days::date($night), StayReason::ClosedDay, Restriction::None, Restriction::NoCheckinCheckout);
    }

    /**
     * @param list<StayChange|ArrivalDepartureChange> $changes
     *
     * @return list<string>
     */
    private function labels(array $changes): array
    {
        return array_map(static fn ($c): string => sprintf('%s %s %s', $c->roomCode, Days::key($c->night), $c instanceof StayChange ? 'stay' : 'arrival'), $changes);
    }
}
