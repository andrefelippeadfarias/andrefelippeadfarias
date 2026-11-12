<?php

declare(strict_types=1);

namespace Ritmo\Tests\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Plan\Assembly\AssembledPlan;
use Ritmo\Domain\Plan\Assembly\PlanAssembler;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\Payload\CallKind;
use Ritmo\Domain\Plan\Payload\Slice;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Support\Fixtures;

/**
 * Level-2 parity: whole runs of the reference engine (pace.py plano with its delete-first plan and
 * no-effect filter, then the validator, the payload and the slices) against PlanAssembler over the
 * same frozen inputs. The reference prices both properties in one pass; the plugin assembles one
 * property at a time, so every comparison is made per property, over that property's rooms.
 *
 * Deliberate differences (docs/ENGINE.md, section "Plan assembly"):
 * - L1: a light run of the plugin reaches the relations of a property on the nights of its own
 *   filter and items; the reference also looks at nights touched in the other property.
 * - V4 and V5 of docs/VALIDATOR.md are in the expected codes of the golden ("php_expected"): the
 *   plugin writes the rest of a plan whose items it refused, after validating it again.
 * - The reason text of a written date rule is the plugin's own (code and parameters); the
 *   reference's Portuguese text is not part of the contract, so payloads are compared without it.
 */
final class Level2Test extends TestCase
{
    private const MIN_SCENARIOS = 10;

    private const TOOLS = ['delete' => 'delete_listing_date_overrides', 'write' => 'update_listing_date_overrides', 'listing' => 'update_listing_data'];

    /** @var array<string, AssembledPlan> */
    private static array $runs = [];

    public function testTheScenariosAreCoveredAndTheInputsAreTheFrozenOnes(): void
    {
        $manifest = Fixtures::json('golden/level2/MANIFEST.json');
        self::assertFalse($manifest['engine_dirty'], 'the goldens must come from committed engine code');
        foreach ($manifest['frozen_inputs'] as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', Fixtures::path($path)), "$path changed without regenerating the goldens");
        }
        $names = array_column(Level2Scenario::cases(), 'name');
        self::assertGreaterThanOrEqual(self::MIN_SCENARIOS, count($names));
        self::assertSame(array_column($manifest['scenarios'], 'name'), $names);
        foreach (array_keys(self::divergences()['other_property_light_run']) as $scenario) {
            self::assertContains($scenario, $names);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testThePlanIsTheReferencesPlan(array $case): void
    {
        $scenario = new Level2Scenario($case);
        foreach (Fixtures::parityPreset()->properties as $property) {
            $run = self::assembled($scenario, $property);
            $got = [];
            foreach ($run->plan->items as $item) {
                $got[$item->key()] = $item;
            }
            $expected = [];
            foreach ($scenario->expected('items', $property) as $it) {
                $key = $it['quarto'] . '|' . $it['data'];
                if (in_array($key, self::divergences()['other_property_light_run'][$case['name']] ?? [], true)) {
                    self::assertArrayNotHasKey($key, $got, "L1: $key comes from the other property's nights");
                    continue;
                }
                $expected[$key] = $it;
            }
            self::assertSame(array_keys($expected), array_keys($got), "{$case['name']} {$property->id}: the room nights of the plan, in order");
            foreach ($expected as $key => $it) {
                self::assertItem($it, $got[$key], "{$case['name']} $key");
            }
            $deletions = array_map(static fn (array $it): string => $it['quarto'] . '|' . $it['data'], $scenario->expected('deletions', $property));
            self::assertSame($deletions, array_map(static fn ($d): string => $d->key(), $run->plan->deletions), "{$case['name']} {$property->id}: delete first");
            $codes = array_map(static fn ($r): string => $r->code, $scenario->rooms($property));
            $noEffect = array_values(array_filter($case['expected']['no_effect'], static fn (array $x): bool => in_array($x['quarto'], $codes, true)));
            self::assertSame(array_map(static fn (array $x): string => $x['quarto'] . '|' . $x['data'], $noEffect), array_map(static fn (PlanItem $i): string => $i->key(), [...$run->noEffect, ...$run->roundingOnly]));
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testTheValidatorReportsWhatThePluginExpects(array $case): void
    {
        $scenario = new Level2Scenario($case);
        foreach (Fixtures::parityPreset()->properties as $property) {
            $run = self::assembled($scenario, $property);
            $codes = array_map(static fn ($r): string => $r->code, $scenario->rooms($property));
            self::assertSame(self::expectedIssues($case, $codes), self::issues($run), "{$case['name']} {$property->id}");
        }
    }

    public function testARefusedRewriteTakesItsDeletionAlong(): void
    {
        $groups = 0;
        foreach (Level2Scenario::cases() as $case) {
            $scenario = new Level2Scenario($case);
            foreach (Fixtures::parityPreset()->properties as $property) {
                $run = self::assembled($scenario, $property);
                $written = $run->finalValidation?->plan?->plan;
                if ($written === null) {
                    continue;
                }
                $refused = self::droppedKeys(['name' => ''], $run);
                foreach ($run->plan->deletions as $deletion) {
                    $groups += isset($refused[$deletion->key()]) ? 1 : 0;
                }
                foreach ([...$written->items, ...$written->deletions] as $entry) {
                    self::assertArrayNotHasKey($entry->key(), $refused, "{$case['name']}: a refused night is written by neither half of its group");
                }
            }
        }
        self::assertGreaterThan(0, $groups, 'some scenario refuses the rewrite of a deleted rule');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testThePayloadAndTheSlicesAreTheReferencesWithoutWhatThePluginRefused(array $case): void
    {
        $scenario = new Level2Scenario($case);
        foreach (Fixtures::parityPreset()->properties as $property) {
            $run = self::assembled($scenario, $property);
            $codes = array_map(static fn ($r): string => $r->code, $scenario->rooms($property));
            $listings = [];
            foreach ($scenario->rooms($property) as $room) {
                $listings[$room->pricingRef] = $room->code;
            }
            if ($run->finalValidation === null) {
                self::assertNull($run->payload);
                self::assertTrue(self::referenceRefuses($case, $codes), "{$case['name']} {$property->id}: the plugin writes nothing only where the reference refuses the plan");
                continue;
            }
            $dropped = self::droppedKeys($case, $run);
            $payload = $run->payload->toArray();
            $reference = $case['expected']['payload'];
            self::assertSame(self::calls($reference['deletions']['delete_listing_date_overrides'] ?? [], $listings, $dropped), self::calls($payload['deletions'], $listings, []), "{$case['name']} {$property->id}: deletions");
            self::assertSame(self::calls($reference['items']['update_listing_date_overrides'] ?? [], $listings, $dropped), self::calls($payload['writes'], $listings, []), "{$case['name']} {$property->id}: writes");
            $expectedSlices = self::slices([...$case['expected']['slices']['deletions'], ...$case['expected']['slices']['items']], $codes, $dropped);
            self::assertSame($expectedSlices, self::slices(array_map(self::asReference(...), $run->slices), $codes, []), "{$case['name']} {$property->id}: slices in writing order");
        }
    }

    /**
     * The deliberate differences of the plugin, per scenario (tests/fixtures/golden/level2/plugin-divergences.json):
     * "other_property_light_run" lists the reference's items that exist only because of the other
     * property's light-run nights (L1); "reason_refinements" tells apart two relations the reference
     * writes with the same text, by the words after it.
     *
     * @return array{other_property_light_run: array<string, list<string>>, reason_refinements: array<string, array<string, string>>}
     */
    private static function divergences(): array
    {
        return Fixtures::json('golden/level2/plugin-divergences.json');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Level2Scenario::cases() as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    private static function assembled(Level2Scenario $scenario, Property $property): AssembledPlan
    {
        $key = $scenario->case['name'] . '|' . $property->id;
        if (!isset(self::$runs[$key])) {
            $preset = Fixtures::parityPreset();
            $assembler = new PlanAssembler($preset->rules, new FixedClock($scenario->frozen['now']), Fixtures::json('presets/parity-plan.json')['pms']);
            self::$runs[$key] = $assembler->assemble($scenario->inputs($property));
        }

        return self::$runs[$key];
    }

    /**
     * @param array<string, mixed> $it the reference's item
     */
    private static function assertItem(array $it, PlanItem $item, string $where): void
    {
        self::assertEqualsWithDelta($it['min_price'] ?? null, $item->minPrice, 0.01, "$where floor");
        self::assertEqualsWithDelta($it['max_price'] ?? null, $item->maxPrice, 0.01, "$where ceiling");
        self::assertSame($it['min_stay'] ?? null, $item->minStay, "$where stay");
        self::assertSame($it['check_in'] ?? null, $item->checkInMask, "$where arrival mask");
        self::assertSame($it['check_out'] ?? null, $item->checkOutMask, "$where departure mask");
        self::assertSame(isset($it['orfa']), $item->orphan !== null, "$where orphan claim");
        self::assertSame(($it['excecao'] ?? null) === 'dono' ? $it['ordem_id'] : null, $item->orderId, "$where order cited");
        self::assertEqualsWithDelta($it['preco_antes'], $item->before?->price, 0.01, "$where price before");
        self::assertSame(self::reason($it), $item->reasonCode, "$where reason");
        if (isset($it['pace'])) {
            $step = $item->reasonParams['step'];
            self::assertEqualsWithDelta($it['pace']['novo'], $step['price_to'], 0.01, "$where step to");
            self::assertSame(['sobe' => 'up', 'desce' => 'down'][$it['pace']['direcao']] ?? null, $step['direction'], "$where step direction");
            self::assertSame([$it['pace']['vendidas'], $it['pace']['total']], [$step['sold'], $step['sold_total']], "$where step sales");
        }
    }

    /**
     * @param array<string, mixed> $it
     */
    private static function reason(array $it): string
    {
        foreach (self::divergences()['reason_refinements'][$it['reason']] ?? [] as $marker => $code) {
            if (str_contains($it['motivo'], $marker)) {
                return $code;
            }
        }

        return $it['reason'];
    }

    /**
     * The plugin's codes per entry ("item|ROOM|DATE", "deletion|ROOM|DATE", "plan|ROOM|DATE").
     *
     * @return array<string, list<string>>
     */
    private static function issues(AssembledPlan $run): array
    {
        $out = [];
        foreach ([...$run->validation->errors, ...$run->validation->warnings] as $issue) {
            $key = match ($issue->subject) {
                IssueSubject::Item => $run->plan->items[$issue->itemIndex]->key(),
                IssueSubject::Deletion => $run->plan->deletions[$issue->itemIndex]->key(),
                default => $issue->roomCode . '|' . ($issue->date === null ? '' : Days::key($issue->date)),
            };
            $out[$issue->subject->value . '|' . $key][] = $issue->severity . ':' . $issue->code;
        }

        return self::normalised($out);
    }

    /**
     * @param array<string, mixed> $case
     * @param list<string>         $codes the rooms of the property
     *
     * @return array<string, list<string>>
     */
    private static function expectedIssues(array $case, array $codes): array
    {
        $out = [];
        foreach (['items' => 'item', 'deletions' => 'deletion'] as $list => $subject) {
            $validation = $case['expected']['validation'][$list];
            if ($validation === null) {
                continue;
            }
            $expected = $validation['php_expected'];
            foreach ($expected[$subject] ?? [] as $index => $entries) {
                $it = $case['expected'][$list][(int) $index];
                if (!in_array($it['quarto'], $codes, true)) {
                    continue;
                }
                foreach ($entries as [$severity, $code]) {
                    $out["$subject|{$it['quarto']}|{$it['data']}"][] = "$severity:$code";
                }
            }
            foreach ($expected['plan'] as [$room, $date, $severity, $code]) {
                if ($room === null || in_array($room, $codes, true)) {
                    $out["plan|$room|$date"][] = "$severity:$code";
                }
            }
        }

        return self::normalised($out);
    }

    /**
     * @param array<string, list<string>> $issues
     *
     * @return array<string, list<string>>
     */
    private static function normalised(array $issues): array
    {
        ksort($issues);

        return array_map(static function (array $codes): array {
            $codes = array_values(array_unique($codes));
            sort($codes);

            return $codes;
        }, $issues);
    }

    /**
     * True when the reference's own validation refuses an entry of the property or the plan.
     *
     * @param array<string, mixed> $case
     * @param list<string>         $codes
     */
    private static function referenceRefuses(array $case, array $codes): bool
    {
        foreach (self::expectedIssues($case, $codes) as $entries) {
            foreach ($entries as $entry) {
                if (str_starts_with($entry, 'error:')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The room nights of the reference's plan the plugin does not write: refused by its
     * validator (with the other half of a delete-and-rewrite group), or left out by L1.
     *
     * @param array<string, mixed> $case
     *
     * @return array<string, true> "ROOM|DATE"
     */
    private static function droppedKeys(array $case, AssembledPlan $run): array
    {
        $out = array_fill_keys(self::divergences()['other_property_light_run'][$case['name']] ?? [], true);
        foreach ($run->validation->rejected(IssueSubject::Item) as $index) {
            $out[$run->plan->items[$index]->key()] = true;
        }
        foreach ($run->validation->rejected(IssueSubject::Deletion) as $index) {
            $out[$run->plan->deletions[$index]->key()] = true;
        }

        return $out;
    }

    /**
     * Calls of the property's listings without the dropped dates and without the reason text, by
     * listing (the order of the calls follows the first item of each room, which moves when an
     * item is dropped).
     *
     * @param list<array<string, mixed>> $calls
     * @param array<string, string>      $listings listing id => room code
     * @param array<string, true>        $dropped
     *
     * @return list<array<string, mixed>>
     */
    private static function calls(array $calls, array $listings, array $dropped): array
    {
        $out = [];
        foreach ($calls as $call) {
            $room = $listings[$call['listing_id']] ?? null;
            if ($room === null) {
                continue;
            }
            $overrides = [];
            foreach ($call['overrides'] as $override) {
                if (!isset($dropped[$room . '|' . $override['date']])) {
                    $overrides[] = array_diff_key($override, ['reason' => true]);
                }
            }
            if ($overrides !== []) {
                $out[$call['listing_id']] = ['listing_id' => $call['listing_id'], 'pms' => $call['pms'], 'overrides' => $overrides];
            }
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Slices of the property's rooms without the dropped dates, the reason text and the numbers, in
     * writing order (deletions, tonight, tomorrow, the blocks, the listing), rooms sorted inside
     * each step.
     *
     * @param list<array<string, mixed>> $slices in the reference's terms
     * @param list<string>               $codes
     * @param array<string, true>        $dropped
     *
     * @return list<array<string, mixed>>
     */
    private static function slices(array $slices, array $codes, array $dropped): array
    {
        $out = [];
        foreach ($slices as $slice) {
            if (!in_array($slice['quarto'], $codes, true)) {
                continue;
            }
            $overrides = [];
            foreach ($slice['args']['overrides'] as $override) {
                if (!isset($dropped[$slice['quarto'] . '|' . $override['date']])) {
                    $overrides[] = array_diff_key($override, ['reason' => true]);
                }
            }
            if ($overrides === []) {
                continue;
            }
            $dates = array_column($overrides, 'date');
            $out[] = [
                'ferramenta' => $slice['ferramenta'],
                'quarto' => $slice['quarto'],
                'rotulo' => $slice['rotulo'],
                'de' => $dates[0],
                'ate' => $dates[count($dates) - 1],
                'datas' => count($dates),
                'args' => ['listing_id' => $slice['args']['listing_id'], 'pms' => $slice['args']['pms'], 'overrides' => $overrides],
            ];
        }
        $rank = static fn (array $s): array => [array_search($s['ferramenta'], array_values(self::TOOLS), true), $s['rotulo'] === 'D0' ? 0 : ($s['rotulo'] === 'D1' ? 1 : 2), $s['quarto'], $s['rotulo']];
        usort($out, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function asReference(Slice $slice): array
    {
        return [
            'ferramenta' => self::TOOLS[$slice->kind->value],
            'quarto' => $slice->roomCode(),
            'rotulo' => $slice->kind === CallKind::Listing ? 'anúncio' : $slice->label,
            'args' => $slice->request->toArray(),
        ];
    }
}
