<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Grid;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Grid\PlanIndex;
use Ritmo\Application\Grid\PlannedNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

final class PlanIndexTest extends TestCase
{
    public function testANightWithoutADateRuleTakesTheListingValuesAsTheAmountsInForce(): void
    {
        $plan = new PlanIndex([
            self::item('A', '2026-11-20', 'floor', null, 432.0),
            self::item('A', '2026-11-20', 'ceiling', null, 1080.0),
            self::item('A', '2026-11-21', 'floor', 480.0, 420.0),
            self::item('B', '2026-11-20', 'floor', null, 300.0),
        ]);

        $filled = $plan->withListing(['A' => self::room('A')]);

        $night = $filled->night('A', '2026-11-20');
        self::assertTrue($night->nowFromListing);
        self::assertSame(400.0, $night->floorNow);
        self::assertSame(1200.0, $night->ceilingNow);
        self::assertSame('up', $night->direction());
        self::assertFalse($filled->night('A', '2026-11-21')->nowFromListing, 'a date rule in force is kept');
        self::assertSame(480.0, $filled->night('A', '2026-11-21')->floorNow);
        self::assertNull($filled->night('B', '2026-11-20')->floorNow, 'a room without listing values stays unknown');
        self::assertNull($plan->night('A', '2026-11-20')->floorNow, 'the original index is unchanged');
        self::assertNull($filled->night('A', '2026-12-01')->status, 'a night the run did not price stays empty');
    }

    public function testANightNotPricedIsNotFilled(): void
    {
        $none = PlannedNight::none();

        self::assertSame($none, $none->withListing(400.0, 1200.0));
        self::assertNull($none->withListing(400.0, 1200.0)->floorNow);
    }

    private static function room(string $code): RoomType
    {
        return new RoomType('inn', $code, 'Room ' . $code, 2, 'pms-' . $code, 'pl-' . $code, 600.0, 400.0, 1200.0, 400.0);
    }

    /**
     * @return array{room_code: string, night: \DateTimeImmutable, field: string, current: float|null, proposed: float|null, reason: string, details: array<string, mixed>, status: string}
     */
    private static function item(string $room, string $night, string $field, ?float $current, float $proposed): array
    {
        return ['room_code' => $room, 'night' => Days::date($night), 'field' => $field, 'current' => $current, 'proposed' => $proposed, 'reason' => 'PACE_STEP', 'details' => [], 'status' => PlannedNight::WOULD_WRITE];
    }
}
