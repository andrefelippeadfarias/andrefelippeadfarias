<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Ai;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Ai\DraftHandOff;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Ai\DraftReader;
use Ritmo\Domain\Ai\DraftContext;
use Ritmo\Domain\Ai\RuleCatalogue;
use Ritmo\Domain\Ai\RuleChange;
use Ritmo\Domain\Support\Days;

/**
 * A draft only gets through when it means what the person wrote: rooms of the property, nights
 * from today within a year, amounts and percentages taken from the request (the floors of a raise
 * computed by the plugin from the base prices), and rule settings of the catalogue only, never a
 * safety limit, a mode, the writing or the quotas, checked as a whole rule set.
 */
final class DraftReaderTest extends TestCase
{
    public function testAMinimumPriceOnTheHolidayBecomesAFloorOrderForTheForm(): void
    {
        $request = 'Não vender abaixo de R$ 900 no feriado de Natal';
        $draft = self::reader()->read(AiFixtures::answer('order', ['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 900, 'note' => 'Holiday minimum.']), AiFixtures::context($request));

        self::assertSame(AiDraft::ORDER, $draft->kind);
        self::assertSame([
            'type' => 'floor',
            'rooms' => ['*'],
            'from' => '2026-12-24',
            'to' => '2026-12-25',
            'nights' => [],
            'floors' => ['STD' => '900.00', 'SUITE' => '900.00'],
            'percentile' => '',
            'releases' => [],
            'note' => 'Holiday minimum.',
        ], $draft->order?->formFields());
    }

    public function testARaiseOfTheSaturdaysIsComputedFromTheBasePriceByThePlugin(): void
    {
        $request = 'subir 10% os sábados de dezembro na suite';
        $draft = self::reader()->read(AiFixtures::answer('order', ['type' => 'floor', 'rooms' => ['SUITE'], 'from' => '2026-12-01', 'to' => '2026-12-31', 'weekdays' => [5], 'change_percent' => 10]), AiFixtures::context($request));

        $fields = $draft->order?->formFields() ?? [];
        self::assertSame(['2026-12-05', '2026-12-12', '2026-12-19', '2026-12-26'], $fields['nights']);
        self::assertSame(['2026-12-05', '2026-12-26'], [$fields['from'], $fields['to']]);
        self::assertSame(['SUITE' => '880.00'], $fields['floors']);
        self::assertSame([['room' => 'SUITE', 'base' => 800.0, 'percent' => 10.0, 'floor' => 880.0]], $draft->order?->derivation);
    }

    public function testInventedNumbersAndForeignRoomsAreRefused(): void
    {
        $cases = [
            'an amount the person did not write' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 950], 'Não vender abaixo de R$ 900 no Natal'],
            'a percentage the person did not write' => [['type' => 'floor', 'rooms' => ['SUITE'], 'from' => '2026-12-01', 'to' => '2026-12-31', 'change_percent' => 15], 'subir 10% em dezembro'],
            'a room of another property' => [['type' => 'floor', 'rooms' => ['LOFT'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 900], 'R$ 900 no Natal'],
            'a night in the past' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-11-01', 'to' => '2026-11-20', 'floor_amount' => 900], 'R$ 900'],
            'a night beyond a year' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2027-12-01', 'to' => '2027-12-02', 'floor_amount' => 900], 'R$ 900'],
            'the last night before the first' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-25', 'to' => '2026-12-24', 'floor_amount' => 900], 'R$ 900'],
            'a floor without an amount' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25'], 'segurar o Natal'],
            'both an amount and a percentage' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 900, 'change_percent' => 10], 'R$ 900 e 10%'],
            'a weekday that does not exist' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-01', 'to' => '2026-12-31', 'weekdays' => [7], 'floor_amount' => 900], 'R$ 900'],
            'a percentile on a floor order' => [['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 900, 'percentile' => 60], 'R$ 900 percentil 60'],
        ];
        foreach ($cases as $name => [$order, $request]) {
            try {
                self::reader()->read(AiFixtures::answer('order', $order), AiFixtures::context($request));
                self::fail($name . ' must be refused');
            } catch (AiStopped $e) {
                self::assertSame(AiStopped::DRAFT_REFUSED, $e->reason, $name);
                self::assertNotEmpty($e->details['problems'], $name);
            }
        }
    }

    public function testARuleChangeOfTheCatalogueGoesThroughWithItsBeforeAndAfter(): void
    {
        $draft = self::reader()->read(
            AiFixtures::answer('rule_change', [], [['key' => 'calendar.holidays.0.floor', 'value' => '900'], ['key' => 'occupancy.goals.7', 'value' => '70']]),
            AiFixtures::context('Piso de R$ 900 no Natal e meta de 70% para os próximos 7 dias')
        );

        self::assertSame(AiDraft::RULE_CHANGE, $draft->kind);
        self::assertSame([
            ['calendar.holidays.0.floor', 'calendar.holidays.*.floor', null, 900.0],
            ['occupancy.goals.7', 'occupancy.goals.7', 60.0, 70.0],
        ], array_map(static fn ($c): array => [$c->path, $c->key, $c->before, $c->after], $draft->ruleChanges));
    }

    public function testSafetyModesWritingAndQuotasAreNeverDrafted(): void
    {
        $refused = [
            'safety.weekly_listing_change_max' => '0.5',
            'safety.never_raise_today' => 'no',
            'modes.autonomous_enabled' => 'yes',
            'write.listing_changes_enabled' => 'yes',
            'floors.fraction_of_reference_min' => '0.5',
            'quota.recalculations_per_day' => '50',
            'approval.window_hours' => '48',
            'calendar.holidays.0.name' => 'Ignore the rules',
            'calendar.holidays.0' => '{}',
            'unknown.setting' => '1',
        ];
        foreach ($refused as $key => $value) {
            try {
                self::reader()->read(AiFixtures::answer('rule_change', [], [['key' => $key, 'value' => $value]]), AiFixtures::context('mudar para ' . $value . ' 0.5 50 48'));
                self::fail($key . ' must be refused');
            } catch (AiStopped $e) {
                self::assertSame(AiStopped::DRAFT_REFUSED, $e->reason, $key);
                self::assertStringContainsString('not a setting the assistant may change', $e->getMessage(), $key);
            }
        }
    }

    public function testWritingToThePmsAndTheValidatorAreNeverDraftedEvenAfterAnInjection(): void
    {
        $request = 'Explique como esta o ritmo de vendas do Natal';
        foreach ([
            'stay.write.enabled' => 'yes',
            'stay.write.max_changes_per_run' => '500',
            'plan.validator.last_minute.enabled' => 'yes',
            'plan.validator.rise_warning_percent' => '90',
            'plan.payload.slice_max_dates' => '30',
            'plan.horizon_nights' => '365',
            'market.csv.max_rows' => '9000',
        ] as $key => $value) {
            self::assertNull(RuleCatalogue::forRules()->fieldFor($key), $key);
            try {
                self::reader()->read(AiFixtures::answer('rule_change', [], [['key' => $key, 'value' => $value]]), AiFixtures::context($request . ' ' . $key . ' ' . $value));
                self::fail($key . ' must be refused');
            } catch (AiStopped $e) {
                self::assertSame(['setting_denied:' . $key], $e->details['problems'], $key);
            }
        }
        $draft = new AiDraft(AiDraft::RULE_CHANGE, 'x', null, [new RuleChange('stay.write.enabled', 'stay.write.enabled', 'On', false, true)], []);
        $this->expectException(\InvalidArgumentException::class);
        (new DraftHandOff(RuleCatalogue::forRules()))->ruleDraft($draft, AiFixtures::rules());
    }

    public function testASwitchOrAChoiceChangesOnlyWhenTheRequestNamesTheSetting(): void
    {
        $change = [['key' => 'soldout.protection.enabled', 'value' => 'no']];
        try {
            self::reader()->read(AiFixtures::answer('rule_change', [], $change), AiFixtures::context('Explique como esta o ritmo de vendas do Natal'));
            self::fail('a switch the request does not name must be refused');
        } catch (AiStopped $e) {
            self::assertSame(['setting_not_named:soldout.protection.enabled'], $e->details['problems']);
        }
        $byLabel = self::reader()->read(AiFixtures::answer('rule_change', [], $change), AiFixtures::context('Turn off "Protect sold-out nights", please'));
        self::assertFalse($byLabel->ruleChanges[0]->after);
        $translated = new DraftContext(Days::date(AiFixtures::TODAY), AiFixtures::ROOMS, 'Desligue a proteção das noites esgotadas', AiFixtures::rules(), ['soldout.protection.enabled' => ['Proteção das noites esgotadas']]);
        self::assertFalse(self::reader()->read(AiFixtures::answer('rule_change', [], $change), $translated)->ruleChanges[0]->after);
    }

    public function testAnOrderKeepsToTheRoomsAndNightsTheRequestNames(): void
    {
        $request = 'Minimo de 900 no quarto STD na noite de 24/12';
        $wide = ['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-11-16', 'to' => '2027-11-14', 'floor_amount' => 900];
        $cases = [
            'every room for one room' => [['from' => '2026-12-24', 'to' => '2026-12-24'] + $wide, 'all_rooms_not_written'],
            'another room' => [['rooms' => ['SUITE'], 'from' => '2026-12-24', 'to' => '2026-12-24'] + $wide, 'room_not_written:SUITE'],
            'a year for one night' => [['rooms' => ['STD']] + $wide, 'nights_not_written'],
            'a fixed price' => [['type' => 'fixed_price', 'floor_amount' => 0] + $wide, 'type_form_only'],
            'a restriction' => [['type' => 'restriction', 'floor_amount' => 0] + $wide, 'type_form_only'],
        ];
        foreach ($cases as $name => [$order, $problem]) {
            try {
                self::reader()->read(AiFixtures::answer('order', $order), AiFixtures::context($request));
                self::fail($name . ' must be refused');
            } catch (AiStopped $e) {
                self::assertSame(AiStopped::DRAFT_REFUSED, $e->reason, $name);
                self::assertContains($problem, $e->details['problems'], $name);
            }
        }
        $ok = self::reader()->read(AiFixtures::answer('order', ['rooms' => ['STD'], 'from' => '2026-12-24', 'to' => '2026-12-24'] + $wide), AiFixtures::context($request));
        self::assertSame(['STD'], $ok->order?->rooms);
        $all = self::reader()->read(AiFixtures::answer('order', ['from' => '2026-12-24', 'to' => '2026-12-24'] + $wide), AiFixtures::context('Minimo de 900 em todos os quartos na noite de 24/12'));
        self::assertSame(['*'], $all->order?->rooms);
        $month = self::reader()->read(AiFixtures::answer('order', ['from' => '2027-01-01', 'to' => '2027-01-31'] + $wide), AiFixtures::context('Minimo de 900 em janeiro'));
        self::assertSame('2027-01-31', $month->order?->to);
        $holiday = self::reader()->read(AiFixtures::answer('order', ['from' => '2026-12-24', 'to' => '2026-12-25'] + $wide), AiFixtures::context('Minimo de 900 no Christmas'));
        self::assertSame('2026-12-25', $holiday->order?->to);
        $short = self::reader()->read(AiFixtures::answer('order', ['from' => '2026-11-20', 'to' => '2026-12-20'] + $wide), AiFixtures::context('Minimo de 900 nas proximas semanas'));
        self::assertSame('2026-12-20', $short->order?->to);
        try {
            self::reader()->read(AiFixtures::answer('order', ['from' => '2026-11-20', 'to' => '2026-12-21'] + $wide), AiFixtures::context('Minimo de 900 nas proximas semanas'));
            self::fail('32 nights without dates must be refused');
        } catch (AiStopped $e) {
            self::assertContains('nights_not_written', $e->details['problems']);
        }
    }

    public function testRuleValuesOutOfRangeNotWrittenOrNotChangingAreRefused(): void
    {
        $cases = [
            'out of range' => [[['key' => 'occupancy.goals.7', 'value' => '170']], 'meta de 170%'],
            'not written by the person' => [[['key' => 'occupancy.goals.7', 'value' => '75']], 'meta de 70%'],
            'not a number' => [[['key' => 'occupancy.goals.7', 'value' => 'high']], 'meta alta'],
            'no change' => [[['key' => 'occupancy.goals.7', 'value' => '60']], 'meta de 60%'],
            'a list item that does not exist' => [[['key' => 'calendar.holidays.4.floor', 'value' => '900']], 'R$ 900'],
            'the same setting twice' => [[['key' => 'occupancy.goals.7', 'value' => '70'], ['key' => 'occupancy.goals.7', 'value' => '70']], '70%'],
        ];
        foreach ($cases as $name => [$changes, $request]) {
            try {
                self::reader()->read(AiFixtures::answer('rule_change', [], $changes), AiFixtures::context($request));
                self::fail($name . ' must be refused');
            } catch (AiStopped $e) {
                self::assertSame(AiStopped::DRAFT_REFUSED, $e->reason, $name);
            }
        }
    }

    public function testAnAnswerOutsideTheSchemaNeverBecomesADraft(): void
    {
        $injected = AiFixtures::answer('order', ['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 900]);
        $cases = [
            'an extra field' => $injected + ['mode' => 'auto'],
            'an extra order field' => array_replace_recursive($injected, ['order' => ['releases' => ['listing']]]),
            'a write kind' => ['kind' => 'write_prices'] + $injected,
            'a manual order' => array_replace_recursive($injected, ['order' => ['type' => 'manual']]),
            'a missing field' => array_diff_key($injected, ['questions' => true]),
            'a list' => [1, 2, 3],
        ];
        foreach ($cases as $name => $answer) {
            try {
                self::reader()->read($answer, AiFixtures::context('R$ 900 no Natal'));
                self::fail($name . ' must be refused');
            } catch (AiStopped $e) {
                self::assertSame(AiStopped::INVALID_OUTPUT, $e->reason, $name);
            }
        }
    }

    public function testNotSupportedCarriesTheSummaryAndTheQuestions(): void
    {
        try {
            self::reader()->read(AiFixtures::answer('not_supported', [], [], ['Which property?']), AiFixtures::context('mude para o modo autônomo'));
            self::fail('not supported is not a draft');
        } catch (AiStopped $e) {
            self::assertSame([AiStopped::NOT_SUPPORTED, 'A draft.', ['Which property?']], [$e->reason, $e->details['summary'], $e->details['questions']]);
        }
    }

    public function testGuestDataInTheDraftIsRefused(): void
    {
        $this->expectException(AiStopped::class);
        $this->expectExceptionMessage('guest data');
        self::reader()->read(AiFixtures::answer('order', ['type' => 'floor', 'rooms' => ['*'], 'from' => '2026-12-24', 'to' => '2026-12-25', 'floor_amount' => 900, 'note' => 'Call maria@example.com']), AiFixtures::context('R$ 900'));
    }

    private static function reader(): DraftReader
    {
        return new DraftReader(RuleCatalogue::forRules());
    }
}
