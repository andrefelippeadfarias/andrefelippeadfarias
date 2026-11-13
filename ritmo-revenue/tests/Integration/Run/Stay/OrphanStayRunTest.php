<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LaunchStatus;

/**
 * Regression: the orphan changes of the stay rules reach the PMS with the proof of this run's
 * read (SW-X5), instead of being refused as pms_orphan_proof.
 */
final class OrphanStayRunTest extends TestCase
{
    public function testTheOrphanNightBeforeAnOverbookedNightGoesDownToOneInThePms(): void
    {
        $world = StayWorld::auto([
            'arrival_rule' => ['enabled' => true, 'by_weekday' => [2, 2, 2, 2, 2, 2, 2]],
            'orphan' => ['next_night' => true, 'on_holidays' => true],
        ]);
        $world->run();
        $pms = $world->apis->world()['pms'];
        // GDN (2 units): 12 March free (1 left), 13 March overbooked (numAvail -1), minimum read 2
        self::assertSame(2, $pms['902001']['2027-03-03']['minStay'] ?? null, 'control: the run wrote the arrival rule to the PMS');
        self::assertSame(1, $pms['902002']['2027-03-12']['minStay'] ?? null, 'the orphan night goes to one night in the PMS');
    }

    /**
     * Friday 5 March sold out in STD, Saturday 6 free: the Saturday opens its arrival in the PMS
     * (with the proof). Its stay of one night is not a PMS change: as in the reference, only the
     * Saturday after a past Friday sends its stay to the PMS; a future one leaves it to the pricing
     * system.
     */
    public function testTheFutureOrphanSaturdayOpensItsArrivalInThePms(): void
    {
        $closed = ['rooms' => ['STD'], 'min_stay' => 2, 'orphan_side' => ['enabled' => true, 'on_holidays' => true, 'when_closed_day_sold_out' => 'no_checkin', 'when_previous_sold_out' => 'no_checkout']] + StayWorld::CLOSED_SATURDAY;
        $world = StayWorld::auto(['closed_days' => [$closed]]);
        $result = $world->run();

        self::assertSame(LaunchStatus::Completed, $result->status, (string) $result->note);
        self::assertSame('noCheckOut', StayWorld::pmsNight($world, '902001', '2027-03-06')['override'] ?? null);
        self::assertSame('noCheckInOrCheckOut', StayWorld::pmsNight($world, '902001', '2027-03-13')['override'] ?? null, 'a Saturday without an orphan side is closed');
    }
}
