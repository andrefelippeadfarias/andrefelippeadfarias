<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\OrphanKind;
use Ritmo\Domain\Calendar\Stay\PricingStayEntry;
use Ritmo\Domain\Calendar\Stay\PricingStayPlan;
use Ritmo\Domain\Calendar\Stay\RestrictedNight;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Calendar\Stay\StayFinding;
use Ritmo\Domain\Calendar\Stay\StayHook;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\ParityNames;

/**
 * Parity of the stay rules with the reference Python engine: every golden file of
 * tests/fixtures/golden/m2/stay, computed in PHP from the parity stay rules, the parity preset and
 * the frozen scenarios, must give the engine's result exactly.
 *
 * Deliberate divergences (docs/STAY.md, section 6), applied to the expected values here and
 * nowhere else:
 *  - D1: a blackout night never gets an arrival/departure change (the engine overwrites a closed
 *    day's blackout with the closure, which reopens a night someone blocked);
 *  - D2: a package stay change carries the minimum stay read (minStayBefore), like every other
 *    stay change, so the writer can skip a night changed since the read;
 *  - D3: "no maximum stay" in the PMS is null in the plugin; the engine reads it as 365.
 */
final class StayGoldenTest extends TestCase
{
    private const FILES = [
        'validar_plano.estadia_exigida.json', 'validar_plano.estadia_planejada.json', 'comum.orfa_permitida.json',
        'comum.sexta_reaberta.json', 'calendario_12m.sabado_com_sexta_passada.json',
        'calendario_12m.estadia_da_noite.json', 'calendario_12m.prova_sabado_orfao.json',
        'calendario_12m.classificar_beds24.json', 'calendario_12m.comparar.json', 'calendario_12m.lote_unico.json',
    ];


    public function testEveryGoldenFileIsCoveredAndTheInputsAreTheFrozenOnes(): void
    {
        $manifest = Fixtures::json(StayParity::GOLDEN . 'MANIFEST.json');
        $onDisk = array_map('basename', glob(Fixtures::path(StayParity::GOLDEN . '*.*.json')) ?: []);
        self::assertEqualsCanonicalizing(self::FILES, $onDisk, 'every golden file of the stay rules needs a test here');
        self::assertEqualsCanonicalizing(self::FILES, array_column($manifest['files'], 'file'));
        self::assertFalse($manifest['engine_dirty'], 'the goldens came from uncommitted engine code: commit it and run golden_stay.py again');
        foreach ($manifest['frozen_inputs'] as $path => $sha256) {
            self::assertSame($sha256, hash_file('sha256', Fixtures::path($path)), "$path changed without regenerating the goldens");
        }
        self::assertSame($manifest['parity_fixture']['sha256'], hash_file('sha256', Fixtures::path($manifest['parity_fixture']['file'])));
        foreach ($manifest['files'] as $file) {
            self::assertCount($file['cases'], self::cases($file['file']), $file['file']);
        }
    }

    public function testRequiredStay(): void
    {
        $rules = StayParity::rules();
        foreach (self::cases('validar_plano.estadia_exigida.json') as $case) {
            self::assertSame($case['expected'], $rules->requiredStay(ParityNames::room('low'), Days::date($case['input']['night'])), $case['name']);
        }
    }

    public function testPlannedStay(): void
    {
        $rules = StayParity::rules();
        foreach (self::cases('validar_plano.estadia_planejada.json') as $case) {
            $in = $case['input'];
            $planned = $rules->plannedStay($in['room'], Days::date($in['night']), $in['current'], $in['free'], $in['next_sold_out']);
            self::assertSame($case['expected'], ['min_stay' => $planned->minStay, 'orphan' => $planned->orphan], $case['name']);
        }
    }

    public function testOrphanAllowed(): void
    {
        $rules = StayParity::rules();
        foreach (self::cases('comum.orfa_permitida.json') as $case) {
            $in = $case['input'];
            self::assertSame($case['expected'], $rules->orphanAllowed(ParityNames::room('low'), Days::date($in['night']), $in['closed_day_side']), $case['name']);
        }
    }

    public function testOpenDay(): void
    {
        $rules = StayParity::rules();
        foreach (self::cases('comum.sexta_reaberta.json') as $case) {
            self::assertSame($case['expected'], $rules->openDayApplies($case['input']['room'], Days::date($case['input']['night'])), $case['name']);
        }
    }

    public function testClosedDayWithPreviousPast(): void
    {
        $rules = StayParity::rules();
        foreach (self::cases('calendario_12m.sabado_com_sexta_passada.json') as $case) {
            $today = $case['input']['today'] === null ? null : Days::date($case['input']['today']);
            self::assertSame($case['expected'], $rules->closedDayWithPreviousPast(ParityNames::room('low'), Days::date($case['input']['night']), $today), $case['name']);
        }
    }

    public function testTheStayOfARewrittenDateRule(): void
    {
        $hook = new StayHook(StayParity::rules());
        foreach (self::cases('calendario_12m.estadia_da_noite.json') as $case) {
            $in = $case['input'];
            [$planned, $proof] = $hook->nightStay($in['room'], Days::date($in['night']), $in['current'], self::hookOccupancy($in));
            self::assertSame($case['expected'], ['min_stay' => $planned->minStay, 'orphan' => $planned->orphan, 'proof' => $proof?->toArray()], $case['name']);
        }
    }

    public function testTheProofOfAClosedDaysFreeSide(): void
    {
        $hook = new StayHook(StayParity::rules());
        foreach (self::cases('calendario_12m.prova_sabado_orfao.json') as $case) {
            $in = $case['input'];
            $proof = $hook->closedDayOrphanProof($in['room'], Days::date($in['night']), self::hookOccupancy($in), Days::date($in['today']));
            self::assertSame($case['expected'], $proof?->toArray(), $case['name']);
        }
    }

    public function testInventoryAudit(): void
    {
        $dropped = 0;
        foreach (self::cases('calendario_12m.classificar_beds24.json') as $case) {
            $scenario = StayParity::scenario($case['name']);
            $expected = array_map(fn (array $room): array => $this->withoutBlackoutChanges($room), $case['expected']);
            $dropped += count(array_merge(...array_column($case['expected'], 'arrival_changes'))) - count(array_merge(...array_column($expected, 'arrival_changes')));

            self::assertSame($expected, array_map($this->auditOf(...), self::runAudit($scenario)), $case['name']);
        }
        self::assertGreaterThan(0, $dropped, 'the scenarios must hold a blackout closed day (divergence D1)');
    }

    public function testPricingStayPlan(): void
    {
        $plan = new PricingStayPlan(StayParity::rules());
        foreach (self::cases('calendario_12m.comparar.json') as $case) {
            $scenario = StayParity::scenario($case['name']);
            $entries = [];
            foreach (Fixtures::parityPreset()->roomTypes as $room) {
                $entries = [...$entries, ...$plan->plan(
                    $room->code,
                    StayParity::calendar($scenario, $room->code),
                    StayParity::occupancy($scenario, $room->code),
                    Days::date($scenario['plan_from']),
                    Days::date($scenario['last']),
                    Days::date($scenario['today']),
                    $scenario['effective'][$room->code] ?? null,
                    StayParity::dateRules($scenario, $room->code),
                )];
            }

            self::assertSame($case['expected'], array_map(self::entryOf(...), $entries), $case['name']);
        }
    }

    public function testSingleBatch(): void
    {
        $batch = new StayBatch(StayParity::config()->batch, StayParity::propertyOfRoom());
        $packageReads = 0;
        foreach (self::cases('calendario_12m.lote_unico.json') as $case) {
            $scenario = StayParity::scenario($case['input']['scenario']);
            $inventory = StayParity::inventory($scenario);
            $expected = [];
            foreach ($case['expected'] as $item) {
                if ($item['restriction'] !== null && $inventory[$item['room']][$item['from']]->restriction->value === 'blackout') {
                    continue; // D1
                }
                if ($item['reason'] === 'package' && $item['min_stay'] !== null) {
                    $item['min_stay_before'] = $inventory[$item['room']][$item['from']]->minStay ?? 1; // D2
                    ++$packageReads;
                }
                $expected[] = $item;
            }
            $changes = $batch->single(self::runAudit($scenario), Days::date($scenario['today']), $case['input']['stay_only']);

            self::assertSame($expected, array_map(self::batchItemOf(...), $changes), $case['name']);
        }
        self::assertGreaterThan(0, $packageReads, 'the scenarios must hold a package stay change (divergence D2)');
    }

    public function testTheScenariosHoldAPackageMaximumWithoutAMaximumRead(): void
    {
        $found = 0;
        foreach (self::runAudit(StayParity::scenario('midweek')) as $room) {
            foreach ($room->packageStays as $change) {
                $found += (int) ($change->maxStay !== null && $change->maxStayBefore === null);
            }
        }
        self::assertGreaterThan(0, $found, 'divergence D3 must be exercised');
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return list<RoomAudit>
     */
    private static function runAudit(array $scenario): array
    {
        return StayParity::audit()->audit(
            StayParity::inventory($scenario),
            Days::date($scenario['audit_from']),
            Days::date($scenario['last']),
            Days::date($scenario['today']),
            StayParity::readAt($scenario),
        );
    }

    /**
     * The occupancy of a hook case: the PMS inventory or the pricing calendar of its scenario.
     *
     * @param array<string, mixed> $input
     */
    private static function hookOccupancy(array $input): Occupancy
    {
        $scenario = StayParity::scenario($input['scenario']);
        $units = Fixtures::parityPreset()->roomType($input['room'])?->units ?? 1;

        return $input['source'] === 'pms'
            ? Occupancy::fromInventory(StayParity::inventory($scenario)[$input['room']], $units, StayParity::readAt($scenario))
            : Occupancy::fromPricing(StayParity::calendar($scenario, $input['room']), $units);
    }

    /**
     * @param array<string, mixed> $room
     *
     * @return array<string, mixed>
     */
    private function withoutBlackoutChanges(array $room): array
    {
        $room['arrival_changes'] = array_values(array_filter($room['arrival_changes'], static fn (array $c): bool => $c['before'] !== 'blackout'));

        return $room;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOf(RoomAudit $r): array
    {
        $maxRead = static fn (StayChange $c): ?int => $c->maxStay === null ? null : ($c->maxStayBefore ?? 365); // D3

        return [
            'room' => $r->roomCode,
            'arrival_changes' => array_map(static fn (ArrivalDepartureChange $c): array => [
                'night' => Days::key($c->night), 'before' => $c->before?->value, 'after' => $c->after?->value,
                'reason' => $c->reason->value, 'also_pricing' => $c->alsoPricing, 'proof' => $c->proof?->toArray(),
            ], $r->arrivalChanges),
            'closed_day_stay_below' => array_map(Days::key(...), $r->closedDayStayBelow),
            'other_restricted' => array_map(static fn (RestrictedNight $n): array => ['night' => Days::key($n->night), 'restriction' => $n->restriction->value], $r->otherRestricted),
            'orphan_stays' => array_map(static fn (StayChange $c): array => [
                'night' => Days::key($c->night), 'min_stay' => $c->minStayBefore, 'target' => $c->minStay,
                'side' => $c->orphan === OrphanKind::NextNight ? 'previous' : 'closed_day', 'proof' => $c->proof?->toArray(),
            ], $r->orphanStays),
            'package_stays' => array_map(static fn (StayChange $c): array => [
                'night' => Days::key($c->night), 'min_stay' => $c->minStayBefore, 'min_target' => $c->minStay,
                'max_stay' => $maxRead($c), 'max_target' => $c->maxStay,
            ], $r->packageStays),
            'arrival_stays' => array_map(static fn (StayChange $c): array => [
                'night' => Days::key($c->night), 'min_stay' => $c->minStayBefore, 'target' => $c->minStay,
                'orphan' => $c->orphan !== null, 'holiday' => $c->holiday,
            ], $r->arrivalStays),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entryOf(PricingStayEntry $e): array
    {
        return [
            'room' => $e->roomCode, 'night' => Days::key($e->night),
            'issues' => array_map(static fn (StayFinding $f): string => $f->value, $e->issues()),
            'accepted' => array_map(static fn (StayFinding $f): string => $f->value, $e->accepted()),
            'stay_before' => $e->stayBefore, 'holiday' => $e->holiday,
            'min_stay' => $e->stay?->minStay, 'kind' => $e->stay?->reason->value,
            'season' => $e->stay?->season, 'checkin_mask' => $e->masks?->checkinMask, 'checkout_mask' => $e->masks?->checkoutMask,
            'keep_reason' => ($e->stay ?? $e->masks)?->keepReason ?? false, 'proof' => $e->stay?->proof?->toArray(),
            'orphan' => $e->stay?->orphan?->value, 'in_plan' => $e->hasItem(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function batchItemOf(StayChange|ArrivalDepartureChange $c): array
    {
        $isStay = $c instanceof StayChange;

        return [
            'room' => $c->roomCode, 'from' => Days::key($c->night), 'to' => Days::key($c->night),
            'restriction' => $isStay ? null : $c->after?->value,
            'min_stay' => $isStay ? $c->minStay : null, 'min_stay_before' => $isStay ? $c->minStayBefore : null,
            'max_stay' => $isStay ? $c->maxStay : null, 'also_pricing' => $c->alsoPricing,
            'reason' => $c->reason->value, 'proof' => $c->proof?->toArray(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cases(string $file): array
    {
        return Fixtures::json(StayParity::GOLDEN . $file)['cases'];
    }
}
