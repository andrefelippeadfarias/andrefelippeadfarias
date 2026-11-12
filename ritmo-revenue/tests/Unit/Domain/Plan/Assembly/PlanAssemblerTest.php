<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan\Assembly;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\StayHook;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\Assembly\CeilingRelease;
use Ritmo\Domain\Plan\Assembly\LadderParts;
use Ritmo\Domain\Plan\Assembly\NightLine;
use Ritmo\Domain\Plan\Assembly\PlanAssembler;
use Ritmo\Domain\Plan\Assembly\RunInputs;
use Ritmo\Domain\Plan\Assembly\WorkItem;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The plan assembly with the neutral rules of a new site (no ladder, no relations, stay rules
 * off): every room night gets a line, steps become items, the plan passes the validator and
 * comes out as a payload; a light run only steps its own nights; the ceiling release and the
 * step block follow the reference. The owner's whole runs are the level-2 goldens.
 */
final class PlanAssemblerTest extends TestCase
{
    private const NIGHTS = ['2027-03-03', '2027-03-04', '2027-03-05'];

    public function testEveryRoomNightGetsALineAndTheStepsPassTheValidator(): void
    {
        $run = $this->assembler()->assemble($this->inputs());

        self::assertCount(2 * count(self::NIGHTS), $run->lines);
        self::assertNotSame([], $run->plan->items);
        foreach ($run->plan->items as $item) {
            self::assertSame('PACE_STEP', $item->reasonCode);
            self::assertSame($item->minPrice, $item->reasonParams['step']['price_to']);
        }
        self::assertTrue($run->isValid(), implode(', ', $run->validation->errorCodes()));
        self::assertNotNull($run->payload);
        self::assertNotSame([], $run->slices);
        self::assertSame([], $run->plan->deletions);
    }

    public function testALightRunStepsOnlyTheNightsOfItsFilter(): void
    {
        $full = $this->assembler()->assemble($this->inputs());
        $key = $full->plan->items[0]->key();
        $light = $this->assembler()->assemble($this->inputs(lightFilter: [$key => true]));

        self::assertSame([$key], array_map(static fn (PlanItem $i): string => $i->key(), $light->plan->items));
        $codes = array_count_values(array_map(static fn (NightLine $l): string => $l->code, $light->lines));
        self::assertSame(count($full->plan->items) - 1, $codes[NightLine::OUT_OF_FILTER] ?? 0);
    }

    public function testANightClosedInThePmsGetsNoItem(): void
    {
        $key = PlanItem::keyOf('Standard', Days::date(self::NIGHTS[1]));
        $run = $this->assembler()->assemble($this->inputs(blocked: [$key => true]));

        self::assertNotContains($key, array_map(static fn (PlanItem $i): string => $i->key(), $run->plan->items));
        $line = array_values(array_filter($run->lines, static fn (NightLine $l): bool => PlanItem::keyOf($l->room, $l->night) === $key))[0];
        self::assertSame(NightLine::BLOCKED, $line->code);
        self::assertSame(['room' => 'Standard', 'night' => self::NIGHTS[1], 'price' => 500.0, 'code' => NightLine::BLOCKED, 'min_price' => null, 'max_price' => null, 'params' => []], $line->toArray());
    }

    public function testACeilingAtTheListingMaximumIsFreedAndARuleWithACeilingIsDeletedFirst(): void
    {
        $night = Days::date(self::NIGHTS[2]);
        $withCeiling = new DateOverride($night, -10.0, 20.0, 3);
        $inputs = $this->inputs(overrides: ['Deluxe' => [self::NIGHTS[2] => $withCeiling], 'Standard' => [self::NIGHTS[2] => new DateOverride($night, -10.0)]]);
        $atMax = $this->work('Deluxe', $night, 900.0, 3000.0);
        $noRuleCeiling = $this->work('Standard', $night, 450.0, 1500.0);
        $kept = $this->work('Deluxe', Days::date(self::NIGHTS[1]), 900.0, 950.0);

        $deletions = (new CeilingRelease(new StayHook($this->stayRules())))->apply([$atMax, $noRuleCeiling, $kept], $inputs, []);

        self::assertSame([PlanItem::keyOf('Deluxe', $night)], array_map(static fn ($d): string => $d->key(), $deletions));
        self::assertSame(CeilingRelease::DELETE_REASON, $deletions[0]->reasonCode);
        self::assertNull($atMax->maxPrice);
        self::assertSame(3, $atMax->minStay, 'the item writes again the stay of the deleted rule');
        self::assertNull($noRuleCeiling->maxPrice);
        self::assertNull($noRuleCeiling->minStay);
        self::assertSame(950.0, $kept->maxPrice);
    }

    public function testANewFloorAboveTheCeilingInForceFreesThatCeiling(): void
    {
        $night = Days::date(self::NIGHTS[2]);
        $inputs = $this->inputs(overrides: ['Deluxe' => [self::NIGHTS[2] => new DateOverride($night, -10.0, 0.0)]]);
        $above = $this->work('Deluxe', $night, 1100.0, null);

        $deletions = (new CeilingRelease(new StayHook($this->stayRules())))->apply([$above], $inputs, []);

        self::assertCount(1, $deletions);
        self::assertNull($above->maxPrice);
    }

    public function testTheStepBlockFollowsTheFinalFloor(): void
    {
        $item = $this->work('Deluxe', Days::date(self::NIGHTS[1]), 1000.0, null);
        $item->params['step'] = ['price_from' => 1000.0, 'price_to' => 900.0, 'direction' => 'down'];

        $item->syncStep(0.03);
        self::assertSame(['price_from' => 1000.0, 'price_to' => 1000.0, 'direction' => null, 'engine_to' => 900.0], $item->params['step'], 'within the tolerance there is no step');

        $item->minPrice = 1100.0;
        $item->syncStep(0.03);
        self::assertSame('up', $item->params['step']['direction']);
        self::assertFalse($item->readsOccupancy());
    }

    public function testNoLadderActsWithoutAnActiveStrategyOrARuler(): void
    {
        $rooms = ['Deluxe' => EngineSample::deluxe()];
        $cadence = new StepCadence();

        self::assertNull(LadderParts::fromRules(EngineSample::rules(), $rooms, new MarketRuler(), $cadence));
        self::assertNull(LadderParts::fromRules(EngineSample::rules(), $rooms, null, $cadence));
    }

    private function assembler(): PlanAssembler
    {
        return new PlanAssembler(EngineSample::rules(), new FixedClock('2027-03-03T12:00:00Z'), 'sample-pms');
    }

    /**
     * @param array<string, array<string, DateOverride>> $overrides
     * @param array<string, true>                        $blocked
     * @param array<string, true>|null                   $lightFilter
     */
    private function inputs(array $overrides = [], array $blocked = [], ?array $lightFilter = null): RunInputs
    {
        $rooms = [EngineSample::deluxe(), EngineSample::standard()];
        $calendar = [];
        $anchors = [];
        foreach (self::NIGHTS as $night) {
            $calendar['Deluxe'][$night] = new PriceNight(Days::date($night), 1000.0, 600.0, 3000.0, null, 0, 4);
            $calendar['Standard'][$night] = new PriceNight(Days::date($night), 500.0, 300.0, 1500.0, null, 1, 6);
            $anchors[$night] = EngineSample::anchor($night);
        }
        $now = new DateTimeImmutable('2027-03-03T12:00:00Z');

        return new RunInputs(
            EngineSample::property(),
            $rooms,
            Days::date(EngineSample::TODAY),
            $now,
            count(self::NIGHTS) - 1,
            $calendar,
            $overrides,
            new AnchorLookup(EngineSample::rules(), $anchors),
            new StepLedger(),
            new OrderBook(),
            null,
            $blocked,
            $lightFilter,
            ['Deluxe' => $now, 'Standard' => $now],
        );
    }

    private function work(string $room, DateTimeImmutable $night, ?float $floor, ?float $ceiling): WorkItem
    {
        return new WorkItem($room, $night, 1000.0, $floor, $ceiling, WorkItem::PACE_STEP, [], new NightState(1000.0));
    }

    private function stayRules(): StayRules
    {
        $rules = EngineSample::rules();

        return new StayRules($rules->stay(), new HolidayCalendar($rules), [EngineSample::deluxe(), EngineSample::standard()]);
    }
}
