<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\BatchPolicy;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Operations\LightRoundPlan;
use Ritmo\Domain\Operations\LightTrigger;
use Ritmo\Domain\Operations\OrphanWindow;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\Calendar\Stay\StaySample;

/**
 * The PMS side of the orphan stays of a light run (operations spec 4.8; O19, O20): only the rooms
 * with a flip, only the nights of the window, never a frozen night, from the inventory read now.
 */
final class OrphanWindowTest extends TestCase
{
    private const TODAY = '2027-03-01';

    private static function plan(array $rooms, int $days): LightRoundPlan
    {
        $today = Days::date(self::TODAY);

        return new LightRoundPlan([LightTrigger::SoldOutFlip], [], $rooms, [], [], [], $rooms, new DateRange($today, Days::add($today, $days)), []);
    }

    private static function window(): OrphanWindow
    {
        return new OrphanWindow(
            StaySample::audit(['arrival_rule' => StaySample::ARRIVAL, 'orphan' => ['next_night' => true], 'pricing_sync' => ['min_stay_rooms' => ['Deluxe']]]),
            new StayBatch(new BatchPolicy(), ['Deluxe' => 'inn', 'Standard' => 'inn']),
        );
    }

    /**
     * @return array<string, array<string, \Ritmo\Domain\Model\InventoryNight>>
     */
    private static function inventory(): array
    {
        $table = ['2027-03-01' => [1, 1], '2027-03-02' => [1, 3], '2027-03-03' => [1, 2], '2027-03-04' => [0, 1], '2027-03-08' => [0, 1]];

        return ['Deluxe' => StaySample::nights($table), 'Standard' => StaySample::nights($table)];
    }

    /**
     * @param list<StayChange|ArrivalDepartureChange> $changes
     *
     * @return list<string>
     */
    private static function labels(array $changes): array
    {
        return array_map(static fn (StayChange|ArrivalDepartureChange $c): string => $c->roomCode . ' ' . Days::key($c->night), $changes);
    }

    public function testOnlyTheFlippedRoomsAndTheNightsOfTheWindowAreReplanned(): void
    {
        $changes = self::window()->changes(self::inventory(), self::plan(['Deluxe'], 4), Days::date(self::TODAY), null);

        self::assertSame(['Deluxe 2027-03-01', 'Deluxe 2027-03-02', 'Deluxe 2027-03-03'], self::labels($changes), 'the 8th is beyond D4 and Standard did not flip');
        self::assertTrue($changes[0] instanceof StayChange && $changes[0]->alsoPricing, 'the pricing side goes with it (STAY.md)');
    }

    public function testAFrozenNightGetsNothing(): void
    {
        $changes = self::window()->changes(self::inventory(), self::plan(['Deluxe'], 4), Days::date(self::TODAY), null, ['Deluxe|2027-03-02' => true]);

        self::assertSame(['Deluxe 2027-03-01', 'Deluxe 2027-03-03'], self::labels($changes));
    }

    public function testWithoutAFlipNothingIsAudited(): void
    {
        self::assertSame([], self::window()->changes(self::inventory(), self::plan([], 7), Days::date(self::TODAY), null));
    }
}
