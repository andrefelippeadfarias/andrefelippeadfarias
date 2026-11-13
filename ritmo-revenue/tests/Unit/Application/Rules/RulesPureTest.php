<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Rules\FieldChange;
use Ritmo\Application\Rules\Percent;
use Ritmo\Application\Rules\PrivacyCheck;
use Ritmo\Application\Rules\RuleDiff;
use Ritmo\Application\Rules\RuleMerge;
use Ritmo\Application\Rules\SafetyFields;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;

final class RulesPureTest extends TestCase
{
    public function testOneChangedGoalIsOneRow(): void
    {
        $from = RuleSet::defaults()->toArray();
        $to = $from;
        $to['occupancy']['goals'][7] = 65.0;

        $changes = RuleDiff::between($from, $to, Schema::ruleSet());

        self::assertCount(1, $changes);
        self::assertSame(['occupancy.goals.7', 'occupancy.goals.7', FieldChange::CHANGED], [$changes[0]->path, $changes[0]->key, $changes[0]->kind]);
        self::assertSame([$from['occupancy']['goals'][7], 65.0], [$changes[0]->before, $changes[0]->after]);
        self::assertSame([], RuleDiff::between($from, $from, Schema::ruleSet()));
    }

    public function testListItemsAreMatchedByIdAndAddedOrRemovedAsOneRow(): void
    {
        $base = RuleSet::defaults()->toArray();
        $base['price_bands']['items'] = [self::band('a', 100.0), self::band('b', 200.0)];
        $to = $base;
        $to['price_bands']['items'] = [self::band('b', 250.0), self::band('c', 300.0)];

        $changes = RuleDiff::between($base, $to, Schema::ruleSet());

        self::assertSame([
            ['price_bands.items.0.min_amount', 'price_bands.items.*.min_amount', FieldChange::CHANGED],
            ['price_bands.items.1', 'price_bands.items.*', FieldChange::ADDED],
            ['price_bands.items.0', 'price_bands.items.*', FieldChange::REMOVED],
        ], array_map(static fn (FieldChange $c): array => [$c->path, $c->key, $c->kind], $changes));
        self::assertCount(1, RuleDiff::between($base, $to, Schema::ruleSet(), true), 'as a merge unit the list is one change');
    }

    public function testAListOfPlainValuesIsOneChangeAndNumbersCompareByValue(): void
    {
        $from = RuleSet::defaults()->toArray();
        $to = $from;
        $to['calendar']['weekend_days'] = [5];
        $to['occupancy']['goals'][15] = (int) $from['occupancy']['goals'][15];

        $changes = RuleDiff::between($from, $to, Schema::ruleSet());

        self::assertSame([['calendar.weekend_days', [4, 5], [5]]], array_map(static fn (FieldChange $c): array => [$c->path, $c->before, $c->after], $changes));
    }

    public function testAnUnknownSectionIsComparedWithoutTheSchema(): void
    {
        $changes = RuleDiff::between(['later' => ['percentiles' => [10, 50]]], ['later' => ['percentiles' => [10, 50, 90], 'neighbour_days' => 2]], Schema::ruleSet());

        self::assertSame([['later.percentiles', null, FieldChange::CHANGED], ['later.neighbour_days', null, FieldChange::ADDED]], array_map(static fn (FieldChange $c): array => [$c->path, $c->key, $c->kind], $changes));
    }

    public function testThreeWayMergeKeepsBothChangesAndReportsConflicts(): void
    {
        $base = RuleSet::defaults()->toArray();
        $mine = $base;
        $mine['occupancy']['goals'][7] = 70.0;
        $theirs = $base;
        $theirs['occupancy']['goals'][15] = 66.0;

        $merge = RuleMerge::merge($base, $mine, $theirs, Schema::ruleSet());

        self::assertSame([], $merge['conflicts']);
        self::assertSame([70.0, 66.0], [$merge['rules']['occupancy']['goals'][7], $merge['rules']['occupancy']['goals'][15]]);

        $theirs['occupancy']['goals'][7] = 65.0;
        $conflict = RuleMerge::merge($base, $mine, $theirs, Schema::ruleSet())['conflicts'];
        self::assertSame([['path' => 'occupancy.goals.7', 'key' => 'occupancy.goals.7', 'mine' => 70.0, 'theirs' => 65.0]], $conflict);

        $theirs['occupancy']['goals'][7] = 70.0;
        self::assertSame([], RuleMerge::merge($base, $mine, $theirs, Schema::ruleSet())['conflicts'], 'the same new value is no conflict');

        $mine['price_bands']['items'] = [self::band('a', 100.0)];
        $theirs['price_bands']['items'] = [self::band('b', 100.0)];
        self::assertSame(['price_bands.items'], array_column(RuleMerge::merge($base, $mine, $theirs, Schema::ruleSet())['conflicts'], 'path'), 'two people editing one list conflict');
    }

    public function testPercentsMoveTheDecimalPointInTheText(): void
    {
        foreach (['70' => 0.7, '7.5' => 0.075, '100' => 1.0, '0' => 0.0, '0,5' => 0.005, '33.333' => 0.33333, '075' => 0.75] as $text => $ratio) {
            self::assertSame($ratio, Percent::toRatio((string) $text), (string) $text);
        }
        foreach ([[0.7, '70'], [0.075, '7.5'], [1.0, '100'], [0.0, '0'], [0.33333, '33.333'], [0.05, '5']] as [$ratio, $text]) {
            self::assertSame($text, Percent::fromRatio($ratio));
        }
        self::assertSame(0.7, Percent::toRatio(Percent::fromRatio(0.7)));
        foreach (['', '-5', '1e2', '70%', 'abc', '1.2.3'] as $bad) {
            try {
                Percent::toRatio($bad);
                self::fail($bad);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSafetyFieldsAndTheirTypedConfirmation(): void
    {
        foreach (SafetyFields::KEYS as $key) {
            self::assertTrue(str_ends_with($key, '.*') ? Schema::ruleSet()->has(substr($key, 0, -2)) : Schema::ruleSet()->has($key), $key);
        }
        self::assertTrue(SafetyFields::covers('quota.per_listing'));
        self::assertFalse(SafetyFields::covers('quotas'));
        self::assertFalse(SafetyFields::covers('floors.owner.*.amount'));
        $changes = [
            new FieldChange('floors.fraction_of_reference_min', 'floors.fraction_of_reference_min', FieldChange::CHANGED, 0.75, 0.7),
            new FieldChange('write.listing_changes_enabled', 'write.listing_changes_enabled', FieldChange::CHANGED, false, true),
            new FieldChange('quota.per_listing', 'quota.per_listing', FieldChange::CHANGED, 3, 2),
        ];
        self::assertSame('70 1 2', SafetyFields::confirmation($changes));
    }

    public function testPrivacyCheckRefusesGuestDataAndKeepsInnocentText(): void
    {
        foreach (['Weekend promo 2026', 'Room 12', 'Price 480', 'Christmas 2026-12-24 to 2026-12-26', 'Promo 1.234,56', 'Closed 24/12/2026', 'Booking 123456'] as $innocent) {
            self::assertNull(PrivacyCheck::problem($innocent, 190), $innocent);
        }
        $cases = ['write to guest@mail.com' => PrivacyCheck::EMAIL, 'call (11) 98765-4321' => PrivacyCheck::PHONE, '+55 11 3333 4444' => PrivacyCheck::PHONE, 'cpf 123.456.789-09' => PrivacyCheck::DOCUMENT, 'cnpj 12.345.678/0001-90' => PrivacyCheck::DOCUMENT];
        foreach ($cases as $text => $problem) {
            self::assertSame($problem, PrivacyCheck::problem($text, 190), $text);
        }
        self::assertSame(PrivacyCheck::TOO_LONG, PrivacyCheck::problem(str_repeat('a', 191), 190));
    }

    /**
     * @return array<string, mixed>
     */
    private static function band(string $id, float $min): array
    {
        return ['id' => $id, 'label' => 'Band ' . $id, 'enabled' => true, 'property' => 'inn-1', 'room' => 'room-a', 'night_types' => ['weekday'], 'price_plan' => 'sent', 'min_amount' => $min, 'max_amount' => 900.0, 'max_days_out' => 60, 'skip_on_strategy_nights' => true];
    }
}
