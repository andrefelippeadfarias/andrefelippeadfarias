<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Relations\Coherence\CoherenceContext;
use Ritmo\Domain\Relations\Coherence\CoherencePass;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\Outcome;
use Ritmo\Domain\Relations\Coherence\RelationWarning;
use Ritmo\Domain\Relations\Port\BetweenHints;
use Ritmo\Domain\Relations\Port\SentBand;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Days;

/**
 * The chain relations (at least, between, premium) in the coherence stage, on the "inn" of the
 * sample (bases LOW 1173, HIGH 1480, MID 1590, TOP 2000).
 */
final class CoherenceChainTest extends TestCase
{
    private const N = RelationsSample::NIGHT;

    /**
     * @param list<Relation>                       $relations
     * @param array<string, array<int, int|float>> $rows room => [price, sold, floor?, ceiling?] for the night
     * @param array<string, float>                 $decided
     */
    private static function pass(array $relations, array $rows, ?StubHooks $hooks = null, array $decided = [], array $strategy = []): CoherenceContext
    {
        $calendar = RelationsSample::calendar(array_map(static fn (array $row): array => [self::N => $row], $rows));
        $context = RelationsSample::context($relations, $calendar, $hooks ?? new StubHooks(), $decided, [], null, $strategy);
        (new CoherencePass($context))->run([Days::date(self::N)]);

        return $context;
    }

    private static function highOverLow(array $params = [], array $extra = []): Relation
    {
        return RelationsSample::relation('high-over-low', 'at_least', 'HIGH', $params + ['reference' => 'LOW'], $extra);
    }

    /**
     * @return array<string, array{float|null, float|null, string|null}>
     */
    private static function items(CoherenceContext $context): array
    {
        $items = [];
        foreach ($context->draft->items() as $item) {
            $items[$item->room] = [$item->floor(), $item->ceiling(), $item->orderRef()];
        }

        return $items;
    }

    public function testAtLeastRaisesTheFloorOnTheTargetsPercentGrid(): void
    {
        $context = self::pass([self::highOverLow([], ['order_ref' => 'order-1'])], ['LOW' => [1500, 1], 'HIGH' => [1400, 0]]);

        self::assertSame(['HIGH' => [1500.0, null, 'order-1']], self::items($context));
        self::assertSame(1509.6, $context->facts->decided('HIGH', Days::date(self::N)), 'the next relations see the effective floor (2% of 1480)');
        self::assertSame(Outcome::Applied, $context->report->outcomeOf('high-over-low', Days::date(self::N)));
    }

    public function testAGapOfExactlyTheToleranceDoesNothing(): void
    {
        $context = self::pass([self::highOverLow()], ['LOW' => [1401, 1], 'HIGH' => [1400, 0]]);

        self::assertSame([], $context->draft->items());
        self::assertSame(Outcome::Satisfied, $context->report->outcomeOf('high-over-low', Days::date(self::N)));
    }

    public function testTheLastUnitsOfTheReferenceDoNotPullTheTarget(): void
    {
        $context = self::pass([self::highOverLow(['reference_min_units_left' => 2])], ['LOW' => [1500, 3], 'HIGH' => [1400, 0]]);

        self::assertSame([], $context->draft->items());
        self::assertSame(Outcome::SkippedReferenceLastUnits, $context->report->outcomeOf('high-over-low', Days::date(self::N)));
    }

    public function testFrozenOwnerPricedAndSoldOutTargetsAreLeftAlone(): void
    {
        $frozen = new StubHooks();
        $frozen->frozen['HIGH|' . self::N] = true;
        $owner = new StubHooks();
        $owner->ownerPrice['HIGH|' . self::N] = true;

        $outcome = static fn (CoherenceContext $context): ?Outcome => $context->report->outcomeOf('high-over-low', Days::date(self::N));
        self::assertSame(Outcome::SkippedFrozen, $outcome(self::pass([self::highOverLow()], ['LOW' => [1500, 1], 'HIGH' => [1400, 0]], $frozen)));
        self::assertSame(Outcome::SkippedOwnerPrice, $outcome(self::pass([self::highOverLow()], ['LOW' => [1500, 1], 'HIGH' => [1400, 0]], $owner)));
        self::assertSame(Outcome::SkippedSoldOut, $outcome(self::pass([self::highOverLow()], ['LOW' => [1500, 1], 'HIGH' => [1400, 3]])));
    }

    public function testAnExistingItemOnlyRaisesItsFloorAndLiftsACeilingBelowIt(): void
    {
        $item = new DraftItem('HIGH', Days::date(self::N), 1400.0, 1350.0, 1350.0);
        $calendar = RelationsSample::calendar(['LOW' => [self::N => [1500, 1]], 'HIGH' => [self::N => [1400, 0]]]);
        $context = RelationsSample::context([self::highOverLow()], $calendar, new StubHooks(), ['HIGH|' . self::N => 1361.6], [$item]);
        (new CoherencePass($context))->run([Days::date(self::N)]);

        self::assertSame([1500.0, 1500.0], [$item->floor(), $item->ceiling()]);
    }

    public function testBetweenPinsTheTargetToTheOnlyFreeReference(): void
    {
        $between = RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75]);
        $context = self::pass([$between], ['LOW' => [1500, 1], 'HIGH' => [1400, 3], 'MID' => [1450, 0]]);

        self::assertSame(['MID' => [1500.0, 1500.0, null]], self::items($context));
    }

    public function testBetweenKeepsTheTargetsOwnFloorAboveTheBandAndWarns(): void
    {
        $hooks = new StubHooks();
        $hooks->hints['MID|' . self::N] = BetweenHints::ofFloor(1219.51);
        $between = RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75]);
        $context = self::pass([$between], ['LOW' => [900, 1], 'HIGH' => [960, 1], 'MID' => [1300, 0]], $hooks);

        self::assertSame(['MID' => [1220.0, 1220.0, null]], self::items($context));
        self::assertSame(RelationWarning::CEILING_RAISED_TO_FLOOR, $context->report->warnings()[0]->code);
    }

    public function testBetweenCitesTheOrderThatLoweredTheFloorWhenItDecidedTheLowerEdge(): void
    {
        $hooks = new StubHooks();
        $hooks->hints['MID|' . self::N] = new BetweenHints(904.06, 1219.51, 'market-order');
        $between = RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75], ['order_ref' => 'standing']);
        $context = self::pass([$between], ['LOW' => [910, 1], 'HIGH' => [955, 1], 'MID' => [1300, 0]], $hooks);

        self::assertSame(['MID' => [910.0, 943.0, 'market-order']], self::items($context));
        self::assertNull((new BetweenHints(904.06, 1219.51, 'market-order'))->forcedOrderRef(1300.0), 'a lower edge above the standard floor keeps the standing order');
    }

    public function testBetweenHintsCapAndHoldTheCeiling(): void
    {
        $between = RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75]);
        $capped = new StubHooks();
        $capped->hints['MID|' . self::N] = new BetweenHints(100.0, 100.0, null, 1320.0);
        $held = new StubHooks();
        $held->hints['MID|' . self::N] = new BetweenHints(100.0, 100.0, null, null, true);
        $rows = ['LOW' => [1300, 1], 'HIGH' => [1400, 1], 'MID' => [1700, 0]];

        self::assertSame(['MID' => [1300.0, 1320.0, null]], self::items(self::pass([$between], $rows, $capped)));
        self::assertSame(['MID' => [1300.0, 1400.0, null]], self::items(self::pass([$between], $rows, $held)), 'held at the current price, capped at the dearer reference');
    }

    public function testBetweenPercentOfOneReference(): void
    {
        $between = RelationsSample::relation('mid', 'between', 'MID', ['mode' => 'percent_of', 'reference' => 'LOW', 'min_percent' => 90, 'max_percent' => 110]);
        $context = self::pass([$between], ['LOW' => [1000, 1], 'MID' => [1200, 0]]);

        self::assertSame(['MID' => [900.0, 1100.0, null]], self::items($context));
        self::assertSame(906.3, $context->facts->decided('MID', Days::date(self::N)));
    }

    public function testPremiumUsesThePercentOfTheNightTypeAndLiftsTheBandCeilingToTheFloor(): void
    {
        $hooks = new StubHooks();
        $hooks->bands['TOP|' . self::N] = new SentBand(1600.0, 1650.0);
        $hooks->bandLastNight = '2027-12-31';
        $premium = RelationsSample::relation('top', 'premium_over_max', 'TOP', ['set' => ['room_tags' => ['tub']], 'premium_percent' => ['weekday' => 20, 'weekend' => 5], 'epsilon_amount' => 0.5]);
        $context = self::pass([$premium], ['LOW' => [1300, 1], 'HIGH' => [1400, 1], 'MID' => [1350, 0], 'TOP' => [1500, 0]], $hooks);

        self::assertSame(['TOP' => [1681.0, 1681.0, null]], self::items($context), '1400 x 1.2 + 0.5 = 1680.5, above the band ceiling 1650');
        self::assertSame(RelationWarning::FLOOR_OVER_BAND_CEILING, $context->report->warnings()[0]->code);
    }

    public function testPremiumWithoutAFreeMemberHasNothingToCompare(): void
    {
        $premium = RelationsSample::relation('top', 'premium_over_max', 'TOP', ['set' => ['rooms' => ['HIGH']], 'premium_percent' => ['weekday' => 20]]);
        $context = self::pass([$premium], ['HIGH' => [1400, 3], 'TOP' => [1500, 0]]);

        self::assertSame(Outcome::EmptySet, $context->report->outcomeOf('top', Days::date(self::N)));
    }

    public function testTouchedRelationsRunOnlyOnNightsTheEngineDecidedAndLadderNightsCanBeSkipped(): void
    {
        $touched = self::highOverLow([], ['scan' => 'touched', 'strategy_nights' => 'skip']);
        $rows = ['LOW' => [1500, 1], 'HIGH' => [1400, 0]];

        self::assertSame([], self::pass([$touched], $rows)->report->decisions(), 'not touched: not run');
        self::assertCount(1, self::pass([$touched], $rows, null, ['LOW|' . self::N => 1500.0])->report->decisions());
        self::assertSame([], self::pass([$touched], $rows, null, ['LOW|' . self::N => 1500.0], ['LOW|' . self::N => 1500.0])->report->decisions(), 'ladder night: skipped');
    }

    public function testARelationThatNeedsAnOrderIsOffWithoutIt(): void
    {
        $relation = self::highOverLow([], ['scope' => ['requires_order' => 'plan-order']]);
        $hooks = new StubHooks();

        self::assertSame(Outcome::NotAuthorised, self::pass([$relation], ['LOW' => [1500, 1], 'HIGH' => [1400, 0]], $hooks)->report->outcomeOf('high-over-low', Days::date(self::N)));
        $hooks->activeOrders['HIGH|' . self::N] = ['plan-order'];
        self::assertSame(Outcome::Applied, self::pass([$relation], ['LOW' => [1500, 1], 'HIGH' => [1400, 0]], $hooks)->report->outcomeOf('high-over-low', Days::date(self::N)));
    }

    public function testBlockedReferencesFollowTheBlockedNightsSetting(): void
    {
        $between = RelationsSample::relation('mid', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_max' => 0.75]);
        $calendar = RelationsSample::calendar(['LOW' => [self::N => [1300, 1]], 'HIGH' => [self::N => [1400, 1]], 'MID' => [self::N => [1700, 0]]]);
        $context = RelationsSample::context([$between], $calendar, new StubHooks(), [], [], null, [], ['LOW|' . self::N]);
        (new CoherencePass($context))->run([Days::date(self::N)]);

        self::assertSame(['MID' => [1400.0, 1400.0, null]], self::items($context), 'exclude (neutral): the blocked LOW is no reference');
    }
}
