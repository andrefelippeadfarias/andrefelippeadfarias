<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Report\OverbookedNight;
use Ritmo\Domain\Report\OverbookingDetector;
use Ritmo\Domain\Support\Days;

final class OverbookingDetectorTest extends TestCase
{
    public function testFindsTheNightsWithNegativeAvailability(): void
    {
        $inventory = [
            'Standard' => [
                new InventoryNight(Days::date('2027-03-10'), 0),
                new InventoryNight(Days::date('2027-03-12'), -1),
                new InventoryNight(Days::date('2027-03-11'), -2),
            ],
            'Deluxe' => [
                new InventoryNight(Days::date('2027-03-11'), -1),
                new InventoryNight(Days::date('2027-03-13'), 3),
            ],
        ];

        $found = (new OverbookingDetector())->detect($inventory);

        self::assertSame(
            [['2027-03-11', 'Deluxe', -1, 1], ['2027-03-11', 'Standard', -2, 2], ['2027-03-12', 'Standard', -1, 1]],
            array_map(static fn (OverbookedNight $night): array => [Days::key($night->night), $night->roomCode, $night->available, $night->unitsOver()], $found),
            'by night, then by room'
        );
    }

    public function testASoldOutNightIsNotOverbooked(): void
    {
        self::assertSame([], (new OverbookingDetector())->detect(['Deluxe' => [new InventoryNight(Days::date('2027-03-10'), 0)]]));
        self::assertSame([], (new OverbookingDetector())->detect([]));
    }
}
