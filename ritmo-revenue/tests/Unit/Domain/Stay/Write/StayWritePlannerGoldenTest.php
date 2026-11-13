<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Stay\Write;

use Ritmo\Tests\Support\ParityNames;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Stay\Write\StayItemFactory;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Stay\Write\StayWritePlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Unit\Domain\Calendar\Stay\StayParity;

/**
 * The planner over the M2 stay scenarios: the PMS changes are the single batch of the reference
 * (calendario_12m.lote_unico, full; with divergences D1 and D2 of STAY.md), every change the
 * pricing system pushes is paired with a plan item setting the same value or held by the pricing
 * calendar (invariant 11), and the pricing stay items are those of calendario_12m.comparar, except
 * the nights a PMS change of the full batch is paired with (its value is folded into the night's
 * item, SW-D2).
 */
final class StayWritePlannerGoldenTest extends TestCase
{
    public function testThePmsChangesAreTheReferenceBatchAndEveryPushedOneIsCovered(): void
    {
        $paired = $held = 0;
        foreach (self::fullCases() as $case) {
            $scenario = StayParity::scenario($case['input']['scenario']);
            $plan = StayWriteParity::planner()->plan(StayWriteParity::inputs($scenario));
            self::assertSame(self::expected($case, $scenario), array_map(self::rowOf(...), $plan->pms), $case['name']);
            $items = [];
            foreach ($plan->items as $item) {
                self::assertArrayNotHasKey($item->key(), $items, 'one item per room night');
                $items[$item->key()] = $item;
            }
            foreach ($plan->pms as $entry) {
                self::assertArrayHasKey($entry->identity(), $plan->ruleTargets);
                if (!$entry->alsoPricing) {
                    continue;
                }
                if ($entry->pricingHolds) {
                    ++$held;
                    continue;
                }
                self::assertNotNull($entry->pairedItem, $case['name'] . ' ' . $entry->identity());
                self::assertItemSets($items[$entry->pairedItem], $entry);
                ++$paired;
            }
        }
        self::assertGreaterThan(0, $paired);
        self::assertGreaterThan(0, $held, 'the scenarios must hold a change the pricing calendar already has (SW-X2)');
    }

    public function testThePricingStayItemsAreThoseOfTheStayOnlyPlan(): void
    {
        $compared = 0;
        foreach (Fixtures::json(StayParity::GOLDEN . 'calendario_12m.comparar.json')['cases'] as $case) {
            $scenario = StayParity::scenario($case['name']);
            $planned = [];
            foreach ($case['expected'] as $entry) {
                if ($entry['in_plan'] && Days::date($entry['night']) >= Days::date($scenario['audit_from'])) {
                    $planned[$entry['room'] . '|' . $entry['night']] = $entry;
                }
            }
            $config = new StayWriteConfig(true, 366, 2000, 2, false);
            $plan = StayWriteParity::planner($config)->plan(StayWriteParity::inputs($scenario));
            $paired = array_flip(array_filter(array_map(static fn (PmsChangeEntry $e): ?string => $e->pairedItem, $plan->pms)));
            foreach ($plan->items as $item) {
                $key = $item->roomCode . '|' . Days::key($item->date);
                if ($item->reasonCode === StayItemFactory::PAIR_REASON || !isset($planned[$key]) || isset($paired[$item->key()])) {
                    continue;
                }
                self::assertSame($planned[$key]['min_stay'], $item->minStay, $case['name'] . " $key");
                self::assertSame($planned[$key]['checkin_mask'], $item->checkInMask, $case['name'] . " $key");
                self::assertSame($planned[$key]['checkout_mask'], $item->checkOutMask, $case['name'] . " $key");
                if ($planned[$key]['kind'] !== null) {
                    self::assertSame('STAY_' . strtoupper($planned[$key]['kind']), $item->reasonCode, $case['name'] . " $key");
                }
                ++$compared;
            }
        }
        self::assertGreaterThan(50, $compared);
    }

    public function testChangesBeyondThePricingHorizonCannotBePaired(): void
    {
        $scenario = StayParity::scenario('midweek');
        $edge = Days::add(Days::date($scenario['today']), 7);
        $plan = StayWriteParity::planner()->plan(StayWriteParity::inputs($scenario, $edge));
        $beyond = array_filter($plan->pms, static fn (PmsChangeEntry $e): bool => $e->alsoPricing && $e->night > $edge && !$e->pricingHolds);
        self::assertNotSame([], $beyond);
        foreach ($beyond as $entry) {
            self::assertNull($entry->pairedItem);
        }
        foreach ($plan->items as $item) {
            self::assertLessThanOrEqual($edge, $item->date, 'no stay item beyond the pricing horizon');
        }
    }

    public function testAFrozenNightGetsNoChange(): void
    {
        $scenario = StayParity::scenario('midweek');
        $first = StayWriteParity::planner()->plan(StayWriteParity::inputs($scenario))->pms[0];
        $key = $first->roomCode . '|' . Days::key($first->night);
        $plan = StayWriteParity::planner()->plan(StayWriteParity::inputs($scenario, null, [$key => true]));
        foreach ($plan->pms as $entry) {
            self::assertNotSame($key, $entry->roomCode . '|' . Days::key($entry->night));
        }
        self::assertContains($first->identity(), array_map(static fn (PmsChangeEntry $e): string => $e->identity(), $plan->frozenOut));
        foreach ($plan->items as $item) {
            self::assertFalse($item->reasonCode !== '' && $item->roomCode . '|' . Days::key($item->date) === $key);
        }
    }

    public function testOffMeansTheAuditOnlyAlerts(): void
    {
        $scenario = StayParity::scenario('midweek');
        $item = new PlanItem(ParityNames::room('low'), Days::date($scenario['today']), 500.0);
        $plan = StayWriteParity::planner(new StayWriteConfig())->plan(StayWriteParity::inputs($scenario, null, [], [$item]));
        self::assertEquals(StayWritePlan::off([$item]), $plan);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fullCases(): array
    {
        return array_values(array_filter(
            Fixtures::json(StayParity::GOLDEN . 'calendario_12m.lote_unico.json')['cases'],
            static fn (array $c): bool => $c['input']['stay_only'] === false
        ));
    }

    /**
     * The reference's batch with divergences D1 (a blackout is never changed) and D2 (a package
     * stay carries the minimum stay read), as in StayGoldenTest.
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $scenario
     *
     * @return list<array<string, mixed>>
     */
    private static function expected(array $case, array $scenario): array
    {
        $inventory = StayParity::inventory($scenario);
        $out = [];
        foreach ($case['expected'] as $item) {
            if ($item['restriction'] !== null && $inventory[$item['room']][$item['from']]->restriction->value === 'blackout') {
                continue;
            }
            if ($item['reason'] === 'package' && $item['min_stay'] !== null) {
                $item['min_stay_before'] = $inventory[$item['room']][$item['from']]->minStay ?? 1;
            }
            $out[] = ['room' => $item['room'], 'night' => $item['from'], 'restriction' => $item['restriction'], 'min_stay' => $item['min_stay'], 'min_stay_before' => $item['min_stay_before'], 'max_stay' => $item['max_stay'], 'also_pricing' => $item['also_pricing'], 'reason' => $item['reason'], 'proof' => $item['proof']];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowOf(PmsChangeEntry $e): array
    {
        $isStay = $e->restriction() === null;

        return [
            'room' => $e->roomCode, 'night' => Days::key($e->night), 'restriction' => $e->restriction()?->value,
            'min_stay' => $e->minStay(), 'min_stay_before' => $isStay ? $e->before->get(ChangeField::PMS_MIN_STAY) : null,
            'max_stay' => $e->maxStay(), 'also_pricing' => $e->alsoPricing, 'reason' => $e->reason, 'proof' => $e->proof?->toArray(),
        ];
    }

    private static function assertItemSets(PlanItem $item, PmsChangeEntry $entry): void
    {
        if ($entry->minStay() !== null) {
            self::assertSame($entry->minStay(), $item->minStay, $entry->identity());
        }
        if ($entry->restriction() !== null) {
            self::assertTrue($item->checkInMask !== null || $item->checkOutMask !== null, $entry->identity());
        }
    }
}
