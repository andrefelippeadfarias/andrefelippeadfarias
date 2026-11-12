<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Relations\Coherence\CoherenceContext;
use Ritmo\Domain\Relations\Coherence\CoherencePass;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\Outcome;
use Ritmo\Domain\Relations\Coherence\Reason;
use Ritmo\Domain\Relations\Coherence\RelationDecision;
use Ritmo\Domain\Relations\Coherence\RelationWarning;
use Ritmo\Domain\Relations\Port\FloorInForce;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Days;

/**
 * The discount band and the occupancy switch in the coherence stage, on the "lodge" of the sample:
 * PLAIN (base 1270, 4 units) between 85.5% and 90% of the cheapest free tub room (SPA 5 units,
 * SPA2 2 units); at 80% of the 7 tub units sold, PLAIN goes to its market price.
 */
final class CoherenceBandTest extends TestCase
{
    private const N = RelationsSample::NIGHT;
    private const KEY = 'PLAIN|' . RelationsSample::NIGHT;

    /**
     * @param array<string, array<int, int|float>> $rows   room => [price, sold, floor?, ceiling?]
     * @param list<Relation>|null                  $relations
     * @param list<DraftItem>                      $items
     * @param array<string, float>                 $strategy
     */
    private static function pass(array $rows, ?StubHooks $hooks = null, ?array $relations = null, string $night = self::N, array $items = [], array $strategy = []): CoherenceContext
    {
        $calendar = RelationsSample::calendar(array_map(static fn (array $row): array => [$night => $row], $rows));
        $context = RelationsSample::context($relations ?? RelationsSample::lodgeRelations(), $calendar, $hooks ?? new StubHooks(), [], $items, null, $strategy);
        (new CoherencePass($context))->run([Days::date($night)]);

        return $context;
    }

    private static function market(float $price = 1036.49): StubHooks
    {
        $hooks = new StubHooks();
        $hooks->market[self::KEY] = $price;
        $hooks->activeOrders[self::KEY] = [];

        return $hooks;
    }

    /**
     * @return array<string, string> relation id => outcome
     */
    private static function outcomes(CoherenceContext $context): array
    {
        $outcomes = [];
        foreach ($context->report->decisions() as $decision) {
            $outcomes[$decision->relationId] = $decision->outcome->value;
        }

        return $outcomes;
    }

    private static function plain(CoherenceContext $context): ?array
    {
        $item = $context->draft->get('PLAIN', Days::date(self::N));

        return $item === null ? null : [$item->floor(), $item->ceiling(), $item->orderRef()];
    }

    public function testTheBandPutsTheTargetOnItsOwnPercentGrid(): void
    {
        $context = self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [700, 0, 700, 700]]);

        self::assertSame([876.3, 876.3, 'band-order'], self::plain($context), '69% of 1270, inside 869 to 900');
        self::assertSame(876.3, $context->facts->decided('PLAIN', Days::date(self::N)));
        self::assertSame(['plain-switch' => 'not_triggered', 'plain-band' => 'applied'], self::outcomes($context));
    }

    public function testInsideTheBandAndOneGridStepNothingIsWritten(): void
    {
        foreach ([855.0, 889.0, 913.7] as $price) {
            $context = self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [$price, 0]]);
            self::assertNull(self::plain($context), (string) $price);
        }
        self::assertSame([889.0, 889.0, 'band-order'], self::plain(self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [914, 0]])));
    }

    public function testASoldOutMemberLeavesTheSetAndAWholeSoldOutSetLeavesTheTargetAlone(): void
    {
        self::assertSame([1130.3, 1130.3, 'band-order'], self::plain(self::pass(['SPA' => [1300, 0], 'SPA2' => [1000, 2], 'PLAIN' => [950, 0]])));
        $context = self::pass(['SPA' => [1300, 5], 'SPA2' => [1000, 2], 'PLAIN' => [1400, 0]], null, [RelationsSample::lodgeRelations()[1]]);
        self::assertNull(self::plain($context));
        self::assertSame(['plain-band' => 'empty_set'], self::outcomes($context));
    }

    public function testOnADownOnlyNightTheBandLowersButNeverRaises(): void
    {
        $night = RelationsSample::HOLIDAY;
        $lowered = self::pass(['SPA' => [3000, 1], 'SPA2' => [2578.1, 0], 'PLAIN' => [2425.7, 0]], null, null, $night);
        $low = self::pass(['SPA' => [3000, 1], 'SPA2' => [2578.1, 0], 'PLAIN' => [2000, 0]], null, null, $night);

        self::assertSame(2311.4, $lowered->draft->get('PLAIN', Days::date($night))?->floor());
        self::assertSame([], $low->draft->items());
    }

    public function testAFloorAboveTheUpperEdgeWinsAndAnOrderFloorGivesWay(): void
    {
        $floor = new StubHooks();
        $floor->floors[self::KEY] = new FloorInForce(690.0);
        $above = self::pass(['SPA' => [750, 1], 'SPA2' => [800, 0], 'PLAIN' => [640, 0]], $floor);
        self::assertSame([698.5, 698.5, 'band-order'], self::plain($above), 'the floor 690 goes to 55% of 1270, above 90% of 750');
        self::assertSame(RelationWarning::FLOOR_ABOVE_BAND, $above->report->warnings()[0]->code);

        $order = new StubHooks();
        $order->floors[self::KEY] = new FloorInForce(950.0, true);
        $order->lowest[self::KEY] = 640.0;
        $yielded = self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [1000, 0]], $order);
        self::assertSame([889.0, 889.0, 'band-order'], self::plain($yielded));
        self::assertSame(RelationWarning::ORDER_FLOOR_YIELDED, $yielded->report->warnings()[0]->code);
    }

    public function testAPaceStepOnlyChoosesInsideTheBandAndIsDroppedOnALadderNightOfTheReference(): void
    {
        $step = new DraftItem('PLAIN', Days::date(self::N), 950.0, 800.0, 800.0, null, true);
        self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [950, 0]], null, null, self::N, [$step]);
        self::assertSame([876.3, 876.3, 'band-order'], [$step->floor(), $step->ceiling(), $step->orderRef()]);

        $ladderStep = new DraftItem('PLAIN', Days::date(self::N), 950.0, 880.0, null, null, true);
        $context = self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [950, 0]], null, null, self::N, [$ladderStep], ['SPA|' . self::N => 1000.0]);
        self::assertNotSame($ladderStep, $context->draft->get('PLAIN', Days::date(self::N)));
        self::assertSame([889.0, 889.0, 'band-order'], self::plain($context), 'the upper edge on a ladder night');
        self::assertSame(RelationWarning::ENGINE_STEP_DISCARDED, $context->report->warnings()[0]->code);
    }

    public function testUseSoldOutAndBoundsAreConfigurable(): void
    {
        $band = RelationsSample::relation('plain-band', 'discount_band', 'PLAIN', [
            'set' => ['room_tags' => ['tub']], 'upper_percent' => 90, 'lower_percent' => 80, 'on_empty_set' => 'use_sold_out', 'write_mode' => 'bounds',
        ], ['order_ref' => 'band-order']);
        $context = self::pass(['SPA' => [1300, 5], 'SPA2' => [1000, 2], 'PLAIN' => [1400, 0]], null, [$band]);

        self::assertSame([800.1, 889.0, 'band-order'], self::plain($context), 'bounds 63% (800.10) to 70% (889) of 1270 over the sold-out 1000');
        self::assertSame(800.1, $context->facts->decided('PLAIN', Days::date(self::N)));
    }

    public function testTheSwitchSendsTheTargetToTheMarketAndSuspendsTheBand(): void
    {
        $context = self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]], self::market());

        self::assertSame([1041.4, 1041.4, 'switch-order'], self::plain($context), '6 of 7 tub units sold: 82% of 1270');
        self::assertSame(['plain-switch' => 'switch_applied'], self::outcomes($context));
    }

    public function testTheSwitchReplacesAPaceStepAndPutsItsReasonFirst(): void
    {
        $step = new DraftItem('PLAIN', Days::date(self::N), 2000.0, 1900.0, null, 'step-order', true);
        $step->addReason(new Reason('pace.step'));
        self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]], self::market(), null, self::N, [$step]);

        self::assertSame([1041.4, 1041.4, 'switch-order'], [$step->floor(), $step->ceiling(), $step->orderRef()]);
        self::assertSame([Reason::OCCUPANCY_SWITCH, 'pace.step'], array_map(static fn (Reason $reason): string => $reason->code, $step->reasons()));
    }

    public function testBelowTheThresholdTheBandRuns(): void
    {
        $context = self::pass(['SPA' => [2400, 4], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]], self::market());

        self::assertSame(['plain-switch' => 'not_triggered', 'plain-band' => 'applied'], self::outcomes($context));
    }

    public function testTheSwitchStaysUnderTheBandWhileAMemberIsFreeAndAboveItsFloor(): void
    {
        $capped = self::pass(['SPA' => [2400, 5], 'SPA2' => [1100, 1], 'PLAIN' => [2000, 0]], self::market(1200.0));
        self::assertSame([977.9, 977.9, 'switch-order'], self::plain($capped), 'cap 990 (90% of 1100): 77% of 1270');

        $hooks = self::market();
        $hooks->switchFloors[self::KEY] = 1200.0;
        self::assertSame([1206.5, 1206.5, 'switch-order'], self::plain(self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]], $hooks)));
    }

    /**
     * Operation of 12/11: the switch holds and the target is already at its market price. The
     * engine writes no item and its validator then reports a false band error; here the stage
     * records an explicit decision "satisfied by the occupancy switch" for the validator.
     */
    public function testATargetAlreadyAtItsSwitchedPriceIsSatisfiedByTheSwitchWithoutAnItem(): void
    {
        $context = self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [1041.4, 0, 1041.4, 1041.4]], self::market());

        self::assertNull(self::plain($context));
        self::assertSame(['plain-switch' => 'switch_satisfied'], self::outcomes($context), 'the band does not run');
        self::assertSame('plain-switch', $context->report->satisfiedBySwitch('PLAIN', Days::date(self::N)));
        self::assertNull($context->report->satisfiedBySwitch('SPA', Days::date(self::N)));
    }

    public function testATargetNearItsSwitchedPriceWithoutACeilingInForceIsWritten(): void
    {
        $context = self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [1041.4, 0, 1041.4]], self::market());

        self::assertSame([1041.4, 1041.4, 'switch-order'], self::plain($context));
        self::assertNull($context->report->satisfiedBySwitch('PLAIN', Days::date(self::N)));
    }

    public function testAFrozenTargetHoldsTheNightWithoutWriting(): void
    {
        $hooks = self::market();
        $hooks->frozen[self::KEY] = true;
        $context = self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]], $hooks);

        self::assertNull(self::plain($context));
        self::assertSame(['plain-switch' => 'switch_held_by_owner'], self::outcomes($context));
    }

    public function testWithoutMarketDataTheSwitchWarnsAndTheBandRuns(): void
    {
        $context = self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]]);

        self::assertSame(['plain-switch' => 'no_market_data', 'plain-band' => 'applied'], self::outcomes($context));
        self::assertSame(RelationWarning::SWITCH_NO_MARKET_DATA, $context->report->warnings()[0]->code);
        self::assertSame([2070.1, 2070.1, 'band-order'], self::plain($context), 'the band over the free SPA2 at 2400: 63% of 1270');
    }

    public function testAHoldingSwitchKeepsTheTargetAndAUnitThresholdCountsUnits(): void
    {
        $hold = self::pass(['SPA' => [2400, 5], 'SPA2' => [2400, 1], 'PLAIN' => [2000, 0]], null, RelationsSample::lodgeRelations('hold'));
        self::assertNull(self::plain($hold));
        self::assertSame(['plain-switch' => 'switch_satisfied'], self::outcomes($hold));

        $byUnits = RelationsSample::relation('units', 'occupancy_switch', 'PLAIN', ['trigger' => ['set' => ['rooms' => ['SPA']], 'unit' => 'units', 'threshold' => 4], 'then' => ['kind' => 'hold']]);
        $context = self::pass(['SPA' => [2400, 4], 'PLAIN' => [2000, 0]], null, [$byUnits]);
        self::assertSame(['units' => 'switch_satisfied'], self::outcomes($context));
    }

    public function testATriggerRoomWithoutCalendarCountsItsUnitsButNoSale(): void
    {
        $context = self::pass(['SPA' => [2400, 5], 'PLAIN' => [2000, 0]], self::market());

        self::assertSame(['plain-switch' => 'not_triggered', 'plain-band' => 'empty_set'], self::outcomes($context), '5 of 7 is below 80%');
    }

    public function testDecisionsCarryTheRelationTypeAndTarget(): void
    {
        $decision = self::pass(['SPA' => [1000, 1], 'SPA2' => [1100, 0], 'PLAIN' => [700, 0]])->report->decisions()[1];

        self::assertInstanceOf(RelationDecision::class, $decision);
        self::assertSame(['discount_band', 'PLAIN', Outcome::Applied], [$decision->type->value, $decision->target, $decision->outcome]);
    }
}
