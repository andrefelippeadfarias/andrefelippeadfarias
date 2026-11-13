<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Confirm\LiveCheck;
use Ritmo\Domain\Write\Confirm\LiveRoute;
use Ritmo\Domain\Write\Confirm\LiveStatus;
use Ritmo\Domain\Write\Confirm\LiveVerdict;
use Ritmo\Domain\Write\Confirm\StoredCheck;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * The stored and live levels of the confirmation (docs/VERIFY.md, sections 2 and 3).
 */
final class ConfirmTest extends TestCase
{
    private const BASE = 1000.0;

    public function testAMergedWriteIsStoredWhenTheReadBackHoldsTheMergedRule(): void
    {
        $written = self::pricing(WriteKind::Write, [ChangeField::FLOOR_PCT => -25], [ChangeField::FLOOR_PCT => -20, ChangeField::MIN_STAY => 2]);

        self::assertSame([], StoredCheck::differences($written, new NightFields([ChangeField::FLOOR_PCT => -25, ChangeField::MIN_STAY => 2, ChangeField::PRICE => 0, ChangeField::PRICE_TYPE => 'percent'])));
        self::assertSame([ChangeField::FLOOR_PCT], StoredCheck::differences($written, new NightFields([ChangeField::FLOOR_PCT => -20, ChangeField::MIN_STAY => 2])), 'the write did not apply');
        self::assertSame([ChangeField::MIN_STAY], StoredCheck::differences($written, new NightFields([ChangeField::FLOOR_PCT => -25])), 'the merge lost the stay');
    }

    public function testADeletionIsStoredOnlyWithoutARule(): void
    {
        $written = self::pricing(WriteKind::Delete, [], [ChangeField::FLOOR_PCT => -20]);

        self::assertSame([], StoredCheck::differences($written, NightFields::none()));
        self::assertSame([ChangeField::RULE], StoredCheck::differences($written, new NightFields([ChangeField::FLOOR_PCT => -20])));
    }

    public function testAGroupLeavesNothingOfTheOldRule(): void
    {
        $written = self::pricing(WriteKind::Rewrite, [ChangeField::FLOOR_PCT => -25], [ChangeField::FLOOR_PCT => -20, ChangeField::CEILING_PCT => 10]);

        self::assertSame([], StoredCheck::differences($written, new NightFields([ChangeField::FLOOR_PCT => -25])));
        self::assertSame([ChangeField::CEILING_PCT], StoredCheck::differences($written, new NightFields([ChangeField::FLOOR_PCT => -25, ChangeField::CEILING_PCT => 10])));
    }

    public function testPmsAvailabilityIsComparedNetOfBookings(): void
    {
        $written = new WrittenNight(Provider::Pms, 'Deluxe', Days::date('2027-03-10'), WriteKind::Write, new NightFields([ChangeField::PMS_AVAILABLE => 4, ChangeField::PMS_MIN_STAY => 2]), NightFields::none(), self::at(), expectedAvailable: 3);

        self::assertSame([], StoredCheck::differences($written, new NightFields([ChangeField::PMS_AVAILABLE => 3, ChangeField::PMS_MIN_STAY => 2])));
        self::assertSame([ChangeField::PMS_AVAILABLE], StoredCheck::differences($written, new NightFields([ChangeField::PMS_AVAILABLE => 4, ChangeField::PMS_MIN_STAY => 2])));
    }

    public function testALiveRuleAppliesTheWrittenFloorAndThePriceObeysIt(): void
    {
        $written = self::pricing(WriteKind::Write, [ChangeField::FLOOR_PCT => -20, ChangeField::CEILING_PCT => 10, ChangeField::MIN_STAY => 2]);
        $check = new LiveCheck();

        self::assertSame(LiveStatus::Live, $check->check($written, self::BASE, self::recalc(820.0, 800.0, 2))->status);
        self::assertSame(LiveStatus::Inactive, $check->check($written, self::BASE, self::recalc(820.0, 800.0, 2, false))->status, 'expired by lead time');
        self::assertSame(LiveStatus::FloorDiffers, $check->check($written, self::BASE, self::recalc(820.0, 850.0, 2))->status);
        self::assertSame(LiveStatus::BelowFloor, $check->check($written, self::BASE, self::recalc(787.0, 800.0, 2))->status, 'more than the slack (12) under 800');
        self::assertSame(LiveStatus::Live, $check->check($written, self::BASE, self::recalc(788.0, 800.0, 2))->status, 'inside the slack');
        self::assertSame(LiveStatus::AboveCeiling, $check->check($written, self::BASE, self::recalc(1125.0, 800.0, 2))->status);
        self::assertSame(LiveStatus::NoData, $check->check($written, self::BASE, null)->status);
    }

    public function testOnASoldOutNightOnlyTheRuleIsJudged(): void
    {
        $verdict = (new LiveCheck())->check(self::pricing(WriteKind::Write, [ChangeField::FLOOR_PCT => -20]), self::BASE, self::recalc(500.0, 800.0, 2, true, 4));

        self::assertSame(LiveStatus::Live, $verdict->status);
        self::assertFalse($verdict->priceChecked);
    }

    public function testAStayThatDiffersOnAnIsolatedNightIsAWarning(): void
    {
        $written = self::pricing(WriteKind::Write, [ChangeField::MIN_STAY => 2]);
        $check = new LiveCheck();

        self::assertSame(LiveStatus::StayDiffers, $check->check($written, self::BASE, self::recalc(900.0, null, 1))->status);
        $isolated = $check->check($written, self::BASE, self::recalc(900.0, null, 1), true);
        self::assertSame(LiveStatus::Live, $isolated->status);
        self::assertSame([LiveVerdict::ISOLATED_NIGHT_STAY], $isolated->warnings);
    }

    public function testADeletionIsLiveWhenNoRuleIsApplied(): void
    {
        $written = self::pricing(WriteKind::Delete, [], [ChangeField::FLOOR_PCT => -20]);

        self::assertSame(LiveStatus::Live, (new LiveCheck())->check($written, self::BASE, self::recalc(900.0, 600.0, 2, false))->status);
        self::assertSame(LiveStatus::DeletionStillLive, (new LiveCheck())->check($written, self::BASE, self::recalc(900.0, 800.0, 2))->status);
    }

    public function testARecalculationIsSpentOnlyWhenTheCalendarIsOlderThanTheWrite(): void
    {
        $written = self::at();

        self::assertSame(LiveRoute::CompareNow, LiveRoute::for($written, $written->modify('+40 seconds'), false));
        self::assertSame(LiveRoute::Recalculate, LiveRoute::for($written, $written->modify('-1 hour'), true));
        self::assertSame(LiveRoute::NotConfirmedQuota, LiveRoute::for($written, null, false));
    }

    /**
     * @param array<string, int|string> $sent
     * @param array<string, int|string> $before
     */
    private static function pricing(WriteKind $kind, array $sent, array $before = []): WrittenNight
    {
        return new WrittenNight(Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), $kind, new NightFields($sent), new NightFields($before), self::at());
    }

    private static function recalc(float $price, ?float $floor, ?int $stay, bool $override = true, int $sold = 1): PriceNight
    {
        return new PriceNight(Days::date('2027-03-10'), $price, $floor, null, $stay, $sold, 4, $override);
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-03-03T12:00:00Z');
    }
}
