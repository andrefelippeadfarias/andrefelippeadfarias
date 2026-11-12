<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Relations\Coherence\Outcome;
use Ritmo\Domain\Relations\Coherence\RelationDecision;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\Validate\BandIssue;
use Ritmo\Domain\Relations\Validate\DiscountBandCheck;
use Ritmo\Domain\Relations\Validate\FreshNight;
use Ritmo\Domain\Relations\Validate\PlanNightValues;
use Ritmo\Domain\Support\Days;

/**
 * The validator rule of the band of PLAIN (90% to 85.5% of the cheapest free tub room).
 */
final class DiscountBandCheckTest extends TestCase
{
    private const N = RelationsSample::NIGHT;

    /**
     * @param array<string, float> $planned room => planned value for the night
     * @param array<string, array{float, int, int}> $fresh room => [price, free units, sold]
     *
     * @return list<string> "code error|warning"
     */
    private static function issues(array $planned, array $fresh, array $extra = [], ?RelationReport $report = null, ?StubHooks $hooks = null): array
    {
        $units = ['SPA' => 5, 'SPA2' => 2, 'PLAIN' => 4];
        $keyed = static fn (array $values): array => array_combine(array_map(static fn (string $room): string => $room . '|' . self::N, array_keys($values)), array_values($values));
        $freshNights = array_map(static fn (string $room, array $row): FreshNight => new FreshNight($row[0], $row[1], $row[2], $units[$room]), array_keys($fresh), $fresh);
        $values = new PlanNightValues(
            $keyed($planned),
            $keyed($extra['ceilings'] ?? []),
            array_map(static fn (string $room): string => $room . '|' . self::N, $extra['percent_floors'] ?? []),
            $keyed($extra['order_refs'] ?? []),
            array_combine(array_map(static fn (string $room): string => $room . '|' . self::N, array_keys($fresh)), $freshNights),
        );
        $hooks ??= new StubHooks();
        $check = new DiscountBandCheck(new RelationGraph(RelationsSample::lodgeRelations(), RelationsSample::rooms()), new RelationSettings(), $hooks->bundle()->calendar, $hooks);

        return array_map(static fn (BandIssue $issue): string => $issue->code . ' ' . ($issue->isError ? 'error' : 'warning'), $check->check($values, $report));
    }

    /**
     * @return array<string, array{float, int, int}>
     */
    private static function calendar(float $plain = 880.0, int $spaSold = 1, int $spa2Sold = 0): array
    {
        return ['SPA' => [1000.0, 5 - $spaSold, $spaSold], 'SPA2' => [1100.0, 2 - $spa2Sold, $spa2Sold], 'PLAIN' => [$plain, 4, 0]];
    }

    public function testInsideTheBandThereIsNothingToSay(): void
    {
        self::assertSame([], self::issues(['PLAIN' => 889.0], self::calendar()));
        self::assertSame([], self::issues(['PLAIN' => 855.0], self::calendar()));
    }

    public function testAboveTheReferenceIsAlwaysAnErrorAndSoIsACeilingAboveIt(): void
    {
        self::assertSame(['band.above_reference error'], self::issues(['PLAIN' => 1001.01], self::calendar()));
        $hooks = new StubHooks();
        $hooks->releases['PLAIN|' . self::N] = 'release';
        self::assertSame(['band.above_reference error'], self::issues(['PLAIN' => 1100.0], self::calendar(), [], null, $hooks), 'no order releases it');
        self::assertSame(['band.ceiling_above_reference error'], self::issues(['PLAIN' => 889.0], self::calendar(), ['ceilings' => ['PLAIN' => 1001.5]]));
        self::assertSame(['band.above_upper warning'], self::issues(['PLAIN' => 913.71], self::calendar()));
    }

    public function testBelowTheLowerEdgeIsAnErrorUnlessSomethingExplainsIt(): void
    {
        self::assertSame(['band.below_lower error'], self::issues(['PLAIN' => 854.99], self::calendar()));
        $hooks = new StubHooks();
        $hooks->releases['PLAIN|' . self::N] = 'release';
        self::assertSame(['band.below_by_order warning'], self::issues(['PLAIN' => 700.0], self::calendar(), [], null, $hooks));
        self::assertSame(['band.below_confirm_after_recalc warning'], self::issues(['PLAIN' => 850.9], self::calendar(), ['percent_floors' => ['PLAIN']]));
        self::assertSame(['band.below_lower error'], self::issues(['PLAIN' => 850.9, 'SPA' => 1000.0], self::calendar(), ['percent_floors' => ['PLAIN', 'SPA']]), 'against a planned reference it is an error');
    }

    public function testTheSwitchExplainsANightBelowTheBandOnlyWithItsTriggerProven(): void
    {
        $order = ['order_refs' => ['PLAIN' => 'switch-order']];

        self::assertSame(['band.below_by_switch warning'], self::issues(['PLAIN' => 700.0], self::calendar(880.0, 5, 1), $order));
        self::assertSame(['band.below_lower error'], self::issues(['PLAIN' => 700.0], self::calendar(880.0, 4, 1), $order), '5 of 7 sold: no proof');
        self::assertSame(['band.below_lower error'], self::issues(['PLAIN' => 700.0], self::calendar(880.0, 5, 1), ['order_refs' => ['PLAIN' => 'band-order']]));
    }

    /**
     * 12/11: a member has an item, the target is already at its market price without an item. The
     * coherence decision "satisfied by the occupancy switch" makes the night acceptable.
     */
    public function testTheCoherenceDecisionSatisfiedByTheSwitchIsAcceptedWithoutAnItem(): void
    {
        $fresh = self::calendar(1041.4, 5, 1);
        $report = new RelationReport();
        $report->record(new RelationDecision('plain-switch', RelationType::OccupancySwitch, 'PLAIN', Days::date(self::N), Outcome::SwitchSatisfied));

        self::assertSame(['band.below_lower error'], self::issues(['SPA2' => 1909.2], $fresh), 'without the decision: what the reference engine reports');
        self::assertSame(['band.below_by_switch warning'], self::issues(['SPA2' => 1909.2], $fresh, [], $report));
        self::assertSame(['band.below_lower error'], self::issues(['SPA2' => 1909.2], self::calendar(1041.4, 5, 0), [], $report), 'the trigger must still be proven (5 of 7)');
    }

    public function testSoldOutTargetsOrReferencesAndHolidaysBelowTheBandAreSkipped(): void
    {
        self::assertSame([], self::issues(['PLAIN' => 1500.0], ['SPA' => [1000.0, 0, 5], 'SPA2' => [1100.0, 0, 2], 'PLAIN' => [880.0, 4, 0]]));
        self::assertSame([], self::issues(['PLAIN' => 1500.0], ['SPA' => [1000.0, 4, 1], 'SPA2' => [1100.0, 2, 0], 'PLAIN' => [880.0, 0, 4]]));
    }
}
