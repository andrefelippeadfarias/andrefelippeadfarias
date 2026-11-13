<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Operations\BookingVersion;
use Ritmo\Domain\Operations\MovementKind;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\SlotPlanner;
use Ritmo\Domain\Operations\SlotRecord;
use Ritmo\Domain\Operations\SlotStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;

final class ScheduleRepositoriesTest extends TestCase
{
    private PdoDatabase $db;

    private MovableClock $clock;

    protected function setUp(): void
    {
        $this->db = OpsDatabase::create();
        $this->clock = new MovableClock('2026-11-12T22:05:00Z');
    }

    private function slots(): ScheduledRunRepository
    {
        return new ScheduledRunRepository($this->db, $this->clock);
    }

    private static function planner(): SlotPlanner
    {
        return new SlotPlanner(ScheduleConfig::fromArray(['light_times' => ['12:30', '18:30']]), 'America/Sao_Paulo');
    }

    public function testASlotOccurrenceIsRecordedOnce(): void
    {
        $slot = self::planner()->slotsOn(Days::date('2026-11-12'))[0];

        self::assertTrue($this->slots()->materialise('inn', $slot));
        self::assertFalse($this->slots()->materialise('inn', $slot), 'a second tick creates no second row (O3)');
        self::assertTrue($this->slots()->materialise('annex', $slot));
        self::assertCount(1, $this->slots()->latest('inn'));
    }

    public function testDueSlotsAreTheOnesUpToNowOldestFirst(): void
    {
        foreach (self::planner()->slotsBetween(new DateTimeImmutable('2026-11-12T10:00:00Z'), new DateTimeImmutable('2026-11-13T22:00:00Z')) as $slot) {
            $this->slots()->materialise('inn', $slot);
        }
        $due = $this->slots()->due('inn', $this->clock->now());

        self::assertSame(['2026-11-12T07:30', '2026-11-12T12:30', '2026-11-12T18:30'], array_map(static fn (SlotRecord $r): string => $r->slot->localKey(), $due));
        self::assertSame('2026-11-12T10:30:00+00:00', $this->slots()->oldestDueAt(null, $this->clock->now())?->format(DATE_ATOM));
        self::assertNull($this->slots()->oldestDueAt('annex', $this->clock->now()));
    }

    public function testAStartedSlotKeepsItsRunAndItsDelayAndEndsWithAStatus(): void
    {
        $slot = self::planner()->slotsOn(Days::date('2026-11-12'))[2];
        $this->slots()->materialise('inn', $slot);
        $id = $this->slots()->findByKey('inn', $slot)?->id ?? 0;

        $this->slots()->started($id, 41, $this->clock->now(), 2100, true, SlotStatus::Running);
        self::assertSame([SlotStatus::Running, 41, 2100, true], self::facts($this->slots()->forRun(41)));
        self::assertSame([], $this->slots()->due('inn', $this->clock->now()));

        $this->slots()->close($id, SlotStatus::Completed, $this->clock->now(), str_repeat('n', 300));
        $closed = $this->slots()->find($id);
        self::assertSame(SlotStatus::Completed, $closed?->status);
        self::assertSame(190, strlen($closed->note));
        self::assertSame(0, $this->slots()->purgeBefore(new DateTimeImmutable('2026-11-12T00:00:00Z')));
        self::assertSame(1, $this->slots()->purgeBefore(new DateTimeImmutable('2026-11-13T00:00:00Z')));
    }

    /**
     * @return array{SlotStatus, int|null, int|null, bool}|null
     */
    private static function facts(?SlotRecord $record): ?array
    {
        return $record === null ? null : [$record->status, $record->runId, $record->delaySeconds, $record->promoted];
    }

    public function testTheOperationStateStartsNeutralAndKeepsPauseAndCursors(): void
    {
        $states = new OperationStateRepository($this->db, $this->clock);
        self::assertFalse($states->get('inn')->paused);

        $states->pause('inn', new DateTimeImmutable('2026-11-13T18:00:00Z'), 7, 'Renovation');
        $states->setMovementCursor('inn', new DateTimeImmutable('2026-11-12T22:00:00Z'));
        $states->setSlotsConsideredUntil('inn', new DateTimeImmutable('2026-11-12T22:05:00Z'));
        $state = $states->get('inn');
        self::assertTrue($state->isPausedAt($this->clock->now()));
        self::assertFalse($state->pauseEndedAt($this->clock->now()));
        self::assertTrue($state->pauseEndedAt(new DateTimeImmutable('2026-11-13T18:00:00Z')));
        self::assertSame([7, 'Renovation', '2026-11-12T22:00:00+00:00'], [$state->pausedBy, $state->pauseReason, $state->movementCursor?->format(DATE_ATOM)]);

        $states->resume('inn');
        self::assertFalse($states->get('inn')->paused);
        self::assertNotNull($states->get('inn')->movementCursor, 'resuming keeps the cursors');
    }

    public function testProcessedBookingVersionsAreKeptOncePerFingerprint(): void
    {
        $ledger = new BookingMovementRepository($this->db);
        $first = BookingVersion::of(MovementDetectorTest::booking('1'));
        $changed = BookingVersion::of(MovementDetectorTest::booking('1', status: BookingStatus::Cancelled, modified: '2026-11-12T20:00:00Z'));

        $ledger->record('inn', 'pms', [[$first, MovementKind::New]], new DateTimeImmutable('2026-11-12T15:00:00Z'), 3);
        $ledger->record('inn', 'pms', [[$first, MovementKind::New]], new DateTimeImmutable('2026-11-12T16:00:00Z'), 4);
        $ledger->record('inn', 'pms', [[$changed, MovementKind::Cancelled]], new DateTimeImmutable('2026-11-12T21:00:00Z'), 5);

        self::assertSame(2, (int) $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_booking_movements'));
        $last = $ledger->lastVersions('inn', 'pms', ['1', '2']);
        self::assertSame(['1'], array_map('strval', array_keys($last)));
        self::assertSame($changed->fingerprint(), $last['1']->fingerprint());
        self::assertSame([], $ledger->lastVersions('annex', 'pms', ['1']), 'the ledger is per property');
        self::assertSame(2, $ledger->purgeDepartedBefore(Days::date('2026-11-23')));
    }

    public function testTheMovementTableHasNoColumnForGuestData(): void
    {
        $columns = (new SchemaInspector($this->db))->columns('booking_movements');

        foreach ($columns as $column) {
            self::assertDoesNotMatchRegularExpression('/name|mail|phone|address|note|comment|description|document/i', $column);
        }
        self::assertContains('fingerprint', $columns);
    }
}
