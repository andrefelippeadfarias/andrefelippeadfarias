<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Operations\BookingVersion;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\Movement;
use Ritmo\Domain\Operations\MovementDetector;
use Ritmo\Domain\Operations\MovementKind;
use Ritmo\Domain\Operations\MovementWindow;
use Ritmo\Domain\Support\Days;

final class MovementDetectorTest extends TestCase
{
    private const ROOMS = ['room-a' => true, 'room-b' => true];

    public static function booking(string $ref, string $arrival = '2026-11-20', string $departure = '2026-11-22', BookingStatus $status = BookingStatus::Confirmed, ?string $room = 'room-a', ?string $created = '2026-11-12T14:00:00Z', ?string $modified = null, ?string $cancelled = null, ?float $total = 500.0): BookingRecord
    {
        $at = static fn (?string $t): ?DateTimeImmutable => $t === null ? null : new DateTimeImmutable($t);

        return new BookingRecord($ref, $room, Days::date($arrival), Days::date($departure), $status, 'booking', 'Booking.com', $total, $at($created), $at($modified ?? $created), $at($cancelled));
    }

    private static function window(?string $cursor, string $now = '2026-11-12T15:00:00Z'): MovementWindow
    {
        return MovementWindow::compute($cursor === null ? null : new DateTimeImmutable($cursor), new DateTimeImmutable($now), new LightRunConfig(movementOverlapMinutes: 5), 30);
    }

    public function testTheReadStartsAtTheCursorMinusTheOverlap(): void
    {
        $window = self::window('2026-11-12T13:45:00Z');

        self::assertSame('2026-11-12T13:40:00+00:00', $window->since->format(DATE_ATOM));
        self::assertFalse($window->baseline);
        self::assertFalse($window->hasGap());
    }

    public function testTheFirstReadIsABaselineOverTheInitialLookBack(): void
    {
        $window = self::window(null);

        self::assertTrue($window->baseline);
        self::assertSame('2026-11-11T15:00:00+00:00', $window->since->format(DATE_ATOM));
        $batch = (new MovementDetector())->detect([self::booking('1')], [], $window, self::ROOMS);
        self::assertSame([], $batch->movements, 'a baseline moves nothing');
        self::assertSame(MovementKind::Baseline, $batch->versions[0][1]);
    }

    public function testALongStopIsCappedAndLeavesAGap(): void
    {
        $window = self::window('2026-09-01T00:00:00Z');

        self::assertSame('2026-10-13T15:00:00+00:00', $window->since->format(DATE_ATOM));
        self::assertSame('2026-08-31T23:55:00+00:00', $window->gapFrom?->format(DATE_ATOM));
    }

    public function testAVersionAlreadyProcessedIsNotMovementAgain(): void
    {
        // L 08/11: the overlap brought back bookings already seen.
        $seen = self::booking('7', created: '2026-11-12T13:42:00Z');
        $batch = (new MovementDetector())->detect([$seen], ['7' => BookingVersion::of($seen)], self::window('2026-11-12T13:45:00Z'), self::ROOMS);

        self::assertSame([], $batch->movements);
        self::assertSame([], $batch->versions);
    }

    public function testAChangedVersionIsMovementWithItsPreviousVersion(): void
    {
        $before = self::booking('8', '2026-11-20', '2026-11-22', created: '2026-11-01T10:00:00Z');
        $after = self::booking('8', '2026-11-25', '2026-11-27', room: 'room-b', created: '2026-11-01T10:00:00Z', modified: '2026-11-12T14:00:00Z');
        $batch = (new MovementDetector())->detect([$after], ['8' => BookingVersion::of($before)], self::window('2026-11-12T13:45:00Z'), self::ROOMS);

        self::assertCount(1, $batch->movements);
        self::assertSame(MovementKind::Modified, $batch->movements[0]->kind);
        self::assertSame('room-a', $batch->movements[0]->previous?->roomCode);
    }

    public function testACancellationWithoutItsInstantIsACancellationWhenThePreviousVersionWasConfirmed(): void
    {
        // OD4: the reference calls it "alterada" (it has no previous version).
        $before = self::booking('9', created: '2026-11-01T10:00:00Z');
        $after = self::booking('9', status: BookingStatus::Cancelled, created: '2026-11-01T10:00:00Z', modified: '2026-11-12T14:00:00Z');
        $window = self::window('2026-11-12T13:45:00Z');

        self::assertSame(MovementKind::Cancelled, (new MovementDetector())->detect([$after], ['9' => BookingVersion::of($before)], $window, self::ROOMS)->movements[0]->kind);
        self::assertSame(MovementKind::Modified, (new MovementDetector())->detect([$after], [], $window, self::ROOMS)->movements[0]->kind);
    }

    public function testBookingsOfUnmappedRoomsAreOnlyCounted(): void
    {
        $batch = (new MovementDetector())->detect([self::booking('10', room: null), self::booking('11', room: 'room-z'), self::booking('12')], [], self::window('2026-11-12T13:45:00Z'), self::ROOMS);

        self::assertSame(2, $batch->unmapped);
        self::assertSame(['12'], array_map(static fn (Movement $m): string => $m->booking->pmsRef, $batch->movements));
        self::assertSame(['new' => 1, 'cancelled' => 0, 'modified' => 0], $batch->counts());
    }

    public function testABlockIsNotASaleButACancelledSaleIs(): void
    {
        $block = new Movement(MovementKind::New, self::booking('13', status: BookingStatus::Blocked));
        $request = new Movement(MovementKind::New, self::booking('14', status: BookingStatus::Request));
        $cancelled = new Movement(MovementKind::Cancelled, self::booking('15', status: BookingStatus::Cancelled));

        self::assertFalse($block->touchesSales());
        self::assertFalse($request->touchesSales());
        self::assertTrue($cancelled->touchesSales());
    }

    public function testTheFingerprintIgnoresNothingThatMakesMovementAndHoldsNoGuestData(): void
    {
        $base = BookingVersion::of(self::booking('16'));

        self::assertNotSame($base->fingerprint(), BookingVersion::of(self::booking('16', total: 501.0))->fingerprint());
        self::assertNotSame($base->fingerprint(), BookingVersion::of(self::booking('16', departure: '2026-11-23'))->fingerprint());
        self::assertSame($base->fingerprint(), BookingVersion::of(self::booking('16'))->fingerprint());
        self::assertSame(64, strlen($base->fingerprint()));
    }
}
