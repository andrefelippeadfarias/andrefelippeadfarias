<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Alerts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Alerts\WatchInput;
use Ritmo\Application\Alerts\WatchService;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\AlertsConfig;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;
use Ritmo\Tests\Unit\Infrastructure\Schedule\OpsDatabase;

/**
 * The watch of every run (O21 to O25, O30, O31, O34; A30 to A33).
 */
final class WatchServiceTest extends TestCase
{
    private PdoDatabase $db;

    private MovableClock $clock;

    private AlertsKit $kit;

    protected function setUp(): void
    {
        $this->db = OpsDatabase::create();
        $this->clock = new MovableClock('2026-11-12T15:00:00Z');
        $this->kit = new AlertsKit($this->db, $this->clock);
    }

    private function service(array $alerts = []): WatchService
    {
        return new WatchService($this->kit->service, AlertsConfig::fromArray($alerts), new CancellationWave(), new InventorySnapshotRepository($this->db));
    }

    /**
     * @param list<BookingRecord>                          $bookings
     * @param array<string, array<string, InventoryNight>> $inventory
     * @param array<string, array<string, int>>            $booked
     */
    private static function input(array $bookings = [], array $inventory = [], array $booked = []): WatchInput
    {
        return new WatchInput('inn', $bookings, new DateTimeImmutable('2026-11-12T13:40:00Z'), $inventory, $booked, ['room-a' => 2], new DateRange(Days::date('2026-11-12'), Days::date('2026-11-30')));
    }

    /**
     * @param array<string, array{0: int, 1?: float}> $table night => [available, price]
     *
     * @return array<string, InventoryNight>
     */
    private static function nights(array $table): array
    {
        $out = [];
        foreach ($table as $night => $row) {
            $out[$night] = new InventoryNight(Days::date($night), $row[0], pmsPrice: $row[1] ?? null);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function activeKeys(): array
    {
        return array_map(static fn (AlertRecord $a): string => $a->dedupeKey, $this->kit->ledger->active('inn', ['security_zero_value', 'security_cancellation_burst', 'booking_below_expected', 'overbooked', 'inventory_closed_without_booking']));
    }

    public function testSecurityFindingsAndBurstsAreRaisedAndTheWavesHandedOver(): void
    {
        $cancelled = array_map(
            static fn (int $i): BookingRecord => MovementDetectorTest::booking((string) (50 + $i), status: BookingStatus::Cancelled, created: '2026-11-01T10:00:00Z', modified: sprintf('2026-11-12T14:%02d:00Z', $i * 10), cancelled: sprintf('2026-11-12T14:%02d:00Z', $i * 10)),
            [0, 1, 2]
        );
        $outcome = $this->service()->watch(self::input([...$cancelled, MovementDetectorTest::booking('9', total: null)]));

        self::assertCount(1, $outcome->waves);
        self::assertSame(['50', '51', '52'], $outcome->waves[0]->bookingRefs);
        self::assertContains('security_zero_value:inn:9', $this->activeKeys());
        self::assertCount(2, array_filter($this->activeKeys(), static fn (string $k): bool => str_starts_with($k, 'security_')));
        self::assertSame(['Booking.com'], $outcome->originsSeen, 'learning: the origins are listed while unconfirmed');
    }

    public function testClosedNightsAreBlockedAndAlertedAndOverbookingResolvesWhenGone(): void
    {
        $inventory = ['room-a' => self::nights(['2026-11-20' => [0], '2026-11-21' => [-1], '2026-11-22' => [2]])];
        $outcome = $this->service()->watch(self::input([], $inventory, ['room-a' => ['2026-11-21' => 3]]));

        self::assertSame(['room-a|2026-11-20' => true], $outcome->blocked, 'two units off sale, none booked');
        self::assertSame(['overbooked:inn:room-a:2026-11-21', 'inventory_closed_without_booking:inn:room-a:2026-11-20'], $this->activeKeys());

        $this->service()->watch(self::input([], ['room-a' => self::nights(['2026-11-20' => [0], '2026-11-21' => [0]])], ['room-a' => ['2026-11-20' => 2, '2026-11-21' => 2]]));
        self::assertSame([], $this->activeKeys(), 'a later read without the conditions resolves them');
    }

    public function testARunThatDidNotReadARoomResolvesNothingOfIt(): void
    {
        $this->service()->watch(self::input([], ['room-a' => self::nights(['2026-11-21' => [-1]])], ['room-a' => ['2026-11-21' => 3]]));
        $this->service()->watch(self::input([], ['room-b' => self::nights(['2026-11-21' => [1]])]));

        self::assertSame(['overbooked:inn:room-a:2026-11-21'], $this->activeKeys());
    }

    public function testBelowExpectedComparesWithThePriceAtBookingTime(): void
    {
        // A33: the price was 500 when the booking was made and is 1000 now.
        $snapshots = new InventorySnapshotRepository($this->db);
        $snapshots->store('room-a', array_values(self::nights(['2026-11-20' => [2, 500.0], '2026-11-21' => [2, 500.0]])), new DateTimeImmutable('2026-11-12T13:00:00Z'));
        $inventory = ['room-a' => self::nights(['2026-11-20' => [1, 1000.0], '2026-11-21' => [1, 1000.0]])];
        $booking = MovementDetectorTest::booking('77', created: '2026-11-12T14:00:00Z', total: 800.0);

        $atBooking = $this->service(['below_expected' => ['enabled' => true]])->watch(self::input([$booking], $inventory, ['room-a' => ['2026-11-20' => 1, '2026-11-21' => 1]]));
        self::assertSame([], $atBooking->belowExpected, '800 of 1000 at booking time is fine');

        $current = $this->service(['below_expected' => ['enabled' => true, 'price_basis' => 'current']])->watch(self::input([$booking], $inventory, ['room-a' => ['2026-11-20' => 1, '2026-11-21' => 1]]));
        self::assertSame(0.4, $current->belowExpected[0]->ratio, 'the reference way: the price now');
        self::assertContains('booking_below_expected:inn:77', $this->activeKeys());
    }

    public function testBelowExpectedIsOffByDefault(): void
    {
        $inventory = ['room-a' => self::nights(['2026-11-20' => [1, 1000.0], '2026-11-21' => [1, 1000.0]])];

        self::assertSame([], $this->service()->watch(self::input([MovementDetectorTest::booking('78', total: 10.0)], $inventory))->belowExpected);
    }

    public function testTheAuditAlertsResolveForTheAuditedRoomsOnly(): void
    {
        $finding = new RoomAudit('room-a', [], [Days::date('2026-11-20')], [], [], [], []);
        $this->service()->audit('inn', [$finding, new RoomAudit('room-b', [], [Days::date('2026-11-20')], [], [], [], [])], ['room-a', 'room-b']);
        $this->service()->audit('inn', [], ['room-a']);

        $active = $this->kit->ledger->active('inn', ['inventory_stay_off_rule']);
        self::assertSame(['inventory_stay_off_rule:inn:room-b:stay'], array_map(static fn (AlertRecord $a): string => $a->dedupeKey, $active));
        self::assertSame(AlertStatus::Resolved, $this->kit->ledger->latestByKey('inventory_stay_off_rule:inn:room-a:stay')?->status);
    }
}
