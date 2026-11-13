<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LightRoundRequest;
use Ritmo\Application\Schedule\LightRoundService;
use Ritmo\Application\Schedule\MovementTracker;
use Ritmo\Application\Schedule\RoomQuota;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\Support\PmsWritesUnavailable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\LightTrigger;
use Ritmo\Domain\Operations\Movement;
use Ritmo\Domain\Operations\MovementKind;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Tests\Fakes\ClockSleeper;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;

/**
 * The light run's reads and scope (operations spec 4.2 to 4.7; L2 to L11).
 */
final class LightRunTest extends TestCase
{
    private ScheduleWorld $world;

    protected function setUp(): void
    {
        $this->world = new ScheduleWorld('2026-11-12T16:00:00Z');
    }

    /**
     * @return list<RoomType>
     */
    private static function rooms(): array
    {
        return [
            new RoomType('inn', 'room-a', 'Room A', 2, 'pms-a', 'pl-a', 500.0, 300.0, 1500.0, 300.0),
            new RoomType('inn', 'room-b', 'Room B', 1, 'pms-b', 'pl-b', 500.0, 300.0, 1500.0, 300.0),
        ];
    }

    private function tracker(): MovementTracker
    {
        return new MovementTracker($this->world->states, new BookingMovementRepository($this->world->db), $this->world->alerts->service, new ClockSleeper($this->world->clock), $this->world->clock);
    }

    /**
     * @param list<list<BookingRecord>|ConnectorException> $answers one per read
     */
    private static function pms(array $answers): PmsConnector
    {
        return new class ($answers) implements PmsConnector {
            use PmsWritesUnavailable;

            /** @var list<DateTimeImmutable> */
            public array $reads = [];

            /**
             * @param list<list<BookingRecord>|ConnectorException> $answers
             */
            public function __construct(private array $answers)
            {
            }

            public function id(): string
            {
                return 'pms';
            }

            public function testConnection(): ConnectionStatus
            {
                return new ConnectionStatus(true, 'ok');
            }

            public function readBookingsByStay(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function readBookingsModifiedSince(DateTimeImmutable $instant): array
            {
                $this->reads[] = $instant;
                $answer = array_shift($this->answers) ?? [];
                if ($answer instanceof ConnectorException) {
                    throw $answer;
                }

                return $answer;
            }

            public function readInventory(array $roomRefs, DateTimeImmutable $from, DateTimeImmutable $to, bool $withPrices = false): array
            {
                return [];
            }
        };
    }

    public function testL11TheFirstReadIsABaselineAndSetsTheCursor(): void
    {
        $read = $this->tracker()->read('inn', self::pms([[MovementDetectorTest::booking('1')]]), self::rooms(), new LightRunConfig(), 30);

        self::assertTrue($read->succeeded());
        self::assertTrue($read->window->baseline);
        self::assertSame([], $read->batch?->movements);
        self::assertSame('2026-11-12T16:00:00+00:00', $this->world->states->get('inn')->movementCursor?->format(DATE_ATOM));
    }

    public function testL3TheOverlapNeverCountsABookingTwice(): void
    {
        $this->world->states->setMovementCursor('inn', new DateTimeImmutable('2026-11-12T13:00:00Z'));
        $sale = MovementDetectorTest::booking('2', created: '2026-11-12T15:58:00Z');
        $first = $this->tracker()->read('inn', self::pms([[$sale]]), self::rooms(), new LightRunConfig(movementOverlapMinutes: 5), 30);
        $this->world->clock->advance(3 * 3600);
        $pms = self::pms([[$sale]]);
        $second = $this->tracker()->read('inn', $pms, self::rooms(), new LightRunConfig(movementOverlapMinutes: 5), 30);

        self::assertSame(MovementKind::New, $first->batch?->movements[0]->kind);
        self::assertSame([], $second->batch?->movements, 'O12');
        self::assertSame('2026-11-12T15:55:00+00:00', $pms->reads[0]->format(DATE_ATOM), 'O11: cursor minus the overlap');
    }

    public function testL2TwoFailedReadsLeaveTheBookingsUnwatchedAndTheCursorWhereItWas(): void
    {
        $this->world->states->setMovementCursor('inn', new DateTimeImmutable('2026-11-12T13:00:00Z'));
        $failure = new ConnectorException('Timeout.', 'pms', null, true);
        $read = $this->tracker()->read('inn', self::pms([$failure, $failure]), self::rooms(), new LightRunConfig(), 30);

        self::assertFalse($read->succeeded());
        self::assertSame('2026-11-12T13:00:00+00:00', $this->world->states->get('inn')->movementCursor?->format(DATE_ATOM));
        self::assertSame(['connector_down:inn:pms'], $this->world->openAlerts('connector_down'));
        self::assertStringContainsString('Vigilance blind', $this->world->alerts->ledger->latestByKey('connector_down:inn:pms')?->message ?? '');
        self::assertCount(1, $this->world->alerts->mailer->sent, 'critical: e-mailed at once');

        $ok = $this->tracker()->read('inn', self::pms([[]]), self::rooms(), new LightRunConfig(), 30);
        self::assertTrue($ok->succeeded());
        self::assertSame([], $this->world->openAlerts('connector_down'), 'a good read resolves it');
    }

    public function testAFailureIsRetriedOnceAfterTenSeconds(): void
    {
        $this->world->states->setMovementCursor('inn', new DateTimeImmutable('2026-11-12T13:00:00Z'));
        $read = $this->tracker()->read('inn', self::pms([new ConnectorException('Timeout.', 'pms', null, true), []]), self::rooms(), new LightRunConfig(), 30);

        self::assertTrue($read->succeeded());
        self::assertSame('2026-11-12T16:00:10+00:00', $this->world->clock->now()->format(DATE_ATOM));
    }

    public function testALongStopRaisesTheVigilanceGap(): void
    {
        $this->world->states->setMovementCursor('inn', new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $this->tracker()->read('inn', self::pms([[]]), self::rooms(), new LightRunConfig(), 30);

        self::assertCount(1, $this->world->openAlerts('vigilance_gap'));
    }

    private function service(): LightRoundService
    {
        return new LightRoundService(new InventorySnapshotRepository($this->world->db), $this->world->alerts->service);
    }

    /**
     * @param array<string, int> $available night => units free
     *
     * @return array<string, InventoryNight>
     */
    private static function nights(array $available): array
    {
        $out = [];
        foreach ($available as $night => $units) {
            $out[$night] = new InventoryNight(Days::date($night), $units);
        }

        return $out;
    }

    private static function quota(array $rooms): RoomQuota
    {
        return new class ($rooms) implements RoomQuota {
            public function __construct(private readonly array $rooms)
            {
            }

            public function permits(string $roomCode): bool
            {
                return in_array($roomCode, $this->rooms, true);
            }
        };
    }

    public function testL7AndL9ASaleSellsOutTodayAndARoomWithoutQuotaKeepsOnlyItsProtection(): void
    {
        $snapshots = new InventorySnapshotRepository($this->world->db);
        $snapshots->store('room-b', array_values(self::nights(['2026-11-12' => 1, '2026-11-13' => 1])), new DateTimeImmutable('2026-11-12T10:30:00Z'));
        $sale = new Movement(MovementKind::New, MovementDetectorTest::booking('3', '2026-11-12', '2026-11-14', BookingStatus::Confirmed, 'room-b'));
        $request = new LightRoundRequest('inn', ['room-a', 'room-b'], Days::date('2026-11-12'), 60, [$sale], ['room-b' => self::nights(['2026-11-12' => 0, '2026-11-13' => 1])], new DateTimeImmutable('2026-11-12T16:00:00Z'), [], 0, new LightRunConfig());

        $plan = $this->service()->plan($request, self::quota(['room-a']));

        self::assertSame([LightTrigger::Movement, LightTrigger::SoldOutFlip], $plan->triggers);
        self::assertSame(['room-b|2026-11-12'], array_keys($plan->filter), 'the sold-out night is protected, the other waits');
        self::assertSame(['room-b'], $plan->safetyOnlyRooms);
        self::assertSame(['recalc_quota_exhausted:inn:room-b'], $this->world->openAlerts('recalc_quota_exhausted'));

        $this->service()->plan($request, self::quota(['room-a', 'room-b']));
        self::assertSame([], $this->world->openAlerts('recalc_quota_exhausted'), 'resolved when the room has quota again');
    }

    public function testL1NothingMovedNothingToDo(): void
    {
        $request = new LightRoundRequest('inn', ['room-a'], Days::date('2026-11-12'), 60, [], ['room-a' => self::nights(['2026-11-12' => 1])], new DateTimeImmutable('2026-11-12T16:00:00Z'), [], 0, new LightRunConfig());

        self::assertFalse($this->service()->plan($request, self::quota(['room-a']))->continues());
    }
}
