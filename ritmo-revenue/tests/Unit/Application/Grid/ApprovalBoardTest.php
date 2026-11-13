<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Grid;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Grid\Approvals\ApprovalBoard;
use Ritmo\Application\Grid\Approvals\EntryRow;
use Ritmo\Application\Grid\Approvals\EntryWarnings;
use Ritmo\Application\Grid\Approvals\Segment;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;

final class ApprovalBoardTest extends TestCase
{
    private ScreenWorld $world;

    protected function setUp(): void
    {
        $this->world = new ScreenWorld();
        $this->world->property();
        $this->world->room('A');
        $this->world->room('B');
    }

    public function testARequestIsShownInMoneyWithSegmentsSmallerThanItsEntries(): void
    {
        $run = $this->world->run(['A', 'B'], 30, $this->statuses(['A', 'B'], 30));
        $id = $this->request($run, $this->entries(['A', 'B'], 30));

        $view = $this->board()->waiting('inn')[0];

        self::assertSame($id, $view->id);
        self::assertSame(60, $view->entries);
        self::assertLessThan(60, $view->segmentCount());
        $sum = 0;
        foreach ($view->rooms as $segments) {
            $sum += array_sum(array_map(static fn (Segment $s): int => $s->count(), $segments));
        }
        self::assertSame(60, $sum, 'every entry is in exactly one segment');
        $first = $view->rooms['A'][0];
        self::assertSame(EntryRow::DOWN, $first->direction());
        self::assertSame(480.0, $first->entries[0]->before());
        self::assertSame(420.0, $first->entries[0]->after());
        self::assertEqualsWithDelta(-12.5, (float) $first->medianChange(), 0.001);
        self::assertSame(60, $view->impact->down);
        self::assertSame(4, $view->impact->soon, 'tonight and tomorrow of two rooms');
    }

    public function testTonightAndTomorrowHaveTheirOwnSegmentsAndWarnings(): void
    {
        $run = $this->world->run(['A'], 5, $this->statuses(['A'], 5));
        $this->request($run, $this->entries(['A'], 5));

        $segments = $this->board()->waiting('inn')[0]->rooms['A'];

        self::assertCount(3, $segments);
        self::assertSame([EntryWarnings::TONIGHT], $segments[0]->warnings());
        self::assertSame([EntryWarnings::TOMORROW], $segments[1]->warnings());
        self::assertSame(3, $segments[2]->count());
    }

    public function testADeletionAndItsRewriteGoTogether(): void
    {
        $run = $this->world->run(['A'], 10, $this->statuses(['A'], 10));
        $entries = $this->entries(['A'], 10);
        $entries[] = (new PlanEntry(PlanEntry::DELETION, 'A', '2026-11-20'))->toArray();
        $id = $this->request($run, $entries);

        $view = $this->board()->waiting('inn')[0];
        $rewrite = [];
        foreach ($view->rooms['A'] as $segment) {
            foreach ($segment->entries as $entry) {
                if ($entry->direction === EntryRow::REWRITE) {
                    $rewrite[] = $entry;
                }
            }
        }
        self::assertCount(2, $rewrite);
        self::assertContains(EntryWarnings::GROUP, $rewrite[0]->warnings);
        self::assertSame(1, $view->impact->rewrites);

        $selection = $this->board()->selection('inn', $id, ['deletion|A|2026-11-20']);
        self::assertSame(['item|A|2026-11-20'], $selection['added']);
        self::assertCount(2, $selection['approved']);
    }

    public function testASelectionWithAnUnknownKeyIsRefused(): void
    {
        $run = $this->world->run(['A'], 5, $this->statuses(['A'], 5));
        $id = $this->request($run, $this->entries(['A'], 5));

        $this->expectException(ApprovalRefused::class);
        $this->board()->selection('inn', $id, ['item|A|2030-01-01']);
    }

    public function testListingChangesAreApartWithTheStoredValueBefore(): void
    {
        $id = $this->request(null, [(new PlanEntry(PlanEntry::LISTING, 'A', 'base', ['value' => 650]))->toArray()], 'listing');

        $view = $this->board()->find('inn', $id);

        self::assertSame([], $view->rooms);
        self::assertCount(1, $view->listing);
        self::assertSame(600.0, $view->listing[0]->before());
        self::assertSame(650.0, $view->listing[0]->after());
        self::assertSame(1, $view->impact->listing);
    }

    public function testWithoutThePlanItemsTheSentPercentStandsForTheAmount(): void
    {
        $this->request(null, [(new PlanEntry(PlanEntry::ITEM, 'B', '2026-11-25', ['floor_pct' => -20, 'ceiling_pct' => 40]))->toArray()]);

        $row = $this->board()->waiting('inn')[0]->rooms['B'][0]->entries[0];

        self::assertSame(480.0, $row->floorAfter);
        self::assertSame(840.0, $row->ceilingAfter);
        self::assertSame(EntryRow::BOUNDS, $row->direction, 'nothing known before');
    }

    public function testANightWithoutADateRuleIsComparedWithTheListingValues(): void
    {
        $run = (new RunRepository($this->world->raw))->start('inn', 'shadow', PropertyMode::Shadow, null, $this->world->clock->now()->modify('-50 minutes'));
        $items = new PlanItemRepository($this->world->raw, $this->world->clock);
        $night = Days::date('2026-11-20');
        $items->add($run, 'inn', 'A', $night, 'floor', null, 432.0, 'PACE_STEP', ['min_percent' => -28], status: 'would_write');
        $items->add($run, 'inn', 'A', $night, 'ceiling', null, 1080.0, 'PACE_STEP', ['max_percent' => 80], status: 'would_write');
        $this->request($run, [(new PlanEntry(PlanEntry::ITEM, 'A', '2026-11-20', ['floor_pct' => -28, 'ceiling_pct' => 80]))->toArray()]);

        $view = $this->board()->waiting('inn')[0];
        $row = $view->rooms['A'][0]->entries[0];

        self::assertTrue($row->beforeFromListing);
        self::assertSame(400.0, $row->before(), 'the listing minimum applies without a date rule');
        self::assertSame(1200.0, $row->ceilingBefore, 'the listing maximum');
        self::assertSame(EntryRow::UP, $row->direction);
        self::assertSame(1, $view->impact->up);
        self::assertEqualsWithDelta(8.0, (float) $row->changePercent(), 0.0001);
    }

    public function testTheStatusOfARequestAndARequestOfAnotherProperty(): void
    {
        $id = $this->request(null, $this->entries(['A'], 3));

        $status = $this->board()->status($id);
        self::assertSame('pending', $status['status'] ?? null);
        self::assertSame(3, $status['entries'] ?? null);
        self::assertNull($this->board()->status(999));

        $this->world->property('other');
        $this->expectException(\OutOfBoundsException::class);
        $this->board()->find('other', $id);
    }

    public function testTheStatementCountDoesNotGrowWithTheEntries(): void
    {
        $small = $this->world->run(['A', 'B'], 50, $this->statuses(['A', 'B'], 50));
        $this->request($small, $this->entries(['A', 'B'], 50));
        $this->world->db->statements = 0;
        $this->board()->waiting('inn');
        $hundred = $this->world->db->statements;

        $world = new ScreenWorld();
        $world->property();
        foreach (range(1, 12) as $n) {
            $world->room('R' . $n);
        }
        $rooms = array_map(static fn (int $n): string => 'R' . $n, range(1, 12));
        $big = $world->run($rooms, 170, []);
        $entries = $this->entries($rooms, 170);
        (new \Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository($world->raw))->create($big, 'inn', 'plan', str_repeat('a', 64), Days::date(ScreenWorld::TODAY), null, $world->clock->now(), $world->clock->now()->modify('+10 hours'), $entries, $world->clock->now());
        $world->db->statements = 0;
        $views = (new ApprovalBoard($world->properties(), $world->roomTypes(), new RuleSetRepository($world->db, $world->clock), $world->approvals(), new PlanItemRepository($world->db, $world->clock), new WaveHoldRepository($world->db), $world->clock))->waiting('inn');

        self::assertSame(2040, $views[0]->entries);
        self::assertSame($hundred, $world->db->statements);
    }

    private function board(): ApprovalBoard
    {
        return new ApprovalBoard($this->world->properties(), $this->world->roomTypes(), new RuleSetRepository($this->world->db, $this->world->clock), $this->world->approvals(), new PlanItemRepository($this->world->db, $this->world->clock), new WaveHoldRepository($this->world->db), $this->world->clock);
    }

    /**
     * @param list<string> $rooms
     *
     * @return array<string, string>
     */
    private function statuses(array $rooms, int $nights): array
    {
        $out = [];
        foreach ($rooms as $room) {
            foreach (DateRange::nights(Days::date(ScreenWorld::TODAY), $nights) as $night) {
                $out[$room . '|' . Days::key($night)] = 'would_write';
            }
        }

        return $out;
    }

    /**
     * @param list<string> $rooms
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $rooms, int $nights): array
    {
        $out = [];
        foreach ($rooms as $room) {
            foreach (DateRange::nights(Days::date(ScreenWorld::TODAY), $nights) as $night) {
                $out[] = (new PlanEntry(PlanEntry::ITEM, $room, Days::key($night), ['floor_pct' => -30, 'ceiling_pct' => 50]))->toArray();
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function request(?int $run, array $entries, string $kind = 'plan'): int
    {
        $now = $this->world->clock->now();

        return $this->world->approvals()->create($run, 'inn', $kind, str_repeat('a', 64), Days::date(ScreenWorld::TODAY), null, $now, $now->modify('+10 hours'), $entries, $now);
    }
}
