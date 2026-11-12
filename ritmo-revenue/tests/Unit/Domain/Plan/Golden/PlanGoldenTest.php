<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\Payload\CallKind;
use Ritmo\Domain\Plan\Payload\PayloadBuilder;
use Ritmo\Domain\Plan\Payload\Slice;
use Ritmo\Domain\Plan\Payload\Slicer;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Support\Fixtures;

/**
 * Parity of the plan validator, the payload and its slices with the reference engine
 * (validar_plano.Validador, montar_payload, fatiar_payload.fatiar) over the frozen plans of
 * tests/fixtures/golden/m2/plan: three real plans of the owner's properties and synthetic plans
 * that walk the error paths. The reference validates the plan of both properties at once; the
 * plugin validates, builds and slices per property, so the reference's payload and slices are
 * compared per property, in their order.
 */
final class PlanGoldenTest extends TestCase
{
    private const FILES = ['validar_plano.validar.json', 'validar_plano.montar_payload.json', 'fatiar_payload.fatiar.json'];

    private const TOOLS = ['delete' => 'delete_listing_date_overrides', 'write' => 'update_listing_date_overrides', 'listing' => 'update_listing_data'];

    public function testEveryGoldenFileIsCoveredAndTheInputsAreTheFrozenOnes(): void
    {
        $manifest = PlanParity::manifest();
        self::assertFalse($manifest['engine_dirty'], 'the goldens must come from committed engine code');
        self::assertSame(self::FILES, array_column($manifest['files'], 'file'));
        foreach ($manifest['frozen_inputs'] as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', Fixtures::path($path)), "$path changed without regenerating the goldens");
        }
        self::assertSame($manifest['parity_fixture']['sha256'], hash_file('sha256', Fixtures::path($manifest['parity_fixture']['file'])));
        $scenarios = array_column(PlanParity::frozen('scenarios.json')['scenarios'], 'name');
        self::assertGreaterThanOrEqual(8, count($scenarios));
        self::assertSame($scenarios, array_column(PlanParity::cases(self::FILES[0]), 'name'));
        foreach (['c1111-final', 'c1112-final', 'c1112-delete'] as $real) {
            self::assertContains($real, array_column(PlanParity::cases(self::FILES[1]), 'name'), "the real plan $real passes and has its payload");
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('validationCases')]
    public function testTheValidatorReportsWhatTheReferenceReports(array $case): void
    {
        $input = $case['input'];
        $got = ['item' => [], 'deletion' => [], 'listing' => [], 'plan' => []];
        $valid = true;
        foreach (self::validations($input) as [$result, $map]) {
            $valid = $valid && $result->isValid();
            foreach ([...$result->errors, ...$result->warnings] as $issue) {
                $entry = [$issue->severity, $issue->code];
                if ($issue->subject === IssueSubject::Plan) {
                    $got['plan'][] = [$issue->roomCode, $issue->date === null ? null : Days::key($issue->date), ...$entry];
                    continue;
                }
                $global = (string) $map[$issue->subject->value][$issue->itemIndex];
                $got[$issue->subject->value][$global][] = $entry;
            }
        }
        $expected = $case['php_expected'] ?? $case['expected'];
        self::assertSame($expected, self::normalised($got), $case['name']);
        self::assertSame($case['valid'] && !isset($case['php_rejected']), $valid, 'a plan passes exactly when the reference passes it (and no divergence refuses an entry)');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('payloadCases')]
    public function testThePayloadIsTheReferencesPayload(array $case): void
    {
        $validation = self::validationCase($case['name']);
        $parity = PlanParity::parity();
        foreach (self::validations($validation['input'], true) as [$result, , $property]) {
            $rooms = Fixtures::parityPreset()->roomTypesOf($property->id);
            $payload = (new PayloadBuilder($parity['pms'], PlanParity::config()))->build($result->plan, $property, $rooms);
            $listings = array_map(static fn ($room): string => $room->pricingRef, $rooms);
            $reference = array_map(
                static fn (array $calls): array => array_values(array_filter($calls, static fn (array $call): bool => in_array($call['listing_id'], $listings, true))),
                $case['php_expected'] ?? $case['expected'],
            );
            $mine = $payload->toArray();
            self::assertSame($reference['delete_listing_date_overrides'], $mine['deletions'], $property->id);
            self::assertSame($reference['update_listing_date_overrides'], $mine['writes'], $property->id);
            self::assertEquals($reference['update_listing_data'], $mine['listing'], $property->id);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('sliceCases')]
    public function testTheSlicesAreTheReferencesSlicesInWritingOrder(array $case): void
    {
        $validation = self::validationCase($case['name']);
        $parity = PlanParity::parity();
        foreach (self::validations($validation['input'], true) as [$result, , $property]) {
            $rooms = Fixtures::parityPreset()->roomTypesOf($property->id);
            $payload = (new PayloadBuilder($parity['pms'], PlanParity::config()))->build($result->plan, $property, $rooms);
            $codes = array_map(static fn ($room): string => $room->code, $rooms);
            $reference = array_values(array_filter($case['php_expected'] ?? $case['expected'], static fn (array $s): bool => in_array($s['quarto'], $codes, true)));
            $mine = array_map(self::asReference(...), (new Slicer($case['input']['max_dates']))->slice($payload, Days::date($case['input']['today'])));
            $strip = static fn (array $slices): array => array_map(static fn (array $s): array => array_diff_key($s, ['n' => true]), $slices);
            self::assertEquals($strip($reference), $strip($mine), $property->id);
            self::assertSame(range(1, max(1, count($mine))), $mine === [] ? [1] : array_column($mine, 'n'));
        }
    }

    public function testTheOrphanProofsOfTheRealPlansAreTheFrozenInventory(): void
    {
        $checked = 0;
        foreach (['c1111', 'c1112'] as $day) {
            $inventory = PlanParity::frozen("inventory-$day.json");
            foreach (PlanParity::frozen("plan-$day-final.json")['itens'] as $it) {
                $proof = $it['orfa_prova'] ?? null;
                if (($proof['fonte'] ?? null) !== 'beds24') {
                    continue;
                }
                $neighbour = $it['orfa'] === 'seguinte' ? 1 : -1;
                $other = Days::key(Days::add(Days::date($it['data']), $neighbour));
                self::assertSame($proof['noite_numAvail'], $inventory[$it['quarto']][$it['data']]['numAvail']);
                self::assertSame($proof[($neighbour === 1 ? 'seguinte' : 'anterior') . '_numAvail'], $inventory[$it['quarto']][$other]['numAvail']);
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function validationCases(): array
    {
        return self::provider(self::FILES[0]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function payloadCases(): array
    {
        return self::provider(self::FILES[1]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function sliceCases(): array
    {
        return self::provider(self::FILES[2]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    private static function provider(string $file): array
    {
        $cases = [];
        foreach (PlanParity::cases($file) as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private static function validationCase(string $name): array
    {
        foreach (PlanParity::cases(self::FILES[0]) as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        self::fail("no validation case $name");
    }

    /**
     * The plan of every property of the parity preset that has entries, validated; with $dropRejected,
     * validated again without the entries refused the first time, as the run does before writing.
     *
     * @param array<string, mixed> $input
     *
     * @return list<array{0: ValidationResult, 1: array<string, array<int, int>>, 2: Property}>
     */
    private static function validations(array $input, bool $dropRejected = false): array
    {
        $preset = Fixtures::parityPreset();
        $clock = new FixedClock($input['now']);
        $items = PlanParity::frozen($input['plan'])['itens'];
        $context = new ValidationContext(
            PlanParity::calendar($input['calendar']),
            PlanParity::orders($input['name']),
            new RecordedStrategy($input['hooks']),
            PlanParity::stay(),
            PlanParity::bands($input['hooks']['band_releases']),
            null,
            PlanParity::parity()['weekly_reference'],
            $input['accept_stale'],
        );
        $out = [];
        foreach ($preset->properties as $property) {
            $codes = array_map(static fn ($room): string => $room->code, $preset->roomTypesOf($property->id));
            [$plan, $map] = self::planOf($property, $codes, $items, $clock, $input['today']);
            if ($plan === null) {
                continue;
            }
            $validator = new Validator($preset->rules, $clock, PlanParity::config());
            $result = $validator->validate($plan, $property, $preset->roomTypesOf($property->id), $context);
            if ($dropRejected && !$result->isValid()) {
                $plan = $plan->without($result->rejected(IssueSubject::Item), $result->rejected(IssueSubject::Deletion), $result->rejected(IssueSubject::Listing));
                $result = $validator->validate($plan, $property, $preset->roomTypesOf($property->id), $context);
                self::assertTrue($result->isValid(), 'without the refused entries the plan passes');
            }
            $out[] = [$result, $map, $property];
        }

        return $out;
    }

    /**
     * The entries of one property, and where each sits in the reference's lists of entries.
     *
     * @param list<string>               $codes the rooms of the property
     * @param list<array<string, mixed>> $items the reference's plan
     *
     * @return array{0: Plan|null, 1: array<string, array<int, int>>}
     */
    private static function planOf(Property $property, array $codes, array $items, FixedClock $clock, string $today): array
    {
        $lists = ['item' => [], 'deletion' => [], 'listing' => []];
        $map = ['item' => [], 'deletion' => [], 'listing' => []];
        $global = ['item' => 0, 'deletion' => 0, 'listing' => 0];
        foreach ($items as $it) {
            $subject = ['data' => 'item', 'apagar' => 'deletion', 'anuncio' => 'listing'][$it['tipo']];
            if (in_array($it['quarto'], $codes, true)) {
                $map[$subject][count($lists[$subject])] = $global[$subject];
                $lists[$subject][] = match ($subject) {
                    'item' => PlanParity::item($it),
                    'deletion' => PlanParity::deletion($it),
                    'listing' => PlanParity::listing($it),
                };
            }
            $global[$subject]++;
        }
        if ($lists === ['item' => [], 'deletion' => [], 'listing' => []]) {
            return [null, $map];
        }

        return [new Plan($property->id, Days::date($today), $clock->now(), $lists['item'], $lists['deletion'], $lists['listing']), $map];
    }

    /**
     * @param array{item: array<string, list<array{0: string, 1: string}>>, deletion: array<string, mixed>, listing: array<string, mixed>, plan: list<array<int, string|null>>} $got
     *
     * @return array<string, mixed>
     */
    private static function normalised(array $got): array
    {
        $out = [];
        foreach (['item', 'deletion', 'listing'] as $subject) {
            $entries = $got[$subject];
            uksort($entries, static fn ($a, $b): int => (int) $a <=> (int) $b);
            $out[$subject] = array_map(static function (array $codes): array {
                $codes = array_values(array_unique($codes, SORT_REGULAR));
                sort($codes);

                return $codes;
            }, $entries);
        }
        $plan = array_values(array_unique($got['plan'], SORT_REGULAR));
        sort($plan);
        $out['plan'] = $plan;

        return $out;
    }

    /**
     * A slice in the reference's terms.
     *
     * @return array<string, mixed>
     */
    private static function asReference(Slice $slice): array
    {
        $first = $slice->first();
        $last = $slice->last();

        return [
            'n' => $slice->number,
            'ferramenta' => self::TOOLS[$slice->kind->value],
            'quarto' => $slice->roomCode(),
            'rotulo' => $slice->kind === CallKind::Listing ? 'anúncio' : $slice->label,
            'de' => $first === null ? null : Days::key($first),
            'ate' => $last === null ? null : Days::key($last),
            'datas' => count($slice->dates()),
            'args' => $slice->request->toArray(),
        ];
    }
}
