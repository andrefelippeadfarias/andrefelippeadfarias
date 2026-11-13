<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\ChangeRecorder;
use Ritmo\Domain\Write\Change\UndoDraft;
use Ritmo\Domain\Write\Change\UndoPlanner;
use Ritmo\Domain\Write\Change\UndoSkip;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;
use Ritmo\Tests\Unit\Domain\EngineSample;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * Undo is a plan draft built from the state read now; it never overwrites a night changed since
 * (undo_conflict) and goes through the Validator like any plan (docs/CHANGELOG-UNDO.md).
 */
final class UndoPlannerTest extends TestCase
{
    private const NIGHT = '2027-03-10';

    public function testAChangedFloorIsWrittenBackAsTheSamePercentage(): void
    {
        $records = self::records([ChangeField::FLOOR_PCT => -29], [ChangeField::FLOOR_PCT => -20, ChangeField::MIN_STAY => 2]);
        $draft = self::plan($records, [ChangeField::FLOOR_PCT => -29, ChangeField::MIN_STAY => 2]);

        self::assertCount(1, $draft->plan->items);
        self::assertSame([], $draft->plan->deletions);
        $item = $draft->plan->items[0];
        self::assertSame(UndoPlanner::REASON, $item->reasonCode);
        self::assertSame(-20, (new PercentRounding())->convert($item, EngineSample::deluxe())->minPercent);
        self::assertNull($item->minStay, 'only the changed field');
        self::assertSame([11], $draft->undoOf);
    }

    public function testAFieldAddedIsUndoneByDeletingAndWritingTheOldRule(): void
    {
        $records = self::records([ChangeField::CEILING_PCT => 5], [ChangeField::FLOOR_PCT => -20]);
        $draft = self::plan($records, [ChangeField::FLOOR_PCT => -20, ChangeField::CEILING_PCT => 5]);

        self::assertCount(1, $draft->plan->deletions);
        self::assertCount(1, $draft->plan->items);
        self::assertNull($draft->plan->items[0]->maxPrice);
        self::assertSame(-20, (new PercentRounding())->convert($draft->plan->items[0], EngineSample::deluxe())->minPercent);
    }

    public function testARuleThatDidNotExistIsDeleted(): void
    {
        $draft = self::plan(self::records([ChangeField::FLOOR_PCT => -29]), [ChangeField::FLOOR_PCT => -29]);

        self::assertCount(1, $draft->plan->deletions);
        self::assertSame([], $draft->plan->items);
    }

    public function testANightChangedSinceIsNeverOverwritten(): void
    {
        $draft = self::plan(self::records([ChangeField::FLOOR_PCT => -29], [ChangeField::FLOOR_PCT => -20]), [ChangeField::FLOOR_PCT => -25]);

        self::assertTrue($draft->isEmpty());
        self::assertSame(UndoSkip::CONFLICT, $draft->skipped[0]->reason);
        self::assertSame([ChangeField::FLOOR_PCT], $draft->skipped[0]->fields);
    }

    public function testUndoneOrPastNightsAndSeveralChangesAreSkipped(): void
    {
        $records = self::records([ChangeField::FLOOR_PCT => -29], [ChangeField::FLOOR_PCT => -20]);
        $undone = array_map(static fn (ChangeRecord $r): ChangeRecord => new ChangeRecord($r->propertyId, $r->provider, $r->roomCode, $r->night, $r->field, $r->oldValue, $r->newValue, $r->undo, $r->actor, $r->createdAt, id: 12, undoneAt: new DateTimeImmutable('2027-03-03T13:00:00Z')), $records);
        $later = (new ChangeRecorder('inn', 'user:3'))->night(self::written([ChangeField::FLOOR_PCT => -31], [ChangeField::FLOOR_PCT => -29]), new NightFields([ChangeField::FLOOR_PCT => -31]), 1000.0, new DateTimeImmutable('2027-03-03T13:00:00Z'));

        self::assertSame(UndoSkip::ALREADY_UNDONE, self::plan($undone, [ChangeField::FLOOR_PCT => -29])->skipped[0]->reason);
        self::assertSame(UndoSkip::PAST_NIGHT, self::plan($records, [ChangeField::FLOOR_PCT => -29], '2027-03-11')->skipped[0]->reason);
        self::assertSame(UndoSkip::SEVERAL_CHANGES, self::plan([...$records, ...$later], [ChangeField::FLOOR_PCT => -31])->skipped[0]->reason);
    }

    public function testAThirdPartyAmountComesBackAsTheNearestPercentage(): void
    {
        $records = self::records([ChangeField::FLOOR_PCT => -29], [ChangeField::FLOOR_FIXED => 812.4]);
        $draft = self::plan($records, [ChangeField::FLOOR_PCT => -29, ChangeField::FLOOR_FIXED => 812.4]);

        self::assertSame(-18, (new PercentRounding())->convert($draft->plan->items[0], EngineSample::deluxe())->minPercent);
        self::assertCount(1, $draft->notes);
    }

    public function testPmsFieldsBecomeRestoresAndListingValuesListingChanges(): void
    {
        $pms = (new ChangeRecorder('inn', 'user:3'))->night(
            new WrittenNight(Provider::Pms, 'Deluxe', Days::date(self::NIGHT), WriteKind::Write, new NightFields([ChangeField::PMS_RESTRICTION => 'none']), new NightFields([ChangeField::PMS_RESTRICTION => 'no_checkin']), self::at()),
            new NightFields([ChangeField::PMS_RESTRICTION => 'none']),
            1000.0,
            self::at(),
        );
        $listing = (new ChangeRecorder('inn', 'user:3'))->listing('Deluxe', ListingField::Min, 600.0, 650.0, self::at(), 'listing_review', '', 'order-2');
        $draft = (new UndoPlanner())->plan([...$pms, $listing], [
            'pms|Deluxe|' . self::NIGHT => new NightFields([ChangeField::PMS_RESTRICTION => 'none', ChangeField::PMS_AVAILABLE => 2]),
            'listing|Deluxe' => new NightFields([ChangeField::LISTING_MIN => 650.0]),
        ], ['Deluxe' => EngineSample::deluxe()], 'inn', Days::date(PlanSample::TODAY), self::at());

        self::assertSame([ChangeField::PMS_RESTRICTION => 'no_checkin'], $draft->pmsRestores[0]->fields->all());
        self::assertSame([ListingField::Min, 600.0, 'order-2'], [$draft->plan->listingChanges[0]->field, $draft->plan->listingChanges[0]->value, $draft->plan->listingChanges[0]->orderId]);
    }

    public function testAnUndoThatWouldRaiseTonightIsRefusedByTheValidator(): void
    {
        $tonight = PlanSample::TODAY;
        $records = (new ChangeRecorder('inn', ChangeRecord::ACTOR_AUTONOMOUS, 5))->night(
            new WrittenNight(Provider::Pricing, 'Deluxe', Days::date($tonight), WriteKind::Write, new NightFields([ChangeField::FLOOR_PCT => -30]), new NightFields([ChangeField::FLOOR_PCT => -10]), self::at()),
            new NightFields([ChangeField::FLOOR_PCT => -30]),
            1000.0,
            self::at(),
        );
        $draft = (new UndoPlanner())->plan($records, ['pricing|Deluxe|' . $tonight => new NightFields([ChangeField::FLOOR_PCT => -30])], ['Deluxe' => EngineSample::deluxe()], 'inn', Days::date($tonight), PlanSample::clock()->now());
        $calendar = new FreshCalendar([PlanItem::keyOf('Deluxe', Days::date($tonight)) => new CalendarNight(700.0, 700.0, 1, 4)]);
        $result = (new Validator(EngineSample::rules(), PlanSample::clock()))->validate($draft->plan, EngineSample::property(), [EngineSample::deluxe(), EngineSample::standard()], new ValidationContext($calendar));

        self::assertContains(PlanIssue::RAISE_TODAY, $result->errorCodes(), 'the floor back at 900 would raise tonight from 700');
    }

    /**
     * @param array<string, int|float> $sent
     * @param array<string, int|float> $before
     *
     * @return list<ChangeRecord>
     */
    private static function records(array $sent, array $before = []): array
    {
        $rows = (new ChangeRecorder('inn', ChangeRecord::ACTOR_AUTONOMOUS, 5))->night(self::written($sent, $before), (new NightFields($before))->merge(new NightFields($sent)), 1000.0, self::at());

        return array_map(static fn (ChangeRecord $r, int $i): ChangeRecord => new ChangeRecord($r->propertyId, $r->provider, $r->roomCode, $r->night, $r->field, $r->oldValue, $r->newValue, $r->undo, $r->actor, $r->createdAt, $r->oldAmount, $r->newAmount, $r->reasonCode, $r->reasonText, $r->orderId, $r->runId, id: 11 + $i), $rows, array_keys($rows));
    }

    /**
     * @param array<string, int|float> $sent
     * @param array<string, int|float> $before
     */
    private static function written(array $sent, array $before): WrittenNight
    {
        return new WrittenNight(Provider::Pricing, 'Deluxe', Days::date(self::NIGHT), WriteKind::Write, new NightFields($sent), new NightFields($before), self::at(), reasonCode: 'pace_step');
    }

    /**
     * @param list<ChangeRecord>       $records
     * @param array<string, int|float> $now
     */
    private static function plan(array $records, array $now, string $today = PlanSample::TODAY): UndoDraft
    {
        $rooms = ['Deluxe' => EngineSample::deluxe(), 'Standard' => EngineSample::standard()];

        return (new UndoPlanner())->plan($records, ['pricing|Deluxe|' . self::NIGHT => new NightFields($now)], $rooms, 'inn', Days::date($today), self::at());
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-03-03T12:00:00Z');
    }
}
