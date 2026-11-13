<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Grid;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Grid\Approvals\EntryRow;
use Ritmo\Application\Grid\Approvals\EntryWarnings;
use Ritmo\Application\Grid\Approvals\Impact;
use Ritmo\Application\Grid\Approvals\RelationHints;
use Ritmo\Application\Grid\Approvals\Segmenter;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\Relations\RelationsSample;

/**
 * The pure parts of the approval screen: warnings, segments, impact and relation hints.
 */
final class ApprovalPartsTest extends TestCase
{
    public function testWarningsOfANight(): void
    {
        $today = Days::date('2026-11-13');

        self::assertSame([EntryWarnings::TONIGHT, EntryWarnings::ORDER], EntryWarnings::of($today, $today, '', false, false, 'order-1', false));
        self::assertSame([EntryWarnings::TOMORROW, EntryWarnings::HOLIDAY], EntryWarnings::of(Days::date('2026-11-14'), $today, 'Example holiday', false, false, null, false));
        self::assertSame([EntryWarnings::GROUP, EntryWarnings::RELATION, EntryWarnings::HELD], EntryWarnings::of(Days::date('2026-11-20'), $today, '', true, true, null, true));
        self::assertSame('holiday:Example holiday', EntryWarnings::special(Days::date('2026-11-20'), $today, 'Example holiday'));
        self::assertSame('', EntryWarnings::special(null, $today, 'Example holiday'), 'a listing value has no night');
    }

    public function testSegmentsBreakOnRoomGapReasonDirectionAndSpecialNights(): void
    {
        $rows = [
            self::row('B', '2026-11-20', 'PACE_STEP', EntryRow::DOWN),
            self::row('A', '2026-11-21', 'PACE_STEP', EntryRow::DOWN),
            self::row('A', '2026-11-20', 'PACE_STEP', EntryRow::DOWN),
            self::row('A', '2026-11-22', 'LADDER_PRICE', EntryRow::DOWN),
            self::row('A', '2026-11-23', 'LADDER_PRICE', EntryRow::UP),
            self::row('A', '2026-11-25', 'LADDER_PRICE', EntryRow::UP),
            self::row('A', '2026-11-26', 'LADDER_PRICE', EntryRow::UP, 'holiday:Example'),
        ];

        $segments = Segmenter::segments($rows, ['A', 'B']);

        self::assertSame([2, 1, 1, 1, 1, 1], array_map(static fn ($s): int => $s->count(), $segments));
        self::assertSame('A', $segments[0]->room);
        self::assertSame('2026-11-20', $segments[0]->first()->format('Y-m-d'));
        self::assertSame('2026-11-21', $segments[0]->last()->format('Y-m-d'));
        self::assertSame('B', $segments[5]->room, 'rooms in the property order');
        self::assertSame([400.0, 400.0], $segments[0]->beforeRange());
        self::assertSame([380.0, 380.0], $segments[0]->afterRange());
        self::assertEqualsWithDelta(-5.0, (float) $segments[0]->medianChange(), 0.0001);
    }

    public function testTheImpactCountsNightsUpAndDownAndTheLargestRise(): void
    {
        $impact = Impact::of([
            self::row('A', '2026-11-20', 'PACE_STEP', EntryRow::DOWN),
            self::row('A', '2026-11-21', 'PACE_STEP', EntryRow::UP, '', 400.0, 460.0),
            self::row('B', '2026-11-21', 'PACE_STEP', EntryRow::UP, '', 400.0, 420.0),
        ]);

        self::assertSame(3, $impact->nights);
        self::assertSame(2, $impact->up);
        self::assertSame(1, $impact->down);
        self::assertEqualsWithDelta(10.0, (float) $impact->medianUp, 0.0001);
        self::assertSame(60.0, $impact->largestRise['amount'] ?? null);
        self::assertSame(0, $impact->listing);
    }

    public function testRelationHintsFindLinkedRoomsAndTheSplitOfASelection(): void
    {
        $relation = RelationsSample::relation('high-over-low', 'at_least', 'HIGH', ['reference' => 'LOW', 'margin' => ['type' => 'amount', 'value' => 10.0]]);
        $hints = new RelationHints([$relation], ['HIGH' => [], 'LOW' => [], 'OTHER' => ['tub']], static fn (DateTimeImmutable $n): NightType => NightType::Weekday, Days::date('2026-11-13'));
        $rows = [self::row('HIGH', '2026-11-20', 'PACE_STEP', EntryRow::DOWN), self::row('LOW', '2026-11-20', 'PACE_STEP', EntryRow::DOWN), self::row('OTHER', '2026-11-20', 'PACE_STEP', EntryRow::DOWN)];

        self::assertSame([['relation' => $relation->label, 'night' => '2026-11-20', 'rooms' => ['HIGH', 'LOW']]], $hints->pairs($rows));
        self::assertCount(1, $hints->split($rows, ['item|HIGH|2026-11-20']));
        self::assertSame([], $hints->split($rows, ['item|HIGH|2026-11-20', 'item|LOW|2026-11-20']));
        self::assertSame([], $hints->split($rows, ['item|OTHER|2026-11-20']));
    }

    private static function row(string $room, string $night, string $reason, string $direction, string $special = '', float $before = 400.0, float $after = 380.0): EntryRow
    {
        return new EntryRow('item|' . $room . '|' . $night, 'item', $room, Days::date($night), $night, 'night|' . $room . '|' . $night, ['floor_pct' => -30], null, $direction, $before, $after, -30, null, null, null, $reason, null, [], $special, [], false);
    }
}
