<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;

final class NightFieldsTest extends TestCase
{
    public function testADateRuleIsReadWithoutItsReason(): void
    {
        $fields = NightFields::fromOverride(new DateOverride(Days::date('2027-03-10'), -29.0, 10.0, 2, '1111111', '1111110', 'pace step'));

        self::assertSame([
            ChangeField::CEILING_PCT => 10,
            ChangeField::CHECK_IN_MASK => '1111111',
            ChangeField::CHECK_OUT_MASK => '1111110',
            ChangeField::FLOOR_PCT => -29,
            ChangeField::MIN_STAY => 2,
        ], $fields->all());
        self::assertTrue(NightFields::fromOverride(null)->isEmpty());
    }

    public function testAPmsNightIsReadWithItsChannelLimits(): void
    {
        $fields = NightFields::fromInventory(new InventoryNight(Days::date('2027-03-10'), 3, 2, null, Restriction::NoCheckin, 450.0), ['booking' => '0']);

        self::assertSame([
            'pms_available' => 3,
            'pms_channel_limit:booking' => '0',
            'pms_min_stay' => 2,
            'pms_restriction' => 'no_checkin',
        ], $fields->all());
    }

    public function testAWriteMergesIntoTheRule(): void
    {
        $before = new NightFields([ChangeField::FLOOR_PCT => -20, ChangeField::MIN_STAY => 2]);
        $merged = $before->merge(new NightFields([ChangeField::FLOOR_PCT => -25, ChangeField::CEILING_PCT => 5]));

        self::assertSame([ChangeField::CEILING_PCT => 5, ChangeField::FLOOR_PCT => -25, ChangeField::MIN_STAY => 2], $merged->all());
        self::assertSame([ChangeField::CEILING_PCT, ChangeField::FLOOR_PCT], $before->differs($merged));
    }

    public function testNumbersCompareWithinAMillionthAndTextsExactly(): void
    {
        self::assertTrue((new NightFields([ChangeField::FLOOR_FIXED => 100.0]))->equals(new NightFields([ChangeField::FLOOR_FIXED => 100.0000001])));
        self::assertFalse((new NightFields([ChangeField::FLOOR_FIXED => 100.0]))->equals(new NightFields([ChangeField::FLOOR_FIXED => 100.01])));
        self::assertFalse(NightFields::same('0', 0));
        self::assertSame(-29, (new NightFields([ChangeField::FLOOR_PCT => -29.0]))->get(ChangeField::FLOOR_PCT));
        self::assertSame((new NightFields([ChangeField::FLOOR_PCT => -29]))->fingerprint(), (new NightFields([ChangeField::FLOOR_PCT => -29.0]))->fingerprint());
    }

    public function testFieldsAreFilteredBySystem(): void
    {
        $mixed = new NightFields([ChangeField::FLOOR_PCT => -10, ChangeField::PMS_MIN_STAY => 2, ChangeField::LISTING_BASE => 900.0]);

        self::assertSame([ChangeField::PMS_MIN_STAY => 2], $mixed->of(Provider::Pms)->all());
        self::assertSame([ChangeField::FLOOR_PCT => -10], $mixed->of(Provider::Pricing)->all());
        self::assertSame(Provider::Pms, ChangeField::providerOf('pms_channel_limit:airbnb'));
        self::assertNull(ChangeField::providerOf('pms_channel_limit:other'));
    }

    public function testUnknownFieldsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NightFields(['price1' => 100]);
    }

    public function testTheIntendedStateFollowsTheKindOfWrite(): void
    {
        $before = new NightFields([ChangeField::FLOOR_PCT => -20, ChangeField::CEILING_PCT => 10]);
        $sent = new NightFields([ChangeField::FLOOR_PCT => -25]);
        $night = static fn (WriteKind $kind, NightFields $sent): WrittenNight => new WrittenNight(Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), $kind, $sent, $before, new DateTimeImmutable('2027-03-03T12:00:00Z'));

        self::assertSame([ChangeField::CEILING_PCT => 10, ChangeField::FLOOR_PCT => -25], $night(WriteKind::Write, $sent)->intended()->all());
        self::assertSame([ChangeField::FLOOR_PCT => -25], $night(WriteKind::Rewrite, $sent)->intended()->all());
        self::assertTrue($night(WriteKind::Delete, NightFields::none())->intended()->isEmpty());
        self::assertSame('pricing|Deluxe|2027-03-10', $night(WriteKind::Write, $sent)->key());
    }

    public function testAWrittenNightKeepsToItsSystem(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WrittenNight(Provider::Pms, 'Deluxe', Days::date('2027-03-10'), WriteKind::Write, new NightFields([ChangeField::FLOOR_PCT => -25]), NightFields::none(), new DateTimeImmutable('2027-03-03T12:00:00Z'));
    }

    public function testThePmsIsNeverDeleted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WrittenNight(Provider::Pms, 'Deluxe', Days::date('2027-03-10'), WriteKind::Delete, NightFields::none(), NightFields::none(), new DateTimeImmutable('2027-03-03T12:00:00Z'));
    }
}
