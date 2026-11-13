<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Protection;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Pricing\Protection\FloorSource;
use Ritmo\Domain\Pricing\Protection\ProtectionConfig;
use Ritmo\Domain\Pricing\Protection\ProtectionOutcome;
use Ritmo\Domain\Pricing\Protection\SoldOutProtection;
use Ritmo\Tests\Support\ParityNames;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

/**
 * A room with base 1173 and maximum 5000; market p90 1420 public, factor 0.63: protection floor 2254.
 */
final class SoldOutProtectionTest extends TestCase
{
    public function testDisabledAndWithoutAnchorThereIsNoItem(): void
    {
        $off = new SoldOutProtection(new ProtectionConfig(enabled: false), 0.03);

        self::assertSame(ProtectionOutcome::DISABLED, $off->propose(Reference::room(ParityNames::room('low')), $this->live(2100.0), null, $this->anchor(), 0.63, 2000.0)->code);
        self::assertSame(ProtectionOutcome::NO_ANCHOR, $this->protection()->propose(Reference::room(ParityNames::room('low')), $this->live(2100.0), null, null, 0.63, 2000.0)->code);
    }

    public function testTheConfiguredPercentileIsHonoured(): void
    {
        $at75 = new SoldOutProtection(new ProtectionConfig(percentile: 75.0), 0.03);
        $item = $at75->propose(Reference::room(ParityNames::room('low')), $this->live(1500.0), null, $this->anchor(), 0.63, 1000.0)->item;

        self::assertNotNull($item);
        self::assertSame(1747.0, $item->minPrice, '1100 / 0.63 rounded up; the reference engine always uses 90');
        self::assertSame(75.0, $item->percentile);
    }

    public function testAnUnknownLiveFloorHasItsOwnReason(): void
    {
        $live = new PriceNight(new DateTimeImmutable('2027-03-12'), 2100.0, null, null, null, 7, 7);

        self::assertSame(ProtectionOutcome::UNKNOWN_LIVE_FLOOR, $this->protection()->propose(Reference::room(ParityNames::room('low')), $live, null, $this->anchor(), 0.63, 2000.0)->code);
    }

    public function testAHigherLiveFloorComesDownWhenKeepingItIsSwitchedOff(): void
    {
        $override = new DateOverride(new DateTimeImmutable('2027-03-12'), 156.0, 156.0, reason: 'ladder price');
        $live = $this->live(3000.0, 3000.0);

        $kept = $this->protection()->propose(Reference::room(ParityNames::room('low')), $live, $override, $this->anchor(), 0.63, 2000.0)->item;
        $lowered = (new SoldOutProtection(new ProtectionConfig(keepLiveFloorOnRelease: false), 0.03))
            ->propose(Reference::room(ParityNames::room('low')), $live, $override, $this->anchor(), 0.63, 2000.0)->item;

        self::assertSame(3000.0, $kept?->minPrice);
        self::assertSame(FloorSource::LiveFloorKept, $kept?->floorSource);
        self::assertSame(2254.0, $lowered?->minPrice);
        self::assertTrue($lowered?->ceilingReleased);
    }

    public function testExtraFloorAndRelationBoundsFollowTheSwitches(): void
    {
        $order = new OwnerOrder('target', OrderType::Target, [ParityNames::room('low')], new DateTimeImmutable('2027-03-01'), new DateTimeImmutable('2027-03-31'), floors: [ParityNames::room('low') => 2600.0]);
        $all = $this->protection();
        $none = new SoldOutProtection(new ProtectionConfig(useTargetOrderFloor: false, useRelationBounds: false), 0.03);

        self::assertSame(2600.0, $all->extraFloor(2400.0, $order, ParityNames::room('low')));
        self::assertSame(2400.0, $all->extraFloor(2400.0, null, ParityNames::room('low')));
        self::assertSame(2600.0, $all->extraFloor(null, $order, ParityNames::room('low')));
        self::assertNull($all->extraFloor(null, $order, ParityNames::room('dual')));
        self::assertNull($none->extraFloor(2400.0, $order, ParityNames::room('low')));
        self::assertSame(2857.0, $all->relationCeiling(2857.0));
        self::assertNull($none->relationCeiling(2857.0));
    }

    public function testTheLightRunReachIsOpenUnlessConfigured(): void
    {
        self::assertTrue($this->protection()->inLightRunReach(40));
        $near = new SoldOutProtection(new ProtectionConfig(lightRunMaxDays: 1), 0.03);
        self::assertTrue($near->inLightRunReach(1));
        self::assertFalse($near->inLightRunReach(2));
    }

    public function testTheItemBecomesAPlanItem(): void
    {
        $item = $this->protection()->propose(Reference::room(ParityNames::room('low')), $this->live(2100.0), null, $this->anchor(), 0.63, 2000.0)->item;
        self::assertNotNull($item);
        $plan = $item->toPlanItem();

        self::assertSame('SOLD_OUT_PROTECTION', $plan->reasonCode);
        self::assertSame(2254.0, $plan->minPrice);
        self::assertSame(5000.0, $plan->maxPrice);
        self::assertTrue($plan->reasonParams['protection']);
        self::assertSame('market_percentile', $plan->reasonParams['floor_source']);
    }

    private function protection(): SoldOutProtection
    {
        return new SoldOutProtection(new ProtectionConfig(), 0.03);
    }

    private function live(float $floor, float $ceiling = 5000.0): PriceNight
    {
        return new PriceNight(new DateTimeImmutable('2027-03-12'), $floor, $floor, $ceiling, null, 7, 7);
    }

    private function anchor(): MarketAnchor
    {
        return new MarketAnchor(new DateTimeImmutable('2027-03-12'), 560.0, 760.0, 1100.0, 1420.0, 1800.0, 20, new DateTimeImmutable('2027-03-05'), AnchorOrigin::Measured);
    }
}
