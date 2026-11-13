<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Run\PushedFields;
use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Days;

/**
 * What the push sets in the PMS, for the third-party watch (stay spec D6): the arrival and
 * departure of the masks in force and the effective minimum stay for the rooms whose push carries
 * them; the minimum stay of the date rules for every room.
 */
final class PushedFieldsTest extends TestCase
{
    private const NIGHT = '2027-03-10';

    public function testAPushedRoomGetsTheRestrictionOfItsMasksAndItsEffectiveStay(): void
    {
        $fields = $this->pushed(new PricingSync(['STD'], [], ['STD']));

        self::assertSame(['pms_min_stay' => 2, 'pms_restriction' => 'no_checkin'], $fields['pms|STD|' . self::NIGHT]);
    }

    public function testARoomWhosePushIsOffKeepsOnlyTheStayOfItsDateRule(): void
    {
        $fields = $this->pushed(new PricingSync());

        self::assertSame(['pms_min_stay' => 2], $fields['pms|STD|' . self::NIGHT], 'a restriction changed by hand on this room is not the push');
        self::assertArrayNotHasKey('pms|GDN|' . self::NIGHT, $fields, 'no date rule, no push: nothing is expected');
    }

    public function testTheListingDefaultStayIsThePushedValueWithoutADateRule(): void
    {
        $fields = $this->pushed(new PricingSync(['GDN']));

        self::assertSame(['pms_min_stay' => 3], $fields['pms|GDN|' . self::NIGHT]);
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private function pushed(PricingSync $sync): array
    {
        $night = Days::date(self::NIGHT);
        $pricing = [
            'STD' => [self::NIGHT => new PriceNight($night, 400.0, 300.0, 600.0, 2, 0, 4)],
            'GDN' => [self::NIGHT => new PriceNight($night, 500.0, 400.0, 700.0, 3, 0, 2)],
        ];
        $overrides = ['STD' => [self::NIGHT => new DateOverride($night, 90.0, null, 2, '0000000', '1111111')]];
        $out = PushedFields::of($sync, new StaySyncCapability(StaySyncField::MinStay, StaySyncField::ArrivalDeparture), $pricing, $overrides);

        return array_map(static fn ($f): array => $f->all(), $out);
    }
}
