<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\ChangeRecorder;
use Ritmo\Domain\Write\Change\UndoAction;
use Ritmo\Domain\Write\Change\UndoKind;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * Every confirmed change has before, after, why and how to undo it (docs/CHANGELOG-UNDO.md).
 */
final class ChangeLogTest extends TestCase
{
    public function testOneRowPerChangedFieldWithAmountsAndTheUndo(): void
    {
        $written = self::night(WriteKind::Write, [ChangeField::FLOOR_PCT => -29], [ChangeField::FLOOR_PCT => -20, ChangeField::MIN_STAY => 2]);
        $rows = (new ChangeRecorder('inn', ChangeRecord::ACTOR_AUTONOMOUS, 7))->night($written, new NightFields([ChangeField::FLOOR_PCT => -29, ChangeField::MIN_STAY => 2]), 1480.0, self::at());

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame([ChangeField::FLOOR_PCT, -20, -29, 1184.0, 1051.0], [$row->field, $row->oldValue, $row->newValue, $row->oldAmount, $row->newAmount]);
        self::assertSame(['pace_step', 'order-1', 7, 'g1', 'stored', 'autonomous'], [$row->reasonCode, $row->orderId, $row->runId, $row->groupKey, $row->confirmLevel, $row->actor]);
        self::assertSame(['kind' => 'write_fields', 'fields' => [ChangeField::FLOOR_PCT => -20]], $row->undo->toArray());
        self::assertSame('pricing|Deluxe|2027-03-10', $row->subjectKey());
    }

    public function testANewRuleIsLoggedWithItsPresenceAndUndoneByADeletion(): void
    {
        $rows = (new ChangeRecorder('inn', 'user:3'))->night(self::night(WriteKind::Write, [ChangeField::CEILING_PCT => 5]), new NightFields([ChangeField::CEILING_PCT => 5]), 1000.0, self::at());

        self::assertSame([ChangeField::RULE, ChangeField::CEILING_PCT], array_map(static fn (ChangeRecord $r): string => $r->field, $rows));
        self::assertSame([ChangeRecord::ABSENT, ChangeRecord::PRESENT], [$rows[0]->oldValue, $rows[0]->newValue]);
        self::assertSame(UndoKind::Delete, $rows[0]->undo->kind);
    }

    public function testAWriteThatChangedNothingLeavesNoRow(): void
    {
        $written = self::night(WriteKind::Write, [ChangeField::FLOOR_PCT => -20], [ChangeField::FLOOR_PCT => -20]);

        self::assertSame([], (new ChangeRecorder('inn', ChangeRecord::ACTOR_AUTONOMOUS))->night($written, new NightFields([ChangeField::FLOOR_PCT => -20]), 1000.0, self::at()));
    }

    public function testOnThePmsOnlyTheSentFieldsAreLogged(): void
    {
        $written = new WrittenNight(Provider::Pms, 'Deluxe', Days::date('2027-03-10'), WriteKind::Write, new NightFields([ChangeField::PMS_MIN_STAY => 3]), new NightFields([ChangeField::PMS_MIN_STAY => 2, ChangeField::PMS_AVAILABLE => 4]), self::at());
        $rows = (new ChangeRecorder('inn', ChangeRecord::ACTOR_AUTONOMOUS))->night($written, new NightFields([ChangeField::PMS_MIN_STAY => 3, ChangeField::PMS_AVAILABLE => 3]), 1000.0, self::at());

        self::assertSame([ChangeField::PMS_MIN_STAY], array_map(static fn (ChangeRecord $r): string => $r->field, $rows));
        self::assertSame(['kind' => 'write_fields', 'fields' => [ChangeField::PMS_MIN_STAY => 2]], $rows[0]->undo->toArray());
    }

    public function testAListingValueIsLoggedWithoutANight(): void
    {
        $row = (new ChangeRecorder('inn', 'user:3'))->listing('Deluxe', ListingField::Base, 1000.0, 1050.0, self::at(), 'base_review', '', 'order-2');

        self::assertSame(['listing', ChangeField::LISTING_BASE, null, 'listing|Deluxe'], [$row->provider->value, $row->field, $row->night, $row->subjectKey()]);
        self::assertSame(UndoKind::ListingChange, $row->undo->kind);
    }

    /**
     * The inverse of a night (CHANGELOG-UNDO.md, section 2).
     */
    public function testTheInverseOfANight(): void
    {
        $f = static fn (array $fields): NightFields => new NightFields($fields);

        self::assertSame(['kind' => 'write_fields', 'fields' => ['floor_pct' => -20]], UndoAction::inverse(Provider::Pricing, $f(['floor_pct' => -20, 'min_stay' => 2]), $f(['floor_pct' => -29, 'min_stay' => 2]))->toArray());
        self::assertSame(['kind' => 'delete_then_write', 'fields' => ['floor_pct' => -20, 'min_stay' => 2]], UndoAction::inverse(Provider::Pricing, $f(['floor_pct' => -20, 'min_stay' => 2]), $f(['ceiling_pct' => 5, 'floor_pct' => -20, 'min_stay' => 2]))->toArray(), 'a field added');
        self::assertSame(['kind' => 'delete', 'fields' => []], UndoAction::inverse(Provider::Pricing, $f([]), $f(['floor_pct' => -29]))->toArray());
        self::assertSame(['kind' => 'write_fields', 'fields' => ['ceiling_pct' => 5, 'floor_pct' => -20]], UndoAction::inverse(Provider::Pricing, $f(['ceiling_pct' => 5, 'floor_pct' => -20]), $f([]))->toArray(), 'the rule deleted');
        self::assertSame(UndoKind::Nothing, UndoAction::inverse(Provider::Pricing, $f(['floor_pct' => -20]), $f(['floor_pct' => -20]))->kind);
        self::assertEquals(UndoAction::inverse(Provider::Pricing, $f(['floor_pct' => -20]), $f(['floor_pct' => -29])), UndoAction::fromArray(['kind' => 'write_fields', 'fields' => ['floor_pct' => -20]]));
    }

    public function testARecordNamesItsActorAndKeepsItsFieldToItsSystem(): void
    {
        $make = static fn (string $actor, string $field): ChangeRecord => new ChangeRecord('inn', Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), $field, 1, 2, new UndoAction(UndoKind::Nothing), $actor, self::at());

        self::assertSame('user:12', $make(ChangeRecord::userActor(12), ChangeField::MIN_STAY)->actor);
        foreach ([['nobody', ChangeField::MIN_STAY], ['autonomous', ChangeField::PMS_MIN_STAY]] as [$actor, $field]) {
            try {
                $make($actor, $field);
                self::fail("$actor / $field");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, int> $sent
     * @param array<string, int> $before
     */
    private static function night(WriteKind $kind, array $sent, array $before = []): WrittenNight
    {
        return new WrittenNight(Provider::Pricing, 'Deluxe', Days::date('2027-03-10'), $kind, new NightFields($sent), new NightFields($before), self::at(), 'g1', 'pace_step', 'Pace step', 'order-1', 'op-4');
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-03-03T12:00:00Z');
    }
}
